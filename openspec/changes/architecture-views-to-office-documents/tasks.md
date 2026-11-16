# Tasks: architecture-views-to-office-documents

## Implementation tasks

### Task 1: SVG renderer
- **spec_ref**: openspec/changes/architecture-views-to-office-documents/specs/architecture-view-office-export/spec.md#requirement-req-avo-001-stackiq-shall-draw-a-view-as-a-standalone-svg-from-its-stored-geometry
- **files**: `lib/Service/ViewImageService.php`, `tests/Unit/Service/ViewImageServiceTest.php`
- **acceptance_criteria**:
  - GIVEN an imported GEMMA view fixture with nested nodes WHEN it is drawn THEN every node sits at its stored position and size and children sit inside their parents
  - GIVEN connections with bendpoints WHEN they are drawn THEN each path runs through its bendpoints with the arrowhead of its relation type
  - GIVEN a name with markup WHEN it is drawn THEN it is escaped, and the SVG holds no script, external href or foreignObject
- [x] Implement
- [x] Test (PHPUnit `ViewImageServiceTest`)

### Task 2: Image route with RBAC on
- **spec_ref**: openspec/changes/architecture-views-to-office-documents/specs/architecture-view-office-export/spec.md#requirement-req-avo-001-stackiq-shall-draw-a-view-as-a-standalone-svg-from-its-stored-geometry
- **files**: `lib/Controller/ViewController.php`, `appinfo/routes.php`, `tests/Unit/Controller/ViewControllerImageTest.php`
- **acceptance_criteria**:
  - GIVEN a view the caller may read WHEN `GET /api/views/{viewId}/image.svg` is called THEN the answer is `image/svg+xml` with a file name built from the view name
  - GIVEN a view OpenRegister does not return for the caller WHEN the route is called THEN the answer is 404
- [x] Implement
- [x] Test (PHPUnit `ViewControllerImageTest`, route reachability gate)
  - `ViewController::getViewImage()` at `GET /api/views/{viewId}/image.svg` (`NoAdminRequired`, `NoCSRFRequired`) reads the stored view through the new `ViewServiceInterface::findStoredView()`, which is the same OpenRegister `find()` with RBAC and multitenancy on that `getView()` uses, and answers 404 when it comes back empty; otherwise the `ViewImageService` SVG as `image/svg+xml` with a file name built from the view name. Documented in `openapi.json`. PHPUnit: `ViewControllerImageTest` (5), two `findStoredView` cases in `ViewServiceAccessScopeTest`.

### Task 3: filinq gateway and document route
- **spec_ref**: openspec/changes/architecture-views-to-office-documents/specs/architecture-view-office-export/spec.md#requirement-req-avo-002-stackiq-shall-ask-filinq-for-a-word-document-or-a-powerpoint-slide-that-holds-the-view
- **files**: `lib/Service/ViewDocumentGateway.php`, `lib/Controller/ViewController.php`, `appinfo/routes.php`, `tests/Stubs/`, `tests/Unit/Service/ViewDocumentGatewayTest.php`, `tests/Unit/Controller/ViewControllerDocumentTest.php`
- **acceptance_criteria**:
  - GIVEN the contract stub WHEN a docx or pptx is requested THEN the gateway sends the content fields of design D3 and returns the Files path and file id
  - GIVEN the contract does not resolve WHEN the route is called THEN it answers 503 with the reason and the gateway is not called
  - GIVEN the source tree WHEN it is scanned THEN only the gateway references filinq
- [x] Implement
- [x] Test (PHPUnit `ViewDocumentGatewayTest`, `ViewControllerDocumentTest`, hydra gate no-phantom-cross-app-rpc)
  - filinq now publishes its document contract: the typed command event `OCA\Filinq\Event\DocumentGenerationRequestedEvent` (render a template over data and file the result), dispatched in-process. `ViewDocumentGateway` resolves it by class name only: `isAvailable()` is true when that class exists and is an event. `requestDocument()` sends an inline, escaped template over the D3 content as `docx` or `pptx`, with `storeFile` set and the caller as owner, and returns the Files path, file id and name. A refusal or an unhandled event raises. `ViewDocumentContent` builds the content: name, description, viewpoint, a legend of element types, the date, a link to `/views/{id}` and the SVG from `ViewImageService`. `POST /api/views/{viewId}/document` (`NoAdminRequired`, CSRF kept) answers 503 "Word and PowerPoint need the document app filinq." before the view is read when the contract does not resolve. It also answers 400 for another format, 404 for a view the caller may not read, 502 when filinq refuses, and otherwise 201. Documented in `openapi.json`. PHPUnit: `ViewDocumentGatewayTest` (8, against a stub of the contract in `tests/Stubs/Filinq/`, including a scan that only the gateway references filinq and nothing calls its routes), `ViewDocumentContentTest` (3), `ViewControllerDocumentTest` (7). Hydra gate 27 no-phantom-cross-app-rpc passes.

### Task 4: Export menu and initial state
- **spec_ref**: openspec/changes/architecture-views-to-office-documents/specs/architecture-view-office-export/spec.md#requirement-req-avo-003-the-document-actions-shall-say-why-they-are-unavailable-when-filinq-cannot-take-the-request
- **files**: `lib/AppInfo/Application.php`, `src/views/architecture/ArchitectureViewEditor.vue`, `tests/e2e/workflows/architecture-view-export.spec.ts`
- **acceptance_criteria**:
  - GIVEN a view page WHEN the Export menu opens THEN it lists Download SVG, Create Word document and Create PowerPoint slide
  - GIVEN `view_document_export` is false WHEN the menu opens THEN the two document actions are disabled with the text about filinq
  - GIVEN Download SVG WHEN it is chosen THEN the browser receives the SVG file
- [ ] Implement
  - Done (2026-11-15): `Application::provideViewDocumentExport()` provides `view_document_export` lazily from `ViewDocumentGateway::isAvailable()` (`tests/Unit/AppInfo/ApplicationViewDocumentExportTest.php`). `src/services/viewExport.js` holds the menu: the three actions in order, the two document actions disabled with "Word and PowerPoint need the document app filinq" when the flag is false, the image URL, the document request (never throws; a 503 reads as unavailable), the toast text and the Open in Files link (`tests/vitest/viewExport.spec.js`, 12).
  - Left: mounting the menu in `src/views/architecture/ArchitectureViewEditor.vue`, which exists only on the unmerged `quality/build-architecture-views-editor` branch (change `architecture-views-editor`).
- [ ] Test (Playwright `architecture-view-export.spec.ts`)

### Task 5: Documentation and translations
- **spec_ref**: openspec/changes/architecture-views-to-office-documents/specs/architecture-view-office-export/spec.md#requirement-req-avo-003-the-document-actions-shall-say-why-they-are-unavailable-when-filinq-cannot-take-the-request
- **files**: `docs/features/architecture-views.md`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN the feature page WHEN it is read THEN an Export section shows the menu and a downloaded SVG in a screenshot and says what filinq adds
  - GIVEN a Dutch instance WHEN the Export menu opens THEN every label and the filinq notice read in Dutch
- [ ] Implement
  - Done (2026-11-15): the menu's English and Dutch strings in `l10n/en.json`, `l10n/nl.json` and their `.js`.
  - Left: the Export section of `docs/features/architecture-views.md`, which the `architecture-views-editor` branch creates (writing it here would conflict), with its screenshot.
- [ ] Test (`tests/l10n` key parity, screenshots captured with Playwright)

## Verification

- `openspec validate architecture-views-to-office-documents --type change --strict`
- PHPUnit: `ViewImageServiceTest`, `ViewControllerImageTest`, `ViewDocumentGatewayTest`, `ViewControllerDocumentTest`
- Playwright: `tests/e2e/workflows/architecture-view-export.spec.ts`
- Documentation in `docs/features/architecture-views.md` with screenshots (ADR-010)
- English and Dutch strings for every new label (ADR-005)
