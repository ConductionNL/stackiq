# Tasks: architecture-views-editor

## Implementation tasks

### Task 1: Register fragment for drawn views and versions
- **spec_ref**: openspec/changes/architecture-views-editor/specs/architecture-views-editor/spec.md#requirement-req-ave-004-the-views-list-shall-filter-on-tag-status-and-owner
- **files**: `lib/Settings/register.d/architecture-views.json`, `tests/Unit/Service/ArchitectureViewsRegisterShapeTest.php`
- **acceptance_criteria**:
  - GIVEN the merged register WHEN it loads THEN `view` has `tags`, `status`, `origin` and `basedOn`, and `element` and `relation` have `origin`
  - GIVEN the merged register WHEN it loads THEN `vng-gemma` lists `view-version` with magic mapping on
  - GIVEN the view lifecycle WHEN the shape test reads it THEN every `from` and `to` value is a member of the status enum
  - GIVEN the fragment WHEN it is compared with development THEN `view`, `element` and `relation` carry bumped versions
- [ ] Implement
- [ ] Test (PHPUnit `ArchitectureViewsRegisterShapeTest`, `RegisterFragmentMergeTest`)

### Task 2: Views index page and Architecture menu group
- **spec_ref**: openspec/changes/architecture-views-editor/specs/architecture-views-editor/spec.md#requirement-req-ave-004-the-views-list-shall-filter-on-tag-status-and-owner
- **files**: `src/manifest.d/architecture-views.json`, `src/menu-layout.json`
- **acceptance_criteria**:
  - GIVEN the effective manifest WHEN it is built THEN `Views` is an index page at `/views` over `@resolve:amef_register` and `view` with the quick filters All, Mine, Drawn and Imported
  - GIVEN the effective menu WHEN it renders THEN Standards and Views sit under an Architecture group and the top-level count is unchanged
- [ ] Implement
- [ ] Test (`tests/validate-manifest.js`, Playwright `tests/e2e/workflows/architecture-views.spec.ts` tag and Mine filters)

### Task 3: Canvas shape mapping
- **spec_ref**: openspec/changes/architecture-views-editor/specs/architecture-views-editor/spec.md#requirement-req-ave-001-an-application-owner-shall-draw-and-save-an-architecture-view
- **files**: `src/utils/viewGraph.js`, `tests/vitest/viewGraph.spec.js`
- **acceptance_criteria**:
  - GIVEN an imported GEMMA view WHEN it is mapped to canvas nodes and back THEN every node keeps its absolute position, size and parent
  - GIVEN a canvas with a child node WHEN it is mapped to the save shape THEN the child carries its parent's `viewNodeId`
- [ ] Implement
- [ ] Test (vitest `viewGraph.spec.js`)

### Task 4: View editor page on CnGraphCanvas
- **spec_ref**: openspec/changes/architecture-views-editor/specs/architecture-views-editor/spec.md#requirement-req-ave-001-an-application-owner-shall-draw-and-save-an-architecture-view
- **files**: `src/views/architecture/ArchitectureViewEditor.vue`, `src/store/modules/architectureView.js`, `src/customComponents.js`, `src/manifest.d/architecture-views.json`
- **acceptance_criteria**:
  - GIVEN the editor WHEN the user places two elements, connects them and saves THEN a `view` with `origin` drawn holds both nodes and the connection
  - GIVEN a new element from the palette WHEN the view is saved THEN an `element` with `origin` drawn and the chosen ArchiMate type exists
  - GIVEN a colleague holds the lock WHEN the user opens the view THEN it is read-only with a notice naming the colleague
- [ ] Implement
- [ ] Test (Playwright `architecture-views.spec.ts` draw and reopen, lock notice)

### Task 5: Relations behind connections
- **spec_ref**: openspec/changes/architecture-views-editor/specs/architecture-views-editor/spec.md#requirement-req-ave-003-a-drawn-connection-shall-reference-a-relation-object
- **files**: `src/store/modules/architectureView.js`, `tests/vitest/architectureViewStore.spec.js`
- **acceptance_criteria**:
  - GIVEN no relation of the chosen type between two elements WHEN they are connected THEN one `relation` with `origin` drawn is created
  - GIVEN such a relation exists WHEN they are connected again THEN it is reused
  - GIVEN the view write fails after the relation write WHEN the user saves again THEN no second relation is created
- [ ] Implement
- [ ] Test (vitest `architectureViewStore.spec.js`)

### Task 6: Read-only imported views and Copy to edit
- **spec_ref**: openspec/changes/architecture-views-editor/specs/architecture-views-editor/spec.md#requirement-req-ave-002-imported-gemma-views-shall-open-read-only-and-shall-be-copied-before-editing
- **files**: `src/views/architecture/ArchitectureViewEditor.vue`, `src/store/modules/architectureView.js`, `tests/Unit/Service/ArchitectureViewsImportIsolationTest.php`
- **acceptance_criteria**:
  - GIVEN an imported view WHEN it opens THEN no edit control renders and Copy to edit is offered
  - GIVEN Copy to edit WHEN it completes THEN a drawn view with a fresh identifier and `basedOn` opens in edit mode
  - GIVEN a re-import WHEN it runs THEN the drawn copy is unchanged
- [ ] Implement
- [ ] Test (Playwright copy flow, PHPUnit `ArchitectureViewsImportIsolationTest`)

### Task 7: Save version and compare versions
- **spec_ref**: openspec/changes/architecture-views-editor/specs/architecture-views-editor/spec.md#requirement-req-ave-005-an-owner-shall-save-named-versions-and-compare-two-of-them
- **files**: `src/utils/viewDiff.js`, `src/views/architecture/ViewVersionCompare.vue`, `src/store/modules/architectureView.js`, `tests/vitest/viewDiff.spec.js`
- **acceptance_criteria**:
  - GIVEN Save version WHEN it completes THEN a `view-version` holds the next number, the label and a copy of the nodes and connections
  - GIVEN two snapshots WHEN they are compared THEN added, removed and changed are keyed by node and connection id, and order alone is no change
  - GIVEN the compare page WHEN it renders THEN colours use `--color-success`, `--color-error` and `--color-warning` and the text list names every change
- [ ] Implement
- [ ] Test (vitest `viewDiff.spec.js`, Playwright compare scenario)

### Task 8: Keep drawn objects out of the shared list, the single view read and the full export
- **spec_ref**: openspec/changes/architecture-views-editor/specs/architecture-views-editor/spec.md#requirement-req-ave-006-drawn-views-shall-stay-inside-the-organisation-that-drew-them
- **files**: `lib/Service/ViewService.php`, `lib/Service/ArchiMateExportService.php`, `tests/Unit/Service/ViewServiceDrawnViewTest.php`, `tests/Unit/Service/ArchiMateExportServiceDrawnFilterTest.php`
- **acceptance_criteria**:
  - GIVEN a drawn and an imported view WHEN `GET /api/views` runs THEN only the imported view is returned and cached
  - GIVEN a drawn view WHEN `GET /api/views/{viewId}` is called with its uuid THEN the answer is 404
  - GIVEN drawn objects WHEN the full ArchiMate export runs THEN none of them is in the file
  - GIVEN a view imported before this change with no origin WHEN either reader runs THEN it is kept as imported
- [ ] Implement
- [ ] Test (PHPUnit `ViewServiceDrawnViewTest`, `ArchiMateExportServiceDrawnFilterTest`)

### Task 9: Documentation and translations
- **spec_ref**: openspec/changes/architecture-views-editor/specs/architecture-views-editor/spec.md#requirement-req-ave-001-an-application-owner-shall-draw-and-save-an-architecture-view
- **files**: `docs/features/architecture-views.md`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN the feature page WHEN it is read THEN it shows the editor, the tag filter and a compare, each with a screenshot
  - GIVEN a Dutch instance WHEN the Views page renders THEN every new string reads in Dutch
- [ ] Implement
- [ ] Test (`tests/l10n` key parity, screenshots captured with Playwright)

## Verification

- `openspec validate architecture-views-editor --type change --strict`
- PHPUnit: `ArchitectureViewsRegisterShapeTest`, `ArchitectureViewsImportIsolationTest`, `ViewServiceDrawnViewTest`, `ArchiMateExportServiceDrawnFilterTest`
- vitest: `viewGraph.spec.js`, `viewDiff.spec.js`, `architectureViewStore.spec.js`
- Playwright: `tests/e2e/workflows/architecture-views.spec.ts`
- Documentation in `docs/features/architecture-views.md` with screenshots (ADR-010)
- English and Dutch strings for every new label (ADR-005)
