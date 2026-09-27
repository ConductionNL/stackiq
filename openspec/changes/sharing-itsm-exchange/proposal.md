---
kind: code
depends_on:
  - landscape-usage-registration
---

# Exchange the application landscape with the organisation's service desk

## Summary

A functional administrator connects stackiq to the organisation's service management tool, such as TOPdesk, ServiceNow or GLPI. The applications the organisation uses go to the service desk as configuration items, with supplier, version, status and owners, and the service desk's record id and link come back onto each application in use. Service desk staff then log calls against the same applications the catalogue holds, and the catalogue shows where each one lives in the service desk.

## Why

Row from the stackiq matrix:

- `stackiq:share-itsm-integration`, "Exchange application data with the organisation's service management tool." Rated no. Four competitors rate yes: SAP LeanIX (https://www.leanix.net/hubfs/Legal/Metrics-and-Feature-List-EAM-SAP-LeanIX-v3.1.pdf, "ServiceNow integration ... to synchronize infrastructure and software asset information"), BlueDolphin (https://help.bluedolphin.io/en/articles/11967779-add-an-integration-in-bluedolphin, "out-of-the-box integrations with ITSM platforms like TOPdesk, ServiceNow, and JIRA"), GLPI (source read at 11.0.9, application records used directly by tickets, `src/Appliance.php:105`) and TOPdesk (https://docs.topdesk.com/en/linking-assets-to-cards.html, assets linked to calls and changes).

No tender, feature request or roadmap row names it. The matrix category keeps stackiq from being a service desk itself (`stackiq:ops-tickets` and the other service desk rows are decided no); exchanging with one is this row.

## What stackiq has today

- No ITSM connector: `lib/Settings/connections.json` declares `email`, `federation` and `eol-feed` only, and `lib/` and `src/` hold no TOPdesk, ServiceNow or ITSM code.
- The connection registry (open change `adopt-connection-registry`) shows stackiq's outside connections on the Integrations page, backed by integriq.
- Stackiq's Flows page (`src/manifest.json:1057`) lists OpenRegister flows scoped to stackiq, and OpenRegister validates and creates flows through its API (`/api/flow/validate`, `/api/flows`).
- Integriq runs synchronisation as flow steps (fetch, map, contract, save) over its sources, with credentials held by integriq (integriq open change `flow-native-synchronization`, ADR-064, ADR-091).

## What this change builds

1. On `usage`: `externalReferences`, a list of `{ system, recordId, url, syncedAt }`, so an application in use knows its service desk record.
2. An `itsm` entry in `lib/Settings/connections.json`, so the Integrations page shows whether the exchange is set up and when it last ran.
3. Two flow templates with mapping presets for TOPdesk, ServiceNow and GLPI, and a set-up action for the administrator that fills in the integriq source and creates the flows: outbound (a usage created or changed goes to the service desk through integriq's source call) and inbound (a nightly read of the service desk's application records that writes their id and link back onto the matching usages).
4. The service desk link on the usage page and a Service desk column on Applications in use.

## Out of scope

- The service desk sources and their credentials: integriq's half. Integriq holds the TOPdesk, ServiceNow or GLPI source and its secret (ADR-064), and its connector catalogue gets the source templates. Stackiq names the source by reference only.
- Logging calls or incidents in stackiq: decided no (`stackiq:ops-tickets`, the matrix category).
- Discovering installed software from the service desk's inventory: the matrix category says stackiq is not a discovery agent.

## Risks

- Matching an existing service desk record to a usage is by name and supplier on the first run; a record that does not match stays unlinked and is listed in the flow run for a person to link by hand.
