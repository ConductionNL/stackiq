# itsm-exchange specification

**Status**: proposed
**Scope**: stackiq
**OpenSpec changes**:
- sharing-itsm-exchange

## Purpose

Stackiq is the CMDB for the application level: applications, components, connections, licences and contracts. It stays in step with the organisation's service desk (TOPdesk, ServiceNow) in both directions through integriq, with per-field ownership deciding every conflict. Matrix row `stackiq:share-itsm-integration`.

## ADDED Requirements

### Requirement: REQ-ITX-001 An administrator sets up the exchange without stackiq holding a credential

Stackiq SHALL let a Nextcloud admin set up the exchange from the admin settings by choosing the service desk (TOPdesk or ServiceNow) and an integriq source. The set-up SHALL create the inbound and outbound flows from stackiq's templates, validate every flow with OpenRegister before saving any, and save, publish and enable them only when all are valid. When one is invalid it SHALL create nothing and SHALL name the node and the reason. Running it again SHALL update the flows it created instead of adding new ones. Stackiq SHALL NOT store or send the service desk credentials.

#### Scenario: Set up against the TOPdesk source
@e2e exclude Admin settings set-up runs against integriq's source and OpenRegister's flow store; verified by tests/Unit/Service/ItsmExchangeServiceTest.php and the live run on the test instance recorded in the PR.

- **GIVEN** integriq holds a TOPdesk source with its credential
- **WHEN** the admin chooses TOPdesk and that source and starts the set-up
- **THEN** the inbound flows and the outbound flow exist, published and enabled, on stackiq's Flows page
- **AND** starting the set-up again leaves the same number of flows

#### Scenario: A missing mapping preset stops the set-up
@e2e exclude Exercised by tests/Unit/Service/ItsmExchangeServiceTest.php with OpenRegister's preflight answering blocking.

- **GIVEN** integriq has no ServiceNow mapping preset
- **WHEN** the admin sets up with ServiceNow
- **THEN** no flow is created
- **AND** the answer names the node and the reason preflight gave

### Requirement: REQ-ITX-002 The import creates and updates stackiq records and never duplicates them

The inbound flows SHALL read the service desk's application records, relations, licences and contracts on a schedule and create or update the matching stackiq records: the supplier as an organisation, the application as a module, the organisation's use of it as a usage, relations as connections, and licences and contracts as contracts. A record SHALL be matched by its service desk record id first and by name and supplier second. A second run over the same records SHALL update, not create.

#### Scenario: A first import creates, a second updates
@e2e exclude Server-side flow runs against the TOPdesk mock; recorded in the PR's live run.

- **GIVEN** the TOPdesk mock holds three applications, one relation and one licence contract
- **WHEN** the inbound flows run
- **THEN** stackiq holds three usages with their modules and supplier, one connection and one contract, each with its service desk record id
- **WHEN** the inbound flows run again
- **THEN** the number of usages, modules, connections and contracts is unchanged

#### Scenario: An application already in the catalogue is reused
@e2e exclude Server-side flow run; recorded in the PR's live run.

- **GIVEN** the catalogue holds module "Zaaksysteem X" from supplier "Leverancier B"
- **WHEN** the import reads a desk record named "Zaaksysteem X" from "Leverancier B"
- **THEN** the new usage points at that module and no second module is created

### Requirement: REQ-ITX-003 The owner of a field wins

Every mapped field SHALL have an owner, the service desk or stackiq, declared in the mapping preset. An import SHALL write service-desk-owned fields on an existing record and SHALL NOT change a stackiq-owned field. An export SHALL send stackiq-owned fields for a record the service desk already knows and SHALL NOT send a service-desk-owned field. A record created by either side SHALL get every mapped field. Ownership SHALL NOT be decided by timestamps.

#### Scenario: Both sides changed, each keeps its own
@e2e exclude Server-side flows against the mock; recorded in the PR's live run.

- **GIVEN** an imported usage
- **WHEN** the desk renames the application and someone in stackiq changes the business owner, before the next run
- **THEN** after the import and the export, stackiq shows the desk's new name and the desk shows stackiq's business owner

#### Scenario: A licence edited in stackiq survives the import
@e2e exclude Server-side flow run; recorded in the PR's live run.

- **GIVEN** a contract created by the import with 100 licences bought
- **WHEN** stackiq changes it to 120 and the desk still says 100
- **THEN** after the next import the contract says 120

### Requirement: REQ-ITX-004 A write never echoes back

A change written by the import SHALL NOT cause an export call, and a change written by the export SHALL NOT cause an import write. Writing the returned record id after a create SHALL NOT cause a second export call.

#### Scenario: One change, one call
@e2e exclude Counts calls on the mock; recorded in the PR's live run.

- **GIVEN** the exchange is set up and the import has run
- **WHEN** someone changes the technical owner of one usage
- **THEN** the mock receives exactly one update call for that record
- **AND** the next import writes nothing
- **AND** the mock receives no further call

### Requirement: REQ-ITX-005 Licences and contracts carry what a CMDB needs

A contract SHALL record the licence metric, licences bought and in use, start and end, cost with its period and currency, the supplier, the supplier's own reference, and the service desk reference. A contract SHALL NOT require a catalogue service. Contracts, licences and costs SHALL NOT be public.

#### Scenario: A licence for an application without a service
@e2e exclude Register fragment; verified by tests/Unit/Settings/ItsmExchangeFragmentTest.php.

- **GIVEN** the merged register
- **WHEN** a contract is created with a usage, type Licence, 50 licences bought per named user, EUR 12000 a year and no service
- **THEN** it validates against the contract schema

### Requirement: REQ-ITX-006 A file feeds the same import

An administrator SHALL be able to import applications from a CSV or XLSX file whose columns are stackiq's field names. The file SHALL run through the same flow, mapping and matching as the service desk import, and importing the same file twice SHALL update rather than duplicate. A row without a record id SHALL be refused with its row number.

#### Scenario: Import a spreadsheet twice
@e2e exclude Upload runs the server-side flow; verified by tests/Unit/Service/ItsmFileImportServiceTest.php and the live run in the PR.

- **GIVEN** a CSV with two applications
- **WHEN** the admin imports it twice
- **THEN** stackiq holds two usages from it, not four

### Requirement: REQ-ITX-007 The CMDB page says what stackiq is

Stackiq SHALL have a CMDB page that names what it records (applications, components, connections, licences and contracts) and what it does not (hardware, network discovery, tickets), links to each list, shows the service desk exchange and the outcome of its last run, and offers the file import to admins. A usage SHALL show its service desk link, and Applications in use SHALL have a Service desk column. The Integrations page SHALL list the service desk exchange.

#### Scenario: An information manager opens the CMDB page
@e2e tests/e2e/workflows/itsm-exchange.spec.ts

- **GIVEN** a signed-in admin
- **WHEN** they open the CMDB page
- **THEN** it lists applications, components, connections, licences and contracts with links
- **AND** it says stackiq does not discover hardware

#### Scenario: A service desk employee finds the catalogue entry and back
@e2e tests/e2e/workflows/itsm-exchange.spec.ts

- **GIVEN** a usage linked to service desk record A-123
- **WHEN** the information manager opens Applications in use
- **THEN** the Service desk column shows A-123 and opens the record in the service desk
