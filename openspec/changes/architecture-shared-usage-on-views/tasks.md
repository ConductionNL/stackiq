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
- [x] Implement (already on development: `fetchView` sends exactly the flags that are on; covered by `tests/vitest/viewStoreToggles.spec.js`)
- [x] Test (vitest tests/vitest/viewStoreEnrichment.spec.js)

### Task 2: Map usage to overlay nodes
- **spec_ref**: openspec/changes/architecture-shared-usage-on-views/specs/shared-usage-on-views/spec.md#requirement-req-suv-002-a-shared-application-must-be-drawn-apart-from-an-own-application-without-relying-on-colour
- **files**: `src/utils/usageOverlay.js`, `tests/vitest/usageOverlay.spec.js`
- **acceptance_criteria**:
  - GIVEN an enriched node with one `usage` and one `deelnamesGebruik` entry WHEN mapped THEN two Vue Flow nodes come back with `parentNode` set to the component's node id and `extent: 'parent'`
  - GIVEN a `deelnamesGebruik` entry WHEN mapped THEN its node has class `usage-node--shared`, `data.badge` "Shared" and `data.ariaLabel` "<name>, shared by <_sourceOrganization>"
  - GIVEN the same application in both lists for one component WHEN mapped THEN one own node comes back
  - GIVEN more usages than fit the component's height WHEN mapped THEN the last node is "+N more"
  - GIVEN `CnGraphCanvas` from `@conduction/nextcloud-vue` WHEN a node with a `class` renders THEN the class reaches the Vue Flow node wrapper (check first; if not, file it on nextcloud-vue and keep the badge)
- [x] Implement (`src/utils/usageOverlay.js`; the `CnGraphCanvas` class pass-through check waits for the view page of `architecture-views-editor`)
- [x] Test (vitest tests/vitest/usageOverlay.spec.js)

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
- [x] Implement (`ArchitectureViewEditor.vue`: a "Show applications" fieldset with two switches on imported views only, the overlay nested through `usageOverlayNodes()`, `UsageOverlayLegend.vue`, a click on an application opens `GebruikDetail`; the dashed border and badge are drawn on the node body the slot renders, so they do not depend on Vue Flow passing `class` to its wrapper; unit-tested in vitest `tests/vitest/architectureViewEditorUsageOverlay.spec.js`)
- [ ] Test (Playwright tests/e2e/spec-coverage/shared-usage-on-views.spec.ts: needs a live instance with imported GEMMA views and usage data)
  - 17 Nov (round 2): the spec is written. It opens a real imported GEMMA view as a regular user with an empty storage state, asserts both switches off and no application node on open, then switches "Shared with partners" and "Our applications" on and checks the include flags each request sent, the node inside its component, the "Shared" text, solid against dashed borders, the legend, the accessible name under keyboard focus and the click to `GebruikDetail`. The own and shared usages are added to the view response in the API's enrichment shape, because whether a usage is own or shared depends on the installing organisation. gate-19 passes with its four references; left unchecked until it has run.

### Task 4: Style shared applications apart in the organisation export
- **spec_ref**: openspec/changes/architecture-shared-usage-on-views/specs/shared-usage-on-views/spec.md#requirement-req-suv-003-the-organisation-archimate-export-must-draw-shared-applications-apart-from-own-ones
- **files**: `lib/Service/ArchiMateExportService.php`, `tests/Unit/Service/ArchiMateExportSharedUsageStyleTest.php`
- **acceptance_criteria**:
  - GIVEN own and shared application elements WHEN `copyAndEnrichViews()` builds its lookup THEN every entry has `kind` own or shared and shared entries name the partner
  - GIVEN the same application owned and shared on one component WHEN nodes are injected THEN one node is nested, with the own style
  - GIVEN a shared entry WHEN `processNodesForInjection()` nests it THEN fillColor is 210,225,255 and lineColor 40,80,170
  - GIVEN a shared element WHEN it is generated THEN it carries the property `Gedeeld door` with the partner name
  - GIVEN the export without deelnames WHEN it runs THEN the output equals today's for own applications
- [x] Implement (`buildRefCompApps()` carries kind and partner, owned wins over shared; `applicationNodeStyle()`; `Gedeeld door` property definition and values; restores the lost negation in the node-list normalisation so applications are nested at all)
- [x] Test (PHPUnit tests/Unit/Service/ArchiMateExportSharedUsageStyleTest.php)

### Task 5: Seed and document
- **spec_ref**: openspec/changes/architecture-shared-usage-on-views/specs/shared-usage-on-views/spec.md#requirement-req-suv-002-a-shared-application-must-be-drawn-apart-from-an-own-application-without-relying-on-colour
- **files**: `lib/Settings/stackiq_mock_register.json`, `docs/features/shared-usage-on-views.md`
- **acceptance_criteria**:
  - GIVEN a fresh demo import WHEN a GEMMA view opens with both switches on THEN at least one own and one shared application show on the same component
  - GIVEN the docs WHEN a reader opens the page THEN it explains both switches, the legend and the export colours, with a screenshot (ADR-010)
- [ ] Implement (docs done: `docs/features/shared-usage-on-views.md`, linked from the features index. Left: the screenshot needs a live instance; the demo seed is left too, because `stackiq_mock_register.json` is a generated descriptor and whether a usage counts as own or shared depends on the organisation that installs it, so a static seed cannot promise both kinds on one component)

## Verification

- `openspec validate architecture-shared-usage-on-views --type change --strict`
- vitest: tests/vitest/viewStoreEnrichment.spec.js, tests/vitest/usageOverlay.spec.js
- PHPUnit: tests/Unit/Service/ArchiMateExportSharedUsageStyleTest.php
- Playwright: tests/e2e/spec-coverage/shared-usage-on-views.spec.ts
- Open the organisation export in Archi once and screenshot a view with both kinds, for the PR body
