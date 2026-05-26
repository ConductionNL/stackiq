---
status: draft
retrofit: true
---

# Archimate Export Specification

## Purpose

Captures observed behavior of ArchiMateExportService — converting OpenRegister catalog objects into an ArchiMate Model Exchange Format (AMEF) XML document, including per-organisation scoped exports.

## ADDED Requirements

### REQ-001: The system SHALL build an ArchiMate AMEF XML document from catalog objects

`exportArchiMateXml(...)` MUST produce a complete AMEF XML document. It builds the document via `createCleanArchiMateXml(modelMetadata)`, reads source objects with `getObjectsFromDatabase(...)`, converts them through `convertFromOpenRegisterObjects(objects,schemaIdMap)`, and appends each section with `addObjectsToXml`, `addElementsToXml`, `addRelationshipsToXml`, `addViewsToXml`, `addOrganizationsToXml`, and `addPropertyDefinitionsToXml`. `arrayToXml(data,xml)` recursively serialises arbitrary array data into the XML node.

#### Scenario: REQ-001 case 1
- WHEN `exportArchiMateXml(...)` is called with catalog objects present
- THEN it MUST return a well-formed AMEF XML document containing the elements, relationships and views

#### Scenario: REQ-001 case 2
- WHEN `createCleanArchiMateXml(metadata)` is called
- THEN it MUST return an XML element seeded with the model metadata

### REQ-002: The system SHALL scope an ArchiMate export to a single organisation

`exportOrganizationArchiMateXml(...)` MUST produce an AMEF XML document containing only the objects belonging to the requested organisation.

#### Scenario: REQ-002 case 1
- WHEN `exportOrganizationArchiMateXml(orgUuid, ...)` is called
- THEN the returned XML MUST contain only that organisation's objects
