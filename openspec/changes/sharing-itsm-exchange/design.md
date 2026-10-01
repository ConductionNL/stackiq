# Design: sharing-itsm-exchange

Read at stackiq development `f262512c`, OpenRegister development `9a4e28e9a9`, integriq development `c67abca0c`. Amended 2026-10-01 for the Rotterdam programme (CMDB import, two-way sync, licences and contracts, file import). The first version of this design (one outbound flow, one inbound flow that only linked existing usages) is replaced by the one below.

## Context

Outside calls and their credentials belong to integriq (ADR-091, ADR-064); stackiq never holds a service desk token. OpenRegister runs flows; integriq adds the nodes that talk to a source and keep track of what was synchronised. Stackiq ships flow templates and a set-up action, and owns the fields.

The building blocks, by who ships them:

| Block | Owner | Key |
|---|---|---|
| Object trigger, schedule trigger, manual trigger | OpenRegister | `openregister.trigger-object`, `openregister.trigger-schedule`, `openregister.trigger-manual` |
| Read objects, write with upsert on a `match` list (patch by default) | OpenRegister | `openregister.object-read`, `openregister.object-write` (`ObjectWriteNode.php:456`) |
| Split a list, compute fields, branch | OpenRegister | `openregister.explode`, `openregister.set-fields`, `openregister.switch` |
| Read pages from a source | integriq | `openconnector.source-paginate` (keyed on a synchronization) |
| Map a record with a stored mapping | integriq | `openconnector.apply-mapping` |
| Remember what was synchronised, decide create, update or skip | integriq | `openconnector.contract`, `openconnector.contract-commit` |
| Call the source | integriq | `openconnector.source-call` |
| Per-field ownership on a mapping, enforced on update | integriq (lane iq, `connectors-service-desk-templates`) | mapping `ownership`, `apply-mapping` `ownership` and `exists` |

## D1. Fields

`lib/Settings/register.d/sharing-itsm-exchange.json` (ADR-037):

- On `usage`, `connection` and `catalogContract`: `serviceDeskSystem` (topdesk, servicenow, file), `serviceDeskRecordId`, `serviceDeskUrl` (uri) and `serviceDeskSyncedAt` (date-time). Shown on the page, hidden on the form: the import writes them.
- On `usage`: `installedVersion` (the version the service desk records, a string; `moduleVersion` stays the catalogue reference) and `publicationDate` (stackiq-owned; lane oc publishes a usage only when it is set and in the past).
- On `catalogContract`: `vendorReference` (the supplier's contract or agreement number), `currency` (ISO 4217, default EUR) and `supplier` (the organisation that sold it). `licenceMetric`, `licencesBought` and `licencesInUse` already come from `contracts-licence-seats.json`; start, end, cost and cost period are on the base schema.

The reference is flat, not a list of `{system, recordId, url, syncedAt}` as the first version had it. `object-write` matches on a property and `object-read` filters on one; neither reaches into a list of objects. An organisation runs one service desk, so one reference per record is enough. Integriq's contract keeps the full origin record and its hash.

The base register changes too, because a fragment can only append to a list (`SettingsService::deepMergeConfig`): `catalogContract.required` drops `service`, and the property loses `required: true`. A licence bought for an application has no catalogue service. Versions: `usage` 1.5.4, `connection` 0.3.4, `catalogContract` 0.1.4, register 2.5.7. `value-assessment.json` also declares the `usage` version and sorts after this fragment, so its version moves to 1.5.4 with it. Otherwise the last fragment wins and the new fields never deploy.

## D2. Field ownership

Integriq's mapping presets carry `ownership: {<output field>: "source" | "stackiq"}`, one entry per mapped field (lane iq, `connectors-service-desk-templates`). `openconnector.apply-mapping` with `ownership: "inbound"` and `exists: <path>` keeps, on an update, only the fields the service desk owns; with `"outbound"` it keeps only the fields stackiq owns. On a create it keeps every field.

The split for the application level:

| Field (stackiq side) | Owner |
|---|---|
| record id, record link, name, supplier, installed version, status, description | service desk |
| business owner, technical owner, BBN level, TIME class, publication date | stackiq |
| every licence and contract field (number, vendor reference, type, start, end, cost, cost period, currency, metric, licences bought) | stackiq |
| relation: both ends, name, type, direction | service desk |

Licences and contracts are stackiq-owned but the service desk is where many organisations first recorded them. So the import creates a contract it does not yet know, with every field filled, and after that only refreshes the service desk reference. That is the same rule: create keeps every field, update keeps only what the service desk owns.

## D3. Inbound flows: create and update, never duplicate

One template per feed: `lib/Settings/flows/itsm-inbound-applications.json`, `itsm-inbound-relations.json`, `itsm-inbound-contracts.json` (licences and contracts share it, each with its own preset and synchronization). Applications run as:

1. `trigger-schedule`, nightly (`0 2 * * *`), run as the administrator who set it up.
2. `source-paginate` on the feed's synchronization, `explode` the page into `source`.
3. `apply-mapping` with the inbound preset, output `record`: every field, used on create.
4. `apply-mapping` with the same preset, `ownership: inbound`, `exists: record.recordId`, output `owned`. `record.recordId` is always set, so this keeps only the service-desk-owned fields.
5. `contract` with `idPosition: owned.recordId` and `hashPosition: owned`. Unchanged service-desk fields give `skip`.
6. `switch` on the outcome. `skip` ends.
7. Supplier: `object-write` upsert on `organization`, match `name` and `type: Supplier`.
8. Application: `object-write` upsert on `module`, match `name` and `provider`.
9. Usage: on `create`, `object-write` upsert on `usage` matching `serviceDeskRecordId`, then `module` and `consumer`, with every field of `record`. On `update`, `object-write` update matching `@self.uuid` on the contract's target id, with only `owned`.
10. `contract-commit` with the written usage's uuid.

That is the matching order Ruben asked for: the service desk reference first (the contract, and `serviceDeskRecordId` on the usage), then name and supplier (steps 7 and 8). Spelling variants that miss an exact match are created, and then surface as duplicate candidates through `x-openregister-dedup` on `module`, which `operations-record-reconciliation` links to and merges through OpenRegister's merge engine. Stackiq does not run its own fuzzy match.

Relations read the desk's relation records, look up both ends by `serviceDeskRecordId` on `usage`, and upsert a `connection` (match `serviceDeskRecordId`) between the two modules. A relation whose end is not imported yet waits for the next run and is listed in the run.

Licences and contracts look up the usage by `applicationRecordId`, upsert `catalogContract` (match `serviceDeskRecordId`) with every field on create and only the service desk reference on update, and link `usage` and `supplier`.

## D4. Outbound flow: only what stackiq owns

`lib/Settings/flows/itsm-outbound-applications.json`:

1. `trigger-object` on `usage`, `object.created` and `object.updated` (two trigger nodes, one flow).
2. `object-read` the usage's module, supplier, owners and its first active contract.
3. `set-fields` builds `usage` in the shape the outbound preset reads (D2 field names, `catalogueUrl`).
4. `apply-mapping` with the outbound preset, `ownership: outbound`, `exists: usage.recordId`, output `send`. A usage the desk does not know yet sends every field; a known one sends only stackiq-owned fields.
5. `set-fields` builds `stackiqOwned`: only the stackiq-owned source fields of `usage` (owners, BBN level, TIME class, licence and contract fields).
6. `contract` on the outbound synchronization with `idPosition: usage.uuid` and `hashPosition: stackiqOwned`. Unchanged stackiq-owned fields give `skip`, and nothing is sent.
7. `switch` on `usage.recordId`: empty means `source-call` POST to the desk's create endpoint, then `object-write` on the usage with the returned record id and link. Set means `source-call` PATCH or PUT to the record.
8. `contract-commit`.

## D5. Why there is no ping-pong

The two directions write disjoint field sets, and each skips on a hash of only its own set.

- **Inbound change, then outbound.** The import writes only service-desk-owned fields on an existing usage. That fires `object.updated`, and the outbound flow runs. Its hash covers only stackiq-owned fields, which did not change, so `contract` says `skip` and no call goes out.
- **Outbound change, then inbound.** The export changes only stackiq-owned fields on the desk record. The next import maps that record, keeps only service-desk-owned fields for its hash, and they did not change, so `contract` says `skip` and nothing is written.
- **The record id written back after a create.** It fires `object.updated`. The id is not in the stackiq-owned hash, so the outbound flow skips.

OpenRegister has no loop guard on object triggers (`lifecycle-auto-transitions/design.md:43` in OpenRegister), so the guard has to come from the data, and it does. The live test asserts that one stackiq change makes exactly one call to the mock, and that the next import writes nothing.

## D6. File import

For organisations without a service desk API: an administrator uploads a CSV or XLSX on the CMDB page. `ItsmFileImportService` reads the rows with PhpSpreadsheet (shipped by OpenRegister) and starts the file import flow once, with the rows as the run's payload (`FlowService::run`, `context.payload`). That flow is the inbound applications flow with a manual trigger and `explode` over `rows` in place of `source-paginate`, the preset `itsm-file-application-inbound` (column names are the stackiq field names, every field owned by the file), and its own synchronization so a second upload of the same file updates rather than duplicates. The column `recordId` is the key; a row without one is refused with its row number.

## D7. The itsm connection and the set-up action

A fourth entry in `lib/Settings/connections.json`: `key: itsm`, title "Service desk", `reportedOnly: true`, a `switch` on app setting `itsm_exchange_enabled`, `settingsUrl: /settings/admin/stackiq#section-itsm`. No `sourceTemplate`: the administrator picks TOPdesk or ServiceNow, so one fixed template would be wrong for half of them.

`lib/Service/ItsmExchangeService.php` and `lib/Controller/ItsmExchangeController.php`, admin only (`#[AuthorizedAdminSetting]`):

- `GET /api/itsm/status`: the desk, the source, the flows with their last run, and the file import.
- `POST /api/itsm/setup` with `desk` (topdesk, servicenow) and `source` (an integriq source uuid or slug): looks the source up in integriq's register, creates the synchronizations for each feed in integriq's register, fills the templates (placeholders `%SOURCE%`, `%SYNC_*%`, `%PRESET_*%`, `%RUN_AS%` and the desk profile's endpoints), validates every flow with OpenRegister's `FlowNodePreflight::inspect()`, and only when all are valid saves them with `FlowService::save()`, publishes them and enables them. If one is invalid, nothing is created, and the answer names the node and the reason. Running it again updates the flows it created (their uuids are kept in app setting `itsm_exchange`).
- `POST /api/itsm/import`: the file import (D6).

The desk profiles (create and update endpoints, the response path of the new record id, the record link pattern) are data in the service, one per desk. They are not a client: every call goes through `openconnector.source-call`.

## D8. The CMDB page

A page at `/cmdb`, menu entry "CMDB" under Applications. It says what stackiq records (applications, their components, connections, licences and contracts) and what it does not (hardware, network discovery, tickets), links to each list, shows the service desk exchange with its last run, and takes a file import. Applications in use gets a Service desk column. English and Dutch, written with the `writing` skill.

## Declarative versus imperative

Declarative: fields, the connection entry, the flows and the mapping presets, run by OpenRegister and integriq (ADR-031, ADR-065). Imperative, and only because it needs the administrator's choice: the set-up action that fills in and creates the flows, and the file import that reads the file and starts the flow.

## Seed data

None. The flows are created on purpose by the set-up action.

## Related changes

- `operations-record-reconciliation`: owns duplicate candidates and the merge. This change creates near-duplicates on purpose and relies on it.
- `operations-sync-status-and-progress`: owns stackiq's own organisation sync. Outside synchronisation runs belong to integriq and show on the Flows page and the Integrations page, so this change does not add a run log of its own.
- `operations-technology-components`: owns servers and other infrastructure configuration items. An import of those from a service desk is a follow-up on that change, through the same flow pattern.
- `landscape-application-components`: `module.partOf`. A desk relation of type "part of" maps to it in a follow-up; v1 imports relations as connections.
- `contracts-expiry-and-owner`: the contract status and responsible user. The import writes neither; the daily contract job keeps owning the status.

## Risks

- Until lane iq ships the presets and the ownership keys, preflight refuses the flows and the set-up action says so. That is a real dependency, listed in the tasks.
- OpenRegister fires `object.updated` on a patch that changes nothing. D5 makes that harmless; it still costs a flow run per imported record.
- A desk whose record id is not unique across feeds (relations and applications in one table) needs the system prefix. The presets map the record id as the desk returns it; the contract is per synchronization, so ids from different feeds never meet.
