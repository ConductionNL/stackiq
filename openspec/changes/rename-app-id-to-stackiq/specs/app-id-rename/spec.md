---
status: proposed
---

# App-Id Rename: stackiq to stackiq

## Purpose

The app's Nextcloud id moves from `stackiq` to `stackiq`, and its PHP
namespace from `OCA\Stackiq` to `OCA\Stackiq`, without losing a single
stored value and without rewriting any identifier another system owns.

The reason this needs a spec at all is that **the failure mode of getting it
wrong is silence.** Nextcloud namespaces `oc_appconfig` and `oc_preferences` by
app id and offers no in-place app-id upgrade. Every reader in this codebase
supplies a default, so a row that becomes unreachable does not raise — the
setting simply reverts to its default and the instance looks freshly installed.
The same shape applies to every identifier stored outside this app's own
namespace: a group id, a dashboard widget id, an OpenRegister configuration
appId, a DNS name. Renaming any of those produces a working-looking app that
quietly does less.

---

## Requirements

### Requirement: The App Identity Is stackiq

The app SHALL declare `stackiq` as its Nextcloud app id in `appinfo/info.xml`
and `Stackiq` as its `<namespace>`, and SHALL use `stackiq` consistently as its
l10n domain, its URL prefix (`/apps/stackiq/...`), its route-name prefix, its
webpack bundle prefix, its DOM mount-point id, and its composer/npm package
name. The PHP root namespace SHALL be `OCA\Stackiq`.

#### Scenario: The app enables and serves its SPA under the new id

- **GIVEN** a Nextcloud instance with OpenRegister installed
- **WHEN** `occ app:enable stackiq` runs and a user opens `/apps/stackiq/`
- **THEN** the navigation entry MUST resolve via route `stackiq.dashboard.page`, the SPA MUST mount on `#stackiq`, and its scripts MUST load from `/apps/stackiq/js/stackiq-main.js`
- @e2e exclude Covered by the existing e2e suite, which navigates the app by its `/apps/<id>/` base URL; the rename retargets that suite rather than adding a scenario to it.

#### Scenario: Translations resolve under the new domain

- **GIVEN** a browser with the Dutch locale
- **WHEN** the SPA calls `t('stackiq', 'Applications')`
- **THEN** the string MUST resolve from `l10n/nl.js`, whose `OC.L10N.register` domain is `stackiq`
- @e2e exclude Assertable offline; `tests/l10n/check-l10n.js` enforces domain/msgid parity across all 38 locales.

### Requirement: Stored App Config Survives The Rename

On both fresh install and upgrade, the app SHALL copy every `oc_appconfig` key
stored under the app id `stackiq` into the `stackiq` namespace before
any other repair step writes app config.

The enumeration SHALL be exhaustive (`IAppConfig::getKeys()` over the old app
id), the copy SHALL be non-destructive (the old rows are never deleted) and
idempotent (a key already present under the new id is left alone), and the
Nextcloud-reserved keys `enabled`, `installed_version` and `types` SHALL be
skipped.

#### Scenario: An operator's admin settings survive the rename

- **GIVEN** an instance where `stackiq` has `federation_enabled = true` and `federation_directory_url` set
- **WHEN** the renamed app is installed and the repair step runs
- **THEN** both keys MUST be readable under app id `stackiq` with their original values, and the original `stackiq` rows MUST still exist
- @e2e exclude Repair-step behaviour with no UI surface; covered by `tests/Unit/Repair/MigrateAppConfigKeysTest.php`.

#### Scenario: The reserved `enabled` key is never copied

- **GIVEN** an instance where `AppManager::enableApp()` has written `enabled` as a MIXED-typed value
- **WHEN** the repair step runs
- **THEN** it MUST skip `enabled`, `installed_version` and `types`, because copying `enabled` with `setValueString()` stores it as STRING and the next `occ app:enable` then fails permanently with `AppConfigTypeConflictException` — a conflict hit before the app can run anything that would repair it
- @e2e exclude Repair-step behaviour with no UI surface; covered by `tests/Unit/Repair/MigrateAppConfigKeysTest.php`.

#### Scenario: The step runs before any step that writes config

- **GIVEN** `InitializeSettings` also writes app config
- **WHEN** the repair steps are ordered in `appinfo/info.xml`
- **THEN** the migration step MUST be declared FIRST in both `<install>` and `<post-migration>`, because a step that writes first makes the key look "already present" and strands the operator's real value in the old namespace forever
- @e2e exclude Declaration ordering in `appinfo/info.xml`; verified by reading the manifest, not by a browser.

### Requirement: Stored User Preferences Survive The Rename

On both fresh install and upgrade, the app SHALL copy every `oc_preferences`
value stored under the app id `stackiq`, for every seen user, into the
`stackiq` namespace.

The user enumeration SHALL use `IUserManager::callForSeenUsers()` combined with
`IConfig::getUserKeys()`. It SHALL NOT use `getUsersForUserValue()`: that method
matches on a VALUE, so over the open value set this app stores (`pref_*` holds
arbitrary user-chosen view state) it migrates nothing and reports success.

#### Scenario: A user's saved view preference survives the rename

- **GIVEN** a user who has stored `pref_applications-view = table` under app id `stackiq`
- **WHEN** the renamed app is installed and the repair step runs
- **THEN** `getUserValue($uid, 'stackiq', 'pref_applications-view')` MUST return `table`
- @e2e exclude Repair-step behaviour with no UI surface; covered by `tests/Unit/Repair/MigrateUserPreferencesTest.php`.

#### Scenario: A failure in one user does not abort the install

- **GIVEN** the step runs under `<install>`, the only hook that fires on the fresh install an app-id rename performs
- **WHEN** any read or write throws
- **THEN** the exception MUST be caught and logged rather than escaping, because an escaping throw aborts the install and the app never enables at all
- @e2e exclude Repair-step behaviour with no UI surface; covered by `tests/Unit/Repair/MigrateUserPreferencesTest.php`.

### Requirement: Stored Background Job Classes Survive The Rename

The app SHALL deregister the four `oc_jobs` rows whose stored `class` string
carries the old `OCA\Stackiq\BackgroundJob\` prefix, so that Nextcloud's
own `<background-jobs>` registration of the `OCA\Stackiq\BackgroundJob\`
classes is the only surviving registration.

#### Scenario: The orphaned job rows are removed

- **GIVEN** an instance whose `oc_jobs` table holds `OCA\Stackiq\BackgroundJob\ContractStatusJob`
- **WHEN** the repair step runs
- **THEN** that row MUST be removed, because the class no longer exists: the job silently never runs again and nothing reports it
- @e2e exclude Repair-step behaviour with no UI surface; covered by `tests/Unit/Repair/MigrateBackgroundJobClassesTest.php`.

### Requirement: Externally Owned Identifiers Stay Frozen

The rename SHALL NOT change any identifier whose authority lives outside this
app. Specifically it SHALL leave unchanged: the Nextcloud group ids
`software-catalog-users` and `software-catalog-admins`; the dashboard widget id
`stackiq_concept_organisaties_widget`; the appId this app passes to
OpenRegister's configuration importer and the `sourceUrl` /
`lib/Settings/softwarecatalogus_register.json` filename that accompanies it; the
live hosts `softwarecatalog.conduction.nl` and
`www.conduction.nl/apps/stackiq`; the Cloudflare Pages project
`stackiq-docs`; VNG's own `softwarecatalogus.nl` identifiers; and every
other Conduction app's id and namespace.

#### Scenario: Group membership still resolves after the rename

- **GIVEN** users who are members of the Nextcloud group `software-catalog-admins`
- **WHEN** an authorization check runs after the rename
- **THEN** it MUST still test membership of `software-catalog-admins`, because Nextcloud stores membership by group id in `oc_group_user` and a renamed literal makes every check miss — silently dropping everyone's permissions rather than erroring
- @e2e exclude Asserted at the unit level against the frozen literals; no UI path exercises a group rename.

#### Scenario: OpenRegister still recognises its own configuration row

- **GIVEN** OpenRegister holds a Configuration row created with `appId = "stackiq"`
- **WHEN** this app calls `getConfiguredAppVersion()` and `importFromApp()`
- **THEN** it MUST keep passing the literal `stackiq`, because OpenRegister looks the row up by that string in ITS OWN namespace: a new appId makes it see an app it has never configured, import a SECOND configuration, and orphan the existing registers and schemas without an error
- @e2e exclude Cross-app persistence behaviour; asserted at the unit level against the frozen constant.

#### Scenario: Documentation still publishes to a host that resolves

- **GIVEN** `softwarecatalog.conduction.nl` answers HTTP 200 and `stackiq.conduction.nl` does not resolve
- **WHEN** the documentation workflow deploys
- **THEN** the `cname` input MUST remain `softwarecatalog.conduction.nl`, because publishing at a host with no DNS record takes the documentation site offline — a regression, not a rename
- @e2e exclude CI workflow input; verified by probing both hosts, not by a browser test.
