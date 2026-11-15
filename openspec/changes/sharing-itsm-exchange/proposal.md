---
kind: code
depends_on:
  - landscape-usage-registration
---

# Keep the application landscape in step with the service desk, as a CMDB

## Summary

Stackiq becomes the configuration management database (CMDB) for the application level: the applications an organisation uses, the components they are made of, the connections between them, and the licences and contracts behind them. A functional administrator connects stackiq to the organisation's service desk, TOPdesk or ServiceNow, through integriq. From then on the two stay in step in both directions:

- **Import**: a nightly run reads the service desk's application records, relations, licences and contracts, and creates or updates the matching stackiq records. A second run updates; it never duplicates.
- **Export**: when someone changes what stackiq owns on an application in use (owners, licences, contract, BBN level, TIME class), that change goes to the service desk record.
- **Ownership decides conflicts, not time.** Each mapping marks every field as owned by the service desk or by stackiq. The service desk wins for the fields it owns (name, supplier, installed version, status). Stackiq-only fields (licences, contracts, publication) always stay with stackiq. An import never overwrites a stackiq-owned field, and an export never sends a service-desk-owned field.
- **No ping-pong.** A write in one direction never comes back as a change in the other.
- **A file import** (CSV or XLSX) feeds the same flow for organisations without a service desk API.

## Why

Row from the stackiq matrix:

- `stackiq:share-itsm-integration`, "Exchange application data with the organisation's service management tool." Rated no. Four competitors rate yes: SAP LeanIX (https://www.leanix.net/hubfs/Legal/Metrics-and-Feature-List-EAM-SAP-LeanIX-v3.1.pdf, "ServiceNow integration ... to synchronize infrastructure and software asset information"), BlueDolphin (https://help.bluedolphin.io/en/articles/11967779-add-an-integration-in-bluedolphin, "out-of-the-box integrations with ITSM platforms like TOPdesk, ServiceNow, and JIRA"), GLPI (source read at 11.0.9, application records used directly by tickets, `src/Appliance.php:105`) and TOPdesk (https://docs.topdesk.com/en/linking-assets-to-cards.html, assets linked to calls and changes).

Ruben widened the row on 2026-10-01 for the Rotterdam programme: stackiq is the CMDB at application level plus licences and contracts, with a two-way sync to TOPdesk and ServiceNow built on OpenRegister and integriq flows and mappings, and per-field ownership as the conflict rule. The earlier version of this change only attached a service desk link to usages that already existed.

The matrix category still keeps stackiq from being a service desk (`stackiq:ops-tickets` is decided no) and from being a discovery agent.

## What stackiq has today

Read at development `f262512c`, OpenRegister development `9a4e28e9a9`, integriq development `c67abca0c`.

- No ITSM connector: `lib/Settings/connections.json` declares `email`, `federation` and `eol-feed` only, and `lib/` and `src/` hold no TOPdesk, ServiceNow or ITSM code.
- The application level exists as data: `module` (the application as a supplier offers it, with `provider`), `usage` (an organisation's use of it, `lib/Settings/softwarecatalogus_register.json`), `connection` (two applications linked), and components through `module.partOf` (open change `landscape-application-components`).
- Licences and contracts: `catalogContract` has `contractNumber`, `contractType` (SLA, Licence, Maintenance), `startDate`, `endDate`, `cost`, `costPeriod` and `status`; `lib/Settings/register.d/contracts-licence-seats.json` adds `licenceMetric`, `licencesBought` and `licencesInUse`. A contract requires a `service` (a catalogue service), which a licence bought for an application does not have.
- OpenRegister's flow engine has an object trigger, a schedule trigger, `object-read`, `object-write` with `upsert` on any `match` list, `explode`, `set-fields` and `switch`. It has no outside call and no loop guard on object triggers (`openspec/changes/lifecycle-auto-transitions/design.md:43` in OpenRegister).
- Integriq contributes the outside half as flow nodes: `openconnector.source-paginate`, `openconnector.apply-mapping`, `openconnector.contract`, `openconnector.contract-commit` and `openconnector.source-call` (integriq `lib/Flow/FlowNodeListener.php:108`). A contract records the origin id, the hash of what was read, and the stackiq record it became; `openconnector.contract` answers create, update or skip.
- Integriq has no TOPdesk or ServiceNow source and no per-field ownership yet. Both are built in integriq's open change `connectors-service-desk-templates` (Rotterdam lane iq), which defines the ownership marker this change relies on.

## What this change builds

1. **Fields** in a register fragment: on `usage`, `connection` and `catalogContract` the service desk reference (system, record id, link, last synchronised); on `usage` the installed version and a publication date; on `catalogContract` the licence fields still missing (vendor reference, currency, supplier). A contract no longer requires a catalogue service.
2. **An `itsm` connection** on the Integrations page.
3. **Flow templates** shipped by stackiq: inbound applications, inbound relations, inbound licences and contracts, outbound applications, and a file import. A set-up action fills them with the administrator's integriq source and the desk's mapping presets, validates them with OpenRegister, creates and publishes them.
4. **Matching** on import: by the service desk record id first (integriq's contract), then by name and supplier (`object-write` upsert). Near-duplicates that do not match exactly surface on OpenRegister's duplicate candidates page through the rules `operations-record-reconciliation` declares, and a steward merges them there.
5. **A CMDB page** in stackiq that says plainly what stackiq records and what it does not, shows the service desk exchange and its last run, and takes a file import. English and Dutch.

## Out of scope

- The service desk sources, their credentials, the mapping presets with their ownership marker, and the mock servers: integriq's half (`connectors-service-desk-templates`, ADR-064, ADR-091). Stackiq names sources and presets by slug only and never holds a secret.
- GLPI: integriq ships its template; stackiq's set-up offers it once a GLPI preset exists.
- Hardware and infrastructure configuration items: `operations-technology-components`.
- Logging calls or incidents in stackiq: decided no (`stackiq:ops-tickets`).
- Discovering installed software: stackiq is not a discovery agent.
- Deleting a stackiq record when its service desk record disappears. The import reports it in the flow run; a person decides.

## Risks

- Integriq's ownership enforcement (`apply-mapping` `ownership` and `exists`) and the TOPdesk and ServiceNow presets do not exist until lane iq ships them. Until then OpenRegister's preflight reports the flows as invalid and the set-up action refuses, saying which node or preset is missing. It never creates a half-working flow.
- A desk record that matches no stackiq application by id, name or supplier is created as a new application. If it was a spelling variant it shows up as a duplicate candidate; merging it is a steward's task.
- Two people changing the same field in both systems at once: the owner's value wins on the next run. That is the rule, and the docs say so.
- Contracts and licences carry costs. The fields stay behind `catalogContract`'s read rule, and lane oc publishes nothing from that schema.
