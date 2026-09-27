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
- [ ] Implement
- [ ] Test (PHPUnit tests/Unit/Service/OrganisationScopeGuardTest.php and tests/Unit/Controller/SettingsControllerOrgExportTest.php)

### Task 2: Add the Export as ArchiMate action to the organisation detail page
- **spec_ref**: openspec/changes/insight-exports-and-custom-reports/specs/catalogue-exports-and-reports/spec.md#requirement-req-cer-003-a-member-of-an-organisation-must-be-able-to-export-that-organisation-as-archimate-from-its-detail-page-and-only-that-organisation
- **files**: `src/components/organisations/OrganisationExportAction.vue`, `src/customComponents.js`, `src/manifest.json`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN a user whose active organisation is the page's organisation WHEN `/organisaties/:id` opens THEN the action shows and downloads an ArchiMate XML file
  - GIVEN a user of another organisation who is not admin or `ambtenaar` WHEN the page opens THEN the action is not shown
- [ ] Implement
- [ ] Test (Playwright tests/e2e/spec-coverage/catalogue-exports.spec.ts)

### Task 3: Add the faceted catalogue export endpoint
- **spec_ref**: openspec/changes/insight-exports-and-custom-reports/specs/catalogue-exports-and-reports/spec.md#requirement-req-cer-002-the-applications-and-services-pages-shall-export-the-rows-the-facets-the-quick-filter-and-the-search-leave
- **files**: `lib/Controller/CatalogExportController.php`, `lib/Service/CatalogExportService.php`, `appinfo/routes.php`, `tests/Unit/Controller/CatalogExportControllerTest.php`, `tests/Unit/Service/CatalogExportServiceTest.php`
- **acceptance_criteria**:
  - GIVEN a schema other than `module` or `catalogService` WHEN the endpoint is called THEN it answers 400
  - GIVEN facet keys, a quick filter and a search WHEN the endpoint runs THEN the rows are the ids `FacetService` matched, narrowed by the quick filter fields, read with RBAC on
  - GIVEN a schema whose `authorization` names `export` and a caller outside it WHEN the endpoint is called THEN it answers 403
  - GIVEN the facet ceiling was reached WHEN the file is written THEN its last row says the set was cut
- [ ] Implement
- [ ] Test (PHPUnit tests/Unit/Controller/CatalogExportControllerTest.php and tests/Unit/Service/CatalogExportServiceTest.php)

### Task 4: Add the Export menu to the Applications and Services pages
- **spec_ref**: openspec/changes/insight-exports-and-custom-reports/specs/catalogue-exports-and-reports/spec.md#requirement-req-cer-002-the-applications-and-services-pages-shall-export-the-rows-the-facets-the-quick-filter-and-the-search-leave
- **files**: `src/views/FacetedCatalogIndexView.vue`, `src/utils/catalogExportUrl.js`, `tests/vitest/catalogExportUrl.spec.js`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN a reference component facet and the BBN2 quick filter on `/modules` WHEN the user chooses Export as CSV THEN the request carries the `_gf_` keys, the quick filter and the search
  - GIVEN the export finishes WHEN the file opens THEN its columns are the page's columns
- [ ] Implement
- [ ] Test (vitest tests/vitest/catalogExportUrl.spec.js and Playwright tests/e2e/spec-coverage/catalogue-exports.spec.ts)

### Task 5: Opt the list pages and schemas into the library's Export menu
- **spec_ref**: openspec/changes/insight-exports-and-custom-reports/specs/catalogue-exports-and-reports/spec.md#requirement-req-cer-001-the-catalogue-list-pages-shall-offer-export-as-csv-and-excel-of-the-rows-the-page-shows
- **files**: `lib/Settings/register.d/insight-exports-and-custom-reports.json`, `src/manifest.json`, `package.json`, `tests/Unit/Settings/CatalogExportFlagsTest.php`
- **acceptance_criteria**:
  - GIVEN the merged register WHEN `catalogContract`, `organization`, `compliancy` and `moduleVersion` are read THEN each has `exportable: true`
  - GIVEN an OpenRegister release that keeps the schema flag WHEN `/komplianties` and `/moduleversies` open THEN the Export menu shows
  - GIVEN a nextcloud-vue release that forwards the page filter and the quick filter WHEN the pin moves to it THEN `/contracten` and `/organisaties` get `allowExport` in the same commit, and not before
- [ ] Implement
- [ ] Test (PHPUnit tests/Unit/Settings/CatalogExportFlagsTest.php and Playwright tests/e2e/spec-coverage/catalogue-exports.spec.ts)

### Task 6: Add the Custom reports page over OpenRegister export profiles
- **spec_ref**: openspec/changes/insight-exports-and-custom-reports/specs/catalogue-exports-and-reports/spec.md#requirement-req-cer-004-a-user-shall-define-run-and-delete-their-own-reports-over-one-catalogue-schema
- **files**: `src/manifest.d/custom-reports.json`, `src/views/reports/CustomReportsView.vue`, `src/modals/reports/CustomReportDialog.vue`, `src/customComponents.js`, `src/manifest.json`, `tests/vitest/customReports.spec.js`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN the Reports page WHEN it opens THEN it shows a Custom reports card that opens `/reports/custom`
  - GIVEN a new report with three fields in order and format CSV WHEN the user runs it THEN the CSV header holds those fields in that order
  - GIVEN a filter row bbnLevel equals BBN2 WHEN the report runs THEN every row is a BBN2 application
  - GIVEN a Nextcloud admin with other users' profiles in OpenRegister WHEN the page opens THEN it lists only the admin's own profiles
- [ ] Implement
- [ ] Test (vitest tests/vitest/customReports.spec.js and Playwright tests/e2e/spec-coverage/custom-reports.spec.ts)

### Task 7: Document the exports and the custom reports
- **spec_ref**: openspec/changes/insight-exports-and-custom-reports/specs/catalogue-exports-and-reports/spec.md#requirement-req-cer-004-a-user-shall-define-run-and-delete-their-own-reports-over-one-catalogue-schema
- **files**: `docs/features/catalogue-exports-and-reports.md`, `openspec/features.overlay.json`
- **acceptance_criteria**:
  - GIVEN the docs WHEN a reader opens the feature page THEN it shows a screenshot of the faceted Export menu, the organisation action and the Custom reports page
  - GIVEN the overlay WHEN `portfolio-reporting` is read THEN its status reflects what shipped
- [ ] Implement
- [ ] Test (docs build and a manual read of the screenshots against the running app)

## Verification

- `openspec validate insight-exports-and-custom-reports --type change --strict`
- PHPUnit: tests/Unit/Service/OrganisationScopeGuardTest.php, tests/Unit/Controller/SettingsControllerOrgExportTest.php, tests/Unit/Controller/CatalogExportControllerTest.php, tests/Unit/Service/CatalogExportServiceTest.php, tests/Unit/Settings/CatalogExportFlagsTest.php, and the existing PortfolioReportController tests
- vitest: tests/vitest/catalogExportUrl.spec.js and tests/vitest/customReports.spec.js
- Playwright: tests/e2e/spec-coverage/catalogue-exports.spec.ts and tests/e2e/spec-coverage/custom-reports.spec.ts
- Docs in docs/features/catalogue-exports-and-reports.md with screenshots (ADR-010)
- English and Dutch strings for the menu entries, the action, the page, the dialog and the cut-off row (ADR-005)
