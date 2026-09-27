# Design: sharing-itsm-exchange

Read at development `9a5ece6a`, OpenRegister development `4fee776`, integriq development `413357e`.

## Context

Stackiq's outside connections are declared in `lib/Settings/connections.json` and shown by integriq's connection registry. Outside calls and their credentials belong to integriq (ADR-091, ADR-064); stackiq never holds a service desk token. Integriq synchronises through OpenRegister flows made of steps (fetch page, map, contract, save), and its `SourceCallNode` calls a configured source from a flow (integriq `lib/Flow/SourceCallNode.php`). Stackiq authors flows on its own Flows page (`src/manifest.json:1057`) and its store accepts `openregister.flows` configuration sets (`src/manifest.json`, `store.types`).

## D1. External references on the usage

`lib/Settings/register.d/itsm-exchange.json` adds to `usage`: `externalReferences`, an array of objects `{ system: string, recordId: string, url: string (uri), syncedAt: date-time }`, visible on the page, `hideOnForm: true` (written by the inbound flow, not typed by hand), and a derived `serviceDeskUrl` for the list column.

The usage is the right object: a service desk's application record describes the application as this organisation runs it, with its own version and owners, not the supplier's product.

## D2. The itsm connection

A fourth entry in `lib/Settings/connections.json`: `key: itsm`, title "Service desk", `reportedOnly: true`, a `switch` on a new app setting `itsm_exchange_enabled`, and `sourceTemplate` naming integriq's service desk templates once integriq publishes them. The flows report their outcome through `ConnectionReportService` (`lib/Service/ConnectionReportService.php`, from `adopt-connection-registry`), so the Integrations page shows the last run.

## D3. Two flow templates and a set-up action

Two flow templates ship in `lib/Settings/flows/` (`itsm-outbound.json`, `itsm-inbound.json`), with three mapping presets (TOPdesk assets, ServiceNow CMDB CIs, GLPI appliances):

- **Outbound**: trigger `object.updated` and `object.created` on `usage` (OpenRegister `TriggerObjectNode`), a map step that builds the service desk payload (application name, supplier, version, lifecycle status, business owner, technical owner, BBN level), and integriq's source call. A `recordId` in the answer is written back to `externalReferences`.
- **Inbound**: trigger on a nightly schedule, integriq's fetch page over the service desk's application records, a match step on `recordId`, else on name and supplier, and a save step that updates `externalReferences` and `syncedAt`. Unmatched records are listed in the flow run.

A "Set up service desk exchange" action in the admin settings (a new section `section-itsm`, the anchor the connection entry links to) asks for the integriq source and the mapping preset, fills them into the templates, validates them with OpenRegister (`POST /apps/openregister/api/flow/validate`, openregister `appinfo/routes.php:803`) and creates the flows (`POST /api/flows`, :861), scoped to stackiq so they appear on its Flows page. Controller `lib/Controller/ItsmExchangeController.php`, service `lib/Service/ItsmExchangeService.php`, admin only (`#[AuthorizedAdminSetting]`).

Rejected: a stackiq PHP client per service desk. It would hold credentials and outside calls in stackiq, which ADR-091 moves to integriq, and it would duplicate integriq's synchronisation. Rejected too: a Store configuration set, because stackiq's Store lists sets from publishers' sources, and this exchange needs the administrator's own source filled in before it can run.

## D4. Pages

The usage page shows the service desk link from `externalReferences` in its data widget, and Applications in use (`src/manifest.d/usages.json`) gets a Service desk column that opens the record in a new tab.

## Declarative versus imperative

Declarative: fields, the connection entry, and the flows and mappings as data run by OpenRegister and integriq (ADR-031, ADR-065). The set-up action only fills in and creates the flows; no stackiq code calls outside.

## Seed data

None; the flows are created on purpose by the set-up action.

## Risks

- Integriq's service desk source templates do not exist yet (its connector catalogue lists ServiceNow among planned categories). Until they do, the administrator configures a generic REST source in integriq, and the flows work against it.
