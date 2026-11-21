# open-data-publishing specification

## Purpose

Approving a self-registered organisation makes it active everywhere, and selected entries are deleted together from a list. Matrix rows `stackiq:org-moderation` and `stackiq:land-bulk-edit`.

## ADDED Requirements

### Requirement: Approving a self-registered organisation makes it active (REQ-CLR-010)

Approving a pending registration SHALL set the organisation's registration status and its status to active in one save. User provisioning SHALL then treat the organisation as active.

#### Scenario: An approved organisation can provision its users
@e2e exclude The approval runs through ModerationService; tests/Unit/Service/ModerationServiceTest.php asserts both fields read active after approve.

- **GIVEN** a pending self-registration of Gemeente Voorbeeld
- **WHEN** an admin approves it in the moderation queue
- **THEN** its registration status and its status SHALL both read active

### Requirement: Selected entries are deleted together from the list (REQ-CLR-011)

The catalogue index pages SHALL let a user select several entries and delete them in one action, after a confirmation that names how many entries go.

#### Scenario: An information manager deletes three entries at once
@e2e tests/e2e/workflows/bulk-actions.spec.ts

- **GIVEN** three selected applications on the Applications page
- **WHEN** an information manager chooses Delete and confirms
- **THEN** the three applications SHALL be gone from the list

### Requirement: A new organisation signs itself up on a public form (REQ-CLR-019)

Stackiq SHALL offer a public sign-up form that posts to the intake endpoint without an account. The registration SHALL land in the moderation queue as pending. The organisation schema SHALL NOT grant public create outside the intake.

#### Scenario: A supplier signs up without an account
@e2e tests/e2e/workflows/self-registration.spec.ts

- **GIVEN** a visitor who is not signed in
- **WHEN** they fill in the sign-up form for Leverancier B.V. and send it
- **THEN** Leverancier B.V. SHALL appear as pending in the admin's moderation queue

#### Scenario: A generic create cannot skip the intake
@e2e exclude An authorisation rule held by OpenRegister; tests/Unit/Settings/OrganisationCreateRuleTest.php asserts the organisation schema grants no public create.

- **GIVEN** an anonymous caller
- **WHEN** it creates an organisation through the OpenRegister objects API
- **THEN** the create SHALL be refused
