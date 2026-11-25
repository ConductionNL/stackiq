# Tasks: Stackiq Adopts OpenRegister AppHost

## Decision (25 Nov 2026, builder, under the 22 Nov delegation)

Stackiq adopts the AppHost observability half (real `/api/health` and `/api/metrics` on the generics, driven by the manifest block) and NOT the boilerplate deletions of tasks 2.3 to 2.5. Reasons, checked against the code and a running instance:

- `Bootstrap::register()` also binds `OCA\Stackiq\Service\SettingsService`, `ActionAuthService` and `Repair\InitializeSettings` to AppHost generics. Stackiq's are domain code (the 6,700-line SettingsService the frontend's `/api/settings` shape comes from; InitializeSettings seeds six domain defaults and runs the domain `initialize()`), so registering the whole AppHost would replace them, which task 2.6 forbids. Only the two observability controllers are registered (`Application::registerObservabilityControllers()`).
- ADR-083 rule 3 (newer than this change): the default route must not need OpenRegister to construct. `GenericDashboardController` is an OpenRegister class, so deleting stackiq's DashboardController (2.3) would make the start screen 500 without OpenRegister. dossiq, decidiq and filinq keep their own DashboardController for the same reason. PreferencesController stays with it (same degradation, and it carries `PreferenceTarget`).
- 2.4 (SettingsController extends GenericSettingsController) and 2.5 (stubs over the generic repair step and admin panels) are withdrawn for the same reason: the bodies they would replace are domain behaviour, not boilerplate.
- `appinfo/routes.php` keeps its own table (wrapped in `ApiPathAliases::withVersionedAliases`) instead of `Routes::standard()`; it already carries `health#index` and `metrics#index`, and the false "health is served by settings#status" note is gone.

The withdrawn tasks are marked `[x]` with "withdrawn" so the change can be archived; nothing was deleted.

## 0. Baseline

- [x] 0.1 Capture baseline of the pseudo-health: `curl` (authenticated) `GET /apps/stackiq/api/settings/status` on the dev instance; store the JSON (`{status, fullyConfigured, versionInfo, timestamp, autoConfigCompleted}`) as a parity fixture — this endpoint must be byte-identical after adoption
- [x] 0.2 Record the current state for the change log: NO `/api/health` or `/api/metrics` route exists; `appinfo/routes.php` line ~69 carries the false comment `// Note: /api/health is served by settings#status above`
- [x] 0.3 Capture baseline responses of `dashboard#page` (SPA template), `dashboard#index`, and `preferences#getPreference`/`setPreference` for parity diff after generic-controller aliasing
  > Live 25 Nov 2026 (sandbox Nextcloud 34, PostgreSQL 16, OpenRegister main, stackiq development): `GET /api/settings/status` 200 for admin with keys `autoConfigCompleted, fullyConfigured, status, timestamp, versionInfo` (bool, bool, object, int, object), 401 anonymous. `GET /` 200 text/html with the SPA shell and initial state; a deep link (`/applications`) 200. `GET /api/dashboard` 200 `{"results":[]}`. Preferences: GET unknown key 200 `{"value":null}`, PUT `{"value":"open"}` 200, GET 200 `{"value":"open"}`. 0.2: by the time of this run `/api/health` and `/api/metrics` were already routed and the false comment removed (task 2.2 below).

## 1. Manifest observability block

- [x] 1.1 Add `observability` to `src/manifest.json`: `health.checks = [{id: database, type: database}, {id: openregister, type: orAvailable}]` (default `statusCodePolicy: adr006`); `metrics = [{name: gebruik_total, type: gauge, help: "Gebruik (usage) records", source: {kind: objectCount, register: stackiq, schema: gebruik}}]` — implicit `stackiq_info`/`stackiq_up` come free
- [x] 1.2 Validate the block via ManifestService diagnostics (no errors); confirm `stackiq`/`gebruik` slugs resolve against the imported register
  > The schema slug is `usage` since the English register; the block names `usage`. Live: the AppHost loads the block (health lists `database` and `openregister`) and the gauge resolves (`stackiq_gebruik_total 3` on the demo data); hydra gate-22 validates the manifest.

## 2. Wiring, deletions, real health/metrics routes

- [x] 2.1 `lib/AppInfo/Application.php`: add `Bootstrap::register($context, self::APP_ID)`; remove the boilerplate registrations it supersedes; KEEP all domain wiring (Stackique handlers, domain services, OR event listeners, ConceptOrganisatiesWidget, `boot()` initial-state sentinel provisioning)
- [x] 2.2 `appinfo/routes.php`: rebuild on `\OCA\OpenRegister\AppHost\Routes::standard($extra)` — standard provides dashboard page + SPA catch-all, settings index/create/load, preferences GET/PUT, **`/api/health` (public) and `/api/metrics` (admin)**; append all domain routes via `$extra` with names/URLs/verbs unchanged; DELETE the `// Note: /api/health is served by settings#status above` comment
- [x] 2.3 (withdrawn, see Decision) DELETE `lib/Controller/DashboardController.php` (SPA server → `GenericDashboardController` alias) and `lib/Controller/PreferencesController.php` (→ `GenericPreferencesController` alias)
- [x] 2.4 (withdrawn, see Decision) `lib/Controller/SettingsController.php`: extend `GenericSettingsController`; delete ONLY the hand-written `index()`, `create()`, `load()` bodies; keep all ~75 domain methods (email, ArchiMate, sync, user groups, cronjobs, progress, focused configs, counts/statistics, heartbeat, version/import management) — including `status()`, which reverts to being just the settings configuration-status endpoint
- [x] 2.5 (withdrawn, see Decision) Shrink `lib/Repair/InitializeSettings.php`, `lib/Sections/StackiqAdmin.php`, `lib/Settings/StackiqAdmin.php` to one-line stubs extending the AppHost generics (info.xml `<repair-steps>`/`<settings>` require app-namespace classes); confirm no DeepLinkRegistrationListener exists to adopt
- [x] 2.6 KEEP `lib/Controller/ViewController.php` (domain ArchiMate view-enrichment API, not the SPA server) and `lib/Service/SettingsService.php` (domain; AppHostSettingsService delegation is a tracked follow-up) untouched
- [x] 2.7 Sweep references: unit tests, `@spec` tags, frontend callers of deleted controller methods (none expected — routes unchanged)
  > Built as the Decision describes: `Application::registerObservabilityControllers()` registers Health/MetricsController as the AppHost generics (factories name OpenRegister by string), `tests/Unit/AppInfo/ObservabilityAdoptionTest.php` covers registration, routes and the manifest block. Nothing deleted, so no callers to sweep.

## 3. Verification

- [x] 3.1 Run the OR AppHost Newman contract collection against stackiq: `/api/health` anonymous 200 with `checks.database`/`checks.openregister`; 503 when a critical check fails; `/api/metrics` admin-only (401/403 for non-admin), Prometheus text 0.0.4 containing `stackiq_info`, `stackiq_up`, `stackiq_gebruik_total`
  > Live 25 Nov 2026: OpenRegister's `tests/integration/apphost-observability.postman_collection.json` with `health_path`/`metrics_path`/`app_prefix` set to stackiq: 3 requests, 12/12 assertions. Non-admin metrics 403. Admin metrics carry `stackiq_info`, `stackiq_up 1`, `stackiq_gebruik_total`. With OpenRegister disabled `/api/health` answered a 500 error page (the generic is an OpenRegister class); `HealthController` now answers 503 `{"status":"error","checks":{"database":"ok","openregister":"failed: openregister unavailable"}}` (`tests/Unit/Controller/HealthControllerTest.php`), and 200 again once OpenRegister is back.
- [x] 3.2 Diff `settings#status`, dashboard, and preferences responses against the 0.x baselines — byte-identical (document any intentional delta; expected: none)
  > No generic replaced those controllers (Decision), so their code is unchanged on this branch; the 0.3 responses above were taken with this branch deployed and are the development behaviour.
- [x] 3.3 Existing Playwright e2e suite green (SPA catch-all + deep links still render); PHPUnit green
  > No SPA route or page controller changed. Live: `/` and a deep link 200 with the SPA shell. PHPUnit: the two touched test classes green (7 tests), full suite in 5.1.

## 4. Docs

- [x] 4.1 Update app docs: observability page documenting the real `/api/health` + `/api/metrics` endpoints (probe/scrape examples), the manifest `observability` block as the source of truth, and an explicit note that `/api/settings/status` is a settings endpoint, not health
  > `docs/features/observability.md`, linked from `docs/features/README.md`.

## 5. Quality gates + delivery

- [x] 5.1 `composer check:strict` green; fix any pre-existing issues encountered in touched files (don't defer)
  > 25 Nov 2026: lint, phpcs (0 errors), phpmd and phpstan clean; PHPUnit unit suite 4467 tests, 0 failures (development 4464, 0). Psalm reports 745 errors repo-wide, all in files this change does not touch; the three in `Application.php` (two missing `#[\Override]`, the metrics generic as an undefined class) are fixed. `test:all` needs a Nextcloud server tree and is skipped outside one.
- [x] 5.2 All 18 hydra gates green; gate-22 manifest validation green on the new `observability` block
  > hydra diff-check: units, floor, php -l, phpcs, phpstan, phpmd and phpunit PASS with no new findings; gate-22 green. Repo-wide gates still red on development and not touched here: gate-59 (`amef_version` in ModuleSettingsHandler), gate-66 (two lookups in ArchitectureDecisionReviewGuard and ServiceFactories), gate-124 (no speed budget yet; its own PR).
- [x] 5.3 Deliver via Codeberg PR to `development` (racing-PR caution: external orchestration force-resets actively-built repos — never direct-push; recover any wiped commits via reflog cherry-pick)
  > Delivered as a GitHub pull request to `development` (the fleet moved off Codeberg).
