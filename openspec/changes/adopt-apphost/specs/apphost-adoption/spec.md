---
status: proposed
---

# SoftwareCatalog AppHost Adoption

## Purpose

SoftwareCatalog serves real ADR-006 observability endpoints (replacing the `settings#status` pseudo-health) and runs its app boilerplate (dashboard SPA serving, preferences, generic settings surface, install/admin plumbing) on the OpenRegister AppHost generics, with endpoint-level parity for everything that exists today.

**Cross-references**: `openregister/openspec/changes/apphost-observability-engine/specs/apphost-observability/spec.md`, `openregister/openspec/changes/apphost-boilerplate-controllers/`

---

## Requirements

### Requirement: ADR-006 Health Endpoint

SoftwareCatalog SHALL serve `GET /apps/softwarecatalog/api/health` through the AppHost `GenericHealthController` — publicly accessible, executing the manifest-declared `database` and `orAvailable` checks, returning the fleet health shape and HTTP 503 when a critical check fails (`statusCodePolicy: adr006`).

#### Scenario: Anonymous health probe on a healthy instance

- **GIVEN** a healthy instance with OpenRegister enabled
- **WHEN** `GET /apps/softwarecatalog/api/health` is called without authentication
- **THEN** the response MUST be HTTP 200 with `status = "ok"`, `app = "softwarecatalog"`, and `checks.database = "ok"` and `checks.openregister = "ok"` in the standard `{status, app, version, checks}` shape
- @e2e exclude API-only endpoint — covered by the OR AppHost Newman contract collection

#### Scenario: Critical check failure returns 503

- **GIVEN** OpenRegister is disabled or its ObjectService cannot be resolved
- **WHEN** `GET /apps/softwarecatalog/api/health` is called
- **THEN** the response MUST be HTTP 503 with `status = "error"` and `checks.openregister` reporting `failed: <generic message>` without leaking exception details
- @e2e exclude API-only endpoint — covered by the OR AppHost Newman contract collection

### Requirement: ADR-006 Metrics Endpoint

SoftwareCatalog SHALL serve `GET /apps/softwarecatalog/api/metrics` through the AppHost `GenericMetricsController` — admin-only, Prometheus text format 0.0.4 — emitting the implicit `softwarecatalog_info` and `softwarecatalog_up` metrics plus the declared `softwarecatalog_gebruik_total` gauge (`objectCount` on register `voorzieningen`, schema `gebruik`).

#### Scenario: Admin scrapes metrics

- **GIVEN** a seeded instance with gebruik objects in the voorzieningen register
- **WHEN** `GET /apps/softwarecatalog/api/metrics` is called by an admin
- **THEN** the response MUST be Prometheus text 0.0.4 with `# HELP`/`# TYPE` lines containing `softwarecatalog_info` (version, php_version, nextcloud_version labels), `softwarecatalog_up 1`, and `softwarecatalog_gebruik_total` matching the gebruik object count
- @e2e exclude API-only endpoint — covered by the OR AppHost Newman contract collection

#### Scenario: Non-admin is rejected

- **GIVEN** an authenticated non-admin user
- **WHEN** `GET /apps/softwarecatalog/api/metrics` is called
- **THEN** the request MUST be rejected (401/403) by the engine-owned admin posture
- @e2e exclude API-only endpoint — covered by the OR AppHost Newman contract collection

### Requirement: Pseudo-Health Retirement

SoftwareCatalog SHALL remove the claim that `settings#status` serves health: the `// Note: /api/health is served by settings#status above` comment in `appinfo/routes.php` SHALL be deleted, while `GET /api/settings/status` SHALL keep serving its current authenticated configuration-status response unchanged (`{status, fullyConfigured, versionInfo, timestamp, autoConfigCompleted}`).

#### Scenario: Settings status is unchanged and is not health

- **GIVEN** an authenticated user on a configured instance
- **WHEN** `GET /apps/softwarecatalog/api/settings/status` is called
- **THEN** the response MUST be byte-identical to the pre-adoption baseline, and anonymous calls MUST still receive 401 — the endpoint is a settings endpoint, distinct from the public `/api/health`
- @e2e exclude API-only endpoint — covered by the OR AppHost Newman contract collection

### Requirement: Boilerplate Runs on AppHost Generics

SoftwareCatalog SHALL serve its SPA (dashboard page + catch-all), per-user preferences, and the generic settings surface (`settings#index`, `settings#create`, `settings#load`) through the AppHost generic controllers via alias wiring (`Bootstrap::register`) and `Routes::standard($extra)`, deleting `DashboardController` and `PreferencesController` and the three generic `SettingsController` method bodies. All domain surfaces SHALL remain app-owned: `ViewController` (ArchiMate view-enrichment API), the ~75 domain methods of `SettingsController`, `SettingsService`, and all domain routes appended via `$extra` with unchanged names, URLs, and verbs.

#### Scenario: SPA still renders after dashboard aliasing

- **GIVEN** the app is enabled and a user is logged in
- **WHEN** the user opens `/apps/softwarecatalog/` or deep-links to any frontend route such as `/apps/softwarecatalog/voorzieningen`
- **THEN** the Vue SPA MUST render via the generic dashboard controller serving `templates/index.php` with the preserved chunk-loading order, and in-app navigation MUST work as before

#### Scenario: Preferences parity through the generic controller

- **GIVEN** a user with a preference previously written by the hand-written controller
- **WHEN** `GET /apps/softwarecatalog/api/preferences/{key}` and `PUT` with a new value are called
- **THEN** the stored value MUST resolve under the same key namespace and the response shapes MUST match the pre-adoption baseline
- @e2e exclude API-only endpoint — covered by the OR AppHost Newman contract collection

#### Scenario: Domain settings surface untouched

- **GIVEN** the adoption is deployed
- **WHEN** any domain settings route is exercised (e.g. `GET /api/settings/email`, `POST /api/archimate/import`, `GET /api/settings/cronjobs`, `GET /api/views`)
- **THEN** it MUST be served by the app's own controller methods with pre-adoption behaviour, names, URLs, and verbs unchanged
- @e2e exclude API-only endpoint — covered by the OR AppHost Newman contract collection

### Requirement: Install And Admin Plumbing Via Stubs

SoftwareCatalog SHALL keep `Repair/InitializeSettings`, `Sections/SoftwareCatalogAdmin`, and `Settings/SoftwareCatalogAdmin` only as one-line app-namespace stubs extending the AppHost generics (required by info.xml `<repair-steps>`/`<settings>` registration), preserving the repair-step install pattern and the IDelegatedSettings (#299) admin-settings pattern.

#### Scenario: Register import still runs on install/upgrade

- **GIVEN** a fresh install or upgrade of the app
- **WHEN** the repair steps run
- **THEN** the stub `InitializeSettings` (extending `GenericInitializeSettings`) MUST import `lib/Settings/softwarecatalogus_register.json` through the established repair-step path, and the admin settings section MUST appear in Nextcloud admin settings as before
- @e2e exclude install-time repair-step plumbing — verified via occ install flow and PHPUnit, no UI surface
