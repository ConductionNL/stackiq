# Design: sbom-import

## Architecture Overview

```
Upload (multipart, .json)
   │
   ▼
SbomController::importSbom()          (auth + size/type guard, mirrors importArchiMate)
   │
   ▼
SbomImportService::importForModuleVersie()
   │  ├─ SbomParserService::parse()   (pure, no OR calls — CycloneDX 1.5/1.6, optional SPDX)
   │  ├─ ProgressTracker (progress-tracking spec) — start/update/complete
   │  ├─ soft-delete previous sbomComponent set for this moduleVersie
   │  │     (ObjectService findAll filtered on moduleVersie + not _deleted, bounded batch delete)
   │  └─ bulk-save new sbomComponent set in bounded batches
   ▼
OpenRegister object store (sbomComponent, moduleVersie)
   │
   ▼
Components tab (ModuleversieDetail) — SbomComponentsPanel.vue
   │  ├─ component table (name, version, purl, licenses)
   │  ├─ summary counts (components / distinct licenses / matched vulnerabilities)
   │  └─ sbomVulnerabilityMatch.js — read-time join vs kwetsbaarheid register
```

Mirrors `archimate-import`'s upload → parse → object creation → status
ergonomics (`SettingsController::importArchiMate` /
`parseArchiMateFileUpload` / `resolveArchiMateMethod`), scoped down to a
single-schema, single-parent-object import instead of a whole-model import.

## Decision 1 — Parser is a pure, OR-free service (ADR-008)

`SbomParserService::parse(string $json): array` takes raw JSON text and
returns an array of normalized component DTOs
(`purl`, `name`, `version`, `licenses`, `hashes`, `type`, `bomRef`). It has no
constructor dependency on `ObjectService` or any OR class — it is
unit-testable with plain fixture files and no database. It validates
`bomFormat === 'CycloneDX'` and `specVersion` in `{'1.5', '1.6'}` before
attempting to read `components[]`; anything else throws a typed
`UnsupportedSbomFormatException` with the offending format/version in the
message, caught by the controller and returned as a 422.

SPDX JSON support (optional, "if cheap") is added as a second entry point,
`SbomParserService::parseSpdx(string $json): array`, sharing the same
component DTO shape (SPDX `packages[]` → `name`, `versionInfo` → `version`,
`licenseConcluded`/`licenseDeclared` → `licenses`, `externalRefs` of type
`purl` → `purl`). The controller picks the parser based on a required
`format` upload parameter (`cyclonedx-json` | `spdx-json`) rather than
sniffing content, so a malformed file gets a clear "wrong format selected"
error instead of a guessed partial parse.

**Alternative considered:** auto-detecting format from JSON shape
(`bomFormat` key vs `spdxVersion` key). Rejected — explicit is cheaper to
reason about and test, and the upload UI already knows which button the user
clicked.

## Decision 2 — Component persistence: new `sbomComponent` schema, `moduleVersie`-scoped

`sbomComponent` (new schema in `softwarecatalogus_register.json`):

| Field | Type | Notes |
|---|---|---|
| `moduleVersie` | related-object → `moduleVersie` | required; `inversedBy: sbomComponents` |
| `name` | string | required |
| `version` | string | component version as reported in the SBOM |
| `purl` | string | Package URL (`pkg:...`), optional but expected for most ecosystems |
| `licenses` | array\<string\> | SPDX license id(s)/expression(s) as reported; free text if the SBOM has no SPDX id |
| `type` | string | CycloneDX component type (`library`, `application`, `framework`, `container`, …), optional |
| `hashes` | array\<object {alg, value}\> | optional, informational only — not used for matching |
| `bomRef` | string | CycloneDX `bom-ref`, kept for within-import traceability only, not queried cross-import |

One `sbomComponent` object per component per import. No dependency-graph
edges are modelled (out of scope) — `bomRef` exists only so a future change
could add that without a schema break, it is not read by anything in this
change.

**Alternative considered:** embedding components as a JSON blob array on
`moduleVersie` itself instead of separate OR objects. Rejected — per-object
storage gets list/filter/count for free from OR's query layer (needed for the
summary counts and the vulnerability-match join), matches how every other
one-to-many relation in this app is modelled (e.g. `compliancy` per module),
and keeps `moduleVersie` objects a bounded size regardless of SBOM size.

## Decision 3 — Re-import is idempotent via soft-delete-aware replace

`SbomImportService::importForModuleVersie()`:

1. Parse the upload (Decision 1). If parsing fails, nothing is written —
   the previous component set is untouched.
2. Query existing `sbomComponent` objects for this `moduleVersie` via
   `ObjectService` with the standard non-deleted filter (OR's list calls
   already exclude `_deleted` rows by default — the previous-set query relies
   on that default rather than re-implementing trash filtering, so a prior
   replace's freshly-trashed rows are never picked up again).
3. Soft-delete the queried set (OR's normal `deleteObject`/bulk-delete path —
   soft-delete, never a hard purge) in bounded batches.
4. Bulk-save the newly-parsed component set in bounded batches (Decision 4).
5. Update `moduleVersie.sbomLastImportedAt` / `sbomFormat` / `sbomFileName`
   (Decision 5) in the same call.

Steps 3–4 are not wrapped in a single OR transaction (OR's ObjectService has
no cross-object transaction primitive available to app-local code) — if step
4 fails partway through, the version is left with **no** component set rather
than a mixed old/new set, which is caught by re-running the import (the next
import starts from "no non-deleted components", identical to a fresh import).
This asymmetry (fail → empty, not fail → stale) is the safer default: a
missing SBOM is visibly missing; a stale one silently misleads.

**Alternative considered:** additive import (never delete, tag each import
with a batch id, always show only the latest batch). Rejected — the brief is
explicit that re-import *replaces*, and additive-with-latest-batch-filter
adds a batch-id concept and an extra filter dimension to every read for no
scoped benefit (no requirement asks for import history).

## Decision 4 — Bounded batches + progress reporting (progress-tracking spec)

Bulk operations (soft-delete of the old set, create of the new set) run in
fixed-size batches (target ~100 objects/batch, matching the batch discipline
already used elsewhere in this app's bulk-save paths) rather than one
all-at-once OR call, per the design constraint on OR bulk-save performance
for SBOMs with hundreds of components.

For imports whose parsed component count exceeds a threshold (component count
> 50), `SbomImportService` starts a `ProgressTracker` operation
(`startOperation('sbom-import', ['total_items' => $count])`), calls
`setPhase`/`incrementProgress`/`updateStatistics` per batch, and
`completeOperation` at the end, returning the `operationId` in the response
so the frontend can poll `getProgress` — reusing `progress-tracking` exactly
as `archimate-import` does, rather than inventing a second progress
mechanism. Small imports (≤ 50 components, the common case for a single
application's direct+transitive-flattened list) complete synchronously
without needing a poll loop; the response still includes final counts either
way.

## Decision 5 — Import provenance lives on `moduleVersie`, not a separate import-log object

Three optional fields are added to the existing `moduleVersie` schema:
`sbomLastImportedAt` (date-time), `sbomFormat` (enum
`cyclonedx-json` | `spdx-json`), `sbomFileName` (string). This gives the
Components tab a "last imported <date> from <file>, <format>" line without a
second schema/query. Existing `moduleVersie` objects remain valid with these
fields unset (same additive-optional-field pattern
`application-lifecycle-tracking` used for `geplandeVervanging` on `gebruik`).

**Alternative considered:** a dedicated `sbomImport` history object per
upload (auditable log of every import attempt). Rejected as scope creep for
this change — no requirement asks for import history/audit beyond "what's
here now"; OR's own audit trail (already surfaced via the sidebar History tab
pattern used on every detail page) covers the object-level create/update
trail for `moduleVersie` and `sbomComponent` if that's ever needed.

## Decision 6 — Vulnerability matching is a read-time join, never persisted

Consistent with how `module-vulnerability-tracking` derives severity and
exposure (Decisions 2–3 of that change) rather than storing them,
SBOM-to-vulnerability matching is computed on demand by a frontend util
(`src/utils/sbomVulnerabilityMatch.js`), not written back to either register.
Two match strategies, both bounded (no full-register free-text scan):

1. **Confirmed (CVE-id) match.** If the uploaded CycloneDX document carries a
   top-level `vulnerabilities[]` (VEX) block — optional per the CycloneDX
   spec — the parser (Decision 1) extracts `{cveId, componentBomRef}` pairs
   alongside the component list. At render time, each extracted `cveId` is
   compared (case-insensitive, exact) against `kwetsbaarheid.cveCode` across
   the full `kwetsbaarheid` register (bounded — CVE-id equality is a single
   indexed-shape comparison per record, not a text scan).
2. **Possible (name/purl) match.** For each `sbomComponent`, a
   case-insensitive substring match of the component's `name` (or the
   package segment of `purl`) against `kwetsbaarheid.naam`, **scoped to
   `kwetsbaarheid` records whose `modules` already include the
   `moduleVersie`'s parent `module`** — never a catalogue-wide scan. This
   keeps the heuristic bounded and relevant: it surfaces "you already
   recorded a vulnerability against this application — here's the component
   in your SBOM it might refer to," not "any word in your SBOM resembles any
   vulnerability name in the whole catalogue."

Confirmed and possible matches render with visually distinct badges; possible
matches are explicitly labelled as such (not auto-elevated to confirmed).
Because nothing is persisted, editing or adding a `kwetsbaarheid` after an
SBOM import changes the match set on next render with no re-import needed —
the two registers stay independently authoritative.

**Alternative considered:** persisting `matchedKwetsbaarheid` references on
`sbomComponent` at import time. Rejected — would go stale the moment a
`kwetsbaarheid` is edited/added/removed after import, duplicating exactly the
staleness problem `module-vulnerability-tracking` deliberately avoided for
severity; a read-time join has no staleness window.

## Decision 7 — No outbound HTTP, anywhere in this path

Neither `SbomParserService` nor `SbomImportService` nor the frontend match
util makes an HTTP call. The parser reads only the uploaded file's bytes; the
matcher reads only the two local OR registers (`sbomComponent` via the
moduleVersie relation, `kwetsbaarheid` via the existing index query). This is
enforced structurally (neither class is given an HTTP client dependency) and
verified by a unit test asserting `SbomParserService`'s constructor takes no
network-capable dependency. Automatic advisory-feed enrichment
(OSV.dev/NVD) stays explicitly out of scope, matching
`module-vulnerability-tracking`'s Decision 5 (external CVE enrichment routes
through openconnector, never bespoke HTTP in softwarecatalog).

## Nextcloud Integration

- Controllers: `SbomController` (`importSbom`, `getSbomImportStatus`) — new,
  `#[NoAdminRequired]` with an explicit admin/manage-ACL check in the method
  body (mirrors `importArchiMate`'s pattern), `#[NoCSRFRequired]` on the
  upload action to allow multipart form posts consistent with the ArchiMate
  upload endpoint.
- Services: `SbomParserService` (pure), `SbomImportService`
  (Controller → Service → Mapper layering, ADR-008), reusing
  `ProgressTracker`.
- Mappers/Entities: none new — persistence goes through OpenRegister's
  `ObjectService` like every other app-local write (ADR-022), no app-local
  Doctrine entity/mapper for `sbomComponent`.
- Events/Hooks: none.

## Security Considerations

- Upload endpoint enforces a maximum file size (config value, default 10 MB)
  and rejects non-`.json`/non-JSON-parseable content before any parsing is
  attempted — the 400 happens on size/content-type, never after a large
  buffer is fully parsed.
- Auth mirrors `importArchiMate`: admin group membership (or manage-ACL on
  the target `moduleVersie`'s parent `module`) required to import; read
  access to the Components tab follows normal `moduleVersie`/OR object read
  ACLs — no new public/anonymous route.
- No SSRF surface — the parser never dereferences a URL found inside the SBOM
  (e.g. `externalReferences` entries are stored as opaque strings if
  captured at all, never fetched).
- Input validation: parser rejects malformed/oversized JSON structurally
  (bounded `json_decode` depth, explicit `bomFormat`/`specVersion` checks)
  rather than trusting the file.

## NL Design System

Components tab uses `CnDataTable` for the component list (columns: name,
version, purl, licenses) and standard Nextcloud upload/button components for
the import control (ADR-012 — no custom table/upload widgets). Match badges
use existing NL Design System status-tag styling (the same visual language as
the severity bands in `module-vulnerability-tracking`, via CSS variables, no
hardcoded colors — ADR-003).

## File Structure

```
lib/
  Controller/
    SbomController.php
  Service/
    SbomParserService.php
    SbomImportService.php
  Settings/
    softwarecatalogus_register.json   (sbomComponent + moduleVersie additions)
src/
  components/
    SbomComponentsPanel.vue
  utils/
    sbomVulnerabilityMatch.js
  manifest.json                        (Components sidebar tab on ModuleversieDetail)
appinfo/
  routes.php                           (importSbom, getSbomImportStatus routes)
tests/
  Unit/
    SbomParserServiceTest.php
    SbomImportServiceTest.php
  fixtures/sbom/
    cyclonedx-1.6-valid.json
    cyclonedx-1.5-valid.json
    cyclonedx-invalid-format.json
    cyclonedx-empty-components.json
tests/vitest/
  sbomVulnerabilityMatch.spec.js
docs/features/
  sbom-import.md
```

## Seed Data

### Schema: `sbomComponent`

| Field | Object 1 | Object 2 | Object 3 |
|---|---|---|---|
| slug | `sbom-component-lodash` | `sbom-component-log4j-core` | `sbom-component-openssl` |
| moduleVersie | (seeded `moduleVersie` of an existing seeded `module`) | (same version) | (same version) |
| name | `lodash` | `log4j-core` | `openssl` |
| version | `4.17.21` | `2.14.1` | `3.0.2` |
| purl | `pkg:npm/lodash@4.17.21` | `pkg:maven/org.apache.logging.log4j/log4j-core@2.14.1` | `pkg:generic/openssl@3.0.2` |
| licenses | `["MIT"]` | `["Apache-2.0"]` | `["Apache-2.0"]` |
| type | `library` | `library` | `library` |

**Related items per object:** none (no Files/Notes/Tasks/Contacts relations
on `sbomComponent`). The seeded `log4j-core@2.14.1` name is deliberately
chosen to demonstrate a "possible match" against a seed `kwetsbaarheid`
already carrying `naam: "Log4Shell"` / `cveCode: "CVE-2021-44228"` if such a
seed exists (else this pairing is a documented candidate for the
`module-vulnerability-tracking` seed set, not duplicated here).

## Trade-offs

- Choosing render-time vulnerability matching over persisted matches trades a
  small amount of per-render compute (bounded — component count ×
  module-scoped kwetsbaarheid count) for zero staleness, consistent with the
  rest of the app's derived-not-stored conventions.
- Restricting the name/purl heuristic to module-scoped `kwetsbaarheid`
  records trades recall (a vulnerability recorded against the *wrong* module
  by mistake won't surface here) for precision and a bounded query — judged
  the right trade for a "possible match, human confirms" feature.
- SPDX support sharing one parser class with CycloneDX trades some internal
  branching for avoiding a second service + second set of tests; if SPDX
  parsing turns out non-trivial during implementation, `parseSpdx` can be
  deferred to a follow-up change without touching the CycloneDX path or the
  `sbomComponent` schema (the DTO shape is format-agnostic by design).
