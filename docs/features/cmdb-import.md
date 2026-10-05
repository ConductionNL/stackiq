<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

# CMDB import

Imports a TOPdesk CMDB export (an Excel workbook, `.xlsx`) for one
municipality. Every application row of the two CMDB sheets becomes, or
updates:

- a **module** (the application, `schema:SoftwareApplication`);
- its vendor (the maker of the software) as an **organisation** of type
  Supplier;
- a **usage** that links the application to the municipality;
- a **contact person** of the municipality for its owner, with the identity
  in Nextcloud Contacts. Owners are never readable by the public.

All of it is stored as OpenRegister objects in the stackiq register. Import a
newer export later and the same applications are updated, not duplicated.

Specification: [`openspec/changes/cmdb-export-import/`](https://github.com/ConductionNL/stackiq/tree/development/openspec/changes/cmdb-export-import).

## Who can import

Nextcloud administrators, and members of the groups an administrator
delegated stackiq's admin settings to (**Administration settings →
Administration privileges**). Membership of `software-catalog-admins` alone
is not enough. The section is part of stackiq's admin settings, under
**Administration settings → Stackiq → CMDB import**.

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

1. Open **Administration settings → Stackiq** and scroll to **CMDB import**.
2. **Municipality.** Pick an existing organisation of type Municipality from
   the list, or type the name of a new one and press Enter. A typed name that
   matches an existing municipality (ignoring case and extra spaces) uses that
   municipality; otherwise a new organisation of type Municipality with
   status Active is created during the import.
3. **File.** Choose the TOPdesk export (`.xlsx`, at most 10 MB by default; see
   [Limits](#limits)).
4. **Update existing records.** On by default. Turn it off to import only
   applications that are new for this municipality; rows that match an
   existing application are then reported as *skipped* with reason `exists`
   and nothing about them changes.
5. Press **Import**. A progress bar shows how many rows have been processed.
   **Cancel import** stops the import before the next row; rows that were
   already processed stay imported. The section says whether the server
   accepted the cancel; one pressed before the server has started on the
   rows cannot take effect yet, and the section says so.

When the import finishes, the section shows:

- the **summary**: rows read, created, updated, unchanged, skipped, failed
  and warnings;
- **warnings for the whole file**, for example an optional column that is
  missing;
- the **rows** table: sheet, row number, APPID, application, outcome,
  and the reasons and warnings for that row. Filter it with **Show rows with
  outcome**, and sort it by clicking a column header. It shows 100 rows at a
  time; **Show 100 more rows** adds the next ones. The application name links
  to the module in stackiq.

The municipality stays selected after an import, so a second import goes to
the same organisation.

## The file

The import reads the two CMDB sheets of the export and ignores all others,
including the `Invoer` sheets they are derived from:

| Sheet | What it holds | Recorded on the usage |
|---|---|---|
| `Onbeh Applicaties CMDB` | applications **without** arranged maintenance (from the AIA export) | `Beheer geregeld: nee` |
| `Beheerde Applicaties CMDB` | applications **with** arranged maintenance (from the APP export) | `Beheer geregeld: ja` |

At least one of the two must be present. Row 1 of each sheet holds the
column names. Columns are found by name per sheet, not by position: case,
surrounding spaces and a trailing `:` or `⚡` do not matter, and the order of
the columns does not matter. A column that one sheet has and the other has
not (such as `Nickname`, only on `Beheerde Applicaties CMDB`) is optional on
the sheet that lacks it. Empty rows, including formatted rows below the data,
are ignored and not counted. A sheet may hold at most 10,000 rows with data
by default (see [Limits](#limits)).

Two columns are **required** on every CMDB sheet that is present: `APPID`
and `Applicatie Naam`. Every other column is optional; when one is missing,
the import names it once in the warnings for the whole file.

**Formulas.** The CMDB sheets are formulas that read the `Invoer` sheets.
The import reads the value Excel stored with each formula cell; formulas are
never calculated. Save the workbook in Excel before importing it, so every
formula has a stored value. A formula without a stored value is read as an
empty cell and the row carries the warning `Column "…": formula without a
cached value, read as empty`; the row is still imported. A stored `0` is what
Excel shows for a reference to an empty cell, and is read as empty too.
External data connections, Power Query queries and links in the workbook are
never opened. Macro-enabled workbooks (`.xlsm`), old Excel files (`.xls`) and
CSV files are not accepted.

**Placeholder values.** The CMDB sheets fill an empty `BNN Classificatie`
with `NB` ("niet bekend"); that is read as empty. Dates are stored as the
file has them: the CMDB's 2036-01-01 in `End-of-Life Functioneel` is imported
as 2036-01-01. The CMDB writes that date when TOPdesk has no end-of-life date,
so it is a stand-in for "no date". It is stored as the phase-out date of the
usage and shown like any real one, and the usage reads as "Phased out"
once that date is reached. A re-import with **Update existing records** on
fills the date where it was left empty before. It also replaces a different
date stored there, including one set by hand.

### Columns and where they go

| Column | Goes to | Rule |
|---|---|---|
| APPID | module external number, and the match key | required, see [Repeat imports](#repeat-imports) |
| Applicatie Naam | module name | required |
| Applicatie Code | module external id | reference only; it can change in TOPdesk, so it is not the match key |
| Roepnaam, Nickname | module short description | Roepnaam when filled, otherwise Nickname (only on `Beheerde Applicaties CMDB`) |
| Functionele Omschrijving | module long description | |
| Applicatiesoort | module application type (`applicationType`) and hosting model (`cloudDienstverleningsmodel`) | stored as is as the application type (Webapplicatie, Client/server, Saas, …); `Saas` → SaaS, `PaaS` → PaaS, `IaaS` → IaaS, `On-premise(s)` → On-premises (self-managed) also set the hosting model, any other kind leaves it empty |
| BNN Classificatie | module BBN level | `1`, `2`, `2+`, `3` (and `BBN1`/`BBN 1`/`BNN1` etc.) become `BBN1`, `BBN2`, `BBN2+`, `BBN3`; `NB` is empty; another value is dropped with a warning |
| Datum | module external creation date | Excel date |
| Referentie datum wijziging | module external modification date | Excel date |
| Vendor | Supplier organisation, set as provider on the module and the usage | one organisation per name, see below |
| Applicatie Status | usage status | In productie → In production, In voorraad → Planned, In ontwikkeling → Acquisition, Uit te faseren and Moet verwijderd worden → To be phased out, Uitgefaseerd and Verwijderd → Phased out, Besteld and Wordt getest → Acquisition, Stand-by voor continuïteit → In production; another value is dropped with a warning |
| Classificatie | usage TIME classification | Tolereren/Tolerate (also `1. Tolereren (wordt ingelezen)`), Investeren/Invest, Migreren/Migrate, Elimineren/Eliminate |
| End-of-Life Functioneel | usage phase-out date | Excel date, stored as is |
| (the sheet), Cluster, Applicatie Eigenaar (Afdeling) | usage internal annotation | `Beheer geregeld: ja` or `nee`, the cluster and the department, joined with ` / `; written only when the usage is new or the note is empty |
| Applicatie Eigenaar (Persoon), Applicatie Eigenaar (Functie) | usage business owner (contact person) | see [Owners](#owners) |

Columns not in this table are not read at all. That includes Hostingpartij
and Leverancier (not mapped yet), the BIV and value columns (Beschikbaarheid,
Integriteit, Vertrouwelijkheid, Applicatienut and the like), Behandelgroep,
Cloud, Rappeldatum, Rappelreden, Locatie BIOToets, Software Suite, Standaard,
Top5, COTS and Applicatie Nummer.

**Vendors.** Names are compared after trimming, collapsing spaces and
ignoring case, so `Fabfrikant`, `Fabfrikant ` and `FABFRIKANT` are one
Supplier. An existing organisation with the same name is reused: a
Municipality first, then a Supplier. A municipality that builds its own
applications (Vendor `Gemeente Rotterdam`) is therefore its own provider,
not a second organisation. Only a name no organisation has creates a
Supplier. A row without a vendor is imported without a provider.

## Repeat imports

An application is recognised by its **APPID within the municipality**: the
match key is `topdesk:<municipality uuid>:<APPID>`. The APPID (TOPdesk's ICT
Applicatienummer) stays the same when TOPdesk changes the Applicatie Code
(Middel-ID). Two municipalities can each have an APPID `101` without
colliding.

- **New APPID**: a module and a usage are created. The module gets a
  publication date (the moment the import started), so OpenCatalogi lists it.
- **Known APPID, values changed**: only the fields in the column table
  are updated. Everything else on the module stays as it is, for example a
  website an administrator added. The publication date and the depublication
  date are never changed: a module an administrator depublished stays
  depublished. The row is reported as *updated*.
- **Known APPID, status changed in TOPdesk**: the usage gets the new status,
  also where the regular steps of the usage lifecycle (Acquisition →
  Planned → In production → To be phased out → Phased out) do not lead
  there. The usage schema allows this jump to administrators only, so the
  import must be run by a Nextcloud administrator; a delegated stackiq
  admin who is not one gets the row reported as *failed*. Other users
  still follow the regular steps.
- **Known APPID, nothing changed**: nothing is saved; the row is reported
  as *unchanged*. Importing the same export twice creates nothing the second
  time.
- **APPID missing from a newer export**: the application, its usage and
  its contact persons are left as they are. They are not changed, depublished
  or deleted.
- Each application keeps exactly one usage for the municipality.

Rows are **skipped** when the APPID is empty (`missing APPID`), when the
Applicatie Naam is empty (`missing Applicatie Naam`), when an APPID appears a
second time in the same upload (`duplicate APPID in file`), or, with **Update
existing records** off, when the application already exists (`exists`). On
one sheet the first occurrence is imported. When an APPID is on both sheets,
the `Beheerde Applicaties CMDB` row is imported and the `Onbeh Applicaties
CMDB` row is skipped, with a warning naming the APPID.

An application that moves from `Onbeh Applicaties CMDB` to `Beheerde
Applicaties CMDB` keeps its module and usage (same APPID); its internal note
is not rewritten when it already has one.

Every row is processed on its own. When one row fails, for example because
OpenRegister refuses to save it, that row is reported as *failed* with the
step that failed, and the other rows are imported. Importing again completes
the failed row.

## Owners

The owner becomes a **contact person of the municipality**, never a
Nextcloud user account. It comes from `Applicatie Eigenaar (Persoon)`; its
function (`Applicatie Eigenaar (Functie)`) is stored as the contact person's
role, and the department (`Applicatie Eigenaar (Afdeling)`) goes into the
usage's internal note. When TOPdesk has no owner, the CMDB sheet shows the
owner's function in the person column; the import then uses that function as
the contact's name. No technical owner is imported: the functional
administrator (FB contactpersoon) is not read.

The identity is kept in **Nextcloud Contacts**, in the first writable
address book of the administrator who runs the import, the same as every
other stackiq contact. The CMDB sheets have no e-mail address, so a contact
is found by an exact match on the name, and created when there is none. The
stackiq contact person object only holds the link to that contact, the role
and the municipality. The same owner on several rows is one contact person.

When the Contacts app is disabled, applications and usages are still
imported; the owners are skipped and each affected row carries a warning.

**Never public.** Contact persons and usages have no public read rule, so an
anonymous visitor cannot read them through OpenRegister, and a published
module in an OpenCatalogi search result refers to them by id at most. The
import report and the Nextcloud log never contain owner names.

## Errors and what to do

When the file or the request cannot be imported at all, nothing is written
and the section shows the reason and the error code.

| Error code | What it means | What to do |
|---|---|---|
| `NOT_XLSX` | The file is not an Excel workbook: wrong extension, or the content is not an `.xlsx` package. | Save the export as Excel workbook (`.xlsx`). |
| `FILE_TOO_LARGE` | The file is larger than the upload limit (10 MB by default). The message names the limit in force. | Remove sheets the import does not read, or split the export. |
| `NO_FILE_UPLOADED` | No file arrived. | Choose the file again. |
| `MUNICIPALITY_REQUIRED` | No municipality was chosen. | Pick or type a municipality. |
| `MUNICIPALITY_INVALID` | The chosen organisation does not exist or is not of type Municipality. | Pick an organisation of type Municipality, or type a new name. |
| `NO_SOURCE_SHEET` | Neither `Onbeh Applicaties CMDB` nor `Beheerde Applicaties CMDB` is in the workbook. | Check the sheet names; they must match exactly. |
| `MISSING_COLUMN` | A present CMDB sheet has no `APPID` or `Applicatie Naam` column. The message names the sheet and the column. | Add the column to that sheet. |
| `TOO_MANY_ROWS` | A CMDB sheet has more rows with data than the row limit (10,000 by default). The message names the sheet and the limit. | Split the export and import the parts one after the other. |
| `FIELD_INVALID` | A form field of the request has a value the import does not accept, for example an `updateExisting` that is neither `true` nor `false`. The message names the field. | Not reachable from the section; reported for API callers. |
| `UPLOAD_FAILED` | The file reached the server but could not be stored there. | Try again; the Nextcloud log has the details. |
| `MISSING_RECORDS_UNSUPPORTED` | The request asked to mark or remove records missing from the export. Only keeping them is supported. | Not reachable from the section; reported for API callers. |
| `MAPPING_UNAVAILABLE` | OpenRegister's mapping engine is missing, or one of the mapping files is invalid. | Update OpenRegister. If you changed a mapping file, check it against the Nextcloud log. |
| `READER_UNAVAILABLE` | The Excel reader that ships with OpenRegister cannot be loaded. | Make sure OpenRegister is installed and enabled. |
| `NOT_CONFIGURED` | The stackiq register or its schemas cannot be found. | Run **Auto Configure** at the top of the stackiq admin settings. |
| `IMPORT_FAILED` | Something unexpected went wrong. | The Nextcloud log has the details. |

**The connection was cut off.** The import runs in one request. When that
request ends without an answer from the import (the connection drops, or a
proxy gives up waiting with a 502, 503 or 504), the import may still be
running on the server. The section then keeps following the import's
progress and shows its report when it finishes. When it cannot find out,
it shows `IMPORT_INTERRUPTED`: wait a few minutes and check the
municipality's applications before importing again. Importing the same file
again creates no duplicates.

A message that you are not signed in, may not use stackiq's admin settings,
or that your session expired comes from Nextcloud itself: sign in again, use
an account that may (see [Who can import](#who-can-import)), or reload the
page.

## Limits

Two limits are read from `lib/Settings/cmdb-import/topdesk-profile.json` on
every import:

| Setting | Default | What it limits |
|---|---|---|
| `maxFileBytes` | `10485760` (10 MB) | the size of the uploaded file |
| `maxRowsPerSheet` | `10000` | the rows with data on one CMDB sheet |

The section's help text shows the defaults; when the server refuses a file,
the message shows the limit the server applied. A larger file also has to
pass PHP's `upload_max_filesize` and `post_max_size` and the web server's
request size limit. Like the mapping files, the profile is part of the app:
a change made on the server is overwritten by the next app update.

## Adjusting the mapping

The mapping from columns to fields is not in code. It is a set of JSON files
in `lib/Settings/cmdb-import/`, executed by OpenRegister's mapping engine:

| File | What it maps |
|---|---|
| `topdesk-profile.json` | the sheets, the constant each sheet adds to its rows (`Beheer`) and the columns it is known to lack, the match column, the required, date and id columns, the placeholder values that mean empty, the limits, and which pack is used for which target |
| `topdesk-module.json` | a row to the module (hosting model and BBN lookups) |
| `topdesk-manufacturer.json` | "Vendor" to the Supplier organisation |
| `topdesk-municipality.json` | a typed municipality name to a new organisation |
| `topdesk-usage.json` | a row to the usage (status and TIME lookups, dates, annotation) |
| `topdesk-business-owner.json` | the owner columns |

Each pack has a list of `fieldMappings`, one per column: `source` (the column
name in the export), `target` (the field), optionally `required`, and a
`transform` such as `trim`, `date` or a `lookup` with a `map` of export values
to stored values. For example, to accept a new "Applicatiesoort" value, add
it to the `map` of the hosting-model lookup in `topdesk-module.json`:

```json
"Cloud": ["SaaS"]
```

To accept a new "Applicatie Status" value, add it to the `map` of the status
lookup in `topdesk-usage.json`. The packs are checked by OpenRegister when an import
starts; an invalid pack stops the import with `MAPPING_UNAVAILABLE` before
any row is read. A mapping file changed on the server is overwritten by the
next app update, so propose lasting changes to the app itself.
