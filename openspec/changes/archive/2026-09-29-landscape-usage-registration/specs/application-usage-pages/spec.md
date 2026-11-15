# application-usage-pages specification

**Status**: proposed
**Scope**: stackiq
**OpenSpec changes**:
- landscape-usage-registration

## Purpose

An organisation records the applications it uses, with the version it runs, its lifecycle status and its owners. Matrix rows `stackiq:land-register-application`, `stackiq:life-version-in-use` and `stackiq:land-application-owner`.

## ADDED Requirements

### Requirement: REQ-UAP-001 An organisation records and browses the applications it uses

Stackiq SHALL offer a page "Applications in use" at `/gebruik` over the `usage` schema that lists the usages the user may read, with application, version, status and owners, and a detail page at `/gebruik/:id` where the user edits them. The organisation page SHALL list the organisation's usages.

#### Scenario: An information manager lists the organisation's applications
@e2e tests/e2e/workflows/usages.spec.ts

- **GIVEN** the municipality uses application X at version 2.1 in production and application Y as planned
- **WHEN** its information manager opens Applications, then Applications in use
- **THEN** the list shows X with version 2.1 and status In production, and Y with status Planned

### Requirement: REQ-UAP-002 An organisation adds an application to its landscape from the application page

The application page SHALL offer "Add to our landscape" to a user who may create a usage. It SHALL open the usage form with the application filled in, the user SHALL pick the organisation, and the version picker SHALL offer only versions of that application.

#### Scenario: Adding an application with its version
@e2e tests/e2e/workflows/usages.spec.ts

- **GIVEN** application X has versions 2.0 and 2.1
- **WHEN** the information manager opens the page of X, clicks Add to our landscape, picks version 2.1 and saves
- **THEN** a usage of X by their municipality with version 2.1 exists
- **AND** it shows on Applications in use

### Requirement: REQ-UAP-003 A usage names a business owner and a technical owner

A usage SHALL carry a business owner and a technical owner, each picked from the contact persons of the using organisation.

#### Scenario: Setting both owners
@e2e tests/e2e/workflows/usages.spec.ts

- **GIVEN** the municipality has contact persons Anna and Bram
- **WHEN** the information manager edits its usage of X and sets business owner Anna and technical owner Bram
- **THEN** the usage page shows Anna as business owner and Bram as technical owner

#### Scenario: A supplier cannot open the owners
@e2e exclude Read rule of the contact person schema; tests/Unit/Settings/SchemaRbacTest.php asserts a supplier reads only its own organisation's contact persons.

- **GIVEN** a usage of the supplier's product with both owners set
- **WHEN** the supplier opens that usage
- **THEN** the owner contact persons do not open for the supplier

### Requirement: REQ-UAP-004 A usage moves through its lifecycle from its page

The usage schema SHALL declare its lifecycle on the status values its rows hold, so the detail page offers Plan, Go live, Phase out and Retire from the matching status.

#### Scenario: Going live
@e2e tests/e2e/workflows/usages.spec.ts

- **GIVEN** a usage with status Planned
- **WHEN** the information manager opens it and clicks Go live
- **THEN** its status reads In production
