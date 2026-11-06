# organisation-registration Specification

## Purpose
How an organisation that registers itself through the public portal (board SkPubliek, "Uw organisatie aanmelden") is stored and reviewed: it stays pending without a publication date until an administrator approves it, and the registration number the form asks is kept. Created by the change organisation-registration-through-the-portal (archived 2026-10-30); holds REQ-ORR-001 and REQ-ORR-002. REQ-ORR-003 keeps a registration from binding to an organisation that is not its own.

## Requirements

### Requirement: REQ-ORR-001 An organisation registered without an account is pending until reviewed

An organisation created while no user is signed in SHALL be stored with `registrationStatus` `pending` and without a `publicationDate`, whatever values the request carries, on every path that creates it through OpenRegister. A signed-in user's create, and a create from the command line (installs, imports and seed data), SHALL keep the values it sends.

#### Scenario: A supplier registers from the municipality website
@e2e exclude The form is portaliq's; tests/Unit/EventListener/AnonymousRegistrationListenerTest.php asserts the stored values and portaliq's site test the form.

- **GIVEN** a visitor who is not signed in
- **WHEN** they register "Open Kaart BV" as a supplier through the portal form
- **THEN** the organisation is stored with `registrationStatus` `pending` and no publication date
- **AND** it appears in the moderation queue

#### Scenario: A crafted request cannot activate itself
@e2e exclude A request crafted outside the browser; tests/Unit/EventListener/AnonymousRegistrationListenerTest.php sends `registrationStatus: active` and a publication date without a user and asserts both are replaced.

- **GIVEN** a request without a session that posts an organisation with `registrationStatus` `active` and a publication date
- **WHEN** OpenRegister creates it
- **THEN** it is stored as `pending` without a publication date

### Requirement: REQ-ORR-002 The organisation keeps what the registration form asks

The organisation schema SHALL hold `website`, `registrationNumber` (a KvK number or an OIN), `registrationContactName` and `registrationContactEmail`. The registration number and the two contact fields SHALL be readable only by signed-in users.

#### Scenario: An anonymous reader does not see who registered
@e2e exclude A property read rule enforced by OpenRegister; tests/Unit/Settings/OrganisationRegistrationFragmentTest.php asserts the rule on the merged schema.

- **GIVEN** an active organisation registered with a contact e-mail address
- **WHEN** an anonymous visitor reads it
- **THEN** the contact name and e-mail address are not in the answer

### Requirement: REQ-ORR-003 A registration never binds to an organisation that is not its own

An organisation object SHALL only be linked to the OpenRegister organisation with its uuid when that organisation was made from it, which shows in it being at least as new as the object, or when an administrator linked the two. Otherwise the sync SHALL leave the existing organisation, its members and its active state untouched, process none of the object's contact persons, log a warning and count the object under `organisationIdConflicts`. An anonymous create that carries the uuid of an existing OpenRegister organisation SHALL be refused.

#### Scenario: A crafted registration names an existing organisation's uuid
@e2e exclude A request crafted outside the browser; tests/Unit/EventListener/AnonymousRegistrationListenerTest.php asserts the refusal.

- **GIVEN** an OpenRegister organisation and a request without a session
- **WHEN** the request posts an organisation with that organisation's uuid as its `id`
- **THEN** the create is refused with a message that names no organisation

#### Scenario: The sync meets an object with the uuid of an older organisation
@e2e exclude The sync's decision on stored dates; tests/Unit/Service/OrganizationSyncEntityGoldenTest.php asserts no writes, no contact processing and the count.

- **GIVEN** a stored organisation object created after the OpenRegister organisation with its uuid, with no link recorded
- **WHEN** the event path or the scheduled sync processes that object
- **THEN** nothing is written and its contact persons are not processed
- **AND** the object is counted under `organisationIdConflicts`

#### Scenario: An administrator confirms a refused pair
@e2e exclude An occ command; tests/Unit/Service/OrganizationSyncEntityGoldenTest.php asserts the link and what it records.

- **GIVEN** an organisation object the sync refused, and that it does belong with the organisation holding its uuid
- **WHEN** an administrator runs `occ stackiq:organisation:link` with that uuid twice
- **THEN** the link is recorded and the sync treats the two as linked
