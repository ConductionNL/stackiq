---
kind: code
depends_on:
  - connections-catalogue-pages
  - landscape-usage-registration
---

# Suggest connections from what the catalogue already knows

## Summary

When an organisation records that it uses an application, stackiq suggests the connections that application already has with other applications the organisation uses, so nobody draws each link by hand. When a usage is replaced by a newer version or by its planned successor, stackiq offers to carry the old usage's connections over. The organisation accepts or dismisses each suggestion.

## Why

Rows from the stackiq matrix:

- `stackiq:conn-auto-populate-dependencies`, "Fill in an application's dependencies automatically from what is already known about connected items, instead of drawing each link by hand." Rated no. Feature request: https://github.com/glpi-project/roadmap/discussions/336. Two competitors rate yes: SAP LeanIX (https://help.sap.com/docs/leanix/ea/jira-service-management-integration, "Dependencies and relationships identified in Jira Service Management are automatically documented", and discovered flows that suggest missing interfaces) and BlueDolphin (https://help.bluedolphin.io/en/articles/11967645-use-datasource-to-create-relationships, "automatically create relationships between objects based on a loaded datasource"). Core area (connections).
- `stackiq:land-version-carry-connections`, "Carry an application's connections over automatically when a new version replaces the old one." Rated no. Feature request from the VNG user research: https://www.softwarecatalogus.nl/gebruikersonderzoek%202021. Core area (landscape).

## What stackiq has today

- A connection links applications, not versions: `connection.moduleA` and `moduleB` are `$ref module` (`lib/Settings/softwarecatalogus_register.json:3689`, `:3705`). Registering a new version never drops a catalogue connection.
- What an organisation runs is a usage: `usage.module`, `usage.moduleVersion` and `usage.koppelingen`, "the connections used within this usage" (usage schema, `register.json:2656`). `usage.plannedReplacement` names a successor application.
- A replacing usage starts with an empty `koppelingen`. Nothing copies connections from the usage it replaces, and nothing fills `koppelingen` from the connections the application already has.
- Suppliers can already offer usages and connections that a municipality accepts or denies (`lib/Service/AanbodService.php:289` acceptAanbod, `:438` denyAanbod; `appinfo/routes.php:203-204`). That flow handles one offered object; it does not derive anything.

## What this change builds

1. A suggestion service that, for one usage, lists the connections of its application whose other end is an application the same organisation uses, and that are not yet in the usage.
2. A carry-over suggestion: when a usage of the same application at a newer version, or of the old usage's planned successor, is created for the organisation, stackiq offers the old usage's connections. For a successor, it offers draft connections from the successor to the same other ends.
3. A Suggested connections panel on the usage page (from `landscape-usage-registration`) with Accept and Dismiss per suggestion and Accept all.
4. Dismissed suggestions stay dismissed for that usage.

## Out of scope

- Discovering connections from network traffic, logs or a service desk. The matrix category says stackiq is not a discovery agent.
- Suggestions pulled from outside systems through integriq (`sharing-itsm-exchange` covers the service desk exchange).
- The offer workflow for suppliers (`AanbodService`), which stays as it is.

## Risks

- A popular application has many connections. Suggestions only list connections whose other end the organisation actually uses, which keeps the list short.
- A draft connection for a successor may be wrong. It starts with status `in development` and names its origin, so the user reviews it.
