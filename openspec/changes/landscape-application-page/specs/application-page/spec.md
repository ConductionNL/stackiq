# application-page specification

**Status**: proposed
**Scope**: stackiq
**OpenSpec changes**:
- landscape-application-page

## Purpose

One page per application shows its data, contact person, versions, usages, contracts and compliance, and opens from the Applications list. Matrix rows `stackiq:land-detail-page`, `stackiq:ctr-per-application` and `stackiq:mkt-contacts-per-product`.

## ADDED Requirements

### Requirement: REQ-APG-001 The application page shows every field it lists under the schema's current keys

The application page `ModuleDetail` and the suite page `SuiteDetail` SHALL list only keys that exist on their schema, so the short description, long description and the supplier's contact person of a product render. The compliance list SHALL show the BIO measure of each claim.

#### Scenario: A buyer reads a product's contact person
@e2e tests/e2e/workflows/application-page.spec.ts

- **GIVEN** a supplier registered product X with a short description and contact person Anna
- **WHEN** a municipal buyer opens the page of product X
- **THEN** the data widget shows the short description and contact person Anna

#### Scenario: A stale key cannot ship again
@e2e exclude Build-time guard; tests/vitest/manifestIncludeKeys.spec.js fails when a detail page lists a key its schema lacks.

- **GIVEN** a detail page whose data widget lists a key the schema does not have
- **WHEN** the vitest suite runs
- **THEN** the test fails and names the page and the key

### Requirement: REQ-APG-002 The application page lists the usages of the application

The application page SHALL list the usages of the application the user may read, with the using organisation, the version and the status.

#### Scenario: A supplier sees which organisations use its product
@e2e tests/e2e/workflows/application-page.spec.ts

- **GIVEN** two municipalities have a usage of product X
- **WHEN** the supplier of X opens its page
- **THEN** the Usages list shows both usages with version and status

### Requirement: REQ-APG-003 The application page lists the contracts behind the application

The application page SHALL list every readable contract whose usage is a usage of the application, or whose service offers the application, once each, with contract number, type, end date and status, and each row SHALL open the contract page.

#### Scenario: An information manager finds the contract behind an application
@e2e tests/e2e/workflows/application-page.spec.ts

- **GIVEN** the municipality has a usage of application X and a contract on that usage
- **WHEN** the information manager opens the page of X
- **THEN** the Contracts list shows that contract with its end date
- **AND** clicking it opens the contract page

### Requirement: REQ-APG-004 The Applications list opens the application page

Clicking a row, or its View action, on the Applications list SHALL open that application's page.

#### Scenario: A user opens an application from the list
@e2e tests/e2e/workflows/application-page.spec.ts

- **GIVEN** the Applications list at `/modules`
- **WHEN** the user clicks the row of application X
- **THEN** the page `/modules/<id of X>` opens
