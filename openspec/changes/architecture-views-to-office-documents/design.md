# Design: architecture-views-to-office-documents

Read at development 49e65cb4. Line numbers below are from that sha. The view page (`ViewEditor`, `src/views/architecture/ArchitectureViewEditor.vue`) and the guard on `ViewService::getView` come from `architecture-views-editor` (its D3 and D9).

## Where it fits

| Layer | Touched | Read at |
|---|---|---|
| Service | new `lib/Service/ViewImageService.php` | reads `xml.viewNodes` and `xml.viewRelationships` in the import's shape (`lib/Service/ArchiMateImportService.php:2886` to :3058, :3364) |
| Service | new `lib/Service/ViewDocumentGateway.php` | the one place stackiq talks to filinq |
| Controller and routes | `lib/Controller/ViewController.php` gains `getViewImage` and `createViewDocument`; routes `view#getViewImage` at `GET /api/views/{viewId}/image.svg` and `view#createViewDocument` at `POST /api/views/{viewId}/document`, next to `view#getView` (`appinfo/routes.php:187`) | `ViewController::getView` (:218) is the pattern |
| Initial state | `lib/AppInfo/Application.php` provides `view_document_export` next to `amef_register` (:908) | the frontend reads it with `loadState` |
| View | `src/views/architecture/ArchitectureViewEditor.vue` gains an Export menu | |
| Register, pages | none | |

## Decisions

### D1. Stackiq draws the picture on the server, as SVG

`ViewImageService::renderSvg(array $view): string` walks the stored nodes and connections and writes one SVG: a rectangle per node at its absolute `x`, `y`, `width` and `height`, children inside their parents, the element name and ArchiMate type as text, and a path per connection through its bendpoints with the arrowhead of its relation type. A caption holds the view name and the date. The palette is the ArchiMate layer convention (business, application, technology, motivation, grouping) in one constant, because an exported file is read outside Nextcloud where the theme's CSS variables do not resolve (ADR-003 governs the UI, not a file).

Rejected: capturing the canvas in the browser. `CnGraphCanvas` draws nodes as HTML and edges as SVG, the library ships no image export, and adding one would mean a DOM snapshot library whose output depends on the browser, the zoom and the theme. The stored geometry gives the same picture on every call, and filinq can take it without a browser.

Rejected: an SVG made from the canvas's own node list. It would picture what the canvas shows after pan and zoom; the stored view is what the user saved.

### D2. The image route reads with RBAC on

`GET /api/views/{viewId}/image.svg` (`#[NoAdminRequired]`) reads the view through OpenRegister's `ObjectService::find` with RBAC and multitenancy on, not through `ViewService::getView`, which reads with both off (`lib/Service/ViewService.php:328`). A reader gets imported GEMMA views and the drawn views of their own organisation; any other uuid gets a 404. The response is `image/svg+xml` with a download file name built from the view name.

Text from the view (names, the caption) is escaped before it goes into the SVG, and the SVG holds no script, no external reference and no `foreignObject`, so a view name cannot inject markup into a file that Word or a browser opens.

### D3. Word and PowerPoint through one gateway to filinq

`ViewDocumentGateway::isAvailable(): bool` and `requestDocument(string $format, array $content, string $userId): array` are the only code that knows filinq. `$format` is `docx` or `pptx`. `$content` holds the view name, description, viewpoint, a legend of the element types on the view, the generation date, a link back to the view page, and the SVG from D1. The result is the Files path and file id of the new document, which the frontend opens.

The gateway consumes filinq's published document contract, resolved the way ADR-075 Decision 2 prescribes: never a container lookup of filinq internals, never a loopback HTTP call to filinq routes, never a guessed endpoint. `isAvailable` is true only when that contract resolves; "filinq is installed" is not the probe (ADR-087 Decision 5 makes the same point for office suites). When it is false, `POST /api/views/{viewId}/document` answers 503 with a message, and nothing is written.

The contract does not exist at the shas read (proposal, Risks). The gateway is written against the contract's declared operations, render-template-with-data (ADR-075 Decision 1), and its unit tests run against a stub of that contract, so the stackiq half is done and tested when filinq publishes.

Rejected: generating the `.docx` or `.pptx` in stackiq with a PHP office library. ADR-075 gives document generation one owner and bans a second engine in a leaf app.

Rejected: a typed `IEventDispatcher` event that stackiq defines and filinq would have to listen for (the ADR-041 route the contract approvals use). The event class belongs to the app that owns the command; stackiq inventing one would be a phantom contract that nothing dispatches to.

### D4. The Export menu

The view page gets an Export menu with Download SVG, Create Word document and Create PowerPoint slide. Download SVG fetches the image route. The two document actions read the initial state `view_document_export` (provided through `IInitialState`, as `amef_register` is at `lib/AppInfo/Application.php:908`); when it is false they are disabled with the text "Word and PowerPoint need the document app filinq", so the absence is visible (ADR-075 Decision 4). After a document is made, a toast names the file and offers Open in Files.

## Declarative versus imperative

The change adds no lifecycle, aggregation, notification, relation or widget behaviour. It is two read-only renderers and one outbound call, all imperative by nature.

## Risks

- **Missing contract.** Until filinq publishes its document contract, only the SVG download works. The spec keeps the document scenarios behind the gateway's availability, and the Playwright spec covers the disabled state.
- **Large views.** A GEMMA view with a few hundred nodes makes an SVG of a few hundred kilobytes. The route streams it and sets no cache header for drawn views, which can change.
- **Fidelity.** The SVG shows boxes, names, types and arrows, not Archi's icons. The caption says the picture is drawn by stackiq, and the ArchiMate export stays the exact exchange format.
