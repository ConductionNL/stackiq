# Design: operations-technology-components

Read at development 49e65cb4, and the lead's merged changes at development 9a5ece6a (`connections-catalogue-pages`, `landscape-usage-registration`).

## Where it fits

| Part | File and line | What changes |
|---|---|---|
| Fragment | new `lib/Settings/register.d/operations-technology-components.json` (ADR-037) | new schema `technologyComponent`; `registers.stackiq.schemas` gets it; `registers.stackiq.configuration.schemas.technologyComponent` gets `magicMapping` and `autoCreateTable`; `usage.properties.runsOn` and a higher `usage` version |
| Settings | `lib/Service/SettingsService.php:121` `LEGACY_SCHEMA_KEY` | nothing: a new slug resolves by the default `<type>_schema` rule to `technologyComponent_schema` |
| Service | `lib/Service/EolSyncService.php`, after `saveStampedVersion()` (`:419`) | stamps `endOfSupport`, `eolSource` and `eolUpdatedOn` on every component whose `software` is the stamped version |
| Pages and menu | new `src/manifest.d/operations-technology-components.json` | `Technologie` at `/technologie` (`type: index`) and `TechnologieDetail` at `/technologie/:id` (`type: detail`), menu entry `TechnologieMenu` |
| Menu layout | `src/menu-layout.json` `relocations` | `"TechnologieMenu": "Modules"` (ADR-097) |
| Page | `GebruikDetail` in `src/manifest.d/usages.json` (from `landscape-usage-registration`) | a body widget `UsageRunsOnPanel` |
| Component | new `src/components/usages/UsageRunsOnPanel.vue`, registered in `src/customComponents.js` | lists the components the usage runs on with their end of support, and marks the ones past it |
| Util | new `src/utils/technologyLifecycle.js` | pure `supportState(component, today)`: `supported`, `ending` (within 180 days), `ended`, `unknown` |
| Seed | `lib/Settings/stackiq_mock_register.json` | three components and `runsOn` on one usage |

## The schema

`technologyComponent`, in the `stackiq` register.

| Property | Type | Notes |
|---|---|---|
| `name` | string, required | the object name |
| `class` | enum, required | Physical server, Virtual machine, Container platform, Database, Middleware, Runtime or framework, Operating system, Network device, Storage, End-user device, Other |
| `archimateType` | enum | Node, Device, System software, Technology service, Communication network; derived default per class, editable |
| `organization` | `$ref` organization, required | the owning organisation, `related-object` |
| `status` | enum | Planned, In use, To be phased out, Phased out; default In use |
| `manufacturer`, `model`, `serialNumber`, `assetTag`, `location` | string | for hardware |
| `software` | `$ref` moduleVersion | the version of a System software module this component is, for example PostgreSQL 13 |
| `endOfSupport` | date | typed for hardware; stamped by the EOL sync when `software` is set |
| `eolSource`, `eolUpdatedOn` | string, datetime | provenance, the same names `moduleVersion` uses |
| `runsOn` | array of `$ref` technologyComponent | a virtual machine runs on a host, a database on a virtual machine |

`configuration`: `objectNameField` `name`, `objectDescriptionField` `class`, `autoPublish` false, `allowFiles` false, and an `x-openregister-lifecycle` on `status` with the English enum values (plan to In use, phase out, retire), so every transition matches a row. The `authorization.read` rule copies `catalogContract`'s organisation scoping (`_organisation` equals `$organisation` per group) plus `software-catalog-admins`; suppliers and anonymous visitors get nothing.

`usage.runsOn`: array of `$ref` technologyComponent, `related-object`, title Runs on.

## Decisions

### D1. One schema with a class, not one schema per kind

A server, a database and a laptop share name, owner, status, lifecycle and relations; they differ in which optional fields are filled. One `technologyComponent` with a `class` keeps one index, one detail page and one relation type, and a new kind is an enum value. GLPI's many asset types each carry their own table and form; stackiq has no reason to copy that.

Rejected: technology as more `module` types. A `module` is the supplier's published product, readable by the public (its `authorization.read`); an organisation's servers must not be.

Rejected: technology as `element` objects in the `vng-gemma` register. That register holds the GEMMA reference model, and `lib/Settings/GEMMA_release.xml` has no technology elements for an organisation's data to hang from.

### D2. Software lifecycle comes from the catalogue, hardware lifecycle is typed

For platform software the catalogue already knows the product (a `module` of type System software), its versions (`moduleVersion`) and, when mapped, their end of support from the feed (`EolSyncService`, `findMappedModules()` at `:290`). A database component points at its version through `software`; after the sync stamps that version, it stamps the same date on the component with `eolSource`. Hardware has no feed, so its owner types `endOfSupport`.

Rejected: reading the version's date at display time only. Then the index could not sort or filter on end of support, and a component whose `software` is unset would need a second code path anyway.

### D3. An application in use runs on components; the product does not

The relation sits on `usage`, the organisation's deployment, and not on `module`, for the same reason as D1: one product runs on different platforms in different municipalities. `usage.runsOn` points at the organisation's own components. The application's exposure is the worst `supportState` of the components it runs on, including what those run on, one level down (a database on a virtual machine on an ageing host).

### D4. Relations between components are one relation type

`runsOn` covers hosting, which is what obsolescence and impact need. Other link types (backup of, cluster member of) are not asked for by the rows and would each need a meaning; a later change can add them as a relation with a type.

### D5. Pages use the library's index and detail types

`Technologie` is a `type: index` page with columns name, class, organisation, status and end of support, quick filters Hardware (the five hardware classes) and Platform software, and sort on `endOfSupport`. `TechnologieDetail` is a `type: detail` page (ADR-062, ADR-096): a data widget, an `object-list` Runs on this component (filter `runsOn` equals `@objectId` on `technologyComponent`), an `object-list` Applications in use here (filter `runsOn` equals `@objectId` on `usage`, `rowRoute: GebruikDetail`), lifecycle actions and a History tab. Only `UsageRunsOnPanel` is custom, because a built-in object list cannot show a derived state per row; it composes `CnWidgetWrapper` and draws nothing else of its own (ADR-012).

## Declarative versus imperative

- The component lifecycle is declarative: an `x-openregister-lifecycle` block on `status`.
- The relations are declarative: `$ref` properties with `related-object` handling, shown through the detail page's object lists.
- The end-of-support stamp is imperative because it extends the existing EOL sync, which already runs imperatively in `EolSyncService`; no `x-openregister-*` rule copies a field from a related object.
- A notification before a component's end of support could be a declarative `x-openregister-notifications` rule, as `moduleVersion` has for its own `dateEndSupport` (`lib/Settings/softwarecatalogus_register.json:7667`). It is a follow-up, not part of this change.

## Seed data

`technologyComponent` is new, and `usage` gains `runsOn`, so the demo descriptor `lib/Settings/stackiq_mock_register.json` gets matching objects in the `stackiq` register, with OpenRegister seed references (`@ref:<slug>`).

| `@self.slug` | `name` | `class` | `organization` | `software` | `endOfSupport` | `runsOn` |
|---|---|---|---|---|---|---|
| technology-host-1 | Host 01 | Physical server | `@ref:organization-voorbeeld-name-1-1` | empty | 2029-12-31 | empty |
| technology-vm-1 | App server 01 | Virtual machine | `@ref:organization-voorbeeld-name-1-1` | empty | empty | `@ref:technology-host-1` |
| technology-db-1 | Database 01 | Database | `@ref:organization-voorbeeld-name-1-1` | `@ref:moduleversion-moduleversion-3-3` | stamped from the version, 2026-03-03 | `@ref:technology-vm-1` |

`usage-usage-3-3` gets `runsOn: [@ref:technology-db-1, @ref:technology-vm-1]`. Its Runs on panel then shows Database 01 as past end of support.

## Risks

- **Sensitive data.** Serial numbers, hosts and locations help an attacker. The read rule is organisation-scoped, the schema is never published, and nothing in this change adds a public route.
- **Stale hardware dates.** A typed `endOfSupport` is only as good as the person who typed it; the detail page shows `eolSource` so a reader sees whether a date came from the feed or by hand.
- **A fragment and the usage schema.** Adding `runsOn` to `usage` from a fragment appends a property, which the deep merge supports; the fragment also raises the `usage` version, or the import skips the change (the register changelog 2.4.4 at `lib/Settings/softwarecatalogus_register.json:7` records why).
