# licence-seats specification

**Status**: proposed
**Scope**: stackiq
**OpenSpec changes**:
- contracts-licence-seats

## Purpose

An application owner records how a licence contract is measured and how many licences were bought and are in use. A municipal information manager sees on the contract and on the License posture page where use runs over what was bought.

## ADDED Requirements

### Requirement: REQ-LSC-001 A contract SHALL record its licence metric and the number of licences bought and in use

`catalogContract` SHALL carry `licenceMetric` with the values Per named user, Per concurrent user, Per device, Per inhabitant, Per organisation and Other, and the integers `licencesBought` and `licencesInUse`, each with a minimum of 0. All three SHALL be optional and editable in the contract form on `ContractDetail`.

#### Scenario: An application owner records a user licence
@e2e tests/e2e/spec-coverage/licence-seats.spec.ts

- **GIVEN** an application owner on `/contracten/:id` for a Licence contract
- **WHEN** they edit the contract, choose Per named user, enter 400 bought and 460 in use, and save
- **THEN** the contract detail SHALL show Per named user, 400 and 460

#### Scenario: A negative count is refused
@e2e exclude Schema validation is OpenRegister's; tests/Unit/Settings/LicenceSeatsDeclarationTest.php asserts minimum 0 and integer type on both counts.

- **GIVEN** the contract form
- **WHEN** an application owner enters -5 licences bought and saves
- **THEN** the save SHALL fail with a validation message on that field
- **AND** the stored contract SHALL keep its previous value

### Requirement: REQ-LSC-002 The contract detail page MUST show licences in use against licences bought

`ContractDetail` SHALL render a seats panel that shows in use against bought as a bar and as numbers, with the state Within licence, Over licence by N, or Unknown when a count is empty. For the metrics Per organisation and Other the panel SHALL show Not counted and no bar. The panel SHALL show the date the contract was last changed.

#### Scenario: Use over the licence is flagged
@e2e tests/e2e/spec-coverage/licence-seats.spec.ts

- **GIVEN** a contract with Per named user, 400 bought and 460 in use
- **WHEN** a municipal information manager opens `/contracten/:id`
- **THEN** the seats panel SHALL read Over licence by 60
- **AND** the bar SHALL use the error variant

#### Scenario: A site licence is not counted
@e2e exclude Covered by tests/vitest/licensePosture.spec.js, which asserts seatPosition() returns not-counted for Per organisation and Other.

- **GIVEN** a contract with Per organisation and 1 bought
- **WHEN** the seats panel renders
- **THEN** it SHALL read Not counted and draw no bar

### Requirement: REQ-LSC-003 The License posture page SHALL list every counted licence contract with its seat state, over-use first

The `LicensePosture` page at `/license-posture` SHALL have a Seats section with one row per contract whose metric is counted and whose `licencesBought` is set. Each row SHALL name the application, the organisation, the metric, bought, in use and the state. Rows over the licence SHALL come first, ordered by how far over they are. The section SHALL respect the reader's read rights on `catalogContract`, because it reads through the same OpenRegister collection as the rest of the page.

#### Scenario: An information manager finds the contracts over their licence
@e2e tests/e2e/spec-coverage/licence-seats.spec.ts

- **GIVEN** one contract 60 over its licence and one contract within its licence
- **WHEN** a municipal information manager opens `/license-posture`
- **THEN** the Seats section SHALL list the over-licence contract first with Over licence by 60
- **AND** it SHALL list the other contract as Within licence

#### Scenario: Contracts without counts stay out of the section
@e2e exclude Covered by tests/vitest/licensePosture.spec.js, which asserts seatRows() skips contracts without licencesBought or with an uncounted metric.

- **GIVEN** an SLA contract without any licence fields
- **WHEN** the Seats section renders
- **THEN** that contract SHALL NOT appear
