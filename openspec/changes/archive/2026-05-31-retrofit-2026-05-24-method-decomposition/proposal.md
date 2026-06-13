# Retrofit — method-decomposition (extend with SettingsController public API)

Extends the existing `method-decomposition` capability with 5 new REQs that retro-describe the observable contract of `SettingsController` public methods that are NOT covered by REQ-DECOMP-001's scope (which only names `syncSoftwareCatalogue`, `registerModules`, `syncOrganizations`, `configureArchiMate`). Bucket 2a in the coverage report flagged 23 SettingsController methods as `_misc_*` aggregate rows — this run captures the first 5 behavioural groupings (settings CRUD, configuration status/version, sync orchestration, cache + heartbeat diagnostics, progress streaming).

Code already exists — this change retroactively specifies it.

## Scope (this run)

5 REQs covering 18 SettingsController public methods:

- **REQ-DECOMP-013 — Settings CRUD endpoints** (index, create, getGeneralConfig, updateGeneralConfig, getSyncConfig, updateSyncConfig)
- **REQ-DECOMP-014 — Configuration bootstrap + status endpoints** (load, initialize, status, getVersionInfo)
- **REQ-DECOMP-015 — Sync orchestration endpoints** (getSyncStatus, performSync)
- **REQ-DECOMP-016 — Cache + heartbeat diagnostics** (clearCache, heartbeat, stats, debug)
- **REQ-DECOMP-017 — Progress snapshot + SSE streaming** (getProgress, streamProgress)

## Out of scope (future runs)

23 methods remain in Bucket 2a `_misc_` — split into future PRs of 5 REQs each:

- **SettingsController remainder** (autoConfigure, resetAutoConfig, manualImport, forceUpdate, consolidatedAutoConfigure, importArchiMate, exportArchiMate, exportOrgArchiMate, downloadArchiMate, sendTestEmail, testEmailConnection, getEmailSettings, updateEmailSettings, render — 14 methods)
- **SettingsService _misc_ 50+ methods**
- **SoftwareCatalogueService _misc_ 40+ methods**
- **ArchiMate Import/Export/Service _misc_ ~170 methods combined**
- **ContactPersonHandler _misc_ 30+ methods**
- 7 other services with `_misc_methods` aggregate rows

## Approach

- For each REQ group: describe observed contract (request shape, success envelope, status codes, exception path), flag any observed-but-buggy behaviour.
- Notes section captures three concrete bugs visible while reading the code (empty-if blocks that invert success/failure status codes in `performSync` and `resetAutoConfig`, mirroring the `progress-tracking#calculateOverallPercentage` bug from PR #288).

Source: openspec/coverage-report.md generated 2026-05-24. Umbrella: ConductionNL/softwarecatalog#285.
