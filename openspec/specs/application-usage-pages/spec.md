# application-usage-pages Specification

## Purpose
An organisation records the applications it uses, with the version it runs, its lifecycle status and its owners. Matrix rows `stackiq:land-register-application`, `stackiq:life-version-in-use` and `stackiq:land-application-owner`.

## Requirements

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

#### Scenario: The usage list names an owner only for who can read the contact
@e2e exclude Needs a Contacts address book per user; tests/Unit/Controller/ContactNamesControllerTest.php asserts the read with the caller's rights and the 401, and tests/Unit/Service/StackiqContactSyncServiceTest.php testANameLookupSkipsTheSystemAddressBookAndMatchesExactly asserts the lookup skips the system address book.

- **GIVEN** a usage whose business owner is a contact person linked to a contact in the information manager's own address book
- **WHEN** the information manager opens Applications in use
- **THEN** the Business owner column shows the contact's name
- **AND** a user without that contact in their own or shared address books sees the contact person's id, as before
- **AND** a request that is not signed in gets 401 and no name, and a contactsUid that is a local or remote user id names nobody

### Requirement: REQ-UAP-004 A usage moves through its lifecycle from its page

The usage schema SHALL declare its lifecycle on the status values its rows hold, so the detail page offers Plan, Go live, Phase out and Retire from the matching status.

#### Scenario: Going live
@e2e tests/e2e/workflows/usages.spec.ts

- **GIVEN** a usage with status Planned
- **WHEN** the information manager opens it and clicks Go live
- **THEN** its status reads In production

### Requirement: REQ-UAP-005 A contact person's name shows only to who can read the contact

The contact person page and the Name column of an organisation's contact person list SHALL show the name of the linked Nextcloud contact only to a user who can read that contact in their own or shared address books, and SHALL say why there is no name when there is none.

#### Scenario: A reader of the contact sees the name
@e2e exclude Needs a Contacts address book per user; tests/vitest/contactNames.spec.js asserts the name on the contact person page and in the list column.

- **GIVEN** a contact person linked to a contact in the information manager's own address book
- **WHEN** the information manager opens the contact person, or the organisation it belongs to
- **THEN** the contact person page opens with the contact's name
- **AND** the Name column of the organisation's contact person list shows it

#### Scenario: Without a name the page says why
@e2e exclude Needs a Contacts address book per user; tests/vitest/contactNames.spec.js asserts each empty state.

- **GIVEN** a contact person whose name the signed-in user does not get
- **WHEN** the user opens the contact person page
- **THEN** a contact person without a contactsUid reads that it is not linked to a Nextcloud contact
- **AND** a contact in an address book the user cannot read reads that the name is kept in Nextcloud Contacts, in an address book the user cannot read
- **AND** a request that failed (for example rate limited) reads that the name could not be loaded, to reload the page
- **AND** the list column keeps the contact person's id in each of these cases
