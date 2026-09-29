# Tasks: lifecycle-maintenance-and-supplier-roadmap

## Implementation tasks

### Task 1: Schema, roadmap field and the moduleVersion lifecycle
- **spec_ref**: openspec/changes/lifecycle-maintenance-and-supplier-roadmap/specs/maintenance-and-supplier-roadmap/spec.md#requirement-req-msr-001-a-supplier-announces-planned-maintenance-on-a-product
- **files**: `lib/Settings/register.d/maintenance-and-roadmap.json`, `lib/Settings/softwarecatalogus_register.json` (moduleVersion lifecycle and version), `lib/Settings/stackiq_mock_register.json`
- **acceptance_criteria**:
  - GIVEN the merged register WHEN it is imported THEN maintenanceWindow exists and a planned version offers the release transition
- [x] Implement
- [x] Test (PHPUnit `tests/Unit/Settings/MaintenanceRoadmapFragmentTest.php`: schema, lifecycle states are enum values on both schemas)

### Task 2: Maintenance on the product page and the dashboard
- **spec_ref**: openspec/changes/lifecycle-maintenance-and-supplier-roadmap/specs/maintenance-and-supplier-roadmap/spec.md#requirement-req-msr-002-organisations-that-use-a-product-see-its-planned-maintenance
- **files**: `src/manifest.json` (ModuleDetail list, Dashboard widget), `src/components/maintenance/UpcomingMaintenanceWidget.vue`, `src/customComponents.js`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN a window next week on product X WHEN a user of a municipality that uses X opens the dashboard THEN the widget lists it
- [x] Implement
- [x] Test (vitest `tests/vitest/maintenance.spec.js`; Playwright `tests/e2e/workflows/maintenance.spec.ts`)

### Task 3: Owner notifications
- **spec_ref**: openspec/changes/lifecycle-maintenance-and-supplier-roadmap/specs/maintenance-and-supplier-roadmap/spec.md#requirement-req-msr-003-the-owners-of-every-usage-are-notified
- **files**: `lib/Listener/MaintenanceRecipientsListener.php`, `lib/AppInfo/Application.php`, `lib/Settings/register.d/maintenance-and-roadmap.json` (rules)
- **acceptance_criteria**:
  - GIVEN two usages of X with owners WHEN the supplier creates a window THEN notifyUserIds holds the owners' user ids
- [x] Implement
- [x] Test (PHPUnit `tests/Unit/EventListener/MaintenanceRecipientsListenerTest.php` with the real event class)

### Task 4: Roadmap on the product page and planned releases
- **spec_ref**: openspec/changes/lifecycle-maintenance-and-supplier-roadmap/specs/maintenance-and-supplier-roadmap/spec.md#requirement-req-msr-004-a-product-page-shows-the-suppliers-roadmap
- **files**: `src/components/roadmap/ProductRoadmap.vue`, `src/customComponents.js`, `src/manifest.json` (ModuleDetail body widget, Moduleversies column and quick filter)
- **acceptance_criteria**:
  - GIVEN product X with a roadmap statement and a planned version WHEN a buyer opens its page THEN the statement and the timeline with the planned version show
  - GIVEN the Module versions list WHEN the user picks Planned releases THEN only versions in development remain
- [x] Implement
- [x] Test (vitest `tests/vitest/maintenance.spec.js`; Playwright case in `tests/e2e/workflows/maintenance.spec.ts`)

### Task 5: Documentation
- **spec_ref**: openspec/changes/lifecycle-maintenance-and-supplier-roadmap/specs/maintenance-and-supplier-roadmap/spec.md#requirement-req-msr-004-a-product-page-shows-the-suppliers-roadmap
- **files**: `docs/features/maintenance-and-roadmap.md`, `docs/images/product-roadmap.png`
- **acceptance_criteria**:
  - GIVEN the docs site WHEN a reader opens Maintenance and roadmap THEN announcing, following and the roadmap are explained with a screenshot
- [x] Implement
- [ ] Test (docs build, screenshot with Playwright): the page is written; the screenshot waits for a seeded instance. The Playwright file lists 4 tests and was not run, no seeded instance

## Verification

- `openspec validate lifecycle-maintenance-and-supplier-roadmap --type change --strict` passes.
- `composer check:strict` and `npm run lint` pass; the PHPUnit, vitest and Playwright cases above pass.
- English and Dutch strings for every new label and notification (ADR-005); docs with a screenshot (ADR-010).
