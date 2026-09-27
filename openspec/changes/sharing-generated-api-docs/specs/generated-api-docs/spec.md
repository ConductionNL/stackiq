# generated-api-docs specification

**Status**: proposed
**Scope**: stackiq
**OpenSpec changes**:
- sharing-generated-api-docs

## Purpose

The catalogue API is documented by generated OpenAPI documents that a developer reads in stackiq and downloads. Matrix row `stackiq:share-api-docs`.

## ADDED Requirements

### Requirement: REQ-GAD-001 Stackiq's own endpoints are described by a generated OpenAPI document

Stackiq SHALL generate `openapi.json` from its controllers with `nextcloud/openapi-extractor`, covering every public and user-facing endpoint and leaving admin settings endpoints out, and `composer check:strict` SHALL fail when the committed file differs from a fresh generation.

#### Scenario: A new endpoint cannot ship undocumented
@e2e exclude Build-time check; composer openapi:check runs inside check:strict.

- **GIVEN** a developer adds a user-facing endpoint and does not regenerate the document
- **WHEN** `composer check:strict` runs
- **THEN** it fails and names the missing path

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
