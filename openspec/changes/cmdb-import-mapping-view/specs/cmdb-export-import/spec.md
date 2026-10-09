# cmdb-export-import Specification (delta: cmdb-import-mapping-view)

**Status**: in-progress
**Scope**: stackiq
**OpenSpec changes**:
- [cmdb-export-import](../../../archive/2026-10-05-cmdb-export-import/) _(archived 2026-10-05)_
- [cmdb-import-mapping-view](../../)

## Purpose

The CMDB import maps the columns of a TOPdesk export to stackiq fields through an import profile and five migration packs, JSON files under `lib/Settings/cmdb-import/` that OpenRegister's mapping engine executes (REQ-CMDB-005). Until now an administrator could only learn that mapping by reading the JSON on the server. This delta adds a read-only view of it to the "CMDB import" section of the admin settings, served by an endpoint that loads the files through the same loader the import uses, so what the administrator sees is what the next import runs (Jira WOO-588, sub-task of WOO-586).

Nextcloud OCP interfaces used: `OCP\IL10N` (messages). OpenRegister: `Service\MigrationPack\PackDefinitionValidator` (through the existing `CmdbImportProfile`, guarded).

## ADDED Requirements

### Requirement: The admin settings SHALL show the mapping the import uses (REQ-CMDB-020)

`GET /api/settings/cmdb-import/mapping` SHALL be reachable only by Nextcloud admins and SHALL require Nextcloud's CSRF token; like the import routes (REQ-CMDB-001) it SHALL NOT carry `#[AuthorizedAdminSetting]`, `#[NoAdminRequired]` or `#[NoCSRFRequired]`. It SHALL load the import profile and every pack it names through the same loader and validator the import uses, and SHALL answer 200 with a flat envelope of two keys: `profile` (the profile's `id`, `name`, `version`, `sheets` with each sheet's `name`, `constants` and `absentColumns`, `sheetPrecedence`, `keyColumn`, `nameColumn`, `requiredColumns`, `dateColumns`, `idColumns`, `emptyValues`, `missingRecords` and `profileFile`) and `packs`, one entry per target in the order module, manufacturer, municipality, usage, businessOwner, each with `target`, `file`, `id`, `name`, `version`, `description` and `fieldMappings`. Each field mapping SHALL carry `source`, `target`, `required` (a boolean) and `transform` as the pack stores it: the `type`, and for `lookup` and `bool-map` the `map` and `default`, for `concat` the `fields` and `separator`, for `const` the `value`, for `date` the `sourceFormat` and `targetFormat`. The endpoint SHALL read nothing but those files and SHALL write nothing. When the validator is missing, or the profile or a pack is unreadable or invalid, it SHALL answer 503 `MAPPING_UNAVAILABLE` in the import's error envelope (`success`, `error`, `message`, `details`) with the loader's reason in `details.reason`, so the administrator who edited a file learns what is wrong without the Nextcloud log. The "CMDB import" section SHALL show the answer in a collapsible block "Mapping (read-only)": the sheets, the key, name, required and date columns, and one table per pack with the source column, the target field, whether it is required, the transformation and its details. The block SHALL show a loading state until the endpoint answers and the error with its code when it fails, and SHALL offer no editing: the mapping is changed in the files on the server, as the documentation describes.

#### Scenario: An admin reads the mapping the import uses
@e2e tests/e2e/spec-coverage/cmdb-import-mapping.spec.ts

- **GIVEN** a Nextcloud admin with the shipped profile and packs
- **WHEN** they request `GET /api/settings/cmdb-import/mapping`
- **THEN** the endpoint SHALL answer 200 with `profile.sheets` naming "Onbeh Applicaties CMDB" and "Beheerde Applicaties CMDB", `profile.keyColumn` `APPID` and `profile.requiredColumns` `APPID` and `Applicatie Naam`
- **AND** `packs` SHALL hold five entries with targets module, manufacturer, municipality, usage and businessOwner, each with its pack id, name and version
- **AND** the usage pack SHALL list a mapping from "Applicatie Status" to `status` with a `lookup` transform whose `map` sends "In productie" to "In production"

#### Scenario: The section shows one table per pack
@e2e tests/e2e/spec-coverage/cmdb-import-mapping.spec.ts

- **GIVEN** a Nextcloud admin on stackiq's admin settings page
- **WHEN** they open "Mapping (read-only)" in the "CMDB import" section
- **THEN** the section SHALL show five tables, one per pack, each named after its target and its file
- **AND** the usage table SHALL have a row for the column "Applicatie Status" with the field `status`, the transformation Lookup and the pair "In productie" to "In production"
- **AND** the block SHALL have no control that edits a mapping

#### Scenario: A broken pack is reported with its reason
@e2e exclude Breaking a shipped file on a shared instance is not done in e2e; tests/Unit/Controller/SettingsControllerCmdbImportMappingTest.php asserts the 503 and the reason, and tests/vitest/cmdbImportMapping.spec.js asserts the section's error state.

- **GIVEN** a pack file on the server that OpenRegister's validator refuses
- **WHEN** a Nextcloud admin requests the mapping
- **THEN** the endpoint SHALL answer 503 with error `MAPPING_UNAVAILABLE`
- **AND** `details.reason` SHALL name the pack file and the validator's reason
- **AND** the section SHALL show the error and the code `MAPPING_UNAVAILABLE` instead of the tables

#### Scenario: A user who is not a Nextcloud admin cannot read the mapping
@e2e tests/e2e/spec-coverage/cmdb-import-mapping.spec.ts

- **GIVEN** a signed-in user who is not a Nextcloud admin
- **WHEN** they request `GET /api/settings/cmdb-import/mapping`
- **THEN** Nextcloud SHALL answer 403
- **AND** the admin settings page that holds the section SHALL NOT be shown to them

## MODIFIED Requirements

### Requirement: The admin settings SHALL offer a CMDB import section (REQ-CMDB-014)

Stackiq's admin settings page SHALL show a section "CMDB import", rendered by the settings page and not registered as an in-app route. The section SHALL let the admin choose an existing municipality or type the name of a new one, choose an `.xlsx` file, and start the import. While the import runs it SHALL show a progress bar and a Cancel button. Afterwards it SHALL show the summary and a report table that can be filtered by outcome. The section SHALL also show the mapping the import uses in a collapsible block "Mapping (read-only)" (REQ-CMDB-020), and the sheet names in its help text SHALL come from that mapping, with the shipped names as the fallback until the mapping has loaded or when it cannot be loaded. Every control SHALL have a visible label, and every string SHALL be translatable.

(Previously: the section had no mapping block, and the sheet names in its help text were a constant in the page, which could drift from the profile on the server.)

#### Scenario: The admin runs an import from the settings page
@e2e tests/e2e/spec-coverage/cmdb-import.spec.ts

- **GIVEN** a Nextcloud admin on stackiq's admin settings page
- **WHEN** they choose "Gemeente Voorbeeldstad", choose the anonymised export and press "Import"
- **THEN** a progress bar SHALL appear while the import runs
- **AND** afterwards the summary and the report table SHALL be shown
- **AND** filtering the table on `created` SHALL show the two imported rows

#### Scenario: The sheet names in the help come from the server
@e2e tests/e2e/spec-coverage/cmdb-import-mapping.spec.ts

- **GIVEN** a Nextcloud admin on stackiq's admin settings page
- **WHEN** the mapping endpoint has answered
- **THEN** the help text under the file control SHALL name the sheets the endpoint returned
- **AND** when the endpoint fails, it SHALL name the shipped sheets and the mapping block SHALL show the error

## Non-Functional Requirements

- **Performance:** the endpoint reads six small JSON files and validates five packs; it SHALL answer within the time an admin settings page loads and SHALL be called once per page view.
- **Security:** admin-only with CSRF, as the import routes. The answer holds column names, field names and lookup tables from files that ship with the app; no object data and no person data. The loader's reason in `details.reason` names a file and a validator message, never a cell value.
- **Accessibility:** Target WCAG 2.2 AA. The block's toggle is an `NcButton` with a visible label and `aria-expanded` (SC 4.1.2; gate `button-name`), the content it controls is referenced with `aria-controls`, and each table has header cells (SC 1.3.1; gate `table-headers`). New in 2.2: 2.4.11 Focus Not Obscured applies (the tables are in the page flow, nothing sticky covers them); 2.5.7 Dragging Movements does not apply; 2.5.8 Target Size applies to the toggle (Nextcloud default); 3.2.6 Consistent Help does not apply (no help mechanism added); 3.3.7 Redundant Entry does not apply (nothing is entered); 3.3.8 Accessible Authentication does not apply.
- **Internationalization:** Dutch and English MUST be supported (ADR-005) for the block, the table headers, the target and transformation names and the error.

## Acceptance Criteria

- [ ] A Nextcloud admin opens "Mapping (read-only)" in the "CMDB import" section and sees five tables, one per pack, with the columns of the shipped packs.
- [ ] The usage table shows "Applicatie Status" mapped to `status` with its lookup values.
- [ ] With a pack file broken on the server, the block shows `MAPPING_UNAVAILABLE` with the reason and the import refuses to run with the same code.
- [ ] A signed-in user who is not a Nextcloud admin gets 403 from the endpoint.
- [ ] The help text under the file control names the sheets from the endpoint.

## Notes

- Editing stays file-based (choice B, 2026-10-09): OpenRegister has APIs for migration packs but no screen, so a stackiq editor would have been a larger change for a mapping that changes rarely. A pack edited on the server is overwritten by the next app update; lasting changes go to the app itself.
- The transform is passed through as the pack stores it, not re-shaped per type, so a transform key the engine reads is never hidden from the administrator.
