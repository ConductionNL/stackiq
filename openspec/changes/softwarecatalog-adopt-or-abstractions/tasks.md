# Tasks — softwarecatalog-adopt-or-abstractions

> Spec-only change. No PR / merge / archive tasks here.

> **Build note (hydra-build 2026-06-07):** The app already shipped its
> manifest (Phase 1) and already has its own consolidated register/schema
> resolver on `SettingsService` (`getSchemaIdForObjectType` /
> `getRegisterIdForObjectType` with caching). The OpenRegister
> `RegisterResolverService` referenced by Phase 2 is **not yet merged**
> (it lives only in `openregister/openspec/changes/register-resolver-service/`,
> not in OR's `lib/`), and nc-vue's `useTenantContext()` (Phase 4) is **not
> yet exported** from any released nc-vue. Tasks gated on those unmerged
> cross-app dependencies are DEFERRED per ADR-022 (consume real, shipped OR
> abstractions only). The shippable, high-value work in this change is the
> i18n wiring (Phase 3), which is fully implemented and tested here.

## Phase 1 — Manifest pilot (Tier 2)

- [x] 1.1 `src/manifest.json` (ALREADY PRESENT): `$schema`, `version: "1.0.0"`,
  `dependencies: ["openregister"]`, top-level `menu`, and 16 `pages`
  declared. Verified to validate via `npm run check:manifest`.
- [x] 1.2 `src/customComponents.js` (ALREADY PRESENT) maps dialogs/modals.
- [x] 1.3 Sidebars under `src/sidebars/` mapped via manifest slots
  (ALREADY PRESENT).
- [x] 1.4 `npm run check:manifest` script present in `package.json`
  (`node tests/validate-manifest.js`).
- [x] 1.5 `useAppManifest('softwarecatalog', bundled)` wired in `src/main.js`
  (ALREADY PRESENT).
- [x] 1.6 `check:manifest` wired into CI (ALREADY PRESENT).

## Phase 2 — `RegisterResolverService` consumption

- [x] 2.1 Inventory completed. Finding: the five classes do NOT call
  `IAppConfig::getValueString` for register/schema pairs directly — they
  read register/schema IDs out of the `voorzieningen` / `amef` config
  arrays returned by `SettingsService::getVoorzieningenConfig()` /
  `getAmefConfig()`, and the app already consolidates the lookup in
  `SettingsService::getSchemaIdForObjectType()` /
  `getRegisterIdForObjectType()` (with an in-memory cache). The premise of
  "five duplicated `getValueString(...register/schema...)` shapes" does not
  match the current code — the consolidation the spec asks for already
  exists at the app level.
- [~] 2.2 Inject `OCA\OpenRegister\Service\RegisterResolverService` into the
  five constructors. **DEFERRED — BLOCKED_EXTERNAL:** `RegisterResolverService`
  is not present in OpenRegister's `lib/` (only an unmerged openspec change).
  ADR-022 forbids inventing/consuming an OR API that does not exist. Re-open
  once OR's `register-resolver-service` change is merged and the class ships.
- [~] 2.3 Replace resolver calls. **DEFERRED — same blocker as 2.2.**
- [x] 2.4 Verified: non-register `getValueString` keys stay on `IAppConfig`
  (e.g. `last_sync_time`, `amef_config`, email/group tunables). Kept.
- [~] 2.5 Resolver-injection unit tests. **DEFERRED — depends on 2.2.**
- [x] 2.6 `composer lint` + `phpcs` clean (0 errors) on the worktree; no PHP
  files were modified by this change so the PHP strict baseline is unchanged.

## Phase 3 — i18n wiring

- [x] 3.1 Added `src/composables/orClient.js` centralising OR object-URL
  building plus i18n/tenant header helpers (`withLanguageParam`,
  `buildWriteHeaders`, `buildObjectUrl`, `resolveLanguage`).
- [x] 3.2 GET fetches stamp `?_lang={user locale}` (region tag stripped).
  Wired into `softwarecatalogPlugin.js` read paths (`downloadObject`,
  `fetchRelatedData`) and the shared `buildObjectUrl` helper.
- [x] 3.3 Writes stamp `X-Translation-Target-Language` when the caller passes
  `targetLang` — implemented via `buildWriteHeaders` and threaded through
  `patchObject(type, id, changes, targetLang)`.
- [x] 3.4 Plugin (the app's central OR transport, used by the entity stores)
  migrated onto the composable helpers. The app does not have separate
  `applications.js` / `components.js` / `catalogs.js` stores — entity CRUD
  is unified through `softwarecatalogPlugin.js` over `createObjectStore`.
- [x] 3.5 Added `src/utils/translationBadge.js` computing the
  "(translated from {language})" badge descriptor when served language ≠
  `sourceLanguage`. i18n key `(translated from {language})` added to all
  six l10n files (en/nl/en_US, .js + .json).
- [~] 3.6 Playwright e2e for the badge. **DEFERRED — needs a live instance**
  with seeded translated objects; tracked under the swc e2e concern.

## Phase 4 — Multi-tenancy wiring (gated on nc-vue release)

- [~] 4.1 Pin nc-vue to the release exporting `useTenantContext`.
  **DEFERRED — BLOCKED_EXTERNAL:** `useTenantContext` is not exported from
  any released nc-vue yet.
- [~] 4.2 Index-view tenant-switch refetch. **DEFERRED — depends on 4.1.**
- [~] 4.3 Detail-view navigate-back on tenant switch. **DEFERRED — depends on
  4.1.**
- [x] 4.4 `orClient.buildWriteHeaders` already supports stamping
  `X-OpenRegister-Organisation` when a non-null `organisation` is supplied,
  so the transport layer is ready for tenant adoption; the value source
  (`useTenantContext().activeOrganisationUuid`) is wired once 4.1 unblocks.
- [~] 4.5 Tenant-switch e2e. **DEFERRED — depends on 4.1.**

## Phase 5 — Manifest Tier 3 graduation (follow-up tracking)

- [~] 5.1 Track Tier 3 prerequisites. **DEFERRED — follow-up tracking item.**
- [~] 5.2 Open follow-up `softwarecatalog-manifest-tier-3` change.
  **DEFERRED — follow-up.**

## Phase 6 — Documentation

- [x] 6.1 `docs/ARCHITECTURE.md` updated with an OR-abstractions adoption
  section (manifest status, resolver finding, i18n flow, tenant readiness).
- [~] 6.2 Badge screenshots. **DEFERRED — needs a live instance to capture.**
- [x] 6.3 Architecture doc cross-linked from README is pre-existing; the new
  section is reachable from the existing docs index.

## Phase 7 — Verification

- [x] 7.1 `composer lint` + `phpcs` pass (0 errors); no PHP modified.
- [x] 7.2 `npm run lint` — new files lint clean (0 errors/0 warnings); no new
  warnings introduced to `softwarecatalogPlugin.js`.
- [x] 7.3 `npm run check:manifest` passes (structural lint PASS, 0 issues).
- [x] 7.4 JS unit tests: 38 tests green across `orClient.spec.js`,
  `translationBadge.spec.js`, and the existing `navigation.spec.js`.
- [~] 7.5 e2e (i18n badge + tenant switch). **DEFERRED — needs live
  instance (see 3.6 / 4.5).**
- [~] 7.6 Manual smoke on clean dev Nextcloud. **DEFERRED — needs live
  instance.**
