---
status: proposed
---

# apphost-adoption Specification

## Purpose

Stackiq serves real ADR-006 observability endpoints on the OpenRegister AppHost generics, driven by the manifest's `observability` block, and retires the `settings#status` pseudo-health. Its dashboard, preferences, settings, repair step and admin panels stay app-owned: they carry domain behaviour, and the start screen must not need OpenRegister to construct (ADR-083 rule 3).

**Cross-references**: `openregister/openspec/changes/apphost-observability-engine/specs/apphost-observability/spec.md`, ADR-006, ADR-040, ADR-083.

## Requirements

### Requirement: REQ-AHA-001 Stackiq SHALL serve an ADR-006 health endpoint

Stackiq SHALL serve `GET /apps/stackiq/api/health` publicly through the AppHost `GenericHealthController`, executing the manifest-declared `database` and `orAvailable` checks, returning `{status, app, version, checks}` and HTTP 503 when a critical check fails (`statusCodePolicy: adr006`). When OpenRegister is disabled or missing, so that the generic cannot load, the app's own `HealthController` SHALL give the same answer for the failed critical check: HTTP 503, `status = "error"`, `checks.openregister = "failed: openregister unavailable"`, and the database check, without leaking exception details.

#### Scenario: Anonymous health probe on a healthy instance

- **GIVEN** a healthy instance with OpenRegister enabled
- **WHEN** `GET /apps/stackiq/api/health` is called without authentication
- **THEN** the response MUST be HTTP 200 with `status = "ok"`, `app = "stackiq"`, `checks.database = "ok"` and `checks.openregister = "ok"`
- @e2e exclude API-only endpoint — covered by the OR AppHost Newman contract collection

#### Scenario: Critical check failure returns 503

- **GIVEN** OpenRegister is disabled or its object service cannot be resolved
- **WHEN** `GET /apps/stackiq/api/health` is called
- **THEN** the response MUST be HTTP 503 with `status = "error"` and `checks.openregister` reporting `failed: <generic message>` without leaking exception details
- @e2e exclude API-only endpoint — covered by `tests/Unit/Controller/HealthControllerTest.php` and a live check

### Requirement: REQ-AHA-002 Stackiq SHALL serve an ADR-006 metrics endpoint

Stackiq SHALL serve `GET /apps/stackiq/api/metrics` through the AppHost `GenericMetricsController`, admin-only, Prometheus text format 0.0.4, emitting the implicit `stackiq_info` and `stackiq_up` metrics plus the declared `stackiq_gebruik_total` gauge (`objectCount` on register `stackiq`, schema `usage`).

#### Scenario: Admin scrapes metrics

- **GIVEN** a seeded instance with usage objects in the stackiq register
- **WHEN** `GET /apps/stackiq/api/metrics` is called by an admin
- **THEN** the response MUST be Prometheus text 0.0.4 with `# HELP`/`# TYPE` lines containing `stackiq_info` (version, php_version, nextcloud_version labels), `stackiq_up 1`, and `stackiq_gebruik_total` matching the usage object count
- @e2e exclude API-only endpoint — covered by the OR AppHost Newman contract collection

#### Scenario: Non-admin is rejected

- **GIVEN** an authenticated non-admin user
- **WHEN** `GET /apps/stackiq/api/metrics` is called
- **THEN** the request MUST be rejected (401/403) by the engine-owned admin posture
- @e2e exclude API-only endpoint — covered by the OR AppHost Newman contract collection

### Requirement: REQ-AHA-003 The settings status endpoint SHALL NOT be presented as health

`appinfo/routes.php` SHALL NOT claim that `settings#status` serves health. `GET /api/settings/status` SHALL keep serving its authenticated configuration-status response unchanged (`{status, fullyConfigured, versionInfo, timestamp, autoConfigCompleted}`).

#### Scenario: Settings status is unchanged and is not health

- **GIVEN** an authenticated user on a configured instance
- **WHEN** `GET /apps/stackiq/api/settings/status` is called
- **THEN** the response MUST keep its configuration-status shape, and anonymous calls MUST still receive 401 — the endpoint is a settings endpoint, distinct from the public `/api/health`
- @e2e exclude API-only endpoint — covered by the OR AppHost Newman contract collection

### Requirement: REQ-AHA-004 The dashboard, preferences and settings controllers SHALL stay app-owned

Stackiq SHALL register only the two observability controllers from the AppHost, not the whole AppHost through `Bootstrap::register()`, because that call also replaces the app's SettingsService, ActionAuthService and InitializeSettings with generics. `DashboardController`, `PreferencesController` and `SettingsController` SHALL stay app-owned, with every route name, URL and verb unchanged, so that the start screen constructs without OpenRegister (ADR-083 rule 3) and the `/api/settings` shape the frontend reads is unchanged.

#### Scenario: SPA still renders

- **GIVEN** the app is enabled and a user is logged in
- **WHEN** the user opens `/apps/stackiq/` or deep-links to a frontend route such as `/apps/stackiq/applications`
- **THEN** the page MUST answer 200 with the SPA shell and its initial state

#### Scenario: Preferences keep their shape

- **GIVEN** a logged-in user
- **WHEN** `GET /apps/stackiq/api/preferences/{key}` and `PUT` with a new value are called
- **THEN** an unknown key MUST answer `{"value": null}`, and after the PUT the GET MUST answer the stored value
- @e2e exclude API-only endpoint — covered by a live check

### Requirement: REQ-AHA-005 Install and admin plumbing SHALL stay domain code

`Repair/InitializeSettings`, `Sections/StackiqAdmin` and `Settings/StackiqAdmin` SHALL stay app-owned classes. `InitializeSettings` seeds the app's domain defaults and runs the domain settings initialisation (which imports the register); a generic stub would drop both.

#### Scenario: Register import still runs on install/upgrade

- **GIVEN** a fresh install or upgrade of the app
- **WHEN** the repair steps run
- **THEN** `InitializeSettings` MUST seed its defaults and import the stackiq register, and the admin settings section MUST appear in Nextcloud admin settings as before
- @e2e exclude install-time repair-step plumbing — verified via the occ install flow and PHPUnit, no UI surface
