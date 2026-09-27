# Design: architecture-data-model-and-ggm

Read at development 49e65cb4. Line numbers below are from that sha. The `origin` field on `element` and `relation` and the Architecture menu group come from `architecture-views-editor` (its D1 and D8). `GebruikDetail` comes from `landscape-usage-registration` (`src/manifest.d/usages.json` in its design).

## Where it fits

| Layer | Touched | Read at |
|---|---|---|
| Register | `usage` (`lib/Settings/softwarecatalogus_register.json:2654`) gains `dataEntities`; `element` (:4130) gains `attributes`; `relation` (:6300) gains `sourceCardinality` and `targetCardinality` | through a new fragment `lib/Settings/register.d/architecture-data-model-and-ggm.json` |
| Pages | new `src/manifest.d/architecture-data-model.json` with `Gegevensmodel` (index) and `GegevensobjectDetail` (detail) | |
| Pages | `GebruikDetail` in `src/manifest.d/usages.json` shows `dataEntities` in its data widget | |
| Menu | `src/menu-layout.json` relocates `Gegevensmodel` under the `Architecture` group | |
| Views | new `src/views/architecture/DataEntityRelations.vue` and `src/views/architecture/DataEntityAttributes.vue`, registered in `src/customComponents.js` | |
| Service, controller, routes | none | reads and writes go through OpenRegister's objects API |

The fragment merges through `SettingsService::loadSettings` (`lib/Service/SettingsService.php:1653-1680`, `deepMergeConfig` at :7338). It bumps `usage`, `element` and `relation`.

## Decisions

### D1. A data entity is an AMEF `element` of type `BusinessObject`

The GGM entities are already `element` objects after a GEMMA import: 503 `BusinessObject` elements in `lib/Settings/GEMMA_release.xml` carry `GGM-guid` (propid-6, :121153), and 636 Association and 158 Specialization relationships run between them. The import keeps them all (`lib/Service/ArchiMateImportService.php:973-980`) and stores the guid in `ggm-guid` (register.json:5038, through `convertToCamelCase` at :2256). A municipality's own entity is an `element` of type `BusinessObject` with `origin` drawn, so the view editor can place it next to GGM entities.

Rejected: a new `dataEntity` schema in the `stackiq` register. It would copy 503 GGM entities out of the model they belong to, and a view could no longer place them, because the editor and both exports read the AMEF schemas only.

### D2. The link lives on the application in use

`usage.dataEntities` is a list of related `element` objects, with `objectConfiguration.queryParams` `type=BusinessObject`, so the picker offers only data entities. It is facetable, so the usages index can filter on an entity.

Rejected: the field on `module`, filled by the supplier. Which data an application holds depends on how the municipality uses it: one product serves several reference components, and a municipality may keep only part of its data there. The tender asks for the municipality's own architecture repository. A supplier-declared list can follow as a suggestion when a usage is created.

### D3. The Data model pages

`Gegevensmodel` (`/gegevensmodel`) is a `CnIndexPage` (manifest `type: index`) over `@resolve:amef_register` and `element` with `filter` `{"type": "BusinessObject"}`, columns name, ggm-uml-type, gemmaType and origin, and quick filters All, GGM (`origin` imported) and Own (`origin` drawn). Its add dialog uses `createDefaults` `{"type": "BusinessObject", "origin": "drawn"}` and `includeFields` name, documentation and attributes (`@conduction/nextcloud-vue` 2.57.1 `src/components/CnIndexPage/CnIndexPage.vue`, props at :1817 and :1949), because `element` has 86 properties and the default dialog would show them all.

`GegevensobjectDetail` (`/gegevensmodel/:id`) is a `type: detail` page on the ADR-062 grid with:
- a `data` widget for name, documentation, ggm-guid, ggm-uml-type and origin,
- a body widget `DataEntityRelations` (D4),
- a body widget `DataEntityAttributes` (D5),
- an `object-list` widget `entity-usages` over `@resolve:voorzieningen_register` and `usage` with filter `{"dataEntities": "@objectId"}`, titled "Applications that hold this data", `rowRoute: GebruikDetail`.

OpenRegister filters an array property on one value with a JSON containment test (`openregister-ro/lib/Db/MagicMapper/MagicSearchHandler.php:1601`).

### D4. The relations of an entity are drawn around it

`DataEntityRelations.vue` loads the `relation` objects whose `source` or `target` is the entity's `identifier` and whose `type` is Association, Aggregation, Composition or Specialization, then the elements at the other end. It passes them to `CnRelationshipGraph` (`@conduction/nextcloud-vue` 2.57.1, `src/components/CnRelationshipGraph/CnRelationshipGraph.vue`, props `nodes`, `edges`, `layout` and `legend` at :123 to :171) with the radial layout, the entity as root, and an edge label of the relation name, its type, and the cardinalities when set. The component's colour props default to hex values (:150 to :158), so the view passes Nextcloud CSS variables for every colour (ADR-003). Under the graph a table lists every relation as text, and choosing a node or a row opens that entity's page.

Rejected: `CnGraphCanvas`. The picture is an entity and its direct neighbours, which is what the relationship graph draws; a canvas with pan and zoom suits a whole view, and the view editor already offers it.

Rejected: a UML class diagram with attribute compartments. GGM entities carry no attributes in the AMEFF file, so the compartments would be empty for 503 of them.

### D5. Attributes and keys on the municipality's own entities

`element.attributes` is a list of objects: `name` (required), `dataType` (enum text, number, date, boolean, reference), `isKey` (boolean) and `description`. `DataEntityAttributes.vue` shows them as a table with the key attributes first and marked "key" in text, and edits them for an entity with `origin` drawn. An imported entity shows "The GGM does not publish attributes in this file".

`relation.sourceCardinality` and `relation.targetCardinality` are enums `0..1`, `1`, `0..*` and `1..*`. They are set on relations with `origin` drawn and shown on the edge label.

Rejected: attributes as separate `element` objects of their own type. ArchiMate has no attribute element, and the export would write them as elements that Archi does not know how to show.

## Declarative versus imperative

- The usage to entity link is a `related-object` list, so OpenRegister keeps it in its relation index, and the entity page's application list is a manifest `object-list` with a filter. No PHP.
- The attribute list and cardinalities are schema properties. No lifecycle, notification or aggregation is added.
- `DataEntityRelations.vue` and `DataEntityAttributes.vue` are the imperative pieces: the first only reads, the second writes one property of one object.

## Seed data

`architecture-views-editor` seeds drawn elements in `vng-gemma`. This change adds two own data entities, one relation and one usage link.

### Schema: `element`

| Field | Object 1 | Object 2 |
|---|---|---|
| slug | `seed-el-melding` | `seed-el-melder` |
| identifier | `id-seed-el-melding` | `id-seed-el-melder` |
| type | `BusinessObject` | `BusinessObject` |
| name | Melding openbare ruimte | Melder |
| origin | drawn | drawn |
| attributes | meldingnummer (text, key), datum melding (date), locatie (text) | e-mailadres (text, key), naam (text) |

### Schema: `relation`

| Field | Object 1 |
|---|---|
| slug | `seed-rel-melding-melder` |
| type | `Association` |
| name | gedaan door |
| source | `id-seed-el-melding` |
| target | `id-seed-el-melder` |
| sourceCardinality | `0..*` |
| targetCardinality | `1` |
| origin | drawn |

### Schema: `usage`

The seeded usage `gebruik-topdesk-gem-leiden-deelnemers` (register.json `components.objects`) gets `dataEntities` with the uuid of `seed-el-melding`, so the entity page shows one application on a fresh install.

## Risks

- **Identifier lookups.** A relation stores ArchiMate identifiers in `source` and `target`, while an imported element's uuid is its GEMMA object id (`ArchiMateImportService.php:4794-4798`). The view queries on the entity's `identifier` field, not its uuid, and its vitest spec covers an imported and a drawn entity.
- **Two queries per open.** The relations of one entity need a query on `source` and one on `target`. Both are limited to 200 rows, and the text list says when the limit is reached.
- **Schema versions.** The fragment bumps three schemas. The register changelog entry 2.4.4 (register.json:7) records that a deployed version equal to or above the declared one makes the import skip.
