# Context Brief: register-import-reliability

## What
Make register/schema changes **actually reach an installed instance**, and make a failure to do so **loud instead of silent**. Closes softwarecatalog#391.

## Why (evidence — reproduced live 2026-07-24 on NC 34 / :8080)
Deployed the `development` head with all 8 market-gap changes, bumped `appinfo/info.xml` 0.2.17→0.2.18, ran `occ upgrade`. Upgrade reported **success** and ran `InitializeSettings`. Yet in OpenRegister:
- `bioMaatregel` / `sbomComponent` schemas — **not created**
- `module` — missing all 7 new properties (`bbnLevel`, `dpiaStatus`, `dpiaDate`, `dpiaVolgendeBeoordeling`, `dpiaDocumentRef`, `verwerkingsregisterRef`, `eolProductSlug`)
- `gebruik` — missing all 3 TIME fields (`timeClassification`, `timeRationale`, `timeReviewDate`)

Eight merged features were **dead on an upgraded instance while green in CI**. Only `POST /api/settings/import {"force": true}` applied them.

## Root cause (confirmed by reading the code)
`SettingsService::loadSettings()` (~lines 1462-1550) computes the import version as:
```
$configVersion = $softwareCatalogSettings['info']['version']            // register JSON's OWN info.version
               . ('+frag.' . substr(md5($fragmentSig), 0, 8))           // md5 of Settings/register.d/*.json only
```
and passes it to `ConfigurationService::importFromApp(..., version: $configVersion, force: $force)`, which is version-gated.

**The monolith's own content is NOT part of that signature.** Per ADR-037 each change should drop a `register.d/<change>.json` fragment, but all 8 wave changes edited the monolith `lib/Settings/softwarecatalogus_register.json` directly — so unless a human also remembers to bump `info.version`, the computed version is byte-identical and the import is a **silent no-op**. (An independent session hit exactly this on opencatalogi.)

Secondary: `oc_openregister_configurations` holds **three** rows titled `Software Catalog Register` (ids 7 and 117 at `2.3.1+frag.9003c029`, id 81 at `2.3.0`), which may also confuse the gate — on this instance the versions DID differ (JSON was `2.4.0`) yet nothing imported and no import log line appeared, so the duplicate rows are a live suspect.

## Scope
IN:
1. **Fold the monolith into the signature** — include a hash of `softwarecatalogus_register.json`'s own content in `$configVersion` (e.g. `+base.<md5-8>`), so ANY register change (monolith or fragment) produces a new version and re-imports. This fixes the defect class permanently rather than relying on humans bumping `info.version`.
2. **Investigate + handle the duplicate configuration rows** — determine how three rows for one app arose, make `importFromApp` resolution deterministic from this app's side (and/or de-duplicate), and document the finding. If the dedupe must happen in OpenRegister, file an issue there instead of hacking around it here.
3. **Make silence impossible** — after import, verify the live schema set matches the shipped register (every schema slug in the effective register exists in OpenRegister, and configured schema ids resolve). On mismatch: log a WARNING and surface it in the admin settings status payload. A no-op import must never look like success.
4. **Regression test** — a test that would have caught this: assert the computed `$configVersion` CHANGES when the monolith content changes (not just when a fragment changes).
5. Docs note in `docs/` on how register changes reach an instance + the ADR-037 fragment preference.

OUT: rewriting OpenRegister's `ConfigurationService`; migrating the 8 already-merged wave schemas into fragments (they are already live via the forced import — a separate cleanup if wanted); any UI beyond the status/warning surface.

## Current state (read first)
- `lib/Service/SettingsService.php` — `loadSettings()` (signature computation + `importFromApp` call), `initialize()`, `getVoorzieningenConfig()`, `normalizeVoorzieningenConfig()`.
- `lib/Repair/InitializeSettings.php` — early-returns when `last_initialized_version === $currentAppVersion`; calls `SettingsService::initialize()`.
- `lib/Settings/register.d/README.md` — the ADR-037 fragment contract.
- `openspec/specs/settings-service/spec.md`, `openspec/specs/repair-init/spec.md` — the specs this change extends.

## Design constraints
- ADR-001: no custom tables. ADR-008 Controller→Service. ADR-009 tests. ADR-005 i18n for any new user-facing string.
- **Do not weaken the version gate into "always re-import"** — importing on every request would be a performance regression. The signature must be content-derived and cheap (md5 of an already-read string).
- Spec deltas MUST use `### Requirement: <name>` headers, and the MUST/SHALL must be on the requirement's **first physical line** (validator only reads line 1). Avoid angle brackets in requirement bodies (validator false-positives).
- `@spec` anchors must point at the CANONICAL `openspec/specs/<capability>/spec.md#requirement-<kebab>` — never `openspec/changes/...`, because `openspec archive` moves the change dir and breaks those anchors.
