# adopt-connection-registry tasks

## 1. Declare

- [x] 1.1 Write `lib/Settings/connections.json` with `email`, `federation` and `eol-feed`.
- [x] 1.2 Give the Email, Catalog federation and End-of-life feed sync sections the ids the file links to.
- [x] 1.3 Guard the file in `tests/Unit/Settings/ConnectionsDeclarationTest.php`.

## 2. Page

- [x] 2.1 Add `src/manifest.d/connection-registry.json` with the page and its settings-gear menu entry.
- [x] 2.2 Add `src/services/connectionRegistry.js` with the two formatters and the Add integration handler.
- [x] 2.3 Wire the formatters in `src/App.vue` and the handler in `src/customComponents.js`; register `PowerPlugOutline` in `src/icons.js`.
- [x] 2.4 Add the strings to `l10n/en` and `l10n/nl`.
- [x] 2.5 Cover it in `tests/vitest/connectionRegistry.spec.js`.

## 3. Reports and refresh

- [x] 3.1 Add `lib/Service/ConnectionReportService.php`.
- [x] 3.2 Refresh and report from the two email settings save paths in `SettingsController`.
- [x] 3.3 Refresh and report from `FederationService` peer changes and pulls.
- [x] 3.4 Refresh and report from `EolSyncService` config saves and runs.
- [x] 3.5 Pass the service in the `Application` factories.
- [x] 3.6 Add the integriq event stubs for PHPUnit, psalm and phpstan.
- [x] 3.7 Cover it in `ConnectionReportServiceTest`, `ConnectionReportCallersTest` and `SettingsControllerConnectionReportTest`.

## 4. End to end

- [x] 4.1 Write `tests/e2e/workflows/integrations-page.spec.ts`.
- [x] 4.2 Install integriq in the CI `additional-apps`.

## 5. Switch and built-in formatters (hydra#677)

- [x] 5.1 Declare `switch` on `federation` and `eol-feed`, and stop reporting `unconfigured` for a switched-off feature.
- [x] 5.2 Move `@conduction/nextcloud-vue` to the release with the built-in connection formatters and delete the local copy.

## 6. After integriq ships

- [ ] 6.1 Run the e2e spec against an instance with both apps, then archive this change.
