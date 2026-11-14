# organisation-registration specification

## ADDED Requirements

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
