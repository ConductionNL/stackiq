# Design: connections-catalogue-pages

Read at development `49e65cb4`. Every path and line below was opened for this design.

## Context

A connection (`koppeling`) is a catalogue object: an application A talks to an application B, or to a national provision, over a transport, in a direction. The schema is complete (`lib/Settings/softwarecatalogus_register.json:3565`) and the ArchiMate import fills it, but the app shows it nowhere. The pages are declarative manifest pages over OpenRegister (ADR-001, ADR-024), so no PHP controller or service is added.

## D1. Pages live in a manifest fragment

New file `src/manifest.d/connections.json` (ADR-037), merged at build like `src/manifest.d/connection-registry.json`. It holds:

- `Koppelingen`, route `/koppelingen`, `type: index`, `register: @resolve:voorzieningen_register`, `schema: connection`. Columns: `name`, `type`, `status`, `moduleA`, `moduleB`, `nonMunicipalProvision`, `dataExchangeDirection`. `filterMenu: true`, so the table header lists the values of every enum column as toggleable filters (`CnIndexPage.vue:2236` in `@conduction/nextcloud-vue` 2.57.1). `quickFilters` on status: All, In use, In development, End of support, Withdrawn, the way `Contracten` does it (`src/manifest.json:540`).
- `KoppelingDetail`, route `/koppelingen/:id`, `type: detail`. Widgets: `kp-data` (type `data`, all visible fields), `kp-files` (files integration, the schema already allows files), `kp-related` (type `related`), and a History tab in the sidebar like every other detail page. `lifecycleActions` on, so `CnLifecycleActions` (`CnDetailPage.vue:158`) offers release, sunset and withdraw once D3 lands.

Rejected: adding the pages to `src/manifest.json` directly. That file is already 1,000+ lines, and ADR-037 puts a change's pages in its own fragment so two changes do not conflict on one file.

## D2. The application page gets two connection lists

`ModuleDetail` (`src/manifest.json:491`) gains two `object-list` widgets:

| id | filter | title |
|---|---|---|
| `md-connections-out` | `{ "moduleA": "@objectId" }` | Connections from this application |
| `md-connections-in` | `{ "moduleB": "@objectId" }` | Connections to this application |

Both set `rowRoute: KoppelingDetail`, `viewAllRoute: Koppelingen` with the same filter in `viewAllQuery`, and columns `type`, `status`, the other side of the connection. `CnObjectListWidget` takes one filter object with AND semantics (`CnObjectListWidget.vue:503`), so one list with "A or B" is not possible declaratively; two lists also say which way the data flows.

Rejected: a custom widget that calls `GET /api/koppelingen-gebruik/{uuid}` (`AangebodenGebruikController.php:208`). That endpoint is public, mixes usages into the answer, and would add the first caller of a custom endpoint where OpenRegister's own list already serves the need (ADR-022).

## D3. Register fixes in the same change

All in `lib/Settings/softwarecatalogus_register.json`, schema `connection`:

1. **Lifecycle states.** (Already landed before this change was built: #1154, register 2.5.1, connection 0.3.2. `tests/Unit/Settings/ConnectionSchemaTest.php` keeps it true.) Replace the Dutch states in `x-openregister-lifecycle` (:3926) with the enum values: initial `in development`, final `withdrawn`, transitions release (`in development` to `in use`), sunset (`in use` to `end of support`), withdraw (`in use`, `end of support` to `withdrawn`). The rows already hold the English values (`lib/Repair/RenameDutchCatalogValues.php:87-90`).
2. **Picker.** Change `objectConfiguration.queryParams` on `nonMunicipalProvision` (:3720) to `gemmaType=Buitengemeentelijke voorziening`, the spelling the GEMMA model uses.
3. **Name template.** `objectNameField` names `gegevensuitwisselingRichting` and `buitengemeentelijkVoorziening`, keys the schema renamed to `dataExchangeDirection` and `nonMunicipalProvision`, and maps `AnaarB`, `BnaarA`, `bi-directioneel` where the enum holds `AtoB`, `BtoA`, `bi-directional`. Rewrite it on the current keys and values, so a connection reads "Application A to Application B" in lists and pickers.
4. **Facets.** Set `facetable: true` on `type`, `status` and `dataExchangeDirection`, so the index page can count and filter them.
5. **Version.** Bump the `connection` schema version (built: 0.3.3, since #1154 had taken 0.3.2) and the register version (built: 2.5.2), and add a changelog line. The register changelog entry 2.4.4 (`register.json:7`) records why: OpenRegister skips an import whose deployed version is not lower, and its content check ignores `configuration`.

## D4. Menu

Add `Connections` as a child of the `Modules` (Applications) menu entry, in the fragment. `CnAppNav` supports one level of `children[]` (`CnAppNav.vue:6`). ADR-097 decision 1 caps the main menu at six top-level entries and asks an amendment for more. Stackiq already carries fifteen, so this change adds none.

Rejected: a top-level `Connections` entry. It would need an ADR-097 amendment that this change has no standing to make.

## Declarative versus imperative

Everything here is declarative: manifest pages, `object-list` widgets, the schema's own lifecycle and facets (ADR-031). No service, controller or route is added. Access follows the schema's existing `authorization` block (`register.json:3565` area): organisation-scoped read and public read after `publicationDate`.

## Seed data

No new schema. As built: the demo register `lib/Settings/stackiq_mock_register.json` already carries six generated connections (types n/a, file transfer and digikoppeling, all three first statuses), so it is left as is.

## As built (2026-09-29)

- Widget icons: `TransitConnectionVariant` is in the app icon registry (menu) but not in nextcloud-vue's widget registry, so the grid widgets use `LinkVariant` (`tests/vitest/manifestWidgetIcons.spec.js`).
- The type filter is the table's filter menu; the same filter is reachable as a route query (`/koppelingen?type=api`), which is what the View all links of the application page use.

## Risks

- `ModuleDetail` is also edited by `landscape-application-page`. Both add rows to the same grid. The second change to land moves its widgets below the first.
- Existing connections whose `nonMunicipalProvision` points at an element still resolve; only the picker's query changes.
- A connection readable by the public group shows on the public frontend already; the pages add no new read path.
