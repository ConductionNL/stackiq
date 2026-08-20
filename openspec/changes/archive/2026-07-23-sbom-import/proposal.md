---
kind: feature
depends_on: []
---

# softwarecatalog — SBOM import

## Summary

Add the ability to import a Software Bill of Materials (SBOM) — CycloneDX
1.5/1.6 JSON, with SPDX JSON as an optional second format — for a specific
`moduleVersie`. The uploaded file is parsed into `sbomComponent` OpenRegister
objects (purl, name, version, licenses, optional hashes) linked to that
version, shown on a new Components tab with license and summary counts, and
cross-referenced against the existing `kwetsbaarheid` register so a version's
component list can answer "are any of these already-known vulnerabilities
relevant here" without leaving the app or calling an external service.

## Motivation

The dependency-management research domain (Specter domain 160) has **zero**
implementation in the app today, despite being a recurring reference-tool
category (OWASP Dependency-Track — 20k+ orgs — correlates ingested SBOMs
against advisory data) and a near-term procurement checkbox: NIS2/BIO
supply-chain requirements appear in 561 mapped compliance requirements across
tenders, and Sonatype's 2026 findings (80% of dependencies un-upgraded >1yr)
make dependency-freshness visibility a live demand driver. Specter's canonical
feature catalogue lists `sbom-file` as a `should`-priority feature (demand 8,
upgraded 2026-07-23).

The `kwetsbaarheid` register already exists (module-vulnerability-tracking)
but nothing in the app currently connects it to what a version *actually
ships* — SBOM import is the missing link between "here is a released version"
and "here is what's inside it, and is any of it already flagged."

## Affected Projects

- [x] Project: `softwarecatalog` — new `sbomComponent` schema, SBOM upload +
  parse + import backend, Components tab on the module-version detail page,
  local vulnerability cross-referencing.

## Scope

### In Scope

- Upload endpoint: bounded file size, JSON content only, admin/manage-ACL
  gated (mirrors `importArchiMate`'s auth pattern).
- `SbomParserService`: pure PHP parser (no OR calls) for CycloneDX 1.5 and 1.6
  JSON `components[]`; SPDX 2.3 JSON as an optional second format if it stays
  cheap to add on top of the same service.
- `sbomComponent` schema: `purl`, `name`, `version`, `licenses[]`, optional
  `hashes[]`, `type`, `moduleVersie` relation.
- Re-import for the same `moduleVersie` replaces the previous component set
  (idempotent), soft-delete aware (existing OR trash rows are excluded from
  the "previous set" before replacing, never double-processed).
- Bounded-batch OR bulk-save with progress reporting for larger files, reusing
  the existing `progress-tracking` capability.
- Components tab on the `ModuleversieDetail` manifest page: component list
  with licenses, upload control, summary counts (component count, distinct
  license count, matched-vulnerability count).
- Local vulnerability matching against existing `kwetsbaarheid` objects by CVE
  id (when the SBOM carries CycloneDX VEX data) and by best-effort
  name/purl matching — computed at render time, never persisted, feeding into
  (not forking) the `module-vulnerability-tracking` model.
- i18n (Dutch + English), tests with real small CycloneDX fixtures, docs.

### Out of Scope

- Any outbound HTTP call to an external vulnerability/advisory service
  (OSV.dev, NVD, GitHub Advisories, etc.) — that integration belongs in
  openconnector, later, per `feedback_integrations-not-leaves`.
- SBOM generation or export (this change only imports).
- License-policy evaluation (allow/deny lists, obligations) — only the raw
  license identifiers are captured and shown.
- Transitive dependency graphs — CycloneDX component **list** only, no
  dependency-edge graph parsing/rendering.

## Approach

A pure `SbomParserService` normalizes an uploaded CycloneDX (or SPDX) JSON
document into component DTOs; a thin `SbomController` endpoint validates the
upload (size, content-type, auth) and hands off to `SbomImportService`, which
resolves the target `moduleVersie`, soft-deletes its previous `sbomComponent`
set (excluding already-trashed rows), and bulk-saves the new set through
OpenRegister's `ObjectService` in bounded batches, reporting progress via the
existing `ProgressTracker` for larger imports. The frontend adds a Components
tab (`SbomComponentsPanel`) to `ModuleversieDetail` with an upload control,
component table, and summary counts; vulnerability matching against
`kwetsbaarheid` is a read-time join computed by a frontend util, mirroring how
`module-vulnerability-tracking`'s exposure and severity are derived rather
than stored. Full detail in `design.md`.

## New Dependencies

None. Parsing uses PHP's built-in `json_decode`; no new Composer or npm
packages.

## Impact

- **New schema**: `sbomComponent` in
  `lib/Settings/softwarecatalogus_register.json`.
- **Modified schema**: `moduleVersie` gains three optional provenance fields
  (`sbomLastImportedAt`, `sbomFormat`, `sbomFileName`) so the Components tab
  can show "last imported" without a separate lookup.
- **New backend**: `SbomParserService`, `SbomImportService`, `SbomController`
  + route.
- **New frontend**: `SbomComponentsPanel.vue`, a Components sidebar tab on
  `ModuleversieDetail`, and a render-time vulnerability-match util.
- **No app-local vulnerability model** — matching reads the existing
  `kwetsbaarheid` register; nothing new is written to it.

## Cross-Project Dependencies

None. Self-contained within `softwarecatalog`; consumes OpenRegister only.
Automatic external CVE/advisory enrichment (out of scope here) would depend
on `openconnector` if built later.

## Risks

### Risk 1: Large SBOMs (hundreds of components) stress OR bulk-save
**Severity:** Medium — **Mitigation:** bounded batch sizes on both the
soft-delete-replace and the create path, with progress reporting so an
in-flight large import is visible rather than appearing hung (per the
`reference_or-magic-table-scan-n1`/bulk-save-performance gotchas already
logged for this app family).

### Risk 2: Name/purl-based vulnerability matching produces false positives
**Severity:** Medium — **Mitigation:** the heuristic match is scoped to
`kwetsbaarheid` records already linked to the version's parent `module` (not
a full-register free-text scan) and is labelled "possible match" in the UI,
visually distinct from the CVE-id "confirmed match" — the user, not the
system, makes the final call.

### Risk 3: CycloneDX version/dialect drift (1.4 vs 1.5 vs 1.6, `bomFormat`
variants)
**Severity:** Low — **Mitigation:** parser explicitly validates
`bomFormat: "CycloneDX"` and `specVersion` in `{1.5, 1.6}`, rejecting anything
else with a clear error rather than guessing; unsupported versions are a
documented follow-up, not a silent partial parse.

## Rollback Strategy

Additive only: new schema, new endpoint, new manifest tab. Revert the
register-config changes (drop `sbomComponent`, revert the `moduleVersie`
addition) and the controller route/manifest entry; no destructive migration
of existing data is introduced, so rollback is a plain revert of the PR.
Previously-imported `sbomComponent` objects remain in OR storage (soft-deleted
on cleanup if desired) but are inert once the schema/route are removed.

## Open Questions

- SPDX JSON support is scoped "if cheap" — the design will confirm whether it
  fits inside `SbomParserService` as a second normalizer or should be
  deferred to a follow-up change if the two formats diverge too much to share
  cleanly.
