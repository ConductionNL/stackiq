# archimate-export Specification

## Purpose
TBD - created by archiving change retrofit-2026-05-26-archimate-export. Update Purpose after archive.
## Requirements
### Requirement: The system SHALL build an ArchiMate AMEF XML document from catalog objects (REQ-001)

`exportArchiMateXml(...)` MUST produce a complete AMEF XML document. It builds the document via `createCleanArchiMateXml(modelMetadata)`, reads source objects with `getObjectsFromDatabase(...)`, converts them through `convertFromOpenRegisterObjects(objects,schemaIdMap)`, and appends each section with `addObjectsToXml`, `addElementsToXml`, `addRelationshipsToXml`, `addViewsToXml`, `addOrganizationsToXml`, and `addPropertyDefinitionsToXml`. `arrayToXml(data,xml)` recursively serialises arbitrary array data into the XML node.

#### Scenario: REQ-001 case 1
- WHEN `exportArchiMateXml(...)` is called with catalog objects present
- THEN it MUST return a well-formed AMEF XML document containing the elements, relationships and views

#### Scenario: REQ-001 case 2
- WHEN `createCleanArchiMateXml(metadata)` is called
- THEN it MUST return an XML element seeded with the model metadata

### Requirement: The system SHALL scope an ArchiMate export to a single organisation (REQ-002)

`exportOrganizationArchiMateXml(...)` MUST produce an AMEF XML document containing only the objects belonging to the requested organisation.

#### Scenario: REQ-002 case 1
- WHEN `exportOrganizationArchiMateXml(orgUuid, ...)` is called
- THEN the returned XML MUST contain only that organisation's objects

