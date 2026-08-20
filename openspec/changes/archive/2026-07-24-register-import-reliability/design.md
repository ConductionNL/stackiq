# Design: register-import-reliability

## Architecture Overview
`SettingsService::loadSettings()` reads the monolith `lib/Settings/softwarecatalogus_register.json`, deep-merges any `lib/Settings/register.d/*.json` fragments on top, computes a `$configVersion` string, and calls OpenRegister's `ConfigurationService::importFromApp(appId, data, version, force)`. `importFromApp` is version-gated: it skips the import when the passed `version` matches the version already stored on the `Configuration` entity for that app. Today `$configVersion` is derived from `info.version` (the monolith's self-declared version field) plus a hash of the fragment files only — never from the monolith body itself. This design closes that gap by making the signature fully content-derived, adds a post-import verification pass, and documents the duplicate-configuration-row investigation and its resolution.

```
loadSettings()
  ├─ read softwarecatalogus_register.json          (monolith)
  ├─ merge register.d/*.json fragments              (ADR-037)
  ├─ compute $configVersion = info.version
  │                           + '+base.' + md5(monolith raw content)[0:8]   <- NEW
  │                           + '+frag.' + md5(fragmentSig)[0:8]            <- existing
  ├─ importFromApp(appId, mergedData, $configVersion, $force)
  └─ verifyRegisterAgainstEffectiveConfig(mergedData)                       <- NEW
        ├─ every schema slug in mergedData.components.schemas exists in OpenRegister
        ├─ every configured schema id (getSchemaIdForObjectType) resolves
        └─ on mismatch: log WARNING + attach to $results['registerVerification']
```

## Goals / Non-Goals
**Goals:**
- Any edit to the monolith OR a fragment produces a different `$configVersion`, guaranteeing `importFromApp` never silently no-ops on real content changes.
- A no-op or partially-applied import becomes observable: a WARNING in the log and a field in the settings status payload that an admin (or a future automated check) can act on.
- Understand and resolve (or escalate) the duplicate `Software Catalog Register` configuration rows.
- Keep `loadSettings()` cheap — it already reads the monolith and fragment files into memory; the new hash reuses that same string, no extra I/O.

**Non-Goals:**
- Do not weaken the version gate into "always re-import" — that would be a performance regression on every repair-step run.
- Do not patch OpenRegister's `ConfigurationService` in this repo.
- Do not migrate the 8 already-merged wave schemas into fragment files.
- Do not build new admin UI; the warning surfaces through the existing settings status payload/response only.

## Decisions

### Decision 1: Fold a monolith content hash into `$configVersion` as `+base.<md5-8>`
`$configVersion` becomes `info.version . '+base.' . substr(md5($softwareCatalogContent), 0, 8) . ('+frag.' . substr(md5($fragmentSig), 0, 8) if fragments exist)`. `$softwareCatalogContent` is the raw file string already read at the top of `loadSettings()` (`file_get_contents($softwareCatalogPath)`), so this costs one extra `md5()` call on a string already in memory — negligible, and it only runs during the repair step (install/upgrade), not per-request.

**Alternative considered:** hash the parsed+merged `$softwareCatalogSettings` array (post-merge) instead of the raw monolith string. Rejected: hashing an array requires stable serialization (`json_encode` with fixed key order) to be deterministic across PHP versions/opcache runs, and conflates "monolith changed" with "merge result changed" — the raw string hash is simpler, deterministic by construction, and keeps the `+base`/`+frag` split legible for debugging (an admin can see from the version string alone whether the base file or a fragment changed).

### Decision 2: Post-import verification walks the *effective* merged register, not a hardcoded expectation
After `importFromApp` returns (success path), iterate `$softwareCatalogSettings['components']['schemas']` (post-merge, i.e. monolith + all fragments — the same data structure just imported) and check each schema's slug exists in OpenRegister via `getSchemaIdForObjectType()` / the register/schema service already injected into `SettingsService`. Separately, for the object types this app hardcodes lookups for, confirm the resolved schema id is non-null. Record misses into `$results['registerVerification'] = ['ok' => bool, 'missingSchemas' => [...], 'unresolvedObjectTypes' => [...]]`, log one WARNING per miss, and let `initialize()` propagate `registerVerification` into its own `$results` (already has an `errors`/`warnings` array pattern used elsewhere in the file) so `InitializeSettings::run()` surfaces it via `$output->warning()` exactly like other partial-failure paths already do.

**Alternative considered:** compare against a static list of "expected" schemas maintained by hand. Rejected: it drifts (this defect *is* about drift) and duplicates information already present in the register JSON itself — verifying against the effective merged register is self-updating as fragments/monolith evolve.

### Decision 3: Duplicate configuration rows — investigate via read-only query, resolve app-side if safely attributable, else file upstream issue
Read `oc_openregister_configurations` (via OpenRegister's existing configuration lookup service, not a raw custom query) filtered by this app's id/title to characterize the three rows (ids 7, 117, 81 observed live). If the duplication is explained by this app's own call pattern (e.g. `importFromApp` being invoked with an app-identifying key that isn't stable, or multiple call sites creating rows independently), fix the call site so this app always resolves/updates the same row deterministically. If the duplication instead stems from `ConfigurationService`'s own row-matching logic (e.g. matching on title rather than a stable appId+slug key), do not patch OpenRegister from this worktree — file `gh issue create -R ConductionNL/openregister` describing the reproduction (three rows for one app, two different version strings) and reference the issue number in this app's code comment and in `docs/`.

**Alternative considered:** directly `DELETE` the stale rows via a repair step. Rejected as a first move — ADR-001/safety: deleting configuration rows without confirming which one is authoritative risks orphaning whatever OpenRegister-side state points at a given row id; investigate and attribute before any destructive action, per the task's explicit instruction and the workspace's "never range-delete" safety rule.

**Outcome:** code review of `OCA\OpenRegister\Service\Configuration\ImportHandler::importFromApp()`, `ConfigurationMapper::findByApp()`/`findBySourceUrl()`, and `MultiTenancyTrait::applyOrganisationFilter()` shows both lookup paths organisation-scope their query (`allowNullOrg: false` by default), so an app-owned configuration row can be invisible to a caller whose active-organisation context differs from the row's — `importFromApp()` then concludes "no existing configuration found" and creates a duplicate. This is not attributable to a defect in this app's own call site (there is only one call site, `SettingsService::loadSettings()` → `getConfigurationService()->importFromApp()`, and it always passes the same `appId`); the true fix is upstream. Filed [openregister#2072](https://github.com/ConductionNL/openregister/issues/2072) with the full mechanism and a suggested fix (don't organisation-scope `is_local` app-owned rows, or add a DB-level uniqueness constraint on `app`). Live DB confirmation of the 3 existing rows' `organisation` column was blocked by the shared Postgres instance being in recovery mode at investigation time — the issue asks a maintainer with DB access to close that loop.

### Decision 4: Remove `shouldLoadSettings()`'s broken app-semver-vs-content-version pre-gate
Code reading during design confirmed a second, independently sufficient cause of "no import log line appeared" in the live evidence: `initialize()` only calls `loadSettings()` when `shouldLoadSettings()` returns true, and that method runs `version_compare($currentAppVersion, $storedVersion, '>')` where `$currentAppVersion` is this app's own semver (`"0.2.17"`) and `$storedVersion` is `ConfigurationService::getConfiguredAppVersion($appId)` — which, per OpenRegister's `ImportHandler::importFromApp()`, stores exactly the `version` argument `loadSettings()` itself passed on the *previous* call, i.e. the register-content version (`"2.3.1+frag.9003c029"`). Verified: `version_compare("0.2.17", "2.3.1+frag.9003c029", ">")` returns `false`. Because register-content versions here start at `"2."` and the app's own semver is `"0.x"`, this comparison can never return true once any import has run — `shouldLoadSettings()` returns `false` forever afterward, and `loadSettings()` is never invoked again by any future upgrade. This makes Decision 1's fix (the content hash) permanently unreachable in exactly the scenario the live evidence describes.

The fix: `shouldLoadSettings()` always returns `true`. The dead app-semver-vs-content-version comparison is removed and replaced with a docblock explaining why. `loadSettings()` is only ever reached from an explicit admin-triggered controller action or the install/upgrade repair step (`InitializeSettings::run()`, which has its own `last_initialized_version` gate against repeated runs within the same app version) — never from a per-request code path — so always attempting it is cheap: a couple of file reads plus `md5()` calls. The actual (potentially expensive) schema/register write remains gated by `importFromApp`'s own comparison of two like-for-like content versions, which Decision 1 makes correct.

**Alternative considered:** keep an outer gate but fix its comparison to be like-for-like (compare freshly-computed `$configVersion` against `getConfiguredAppVersion()`). Rejected: that duplicates the exact comparison `importFromApp` already performs internally, doubling the places that must independently agree on "is this newer" semantics — a strict superset of Decision 1's fix with no added correctness, and more surface area to drift out of sync again in the future (which is precisely the defect class this change closes).

## Risks / Trade-offs
- [Content-hash bump forces one re-import per instance on first deploy of this change] → Mitigation: intended; this is exactly the corrective effect needed for already-drifted instances, and `importFromApp` is idempotent per its existing contract (re-importing identical data is a safe no-op at the data level, just not at the version-gate level).
- [Verification adds a schema-resolution round trip after every real import] → Mitigation: it only runs on the (rare) path where the version-gate decided a re-import was needed, not on every request; cost is bounded by the number of schemas in the register (dozens, not thousands).
- [Duplicate-row root cause may turn out to be entirely OpenRegister-side, leaving three rows in place until upstream fixes it] → Mitigation: filing the issue plus documenting the deterministic resolution on this app's side (so we always know which row is "ours") is the correct scope boundary per the proposal; it doesn't block the primary defect fix (Decision 1) from shipping.
- [Removing `shouldLoadSettings()`'s gate makes `loadSettings()` run on every `initialize()` call instead of being skipped] → Mitigation: `initialize()` is already bounded to explicit admin action or the install/upgrade repair step (never per-request), and the work `loadSettings()` now always does when skipped previously — reading two small files and hashing them — is negligible next to the HTTP/DB round trips already in that path; the actual expensive write remains gated by `importFromApp`.

## Migration Plan
No database schema changes (ADR-001 — no custom tables). Deployment is a normal app release: bump `info.xml` version, ship, `occ upgrade` runs the existing `InitializeSettings` repair step, which now computes a version signature that differs from any prior stored value (because the monolith content hash is new), forcing exactly one re-import that reconciles the instance to the current register state. Rollback is a `git revert` — reverting drops the `+base.<hash>` suffix, and the next repair run's version string again matches the last-imported value already recorded on the instance (no further action needed).

## Open Questions
None outstanding — resolved during design: hash source (raw monolith string, not merged array), verification scope (effective merged register, not static list), and duplicate-row handling posture (investigate + deterministic resolution or upstream issue, never blind delete).
