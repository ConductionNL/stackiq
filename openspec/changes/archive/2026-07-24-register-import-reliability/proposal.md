# Proposal: register-import-reliability

## Summary
`SettingsService::loadSettings()` computes the OpenRegister import version from `info.version` in `softwarecatalogus_register.json` plus a hash of the ADR-037 fragment files only — it never hashes the monolith's own body. When a change edits the monolith directly (as all 8 recent market-gap changes did) without also bumping `info.version`, the computed version is byte-identical to the last import and OpenRegister's version-gated `importFromApp` silently no-ops: schemas and properties that were merged and shipped never reach an installed instance, while CI and `occ upgrade` both report success. This change folds a content hash of the monolith into the version signature so any register edit forces a re-import, investigates and resolves the duplicate `Software Catalog Register` configuration rows that may also be confusing import resolution, and adds a post-import verification pass that turns a no-op import into a loud, visible warning instead of silence.

## Motivation
Reproduced live on 2026-07-24 on NC 34 / :8080: after deploying `development` HEAD with all 8 merged market-gap changes and running `occ upgrade`, three schemas/property sets (`bioMaatregel`, `sbomComponent`, 7 `module` properties, 3 `gebruik` TIME fields) were absent from OpenRegister despite the upgrade reporting success. Only a manually-triggered `POST /api/settings/import {"force":true}` applied them. This is a silent data-loss-of-functionality defect: features that are "done" in every visible signal (merged PR, green CI, successful upgrade log) are dead on the instance that matters. It has now been observed independently on two apps (softwarecatalog here, opencatalogi in a separate session), indicating a defect class rather than a one-off mistake, and it must be fixed at the root (content-derived versioning) rather than by process discipline (remembering to bump `info.version`).

## Affected Projects
- [x] Project: `softwarecatalog` — `SettingsService::loadSettings()` version signature, duplicate configuration row handling, post-import verification + status surface, regression test, docs note

## Scope

### In Scope
1. Fold an md5 of the monolith `softwarecatalogus_register.json` file content into the computed `$configVersion` (e.g. `+base.<md5-8>`) alongside the existing fragment signature, so any register change — monolith or fragment — produces a new version and triggers re-import. Also fix `SettingsService::shouldLoadSettings()`, whose comparison of the app's own semver against the register-content version stored by `importFromApp` is a confirmed apples-to-oranges defect that can permanently prevent `loadSettings()` from ever running again — making item 1's signature fix unreachable in the exact scenario reproduced live.
2. Investigate how three `Software Catalog Register` configuration rows (ids 7, 117, 81) arose in `oc_openregister_configurations`; make this app's resolution of "the" configuration deterministic; de-duplicate where safe from this app's side, or file an issue against OpenRegister's `ConfigurationService` if the true fix belongs there. **Filed:** [openregister#2072](https://github.com/ConductionNL/openregister/issues/2072) — code review of `ImportHandler::importFromApp()` found that `ConfigurationMapper::findByApp()`/`findBySourceUrl()` organisation-scope their lookup (`applyOrganisationFilter`, default `allowNullOrg: false`), so an app-owned configuration row can become invisible to a caller whose active-organisation context differs from the row's, causing "no existing configuration found, will create new one" and a duplicate row. Live DB confirmation of the 3 rows' `organisation` values was blocked by the shared Postgres instance being in recovery mode at investigation time; the issue documents the mechanism from code review and asks a maintainer with DB access to confirm.
3. Post-import verification: after `importFromApp` returns, confirm every schema slug in the effective (merged) register exists in OpenRegister and that every configured schema id resolves. On mismatch, log a WARNING and surface the mismatch in the settings status payload (`getConfigurationStatus()` / equivalent) so an admin can see a no-op import instead of it looking identical to success.
4. Regression test asserting the computed `$configVersion` changes when the monolith's own content changes (not only when a fragment changes) — the test that would have caught the original defect.
5. Docs note describing how register/schema changes reach an installed instance, and reiterating the ADR-037 fragment-file preference for future changes.

### Out of Scope
- Rewriting or patching OpenRegister's `ConfigurationService` import/version-gate logic itself (file an issue there instead if a true fix belongs upstream).
- Migrating the 8 already-merged market-gap schema changes from the monolith into ADR-037 fragment files — they are already live via the forced import; a separate cleanup if desired later.
- Any admin UI beyond surfacing the mismatch/warning in the existing settings status payload (no new settings screens or widgets).

## Approach
Compute a second content hash (`md5` of the raw monolith file contents, truncated to 8 hex chars) inside `loadSettings()`, append it to `$configVersion` as `+base.<hash>` before the existing `+frag.<hash>` suffix, so the full signature changes whenever the monolith OR any fragment changes. After `importFromApp` succeeds, walk the merged `components.schemas` keys from the effective register, resolve each against OpenRegister's schema list by slug, and record any misses; also resolve each schema id referenced in this app's own configuration (e.g. `getSchemaIdForObjectType`) and record unresolved ids. Persist a `registerVerification` block (ok/warnings/mismatched slugs) into the settings status result and log a WARNING per mismatch. Separately, query `oc_openregister_configurations` for rows matching this app's title/appId to characterize the duplicates, and either tighten `importFromApp`'s resolution to be appId+title deterministic from this app's call site, or (if the duplication is caused by OpenRegister-side logic) file a `ConductionNL/openregister` issue documenting the reproduction and reference it in the design doc and code comment.

**Additional confirmed finding (code-reading, not just hypothesis):** `SettingsService::initialize()` only calls `loadSettings()` at all when its own private `shouldLoadSettings()` returns true, and that method compares this app's own semver (`appManager->getAppVersion()`, e.g. `"0.2.17"`) against `ConfigurationService::getConfiguredAppVersion($appId)` — which returns whatever value was last passed as the `version` argument to `importFromApp()`, i.e. the **register-content** version this same service computes (e.g. `"2.3.1+frag.9003c029"`). These are two unrelated versioning schemes on the same stored slot. `version_compare("0.2.17", "2.3.1+frag.9003c029", ">")` is `false` (verified), so once any import has ever stored a content version whose leading numeral is `>=` the app's own leading numeral (true here — content versions are `2.x`, the app is `0.x`), `shouldLoadSettings()` returns `false` **permanently**, and `loadSettings()` is never invoked again by any future upgrade, regardless of the fix in item 1. This is the exact, confirmed mechanism behind the live evidence's "versions DID differ ... yet nothing imported and no import log line appeared" — `loadSettings()`'s own "Attempting to import" log line never fires because the method is never entered. Fixing item 1 alone would ship a correct signature that is permanently unreachable. This change therefore also removes the broken pre-gate so `loadSettings()` always runs when `initialize()` runs (bounded to explicit admin action or the install/upgrade repair step — never per-request), leaving the now-correct, content-derived `importFromApp` version comparison as the sole and cheap gate on whether an actual import happens.

## New Dependencies
None.

## Impact
- `lib/Service/SettingsService.php` — `loadSettings()` version computation, new post-import verification method(s), status payload additions.
- `lib/Repair/InitializeSettings.php` — no signature change expected, but its logged/surfaced warnings will now include verification mismatches bubbling up from `initialize()` → `loadSettings()`.
- Settings status API/payload consumed by the admin settings screen (additive field only).
- `docs/` — new note on register-change delivery.
- Test suite — new/extended PHPUnit coverage in whatever `tests/` path already covers `SettingsService`.

## Cross-Project Dependencies
Potentially OpenRegister (`ConductionNL/openregister`), if the duplicate-configuration-row root cause is confirmed to live in `ConfigurationService`'s resolution logic — handled by filing an issue there, not by patching OpenRegister in this worktree.

## Risks

### Risk 1: Verification false positives on legitimately-optional schemas
**Severity:** Medium — **Mitigation:** scope verification to schema slugs actually declared in the effective merged register (monolith + fragments), not to a hardcoded expected list, so the check tracks whatever the register currently claims to ship.

### Risk 2: Duplicate-row cleanup accidentally deletes a row another app or process depends on
**Severity:** Medium — **Mitigation:** investigate and document first; only de-duplicate rows conclusively identified as this app's own stale/duplicate configuration entries, and prefer OpenRegister-side deterministic resolution (or an upstream issue) over destructive local cleanup.

### Risk 3: Content-hash version bump causes a one-time re-import storm across many upgraded instances
**Severity:** Low — **Mitigation:** this is intended and desired (it is exactly how the fix corrects already-drifted instances); `importFromApp` remains idempotent per its existing contract, and the hash is only recomputed on repair-step runs (install/upgrade), not on every request.

## Rollback Strategy
The change is additive to a single service method and a settings status payload field. Revert is a straightforward `git revert` of the commits on `wip/register-import-reliability`; no schema/data migrations are introduced, so no data rollback is needed. If the OpenRegister issue results in an upstream PR later, this app-side workaround can be removed independently once that lands.

## Open Questions
None — root cause and remediation approach are already confirmed in `context-brief.md` from live reproduction.
