---
status: done
---

# archimate-export Specification

## Purpose
Exports catalog objects as a well-formed ArchiMate AMEF XML document (Open Group Exchange format), serialising elements, relationships, views, organisations, and property definitions. Supports both a full-catalog export and an export scoped to a single organisation.

@e2e exclude PHP ArchiMate export backend (Open Group Exchange XML generation, element/relationship serialisation) — XML-generation logic with no UI surface; covered by PHPUnit service tests and Newman. The export-trigger UI is covered by org-archimate-export and the fe-settings-ui settings page.

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

