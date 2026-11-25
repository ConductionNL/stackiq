---
kind: code
depends_on:
  - architecture-views-editor
  - landscape-usage-registration
---

# Show the data model and link applications to the entities of the GGM

## Summary

A municipal information manager opens a Data model page in stackiq and finds the entities of the Gemeentelijk Gegevensmodel (GGM), each with its relations to other entities drawn around it. They record which entities an application in use holds data for, and an entity's page lists those applications. The municipality can add its own data entities with attributes and keys, and relate them with a cardinality.

## Why

This change builds two rows of the stackiq parity matrix. Both come from the Helmond architecture repository tender, https://www.tenderned.nl/aankondigingen/overzicht/398728.

- `stackiq:arch-data-model`, "Model data entities and their relations, as an entity relationship or UML diagram, next to the applications." The matrix note: "Helmond REQ54 asks for entity relationship or UML data models." BlueDolphin rates yes: "Primary keys are unique identifiers for a data object and can be used to create a relationship between data objects" (https://help.bluedolphin.io/en/articles/11967570-keys-and-relationships), with a logical data dictionary (https://help.bluedolphin.io/en/articles/11967568-logical-data-dictionary) and views of type Logical Data (https://help.bluedolphin.io/en/articles/11967483-views-button-explanation). SAP LeanIX rates partial. The lane decided build on tender demand and a core area.
- `stackiq:arch-ggm-link`, "Relate applications to the entities of the Gemeentelijk Gegevensmodel they hold data for." The matrix note: "Helmond's architecture repository tender (REQ3, REQ61) asks to relate the repository to the GGM." BlueDolphin rates partial, through a third-party route: "hiervoor gebruik je het AMEFF-bestand van het GGM uit de GEMMA-repository voor de Architectuur module van BlueDolphin" (https://github.com/Gemeente-Delft/Gemeentelijk-Gegevensmodel/blob/master/README.md). No competitor rates yes. Decided build on tender demand and a core area.

## What stackiq has today

- The GGM is already in the data after a GEMMA import. `lib/Settings/GEMMA_release.xml` defines the property `GGM-guid` (propid-6, :121153) and carries it on 503 `BusinessObject` elements, such as Formatieplaats, Werknemer and Aanvraag, and on the relationships between them. The import keeps every element type (`lib/Service/ArchiMateImportService.php:973-980`) and turns a property name into a key by lowercasing it (`convertToCamelCase`, :2256), so `GGM-guid` lands in `ggm-guid`, which the `element` schema declares (`lib/Settings/softwarecatalogus_register.json:5038`, schema at :4130).
- No page shows these entities. The only AMEF page, Standaarden (`src/manifest.json:701`), is filtered to `gemmaType` standaard.
- No application points at a data entity. `module` (register.json:6777) and `usage` (:2654) hold no such field. `usage.amefElements` holds reference component ids that `GebruikSyncService` fills (`lib/Service/GebruikSyncService.php:170-272`).
- `relation` (:6300) has `source`, `target`, `type` and `name`, and no cardinality. `element` has no attribute list.

## What this change builds

- A field `dataEntities` on `usage`: the data entities an application in use holds data for.
- A Data model index page over `element` objects of type `BusinessObject`, and a data entity page with its relations drawn around it, its attributes and the applications that hold its data.
- An attribute list with keys on data entities the municipality adds, and a cardinality on relations it draws.
- A picker for data entities on the usage page `GebruikDetail`.

## Out of scope

- Importing the GGM separately. The GEMMA release carries it, and a municipality that wants a newer GGM imports that AMEFF file through the existing ArchiMate import.
- The attributes of GGM entities. The GEMMA AMEFF file has entities and relations but no attributes, so a GGM entity shows none. Loading GGM attributes from its UML source is a later change.
- Drawing a data model freehand. The view editor from `architecture-views-editor` draws `BusinessObject` elements and their relations on a canvas; this change adds the entity pages and the fields.
- A field on the catalogue `module` that suppliers fill. See design D2.

## Risks

- A GEMMA re-import updates imported entities. The municipality's own entities carry `origin` drawn and the import never writes them (`architecture-views-editor` D2).
- An entity with many relations draws a crowded graph. The graph shows direct neighbours only, and the text list under it holds every relation.
