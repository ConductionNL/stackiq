# Tasks: architecture-views-to-office-documents

## Implementation tasks

### Task 1: SVG renderer
- **spec_ref**: openspec/changes/architecture-views-to-office-documents/specs/architecture-view-office-export/spec.md#requirement-req-avo-001-stackiq-shall-draw-a-view-as-a-standalone-svg-from-its-stored-geometry
- **files**: `lib/Service/ViewImageService.php`, `tests/Unit/Service/ViewImageServiceTest.php`
- **acceptance_criteria**:
  - GIVEN an imported GEMMA view fixture with nested nodes WHEN it is drawn THEN every node sits at its stored position and size and children sit inside their parents
  - GIVEN connections with bendpoints WHEN they are drawn THEN each path runs through its bendpoints with the arrowhead of its relation type
  - GIVEN a name with markup WHEN it is drawn THEN it is escaped, and the SVG holds no script, external href or foreignObject
- [ ] Implement
- [ ] Test (PHPUnit `ViewImageServiceTest`)

### Task 2: Image route with RBAC on
- **spec_ref**: openspec/changes/architecture-views-to-office-documents/specs/architecture-view-office-export/spec.md#requirement-req-avo-001-stackiq-shall-draw-a-view-as-a-standalone-svg-from-its-stored-geometry
- **files**: `lib/Controller/ViewController.php`, `appinfo/routes.php`, `tests/Unit/Controller/ViewControllerImageTest.php`
- **acceptance_criteria**:
  - GIVEN a view the caller may read WHEN `GET /api/views/{viewId}/image.svg` is called THEN the answer is `image/svg+xml` with a file name built from the view name
  - GIVEN a view OpenRegister does not return for the caller WHEN the route is called THEN the answer is 404
- [ ] Implement
- [ ] Test (PHPUnit `ViewControllerImageTest`, route reachability gate)

### Task 3: filinq gateway and document route
- **spec_ref**: openspec/changes/architecture-views-to-office-documents/specs/architecture-view-office-export/spec.md#requirement-req-avo-002-stackiq-shall-ask-filinq-for-a-word-document-or-a-powerpoint-slide-that-holds-the-view
- **files**: `lib/Service/ViewDocumentGateway.php`, `lib/Controller/ViewController.php`, `appinfo/routes.php`, `tests/Stubs/`, `tests/Unit/Service/ViewDocumentGatewayTest.php`, `tests/Unit/Controller/ViewControllerDocumentTest.php`
- **acceptance_criteria**:
  - GIVEN the contract stub WHEN a docx or pptx is requested THEN the gateway sends the content fields of design D3 and returns the Files path and file id
  - GIVEN the contract does not resolve WHEN the route is called THEN it answers 503 with the reason and the gateway is not called
  - GIVEN the source tree WHEN it is scanned THEN only the gateway references filinq
- [ ] Implement
- [ ] Test (PHPUnit `ViewDocumentGatewayTest`, `ViewControllerDocumentTest`, hydra gate no-phantom-cross-app-rpc)

### Task 4: Export menu and initial state
- **spec_ref**: openspec/changes/architecture-views-to-office-documents/specs/architecture-view-office-export/spec.md#requirement-req-avo-003-the-document-actions-shall-say-why-they-are-unavailable-when-filinq-cannot-take-the-request
- **files**: `lib/AppInfo/Application.php`, `src/views/architecture/ArchitectureViewEditor.vue`, `tests/e2e/workflows/architecture-view-export.spec.ts`
- **acceptance_criteria**:
  - GIVEN a view page WHEN the Export menu opens THEN it lists Download SVG, Create Word document and Create PowerPoint slide
  - GIVEN `view_document_export` is false WHEN the menu opens THEN the two document actions are disabled with the text about filinq
  - GIVEN Download SVG WHEN it is chosen THEN the browser receives the SVG file
- [ ] Implement
- [ ] Test (Playwright `architecture-view-export.spec.ts`)

### Task 5: Documentation and translations
- **spec_ref**: openspec/changes/architecture-views-to-office-documents/specs/architecture-view-office-export/spec.md#requirement-req-avo-003-the-document-actions-shall-say-why-they-are-unavailable-when-filinq-cannot-take-the-request
- **files**: `docs/features/architecture-views.md`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN the feature page WHEN it is read THEN an Export section shows the menu and a downloaded SVG in a screenshot and says what filinq adds
  - GIVEN a Dutch instance WHEN the Export menu opens THEN every label and the filinq notice read in Dutch
- [ ] Implement
- [ ] Test (`tests/l10n` key parity, screenshots captured with Playwright)

## Verification

- `openspec validate architecture-views-to-office-documents --type change --strict`
- PHPUnit: `ViewImageServiceTest`, `ViewControllerImageTest`, `ViewDocumentGatewayTest`, `ViewControllerDocumentTest`
- Playwright: `tests/e2e/workflows/architecture-view-export.spec.ts`
- Documentation in `docs/features/architecture-views.md` with screenshots (ADR-010)
- English and Dutch strings for every new label (ADR-005)
