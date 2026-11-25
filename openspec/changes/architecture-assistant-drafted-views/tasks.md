# Tasks: architecture-assistant-drafted-views

## Implementation tasks

### Task 1: Register fragment for the assistant mark
- **spec_ref**: openspec/changes/architecture-assistant-drafted-views/specs/architecture-assistant-views/spec.md#requirement-req-aav-003-every-object-the-draft-tool-writes-shall-carry-the-assistant-mark-in-the-same-write
- **files**: `lib/Settings/register.d/architecture-assistant-drafted-views.json`, `tests/Unit/Service/ArchitectureViewsRegisterShapeTest.php`
- **acceptance_criteria**:
  - GIVEN the merged register WHEN it loads THEN `origin` on `view`, `element` and `relation` accepts imported, drawn and assistant
  - GIVEN the merged register WHEN it loads THEN `view` has `draftedFor` and `draftedAt` and carries a bumped version
  - GIVEN a fresh install WHEN the seed runs THEN the drafted example view exists with `origin` assistant
- [x] Implement
- [x] Test (PHPUnit `ArchitectureViewsRegisterShapeTest`, `RegisterFragmentMergeTest`)
- Built: `lib/Settings/register.d/assistant-drafted-views.json` (named so it merges after `architecture-views.json`; under the planned name `architecture-assistant-drafted-views.json` it would sort first and the editor fragment's versions would overwrite the bump). `origin` on view, element and relation accepts `assistant`; `view` gains `draftedFor` and `draftedAt`; view 0.0.11, element 0.0.15, relation 0.0.12. The seed holds the drafted view `seed-view-assistant-zaak-dms` with the two elements and the relation it names: the drawn examples the design meant to reuse were never seeded, so they are seeded here with `origin: assistant`. Tests: `ArchitectureViewsRegisterShapeTest::testADraftedViewCarriesItsMarkAndTheSeedHoldsOne`, the origin enums in `testViewElementAndRelationGainTheirFields`; `RegisterFragmentMergeTest` unchanged and green.

### Task 2: Draft service
- **spec_ref**: openspec/changes/architecture-assistant-drafted-views/specs/architecture-assistant-views/spec.md#requirement-req-aav-002-stackiq-shall-offer-a-create-tool-that-writes-a-draft-view-from-elements-and-relations
- **files**: `lib/Service/ArchitectureViewDraftService.php`, `tests/Unit/Service/ArchitectureViewDraftServiceTest.php`
- **acceptance_criteria**:
  - GIVEN an unknown type, a dangling key or a list over the cap WHEN `draftView` runs THEN it returns an error and calls no save
  - GIVEN a new element whose type and exact name match an existing element WHEN `draftView` runs THEN the existing element is referenced
  - GIVEN a relation of the same type between the same two elements WHEN `draftView` runs THEN it is reused
  - GIVEN any object the service creates WHEN it is saved THEN `origin` assistant is in the same payload, and the view carries `draftedFor` and `draftedAt`
  - GIVEN `searchElements` WHEN it runs THEN it returns only uuid, identifier, name, ArchiMate type and GEMMA type, at most 50 rows
- [x] Implement
- [x] Test (PHPUnit `ArchitectureViewDraftServiceTest`)
- Built: `lib/Service/ArchitectureViewDraftService.php` (`searchElements()`, `draftView()`), closed ArchiMate 3.2 element and relation type lists, caps 60/120/50, reuse of a named element, of an element of the same type and exact name and of a relation of the same type between the same two elements; every created object carries `origin: assistant` in its own save, the view `draftedFor` and `draftedAt`; nodes are written without positions. Tests: `ArchitectureViewDraftServiceTest` (19).

### Task 3: Two tools on the stackiq MCP provider
- **spec_ref**: openspec/changes/architecture-assistant-drafted-views/specs/architecture-assistant-views/spec.md#requirement-req-aav-001-stackiq-shall-offer-a-read-tool-that-finds-architecture-elements-for-a-draft
- **files**: `lib/Mcp/StackiqToolProvider.php`, `tests/Unit/Mcp/StackiqToolProviderDraftToolsTest.php`
- **acceptance_criteria**:
  - GIVEN the provider WHEN it lists its tools THEN `stackiq.searchArchitectureElements` is scope read, reach user, read-only, and `stackiq.draftView` is scope create, reach instance, not read-only, not destructive, not idempotent
  - GIVEN a call to either tool WHEN it is dispatched THEN the arguments pass `McpArgumentValidator` and reach the service unchanged
- [x] Implement
- [x] Test (PHPUnit `StackiqToolProviderDraftToolsTest`)
- Built: `lib/Mcp/McpDraftTools.php` and the two descriptors and dispatch entries on `StackiqToolProvider`. Tests: `StackiqToolProviderDraftToolsTest` (5), `McpToolCatalogueTest` (the grant matrix now lists both).

### Task 4: Caller rights and shared readers
- **spec_ref**: openspec/changes/architecture-assistant-drafted-views/specs/architecture-assistant-views/spec.md#requirement-req-aav-005-the-draft-tools-shall-run-with-the-callers-rights-and-shall-keep-drafts-out-of-shared-readers
- **files**: `lib/Service/ArchitectureViewDraftService.php`, `tests/Unit/Service/ArchitectureViewDraftServiceTest.php`, `tests/Unit/Service/ViewServiceDrawnViewTest.php`, `tests/Unit/Service/ArchiMateExportServiceDrawnFilterTest.php`
- **acceptance_criteria**:
  - GIVEN OpenRegister refuses the view save WHEN `draftView` runs THEN the result is forbidden and no later save runs
  - GIVEN a view with `origin` assistant WHEN `GET /api/views`, `GET /api/views/{viewId}` or the full ArchiMate export runs THEN it is left out
- [x] Implement
- [x] Test (PHPUnit `ArchitectureViewDraftServiceTest`, `ViewServiceDrawnViewTest`, `ArchiMateExportServiceDrawnFilterTest`)
- Built: the service writes through OpenRegister's `ObjectService` with RBAC and multitenancy on; a refused save raises `DraftRefusedException`, answered `forbidden`, and stops the draft. The shared readers already keep only imported views. Tests: `ArchitectureViewDraftServiceTest::testARefusedSaveIsForbiddenAndStopsTheDraft`, `testARefusedViewSaveIsForbidden`, `ViewServiceDrawnViewTest::testADraftedViewReadsAsMissing` and the list case, `ArchiMateExportServiceDrawnFilterTest` with an `assistant` object per schema.

### Task 5: Draft notice and first-open layout in the editor
- **spec_ref**: openspec/changes/architecture-assistant-drafted-views/specs/architecture-assistant-views/spec.md#requirement-req-aav-004-a-drafted-view-shall-be-laid-out-when-it-is-first-opened
- **files**: `src/views/architecture/ArchitectureViewEditor.vue`, `src/utils/viewGraph.js`, `tests/vitest/viewGraph.spec.js`, `tests/e2e/workflows/architecture-assistant-views.spec.ts`
- **acceptance_criteria**:
  - GIVEN a view with `origin` assistant WHEN it opens THEN the notice names the user it was drafted for and the date, also after a save
  - GIVEN nodes without positions WHEN the editor opens them THEN `layoutFlowNodes` places each at a distinct point, and a save stores the points
  - GIVEN the Views page WHEN the origin facet is opened THEN assistant is one of its values
- [x] Implement
- [x] Test (vitest `viewGraph.spec.js` layout case, Playwright `architecture-assistant-views.spec.ts`) (run 25 Nov 2026 against a live sandbox instance, NC 34 + PostgreSQL 16 + OpenRegister main: `architecture-assistant-views.spec.ts` 3 passed, after the fixes below)
- Built: `layoutUnplacedNodes()` in `src/utils/viewGraph.js` over nextcloud-vue's `needsFullLayout`/`layoutFlowNodes` (deep import, design D4); the editor lays a drafted view out on open and offers Save, shows the notice "Drafted by an assistant for {user} on {date}" and the origin label, and the store keeps `origin: assistant` on a person's save (it wrote `drawn` before). The Views origin facet reads the schema enum, so `assistant` is one of its values. Tests: vitest `viewGraph.spec.js` (4 layout cases), `architectureViewEditorMounted.spec.js` (open, save, mark kept, notice). Left: the Playwright spec `architecture-assistant-views.spec.ts`, which needs a live instance.

### Task 6: Documentation and translations
- **spec_ref**: openspec/changes/architecture-assistant-drafted-views/specs/architecture-assistant-views/spec.md#requirement-req-aav-003-every-object-the-draft-tool-writes-shall-carry-the-assistant-mark-in-the-same-write
- **files**: `docs/features/architecture-views.md`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN the feature page WHEN it is read THEN a section shows a drafted view with its notice in a screenshot and names the two tools
  - GIVEN a Dutch instance WHEN a drafted view opens THEN the notice reads in Dutch
- [x] Implement
- [ ] Test (`tests/l10n` key parity, screenshot captured with Playwright)
- Built: a section in `docs/features/architecture-views.md` naming both tools; the notice, the origin label and the fallback user in `l10n/en.json` and `l10n/nl.json` (and their `.js`). `npm run test:l10n` passes. Left: the screenshot, which needs a live instance.

Live run 25 Nov 2026 found that `stackiq.draftView` could not draft anything on a current install: the draft service addressed the fixed register slug `vng-gemma`, while the AMEF mapping (chosen by which register carries the AMEF schemas) names the stackiq register, so a named element was "not found"; its search called `searchObjectsBySlug()` with named slug arguments OpenRegister main no longer takes; and the element, relation and view it wrote lacked the schema's required `properties` (and the view its `type`). `ArchitectureViewDraftService` now reads and writes through the register and schemas `getAmefConfig()` names (the slug is the fallback without a mapping), searches with `searchObjectsPaginated()`, and writes `properties: []` (and `type: Diagram` on the view). The spec now seeds elements with `properties`, fails on a tool error inside the text payload, opens the index sidebar before the Origin facet, and picks the option through the vue-select list.

## Verification

- `openspec validate architecture-assistant-drafted-views --type change --strict`
- PHPUnit: `ArchitectureViewDraftServiceTest`, `StackiqToolProviderDraftToolsTest`, `ArchitectureViewsRegisterShapeTest`, `ViewServiceDrawnViewTest`, `ArchiMateExportServiceDrawnFilterTest`
- vitest: `viewGraph.spec.js`
- Playwright: `tests/e2e/workflows/architecture-assistant-views.spec.ts`
- Documentation in `docs/features/architecture-views.md` with a screenshot (ADR-010)
- English and Dutch strings for the notice and the facet value (ADR-005)
