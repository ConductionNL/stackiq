# cmdb-export-import Specification (delta: cmdb-import-mapping-view)

**Status**: in-progress
**Scope**: stackiq
**OpenSpec changes**:
- [cmdb-export-import](../../../archive/2026-10-05-cmdb-export-import/) _(archived 2026-10-05)_
- [cmdb-import-mapping-view](../../)

## Purpose

The CMDB import maps the columns of a TOPdesk export to stackiq fields through an import profile and five migration packs that OpenRegister's mapping engine executes (REQ-CMDB-005). Until now the packs were JSON files under `lib/Settings/cmdb-import/`, which an administrator could only read on the server and an app update overwrote. This delta moves the four sheet packs into OpenRegister's migration-pack store, where an administrator views and edits them, and makes the import run what is stored there; the mapping endpoint says where each pack comes from with a link to its editor, and the settings section that shows it follows in #1289 (Jira WOO-588, sub-task of WOO-586; decision 2026-10-12: mapping lives in OpenRegister).

Nextcloud OCP interfaces used: `OCP\IL10N` (messages), `OCP\IURLGenerator` (the editor link), `OCP\IAppConfig` (the stored pack ids), `OCP\Migration\IRepairStep` (seeding). OpenRegister: `Service\MigrationPackService`, `Service\MigrationPack\PackDefinitionValidator` and `Service\MigrationPack\MappingEngine`, resolved defensively.

## ADDED Requirements

### Requirement: The admin settings SHALL show the mapping the import uses (REQ-CMDB-020)

`GET /api/settings/cmdb-import/mapping` SHALL be reachable only by Nextcloud admins and SHALL require Nextcloud's CSRF token; like the import routes (REQ-CMDB-001) it SHALL NOT carry `#[AuthorizedAdminSetting]`, `#[NoAdminRequired]` or `#[NoCSRFRequired]`. It SHALL load the import profile and every pack through the same loader, store and validator the import uses (REQ-CMDB-021), and SHALL answer 200 with a flat envelope of three keys:

- `profile`: the profile's `id`, `name`, `version`, `sheets` with each sheet's `name`, `constants` and `absentColumns`, `sheetPrecedence`, `keyColumn`, `nameColumn`, `requiredColumns`, `dateColumns` (the columns the import converts from Excel dates, REQ-CMDB-005), `idColumns`, `emptyValues`, `externalKeyPrefix`, `missingRecords` and `profileFile`;
- `packs`: one entry per target in the order module, manufacturer, municipality, usage, businessOwner, each with `target`, `file`, `id`, `name`, `version`, `description`, `fieldMappings`, the write rules `defaults`, `createOnlyDefaults`, `createOnlyFields` and `neverWritten`, and where the pack comes from: `source` (`openregister` or `file`), `packId` (the OpenRegister row id, or null), `storedVersion` (or null), `shippedVersion`, `edited` (whether the stored pack differs from what stackiq seeded; null when unknown or for a file), `newerShipped` (an edited pack kept a newer shipped pack out), `editable` (false for the municipality pack), `updatePending` (an unedited stored pack that the next import run replaces with the shipped one; then `version`, `fieldMappings`, `defaults` and the other pack fields show the shipped pack and `storedVersion` what OpenRegister holds) and `editUrl` (the absolute link to the pack's editor in OpenRegister, built by Nextcloud's URL generator so an instance that needs `/index.php` gets it, or null when the pack is not stored there or the link cannot be built);
- `openRegisterPacks`: whether OpenRegister's migration-pack store is available.

Each field mapping SHALL carry `source`, `target`, `required` (a boolean) and `transform` as the pack stores it. `createOnlyDefaults` holds values the import sets on create only, `createOnlyFields` the mapped fields written on create or, on an existing object, only when the stored value is empty (a key of `createOnlyDefaults` only when it is also a mapped field), and `neverWritten` the fields the import never writes on an existing object. When the validator is missing, a pack is unreadable, invalid or breaks a rule of the import, or the store that holds the packs is gone, the endpoint SHALL answer 503 `MAPPING_UNAVAILABLE` in the import's error envelope (`success`, `error`, `message`, `details`) with the loader's reason in `details.reason` (its first line, at most 500 characters), and for a pack stored in OpenRegister its slug in `details.pack`, its row id in `details.packId` and the link to its editor in `details.editUrl` (null when the link cannot be built). Reading the mapping SHALL change nothing: it SHALL NOT seed, update or delete a pack in OpenRegister and SHALL NOT write stackiq's app config; a pack the store does not hold yet SHALL be answered from its shipped file (`source` `file`), and an unedited stored pack that a newer shipped pack would replace SHALL be answered as the import will run it, that is as the shipped pack with `updatePending` `true`, without writing anything; a stored pack of which OpenRegister reports no last-saved time is kept by the import and answered as stored. The "CMDB import" section SHALL show, per pack, its source, its version, a hint when a newer shipped pack was not applied, a hint when an update is pending (`updatePending`: the shipped version replaces the stored one at the next import), and a link "Edit in OpenRegister" when the pack has an `editUrl`; when the endpoint answers 503 for a stored pack, the error SHALL carry the same link from `details.editUrl`. Per sheet pack it SHALL also show, as text in accessible tables, the field mappings (`source`, `target`, `required`, the transform's type) and the write rules that have values (`defaults`, `createOnlyDefaults`, `createOnlyFields`, `neverWritten`). It SHALL offer no editing itself.

#### Scenario: An admin reads the mapping the import uses
@e2e tests/e2e/spec-coverage/cmdb-import-packs.spec.ts

- **GIVEN** a Nextcloud admin, with OpenRegister's migration-pack store holding the seeded packs
- **WHEN** they request `GET /api/settings/cmdb-import/mapping`
- **THEN** the endpoint SHALL answer 200 with `profile.sheets` naming "Onbeh Applicaties CMDB" and "Beheerde Applicaties CMDB", `profile.keyColumn` `APPID` and `openRegisterPacks` `true`
- **AND** `packs` SHALL hold five entries with targets module, manufacturer, municipality, usage and businessOwner
- **AND** the module, manufacturer, usage and businessOwner packs SHALL have `source` `openregister`, a `packId` and an `editUrl` ending in `/apps/openregister/migration-packs/<packId>`
- **AND** the municipality pack SHALL have `source` `file`, `editable` `false` and `editUrl` `null`
- **AND** the usage pack SHALL list a mapping from "Applicatie Status" to `status` with a `lookup` transform whose `map` sends "In productie" to "In production"

#### Scenario: A broken stored pack is reported with its name
@e2e tests/e2e/spec-coverage/cmdb-import-packs.spec.ts

- **GIVEN** the usage pack in OpenRegister maps a column to `provider`
- **WHEN** a Nextcloud admin requests the mapping
- **THEN** the endpoint SHALL answer 503 with error `MAPPING_UNAVAILABLE`
- **AND** `details.pack` SHALL be `stackiq-topdesk-usage`, `details.packId` its row id, `details.editUrl` the link to its editor ending in `/apps/openregister/migration-packs/<packId>`, and `details.reason` SHALL name the pack, its id and the broken rule on one line of at most 500 characters

#### Scenario: A user who is not a Nextcloud admin cannot read the mapping
@e2e tests/e2e/spec-coverage/cmdb-import-packs.spec.ts

- **GIVEN** a signed-in user who is not a Nextcloud admin
- **WHEN** they request `GET /api/settings/cmdb-import/mapping`
- **THEN** Nextcloud SHALL answer 403

### Requirement: The import SHALL run the mapping packs stored in OpenRegister (REQ-CMDB-021)

For the module, manufacturer, usage and business-owner targets the import SHALL use the pack stored in OpenRegister's migration-pack store under the shipped pack's slug (`stackiq-topdesk-module`, `stackiq-topdesk-manufacturer`, `stackiq-topdesk-usage`, `stackiq-topdesk-business-owner`), without the `x-stackiq` marker (REQ-CMDB-022). When the store is available and such a pack is missing, the import SHALL first seed it from the shipped file. The municipality pack maps the import options, not a sheet; it SHALL always come from its shipped file. An instance SHALL have one mapping, used for every municipality it imports.

Every pack, stored or shipped, SHALL pass OpenRegister's `PackDefinitionValidator` and the import's own rules, or the import SHALL stop with 503 `MAPPING_UNAVAILABLE` before any row is read, naming the pack (its file, or its OpenRegister slug and row id) and every rule it breaks; it SHALL NOT fall back to the shipped file. The rules are:

- the module pack SHALL map `name`, and SHALL map the profile's key column (`APPID`) to `externalNumber`, both with `required: true`; the manufacturer, municipality and business-owner packs SHALL map `name` with `required: true`;
- a pack SHALL map, and set as a default, only properties of its target schema in the register stackiq ships, fragments included (module; organisation for manufacturer and municipality; usage); every such property is allowed unless a rule below refuses it, so no list of fields is kept in code; the business-owner pack, which writes into no schema, only `name` and `role`;
- no pack SHALL map or default `@self`, `id`, `externalKey`, `provider`, `consumer`, `module`, `businessOwner`, a field the profile lists as never written, a relation or a file (a property whose definition or items carry a `$ref`, an `inversedBy`, a `writeBack`, `format: uuid`, `type: file` or a related object), a property the register hides from forms (`hideOnForm: true`, in the base register or a fragment) or calculates (an `x-openregister-calculations` entry), which other subsystems own (the service desk exchange's `serviceDeskSystem`, `serviceDeskRecordId`, `serviceDeskUrl` and `serviceDeskSyncedAt`, the calculated `suggestedTimeClassification`), a usage's `installedVersion`, `publicationDate`, `depublicationDate`, `registeredBy`, `registrationStatus`, `mergedInto`, `contactsUid`, `pki`, `xml`, an organisation's `status` or a module's `type`;
- the manufacturer and municipality packs SHALL NOT map `type` and SHALL keep the defaults as shipped: `type` `Supplier` and `Municipality`, `status` `Active`, and the manufacturer's `registeredBy` `Supplier`.

OpenRegister's store SHALL count as available only when its pack writes require a CSRF token and lock a built-in pack's slug (the class `OCA\OpenRegister\Exception\BuiltinPackSlugLockedException` exists), and none of the pack controller's write methods (create, update, destroy, import, preview) is opened to non-admins or exempted from the CSRF check (`#[NoAdminRequired]`, `#[PublicPage]`, `#[AuthorizedAdminSetting]` or `#[NoCSRFRequired]`). When OpenRegister's store is not available and no pack was ever stored there, the import SHALL use the shipped files and SHALL add one warning to the report saying so. Once the packs were stored there, a store that is not available SHALL stop the import with 503 `MAPPING_UNAVAILABLE` naming the stored packs. A pack whose stored row now carries another slug SHALL stop the import with 503 naming the row and both slugs; a pack whose row was deleted SHALL be seeded again. The import's 503 message SHALL name the OpenRegister pack and its id when one is at fault.

#### Scenario: An edit in OpenRegister is what the import runs
@e2e tests/e2e/spec-coverage/cmdb-import-packs.spec.ts

- **GIVEN** the usage pack in OpenRegister maps "In productie" to `Planned`
- **WHEN** a row with "Applicatie Status" `In productie` is imported
- **THEN** the usage SHALL have `status` `Planned`
- **AND** the report SHALL carry no warning about the mapping

#### Scenario: A stored pack that writes a field the import manages is refused
@e2e tests/e2e/spec-coverage/cmdb-import-packs.spec.ts

- **GIVEN** the usage pack in OpenRegister maps "Applicatie Status" to `provider`
- **WHEN** an import starts
- **THEN** the import SHALL answer 503 `MAPPING_UNAVAILABLE` before any row is read, with `details.pack` `stackiq-topdesk-usage` and `details.packId` its row id, and SHALL write nothing
- **AND** the mapping endpoint's reason SHALL name `stackiq-topdesk-usage`, its row id and `"provider" is set by the import and cannot be mapped`

#### Scenario: A stored module pack without the required name mapping is refused
@e2e tests/e2e/spec-coverage/cmdb-import-packs.spec.ts

- **GIVEN** the module pack in OpenRegister maps "Applicatie Naam" to `name` without `required: true`
- **WHEN** an import starts
- **THEN** the import SHALL answer 503 `MAPPING_UNAVAILABLE` with `details.pack` `stackiq-topdesk-module`
- **AND** the mapping endpoint's reason SHALL name the pack and `"name" must be mapped as required`

#### Scenario: A deleted pack is seeded again
@e2e tests/e2e/spec-coverage/cmdb-import-packs.spec.ts

- **GIVEN** an administrator deleted the business-owner pack in OpenRegister
- **WHEN** a Nextcloud admin requests the mapping
- **THEN** the business-owner pack SHALL have `source` `openregister`, a new `packId` and `edited` `false`
- **AND** OpenRegister SHALL hold `stackiq-topdesk-business-owner` again as a built-in pack

### Requirement: Stackiq SHALL seed its mapping packs into OpenRegister and keep unedited ones current (REQ-CMDB-022)

On install and on every upgrade, after its register is initialised, stackiq SHALL create each of the four sheet packs that is missing from OpenRegister's migration-pack store as a built-in pack, from the shipped file, never through OpenRegister's import (which overwrites). Each stored definition SHALL carry `x-stackiq` with `shippedVersion` and `shippedHash`: the version of the shipped pack and the sha256 of its canonical JSON (every object's keys sorted, recursively; list order kept). A stored pack whose definition without `x-stackiq` still hashes to its `shippedHash` is unedited: when the shipped pack of the release differs, stackiq SHALL replace it with the shipped pack and a new marker. An edited pack, or one without marker, SHALL NOT be changed; for an edited pack whose marker differs from the shipped pack, the upgrade SHALL warn that the shipped version was not applied, and the mapping endpoint SHALL answer `newerShipped` `true`. Stackiq SHALL record the row id of every pack it stored or found in its app config. Running the step again on an unchanged store SHALL write nothing. Seeding SHALL never throw: without OpenRegister's store, or when the store fails, it SHALL report and leave the instance as it was.

#### Scenario: The four sheet packs are stored in OpenRegister
@e2e tests/e2e/spec-coverage/cmdb-import-packs.spec.ts

- **GIVEN** stackiq is installed next to an OpenRegister with the hardened migration-pack store
- **WHEN** a Nextcloud admin lists OpenRegister's migration packs
- **THEN** the store SHALL hold `stackiq-topdesk-module`, `stackiq-topdesk-manufacturer`, `stackiq-topdesk-usage` and `stackiq-topdesk-business-owner` as built-in packs with the `x-stackiq` marker
- **AND** no municipality pack SHALL be stored

## MODIFIED Requirements

### Requirement: Field mapping SHALL be declarative and executed by OpenRegister's mapping engine (REQ-CMDB-005)

The service SHALL map each normalised row with OpenRegister's `MigrationPack\MappingEngine::mapRow()`, once per target pack: module, manufacturer, municipality, usage, business owner. The packs and the import profile SHALL ship as JSON under `lib/Settings/cmdb-import/`; the module, manufacturer, usage and business-owner packs SHALL be taken from OpenRegister's migration-pack store when it holds them (REQ-CMDB-021), the shipped files being their seed. Each pack SHALL pass OpenRegister's `PackDefinitionValidator` and the import's rules when the import starts; an invalid pack, or a missing `MappingEngine`, SHALL stop the import with 503 `MAPPING_UNAVAILABLE` before any row is read. Before mapping, the service SHALL convert the cells of the date columns from Excel serial numbers to `Y-m-d`: the profile's date columns and every column a pack maps with a `date` transform. It SHALL turn numeric id cells into strings without a decimal part, SHALL read a value the profile lists as empty for its column (`NB` in "BNN Classificatie"; dates, "End-of-Life Functioneel" included, are kept as the file has them) as empty, and SHALL add the constants of the row's sheet (`Beheer` = `Beheer geregeld: nee` or `ja`). A mapping error on a mapping marked `required` in the module pack SHALL skip the row. In the manufacturer and owner packs it SHALL mean the row has no manufacturer or no such owner, without a warning. A mapping error on any other mapping SHALL drop only that field and add a row warning naming the column and the value. The empty parts of the usage's internal note SHALL be dropped on the separator of the concat mapping that writes it. The reader SHALL keep only the columns that the profile or a pack references, and SHALL discard every other cell when it reads the row.

(Previously: the packs were read from the shipped files only, only the profile's date columns were converted, and the note was split on a fixed ` / `.)

#### Scenario: Excel serial dates are converted before mapping
@e2e exclude Pure transformation; tests/Unit/Service/Cmdb/CmdbRowNormaliserTest.php asserts the conversions below.

- **GIVEN** the "Onbeh" row of the anonymised export with "Datum" = `45111.380322627316` and "Referentie datum wijziging" = `46232.552113113423`, and the "Beheerde" row with "End-of-Life Functioneel" = `53359`
- **WHEN** the rows are normalised
- **THEN** "Datum" SHALL be `2023-07-04`, "Referentie datum wijziging" SHALL be `2026-07-29`, and "End-of-Life Functioneel" SHALL be `2046-02-01`
- **AND** "APPID" `1234` SHALL be the string `"1234"`
- **AND** "BNN Classificatie" `NB` SHALL be empty
- **AND** "End-of-Life Functioneel" `49675` SHALL be `2036-01-01`

#### Scenario: Changing a pack changes the mapping without code
@e2e exclude Configuration behaviour; tests/Unit/Service/CmdbExportImportServiceTest.php imports against an alternate module pack and against an edited stored usage pack.

- **GIVEN** the usage pack in OpenRegister is edited to add a mapping from "In productie sinds" to `startDateInProduction` with a `date` transform, and its note separator is changed to ` | `
- **WHEN** an export is imported whose row has "In productie sinds" = `45111` and "Cluster" = `B10`
- **THEN** the usage SHALL have `startDateInProduction` = `2023-07-04`
- **AND** its `interneAnnotation` SHALL be `Beheer geregeld: ja | B10`
- **AND** no PHP code SHALL have changed

#### Scenario: An unknown status value drops only that field
@e2e exclude Mapping behaviour; tests/Unit/Service/CmdbExportImportServiceTest.php asserts the row outcome and warning.

- **GIVEN** a row whose "Applicatie Status" is `Onbekende status`, which the usage pack's lookup does not contain
- **WHEN** the row is imported
- **THEN** the module and the usage SHALL be saved without a status from the export
- **AND** the row's report entry SHALL carry a warning naming column "Applicatie Status" and value `Onbekende status`

#### Scenario: The classifications map to the stackiq fields
@e2e exclude Mapping behaviour; tests/Unit/Service/CmdbExportImportServiceTest.php imports the fixture and asserts the fields.

- **GIVEN** the "Beheerde" row of the anonymised export with "Applicatiesoort" `Saas`, "BNN Classificatie" `BBN2`, "Classificatie" `Tolereren` and "End-of-Life Functioneel" `53359`
- **WHEN** it is imported
- **THEN** the module SHALL have `applicationType` = `Saas`, `cloudDienstverleningsmodel` = `["SaaS"]` and `bbnLevel` = `BBN2`
- **AND** the usage SHALL have `timeClassification` = `Tolerate` and `startDateOutPhased` = `2046-02-01`
- **AND** the "Onbeh" row's "Applicatiesoort" `Webapplicatie`, which is not a hosting model, SHALL be stored as `applicationType` and SHALL leave `cloudDienstverleningsmodel` empty without a warning
- **AND** "BNN Classificatie" `1`, `2`, `2+` SHALL be `BBN1`, `BBN2`, `BBN2+`

### Requirement: The admin settings SHALL offer a CMDB import section (REQ-CMDB-014)

Stackiq's admin settings page SHALL show a section "CMDB import", rendered by the settings page and not registered as an in-app route. The section SHALL let the admin choose an existing municipality or type the name of a new one, choose an `.xlsx` file, and start the import. While the import runs it SHALL show a progress bar and a Cancel button. Afterwards it SHALL show the summary and a report table that can be filtered by outcome. The section SHALL also show the mapping the import uses (REQ-CMDB-020), as tables: the export's sheets and columns, and per sheet pack its source, its version, a hint when a newer shipped pack was not applied, and a link "Edit in OpenRegister" when the pack has an `editUrl`; the municipality pack, which maps the import options and no column, SHALL NOT be listed. When the endpoint answers 503 for a stored pack, the error SHALL carry the same link from `details.editUrl`. The section SHALL offer no editing of the mapping itself. The sheet names in its help text SHALL come from that mapping, every sheet it names and in its order, whatever their number, with the shipped names as the fallback until the mapping has loaded or when it cannot be loaded. Every control SHALL have a visible label, and every string SHALL be translatable.

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
- **AND** a profile with one, two or three sheets SHALL give a help text and an info text that name exactly those sheets, with no sheet the profile does not name and no description tied to a sheet's position
- **AND** when the endpoint fails, it SHALL name the shipped sheets and the mapping block SHALL show the error

#### Scenario: The section lists the mapping and links each pack to its editor
@e2e tests/e2e/spec-coverage/cmdb-import-mapping.spec.ts

- **GIVEN** a Nextcloud admin on stackiq's admin settings page
- **WHEN** the mapping block of the "CMDB import" section has loaded
- **THEN** a table of the export SHALL list the sheets read and the match, name, required and date columns, and a table of the packs SHALL show each of the four sheet packs with its source and version and a link "Edit in OpenRegister" to its `editUrl`
- **AND** the municipality pack, which maps the import options and no column, SHALL NOT be listed
- **AND** when the endpoint answers 503 for a stored pack, the error SHALL show a link "Edit in OpenRegister" to its `details.editUrl`
- **AND** the block SHALL hold no input field

#### Scenario: An edited pack whose newer shipped version was not applied says so
@e2e exclude Needs a stackiq release that ships a newer pack than the one installed; tests/vitest/cmdbImportMapping.spec.js mounts the block with an edited usage pack whose `newerShipped` is true and asserts the hint, and its absence for the unedited module pack.

- **GIVEN** the mapping endpoint answers an edited usage pack with `newerShipped` `true` and `shippedVersion` `1.1.0`
- **WHEN** the mapping block renders
- **THEN** the usage row SHALL say that stackiq ships version `1.1.0`, that it was not applied because the pack was edited, and how to use the shipped version
- **AND** a row whose `newerShipped` is `false` SHALL show no such hint

## Non-Functional Requirements

- **Performance:** loading the mapping reads seven small JSON files and the register definition, and makes at most one OpenRegister lookup per sheet pack; it SHALL answer within the time an admin settings page loads. Writes happen only to seed or update a pack.
- **Security:** admin-only with CSRF, as the import routes; OpenRegister's pack writes are admin-only with CSRF, and stackiq uses no store without those guards. The import writes with OpenRegister's RBAC and multitenancy off, so every pack SHALL follow the import's rules (REQ-CMDB-021). An edit that maps a new column makes the import read that column. The answer and the reasons hold column names, field names, lookup tables, pack names and ids; no object data and no person data. A store failure is named by its exception class, never its message.
- **Reliability:** an edit is never overwritten by an upgrade and never silently replaced by the shipped files (REQ-CMDB-021, REQ-CMDB-022).
- **Accessibility:** Target WCAG 2.2 AA. The mapping block's link has a visible, descriptive label (SC 2.4.4, 4.1.2; gate `link-name`); a status hint is text, not colour alone (SC 1.4.1). New in 2.2: 2.4.11 Focus Not Obscured applies (the block is in the page flow); 2.5.7 Dragging Movements does not apply; 2.5.8 Target Size applies to the link (Nextcloud default); 3.2.6 Consistent Help does not apply; 3.3.7 Redundant Entry does not apply (nothing is entered); 3.3.8 Accessible Authentication does not apply.
- **Internationalization:** Dutch and English MUST be supported (ADR-005) for the block, the messages and the import warning.

## Acceptance Criteria

- [ ] After install, OpenRegister lists the four `stackiq-topdesk-*` packs as built-in packs.
- [ ] A Nextcloud admin edits a lookup value of the usage pack in OpenRegister, and the next CMDB import uses it.
- [ ] A pack edited to map a field the import manages makes the import answer 503 naming the pack; the mapping endpoint answers the same 503.
- [ ] Deleting the stored pack in OpenRegister makes the next import seed the shipped pack again.
- [ ] The "CMDB import" section shows per pack its source and an "Edit in OpenRegister" link that opens the pack's editor.
- [ ] A signed-in user who is not a Nextcloud admin gets 403 from the endpoint.

## Notes

- Decision 2026-10-12: the mapping lives in OpenRegister. The packs use OpenRegister's existing migration-pack store, not its Twig mapping entity: the packs are already migration packs, executed by `MappingEngine` with structural validation and row and column errors.
- One mapping per instance: OpenRegister's pack store has no organisation scope. An instance that imports for more than one municipality shares the mapping between them.
- `MigrationPackService`, `PackDefinitionValidator` and `MappingEngine` are not part of OpenRegister's `Contract` namespace; stackiq resolves them defensively.
- Deleting a stored pack means "back to the shipped pack": the next import or upgrade seeds it again.
