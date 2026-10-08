# Tasks: operations-technology-components

## Implementation tasks

### Task 1: Add the technologyComponent schema and usage.runsOn
- **spec_ref**: openspec/changes/operations-technology-components/specs/technology-components/spec.md#requirement-req-tco-001-an-organisation-shall-record-technology-components-with-a-class-a-status-and-an-owner
- **files**: `lib/Settings/register.d/operations-technology-components.json`, `tests/Unit/Settings/TechnologyComponentDeclarationTest.php`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN the merged register WHEN technologyComponent is read THEN it has the properties in the design, is in the stackiq register with magicMapping and autoCreateTable, and its lifecycle states equal its status enum
  - GIVEN the merged register WHEN its read rule is read THEN it is organisation-scoped and names no supplier or public group
  - GIVEN the merged register WHEN usage is read THEN it has runsOn and a higher version than before
  - GIVEN a Dutch instance WHEN the form renders THEN the class and status values are Dutch
- [ ] Implement
- [ ] Test (PHPUnit tests/Unit/Settings/TechnologyComponentDeclarationTest.php)

### Task 2: Add the Technology index and detail pages under Applications
- **spec_ref**: openspec/changes/operations-technology-components/specs/technology-components/spec.md#requirement-req-tco-002-the-technology-pages-shall-list-components-and-show-their-relations-both-ways
- **files**: `src/manifest.d/operations-technology-components.json`, `src/menu-layout.json`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN the merged menu WHEN it is built THEN Technology is a child of Applications and the top-level count does not grow
  - GIVEN a host with a virtual machine on it WHEN the host's detail page opens THEN the machine is listed under Runs on this component
  - GIVEN a component WHEN its detail page opens THEN the applications in use that run on it are listed and open GebruikDetail
  - GIVEN a component with status In use WHEN Phase out is chosen THEN its status is To be phased out
- [ ] Implement
- [ ] Test (Playwright tests/e2e/spec-coverage/technology-components.spec.ts)

### Task 3: Show the Runs on panel with the support state on the usage page
- **spec_ref**: openspec/changes/operations-technology-components/specs/technology-components/spec.md#requirement-req-tco-003-an-application-in-use-shall-record-the-components-it-runs-on-and-show-their-support-state
- **files**: `src/utils/technologyLifecycle.js`, `src/components/usages/UsageRunsOnPanel.vue`, `src/customComponents.js`, `src/manifest.d/usages.json`, `tests/vitest/technologyLifecycle.spec.js`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN an end of support in the past, within 180 days, later, or empty WHEN supportState() runs THEN it returns ended, ending, supported or unknown
  - GIVEN a virtual machine without a date on an ended host WHEN the worst state is computed THEN it is ended
  - GIVEN the demo usage WHEN /gebruik/:id opens THEN Database 01 is listed as ended
- [ ] Implement
- [ ] Test (vitest tests/vitest/technologyLifecycle.spec.js and Playwright tests/e2e/spec-coverage/technology-components.spec.ts)

### Task 4: Stamp end of support on components from the EOL sync
- **spec_ref**: openspec/changes/operations-technology-components/specs/technology-components/spec.md#requirement-req-tco-004-the-end-of-life-sync-shall-stamp-a-components-end-of-support-from-the-software-version-it-is
- **files**: `lib/Service/EolSyncService.php`, `tests/Unit/Service/EolSyncServiceTest.php`
- **acceptance_criteria**:
  - GIVEN a stamped version with two components pointing at it WHEN the sync saves the version THEN both components carry the date, eolSource and eolUpdatedOn
  - GIVEN a component without software WHEN the sync runs THEN its typed endOfSupport is untouched
  - GIVEN many components WHEN they are looked up THEN the lookup has an explicit limit
- [ ] Implement
- [ ] Test (PHPUnit tests/Unit/Service/EolSyncServiceTest.php)

### Task 5: Seed components and document the feature
- **spec_ref**: openspec/changes/operations-technology-components/specs/technology-components/spec.md#requirement-req-tco-003-an-application-in-use-shall-record-the-components-it-runs-on-and-show-their-support-state
- **files**: `lib/Settings/stackiq_mock_register.json`, `docs/features/technology-components.md`
- **acceptance_criteria**:
  - GIVEN a fresh demo import WHEN /technologie opens THEN it lists Host 01, App server 01 and Database 01
  - GIVEN the docs WHEN a reader opens the feature page THEN it shows the index, a detail page and the Runs on panel in screenshots, and says stackiq records and does not discover
- [ ] Implement
- [ ] Test (Playwright tests/e2e/spec-coverage/technology-components.spec.ts against the demo data)

## Verification

- `openspec validate operations-technology-components --type change --strict`
- PHPUnit: tests/Unit/Settings/TechnologyComponentDeclarationTest.php and tests/Unit/Service/EolSyncServiceTest.php
- vitest: tests/vitest/technologyLifecycle.spec.js
- Playwright: tests/e2e/spec-coverage/technology-components.spec.ts
- Docs in docs/features/technology-components.md with screenshots (ADR-010)
- English and Dutch strings for the classes, the states, the pages and the panel (ADR-005)
