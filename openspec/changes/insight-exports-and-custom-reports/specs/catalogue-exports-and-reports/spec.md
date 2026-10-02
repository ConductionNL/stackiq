# catalogue-exports-and-reports specification

**Status**: proposed
**Scope**: stackiq
**OpenSpec changes**:
- insight-exports-and-custom-reports

## Purpose

A municipal information manager takes the catalogue out of stackiq in the shape they need: the list they have filtered, their own organisation as an ArchiMate file, or a report they defined themselves. Every export reads through OpenRegister's rights, so nobody gets rows they could not read on screen.

## ADDED Requirements

### Requirement: REQ-CER-001 The catalogue list pages SHALL offer export as CSV and Excel of the rows the page shows

The pages `Contracten`, `Organisaties`, `Komplianties` and `Moduleversies` SHALL show the library's Export menu with Export as CSV and Export as Excel. Their schemas SHALL be flagged `exportable`. The file SHALL hold the rows the list shows under its page filter, active quick filter and search, and only rows the user may read.

#### Scenario: An information manager exports the compliance list
@e2e tests/e2e/spec-coverage/catalogue-exports.spec.ts

- **GIVEN** a municipal information manager on `/komplianties`
- **WHEN** they open the Export menu and choose Export as CSV
- **THEN** the browser SHALL download a CSV file
- **AND** every row in the file SHALL be a compliance record the list shows

#### Scenario: The active quick filter reaches the file
@e2e tests/e2e/spec-coverage/catalogue-exports.spec.ts

- **GIVEN** a municipal information manager on `/contracten` with the Active quick filter chosen, and the catalogue holds one active and one expired contract
- **WHEN** they choose Export as Excel
- **THEN** the file SHALL hold the active contract
- **AND** it SHALL NOT hold the expired contract

#### Scenario: Merged organisations stay out of the organisations export
@e2e tests/e2e/spec-coverage/catalogue-exports.spec.ts

- **GIVEN** an organisation with status merged, which the Organisations page hides through its page filter
- **WHEN** a municipal information manager exports `/organisaties` as CSV
- **THEN** the file SHALL NOT hold the merged organisation

### Requirement: REQ-CER-002 The Applications and Services pages SHALL export the rows the facets, the quick filter and the search leave

`FacetedCatalogIndexView` on `/modules` and `/diensten` SHALL offer Export as CSV and Export as Excel. The export SHALL call `GET /api/catalog/{schema}/export` with the current `_gf_` facet keys, the active quick filter and the search. The endpoint SHALL accept only `module` and `catalogService`, SHALL resolve the rows through `FacetService` with the caller's rights, SHALL refuse a caller outside the schema's `export` grant when the schema names one, and SHALL end the file with a row saying the set was cut when the facet ceiling was reached.

#### Scenario: An application owner exports the applications behind one reference component
@e2e tests/e2e/spec-coverage/catalogue-exports.spec.ts

- **GIVEN** an application owner on `/modules` with the reference component facet set to one component
- **WHEN** they choose Export as CSV
- **THEN** the file SHALL hold exactly the applications the list shows
- **AND** its columns SHALL be the page's columns

#### Scenario: The BBN quick filter reaches the file
@e2e tests/e2e/spec-coverage/catalogue-exports.spec.ts

- **GIVEN** a municipal information manager on `/modules` with the BBN2 quick filter chosen
- **WHEN** they choose Export as Excel
- **THEN** every row in the file SHALL be an application with BBN level BBN2

#### Scenario: Another schema is refused
@e2e exclude An API guard; tests/Unit/Controller/CatalogExportControllerTest.php asserts a 400 for any schema other than module and catalogService.

- **GIVEN** a signed-in user
- **WHEN** they call `GET /api/catalog/contactPerson/export`
- **THEN** stackiq SHALL answer 400 and send no file

#### Scenario: A narrowed export grant is honoured
@e2e exclude Needs a schema with an export grant; tests/Unit/Service/CatalogExportServiceTest.php asserts the refusal and the read fallback.

- **GIVEN** the `module` schema names `export` for the group `software-catalog-admins` only, and a user outside that group
- **WHEN** the user calls `GET /api/catalog/module/export`
- **THEN** stackiq SHALL answer 403 and send no file

### Requirement: REQ-CER-003 A member of an organisation MUST be able to export that organisation as ArchiMate from its detail page, and only that organisation

The `OrganisatieDetail` page SHALL show an Export as ArchiMate action to a user whose active organisation is the page's organisation, and to Nextcloud admins and members of `ambtenaar`. The action SHALL download the file from `GET /api/archimate/export/organization/{organizationUuid}`. That endpoint SHALL allow the same callers and SHALL refuse any other user before it starts the export.

#### Scenario: An information manager exports their own organisation
@e2e tests/e2e/spec-coverage/catalogue-exports.spec.ts

- **GIVEN** a municipal information manager whose active organisation is Gemeente Voorbeeld, on `/organisaties/:id` for Gemeente Voorbeeld
- **WHEN** they choose Export as ArchiMate
- **THEN** the browser SHALL download an ArchiMate XML file for that organisation

#### Scenario: Another organisation's export is refused
@e2e exclude An authorisation rule; tests/Unit/Service/OrganisationScopeGuardTest.php asserts refusal for a user of another organisation and access for admin and ambtenaar, and tests/Unit/Controller/SettingsControllerOrgExportTest.php asserts the 403.

- **GIVEN** a user whose active organisation is Gemeente Voorbeeld and who is not an admin or `ambtenaar`
- **WHEN** they call the export for Gemeente Anders
- **THEN** stackiq SHALL answer 403 and send no file

#### Scenario: The action is hidden on another organisation's page
@e2e tests/e2e/spec-coverage/catalogue-exports.spec.ts

- **GIVEN** a municipal information manager whose active organisation is Gemeente Voorbeeld and who is not an admin or `ambtenaar`
- **WHEN** they open the detail page of a supplier organisation
- **THEN** the Export as ArchiMate action SHALL NOT be shown

### Requirement: REQ-CER-004 A user SHALL define, run and delete their own reports over one catalogue schema

A Custom reports page at `/reports/custom`, reached from a card on the Reports page, SHALL list the user's OpenRegister export profiles for the catalogue register. The user SHALL create a report with a name, one catalogue schema, an ordered list of fields, a value mode, a format (CSV or JSON) and optional filter rows of a field and a value. Run SHALL download the file from OpenRegister's `/api/export-profiles/{id}/run`. Delete SHALL remove the profile. The page SHALL list only the current user's own profiles, also for a Nextcloud admin, to whom OpenRegister returns every profile.

#### Scenario: An information manager builds a supplier overview report
@e2e tests/e2e/spec-coverage/custom-reports.spec.ts

- **GIVEN** a municipal information manager on `/reports/custom`
- **WHEN** they create a report named Applications per supplier over Applications with the fields name, provider and licentietype in that order, format CSV, and run it
- **THEN** the browser SHALL download a CSV whose header is name, provider, licentietype in that order
- **AND** the report SHALL be listed on the page afterwards

#### Scenario: A filter narrows the report
@e2e tests/e2e/spec-coverage/custom-reports.spec.ts

- **GIVEN** a municipal information manager on `/reports/custom`
- **WHEN** they create a report over Applications with the filter bbnLevel equals BBN2 and run it
- **THEN** every row in the file SHALL be a BBN2 application

#### Scenario: Another user's reports stay private
@e2e exclude Ownership is OpenRegister's; tests/vitest/customReports.spec.js asserts the page keeps only profiles whose owner is the current user and whose register is the catalogue register.

- **GIVEN** two users who each created a report
- **WHEN** the first user opens `/reports/custom`
- **THEN** the page SHALL list only the first user's report
