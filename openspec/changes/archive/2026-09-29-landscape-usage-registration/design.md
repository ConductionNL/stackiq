# Design: landscape-usage-registration

Read at development `49e65cb4`.

## Context

A usage (`gebruik`) is an organisation's use of an application: `consumer` (the organisation), `module`, `moduleVersion`, `status`, phase dates, connections and replacement (`usage` schema, `lib/Settings/softwarecatalogus_register.json:2656`). Its read rule shows a usage to the using organisation (`consumer` or `_organisation` equals the active organisation) and to the supplier (`provider`). Everything the portfolio views compute (`src/views/LifecycleRoadmapView.vue:397`, `lib/Service/PortfolioReportService.php`) starts from usages, but no page creates them.

## D1. Pages in a fragment

`src/manifest.d/usages.json` (ADR-037):

- `Gebruik`, route `/gebruik`, `type: index`, schema `usage`. Title "Applications in use". Columns: module, moduleVersion, status, businessOwner, technicalOwner, timeClassification. Quick filters on status. `filterMenu: true`.
- `GebruikDetail`, route `/gebruik/:id`, `type: detail`. Widgets: data (application, version, status, phase dates, owners, cloud model, annotation), files (the schema has `allowFiles` and tags DPIA, Contract, Verwerkingsovereenkomst), related, and a History tab. `lifecycleActions` on.
- Menu child "Applications in use" under the `Modules` entry. No new top-level entry (ADR-097).

`landscape-application-page` added a usages list to `ModuleDetail` without a row route; this change sets its `rowRoute: GebruikDetail`. `OrganisatieDetail` (`src/manifest.json:403`) gets an `object-list` `org-usages` with filter `{ "consumer": "@objectId" }`, next to `org-modules` (:417), which lists what an organisation offers.

## D2. "Add to our landscape"

`ModuleDetail`'s usages list (`md-usages`) offers its create button labelled "Add to our landscape" (`addLabel`). The list is filtered on `module: @objectId`, and the library's `CnObjectListWidget.onCreateConfirm` merges that filter value into the new row, so the usage is created with the application filled in. The form asks consumer, version, status and both owners (`formIncludeFields`). It mirrors the GEMMA Softwarecatalogus "+" behind a package (the row's evidence).

Changed at build (29 Sep, development `f280e807`): the design first said a header action with `consumer` set to the active organisation. No manifest token names the active organisation (`resolveFilterTokens` knows `@objectId`, `@object.*`, `@workspace.*`, `@config.*`, `@me` and dates; `CnFormDialog._autofillTenant` fills only a field called `organisation`), so the user picks the organisation in the form. Filling it needs an `@organisation` token in nextcloud-vue.

Rejected: a wizard. The usage form has five fields a user must decide on; a dialog is enough, and `CnFormDialog` already renders the schema.

## D3. Owners

Two new properties on `usage`, added through `lib/Settings/register.d/usage-owners.json`:

| property | type | notes |
|---|---|---|
| `businessOwner` | `$ref contactPerson` | `x-relation-filter: { "organization": "@object.consumer" }` |
| `technicalOwner` | `$ref contactPerson` | same filter |

The existing hidden `contactPerson` stays as it is. The contact person read rule (`register.json:1788` schema) scopes a supplier to its own organisation's contact persons, so a supplier reading a usage of its product sees an owner reference it cannot open.

Rejected: owner fields on `module`. A module is the supplier's product; the business owner is a person of the organisation that uses it, and two municipalities using one product have two owners.

## D4. Register fixes

Items 1 and 3 landed before this change was built, in register 2.5.1 (stackiq#1140: usage 1.5.1 with the lifecycle on the enum values). The fragment `usage-owners.json` carries item 2 and the owners, and bumps usage to 1.5.2. It also makes `status` facetable for the list's status filters. The seeded usages in the register held `in-gebruik`, a value outside the enum; they now read In production and Planned.

In `lib/Settings/softwarecatalogus_register.json`, schema `usage`:

1. `x-openregister-lifecycle` on the enum values: initial `Acquisition`, final `Phased out`, transitions plan (Acquisition to Planned), goLive (Planned to In production), phaseOut (In production to To be phased out), retire (To be phased out to Phased out). The rows already hold these (`lib/Repair/RenameDutchCatalogValues.php:80-84`).
2. `objectNameField` becomes `{{ module }} ({{ consumer }})`.
3. The schema version goes to 1.5.1 with a register changelog line, for the reason the 2.4.4 entry records (`register.json:7`).

## Declarative versus imperative

Declarative only: pages, relations, lifecycle and a header action that opens the library form (ADR-031). No PHP.

## Seed data

`lib/Settings/stackiq_mock_register.json`: two demo usages of demo applications by the demo municipality, one In production with a version and both owners, one Planned.

## Risks

- The default status stays In production, which the lifecycle treats as a valid state. A usage created as In production starts there; the lifecycle's initial state only applies when no status is sent.
- `ModuleDetail` gains a header action and a row route; `landscape-application-page` lands first.
