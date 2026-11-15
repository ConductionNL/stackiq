# architecture-view-office-export specification

**Status**: proposed
**Scope**: stackiq
**OpenSpec changes**:
- architecture-views-to-office-documents

## Purpose

An application owner takes an architecture view out of stackiq into a document: as an SVG image they can place anywhere, or as a Word document or PowerPoint slide made for them. Stackiq draws the image from the stored view. The document is made by filinq, the fleet's document app (app id docudesk), through its published contract (ADR-075, ADR-087); stackiq never generates office files itself.

## ADDED Requirements

### Requirement: REQ-AVO-001 Stackiq SHALL draw a view as a standalone SVG from its stored geometry

`GET /api/views/{viewId}/image.svg` SHALL return an SVG image of the view: every node at its stored position and size with its name and ArchiMate type, children inside their parents, every connection along its bendpoints with the arrowhead of its relation type, and a caption with the view name and date. It SHALL read the view with OpenRegister RBAC and multitenancy on and SHALL answer 404 for a view the caller may not read. Every text taken from the view SHALL be escaped, and the SVG SHALL contain no script, no external reference and no `foreignObject`.

#### Scenario: An application owner downloads a view as SVG
@e2e tests/e2e/workflows/architecture-view-export.spec.ts

- **GIVEN** the seeded drawn view Zaakgericht werken, huidige situatie
- **WHEN** an application owner opens it and chooses Export, then Download SVG
- **THEN** the browser SHALL receive an SVG file named after the view
- **AND** the file SHALL hold the text Zaaksysteem, Documentbeheer and Zaakregistratie

#### Scenario: A view name cannot inject markup
@e2e exclude A rendering rule; tests/Unit/Service/ViewImageServiceTest.php renders a view named with a script tag and asserts the output escapes it and contains no script, external href or foreignObject.

- **GIVEN** a drawn view whose name holds `<script>`
- **WHEN** its SVG is drawn
- **THEN** the SVG SHALL show the name as text
- **AND** it SHALL contain no script element

#### Scenario: Another organisation's drawn view is not drawn
@e2e exclude The CI instance has one organisation; tests/Unit/Controller/ViewControllerImageTest.php asserts the route reads with RBAC on and answers 404 when OpenRegister finds nothing for the caller.

- **GIVEN** a drawn view of municipality A
- **WHEN** a user of municipality B requests its SVG by uuid
- **THEN** the response SHALL be 404

### Requirement: REQ-AVO-002 Stackiq SHALL ask filinq for a Word document or a PowerPoint slide that holds the view

`POST /api/views/{viewId}/document` with the format `docx` or `pptx` SHALL send filinq, through its published document contract, the view's name, description, viewpoint, a legend of its element types, the date, a link to the view page and the SVG from REQ-AVO-001, and SHALL return the Files path and file id of the document filinq made in the caller's Files. Only `lib/Service/ViewDocumentGateway.php` SHALL call filinq. Stackiq SHALL NOT generate the office file itself.

#### Scenario: An application owner creates a Word document of a view
@e2e exclude The CI instance runs without filinq, whose document contract is not published yet; tests/Unit/Service/ViewDocumentGatewayTest.php runs the gateway against a stub of the contract and asserts the format, the content fields and the returned Files path.

- **GIVEN** filinq with its document contract enabled, and a drawn view
- **WHEN** an application owner chooses Export, then Create Word document
- **THEN** a Word document with the view and its caption SHALL appear in their Files
- **AND** a toast SHALL name the file and offer Open in Files

#### Scenario: Stackiq does not reach into filinq
@e2e exclude A code rule; tests/Unit/Service/ViewDocumentGatewayTest.php and the hydra no-phantom-cross-app-rpc gate assert that no class outside the gateway references filinq and that the gateway makes no HTTP call to filinq routes.

- **GIVEN** the stackiq source
- **WHEN** it is scanned for references to filinq
- **THEN** only `ViewDocumentGateway` SHALL reference the document contract

### Requirement: REQ-AVO-003 The document actions SHALL say why they are unavailable when filinq cannot take the request

The view page SHALL offer an Export menu with Download SVG, Create Word document and Create PowerPoint slide. When filinq's document contract does not resolve, the two document actions SHALL be disabled with the text "Word and PowerPoint need the document app filinq", `POST /api/views/{viewId}/document` SHALL answer 503 with that reason and write nothing, and Download SVG SHALL still work.

#### Scenario: Without filinq the image still downloads
@e2e tests/e2e/workflows/architecture-view-export.spec.ts

- **GIVEN** a stackiq instance without filinq
- **WHEN** an application owner opens a view and the Export menu
- **THEN** Create Word document and Create PowerPoint slide SHALL be disabled with the text about filinq
- **AND** Download SVG SHALL be enabled

#### Scenario: The document route refuses without the contract
@e2e exclude A server rule; tests/Unit/Controller/ViewControllerDocumentTest.php asserts a 503 with the reason and no gateway call when isAvailable is false.

- **GIVEN** filinq's contract does not resolve
- **WHEN** a client posts to `/api/views/{viewId}/document`
- **THEN** the response SHALL be 503 and nothing SHALL be written
