<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

# CMDB import

Imports a TOPdesk CMDB export (an Excel workbook, `.xlsx`) for one
municipality. Every application row of the export becomes, or updates:

- a **module** (the application, `schema:SoftwareApplication`);
- its manufacturer as an **organisation** of type Supplier;
- a **usage** that links the application to the municipality;
- **contact persons** of the municipality for its owners, with their identity in
  Nextcloud Contacts.

All of it is stored as OpenRegister objects in the stackiq register. Import a
newer export later and the same applications are updated, not duplicated.

Specification: [`openspec/changes/cmdb-export-import/`](https://github.com/ConductionNL/stackiq/tree/development/openspec/changes/cmdb-export-import).

## Who can import

Only Nextcloud administrators. Members of the `software-catalog-admins` group
who are not Nextcloud administrators cannot import. The section is part of
stackiq's admin settings, under **Administration settings → Stackiq →
CMDB import**.

## Before you start

The import itself only needs stackiq and OpenRegister. To see the imported
applications in the other apps, two things must be set up there. The import
does not change either of them.

**OpenCatalogi (search).** OpenCatalogi lists an application only through a
catalogue that includes the register `stackiq` and the schema `module`. In
OpenCatalogi, open the catalogue that should show the municipality's
applications and add that register and schema. A newly imported module gets
a publication date (the moment the import started), so it is listed from then
on.

**Portaliq ("Software we use").** Portaliq shows an application to a
municipality through a usage whose consumer is that municipality. The portal
account of the municipality needs the claim `stackiq.organisationId` set to
the uuid of the municipality organisation the import used. The uuid is in
the import result (the municipality line) and on the organisation's detail
page in stackiq.

## Steps

<!-- screenshot: empty CMDB import section in stackiq admin settings -->

1. Open **Administration settings → Stackiq** and scroll to **CMDB import**.
2. **Municipality.** Pick an existing organisation of type Municipality from
   the list, or type the name of a new one and press Enter. A typed name that
   matches an existing municipality (ignoring case and extra spaces) uses that
   municipality; otherwise a new organisation of type Municipality with
   status Active is created during the import.
3. **File.** Choose the TOPdesk export (`.xlsx`, at most 10 MB).
4. **Update existing records.** On by default. Turn it off to import only
   applications that are new for this municipality; rows that match an
   existing application are then reported as *skipped* with reason `exists`
   and nothing about them changes.
5. Press **Import**. A progress bar shows how many rows have been processed.
   **Cancel import** stops the import before the next row; rows that were
   already processed stay imported.

<!-- screenshot: a running import with the progress bar and the Cancel import button -->

When the import finishes, the section shows:

- the **summary**: rows read, created, updated, unchanged, skipped, failed
  and warnings;
- **warnings for the whole file**, for example an optional column that is
  missing;
- the **rows** table: sheet, row number, Middel-ID, application, outcome,
  and the reasons and warnings for that row. Filter it with **Show rows with
  outcome**. The application name links to the module in stackiq.

<!-- screenshot: a finished report with the summary and the rows table (sanitised fixture only) -->

The municipality stays selected after an import, so a second import goes to
the same organisation.

## The file

The import reads two sheets and ignores all others:

| Sheet | Rows it accepts (column "Soort") |
|---|---|
| `Invoer AIA data` | `Application Inventory` |
| `Invoer APP data` | `Applicatie` |

At least one of the two must be present. Row 1 of each sheet holds the
column names. Columns are found by name, not by position: case, surrounding
spaces and a trailing `:` or `⚡` do not matter, and the order of the columns
does not matter. Empty rows, including formatted rows below the data, are
ignored and not counted. A sheet may hold at most 10,000 rows with data.

Two columns are **required** on every source sheet that is present:
`Middel-ID` and `Naam`. Every other column is optional; when one is missing,
the import names it once in the warnings for the whole file.

Formula cells are read as the value Excel stored with them; formulas are
never recalculated. External data connections, Power Query queries and links
in the workbook are never opened. Macro-enabled workbooks (`.xlsm`), old
Excel files (`.xls`) and CSV files are not accepted.

### Columns and where they go

| Column | Goes to | Rule |
|---|---|---|
| Naam | module name | required |
| Middel-ID | module external id, and the match key | required, see [Repeat imports](#repeat-imports) |
| ICT Applicatienummer | module external number | number stored as text |
| Functionele omschrijving | module long description | |
| ICT BBN Classificatie | module BBN level | `BBN1`/`BBN 1` etc. become `BBN1`, `BBN2`, `BBN3`; another value is dropped with a warning |
| Aanmaakdatum | module external creation date | Excel date |
| Wijzigingsdatum | module external modification date | Excel date |
| Fabrikant | Supplier organisation, set as provider on the module and the usage | one organisation per name, see below |
| Status | usage status | In productie → In production, In voorraad → Planned, In ontwikkeling → Acquisition, Uit te faseren → To be phased out, Uitgefaseerd → Phased out; another value is dropped with a warning |
| ICT TIME Classificatie | usage TIME classification | Tolerate/Tolereren, Invest/Investeren, Migrate/Migreren, Eliminate/Elimineren |
| End of Life Business | usage phase-out date | Excel date |
| Eigenaar afdeling, Eigenaar cluster | usage internal annotation | joined with ` / `, written only when the usage is new or the note is empty |
| Eigenaar, Eigenaar e-mail, Eigenaar functie | usage business owner (contact person) | see [Owners](#owners) |
| FB contactpersoon 1 | usage technical owner (contact person) | see [Owners](#owners) |
| Soort | not stored | decides whether the row is imported |

Columns not in this table are not read at all. That includes Personeelsnummer,
the phone number columns, the group owner and group mailbox columns,
Configuratie coördinator, FB contactpersoon 2 and Opmerkingen.

**Manufacturers.** Names are compared after trimming, collapsing spaces and
ignoring case, so `Fabfrikant`, `Fabfrikant ` and `FABFRIKANT` are one
Supplier. An existing organisation of type Supplier with the same name is
reused. A row without a manufacturer is imported without a provider.

## Repeat imports

An application is recognised by its **Middel-ID within the municipality**.
Two municipalities can each have an `APP-00001` without colliding.

- **New Middel-ID**: a module and a usage are created. The module gets a
  publication date (the moment the import started), so OpenCatalogi lists it.
- **Known Middel-ID, values changed**: only the fields in the column table
  are updated. Everything else on the module stays as it is, for example a
  website an administrator added. The publication date and the depublication
  date are never changed: a module an administrator depublished stays
  depublished. The row is reported as *updated*.
- **Known Middel-ID, nothing changed**: nothing is saved; the row is reported
  as *unchanged*. Importing the same export twice creates nothing the second
  time.
- **Middel-ID missing from a newer export**: the application, its usage and
  its contact persons are left as they are. They are not changed, depublished
  or deleted.
- Each application keeps exactly one usage for the municipality.

Rows are **skipped** when the Middel-ID is empty (`missing Middel-ID`), when
a Middel-ID appears a second time in the same upload, also across the two
sheets (`duplicate Middel-ID in file`; the first occurrence is imported), when
"Soort" is not accepted for the sheet (`unsupported Soort "…"`), or, with
**Update existing records** off, when the application already exists
(`exists`).

Every row is processed on its own. When one row fails, for example because
OpenRegister refuses to save it, that row is reported as *failed* with the
step that failed, and the other rows are imported. Importing again completes
the failed row.

## Owners

Owners become **contact persons of the municipality**, never Nextcloud user
accounts.

- The business owner comes from "Eigenaar", "Eigenaar e-mail" and "Eigenaar
  functie" (the function is stored as the contact person's role).
- The technical owner comes from "FB contactpersoon 1".

The person's identity (name, e-mail address) is kept in **Nextcloud
Contacts**, in the first writable address book of the administrator who runs
the import, the same as every other stackiq contact. A contact is found by
e-mail address when the export has one, otherwise by an exact match on the
name, and created when neither finds one. The stackiq contact person object
only holds the link to that contact, the role and the municipality. The same
owner on several rows is one contact person.

When the Contacts app is disabled, applications and usages are still
imported; the owners are skipped and each affected row carries a warning.

The import report and the Nextcloud log never contain owner names or e-mail
addresses.

## Errors and what to do

When the file or the request cannot be imported at all, nothing is written
and the section shows the reason and the error code.

| Error code | What it means | What to do |
|---|---|---|
| `NOT_XLSX` | The file is not an Excel workbook: wrong extension, or the content is not an `.xlsx` package. | Save the export as Excel workbook (`.xlsx`). |
| `FILE_TOO_LARGE` | The file is larger than 10 MB. | Remove sheets the import does not read, or split the export. |
| `NO_FILE_UPLOADED` | No file arrived. | Choose the file again. |
| `MUNICIPALITY_REQUIRED` | No municipality was chosen. | Pick or type a municipality. |
| `MUNICIPALITY_INVALID` | The chosen organisation does not exist or is not of type Municipality. | Pick an organisation of type Municipality, or type a new name. |
| `NO_SOURCE_SHEET` | Neither `Invoer AIA data` nor `Invoer APP data` is in the workbook. | Check the sheet names; they must match exactly. |
| `MISSING_COLUMN` | A present source sheet has no `Middel-ID` or `Naam` column. The message names the sheet and the column. | Add the column to that sheet. |
| `TOO_MANY_ROWS` | A source sheet has more than 10,000 rows with data. | Split the export and import the parts one after the other. |
| `MISSING_RECORDS_UNSUPPORTED` | The request asked to mark or remove records missing from the export. Only keeping them is supported. | Not reachable from the section; reported for API callers. |
| `MAPPING_UNAVAILABLE` | OpenRegister's mapping engine is missing, or one of the mapping files is invalid. | Update OpenRegister. If you changed a mapping file, check it against the Nextcloud log. |
| `READER_UNAVAILABLE` | The Excel reader that ships with OpenRegister cannot be loaded. | Make sure OpenRegister is installed and enabled. |
| `NOT_CONFIGURED` | The stackiq register or its schemas cannot be found. | Run **Auto Configure** at the top of the stackiq admin settings. |
| `IMPORT_FAILED` | Something unexpected went wrong. | The Nextcloud log has the details. |

A message that you are not signed in, not an administrator, or that your
session expired comes from Nextcloud itself: sign in again, use an
administrator account, or reload the page.

## Adjusting the mapping

The mapping from columns to fields is not in code. It is a set of JSON files
in `lib/Settings/cmdb-import/`, executed by OpenRegister's mapping engine:

| File | What it maps |
|---|---|
| `topdesk-profile.json` | the sheets and accepted "Soort" values, the match column, the required, date and id columns, the limits, and which pack is used for which target |
| `topdesk-module.json` | a row to the module |
| `topdesk-manufacturer.json` | "Fabrikant" to the Supplier organisation |
| `topdesk-municipality.json` | a typed municipality name to a new organisation |
| `topdesk-usage.json` | a row to the usage (status and TIME lookups, dates, annotation) |
| `topdesk-business-owner.json` | the business owner columns |
| `topdesk-technical-owner.json` | the technical owner column |

Each pack has a list of `fieldMappings`, one per column: `source` (the column
name in the export), `target` (the field), optionally `required`, and a
`transform` such as `trim`, `date` or a `lookup` with a `map` of export values
to stored values. For example, to also store "Roepnaam" as the module's short
description, add to `topdesk-module.json`:

```json
{ "source": "Roepnaam", "target": "shortDescription", "transform": { "type": "trim" } }
```

To accept a new "Status" value, add it to the `map` of the status lookup in
`topdesk-usage.json`. The packs are checked by OpenRegister when an import
starts; an invalid pack stops the import with `MAPPING_UNAVAILABLE` before
any row is read. A mapping file changed on the server is overwritten by the
next app update, so propose lasting changes to the app itself.
