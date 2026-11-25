---
kind: code
---

# Proposal: Stackiq Adopts OpenRegister AppHost

## Problem

Stackiq is one of the three fleet apps (with larpingapp and zaakafhandelapp) that has **no health or metrics endpoint at all** — a direct ADR-006 violation flagged in the 2026-06-12 fleet observability inventory. Worse, it pretends otherwise: `appinfo/routes.php` carries the comment `// Note: /api/health is served by settings#status above`, but:

- There is **no `/api/health` route** in the file. The referenced route is `settings#status` at `/api/settings/status`.
- `SettingsController::status()` requires an authenticated session (`@NoAdminRequired` + an explicit 401 on anonymous calls) — ADR-006 health must be public for probes.
- Its response is configuration status (`{status, fullyConfigured, versionInfo, timestamp, autoConfigCompleted}`), not the fleet health shape (`{status, app, version, checks}`), and it never returns 503 on failure.

So the app's "health" is a pseudo-health: wrong URL, wrong auth posture, wrong shape, wrong semantics. There is no metrics endpoint of any kind.

Beyond observability, Stackiq carries the full boilerplate set the AppHost replaces: `DashboardController` (100 lines, SPA page + catch-all + trivial `index()` stub), `PreferencesController` (154 lines), the generic settings surface inside the 3,667-line `SettingsController`, `Repair/InitializeSettings`, `Sections/StackiqAdmin` + `Settings/StackiqAdmin`, a 592-line `Application.php`, and a 217-line hand-maintained `routes.php`.

## Proposed Change

Adopt both halves of the OpenRegister AppHost (`apphost-observability-engine` + `apphost-boilerplate-controllers`):

### 1. Real observability (pseudo-health → ADR-006 endpoints)

- Add an `observability` block to `src/manifest.json`:
  - **Health checks**: `database` + `orAvailable` (the app is fully OR-backed; if OR is down the app is down). Engine-owned posture: public, `statusCodePolicy: adr006` (503 on critical failure).
  - **Metrics**: implicit `stackiq_info` / `stackiq_up`, plus one example descriptor `gebruik_total` — `objectCount` on register `stackiq`, schema `gebruik` (the app's main usage entity; both slugs verified against `lib/Settings/softwarecatalogus_register.json`). Engine-owned: admin-only, Prometheus text 0.0.4.
- Route `/api/health` → `health#check` and `/api/metrics` → `metrics#index` to the AppHost generic controllers via the standard alias wiring.
- **Delete the misleading routes.php comment.** `settings#status` keeps existing at `/api/settings/status` and reverts to being exactly what it is: an authenticated settings/configuration-status endpoint. Nothing else changes about it; it simply stops being claimed as health.

### 2. Boilerplate adoption (scoped deletions)

- **`lib/Controller/DashboardController.php` (100 lines) — DELETE**, alias `dashboard#page` / `dashboard#index` to `GenericDashboardController`. This *is* the SPA server (template `index` + SPA catch-all at `/{path}`).
- **`lib/Controller/ViewController.php` (506 lines) — KEEP, untouched.** Despite the name it is NOT the SPA server: it is the domain ArchiMate view-enrichment API (`/api/views`, enrichment query params, ViewService). Pure domain.
- **`lib/Controller/PreferencesController.php` (154 lines) — DELETE**, alias to `GenericPreferencesController` (same `/api/preferences/{key}` GET/PUT routes, same user-config keys — no key-namespace change).
- **`lib/Controller/SettingsController.php` (3,667 lines) — SCOPED, NOT deleted.** This controller is almost entirely DOMAIN: ~75 methods covering email + email templates, ArchiMate import/export/status/round-trip, organisation sync, user-group management, cronjob config, progress streaming, AMEF/voorzieningen/general/sync focused config endpoints, object counts/statistics, bulk standards sync, heartbeat, version/import/force-update management. **Only the generic settings surface moves**: `index()`, `create()`, and `load()` (the `GenericSettingsController` contract). The class becomes a subclass of `GenericSettingsController` (extension-first, per the AppHost design), deletes those three hand-written bodies, and keeps every domain method — including `status()`, which stays as the domain configuration-status endpoint.
- **`lib/Service/SettingsService.php` (6,716 lines) — KEEP.** Same verdict: overwhelmingly domain (register auto-detection heuristics, sync, ArchiMate config). Only the register/schema config-resolution + OR-availability surface overlaps `AppHostSettingsService`; refactoring it to delegate is explicitly out of scope here (tracked as follow-up) to keep this change parity-verifiable.
- **`lib/Repair/InitializeSettings.php` → one-line stub** extending `GenericInitializeSettings` (info.xml `<repair-steps>` requires an app-namespace class; repair-step pattern preserved per the install-order constraint).
- **`lib/Sections/StackiqAdmin.php` + `lib/Settings/StackiqAdmin.php` → one-line stubs** extending `GenericSettingsSection` / `GenericAdminSettings` (IDelegatedSettings, #299 pattern).
- **No DeepLinkRegistrationListener exists in this app** — nothing to adopt there.
- **`lib/AppInfo/Application.php` (592 lines)**: replace the boilerplate registrations with `Bootstrap::register($context, self::APP_ID)`; the substantial domain wiring stays (Stackique handlers, domain services, OR event listeners, dashboard widget, the `boot()` manifest-sentinel initial-state provisioning).
- **`appinfo/routes.php` (217 lines)**: rebuild on `Routes::standard($extra)` — standard supplies dashboard page + catch-all, settings index/create/load, preferences, health, metrics; the large domain route set (settings domain endpoints, contactpersonen, views, aanbod, aangeboden-gebruik, gebruik, cronjobs) is appended via `$extra`. Route names/URLs/verbs unchanged for everything that exists today.

## Impact

- **New endpoints**: `GET /apps/stackiq/api/health` (public, ADR-006) and `GET /apps/stackiq/api/metrics` (admin, Prometheus) — closing a fleet-inventory compliance gap.
- **Deleted**: DashboardController + PreferencesController (~254 lines), 3 generic method bodies in SettingsController, boilerplate Application.php registrations, hand-written standard routes; repair/section/admin-settings classes shrink to stubs.
- **Unchanged contracts**: all existing route names, URLs, verbs, response shapes; `settings#status` response is byte-identical; preferences keys keep resolving.
- **Risk**: behavioural drift between old copies and the generic classes — mitigated by endpoint-level parity checks (baseline capture in tasks 0.x, OR Newman contract collection, existing e2e suite) before deletion lands.

## Dependencies

Chained on OpenRegister changes `apphost-observability-engine` and `apphost-boilerplate-controllers` (see `hydra.json`). ADR-006 (observability contract), ADR-022 (apps consume OR abstractions), ADR-040 (declarative observability manifest block).
