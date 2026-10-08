# App-id rename: softwarecatalog to stackiq

## ADDED Requirements

### Requirement: The app identity is stackiq

The app SHALL declare `stackiq` as its Nextcloud app id in `appinfo/info.xml` (formerly `softwarecatalog`) and `Stackiq` as its `<namespace>` (formerly `SoftwareCatalog`), and SHALL use `stackiq` consistently as its l10n domain, its URL prefix (`/apps/stackiq/...`), its route-name prefix, its webpack bundle prefix, its DOM mount-point id, and its composer/npm package name. The PHP root namespace SHALL be `OCA\Stackiq` (formerly `OCA\SoftwareCatalog`).

#### Scenario: The app enables and serves its SPA under the new id

- **GIVEN** a Nextcloud instance with OpenRegister installed
- **WHEN** `occ app:enable stackiq` runs and a user opens `/apps/stackiq/`
- **THEN** the navigation entry MUST resolve via route `stackiq.dashboard.page`, the SPA MUST mount on `#stackiq`, and its scripts MUST load from `/apps/stackiq/js/stackiq-main.js`
- @e2e exclude Covered by the existing e2e suite, which navigates the app by its `/apps/<id>/` base URL; the rename retargets that suite rather than adding a scenario to it.

#### Scenario: Translations resolve under the new domain

- **GIVEN** a browser with the Dutch locale
- **WHEN** the SPA calls `t('stackiq', 'Applications')`
- **THEN** the string MUST resolve from `l10n/nl.js`, whose `OC.L10N.register` domain is `stackiq`
- @e2e exclude Assertable offline; `tests/l10n/check-l10n.js` enforces domain/msgid parity across all locales.

### Requirement: Stored app config survives the rename

On both fresh install and upgrade, the app SHALL copy every `oc_appconfig` key stored under the old app id `softwarecatalog` into the `stackiq` namespace before any other repair step writes app config.

The enumeration SHALL be exhaustive (`IAppConfig::getKeys()` over the old app id), the copy SHALL be non-destructive (the old rows are never deleted) and idempotent (a key already present under the new id is left alone), and the Nextcloud-reserved keys `enabled`, `installed_version` and `types` SHALL be skipped.

#### Scenario: An operator's admin settings survive the rename

- **GIVEN** an instance where `softwarecatalog` has `federation_enabled = true` and `federation_directory_url` set
- **WHEN** the renamed app is installed and the repair step runs
- **THEN** both keys MUST be readable under app id `stackiq` with their original values, and the original `softwarecatalog` rows MUST still exist
- @e2e exclude Repair-step behaviour with no UI surface; covered by `tests/Unit/Repair/MigrateAppConfigKeysTest.php`.

#### Scenario: The reserved enabled key is never copied

- **GIVEN** an instance where `AppManager::enableApp()` has written `enabled` as a MIXED-typed value
- **WHEN** the repair step runs
- **THEN** it MUST skip `enabled`, `installed_version` and `types`, because copying `enabled` with `setValueString()` stores it as STRING and the next `occ app:enable` then fails permanently with `AppConfigTypeConflictException`, a conflict hit before the app can run anything that would repair it
- @e2e exclude Repair-step behaviour with no UI surface; covered by `tests/Unit/Repair/MigrateAppConfigKeysTest.php`.

#### Scenario: The step runs before any step that writes config

- **GIVEN** `InitializeSettings` also writes app config
- **WHEN** the repair steps are ordered in `appinfo/info.xml`
- **THEN** the migration step MUST be declared FIRST in both `<install>` and `<post-migration>`, because a step that writes first makes the key look "already present" and strands the operator's real value in the old namespace forever
- @e2e exclude Declaration ordering in `appinfo/info.xml`; verified by reading the manifest, not by a browser.

### Requirement: Stored user preferences survive the rename

On both fresh install and upgrade, the app SHALL copy every `oc_preferences` value stored under the old app id `softwarecatalog`, for every seen user, into the `stackiq` namespace.

The user enumeration SHALL use `IUserManager::callForSeenUsers()` combined with `IConfig::getUserKeys()`. It SHALL NOT use `getUsersForUserValue()`: that method matches on a VALUE, so over the open value set this app stores (`pref_*` holds arbitrary user-chosen view state) it migrates nothing and reports success.

#### Scenario: A user's saved view preference survives the rename

- **GIVEN** a user who has stored `pref_applications-view = table` under app id `softwarecatalog`
- **WHEN** the renamed app is installed and the repair step runs
- **THEN** `getUserValue($uid, 'stackiq', 'pref_applications-view')` MUST return `table`
- @e2e exclude Repair-step behaviour with no UI surface; covered by `tests/Unit/Repair/MigrateUserPreferencesTest.php`.

#### Scenario: A failure in one user does not abort the install

- **GIVEN** the step runs under `<install>`, the only hook that fires on the fresh install an app-id rename performs
- **WHEN** any read or write throws
- **THEN** the exception MUST be caught and logged rather than escaping, because an escaping throw aborts the install and the app never enables at all
- @e2e exclude Repair-step behaviour with no UI surface; covered by `tests/Unit/Repair/MigrateUserPreferencesTest.php`.

### Requirement: Stored background job classes survive the rename

The app SHALL deregister the four `oc_jobs` rows whose stored `class` string carries the old `OCA\SoftwareCatalog\BackgroundJob\` prefix, so that Nextcloud's own `<background-jobs>` registration of the `OCA\Stackiq\BackgroundJob\` classes is the only surviving registration.

#### Scenario: The orphaned job rows are removed

- **GIVEN** an instance whose `oc_jobs` table holds `OCA\SoftwareCatalog\BackgroundJob\ContractStatusJob`
- **WHEN** the repair step runs
- **THEN** that row MUST be removed, because the class no longer exists: the job silently never runs again and nothing reports it
- @e2e exclude Repair-step behaviour with no UI surface; covered by `tests/Unit/Repair/MigrateBackgroundJobClassesTest.php`.

### Requirement: Externally owned identifiers stay frozen

The rename SHALL NOT change any identifier whose authority lives outside this app. Specifically it SHALL leave unchanged: the Nextcloud group ids `software-catalog-users` and `software-catalog-admins`; the dashboard widget id `softwarecatalog_concept_organisaties_widget`; the `lib/Settings/softwarecatalogus_register.json` filename; the `softwarecatalog-docs` worker that serves the documentation; the App Store signing request `.nextcloud/certificates/softwarecatalog.csr`; VNG's own `softwarecatalogus.nl` identifiers; and every other Conduction app's id and namespace.

The appId this app passes to OpenRegister's configuration importer is NOT frozen: it SHALL be `Application::APP_ID` (`stackiq`), and the repair steps `MigrateRegisterSlug` and `MigrateSchemaApplicationId` SHALL move this app's existing register slug and schema `application` column onto the new id before the import runs, so the import updates the existing rows instead of creating second, empty ones.

#### Scenario: Group membership still resolves after the rename

- **GIVEN** users who are members of the Nextcloud group `software-catalog-admins`
- **WHEN** an authorization check runs after the rename
- **THEN** it MUST still test membership of `software-catalog-admins`, because Nextcloud stores membership by group id in `oc_group_user` and a renamed literal makes every check miss, silently dropping everyone's permissions rather than erroring
- @e2e exclude Asserted at the unit level against the frozen literals; no UI path exercises a group rename.

#### Scenario: A user's dashboard keeps the concept organisations widget

- **GIVEN** a user who added the concept organisations widget to their Nextcloud dashboard before the rename
- **WHEN** the dashboard loads after the rename
- **THEN** `ConceptOrganisatiesWidget::getId()` MUST still return `softwarecatalog_concept_organisaties_widget`, because the Dashboard app stores each user's chosen widgets by widget id in its own `oc_preferences` namespace, which this app's repair steps cannot reach
- @e2e exclude Cross-app persistence behaviour; asserted at the unit level against the frozen literal.

#### Scenario: The OpenRegister import keeps addressing the existing register and schemas

- **GIVEN** OpenRegister holds this app's register and schemas created under the old id `softwarecatalog`
- **WHEN** the register import runs with `appId = "stackiq"`
- **THEN** `MigrateRegisterSlug` and `MigrateSchemaApplicationId` MUST already have moved the register slug and each schema's `application` onto `stackiq`, so OpenRegister finds the existing rows, and a schema that already has a twin under `stackiq` MUST be logged and left alone rather than merged
- @e2e exclude Repair-step behaviour with no UI surface; covered by the repair steps' unit tests.

#### Scenario: Documentation publishes to a host that resolves

- **GIVEN** `stackiq.conduction.nl` answers HTTP 200 and `softwarecatalog.conduction.nl` is the retired host
- **WHEN** the documentation workflow deploys
- **THEN** the `cname` and `canonical-host` inputs MUST be `stackiq.conduction.nl`, `docs-hosts` MUST still list `softwarecatalog.conduction.nl` so old links answer 301 instead of going dark, and `worker-name` MUST stay `softwarecatalog-docs`
- @e2e exclude CI workflow input; verified by probing both hosts, not by a browser test.
