# Tasks: Rename the app id from `softwarecatalog` to `stackiq`

## 1. App identity

- [x] `appinfo/info.xml`: `<id>` -> `stackiq`, `<namespace>` -> `Stackiq`, display names -> "Stackiq", navigation id/name/route.
- [x] `composer.json` psr-4 `OCA\Stackiq\` + package name `conductionnl/stackiq`.
- [x] `package.json` / `package-lock.json` name -> `stackiq` (both `name` fields; `npm ci` fails if they disagree).
- [x] `webpack.config.js` `appId`, bundle names, log prefix.
- [x] `templates/index.php` + `templates/settings/admin.php` mount ids -> `#stackiq` / `#stackiq-settings`.
- [x] PHP root namespace `OCA\SoftwareCatalog` -> `OCA\Stackiq` across `lib/` and `tests/`.
- [x] `git mv` app-named classes/files and update their contents.
- [x] Frontend l10n domain, `/apps/stackiq/` URLs, route names.
- [x] `l10n/*.js` `OC.L10N.register` domain; product-name msgids and their translations.
- [x] CI callers: `app-name` / `app-id` inputs, seed command path, docker-compose CI names.

## 2. Data migration (the point of the PR)

- [x] `lib/Repair/MigrateAppConfigKeys.php` — `IAppConfig::getKeys('softwarecatalog')`, skip `enabled` / `installed_version` / `types`, copy-only-if-absent, non-destructive, whole body in try.
- [x] `lib/Repair/MigrateUserPreferences.php` — `callForSeenUsers()` + `getUserKeys()`; NOT `getUsersForUserValue()`.
- [x] `lib/Repair/MigrateBackgroundJobClasses.php` — deregister the four orphaned `oc_jobs` rows.
- [x] All three registered FIRST in BOTH `<install>` and `<post-migration>`.
- [x] Unit tests for all three, in the same commit as the steps.
- [x] Confirm the classes are TRACKED (`git check-ignore -v lib/Repair/*.php`, `git ls-files lib/Repair/`).

## 3. Freezes

- [x] Group ids `software-catalog-users` / `software-catalog-admins` — left, commented.
- [x] Dashboard widget id `softwarecatalog_concept_organisaties_widget` — left, commented.
- [x] OpenRegister importer appId + `lib/Settings/softwarecatalogus_register.json` — left, commented.
- [x] Live hosts + Cloudflare Pages project — left, commented, both names probed.
- [x] VNG `softwarecatalogus.nl` identifiers, `issues/`, `reacties/` — left.
- [x] `openspec/changes/archive/**` and `openspec/specs/*` directory names — left.

## 4. Verification

- [x] `composer cs:fix` before pushing (`OCA\Stackiq` sorts differently against `OCA\OpenRegister`).
- [x] `composer check:strict`, `npm run lint`, `npm run test`.
- [x] gate-16 spec-coverage and gate-46 spec-anchors run locally, not round-tripped through CI.
- [x] Full-tree sweep: every remaining `softwarecatalog` hit classified as renamed or frozen-with-reason.
