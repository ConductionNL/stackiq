# Design: eol-feed-integration

## Architecture Overview

```
openconnector (sibling repo, optional)
  endoflife-date-source: Source + Synchronization + Mapping
    → fetches https://endoflife.date/api (all.json + per-product cycles)
    → upserts OpenRegister objects: eolProduct, eolCycle
        (in a register, name configurable; e.g. "eol-lifecycle")
                          │
                          │  read-only, via ObjectService — NO HTTP here
                          ▼
softwarecatalog (this change)
  module.eolProductSlug  ──────────┐  (mapping config, per product)
                                    │
  EolSyncJob (background)  ───►  EolSyncService  ───►  EolMatcherService
  SettingsController "sync now" ───┘                        │
                                                              ▼
                                          moduleVersie.datumEindeOndersteuning
                                          moduleVersie.eolBron
                                          moduleVersie.eolBijgewerktOp
                                                              │
                                                              ▼
                              application-lifecycle-tracking (unchanged):
                              EOL indicator, EOL-approaching filter, roadmap,
                              eol-approaching notification rule
```

The matcher never talks to endoflife.date. It reads two register/schemas that
openconnector's `endoflife-date-source` change provisions, through the same
`ObjectService`/`ConfigurationService` accessors this app already uses for
every other OR read (`Uses OpenRegister API directly` project rule extends to
backend service reads, not just the frontend).

## Goals / Non-Goals

**Goals**: data-driven `datumEindeOndersteuning`, conservative/safe matching,
graceful degradation with zero coupling to whether openconnector is
installed, provenance so stamped values are distinguishable from
hand-entered ones, no new outbound network surface in softwarecatalog.

**Non-Goals**: fetching endoflife.date directly (openconnector's job);
changing how EOL state is derived/filtered/notified
(`application-lifecycle-tracking` is unmodified); auto-matching
`datumTeruggetrokken`; fuzzy/best-effort version matching (ambiguous cases are
always left for a human).

## Decisions

### Decision 1 — Mapping config lives on `module`, not a separate schema

**Choice**: add one optional field, `eolProductSlug`, directly to the existing
`module` schema (the same object `moduleVersie.module` already points at).

**Alternatives considered**: a standalone `eolMapping` schema linking
`module` → endoflife.date slug. Rejected — `module-vulnerability-tracking`
and `application-lifecycle-tracking` both established the pattern of adding a
narrow optional field to an existing schema rather than introducing a new
join schema for a 1:1 config value (ADR-011: check existing shape first). One
field, one admin action ("set the endoflife.date slug for this product"), no
extra CRUD surface.

### Decision 2 — Matching is conservative: unambiguous single-candidate only

**Choice**: `EolMatcherService::match(module)` fetches all `eolCycle` rows for
`module.eolProductSlug`, and for each `moduleVersie.versie` on that module,
selects candidate cycles whose `cycle` value is a version-prefix of (or
exactly equals) the module version string (e.g. version `21.3.1` matches
cycle `21.3` and `21`, ranked most-specific-first). A stamp is only written
when **exactly one** cycle at the most-specific matching level exists; any
tie or zero-candidate result is skipped and left untouched.

**Alternatives considered**: best-effort "closest" match with a confidence
score. Rejected per the context brief's explicit "conservative,
unambiguous" requirement and Risk 1 in the proposal — a wrong EOL date
silently shown as authoritative (with provenance implying it's sourced) is
worse than no date. Skipped versions remain exactly as visible/editable as
they are today (no regression versus manual-only).

### Decision 3 — Stamping preserves OR's PUT semantics

**Choice**: `EolMatcherService` never constructs a partial payload. It reads
the full current `moduleVersie` object, sets
`datumEindeOndersteuning`/`eolBron`/`eolBijgewerktOp` on the in-memory copy,
and calls `saveObject()` with the complete object — every other field
(`versie`, `status`, `gebruiken`, etc.) is carried forward unchanged.

**Rationale**: `reference_or-saveobject-put-semantic-nulls-omitted` — OR's
`saveObject` is PUT-semantic; omitted properties are nulled, not left alone.
This is the same discipline `application-lifecycle-tracking`'s replacement
fields and `module-vulnerability-tracking`'s manifest CRUD already rely on.
A regression test asserts an unrelated field (e.g. `beschrijvingKort`)
survives a stamp.

### Decision 4 — Provenance fields, not a provenance schema

**Choice**: two new optional fields on `moduleVersie`: `eolBron` (string,
default `"endoflife.date"` when stamped by this feature, absent when
hand-entered) and `eolBijgewerktOp` (date-time, set to the sync run's
timestamp). Both are only ever written by `EolMatcherService`; a user editing
`datumEindeOndersteuning` by hand does not set or clear them automatically
(so a subsequent overwrite by a human is visible as "no longer feed-sourced"
only if the admin also clears provenance — acceptable, since the fields exist
purely as an informational trail, not a lock).

**Alternatives considered**: a generic `_provenance` envelope object.
Rejected — two flat fields are enough for this single source, consistent
with how `moduleVersie` already models dates as flat fields rather than
structured sub-objects, and avoids inventing new schema conventions for one
feature.

### Decision 5 — Register/schema names are settings, not constants

**Choice**: the EOL sync config domain (new, alongside the existing sync/
cronjob domains in `settings-admin-controller`) stores the register slug and
the `eolProduct`/`eolCycle` schema slugs as configurable strings, with
defaults matching what `endoflife-date-source` provisions. `EolSyncService`
resolves these via `ConfigurationService` at run time, never a hardcoded
schema ID.

**Rationale**: openconnector and softwarecatalog are separate repos/release
trains (ADR-011); hardcoding schema slugs would silently break if the
openconnector change ships with different names, with no recovery path short
of a code change. A settings field is the same recovery path the existing
sync config already uses for other cross-app register wiring.

### Decision 6 — Absence is a first-class, silent-by-default state

**Choice**: if the configured register/schema cannot be resolved (register
missing, schema missing, or the EOL sync toggle is off), `EolSyncService`
returns a status object (`available: false`, `reason`) and neither the
background job nor the manual trigger raises an error to the end user. The
settings status endpoint surfaces this so an admin can tell "not configured"
apart from "configured but nothing matched yet" apart from "ran, N matched, M
skipped".

**Rationale**: mirrors `module-vulnerability-tracking`'s "core capability
works with no feed configured" requirement — manual `datumEindeOndersteuning`
entry, the EOL-approaching filter, the roadmap, and the notification rule all
already work with zero code from this change; this feature must never be a
precondition for them.

## Risks / Trade-offs

- [Prefix matching false-positive across products with identically-shaped
  version strings, e.g. `1.0` matching an unrelated product's cycle] →
  Mitigation: matching is always scoped to the module's own
  `eolProductSlug`-selected cycle set, never cross-product; the ambiguity
  guard (Decision 2) also catches shape collisions within one product.
- [Two new optional fields on a schema with existing production data] →
  Mitigation: additive-only register change, same low-risk shape as
  `application-lifecycle-tracking`'s `geplandeVervanging` addition; no
  migration required (see Migration Plan below).
- [Background job load if a catalog maps hundreds of products] → Mitigation:
  the job iterates modules with `eolProductSlug` set only (typically a small
  subset), and per-module the register read is a single filtered query
  (`eolCycle` where `product = slug`), not a full-register scan.

## Migration Plan

No Nextcloud `lib/Migration/` class is introduced — per ADR-001 this app owns
no custom database tables, and the new fields are additive optional
properties on the existing OpenRegister-backed `module`/`moduleVersie`
schemas. They ship in `lib/Settings/softwarecatalogus_register.json` and are
applied the same way every other schema change in this app is: imported via
`ConfigurationService::importFromApp()` in the repair step
(`repair-init` spec), which existing objects survive unchanged (both fields
optional, no default that would alter current records). Rollback is deleting
the two field definitions from the register JSON — existing stamped values on
already-saved objects are unaffected either way (OR does not retroactively
strip data on a schema-definition change).

## Nextcloud Integration

- **Controllers**: `SettingsController` gains `getEolSyncConfig()` /
  `updateEolSyncConfig()`, `triggerEolSync()`, `getEolSyncStatus()` —
  same pattern as the existing sync/cronjob endpoint pairs.
- **Services**: `EolSyncService` (orchestration: resolve config → call
  matcher → aggregate status), `EolMatcherService` (pure matching + stamping
  logic, unit-testable with fixture cycle arrays and no OCP dependencies).
- **Background job**: `EolSyncJob extends TimedJob`, registered in
  `appinfo/info.xml` background-jobs section (NC 34 registration gotchas
  apply — verify against `cronjob-context`'s existing job registration),
  runs in system context (no RBAC), interval read from the EOL sync config.
- **Mappers/Entities**: none new — reads/writes go through OpenRegister's
  `ObjectService`, not app-local entities/mappers (ADR-022, consistent with
  `module-vulnerability-tracking` Decision 1).
- **Events/Hooks**: none introduced; this stays a pull (scheduled +
  manual-trigger) design, not event-driven, matching `cronjob-context`.

## Security Considerations

The manual trigger endpoint requires the same admin/settings authorization as
every other `settings-admin-controller` endpoint (existing NC settings
auth — `#[AuthorizedAdminSetting]` / `NoAdminRequired` pattern already used by
the sync endpoints; no new auth pattern introduced). The matcher performs no
outbound HTTP and accepts no user-supplied URLs, eliminating SSRF risk by
construction — it only reads objects from OpenRegister via the standard
authorization already enforced by `ObjectService`. No new PII surface: cycle
data (versions, dates) carries no personal data.

## File Structure

```
lib/
  Settings/
    softwarecatalogus_register.json   (+ eolProductSlug on module,
                                          eolBron/eolBijgewerktOp on moduleVersie)
  Service/
    EolMatcherService.php             (new — pure matching/stamping logic)
    EolSyncService.php                (new — orchestration + status)
    SettingsService.php               (+ EOL sync config get/update)
  Controller/
    SettingsController.php            (+ EOL sync endpoints)
  BackgroundJob/
    EolSyncJob.php                    (new)
src/
  views/Settings/                     (+ EOL source config panel: register/
                                          schema names, enable toggle, sync-now
                                          button, last-run status)
  store/                              (+ settings store actions for the new
                                          endpoints, following fe-stores pattern)
l10n/
  en.js / en.json / nl.js / nl.json   (+ new settings + status strings)
tests/
  Unit/Service/EolMatcherServiceTest.php  (fixture cycles: unambiguous match,
                                             ambiguous/tie, no match, prefix
                                             overlap across major versions)
docs/features/eol-feed-integration.md
```

## Trade-offs

Considered building the matcher as a pure frontend computation (fetch both
registers client-side, match in Vue) instead of a backend service + job.
Rejected: a scheduled background job needs a backend entry point regardless
(`cronjob-context` pattern), and doing the match server-side keeps the
conservative-matching logic in one testable PHP unit rather than duplicated
between a job and a browser-triggered path — the manual "sync now" button
simply invokes the same backend service the job calls, avoiding drift between
the two trigger paths.
