# Design — SoftwareCatalog manifest v1

## Approach

SoftwareCatalog has no `src/manifest.json` today. The shell is a
hand-wired `MainMenu.vue` (NcAppNavigation populated from
`objectStore.settings.availableRegisters`, filtered to schemas in the
`voorzieningen` register that have a configuration with an icon) +
`Views.vue` (a v-if/v-else cascade dispatching on
`navigationStore.selected`) + a single `ObjectIndex.vue` that
re-implements `CnIndexPage`'s job per object type. The `Dashboard.vue`
view uses `CnDashboardPage` already but with widget definitions
hard-coded inline.

The migration target is decidesk's Tier-4 shell: a manifest-driven
app where `CnAppRoot` mounts `CnAppNav` from `manifest.menu[]` and
`CnPageRenderer` dispatches each route through `defaultPageTypes`.
Schema-driven index/detail pages get free CRUD + sidebar; the
dashboard becomes `widgets[]` + `layout[]`; settings becomes a
`type: "settings"` shell with `widgets[]` rich sections falling
through to a custom registry component while the lib's settings
orchestration matures.

The change consumes `@conduction/nextcloud-vue@1.0.0-beta.12` which
ships:
- `CnAppRoot` / `CnAppNav` / `CnPageRenderer` (full Tier-4 stack)
- The `@resolve:` sentinel in `useAppManifest()`
- Page types `index | detail | dashboard | logs | settings | chat |
  files | custom`
- The Vue 2 `Vue.extend()` frozen-component fix on the page-types
  registry

## Per-page mapping table

The 14 entries in the new `src/manifest.json`:

| New id | Type | Route | Schema slug | Config sketch | Notes |
|---|---|---|---|---|---|
| `Dashboard` | `dashboard` | `/` | — | `{ widgets: [info-box, stats-table-1, stats-table-2], layout: [3 grid items] }` | Ports the existing `Dashboard.vue` info-box + 2 stats tables to `widgetDef[]`. Uses `custom` widget type for the bespoke stats tables until a `stats-block` widget covers it. |
| `Organisaties` | `custom` | `/organisaties` | — | `component: "OrganisatieIndexView"` | **Custom survivor.** Bespoke `OrganisatieCard` + `AddContactpersoonModal`/`OrganisationModal` orchestration. Documented as the canonical example for a future `cardComponent` config field on `type: "index"`. |
| `Contactpersonen` | `index` | `/contactpersonen` | `contactpersoon` | `{ register: "@resolve:voorzieningen_register", schema: "contactpersoon", columns: ["voornaam","achternaam","email","telefoonnummer","organisatie"], sidebar: { enabled: true, showMetadata: true } }` | Standard schema-driven list. |
| `ContactpersoonDetail` | `detail` | `/contactpersonen/:id` | `contactpersoon` | `{ register, schema, sidebarTabs: [overview, audit] }` | Standard detail. |
| `Contracten` | `index` | `/contracten` | `contract` | `{ register, schema: "contract", columns: ["naam","leverancier","ingangsdatum","einddatum","status"], sidebar: { enabled: true } }` | Standard schema-driven list. |
| `ContractDetail` | `detail` | `/contracten/:id` | `contract` | `{ register, schema, sidebarTabs: [overview, audit] }` | Standard detail. |
| `Standaarden` | `index` | `/standaarden` | `standaard` | `{ register, schema: "standaard", columns: ["naam","versie","categorie","status"], sidebar: { enabled: true } }` | NB: `standaard` is in the AMEF/vng-gemma register schema set, but is exposed via `voorzieningen` for cross-register listing here. If the standalone `standaard` schema is not in the active tenant's `voorzieningen_register`, the renderer shows the standard "schema not found" empty state — non-fatal. |
| `StandaardDetail` | `detail` | `/standaarden/:id` | `standaard` | `{ register, schema, sidebarTabs: [overview, audit] }` | Standard detail. |
| `Reviews` | `index` | `/reviews` | `beoordeeling` | `{ register, schema: "beoordeeling", columns: ["titel","auteur","score","datum"], sidebar: { enabled: true } }` | Schema slug is `beoordeeling` (legacy spelling preserved from the register). |
| `ReviewDetail` | `detail` | `/reviews/:id` | `beoordeeling` | `{ register, schema, sidebarTabs: [overview, audit] }` | Standard detail. |
| `Komplianties` | `index` | `/komplianties` | `compliancy` | `{ register, schema: "compliancy", columns: ["naam","standaard","status","datum"], sidebar: { enabled: true } }` | Standard schema-driven list. |
| `KompliantieDetail` | `detail` | `/komplianties/:id` | `compliancy` | `{ register, schema, sidebarTabs: [overview, audit] }` | Standard detail. |
| `Moduleversies` | `index` | `/moduleversies` | `moduleVersie` | `{ register, schema: "moduleVersie", columns: ["versie","module","releaseDatum","status"], sidebar: { enabled: true } }` | Schema slug is camelCase `moduleVersie` (legacy preserved from register). |
| `Settings` | `settings` | `/settings` | — | `{ saveEndpoint: "/index.php/apps/softwarecatalog/api/settings", sections: [{ title: "Algemeen", widgets: [{ type: "custom", component: "SoftwareCatalogSettingsPage" }] }] }` | Single rich-section that delegates to the existing `SoftwareCatalogSettings.vue` via `customComponents`. Future: split into per-tab sections once the lib's settings orchestration matures. |

Final tally: **6 index + 6 detail + 1 dashboard + 1 settings + 1
custom = 15 pages**.

NOTE: the manifest's top-level `menu[]` exposes only the user-visible
list-page entries (Dashboard, Organisaties, Contactpersonen,
Contracten, Standaarden, Reviews, Komplianties, Moduleversies,
Settings, Documentation external link). The detail pages are reached
via row-click navigation from their corresponding index page; they
do NOT appear in the menu (mirrors decidesk).

## Sidebar tab inventory

Detail pages declare a minimal abstract-sidebar tab inventory:
`overview` (built-in `data` + `metadata` widgets) + `audit`
(built-in `audit-trail` widget). No per-schema custom tabs in this
change — the `voorzieningen` schemas are mostly leaf entities (no
cross-schema relations a la decidesk's
`MotionAmendmentsTab`/`MotionVotesTab`). Cross-schema tabs are a
follow-up if the schema reveals natural relations (e.g.
`Contract → Contactpersoon` cardholders).

## Dashboard widget inventory

`Dashboard` config sketch:

```json
{
  "widgets": [
    {
      "id": "info-box",
      "type": "custom",
      "title": "Beheer Informatie",
      "props": { "component": "OrganisatieInfoBox" }
    },
    {
      "id": "stats-table-1",
      "type": "custom",
      "title": "Object Statistieken (1)",
      "props": { "component": "ObjectStatsTable", "half": "first" }
    },
    {
      "id": "stats-table-2",
      "type": "custom",
      "title": "Object Statistieken (2)",
      "props": { "component": "ObjectStatsTable", "half": "second" }
    }
  ],
  "layout": [
    { "id": "info-box", "widgetId": "info-box", "gridX": 0, "gridY": 0, "gridWidth": 12, "gridHeight": 2, "showTitle": false },
    { "id": "stats-table-1", "widgetId": "stats-table-1", "gridX": 0, "gridY": 2, "gridWidth": 6, "gridHeight": 4, "showTitle": false },
    { "id": "stats-table-2", "widgetId": "stats-table-2", "gridX": 6, "gridY": 2, "gridWidth": 6, "gridHeight": 4, "showTitle": false }
  ]
}
```

The three widgets all use `type: "custom"` and resolve through the
`customComponents` registry (`OrganisatieInfoBox`,
`ObjectStatsTable`). This change does NOT migrate the dashboard
widgets to a built-in `stats-block` widget — that would require
extracting the schema-stats query pattern into the lib.
Tracked as Open Question 3 below.

> **NOTE:** to keep this change scope-bounded, the three custom
> dashboard widgets are NOT extracted as standalone Vue files in
> this commit. Instead, the existing `Dashboard.vue` is preserved as
> a single custom widget (`InfoBox + StatsTable + StatsTable`)
> registered as `DashboardCustomView` and the manifest declares
> `Dashboard` as `type: "custom"` with `component: "DashboardCustomView"`.
> Migrating to declarative `widgets[]` + `layout[]` is a follow-up
> once the lib ships a schema-driven stats widget. See "Cleanup
> follow-up" below.

REVISED: Final tally with the dashboard custom-fallback:
**6 index + 6 detail + 1 settings + 2 custom (Organisaties + Dashboard)
+ 0 dashboard = 15 pages, 2 customs.**

## customComponents registry

`src/customComponents.js` exports:

- `OrganisatieIndexView` → `./views/organisaties/OrganisatieIndex.vue`
  (custom card view + AddContactpersoonModal + OrganisationModal)
- `SoftwareCatalogSettingsPage` → `./views/settings/SoftwareCatalogSettings.vue`
  (admin settings orchestration)
- `DashboardCustomView` → `./views/Dashboard.vue` (info-box + 2 stats
  tables; preserved verbatim while the lib's dashboard widget
  registry matures)

Three entries total. No detail-tab custom components in this change
(no cross-schema relations require them yet).

## `@resolve:` sentinel usage

Two register-slug sentinels:

- `@resolve:voorzieningen_register` — used in every `index` and
  `detail` page's `config.register`. Resolves to the
  `softwarecatalog.voorzieningen_register` `IAppConfig` value at
  manifest-load time. Per-tenant configurable; matches the existing
  `lib/Service/SettingsService.php` resolution pattern.
- `@resolve:amef_register_id` — reserved for future AMEF-register
  pages (`Models`, `Elements`, `Views`). NOT used in this change
  because no AMEF schemas have an existing user-visible route in the
  current shell.

Schema slugs (`organisatie`, `contactpersoon`, `contract`, …) are
stable across tenants and stay literal — only register slugs flow
through the sentinel.

## Files affected

New:
- `softwarecatalog/src/manifest.json`
- `softwarecatalog/src/customComponents.js`
- `softwarecatalog/src/router.js` (`routesFromManifest()`)
- `softwarecatalog/tests/validate-manifest.js`
- `softwarecatalog/openspec/changes/softwarecatalog-manifest-v1/{proposal,design,tasks}.md`
- `softwarecatalog/openspec/changes/softwarecatalog-manifest-v1/specs/softwarecatalog-manifest-v1/spec.md`

Modified:
- `softwarecatalog/src/main.js` — `CnAppRoot` bootstrap
- `softwarecatalog/src/App.vue` — `<CnAppRoot>` shell
- `softwarecatalog/package.json` — `@conduction/nextcloud-vue@^1.0.0-beta.12`
- `softwarecatalog/webpack.config.js` — `@nextcloud/axios$` alias
- `softwarecatalog/appinfo/info.xml` — `<version>` 0.1.140 → 0.2.0
- `softwarecatalog/l10n/en_US.json` — mirror of `en.json`

Deleted:
- `softwarecatalog/src/views/Views.vue`
- `softwarecatalog/src/views/ObjectIndex.vue`
- `softwarecatalog/src/views/dashboard/DashboardIndex.vue` (legacy
  nested empty page)
- `softwarecatalog/src/navigation/MainMenu.vue`

Kept (registered in customComponents.js):
- `softwarecatalog/src/views/organisaties/OrganisatieIndex.vue`
- `softwarecatalog/src/views/settings/SoftwareCatalogSettings.vue`
- `softwarecatalog/src/views/Dashboard.vue` (preserved verbatim;
  registered as `DashboardCustomView`)

Untouched:
- `softwarecatalog/src/components/**` (cards, dialogs, modals,
  sidebars, settings sub-sections — referenced from the kept
  custom-registry views)
- `softwarecatalog/src/store/**` (Pinia stores; runtime data layer
  unchanged)
- `softwarecatalog/lib/**` (PHP backend untouched; scope =
  frontend-only manifest adoption)

## Cleanup follow-up

Tracked but DEFERRED to a future change:

1. Extract the dashboard's info-box and 2 stats tables into
   standalone widget components (`OrganisatieInfoBox`,
   `ObjectStatsTable`) and migrate `Dashboard` from `type: "custom"`
   to `type: "dashboard"` with declarative `widgets[]`. Blocked on
   either (a) extracting them or (b) the lib shipping a generic
   schema-stats widget.
2. Migrate Organisaties from `type: "custom"` to `type: "index"`
   with a `cardComponent` config field. Blocked on the lib growing
   `cardComponent` support on `CnIndexPage` driven from manifest.
3. Migrate Settings from `type: "settings" + custom-component
   fallback` to fully declarative `widgets[]` rich sections. Blocked
   on the lib's settings orchestration maturing to cover ArchiMate
   import/export status polling and 8-tab sub-section navigation.
4. AMEF-register pages (`Models`, `Elements`, `Views`) — register
   slug already configured via `@resolve:amef_register_id`, but no
   user-visible routes today. Add when the AMEF UX is designed.

## Citations

- **Library schema**:
  `@conduction/nextcloud-vue@1.0.0-beta.12` →
  `node_modules/@conduction/nextcloud-vue/src/schemas/app-manifest.schema.json`
- **Library renderer parent contract**:
  `@conduction/nextcloud-vue` `CnAppRoot` + `CnPageRenderer` (see
  the lib's CLAUDE.md "JSON Manifest Renderer" section).
- **Reference migration (full Tier 4 + cleanup)**:
  - Pull request: https://github.com/ConductionNL/decidesk/pull/160
  - Spec: `decidesk/openspec/changes/decidesk-manifest-v1/`
  - Reference commits:
    - `b5c88cd2` — manifest write
    - `4b49bca1` — validator script
    - `9494e546` — TODO markers on obsolete views
    - `ed34703c` — `CnAppRoot` shell + customComponents
    - `fdfb036f` — Settings rich-sections migration
    - `50e4df7c` — mount-survivable `tryLoadTranslations()`
    - `866ff132` — sidebar tab implementations (not applicable
      here; SoftwareCatalog has no cross-schema tabs in v1)
- **`@resolve:` sentinel**:
  `nextcloud-vue/openspec/changes/manifest-resolve-sentinel/proposal.md`
- **Cross-app convention**:
  `hydra/openspec/architecture/adr-024-app-manifest.md`
- **Sibling change (broader OR adoption)**:
  `softwarecatalog/openspec/changes/softwarecatalog-adopt-or-abstractions/`
  — this `softwarecatalog-manifest-v1` ships Phase 1 (manifest
  pilot) at Tier 4. Phases 2-5 (resolver consumption, i18n,
  multi-tenancy) stay parked.

## Out of scope

- Multi-tenancy, i18n, resolver consumer wiring (separate changes).
- Backend `/api/manifest` endpoint.
- New page types beyond the eight currently in the closed enum.
- The VNG `Softwarecatalogus/` client repo (read-only per project
  memory).

## Open questions

1. **Settings rich-section orchestration.** The lib's
   `manifest-settings-rich-sections` change shipped widget rendering
   inside `sections[].widgets[]`, but does NOT cover the multi-tab
   navigation pattern softwarecatalog's `SoftwareCatalogSettings.vue`
   uses (8 sub-sections, ArchiMate status polling, register
   selector). For v1 the Settings page declares `type: "settings"`
   with a single section that delegates to the
   `SoftwareCatalogSettingsPage` custom component. Splitting into
   declarative sub-section widgets is a follow-up.
2. **`cardComponent` config field on `type: "index"`.** The lib's
   `CnIndexPage` exposes a `#card` slot but the manifest cannot
   reference a `customComponents` entry to fill it. Until that
   gap is closed, the Organisaties page stays `type: "custom"` to
   preserve the bespoke `OrganisatieCard` + add-contactpersoon
   modal flow.
3. **Dashboard widget extraction.** `Dashboard.vue`'s info-box and 2
   stats tables are bespoke Vue templates that don't fit any
   built-in widget type. Extracting them into reusable widget
   components is a follow-up. For v1 the Dashboard page stays
   `type: "custom"` to preserve the existing visuals.
