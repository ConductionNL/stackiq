# Design — Retrofit method-decomposition (SettingsController public API)

Retrofit change. Tasks describe retroactive annotation, not new implementation work.

## Context

`method-decomposition` is a refactoring/quality spec, not a feature spec — its existing REQ-DECOMP-001..012 describe target-state handler classes that don't yet exist (see Bucket 3b in the coverage report). The current code is the *subject* of decomposition.

The coverage scan flagged 23 SettingsController methods as `_misc_*` aggregate rows under Bucket 2a — methods in REQ-DECOMP-001's file scope but outside its named-method scope (`syncSoftwareCatalogue`, `registerModules`, `syncOrganizations`, `configureArchiMate`). They describe observable HTTP behaviour, not refactoring targets, but they live in a file the existing capability already owns.

## Decisions

- **`--extend method-decomposition`** — the methods are in a file already tied to this capability. Minting a new capability for "SettingsController public API" would fork ownership of the same file across two specs.
- **REQ-DECOMP-013..017 (5 REQs)** — within the 5-REQ-per-run cap. Future runs add 014+ for the remaining files.
- **REQ language describes the contract.** Three bugs (empty-if blocks that invert HTTP status codes in `performSync` + `resetAutoConfig`, missing `@NoAdminRequired` on general config endpoints) were spotted while reading. They are flagged in Notes, not silently encoded as REQ behaviour.
- **Frontmatter uses block-YAML `retrofit_extensions`** per skill convention. Five entries, one per REQ.
- **17 methods annotated, 6 groupings** — methods within a REQ that share a behavioural cluster (e.g. `getGeneralConfig` + `updateGeneralConfig` both under REQ-DECOMP-013) get the same `@spec` tag pointing at the same task.

## Out of scope

- Fixing the empty-if bugs (`performSync`, `resetAutoConfig`).
- Revisiting `@NoAdminRequired` on `getGeneralConfig` / `updateGeneralConfig`.
- The remaining 14 SettingsController methods + 6 service files in Bucket 2a `_misc_*`.

## References

- Umbrella: ConductionNL/softwarecatalog#285
- Coverage report: openspec/coverage-report.md (2026-05-24)
- Sibling retrofit (progress-tracking): PR #288 — same empty-if bug pattern
- Source: lib/Controller/SettingsController.php
