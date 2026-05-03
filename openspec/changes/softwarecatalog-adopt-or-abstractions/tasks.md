# Tasks — softwarecatalog-adopt-or-abstractions

> Spec-only change. No PR / merge / archive tasks here.

## Phase 1 — Manifest pilot (Tier 2)

- [ ] 1.1 Add `src/manifest.json`:
  - `$schema` to published nc-vue app-manifest schema URL
  - `version: "0.1.0"`
  - `dependencies: ["openregister"]`
  - top-level `menu` entries: Apps, Components, Organisations,
    Catalogs, Concept Organisations, Settings
  - `pages`:
    - `apps-index` — `type: "index"`, route `/apps`,
      `config.{register, schema, columns}`
    - `apps-detail` — `type: "detail"`, route `/apps/:id`
    - `components-index` / `components-detail`
    - `organisations-index` / `organisations-detail`
    - `catalogs-index` / `catalogs-detail`
    - `concept-organisations-index` (driven by
      `conceptOrganisatiesWidget.js`)
    - `settings` — `type: "custom"`,
      `component: "SettingsPage"`
- [ ] 1.2 Map existing `src/dialogs/*.vue` and `src/modals/*.vue` to
  the manifest's `customComponents` registry via
  `src/customComponents.js` (new file).
- [ ] 1.3 Map existing `src/sidebars/*.vue` to the manifest's
  per-page `slots.sidebar` overrides where applicable.
- [ ] 1.4 Add `npm run check:manifest` script to `package.json`.
- [ ] 1.5 Wire `useAppManifest('softwarecatalog', bundled)` in
  `src/main.js` after pinia setup, before router mount.
- [ ] 1.6 Wire `npm run check:manifest` into existing CI lint job.

## Phase 2 — `RegisterResolverService` consumption

- [ ] 2.1 Inventory of register/schema-resolving `getValueString`
  calls (file:line + key name + register/schema pair?):
  - `lib/Service/ModuleComplianceService.php`
  - `lib/Service/GebruikSyncService.php`
  - `lib/Service/OrganizationSyncService.php`
  - `lib/Service/ViewService.php`
  - `lib/EventListener/UserProfileUpdatedEventListener.php`
- [ ] 2.2 Inject `OCA\OpenRegister\Service\RegisterResolverService`
  into each of the five classes' constructors.
- [ ] 2.3 Replace each register/schema-resolving `getValueString`
  pair with a single `$this->resolver->resolveForObjectType(...)`
  call.
- [ ] 2.4 Verify non-register `getValueString` calls (sync
  intervals, feature flags) STAY on `IAppConfig` directly. Document
  each in tasks.md as "kept".
- [ ] 2.5 Add unit tests asserting resolver injection in each
  service.
- [ ] 2.6 Run `composer check:strict` — fix any pre-existing
  PHPCS / PHPMD / Psalm / PHPStan warnings touched by edits.

## Phase 3 — i18n wiring

- [ ] 3.1 Centralise OR fetch URL building in
  `src/composables/orClient.js` exposing
  `fetchObject({register, schema, uuid, lang})` and
  `patchObject({register, schema, uuid, body, targetLang})`.
- [ ] 3.2 The composable MUST set `?_lang={user locale}` (region
  tag stripped) on every fetch.
- [ ] 3.3 The composable MUST set `X-Translation-Target-Language`
  on writes when caller passes `targetLang`.
- [ ] 3.4 Migrate `src/store/applications.js`,
  `src/store/components.js`, `src/store/organisations.js`,
  `src/store/catalogs.js` (plus any other Pinia stores) onto the
  composable.
- [ ] 3.5 Add "(translated from {lang})" badge to list rows where
  served language ≠ `sourceLanguage`. Use canonical nc-vue badge
  style.
- [ ] 3.6 Add Cypress / Playwright e2e: switch user locale to
  `en_GB`, open an application with Dutch source, assert badge
  reads "(translated from Dutch)".

## Phase 4 — Multi-tenancy wiring (gated on nc-vue release)

- [ ] 4.1 Pin nc-vue version in `package.json` to the release
  exporting `useTenantContext`. Until released, guard import with
  try/catch.
- [ ] 4.2 In each index view (apps, components, organisations,
  catalogs): import composable, watch
  `activeOrganisationUuid`, on change call store
  `clearAllSubResources()` and refetch.
- [ ] 4.3 In each detail view: watch
  `activeOrganisationUuid`, on change navigate back to index.
- [ ] 4.4 In `orClient.js`, add option to stamp
  `X-OpenRegister-Organisation` on writes. Default ON when
  `activeOrganisationUuid` is non-null.
- [ ] 4.5 e2e: switch tenants, assert apps list refetches and
  excludes apps from previous tenant.

## Phase 5 — Manifest Tier 3 graduation (follow-up tracking)

- [ ] 5.1 Track prerequisites for Tier 3:
  - `type: "index"` and `type: "detail"` page-type contracts
    stable in nc-vue
  - SoftwareCatalog dialogs / modals / sidebars compatible with
    nc-vue's `customComponents` and `slots` resolution
  - Pinia stores compatible with manifest-driven `CnPageRenderer`
    data fetching
- [ ] 5.2 Open follow-up `softwarecatalog-manifest-tier-3` change
  once prerequisites met.

## Phase 6 — Documentation

- [ ] 6.1 Update / create `docs/architecture.md` covering:
  - manifest adoption
  - resolver consumption
  - i18n flow
  - multi-tenancy wiring
- [ ] 6.2 Add screenshots of the "(translated from)" badge to
  `docs/features/applications.md`.
- [ ] 6.3 Cross-link new docs from app's README.

## Phase 7 — Verification

- [ ] 7.1 `composer check:strict` passes.
- [ ] 7.2 `npm run lint` passes.
- [ ] 7.3 `npm run check:manifest` passes.
- [ ] 7.4 PHPUnit unit tests pass via container invocation:
  `docker exec -w /var/www/html/custom_apps/softwarecatalog
  nextcloud php vendor/bin/phpunit -c phpunit-unit.xml`
- [ ] 7.5 e2e tests for i18n badge and tenant-switch refetch
  pass.
- [ ] 7.6 Manual smoke: enable OR + SoftwareCatalog on a clean
  dev Nextcloud, list applications, switch tenants, edit a
  Dutch-source application from an English UI, confirm UX matches
  spec.
