# generated-api-docs Specification

## Purpose

The catalogue API is documented by generated OpenAPI documents that a developer reads in stackiq and downloads. Matrix row `stackiq:share-api-docs`.

## Requirements

### Requirement: REQ-GAD-001 Stackiq's own endpoints are described by an OpenAPI document kept in step with the routes

Stackiq SHALL describe every versioned `/api/v1/` endpoint it routes in `openapi.json`, an OpenAPI 3 document served by `OpenApiController`, with standard-protocol paths under their own unversioned base and OpenRegister-hosted operations marked `x-provided-by`. Its unit suite, which `composer check:strict` runs, SHALL fail when a routed endpoint has no documented path or a documented path has no route.

#### Scenario: A new endpoint cannot ship undocumented
@e2e exclude Build-time check; tests/Unit/Middleware/Support/OpenApiDocumentTest.php runs in the unit suite.

- **GIVEN** a developer adds a `/api/v1/` route to `appinfo/routes.php` and does not document it in `openapi.json`
- **WHEN** the unit suite runs
- **THEN** it fails and names the undocumented route

### Requirement: REQ-GAD-002 A developer reads both documents on one page in stackiq

Stackiq SHALL offer an API documentation page, linked from the footer menu, with the OpenRegister document of the stackiq register and stackiq's own document, each rendered in the app with operations, parameters, responses and schemas, and each downloadable as JSON. The page SHALL NOT load a third-party viewer.

#### Scenario: A supplier's developer reads the offered usage endpoint
@e2e tests/e2e/workflows/api-docs.spec.ts

- **GIVEN** a developer at a supplier signed in to stackiq
- **WHEN** they open API documentation, then Stackiq endpoints
- **THEN** they see `GET /api/koppelingen-gebruik/{uuid}` with its parameters and response shape

#### Scenario: Downloading the catalogue objects document
@e2e tests/e2e/workflows/api-docs.spec.ts

- **GIVEN** the API documentation page
- **WHEN** the developer opens Catalogue objects and clicks Download JSON
- **THEN** an OpenAPI document for the stackiq register downloads

### Requirement: REQ-GAD-003 The hand-written documentation points to the generated documents

`GET /api/views/docs` and `GET /api/aangeboden-gebruik/docs` SHALL keep answering and SHALL carry a link to the API documentation page, and the API reference on the docs site SHALL point to the page and the two documents.

#### Scenario: An old integration finds the new documents
@e2e exclude API body check; tests/Unit/Controller/AangebodenGebruikControllerTest.php asserts the documentation link.

- **GIVEN** an integration that reads `GET /api/aangeboden-gebruik/docs`
- **WHEN** it calls the endpoint
- **THEN** the answer holds its current documentation and a link to `/apps/stackiq/api-docs`
