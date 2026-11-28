---
kind: config
depends_on:
  - landscape-application-page
---

# Break an application into its components

## Summary

A supplier or an information manager records that an application consists of components, such as a case system with a separate portal and a document module. A component is an application in its own right, with its own versions, standards and connections, and it names the application it belongs to. The application page lists its components, and a component's page shows the application it is part of.

## Why

Row from the stackiq matrix:

- `stackiq:land-application-modules`, "Break an application into modules and see which module belongs to which product." Rated partial, built. Two competitors rate yes: SAP LeanIX (https://help.sap.com/docs/leanix/ea/application-modeling-guidelines, "Applications often consist of multiple entities or modules within a common ecosystem or platform", modelled as a parent and child hierarchy) and BlueDolphin (https://help.bluedolphin.io/en/articles/11967518-grouping-and-child-objects-in-architecture-views, "breaking down a system into its components"). The missing half: breaking one application into its components. Core area (landscape).

No tender, feature request or roadmap row names it.

## What stackiq has today

- Stackiq's `module` is the whole application (`lib/Settings/softwarecatalogus_register.json:6779` schema, title Application). Nothing breaks one application into parts.
- A suite (`register.json:1137` schema) lists applications that are sold together (`suite.applications`), with a wizard (`src/dialogs/SuiteWizardDialog.vue`) and a page (`SuiteDetail`, `src/manifest.json` around :676). That answers "which application belongs to which product", not what one application is made of.

## What this change builds

1. A `partOf` field on `module` pointing at the application it is a component of, limited to applications of the same supplier.
2. A Components list on the application page, with an Add component action, and the parent application in the component's data.
3. A column filter on the Applications list to hide components, so the list shows whole applications by default.

## Out of scope

- More than one level of nesting (a component of a component). The field allows it, but the pages show one level.
- Suites and the suite wizard, which stay as they are.
- Drawing the composition in an architecture view: `architecture-views-editor`.

## Risks

- A component that is also registered as a separate application in usages keeps its own usages; nothing moves usages to the parent.
