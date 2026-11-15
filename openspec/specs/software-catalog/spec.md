# software-catalog Specification

## Purpose
Stackiq's catalogue schemas share an OpenRegister instance with other apps, and a few of them first used a bare word that another app already owns. This spec fixes the catalogue's own names where they collided: the contract schema is `catalogContract`, a facet that points at the shillinq contract that owns the lifecycle, and the dienst schema is `catalogService`. It also says how existing installs reach those names through repair steps that keep each schema id, and which identifiers stay as they are because they are property names, config keys or another app's enum values.

## Requirements

### Requirement: The catalogue contract points at the shillinq contract (REQ-SC-060)

The catalogue contract schema's slug SHALL be `catalogContract` and SHALL NOT be
`contract`.

Three apps declared a `contract` and all three carry `contractNumber`, so they
describe one contract from three sides. shillinq owns the lifecycle (ADR-066).

The schema SHALL carry a `contract` property holding the UUID of the shillinq
`Contract`. It SHALL be a plain uuid string and SHALL NOT be a `$ref`, because
shillinq's register is a different register and ADR-062 rule 7 gives a
cross-register target no `$ref`.

The object type SHALL move with the slug everywhere it is named: the register's
schema list, the register table configuration, the generic modal type list, the
settings type lists, and `tests/e2e/ci-seed.sh`. The seed list is checked after
the import and exits before Playwright, so a missed slug there reports every
spec as not run rather than as a failure.

The config key SHALL remain `contract_schema`, pinned through
`SettingsService::LEGACY_SCHEMA_KEY`. The default `<type>_schema` rule would
otherwise look for `catalogContract_schema` and resolve nothing.

`ContractApprovalService::DECISION_TYPE_APPROVAL` SHALL remain `contract`. It is
decidiq's `Decision.decisionType` enum value, not a schema slug.

#### Scenario: Every catalogue type still resolves

- **WHEN** each catalogue object type is resolved to a schema and a register
- **THEN** `catalogContract` resolves to both, through the pinned legacy key.

#### Scenario: The catalogue facet points at its owner

- **WHEN** the register JSON is read
- **THEN** `catalogContract` carries a `contract` property targeting shillinq.

### Requirement: The catalogue service is namespaced (REQ-SC-061)

The catalogue dienst schema's slug SHALL be `catalogService` and SHALL NOT be
`service`. shillinq keeps the bare slug; pipelinq uses `appointmentService`.

The rename SHALL be carried by `RenameCollidingSchemaSlugs` and SHALL NOT be
folded into `RenameDutchSchemaSlugs`. That map's planner forbids two sources
targeting one name, and both `dienst` and `service` would have to point at
`catalogService`. The Dutch pass SHALL run first and land on `service`; the
colliding pass SHALL then move it.

Every `$ref` targeting the schema SHALL follow the rename. A `$ref` left on the
old name points at a schema that no longer exists.

The rename SHALL NOT touch `service` where it is a property name, including the
`via` key in the portal contribution provider and the `required` entry on
`catalogContract`.

#### Scenario: The Dutch pass and the colliding pass compose

- **GIVEN** an install carrying `dienst`
- **WHEN** the repair steps run in order
- **THEN** the row ends on `catalogService`, keeping its schema id throughout.

#### Scenario: An install already on the colliding slug is moved

- **GIVEN** an install carrying `service` under this app
- **WHEN** the colliding pass runs
- **THEN** the row is renamed to `catalogService`.

#### Scenario: The via property is still a property

- **WHEN** the vendor contract collection is read
- **THEN** its `via` is `service`, the property on `catalogContract`, and that
  property's `$ref` resolves to `catalogService`.
