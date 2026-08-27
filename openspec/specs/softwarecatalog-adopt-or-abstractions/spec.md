---
status: done
---

# stackiq-adopt-or-abstractions Specification

## Purpose
Aligns Stackiq with the shared OpenRegister and nc-vue abstractions: it ships an architectural manifest, resolves register and schema ids through RegisterResolverService instead of raw app-config, and stamps locale, translation-target, and tenant-organisation headers on OR fetches and writes. It also surfaces a translated-from badge in list views, adopts the tenant-context composable, and keeps all PHP code passing composer check:strict with container-run PHPUnit tests.
## Requirements
### Requirement: Stackiq MUST ship an architectural manifest at `src/manifest.json`

Stackiq MUST add `src/manifest.json` conforming to the
JSON Schema published by `@conduction/nextcloud-vue` at
`src/schemas/app-manifest.schema.json`. The manifest MUST be loaded
via `useAppManifest('stackiq', bundledManifest)` in
`src/main.js`.

The manifest MUST set:
- `$schema` to the published nc-vue schema URL
- `version` to a semver string
- `dependencies: ["openregister"]`
- a `menu` array including all six top-level entries (Apps,
  Components, Organisations, Catalogs, Concept Organisations,
  Settings)
- a `pages` array including index and detail pages for the four
  schema-driven entity types (apps, components, organisations,
  catalogs) plus the two `type: "custom"` pages
  (concept-organisations, settings)

#### Scenario: Manifest loads on app boot

- GIVEN Stackiq is installed and OR is enabled
- WHEN a user navigates to
  `/index.php/apps/stackiq`
- THEN `useAppManifest('stackiq', bundledManifest)` MUST
  be called before vue-router mounts
- AND on async-fetch of
  `/index.php/apps/stackiq/api/manifest` the loader MUST
  silently fall back to bundled on non-200

#### Scenario: Manifest validation fails build

- GIVEN a developer commits `src/manifest.json` with a missing
  `pages[].type` field
- WHEN `npm run check:manifest` runs
- THEN it MUST exit non-zero
- AND CI MUST fail

#### Scenario: Manifest declares OR dependency

- GIVEN `src/manifest.json`
- WHEN reading `manifest.dependencies`
- THEN it MUST contain `"openregister"`
- AND `CnAppRoot` (Tier 4, future) MUST render
  `CnDependencyMissing` if OR is disabled

#### Scenario: Sidebar slot wiring

- GIVEN the manifest declares
  `pages[id="apps-detail"].slots.sidebar = "AppSidebar"`
- AND `customComponents` registers `AppSidebar` to
  `src/sidebars/AppSidebar.vue`
- WHEN the apps detail page renders
- THEN the sidebar slot MUST resolve to `AppSidebar.vue`
- AND existing sidebar functionality (org info, related apps)
  MUST continue to work

### Requirement: Stackiq MUST consume `RegisterResolverService` for register / schema resolution

Stackiq MUST resolve register / schema IDs via `OCA\OpenRegister\Service\RegisterResolverService`. The five PHP classes that currently resolve register / schema IDs via `IAppConfig::getValueString` MUST migrate to `RegisterResolverService::resolveForObjectType()`:

- `lib/Service/ModuleComplianceService.php`
- `lib/Service/GebruikSyncService.php`
- `lib/Service/OrganizationSyncService.php`
- `lib/Service/ViewService.php`
- `lib/EventListener/UserProfileUpdatedEventListener.php`

Non-register `getValueString` calls (sync intervals, retry policy,
feature flags) MUST remain on `IAppConfig`.

#### Scenario: ModuleComplianceService uses resolver

- GIVEN `ModuleComplianceService` checks compliance for the
  `modules` register
- WHEN it resolves register / schema IDs
- THEN it MUST call `$this->resolver->resolveForObjectType('modules')`
- AND MUST NOT call `$this->config->getValueString(...)` for
  the `_register` / `_schema` suffixes

#### Scenario: Sync services use resolver

- GIVEN `GebruikSyncService` and `OrganizationSyncService` run
  their cron jobs
- WHEN each resolves its target register / schema
- THEN both MUST use `RegisterResolverService`
- AND non-register tunables (cron interval, retry attempts,
  external feed URL) MUST remain on `IAppConfig`

#### Scenario: Resolver fallback during upgrade window

- GIVEN OR is installed but the
  `RegisterResolverService` class is not yet present
- WHEN any of the five migrated classes is instantiated
- THEN the constructor MUST detect resolver absence via DI
  null-check
- AND MUST fall back to legacy `getValueString` path
- AND MUST log a deprecation warning

### Requirement: Stackiq OR fetches MUST pass `?_lang={user locale}`

All OR object fetches issued from Stackiq's frontend MUST
include `?_lang={BCP47}` set to the user's Nextcloud locale (region
tag stripped).

#### Scenario: Lang stamping on app fetch

- GIVEN the user's Nextcloud locale is `en_GB`
- WHEN `useOrClient().fetchObject({register: 7, schema: 21, uuid: 'xyz'})`
  is called
- THEN the URL MUST be
  `/index.php/apps/openregister/api/objects/7/21/xyz?_lang=en`

#### Scenario: Locale region tag stripped

- GIVEN `OC.getLocale()` returns `nl_NL`
- WHEN `orClient.js` builds the URL
- THEN `_lang=nl` MUST be the parameter value

### Requirement: Stackiq OR writes MUST stamp `X-Translation-Target-Language` when editing a non-default language

Stackiq OR writes MUST stamp `X-Translation-Target-Language` when editing a non-default language. When a user edits a translatable property in a non-default language, the PATCH/PUT request MUST include `X-Translation-Target-Language: {target}`.

When sync services write content known to be in a specific
language (e.g. GitHub README content in English), they MUST also
stamp the header.

#### Scenario: User edits English description on Dutch-default register

- GIVEN an application object with translatable property
  `description` and `sourceLanguage: "nl"`
- AND the user edits the English variant from an English UI
- WHEN the PATCH is issued
- THEN the body MUST be `{ "description": "..." }`
- AND the request MUST include
  `X-Translation-Target-Language: en`

#### Scenario: Sync service stamps source language

- GIVEN `GebruikSyncService` pulls a README from GitHub (English)
- WHEN it writes the content to OR
- THEN the request MUST include
  `X-Translation-Target-Language: en`
- AND OR MUST store the value under the `en` slot

### Requirement: Stackiq lists MUST display "(translated from {lang})" badge when served language differs from source

Index views (Apps, Components, Organisations, Catalogs) MUST show
a small "(translated from {sourceLanguage})" badge next to the
primary display field when the served language differs from the
object's `sourceLanguage` metadata. The badge MUST use the
canonical nc-vue badge style.

#### Scenario: Badge on translated row

- GIVEN an application with `sourceLanguage: "nl"` and English
  translation
- AND the user's locale is `en_GB`
- WHEN the application appears in the apps index
- THEN the row MUST show the English name
- AND a badge MUST appear with text `(translated from Dutch)`
  (i18n-keyed)

#### Scenario: No badge when served = source

- GIVEN an application with `sourceLanguage: "nl"` and the user's
  locale is `nl_NL`
- WHEN the application appears in the apps index
- THEN no translated-from badge MUST be rendered

### Requirement: Stackiq MUST consume `useTenantContext()` from nc-vue when surfacing tenant-scoped OR data

Stackiq MUST consume `useTenantContext()` from nc-vue when surfacing tenant-scoped OR data. Once `useTenantContext()` is exported from a versioned nc-vue release, Stackiq views that surface OR data MUST adopt the composable.

#### Scenario: Tenant switch refetches apps list

- GIVEN the user is viewing the apps index in tenant A
- WHEN the user switches to tenant B
- THEN `useTenantContext().activeOrganisationUuid` MUST update
- AND the Pinia apps store MUST clear its collection cache
- AND a fresh fetch MUST issue with B's session
- AND the rendered list MUST contain only apps scoped to B

#### Scenario: Tenant switch on detail navigates back

- GIVEN the user is viewing an application detail in tenant A
- WHEN the user switches to tenant B
- THEN the detail view MUST navigate back to the apps index
- AND the index MUST refetch with B's session

#### Scenario: Pre-release fallback

- GIVEN nc-vue's exported version does not yet include
  `useTenantContext`
- WHEN Stackiq imports it (try/catch guarded)
- THEN absence MUST NOT crash the app
- AND views MUST behave as single-tenant

### Requirement: Stackiq write paths MUST stamp `X-OpenRegister-Organisation` when a tenant is active

Stackiq write paths MUST stamp `X-OpenRegister-Organisation` when a tenant is active. When a user writes (POST/PATCH/PUT) to an OR object via the `orClient.js` composable, the request MUST include `X-OpenRegister-Organisation: {activeOrganisationUuid}` when `useTenantContext().activeOrganisationUuid` is non-null.

#### Scenario: Header stamping on write

- GIVEN `useTenantContext().activeOrganisationUuid` is
  `tenant-b-uuid`
- WHEN a user PATCHes an application
- THEN the request MUST include
  `X-OpenRegister-Organisation: tenant-b-uuid`
- AND OR's server-side multi-tenancy trait MUST validate the
  header against the session and reject on mismatch

#### Scenario: No header when no tenant active

- GIVEN `useTenantContext().activeOrganisationUuid` is null
- WHEN a user PATCHes an application
- THEN the request MUST NOT include
  `X-OpenRegister-Organisation`
- AND OR MUST stamp the active organisation from session
  (existing behaviour)

### Requirement: Stackiq PHP code MUST pass `composer check:strict`

All Stackiq PHP files MUST pass `composer check:strict`
(PHPCS, PHPMD, Psalm, PHPStan). This change MUST NOT introduce
new warnings, and SHOULD fix any pre-existing warnings in the
five files it touches.

#### Scenario: Strict check passes

- GIVEN the change is applied
- WHEN `composer check:strict` runs in the Stackiq
  container
- THEN exit code MUST be 0
- AND no new warnings MUST appear

### Requirement: Stackiq PHPUnit tests MUST run inside the Nextcloud container

Per project policy, unit tests MUST be invoked via:

```
docker exec -w /var/www/html/custom_apps/stackiq nextcloud \
  php vendor/bin/phpunit -c phpunit-unit.xml
```

#### Scenario: Container test invocation

- GIVEN the developer wants to run unit tests
- WHEN they invoke the container command above
- THEN tests for each migrated service MUST run
- AND each test MUST assert resolver-injection is exercised
- AND the legacy `getValueString` fallback path MUST be covered
  by a separate test that mocks resolver absence

