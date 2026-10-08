# Tasks: architecture-shared-usage-on-views

Starts after `architecture-views-editor` has landed the read-only GEMMA view page.

## Implementation tasks

### Task 1: Fetch enriched views from the store
- **spec_ref**: openspec/changes/architecture-shared-usage-on-views/specs/shared-usage-on-views/spec.md#requirement-req-suv-001-the-gemma-view-page-must-let-a-user-show-own-and-shared-applications-on-the-view
- **files**: `src/store/modules/view.js`, `tests/vitest/viewStoreEnrichment.spec.js`
- **acceptance_criteria**:
  - GIVEN `includeGebruik: true` WHEN the store fetches one view THEN the request carries `include_gebruik=true`
  - GIVEN `includeDeelnamesGebruik: true` WHEN the store fetches one view THEN the request carries `include_deelnames_gebruik=true`
  - GIVEN neither flag WHEN the store fetches THEN neither parameter is sent
- [ ] Implement
- [ ] Test (vitest tests/vitest/viewStoreEnrichment.spec.js)

### Task 2: Map usage to overlay nodes
- **spec_ref**: openspec/changes/architecture-shared-usage-on-views/specs/shared-usage-on-views/spec.md#requirement-req-suv-002-a-shared-application-must-be-drawn-apart-from-an-own-application-without-relying-on-colour
- **files**: `src/utils/usageOverlay.js`, `tests/vitest/usageOverlay.spec.js`
- **acceptance_criteria**:
  - GIVEN an enriched node with one `usage` and one `deelnamesGebruik` entry WHEN mapped THEN two Vue Flow nodes come back with `parentNode` set to the component's node id and `extent: 'parent'`
  - GIVEN a `deelnamesGebruik` entry WHEN mapped THEN its node has class `usage-node--shared`, `data.badge` "Shared" and `data.ariaLabel` "<name>, shared by <_sourceOrganization>"
  - GIVEN the same application in both lists for one component WHEN mapped THEN one own node comes back
  - GIVEN more usages than fit the component's height WHEN mapped THEN the last node is "+N more"
  - GIVEN `CnGraphCanvas` from `@conduction/nextcloud-vue` WHEN a node with a `class` renders THEN the class reaches the Vue Flow node wrapper (check first; if not, file it on nextcloud-vue and keep the badge)
- [ ] Implement
- [ ] Test (vitest tests/vitest/usageOverlay.spec.js)

### Task 3: Add the control, the overlay and the legend to the read-only view page
- **spec_ref**: openspec/changes/architecture-shared-usage-on-views/specs/shared-usage-on-views/spec.md#requirement-req-suv-001-the-gemma-view-page-must-let-a-user-show-own-and-shared-applications-on-the-view
- **files**: `src/views/architecture/ArchitectureViewEditor.vue`, `src/components/architecture/UsageOverlayLegend.vue`, `l10n/en.json`, `l10n/nl.json`, `tests/e2e/spec-coverage/shared-usage-on-views.spec.ts`
- **acceptance_criteria**:
  - GIVEN an imported view in read-only mode WHEN the page opens THEN "Show applications" is visible with "Our applications" and "Shared with partners" off
  - GIVEN a drawn view WHEN the page opens THEN the control is not shown
  - GIVEN "Shared with partners" switched on WHEN the view reloads THEN shared nodes appear inside their components with a dashed border and the text "Shared"
  - GIVEN any switch on WHEN the page renders THEN the legend lists "Our application" and "Shared with a partner"
  - GIVEN a Dutch instance WHEN the page renders THEN every new label is Dutch
  - GIVEN a click on an overlay node WHEN it is handled THEN the usage detail page (`GebruikDetail`) opens
- [ ] Implement
- [ ] Test (Playwright tests/e2e/spec-coverage/shared-usage-on-views.spec.ts)

### Task 4: Style shared applications apart in the organisation export
- **spec_ref**: openspec/changes/architecture-shared-usage-on-views/specs/shared-usage-on-views/spec.md#requirement-req-suv-003-the-organisation-archimate-export-must-draw-shared-applications-apart-from-own-ones
- **files**: `lib/Service/ArchiMateExportService.php`, `tests/Unit/Service/ArchiMateExportSharedUsageStyleTest.php`
- **acceptance_criteria**:
  - GIVEN own and shared application elements WHEN `copyAndEnrichViews()` builds its lookup THEN every entry has `kind` own or shared and shared entries name the partner
  - GIVEN the same application owned and shared on one component WHEN nodes are injected THEN one node is nested, with the own style
  - GIVEN a shared entry WHEN `processNodesForInjection()` nests it THEN fillColor is 210,225,255 and lineColor 40,80,170
  - GIVEN a shared element WHEN it is generated THEN it carries the property `Gedeeld door` with the partner name
  - GIVEN the export without deelnames WHEN it runs THEN the output equals today's for own applications
- [ ] Implement
- [ ] Test (PHPUnit tests/Unit/Service/ArchiMateExportSharedUsageStyleTest.php)

### Task 5: Seed and document
- **spec_ref**: openspec/changes/architecture-shared-usage-on-views/specs/shared-usage-on-views/spec.md#requirement-req-suv-002-a-shared-application-must-be-drawn-apart-from-an-own-application-without-relying-on-colour
- **files**: `lib/Settings/stackiq_mock_register.json`, `docs/features/shared-usage-on-views.md`
- **acceptance_criteria**:
  - GIVEN a fresh demo import WHEN a GEMMA view opens with both switches on THEN at least one own and one shared application show on the same component
  - GIVEN the docs WHEN a reader opens the page THEN it explains both switches, the legend and the export colours, with a screenshot (ADR-010)
- [ ] Implement

## Verification

- `openspec validate architecture-shared-usage-on-views --type change --strict`
- vitest: tests/vitest/viewStoreEnrichment.spec.js, tests/vitest/usageOverlay.spec.js
- PHPUnit: tests/Unit/Service/ArchiMateExportSharedUsageStyleTest.php
- Playwright: tests/e2e/spec-coverage/shared-usage-on-views.spec.ts
- Open the organisation export in Archi once and screenshot a view with both kinds, for the PR body
