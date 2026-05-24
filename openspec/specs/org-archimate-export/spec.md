---
status: implemented
---

# Organization-Specific ArchiMate Export Specification

## Purpose
Defines how the softwarecatalog app exports an organization-enriched ArchiMate (AMEFF) XML file that includes the base GEMMA model plus the organization's applications plotted on referentiecomponenten, with proper folder structure, naming, and metadata. Supports toggling data layers (modules, deelnames, gebruik) via query parameters and organises output into typed folders. The exported file is designed to import cleanly into Archi (the open-source ArchiMate modelling tool) and other AMEFF-compatible tools.

## Context
Organizations using the softwarecatalog map their applications to GEMMA referentiecomponenten. This export feature lets them download a complete ArchiMate model that includes both the national GEMMA standard and their organization-specific application landscape. The exported XML follows the ArchiMate Model Exchange File Format (AMEFF) specification and can be opened in tools like Archi for further analysis, reporting, and architecture governance.

**Relation to existing specs:**
- `view-enrichment-api`: Uses similar module-to-referentiecomponent matching logic, but this spec outputs XML instead of JSON
- `deelnames-gebruik`: Provides deelnames data that can optionally be included in the export
- `module-overlay-rendering`: The visual equivalent of what this spec produces in XML form

**Technical foundation:**
- Output format: ArchiMate Model Exchange File Format (AMEFF) XML
- Base model: VNG GEMMA reference architecture (imported from official AMEFF file)
- Added elements: ApplicationComponent elements for organization modules
- Added relationships: SpecializationRelationship linking modules to referentiecomponenten
- Added views: Copies of qualifying GEMMA views with module nodes plotted

## Requirements

### Requirement: Export MUST produce valid ArchiMate XML with organization applications
The organization export MUST generate a valid AMEFF XML file that includes all base GEMMA objects plus synthesized application elements, specialization relationships, enriched view copies, and organization folder structure.

#### Scenario: Organization with mapped applications exports successfully
- GIVEN an organization "Zeist" with 10 modules mapped to referentiecomponenten
- WHEN the organization export is requested for "Zeist"
- THEN the response MUST be a valid ArchiMate XML file
- AND the file MUST contain all base GEMMA elements, relationships, and property definitions
- AND the file MUST contain 10 additional `<element>` entries for the applications
- AND the file MUST contain specialization relationships linking each application to its referentiecomponent
- AND the file MUST import into Archi without errors

#### Scenario: Organization with no mapped applications
- GIVEN an organization "EmptyOrg" with 0 modules mapped to referentiecomponenten
- WHEN the organization export is requested for "EmptyOrg"
- THEN the response MUST be a valid ArchiMate XML file containing only the base GEMMA objects
- AND no SWC-specific elements, relationships, or view copies MUST be added
- AND no error MUST be returned

#### Scenario: Export preserves all base GEMMA data
- GIVEN the base GEMMA model with 2000 elements and 1500 relationships
- WHEN any organization export is generated
- THEN all 2000 base elements MUST be present in the output
- AND all 1500 base relationships MUST be present
- AND all base views, property definitions, and organization folders MUST be preserved
- AND no base GEMMA data MUST be modified or omitted

#### Scenario: Export XML is well-formed and schema-valid
- GIVEN any organization export
- WHEN the XML output is validated
- THEN it MUST be well-formed XML (parseable without errors)
- AND it MUST conform to the ArchiMate 3.x AMEFF XML schema
- AND the XML declaration MUST specify UTF-8 encoding

#### Scenario: Large organization export completes within timeout
- GIVEN an organization with 200 modules mapped to referentiecomponenten across 50 views
- WHEN the export is requested
- THEN the export MUST complete within 30 seconds
- AND the response MUST be streamed (not buffered entirely in memory) for files over 10MB

### Requirement: Application elements MUST be ApplicationComponent type with Bron property
Each organization application MUST be exported as an ArchiMate `<element>` with `xsi:type="ApplicationComponent"`, a unique identifier, and a `Bron=Softwarecatalogus` property.

#### Scenario: Application element has correct structure
- GIVEN a module "Topdesk" belonging to organization "Zeist"
- WHEN the organization export is generated
- THEN the XML MUST contain an element like `<element identifier="id-swc-app-{uuid}" xsi:type="ApplicationComponent">`
- AND the element MUST have a `<name>` child with value "Topdesk"
- AND the element MUST have a `<properties>` section with `Bron` property set to "Softwarecatalogus"

#### Scenario: Application element has unique SWC identifier
- GIVEN two modules "Topdesk" and "Key2Financien"
- WHEN the organization export is generated
- THEN each application element MUST have a unique `identifier` attribute prefixed with `id-swc-app-`
- AND the identifiers MUST NOT collide with any existing GEMMA element identifiers

#### Scenario: Application element identifier is deterministic
- GIVEN a module "Topdesk" with UUID "abc-123"
- WHEN the export is generated twice
- THEN the element identifier MUST be the same both times (e.g., `id-swc-app-abc-123`)
- AND importing the same export twice into Archi MUST not create duplicate elements

#### Scenario: Application element name handles special XML characters
- GIVEN a module named "R&D Tool <v2>"
- WHEN the organization export is generated
- THEN the `<name>` element MUST properly escape XML special characters
- AND the output MUST contain `R&amp;D Tool &lt;v2&gt;`

### Requirement: SpecializationRelationship MUST link applications to referentiecomponenten
Each application-to-referentiecomponent mapping MUST produce a `<relationship>` of type `SpecializationRelationship` in the export.

#### Scenario: Application mapped to one referentiecomponent
- GIVEN module "Topdesk" is mapped to referentiecomponent "Zaakregistratiecomponent"
- WHEN the organization export is generated
- THEN the XML MUST contain a `<relationship xsi:type="SpecializationRelationship">` with `source` pointing to the Topdesk application element and `target` pointing to the Zaakregistratiecomponent element
- AND the relationship MUST have a unique identifier prefixed with `id-swc-rel-`
- AND the relationship MUST have the `Bron=Softwarecatalogus` property

#### Scenario: Application mapped to multiple referentiecomponenten
- GIVEN module "SAP" is mapped to 3 referentiecomponenten
- WHEN the organization export is generated
- THEN the XML MUST contain 3 separate SpecializationRelationship elements, one per mapping
- AND each relationship MUST have a unique identifier

#### Scenario: Relationship identifiers are deterministic
- GIVEN module "Topdesk" mapped to "Zaakregistratiecomponent"
- WHEN the export is generated twice
- THEN the relationship identifier MUST be identical both times
- AND the relationship MUST NOT create duplicates on re-import

#### Scenario: Relationship source and target reference valid elements
- GIVEN a module-to-referentiecomponent relationship
- WHEN the XML is validated
- THEN the `source` attribute MUST reference an existing `<element>` identifier in the same file
- AND the `target` attribute MUST reference an existing `<element>` identifier in the same file

### Requirement: Views MUST be copied with applications plotted inside referentiecomponenten
The export MUST create copies of qualifying GEMMA views and inject application nodes as children of their mapped referentiecomponent nodes.

#### Scenario: View with applications plotted on referentiecomponenten
- GIVEN a GEMMA view "BBN poster" with referentiecomponent node "Zaakregistratiecomponent"
- AND module "Topdesk" is mapped to "Zaakregistratiecomponent"
- WHEN the organization export is generated
- THEN the export MUST contain a copy of the "BBN poster" view with a new identifier prefixed with `id-swc-view-`
- AND the copied view MUST contain a child `<node>` inside the "Zaakregistratiecomponent" node with `elementRef` pointing to the "Topdesk" application element
- AND a `<connection>` element MUST be added for the specialization relationship between "Topdesk" and "Zaakregistratiecomponent"

#### Scenario: Multiple applications stacked inside one referentiecomponent
- GIVEN referentiecomponent "Zaakregistratiecomponent" has 3 mapped applications
- WHEN the organization export is generated
- THEN the referentiecomponent node MUST contain 3 child `<node>` elements
- AND each child node MUST have positioning attributes (`x`, `y`, `w`, `h`) that fit within the parent node bounds
- AND the child nodes MUST be stacked vertically without overlapping

#### Scenario: Application appears in multiple referentiecomponenten across views
- GIVEN module "Topdesk" is mapped to 2 referentiecomponenten that appear on 3 views
- WHEN the organization export is generated
- THEN each view copy MUST contain child nodes for "Topdesk" inside each occurrence of its mapped referentiecomponenten
- AND each child node MUST have a unique identifier

#### Scenario: View without any matching referentiecomponenten
- GIVEN a view that contains no referentiecomponenten with mapped applications
- WHEN the organization export is generated
- THEN the view copy MUST be included unchanged (no child nodes added)
- AND the view copy MUST still have the new identifier and name

#### Scenario: Original GEMMA views are preserved unchanged
- GIVEN the base GEMMA model contains view "BBN poster"
- WHEN the organization export is generated
- THEN the original "BBN poster" view MUST remain in the export unchanged
- AND the enriched copy MUST be a separate view with a different identifier
- AND both views MUST coexist in the XML

### Requirement: View copies MUST use Titel view SWC property for naming
Copied views MUST be named using the `Titel view SWC` property from the original view combined with the organization name.

#### Scenario: View has Titel view SWC property
- GIVEN a GEMMA view with property `Titel view SWC` = "Applicatieservices bestuur"
- AND the organization name is "Zeist"
- WHEN the organization export is generated
- THEN the copied view's `<name>` MUST be "Applicatieservices bestuur Zeist"

#### Scenario: View without Titel view SWC property
- GIVEN a GEMMA view named "BBN poster" without a `Titel view SWC` property
- AND the organization name is "Zeist"
- WHEN the organization export is generated
- THEN the copied view's `<name>` MUST fall back to the original view name plus organization name: "BBN poster Zeist"

#### Scenario: View name handles long organization names
- GIVEN a GEMMA view with property `Titel view SWC` = "Applicatieservices bestuur"
- AND the organization name is "Samenwerkingsverband Regio Eindhoven"
- WHEN the organization export is generated
- THEN the full name "Applicatieservices bestuur Samenwerkingsverband Regio Eindhoven" MUST be used
- AND the name MUST NOT be truncated

### Requirement: SWC objects MUST be organized in typed folders
All SWC-added elements MUST be placed in organisation folders within the `<organizations>` section, separated by relationship type.

#### Scenario: Organisation folders created with typed subfolders
- GIVEN an organization export for "Zeist" with modules, deelnames, and gebruik enabled
- AND Zeist has own modules, deelname modules, and relationships/views
- WHEN the XML is generated
- THEN the `<organizations>` section MUST contain a top-level item with label "Zeist"
- AND under it, a subfolder with label `Gebruikt (Softwarecatalogus)` referencing the org's own application elements
- AND a subfolder with label `Aangeboden (Softwarecatalogus)` referencing applications the org provides
- AND a subfolder with label `Deelnames (Softwarecatalogus)` referencing deelname application elements
- AND a subfolder with label `Relaties (Softwarecatalogus)` referencing all SWC relationship elements
- AND a subfolder with label `Views (Softwarecatalogus)` referencing all SWC view copies

#### Scenario: Empty folders are omitted
- GIVEN organisation "Zeist" has modules but no deelnames and no aangeboden
- WHEN the export is generated with all parameters enabled
- THEN the `Gebruikt (Softwarecatalogus)` folder MUST be present with application references
- AND the `Deelnames (Softwarecatalogus)` folder MUST NOT appear
- AND the `Aangeboden (Softwarecatalogus)` folder MUST NOT appear

#### Scenario: Only deelnames enabled produces only deelnames folder
- GIVEN organisation "Zeist" has deelname gebruik
- WHEN the export is generated with only `?deelnames=true`
- THEN only the `Deelnames (Softwarecatalogus)` folder MUST appear under the org
- AND the `Gebruikt (Softwarecatalogus)` folder MUST NOT appear

#### Scenario: Folder item references are valid
- GIVEN the `Gebruikt (Softwarecatalogus)` folder contains 10 application references
- WHEN the XML is validated
- THEN each `<item>` in the folder MUST have an `identifierRef` pointing to a valid `<element>` identifier
- AND no orphaned references MUST exist

### Requirement: File and model MUST follow naming convention
The export file name and ArchiMate model name MUST include the organization name and export date.

#### Scenario: File name includes date and organization
- GIVEN the organization name is "Zeist"
- AND the current date is 17-02-2026
- WHEN the organization export is generated
- THEN the Content-Disposition header MUST set the filename to `17-02-2026_Softwarecatalogus_AMEFF_export_Zeist.xml`

#### Scenario: Model name includes organization
- GIVEN the organization name is "Zeist"
- WHEN the organization export is generated
- THEN the root `<model>` element's `<name>` MUST be "Softwarecatalogus Zeist"

#### Scenario: File name sanitizes special characters in organization name
- GIVEN an organization name "Gemeente 's-Hertogenbosch"
- WHEN the organization export is generated
- THEN the filename MUST sanitize special characters: `17-02-2026_Softwarecatalogus_AMEFF_export_Gemeente_s-Hertogenbosch.xml`
- AND the model `<name>` MUST preserve the original name: "Softwarecatalogus Gemeente 's-Hertogenbosch"

### Requirement: API endpoint MUST accept organization UUID and return XML download
The export MUST be triggered via `GET /api/archimate/export/organization/{organizationUuid}` with the organization UUID as a path parameter and optional boolean query parameters.

#### Scenario: Valid organization UUID provided
- GIVEN a valid organization UUID "uuid-123"
- WHEN `GET /api/archimate/export/organization/uuid-123` is called
- THEN the response MUST have status 200
- AND Content-Type MUST be `application/xml`
- AND Content-Disposition MUST include `attachment; filename="..."`
- AND the body MUST contain valid ArchiMate XML

#### Scenario: Valid organization UUID with query parameters
- GIVEN a valid organization UUID "uuid-123"
- WHEN `GET /api/archimate/export/organization/uuid-123?modules=true&deelnames=true` is called
- THEN the response MUST have status 200
- AND the XML MUST include both module and deelname data

#### Scenario: Non-existent organization UUID
- GIVEN a UUID that does not match any organization
- WHEN `GET /api/archimate/export/organization/uuid-invalid` is called
- THEN the response MUST have status 404
- AND the response MUST contain error message "Organization not found"

#### Scenario: Unauthenticated request is rejected
- GIVEN no authentication credentials
- WHEN `GET /api/archimate/export/organization/uuid-123` is called
- THEN the response MUST have status 401
- AND no XML data MUST be returned

#### Scenario: Non-admin user is rejected
- GIVEN a non-admin authenticated user
- WHEN `GET /api/archimate/export/organization/uuid-123` is called
- THEN the response MUST have status 403
- AND the response MUST indicate insufficient permissions

### Requirement: Bron property definition MUST be added to the model
The export MUST include a `<propertyDefinition>` for "Bron" so that the `Bron=Softwarecatalogus` property on SWC objects references a valid definition.

#### Scenario: Bron property definition does not already exist
- GIVEN the base GEMMA model does not have a "Bron" property definition
- WHEN the organization export is generated
- THEN the XML MUST contain a `<propertyDefinition identifier="id-swc-propdef-bron">` with name "Bron" in the `<propertyDefinitions>` section

#### Scenario: Bron property definition already exists
- GIVEN the base GEMMA model already has a "Bron" property definition
- WHEN the organization export is generated
- THEN the existing property definition MUST be reused
- AND a duplicate MUST NOT be created

#### Scenario: Bron property references are valid
- GIVEN SWC elements and relationships with `Bron=Softwarecatalogus` properties
- WHEN the XML is validated
- THEN each `<property>` element's `propertyDefinitionRef` MUST point to a valid `<propertyDefinition>` identifier

### Requirement: Connection elements MUST be created for plotted applications
Each application node plotted inside a referentiecomponent MUST have a corresponding `<connection>` element in the view linking it via the specialization relationship.

#### Scenario: Connection links application node to referentiecomponent node
- GIVEN application node "Topdesk" plotted inside referentiecomponent node "Zaakregistratiecomponent"
- AND a SpecializationRelationship exists between them
- WHEN the view copy is generated
- THEN a `<connection>` element MUST be added to the view with `relationshipRef` pointing to the SpecializationRelationship identifier
- AND `source` pointing to the application node identifier
- AND `target` pointing to the referentiecomponent node identifier

#### Scenario: Connection identifiers are unique
- GIVEN 10 application nodes plotted across 3 views
- WHEN the export is generated
- THEN each `<connection>` element MUST have a unique `identifier` attribute
- AND identifiers MUST be deterministic (same on repeated exports)

#### Scenario: Connection without matching relationship is not created
- GIVEN an application node that has no SpecializationRelationship in the model
- WHEN the view copy is generated
- THEN no `<connection>` element MUST be created for this node
- AND a warning MUST be logged about the missing relationship

### Requirement: Export MUST include deelname data when deelnames parameter is enabled
When the `deelnames` query parameter is `true`, the export MUST query gebruik objects where the current organisation's UUID appears in the `deelnemers` field (with RBAC disabled) and include those applications in the output.

#### Scenario: Organisation has deelname gebruik
- GIVEN organisation "Zeist" appears in the `deelnemers` field of 5 gebruik objects owned by other organisations
- WHEN the export is requested with `?deelnames=true`
- THEN the XML MUST contain application elements for the 5 deelname modules
- AND specialization relationships MUST link each deelname application to its referentiecomponent
- AND the deelname applications MUST be placed in the `Deelnames (Softwarecatalogus)` folder

#### Scenario: Organisation has no deelname gebruik
- GIVEN organisation "EmptyDeelnames" does not appear in any other organisation's `deelnemers` field
- WHEN the export is requested with `?deelnames=true`
- THEN the XML MUST still be valid
- AND the `Deelnames (Softwarecatalogus)` folder MUST NOT appear in the output
- AND no error MUST be returned

#### Scenario: Deelnames parameter is not set
- GIVEN organisation "Zeist" has deelname gebruik
- WHEN the export is requested without the `deelnames` parameter (or `deelnames=false`)
- THEN the XML MUST NOT contain any deelname application elements
- AND no deelname query MUST be executed

#### Scenario: Deelname applications have distinct identifiers
- GIVEN organisation "Zeist" has both owned and deelname modules with the same name "Topdesk"
- WHEN the export is generated with `?modules=true&deelnames=true`
- THEN the owned "Topdesk" element MUST have identifier `id-swc-app-{owned-uuid}`
- AND the deelname "Topdesk" element MUST have identifier `id-swc-app-{deelname-uuid}`
- AND both MUST be distinct elements in the XML

### Requirement: Deelname query MUST use RBAC-disabled ObjectService search
Deelname gebruik objects are owned by other organisations. The query MUST bypass RBAC to find records where the current organisation appears in the `deelnemers` array.

#### Scenario: Deelname query filters on deelnemers field
- GIVEN organisation "Zeist" with UUID "uuid-zeist"
- WHEN the deelname query is executed
- THEN ObjectService.searchObjects MUST be called with `_rbac: false` and `_multitenancy: false`
- AND the query MUST contain `'deelnemers' => 'uuid-zeist'`
- AND the query MUST target the gebruik schema in the voorzieningen register

#### Scenario: Deelname query handles no results gracefully
- GIVEN an organisation with no deelname usage
- WHEN the deelname query returns empty results
- THEN the export MUST continue without deelname data
- AND no error MUST be logged for empty results

### Requirement: Export MUST support query parameters for toggling data layers
The GET endpoint MUST accept boolean query parameters that control which data is included in the export.

#### Scenario: All parameters enabled
- GIVEN a valid organisation UUID
- WHEN the export is requested with `?modules=true&deelnames=true&gebruik=true`
- THEN the XML MUST contain module application elements in `Gebruikt (Softwarecatalogus)` folder
- AND deelname application elements in `Deelnames (Softwarecatalogus)` folder
- AND gebruik data MUST be included
- AND all corresponding relationships and view enrichments MUST be present

#### Scenario: No parameters provided (default behavior)
- GIVEN a valid organisation UUID
- WHEN the export is requested without any query parameters
- THEN the export MUST behave as if `modules=true` (current default behavior)
- AND deelnames and gebruik data MUST NOT be included

#### Scenario: Only deelnames enabled
- GIVEN a valid organisation UUID
- WHEN the export is requested with `?deelnames=true`
- THEN the XML MUST contain only deelname application elements
- AND module elements from the organisation's own gebruik MUST NOT be included

#### Scenario: Boolean parameters accept various truthy values
- GIVEN a valid organisation UUID
- WHEN the export is requested with `?modules=1&deelnames=yes&gebruik=TRUE`
- THEN all three data layers MUST be included
- AND the API MUST treat `1`, `yes`, `true`, `TRUE` as truthy values

### Requirement: Frontend MUST provide organization export with data layer toggles
The ArchiMate settings section MUST include checkboxes for selecting which data layers to include, and trigger the export via the GET endpoint.

#### Scenario: User triggers organization export with toggles
- GIVEN the user is on the ArchiMate settings page
- AND an organization is selected in the organization dropdown
- AND the "Modules" checkbox is checked
- AND the "Deelnames" checkbox is checked
- WHEN the user clicks the "Organization Export" button
- THEN the frontend MUST call `GET /api/archimate/export/organization/{uuid}?modules=true&deelnames=true`
- AND the browser MUST download the resulting XML file

#### Scenario: No organization selected
- GIVEN the user is on the ArchiMate settings page
- AND no organization is selected in the dropdown
- WHEN the user attempts to click the export button
- THEN the button MUST be disabled or show a message requiring organization selection

#### Scenario: Default checkbox state
- GIVEN the user navigates to the ArchiMate settings page
- THEN the "Modules" checkbox MUST be checked by default
- AND the "Deelnames" checkbox MUST be unchecked by default
- AND the "Gebruik" checkbox MUST be unchecked by default

#### Scenario: Export button shows loading state during download
- GIVEN the user clicks the export button
- WHEN the download request is in progress
- THEN the export button MUST show a loading indicator (spinner or disabled state)
- AND the button MUST return to normal state when the download completes or fails

## MODIFIED Requirements

_None._

## REMOVED Requirements

_None._

## Current Implementation Status
- **Partially implemented**: The softwarecatalog app has an ArchiMate export controller, but deelnames and data layer toggles are not yet implemented.
- **Key gaps**:
  - No deelnames data layer in the export
  - No typed organization folders (Gebruikt, Aangeboden, Deelnames)
  - No frontend data layer toggle checkboxes
  - No boolean parameter parsing for truthy values
  - No file name sanitization for special characters

## Dependencies
- VNG GEMMA ArchiMate model (base AMEFF XML)
- OpenRegister ObjectService (module and gebruik data)
- `deelnames-gebruik` spec (deelnames query logic)
- Softwarecatalog app (hosts the export endpoint)
- ArchiMate 3.x AMEFF XML schema (validation target)
