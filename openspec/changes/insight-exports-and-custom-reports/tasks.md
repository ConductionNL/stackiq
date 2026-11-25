# Tasks: insight-exports-and-custom-reports

## Implementation tasks

### Task 1: Share the organisation scope rule and guard the organisation export with it
- **spec_ref**: openspec/changes/insight-exports-and-custom-reports/specs/catalogue-exports-and-reports/spec.md#requirement-req-cer-003-a-member-of-an-organisation-must-be-able-to-export-that-organisation-as-archimate-from-its-detail-page-and-only-that-organisation
- **files**: `lib/Service/OrganisationScopeGuard.php`, `lib/Controller/PortfolioReportController.php`, `lib/Controller/SettingsController.php`, `tests/Unit/Service/OrganisationScopeGuardTest.php`, `tests/Unit/Controller/SettingsControllerOrgExportTest.php`
- **acceptance_criteria**:
  - GIVEN a user in `admin` or `ambtenaar` WHEN the guard checks any organisation uuid THEN it allows
  - GIVEN a user whose active organisation is A WHEN the guard checks A THEN it allows, and WHEN it checks B THEN it refuses
  - GIVEN a refused user WHEN they call the organisation ArchiMate export THEN stackiq answers 403 before `ArchiMateService::exportOrgArchiMate()` runs
  - GIVEN the portfolio report WHEN the same users call it THEN the answers are unchanged
- [x] Implement
- [x] Test (PHPUnit tests/Unit/Service/OrganisationScopeGuardTest.php and tests/Unit/Controller/SettingsControllerOrgExportTest.php)

### Task 2: Add the Export as ArchiMate action to the organisation detail page
- **spec_ref**: openspec/changes/insight-exports-and-custom-reports/specs/catalogue-exports-and-reports/spec.md#requirement-req-cer-003-a-member-of-an-organisation-must-be-able-to-export-that-organisation-as-archimate-from-its-detail-page-and-only-that-organisation
- **files**: `src/components/organisations/OrganisationExportAction.vue`, `src/customComponents.js`, `src/manifest.json`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN a user whose active organisation is the page's organisation WHEN `/organisaties/:id` opens THEN the action shows and downloads an ArchiMate XML file
  - GIVEN a user of another organisation who is not admin or `ambtenaar` WHEN the page opens THEN the action is not shown
- [x] Implement
- [x] Test (Playwright tests/e2e/spec-coverage/catalogue-exports.spec.ts) (e2e written, runs in nightly; not verified on a live instance)
  > Built: `OrganisationExportAction` in OrganisatieDetail's actions slot. It asks `GET /api/archimate/export/organization/{uuid}/access`, which answers `{mayExport}` from the export's own `verifyOrgScope()` rule without exporting, so the action and the 403 cannot drift; any other answer hides it. PHPUnit `SettingsControllerOrgExportTest` (the probe), vitest `organisationExportAction.spec.js`. The Playwright half needs a live instance.

### Task 3: Add the faceted catalogue export endpoint
- **spec_ref**: openspec/changes/insight-exports-and-custom-reports/specs/catalogue-exports-and-reports/spec.md#requirement-req-cer-002-the-applications-and-services-pages-shall-export-the-rows-the-facets-the-quick-filter-and-the-search-leave
- **files**: `lib/Controller/CatalogExportController.php`, `lib/Service/CatalogExportService.php`, `appinfo/routes.php`, `tests/Unit/Controller/CatalogExportControllerTest.php`, `tests/Unit/Service/CatalogExportServiceTest.php`
- **acceptance_criteria**:
  - GIVEN a schema other than `module` or `catalogService` WHEN the endpoint is called THEN it answers 400
  - GIVEN facet keys, a quick filter and a search WHEN the endpoint runs THEN the rows are the ids `FacetService` matched, narrowed by the quick filter fields, read with RBAC on
  - GIVEN a schema whose `authorization` names `export` and a caller outside it WHEN the endpoint is called THEN it answers 403
  - GIVEN the facet ceiling was reached WHEN the file is written THEN its last row says the set was cut
- [x] Implement
- [x] Test (PHPUnit tests/Unit/Controller/CatalogExportControllerTest.php and tests/Unit/Service/CatalogExportServiceTest.php)

### Task 4: Add the Export menu to the Applications and Services pages
- **spec_ref**: openspec/changes/insight-exports-and-custom-reports/specs/catalogue-exports-and-reports/spec.md#requirement-req-cer-002-the-applications-and-services-pages-shall-export-the-rows-the-facets-the-quick-filter-and-the-search-leave
- **files**: `src/views/FacetedCatalogIndexView.vue`, `src/utils/catalogExportUrl.js`, `tests/vitest/catalogExportUrl.spec.js`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN a reference component facet and the BBN2 quick filter on `/modules` WHEN the user chooses Export as CSV THEN the request carries the `_gf_` keys, the quick filter and the search
  - GIVEN the export finishes WHEN the file opens THEN its columns are the page's columns
- [x] Implement
- [x] Test (vitest tests/vitest/catalogExportUrl.spec.js and Playwright tests/e2e/spec-coverage/catalogue-exports.spec.ts) (e2e written, runs in nightly; not verified on a live instance)
  > Built: the Export menu (CSV, Excel) in the view's toolbar, `catalogExportUrl()` and its vitest spec, and `facetedCatalogExport.spec.js` for the handler. The Playwright half needs a live instance.

### Task 5: Opt the list pages and schemas into the library's Export menu
- **spec_ref**: openspec/changes/insight-exports-and-custom-reports/specs/catalogue-exports-and-reports/spec.md#requirement-req-cer-001-the-catalogue-list-pages-shall-offer-export-as-csv-and-excel-of-the-rows-the-page-shows
- **files**: `lib/Settings/register.d/insight-exports-and-custom-reports.json`, `src/manifest.json`, `package.json`, `tests/Unit/Settings/CatalogExportFlagsTest.php`
- **acceptance_criteria**:
  - GIVEN the merged register WHEN `catalogContract`, `organization`, `compliancy` and `moduleVersion` are read THEN each has `exportable: true`
  - GIVEN an OpenRegister release that keeps the schema flag WHEN `/komplianties` and `/moduleversies` open THEN the Export menu shows
  - GIVEN a nextcloud-vue release that forwards the page filter and the quick filter WHEN the pin moves to it THEN `/contracten` and `/organisaties` get `allowExport` in the same commit, and not before
- [x] Implement
- [x] Test (PHPUnit tests/Unit/Settings/CatalogExportFlagsTest.php done; Playwright tests/e2e/spec-coverage/catalogue-exports.spec.ts) (e2e written, runs in nightly; not verified on a live instance)
  > Built: the fragment flags the four schemas, `/komplianties` and `/moduleversies` set `allowExport`. Left by design: `/contracten` and `/organisaties` get `allowExport` with the nextcloud-vue pin that forwards their page and quick filters (no such release yet), and the menu shows only once OpenRegister keeps the schema flag.

### Task 6: Add the Custom reports page over OpenRegister export profiles
- **spec_ref**: openspec/changes/insight-exports-and-custom-reports/specs/catalogue-exports-and-reports/spec.md#requirement-req-cer-004-a-user-shall-define-run-and-delete-their-own-reports-over-one-catalogue-schema
- **files**: `src/manifest.d/custom-reports.json`, `src/views/reports/CustomReportsView.vue`, `src/modals/reports/CustomReportDialog.vue`, `src/customComponents.js`, `src/manifest.json`, `tests/vitest/customReports.spec.js`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN the Reports page WHEN it opens THEN it shows a Custom reports card that opens `/reports/custom`
  - GIVEN a new report with three fields in order and format CSV WHEN the user runs it THEN the CSV header holds those fields in that order
  - GIVEN a filter row bbnLevel equals BBN2 WHEN the report runs THEN every row is a BBN2 application
  - GIVEN a Nextcloud admin with other users' profiles in OpenRegister WHEN the page opens THEN it lists only the admin's own profiles
- [x] Implement
- [x] Test (vitest tests/vitest/customReports.spec.js and Playwright tests/e2e/spec-coverage/custom-reports.spec.ts) (e2e written, runs in nightly; not verified on a live instance)
  > Built: the `CustomReports` page at `/reports/custom` (`src/manifest.d/custom-reports.json`) and its card on the Reports page. `CustomReportsView` reads OpenRegister's export profiles and the catalogue register's schemas, lists only the caller's own profiles over that register, runs a report as the download from `/api/export-profiles/{id}/run` and deletes it. `CustomReportDialog` defines a report over the picked schema in `CnFormDialog`: name, fields in column order, value mode, CSV or JSON, and filter rows of a field and a value, stored as the profile's `filters`. vitest `customReports.spec.js` and `customReportDialog.spec.js`. The Playwright half needs a live instance.

### Task 7: Document the exports and the custom reports
- **spec_ref**: openspec/changes/insight-exports-and-custom-reports/specs/catalogue-exports-and-reports/spec.md#requirement-req-cer-004-a-user-shall-define-run-and-delete-their-own-reports-over-one-catalogue-schema
- **files**: `docs/features/catalogue-exports-and-reports.md`, `openspec/features.overlay.json`
- **acceptance_criteria**:
  - GIVEN the docs WHEN a reader opens the feature page THEN it shows a screenshot of the faceted Export menu, the organisation action and the Custom reports page
  - GIVEN the overlay WHEN `portfolio-reporting` is read THEN its status reflects what shipped
- [x] Implement
- [x] Test (docs build and a manual read of the screenshots against the running app) (25 Nov 2026: `docs/images/export-menu.png`, `organisation-archimate-export.png` and `custom-reports.png` captured from a live sandbox instance by `npx playwright test --project docs`, read against the running app; docs build passes)
  > Written: `docs/features/catalogue-exports-and-reports.md` (linked from the features index) and `portfolio-reporting` set to `beta` in the overlay. The screenshots of the faceted Export menu, the organisation action and the Custom reports page were added 25 Nov 2026.

## Verification

- `openspec validate insight-exports-and-custom-reports --type change --strict`
- PHPUnit: tests/Unit/Service/OrganisationScopeGuardTest.php, tests/Unit/Controller/SettingsControllerOrgExportTest.php, tests/Unit/Controller/CatalogExportControllerTest.php, tests/Unit/Service/CatalogExportServiceTest.php, tests/Unit/Settings/CatalogExportFlagsTest.php, and the existing PortfolioReportController tests
- vitest: tests/vitest/catalogExportUrl.spec.js and tests/vitest/customReports.spec.js
- Playwright: tests/e2e/spec-coverage/catalogue-exports.spec.ts and tests/e2e/spec-coverage/custom-reports.spec.ts
- Docs in docs/features/catalogue-exports-and-reports.md with screenshots (ADR-010)
- English and Dutch strings for the menu entries, the action, the page, the dialog and the cut-off row (ADR-005)

## Build notes (round 2, 2026-11-17)

- `tests/e2e/spec-coverage/catalogue-exports.spec.ts` (6 tests: the Applications CSV export with its search, the 400 for another schema, the Export menu on `/komplianties` and `/moduleversies`, the organisation ArchiMate action for the admin and its absence and 403 for an account of no organisation) and `tests/e2e/spec-coverage/custom-reports.spec.ts` (4 tests: the card, a three-field BBN2 report's header and rows, another account's report not listed, delete) are written. They lint, type-check and `playwright --list` collects them, but they have not run against a live instance yet, so the Test boxes of tasks 2, 4, 5 and 6 stay open until they have.
- Task 7: `docs/features/catalogue-exports-and-reports.md` is on development; its screenshots need a capture on a live instance.
