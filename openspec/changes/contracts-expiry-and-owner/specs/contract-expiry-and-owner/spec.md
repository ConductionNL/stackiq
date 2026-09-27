# contract-expiry-and-owner specification

**Status**: proposed
**Scope**: stackiq
**OpenSpec changes**:
- contracts-expiry-and-owner

## Purpose

A municipal information manager sees a contract move from Active to Expiring to Expired without anyone touching it, and every contract names one Nextcloud user who is responsible for it. The expiry warning that OpenRegister sends for a contract reaches that person as well as the catalogue administrators.

## ADDED Requirements

### Requirement: REQ-CEO-001 The contract status MUST include Expiring, set by the daily contract job inside the notice window

`catalogContract.status` SHALL accept `Expiring` next to Active, Expired and In negotiation. Once a day `ContractStatusJob` SHALL move an Active contract to Expiring when its `endDate` is today or later and at most `contract_expiry_window_days` days away. The window SHALL default to 90 days when the setting is empty or not a positive number.

#### Scenario: An active contract inside the window becomes expiring
@e2e exclude The job runs on the Nextcloud cron, not in a browser; tests/Unit/Service/ContractStatusServiceTest.php covers shouldStartExpiring() and the pass.

- **GIVEN** an Active contract whose `endDate` is 30 days from now and `contract_expiry_window_days` is 90
- **WHEN** `ContractStatusJob` runs
- **THEN** the contract's `status` SHALL be `Expiring`
- **AND** the job's log line SHALL count it among the contracts that started expiring

#### Scenario: An active contract outside the window stays active
@e2e exclude Covered by tests/Unit/Service/ContractStatusServiceTest.php, which asserts shouldStartExpiring() is false beyond the window.

- **GIVEN** an Active contract whose `endDate` is 200 days from now and the window is 90 days
- **WHEN** `ContractStatusJob` runs
- **THEN** the contract's `status` SHALL still be `Active`

#### Scenario: A contract in negotiation is never touched
@e2e exclude Covered by tests/Unit/Service/ContractStatusServiceTest.php for every move.

- **GIVEN** a contract In negotiation whose `endDate` is 10 days from now
- **WHEN** `ContractStatusJob` runs
- **THEN** the contract's `status` SHALL still be `In negotiation`

### Requirement: REQ-CEO-002 The daily job SHALL expire an expiring contract after its end date and SHALL return it to active when its end date moves past the window

`ContractStatusJob` SHALL move an Active or Expiring contract whose `endDate` is before today to Expired. It SHALL move an Expiring contract whose `endDate` is now more than the window away back to Active. It SHALL NOT move a contract out of Expired, and it SHALL skip a contract without a parseable `endDate`.

#### Scenario: An expiring contract expires after its end date
@e2e exclude The job runs on the Nextcloud cron; tests/Unit/Service/ContractStatusServiceTest.php covers shouldExpire() for both source states.

- **GIVEN** an Expiring contract whose `endDate` was yesterday
- **WHEN** `ContractStatusJob` runs
- **THEN** the contract's `status` SHALL be `Expired`

#### Scenario: An extended contract returns to active
@e2e exclude Covered by tests/Unit/Service/ContractStatusServiceTest.php, which asserts shouldReturnToActive().

- **GIVEN** an Expiring contract, and an application owner has changed its `endDate` to two years from now
- **WHEN** `ContractStatusJob` runs
- **THEN** the contract's `status` SHALL be `Active`

#### Scenario: The lifecycle names the states the rows hold
@e2e exclude A register declaration; tests/Unit/Settings/ContractLifecycleDeclarationTest.php asserts every lifecycle state is an enum value and the schema version moved up.

- **GIVEN** `lib/Settings/softwarecatalogus_register.json`
- **WHEN** the `catalogContract` lifecycle block is read
- **THEN** every state in `initial`, `final`, `from` and `to` SHALL be a value of the `status` enum
- **AND** a transition SHALL exist from Active to Expiring, from Expiring to Active, and from Expiring to Expired

### Requirement: REQ-CEO-003 The Contracts page MUST let a user filter on expiring and on expired contracts separately

The `Contracten` page at `/contracten` SHALL offer an Expiring quick filter on `status` equal to `Expiring` and an Expired quick filter on `status` equal to `Expired`, in place of the single "Expiring / expired" filter. Both labels SHALL exist in English and Dutch.

#### Scenario: An information manager lists the contracts about to expire
@e2e tests/e2e/spec-coverage/contract-expiry-and-owner.spec.ts

- **GIVEN** a municipal information manager, and the catalogue holds one Expiring and one Expired contract
- **WHEN** they open `/contracten` and choose the Expiring quick filter
- **THEN** the list SHALL show the Expiring contract
- **AND** it SHALL NOT show the Expired contract

#### Scenario: The status column shows the expiring state
@e2e tests/e2e/spec-coverage/contract-expiry-and-owner.spec.ts

- **GIVEN** an Expiring contract
- **WHEN** a municipal information manager opens `/contracten`
- **THEN** its status cell SHALL read Expiring, or Verlopend on a Dutch instance

### Requirement: REQ-CEO-004 A contract SHALL name one responsible Nextcloud user, picked from the users of the instance

`catalogContract` SHALL carry `responsibleUser`, a string holding a Nextcloud user id with `referenceType` `nextcloud-user`. The contract form SHALL offer it as a user picker. The `ContractDetail` page SHALL show it, and the `Contracten` page SHALL show it as a column.

#### Scenario: An application owner names the responsible person
@e2e tests/e2e/spec-coverage/contract-expiry-and-owner.spec.ts

- **GIVEN** an application owner editing a contract on `/contracten/:id`
- **WHEN** they open the edit form, pick a colleague in the Responsible user field and save
- **THEN** the contract detail SHALL show that colleague as the responsible user
- **AND** the Contracts page SHALL show the colleague in the Responsible user column

#### Scenario: A free-text value is not accepted
@e2e exclude The picker only offers existing users; tests/vitest/contractResponsibleUser.spec.js asserts the schema property resolves to the user widget.

- **GIVEN** the contract form
- **WHEN** an application owner types a name that matches no Nextcloud user
- **THEN** the form SHALL offer no value to pick
- **AND** `responsibleUser` SHALL stay empty

### Requirement: REQ-CEO-005 The contract expiry warning MUST include the responsible user among its recipients

The `contract-expiry` rule in `x-openregister-notifications` on `catalogContract` SHALL list a recipient of kind `field` reading `responsibleUser`, next to the existing `software-catalog-admins` group and manage rights recipients. When the rule fires for a contract, OpenRegister SHALL resolve that recipient to the named user.

#### Scenario: The declaration names the responsible user as a recipient
@e2e exclude A register declaration; tests/Unit/Settings/ContractLifecycleDeclarationTest.php asserts the field recipient on the contract-expiry rule and that the field exists on the schema.

- **GIVEN** `lib/Settings/softwarecatalogus_register.json`
- **WHEN** the `contract-expiry` rule on `catalogContract` is read
- **THEN** its `recipients` SHALL contain `{"kind": "field", "field": "responsibleUser"}`
- **AND** `responsibleUser` SHALL be a property of `catalogContract`

#### Scenario: The responsible person receives the warning
@e2e exclude Delivery needs the rule fix of stackiq:ctr-expiry-alert and OpenRegister's scheduled notification job; OpenRegister's NotificationRecipientResolver tests cover the field kind, and this app asserts only the declaration.

- **GIVEN** an Expiring contract whose `responsibleUser` is a Nextcloud user, and the `contract-expiry` rule fires for it
- **WHEN** OpenRegister resolves the recipients
- **THEN** the responsible user SHALL receive the warning in Nextcloud notifications
- **AND** the members of `software-catalog-admins` SHALL still receive it
