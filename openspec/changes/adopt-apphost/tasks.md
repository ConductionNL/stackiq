# Tasks: Stackiq Adopts OpenRegister AppHost

## 0. Baseline

- [ ] 0.1 Capture baseline of the pseudo-health: `curl` (authenticated) `GET /apps/stackiq/api/settings/status` on the dev instance; store the JSON (`{status, fullyConfigured, versionInfo, timestamp, autoConfigCompleted}`) as a parity fixture — this endpoint must be byte-identical after adoption
- [ ] 0.2 Record the current state for the change log: NO `/api/health` or `/api/metrics` route exists; `appinfo/routes.php` line ~69 carries the false comment `// Note: /api/health is served by settings#status above`
- [ ] 0.3 Capture baseline responses of `dashboard#page` (SPA template), `dashboard#index`, and `preferences#getPreference`/`setPreference` for parity diff after generic-controller aliasing

## 1. Manifest observability block

- [ ] 1.1 Add `observability` to `src/manifest.json`: `health.checks = [{id: database, type: database}, {id: openregister, type: orAvailable}]` (default `statusCodePolicy: adr006`); `metrics = [{name: gebruik_total, type: gauge, help: "Gebruik (usage) records", source: {kind: objectCount, register: voorzieningen, schema: gebruik}}]` — implicit `stackiq_info`/`stackiq_up` come free
- [ ] 1.2 Validate the block via ManifestService diagnostics (no errors); confirm `voorzieningen`/`gebruik` slugs resolve against the imported register

## 2. Wiring, deletions, real health/metrics routes

- [ ] 2.1 `lib/AppInfo/Application.php`: add `Bootstrap::register($context, self::APP_ID)`; remove the boilerplate registrations it supersedes; KEEP all domain wiring (Stackique handlers, domain services, OR event listeners, ConceptOrganisatiesWidget, `boot()` initial-state sentinel provisioning)
- [ ] 2.2 `appinfo/routes.php`: rebuild on `\OCA\OpenRegister\AppHost\Routes::standard($extra)` — standard provides dashboard page + SPA catch-all, settings index/create/load, preferences GET/PUT, **`/api/health` (public) and `/api/metrics` (admin)**; append all domain routes via `$extra` with names/URLs/verbs unchanged; DELETE the `// Note: /api/health is served by settings#status above` comment
- [ ] 2.3 DELETE `lib/Controller/DashboardController.php` (SPA server → `GenericDashboardController` alias) and `lib/Controller/PreferencesController.php` (→ `GenericPreferencesController` alias)
- [ ] 2.4 `lib/Controller/SettingsController.php`: extend `GenericSettingsController`; delete ONLY the hand-written `index()`, `create()`, `load()` bodies; keep all ~75 domain methods (email, ArchiMate, sync, user groups, cronjobs, progress, focused configs, counts/statistics, heartbeat, version/import management) — including `status()`, which reverts to being just the settings configuration-status endpoint
- [ ] 2.5 Shrink `lib/Repair/InitializeSettings.php`, `lib/Sections/StackiqAdmin.php`, `lib/Settings/StackiqAdmin.php` to one-line stubs extending the AppHost generics (info.xml `<repair-steps>`/`<settings>` require app-namespace classes); confirm no DeepLinkRegistrationListener exists to adopt
- [ ] 2.6 KEEP `lib/Controller/ViewController.php` (domain ArchiMate view-enrichment API, not the SPA server) and `lib/Service/SettingsService.php` (domain; AppHostSettingsService delegation is a tracked follow-up) untouched
- [ ] 2.7 Sweep references: unit tests, `@spec` tags, frontend callers of deleted controller methods (none expected — routes unchanged)

## 3. Verification

- [ ] 3.1 Run the OR AppHost Newman contract collection against stackiq: `/api/health` anonymous 200 with `checks.database`/`checks.openregister`; 503 when a critical check fails; `/api/metrics` admin-only (401/403 for non-admin), Prometheus text 0.0.4 containing `stackiq_info`, `stackiq_up`, `stackiq_gebruik_total`
- [ ] 3.2 Diff `settings#status`, dashboard, and preferences responses against the 0.x baselines — byte-identical (document any intentional delta; expected: none)
- [ ] 3.3 Existing Playwright e2e suite green (SPA catch-all + deep links still render); PHPUnit green

## 4. Docs

- [ ] 4.1 Update app docs: observability page documenting the real `/api/health` + `/api/metrics` endpoints (probe/scrape examples), the manifest `observability` block as the source of truth, and an explicit note that `/api/settings/status` is a settings endpoint, not health

## 5. Quality gates + delivery

- [ ] 5.1 `composer check:strict` green; fix any pre-existing issues encountered in touched files (don't defer)
- [ ] 5.2 All 18 hydra gates green; gate-22 manifest validation green on the new `observability` block
- [ ] 5.3 Deliver via Codeberg PR to `development` (racing-PR caution: external orchestration force-resets actively-built repos — never direct-push; recover any wiped commits via reflog cherry-pick)
