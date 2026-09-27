# Tasks: architecture-assistant-drafted-views

## Implementation tasks

### Task 1: Register fragment for the assistant mark
- **spec_ref**: openspec/changes/architecture-assistant-drafted-views/specs/architecture-assistant-views/spec.md#requirement-req-aav-003-every-object-the-draft-tool-writes-shall-carry-the-assistant-mark-in-the-same-write
- **files**: `lib/Settings/register.d/architecture-assistant-drafted-views.json`, `tests/Unit/Service/ArchitectureViewsRegisterShapeTest.php`
- **acceptance_criteria**:
  - GIVEN the merged register WHEN it loads THEN `origin` on `view`, `element` and `relation` accepts imported, drawn and assistant
  - GIVEN the merged register WHEN it loads THEN `view` has `draftedFor` and `draftedAt` and carries a bumped version
  - GIVEN a fresh install WHEN the seed runs THEN the drafted example view exists with `origin` assistant
- [ ] Implement
- [ ] Test (PHPUnit `ArchitectureViewsRegisterShapeTest`, `RegisterFragmentMergeTest`)

### Task 2: Draft service
- **spec_ref**: openspec/changes/architecture-assistant-drafted-views/specs/architecture-assistant-views/spec.md#requirement-req-aav-002-stackiq-shall-offer-a-create-tool-that-writes-a-draft-view-from-elements-and-relations
- **files**: `lib/Service/ArchitectureViewDraftService.php`, `tests/Unit/Service/ArchitectureViewDraftServiceTest.php`
- **acceptance_criteria**:
  - GIVEN an unknown type, a dangling key or a list over the cap WHEN `draftView` runs THEN it returns an error and calls no save
  - GIVEN a new element whose type and exact name match an existing element WHEN `draftView` runs THEN the existing element is referenced
  - GIVEN a relation of the same type between the same two elements WHEN `draftView` runs THEN it is reused
  - GIVEN any object the service creates WHEN it is saved THEN `origin` assistant is in the same payload, and the view carries `draftedFor` and `draftedAt`
  - GIVEN `searchElements` WHEN it runs THEN it returns only uuid, identifier, name, ArchiMate type and GEMMA type, at most 50 rows
- [ ] Implement
- [ ] Test (PHPUnit `ArchitectureViewDraftServiceTest`)

### Task 3: Two tools on the stackiq MCP provider
- **spec_ref**: openspec/changes/architecture-assistant-drafted-views/specs/architecture-assistant-views/spec.md#requirement-req-aav-001-stackiq-shall-offer-a-read-tool-that-finds-architecture-elements-for-a-draft
- **files**: `lib/Mcp/StackiqToolProvider.php`, `tests/Unit/Mcp/StackiqToolProviderDraftToolsTest.php`
- **acceptance_criteria**:
  - GIVEN the provider WHEN it lists its tools THEN `stackiq.searchArchitectureElements` is scope read, reach user, read-only, and `stackiq.draftView` is scope create, reach instance, not read-only, not destructive, not idempotent
  - GIVEN a call to either tool WHEN it is dispatched THEN the arguments pass `McpArgumentValidator` and reach the service unchanged
- [ ] Implement
- [ ] Test (PHPUnit `StackiqToolProviderDraftToolsTest`)

### Task 4: Caller rights and shared readers
- **spec_ref**: openspec/changes/architecture-assistant-drafted-views/specs/architecture-assistant-views/spec.md#requirement-req-aav-005-the-draft-tools-shall-run-with-the-callers-rights-and-shall-keep-drafts-out-of-shared-readers
- **files**: `lib/Service/ArchitectureViewDraftService.php`, `tests/Unit/Service/ArchitectureViewDraftServiceTest.php`, `tests/Unit/Service/ViewServiceDrawnViewTest.php`, `tests/Unit/Service/ArchiMateExportServiceDrawnFilterTest.php`
- **acceptance_criteria**:
  - GIVEN OpenRegister refuses the view save WHEN `draftView` runs THEN the result is forbidden and no later save runs
  - GIVEN a view with `origin` assistant WHEN `GET /api/views` or the full ArchiMate export runs THEN it is left out
- [ ] Implement
- [ ] Test (PHPUnit `ArchitectureViewDraftServiceTest`, `ViewServiceDrawnViewTest`, `ArchiMateExportServiceDrawnFilterTest`)

### Task 5: Draft notice and first-open layout in the editor
- **spec_ref**: openspec/changes/architecture-assistant-drafted-views/specs/architecture-assistant-views/spec.md#requirement-req-aav-004-a-drafted-view-shall-be-laid-out-when-it-is-first-opened
- **files**: `src/views/architecture/ArchitectureViewEditor.vue`, `src/utils/viewGraph.js`, `tests/vitest/viewGraph.spec.js`, `tests/e2e/workflows/architecture-assistant-views.spec.ts`
- **acceptance_criteria**:
  - GIVEN a view with `origin` assistant WHEN it opens THEN the notice names the user it was drafted for and the date, also after a save
  - GIVEN nodes without positions WHEN the editor opens them THEN `layoutFlowNodes` places each at a distinct point, and a save stores the points
  - GIVEN the Views page WHEN the origin facet is opened THEN assistant is one of its values
- [ ] Implement
- [ ] Test (vitest `viewGraph.spec.js` layout case, Playwright `architecture-assistant-views.spec.ts`)

### Task 6: Documentation and translations
- **spec_ref**: openspec/changes/architecture-assistant-drafted-views/specs/architecture-assistant-views/spec.md#requirement-req-aav-003-every-object-the-draft-tool-writes-shall-carry-the-assistant-mark-in-the-same-write
- **files**: `docs/features/architecture-views.md`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN the feature page WHEN it is read THEN a section shows a drafted view with its notice in a screenshot and names the two tools
  - GIVEN a Dutch instance WHEN a drafted view opens THEN the notice reads in Dutch
- [ ] Implement
- [ ] Test (`tests/l10n` key parity, screenshot captured with Playwright)

## Verification

- `openspec validate architecture-assistant-drafted-views --type change --strict`
- PHPUnit: `ArchitectureViewDraftServiceTest`, `StackiqToolProviderDraftToolsTest`, `ArchitectureViewsRegisterShapeTest`, `ViewServiceDrawnViewTest`, `ArchiMateExportServiceDrawnFilterTest`
- vitest: `viewGraph.spec.js`
- Playwright: `tests/e2e/workflows/architecture-assistant-views.spec.ts`
- Documentation in `docs/features/architecture-views.md` with a screenshot (ADR-010)
- English and Dutch strings for the notice and the facet value (ADR-005)
