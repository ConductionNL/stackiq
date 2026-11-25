# data-model-and-ggm specification

**Status**: proposed
**Scope**: stackiq
**OpenSpec changes**:
- architecture-data-model-and-ggm

## Purpose

A municipality sees the entities of the Gemeentelijk Gegevensmodel (GGM) in stackiq, with their relations, and records which entities each application in use holds data for. It can add its own data entities with attributes and keys. Entities are AMEF `element` objects of type `BusinessObject`, and the link is a field on `usage`, both stored in OpenRegister (ADR-001) and shown with `CnIndexPage`, `CnDetailPage` and `CnRelationshipGraph` (ADR-012).

## ADDED Requirements

### Requirement: REQ-DMG-001 Stackiq SHALL show the data entities of the imported model on a Data model page

Stackiq SHALL offer a Data model page at `/gegevensmodel` (page `Gegevensmodel`) that lists the `element` objects of type `BusinessObject`, with columns name, GGM UML type, GEMMA type and origin, and the quick filters All, GGM and Own. It SHALL offer a data entity page at `/gegevensmodel/:id` (page `GegevensobjectDetail`) with the entity's name, documentation, GGM guid and origin. The page SHALL sit under the Architecture menu group.

#### Scenario: An information manager finds a data entity
@e2e tests/e2e/workflows/data-model.spec.ts

- **GIVEN** the seeded own data entity Melding openbare ruimte
- **WHEN** a municipal information manager opens Architecture, then Data model, chooses the quick filter Own and searches Melding
- **THEN** the list SHALL show Melding openbare ruimte with origin drawn
- **AND** opening it SHALL show its name and documentation

#### Scenario: GGM entities are listed after a GEMMA import
@e2e exclude The CI seed (tests/e2e/ci-seed.sh) imports the register but no GEMMA model; tests/validate-manifest.js asserts that the Gegevensmodel page filters element on type BusinessObject and that the quick filter GGM filters on origin imported.

- **GIVEN** a stackiq instance where a Nextcloud admin imported the GEMMA model
- **WHEN** a municipal information manager opens the Data model page and chooses the quick filter GGM
- **THEN** the list SHALL show GGM entities such as Werknemer and Aanvraag
- **AND** no application component SHALL be listed

### Requirement: REQ-DMG-002 A data entity page SHALL draw the entity's relations to other entities

The data entity page SHALL show the entity with every entity it is related to by an Association, Aggregation, Composition or Specialization relation, drawn on `CnRelationshipGraph` with the entity at the centre and each edge labelled with the relation name or type and its cardinalities. A table under the graph SHALL list the same relations as text. Choosing a node or a row SHALL open that entity's page. All colours SHALL be Nextcloud CSS variables.

#### Scenario: An information manager sees what a melding relates to
@e2e tests/e2e/workflows/data-model.spec.ts

- **GIVEN** the seeded own entities Melding openbare ruimte and Melder with the relation gedaan door
- **WHEN** a municipal information manager opens the page of Melding openbare ruimte
- **THEN** the graph SHALL show Melder connected to it with the label gedaan door, 0..* to 1
- **AND** the relation table SHALL hold the same relation as text

#### Scenario: Relations are found by identifier for imported and drawn entities
@e2e exclude A lookup detail; tests/vitest/dataEntityRelations.spec.js asserts that an imported entity, whose uuid differs from its identifier, and a drawn entity both find their relations through the identifier field.

- **GIVEN** an imported entity whose uuid is its GEMMA object id and whose identifier starts with id-
- **WHEN** its relations load
- **THEN** relations whose source or target is its identifier SHALL be found

### Requirement: REQ-DMG-003 An application in use SHALL record the data entities it holds data for

The `usage` schema SHALL have a facetable list `dataEntities` of related `element` objects, and its picker SHALL offer only elements of type `BusinessObject`. The usage page `GebruikDetail` SHALL show and edit the list. The data entity page SHALL list the usages that name it under "Applications that hold this data".

#### Scenario: An application owner links their application to a data entity
@e2e tests/e2e/workflows/data-model.spec.ts

- **GIVEN** an application owner on the page of a usage at `/gebruik/:id`
- **WHEN** they edit the usage, pick the data entity Melding openbare ruimte and save
- **THEN** the usage page SHALL show the entity under data entities
- **AND** the page of Melding openbare ruimte SHALL list the usage under "Applications that hold this data"

#### Scenario: The picker offers data entities only
@e2e exclude A schema setting; tests/Unit/Settings/DataModelRegisterShapeTest.php asserts that usage.dataEntities is a related element list with the query type=BusinessObject and is facetable.

- **GIVEN** the merged register
- **WHEN** the shape test reads `usage.dataEntities`
- **THEN** it SHALL relate to `element` filtered on type BusinessObject

### Requirement: REQ-DMG-004 A municipality SHALL add its own data entities with attributes, keys and cardinalities

The Data model page SHALL offer New data entity, which SHALL create an `element` of type `BusinessObject` with `origin` drawn and ask only for name, documentation and attributes. An attribute SHALL have a name, a data type (text, number, date, boolean or reference), a key flag and a description. The entity page SHALL list attributes with key attributes first and marked as key in text, and SHALL let an owner edit the attributes of an entity with `origin` drawn. A relation with `origin` drawn SHALL carry a source and a target cardinality of `0..1`, `1`, `0..*` or `1..*`. An imported entity SHALL show that the GGM file publishes no attributes.

#### Scenario: An information manager adds an entity with a key
@e2e tests/e2e/workflows/data-model.spec.ts

- **GIVEN** a municipal information manager on the Data model page
- **WHEN** they choose New data entity, enter the name Vergunning, add the attribute zaaknummer as a text key and save
- **THEN** the Data model page SHALL list Vergunning under the quick filter Own
- **AND** its page SHALL show zaaknummer first, marked key

#### Scenario: An imported entity cannot be given attributes
@e2e exclude A view rule; tests/vitest/dataEntityAttributes.spec.js asserts that an entity with origin imported renders no edit control and shows the notice about the GGM file.

- **GIVEN** an imported GGM entity
- **WHEN** its page renders
- **THEN** the attribute section SHALL show no edit control
- **AND** it SHALL say that the GGM file publishes no attributes
