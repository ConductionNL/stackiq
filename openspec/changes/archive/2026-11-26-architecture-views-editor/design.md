# Design: architecture-views-editor

Read at development 49e65cb4. Line numbers below are from that sha.

## Where it fits

| Layer | Touched | Read at |
|---|---|---|
| Register | `vng-gemma` register (`lib/Settings/softwarecatalogus_register.json:916`), schemas `view` (:5299), `element` (:4130), `relation` (:6300) | through a new fragment `lib/Settings/register.d/architecture-views.json` |
| Service | `lib/Service/ViewService.php` `getViewsFromRegister` (:232) and `getViewFromRegister` (:307) | the drawn views filter |
| Service | `lib/Service/ArchiMateExportService.php` `getObjectsFromDatabase` (:808, the read at :844) | the drawn objects filter |
| Routes | none added. `appinfo/routes.php:185-187` stays as it is | |
| Pages | new `src/manifest.d/architecture-views.json` with `Views` (index) and `ViewEditor` (custom); `src/menu-layout.json` relocations | |
| Views | new `src/views/architecture/ArchitectureViewEditor.vue`, `src/views/architecture/ViewVersionCompare.vue` | registered in `src/customComponents.js` |
| Store and utils | new `src/store/modules/architectureView.js`, `src/utils/viewGraph.js`, `src/utils/viewDiff.js` | `src/store/modules/view.js` is left alone |

The fragment is merged by `SettingsService::loadSettings` (`lib/Service/SettingsService.php:1653-1680`). `deepMergeConfig` (:7338) appends lists and replaces lists under `authorization`. So the fragment carries `components.schemas.view-version`, `components.registers.vng-gemma.schemas: ["view-version"]` and `components.registers.vng-gemma.configuration.schemas.view-version: {"magicMapping": true, "autoCreateTable": true}`, plus the new properties on `view`, `element` and `relation`. It bumps `view` to 0.0.8, `element` to 0.0.12 and `relation` to 0.0.9.

## Decisions

### D1. A drawn view is a `view` object with `origin: drawn`

A user's view is stored in the same `view` schema as the imported GEMMA views, with `origin` set to `drawn`. The import writes `origin: imported`. A view imported before this change has no `origin` and counts as imported. Every reader that must see only GEMMA content keeps views whose `origin` is empty or `imported`, so a later origin value is left out by default.

Rejected: a new schema in the `stackiq` register. `ViewService` and both ArchiMate exports read only the AMEF `view` schema, so a second store would split the views list and leave drawn views out of any later export.

### D2. Imported views open read-only, and Copy to edit makes a drawn copy

The import saves views with `@self.id` equal to the ArchiMate identifier (`lib/Service/ArchiMateImportService.php:5394`) through `saveObjects` (:1587), which updates the object with that id. An edit to an imported view would be overwritten without a word on the next GEMMA import. The editor therefore opens `origin: imported` views read-only and offers Copy to edit. The copy gets a fresh identifier `id-<uuid>`, `origin: drawn`, `status: draft` and `basedOn` set to the source view's uuid.

Rejected: editing in place with a "protected" flag the import respects. The import is 5,961 lines with three save paths (:1369, :1587, :1695), and a flag that one of them forgets fails silently.

### D3. The editor saves the shape the import writes

The editor writes `xml.viewNodes` and `xml.viewRelationships` in the import's shape: a flat node list with `viewNodeId`, `parent`, `elementRef`, `x`, `y`, `width`, `height`, `name` and `type` (:2886 to :3058), and connections with `viewRelationshipId`, `modelRelationshipId`, `sourceId`, `targetId`, `type` and `bendpoints` (:3364). It fills the required `nodes` and `connections` fields with the same lists. `ViewService::transformView` (`lib/Service/ViewService.php:1451`) and the exporter then read a drawn view the way they read an imported one.

`src/utils/viewGraph.js` maps that shape to and from `CnGraphCanvas` nodes and edges. A node with a `parent` becomes a Vue Flow child node of that parent, so a grouping keeps its children.

Rejected: storing the canvas's own JSON. Two shapes need two readers, and the export would miss drawn views.

### D4. The canvas is `CnGraphCanvas`

`CnGraphCanvas` (`@conduction/nextcloud-vue` 2.57.1, `src/components/CnGraphCanvas/CnGraphCanvas.vue`) takes `nodes`, `edges` and `readOnly`, emits a connection for pointer and keyboard input alike, and carries labelled zoom controls. The editor renders an ArchiMate element in the `node` slot: the element name, its ArchiMate type and the layer colour from Nextcloud CSS variables (ADR-003).

Rejected: a separate diagram library. ADR-012 asks for the shared components, and `CnGraphCanvas` already carries the keyboard contract (ADR-059).

### D5. A drawn connection references a `relation` object

The ArchiMate exchange format needs every relationship connection to name a relationship. When the user connects two elements with a relation type, the store looks for a `relation` object of that type between the two elements (`source`, `target` and `type`, register.json:6346 and :6376) and reuses it, or creates one with `origin: drawn`. The connection stores its id as `modelRelationshipId`.

A new element placed from the palette ("New application component", and the other ArchiMate element types) is created as an `element` object with `origin: drawn` and the ArchiMate `type`. An existing GEMMA element is placed by reference and is not changed.

### D6. Versions are explicit snapshots in `view-version`

Save version writes a `view-version` object with the view's uuid, a version number, a label, the saving user, the time and a copy of `xml.viewNodes` and `xml.viewRelationships`. Compare versions loads two snapshots, or one snapshot and the current view, and `src/utils/viewDiff.js` sorts every node and connection by id into added, removed, changed and unchanged. A node counts as changed when its name, element, parent, position, size or style differs. `ViewVersionCompare.vue` renders both sides on a read-only `CnGraphCanvas`, marks added in `--color-success`, removed in `--color-error` and changed in `--color-warning`, and lists the same result as text for screen readers.

Rejected: OpenRegister's audit trail with `CnVersionHistory`. OpenRegister replaces any changed value over 65,536 bytes with a descriptor (`openregister-ro/lib/Db/AuditTrailPayloadHelper.php:51` and :150), so the node list of a large view cannot be rebuilt from its history. And `computeObjectDiff` (`@conduction/nextcloud-vue` `src/utils/computeObjectDiff.js:141`) compares arrays by index, so one node removed from the middle reads as every later node changed. The audit trail still records who saved what, in the sidebar History tab.

### D7. Tags, status and owner filter the views list

`view.tags` is a facetable string list. `view.status` is a facetable enum `draft`, `in review`, `published`, `retired` with default `draft`. `view.origin` is facetable. The `Views` index page is a `CnIndexPage` (manifest `type: index`) over `@resolve:amef_register` and `view`, with columns name, viewpoint, status, tags and origin, the schema facets in its sidebar, and quick filters All, Mine (`{"_owner": "@me"}`), Drawn and Imported. `@me` is resolved by `@conduction/nextcloud-vue` (`src/utils/resolveFilterTokens.js:119`).

### D8. The menu gains a group, not an entry

ADR-097 caps the main menu at six entries, and stackiq has 13 after relocation. The fragment adds a group `Architecture` (order 50, no route) and `src/menu-layout.json` relocates `Standaarden` and the new `Views` entry under it. The count of top-level entries stays 13.

### D9. Drawn objects stay inside the organisation

Drawn views, elements and relations are scoped to the organisation that created them through OpenRegister multitenancy. Three readers bypass that today and each gets a filter:

- `ViewService::getViewsFromRegister` caches one list for all callers (`views_list`, :234, 30 minutes, :74). It keeps only views whose `origin` is empty or `imported`, so the cache only ever holds GEMMA views.
- `ViewService::getView` (:166) reads one view through `getViewFromRegister` (:307) with `_rbac: false` and `_multitenancy: false` (:328), so `GET /api/views/{viewId}` returns any view to any signed-in user. It answers a view whose `origin` is not empty or `imported` with a 404, the same answer as a missing view, so a uuid does not reveal that a drawn view exists.
- `ArchiMateExportService::getObjectsFromDatabase` reads with `_rbac: false` and `_multitenancy: false` (:844). The full model export keeps only objects whose `origin` is empty or `imported`.

The editor and the index read drawn views through OpenRegister's objects API, which applies RBAC and multitenancy.

### D10. One editor at a time

The editor takes OpenRegister's object lock through `useObjectLock` (`@conduction/nextcloud-vue` `src/composables/useObjectLock.js:66`) before it enters edit mode, and shows who holds the lock otherwise (ADR-033).

## Declarative versus imperative

- The view status lifecycle is declared in the fragment as `configuration.x-openregister-lifecycle` on `view`, in the shape `usage` already uses (field `status`, `initial` draft, named transitions): submit (draft to in review), publish (in review to published), rework (in review to draft), retire (published to retired), reopen (retired to draft). The `from` and `to` values are the enum values exactly, because a lifecycle whose values match no row offers no transition and raises no error (register changelog 2.4.4, register.json:7). No PHP.
- Copy to edit, Save version and relation reuse write through OpenRegister's objects API from `src/store/modules/architectureView.js`. They add no controller and no service (ADR-022, config rule "Uses OpenRegister API directly from frontend").
- The three backend filters in D9 are changes to existing readers, not new behaviour.

## Seed data

The fragment seeds a small drawn example so the Views page is not empty on a fresh install. All objects live in the `vng-gemma` register.

### Schema: `element`

| Field | Object 1 | Object 2 | Object 3 |
|---|---|---|---|
| slug | `seed-el-zaaksysteem` | `seed-el-dms` | `seed-el-zaakregistratie` |
| identifier | `id-seed-el-zaaksysteem` | `id-seed-el-dms` | `id-seed-el-zaakregistratie` |
| type | `ApplicationComponent` | `ApplicationComponent` | `ApplicationService` |
| name | Zaaksysteem | Documentbeheer | Zaakregistratie |
| origin | drawn | drawn | drawn |

### Schema: `relation`

| Field | Object 1 | Object 2 |
|---|---|---|
| slug | `seed-rel-zaak-dms` | `seed-rel-zaak-registratie` |
| type | `Flow` | `Realization` |
| source | `id-seed-el-zaaksysteem` | `id-seed-el-zaaksysteem` |
| target | `id-seed-el-dms` | `id-seed-el-zaakregistratie` |
| origin | drawn | drawn |

### Schema: `view`

| Field | Object 1 | Object 2 |
|---|---|---|
| slug | `seed-view-zaakgericht-nu` | `seed-view-zaakgericht-concept` |
| name | Zaakgericht werken, huidige situatie | Zaakgericht werken, concept |
| status | published | draft |
| tags | zaakgericht, applicatielandschap | zaakgericht |
| origin | drawn | drawn |
| nodes | the three elements | the first two elements |

### Schema: `view-version`

| Field | Object 1 |
|---|---|
| slug | `seed-view-zaakgericht-nu-v1` |
| view | uuid of `seed-view-zaakgericht-nu` |
| versionNumber | 1 |
| label | Eerste opzet |
| nodes | the first two elements, so a compare with the current view shows one addition |

## Risks

- **Nested ArchiMate nodes on Vue Flow.** Vue Flow draws a child node relative to its parent, while the import stores absolute coordinates. `viewGraph.js` converts both ways and its vitest spec round-trips an imported GEMMA view without moving a node.
- **Partial saves.** A save that creates relations and then the view can fail between the two. The store writes relations first, reuses them on a retry, and reports a failed view write without leaving the canvas.
- **Large views.** A GEMMA view with a few hundred nodes is a large object. The editor loads one view, never the whole list with nodes, and the index columns do not include `xml`.
- **Schema versions.** The fragment bumps three AMEF schema versions. The register changelog entry 2.4.4 (register.json:7) records why: a deployed version equal to or above the declared one makes the import skip, and OpenRegister compares only properties, required and authorization, never `configuration`, where the lifecycle lives.
