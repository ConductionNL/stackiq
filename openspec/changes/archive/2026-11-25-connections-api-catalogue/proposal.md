---
kind: code
depends_on:
  - connections-catalogue-pages
---

# Record the APIs an application exposes

## Summary

A supplier or an information manager records the APIs an application offers, next to that application: the style, the version, where the specification lives, the standards it follows and its status. A connection can name the API it calls. The application page lists its APIs, and an APIs list shows them across the catalogue.

## Why

Row from the stackiq matrix:

- `stackiq:conn-api-catalogue`, "Keep the APIs an application exposes in the catalogue next to the application." Rated no. SAP LeanIX rates yes: https://help.sap.com/docs/leanix/ea/interface-modeling-guidelines, interface subtype "API ... APIs provide functionalities accessible to external applications", related to the providing application. The row sits in the product's core area (connections), which is why it is built with one competitor.

No tender, feature request or roadmap row names it.

## What stackiq has today

- No schema describes an API. The register's catalogue schemas are listed at `lib/Settings/softwarecatalogus_register.json:817` onwards (sector, suite, module, catalogService, vulnerability, contactPerson, organization, usage, catalogContract, connection, software-review, compliancy, moduleVersion, sbomComponent, bioMeasure).
- `connection.type` has the value `api` (`register.json:3565` schema), but it only labels the transport of one connection; it says nothing about the API itself.
- Standards live as GEMMA elements with `gemmaType` standaard and standaardversie; `module.standardVersions` and `connection.standardVersions` already point at them.

## What this change builds

1. A schema `applicationInterface` (title "API") in a register fragment: name, descriptions, the providing application, style, version, specification URL, documentation URL, standard versions, status and publication dates.
2. An optional `interface` field on `connection`, so a connection names the API it calls.
3. An APIs list page and an API detail page, reached under Applications in the menu.
4. An APIs section on the application page, with an Add button that fills in the application.

## Out of scope

- A developer portal: keys, subscriptions, a try-it console. The matrix category says stackiq is not a developer portal; integriq's open change `access-developer-portal-and-subscriptions` covers that for its gateway.
- Importing APIs from an OpenAPI file or a gateway (integriq's `gateway-openapi-import-and-publish` covers publishing through integriq).
- Checking an API against the NLGov REST API design rules (integriq's `gateway-api-design-rules-check`).

## Risks

- Suppliers and municipalities may both register the same API. The detail page shows the providing application and its supplier, and `operations-record-reconciliation` covers merging duplicates.
