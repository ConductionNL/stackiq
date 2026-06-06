# archimate-import Specification

## Purpose
TBD - created by archiving change retrofit-2026-05-26-archimate-import. Update Purpose after archive.

@e2e exclude PHP ArchiMate import backend (XML parse, element mapping, upsert into OpenRegister) — XML-processing logic with no UI surface; covered by PHPUnit service tests and Newman. The import-trigger UI is covered under fe-settings-ui's settings page.

## Requirements
### Requirement: The system SHALL parse an ArchiMate AMEF file into catalog objects (REQ-001)

`importArchiMateFileFromPath(options)` and `importArchiMateFileFromPathOptimized(options)` MUST read an AMEF XML file (via `xmlToArray(xml)`) and persist the parsed elements/relationships/views/organisations as OpenRegister objects, returning an import-result summary. `getPropertyNameMapping(propDefMap)` MUST resolve property-definition ids to their human names; `getAmefConfig()` MUST return the AMEF register/schema configuration used by the import.

#### Scenario: REQ-001 case 1
- WHEN `importArchiMateFileFromPath(options)` is called with a valid AMEF file
- THEN the parsed objects MUST be persisted and a result summary returned

#### Scenario: REQ-001 case 2
- WHEN `xmlToArray(xml)` is called
- THEN it MUST return the array representation of the AMEF XML

### Requirement: The system SHALL expose ArchiMate import/export, round-trip and operation status via a facade (REQ-002)

`ArchiMateService` is the facade: `exportToArchiMate(organization)` and `exportOrgArchiMate(orgUuid,options)` produce exports; `testRoundTrip()` exports-then-imports to validate fidelity; `getArchiMateStatus()` reports the current operation status; `isImportInProgress()`/`isExportInProgress()`/`isOperationInProgress()` report liveness. The typed query helpers (`getElementObjects`, `getOrganizationObjects`, `getViewObjects`, `getRelationshipObjects`, `getModelObjects`, `getPropertyObjects`, `getPropertyDefinitionObjects`) MUST return the matching catalog objects for the supplied query.

#### Scenario: REQ-002 case 1
- WHEN `testRoundTrip()` is called
- THEN it MUST export then re-import and report whether the model survived intact

#### Scenario: REQ-002 case 2
- WHEN `isOperationInProgress()` is called during an import
- THEN it MUST return true

