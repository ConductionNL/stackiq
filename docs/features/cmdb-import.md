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
newer export later and the same applications are updated, not duplicated;
applications that left the export are archived or moved to the trash (see
[Repeat imports](#repeat-imports)).

Specification: [`openspec/specs/cmdb-export-import/spec.md`](https://github.com/ConductionNL/stackiq/blob/development/openspec/specs/cmdb-export-import/spec.md) (change archived in [`openspec/changes/archive/2026-10-05-cmdb-export-import/`](https://github.com/ConductionNL/stackiq/tree/development/openspec/changes/archive/2026-10-05-cmdb-export-import); archiving and deleting on a repeat import in [`openspec/changes/cmdb-import-archive-reconciliation/`](https://github.com/ConductionNL/stackiq/tree/development/openspec/changes/cmdb-import-archive-reconciliation)).

## Who can import

Only Nextcloud administrators. The import writes into the catalogue of
any municipality, past OpenRegister's access rules and organisation
boundaries, so it is not open to the groups an administrator delegated
stackiq's admin settings to (**Administration settings → Administration
privileges**), nor to members of `software-catalog-admins`. The section is
part of stackiq's admin settings, under **Administration settings → Stackiq
→ CMDB import**.

## Before you start

The import itself only needs stackiq and OpenRegister. To see the imported
applications in the other apps, two things must be set up there. The import
does not change either of them.

**OpenCatalogi (search).** OpenCatalogi lists an application only through a
catalogue that includes the register `stackiq` and the schema `module`. In
OpenCatalogi, open the catalogue that should show the municipality's
applications and add that register and schema. A newly imported module gets
a publication date (the moment the import started), so it is listed from then
on, unless you turn off **Publish the applications this import creates**
(see the steps below).

**Portaliq ("Software we use").** Portaliq shows an application to a
municipality through a usage whose consumer is that municipality. The portal
account of the municipality needs the claim `stackiq.organisationId` set to
the uuid of the municipality organisation the import used. The uuid is in
the import result (the municipality line) and on the organisation's detail
page in stackiq.

## Steps

1. Open **Administration settings → Stackiq** and scroll to **CMDB import**.
2. **Municipality.** Pick an existing organisation of type Municipality from
   the list, or type the name of a new one and press Enter. The list shows
   only active municipalities, not merged or inactive ones; municipalities
   with the same name show the start of their id after the name. A typed name
   is sent to the server as a name, and a name that matches one existing
   municipality (ignoring case and extra spaces) uses that municipality; otherwise a new organisation of type Municipality with
   status Active is created during the import, and the result warns about
   it. When more than one municipality has the typed name, the import is
   refused (`MUNICIPALITY_AMBIGUOUS`): pick the right one from the list.
3. **File.** Choose the TOPdesk export (`.xlsx`, at most 10 MB by default; see
   [Limits](#limits)).
4. **Update existing records.** On by default. Turn it off to import only
   applications that are new for this municipality; rows that match an
   existing application are then reported as *skipped* with reason `exists`
   and nothing about them changes.
5. **Publish the applications this import creates.** On by default. A
   published application is visible to anyone, including anonymous visitors
   of OpenCatalogi. Turn it off to create the new applications without a
   publication date; they stay unpublished until you publish them by hand,
   and the summary counts them as *created unpublished*. Applications that
   were imported before keep their publication as it is, whichever you
   choose.
6. **Applications missing from the export.** **Archive or delete them** is
   the default: an application of this municipality that is on neither CMDB
   sheet any more is archived or moved to the trash after the last row (see
   [Repeat imports](#repeat-imports)). Choose **Keep them as they are** to
   leave such applications untouched.
7. Press **Import**. A progress bar shows how many rows have been processed.
   **Cancel import** stops the import before the next row, or, while the
   applications are compared with the export, before the next application;
   what was already processed stays: the rows imported, and the applications
   archived or deleted so far. The section says whether the server
   accepted the cancel; one pressed before the server has started on the
   rows cannot take effect yet, and the section says so.

One import runs at a time. While an import is running, a second one, from
another administrator or another browser tab, is refused with
`IMPORT_IN_PROGRESS` and reads nothing; start it again when the first has
finished.

When the import finishes, the section shows:

- the **summary**: rows read, created, updated, unchanged, skipped, failed
  and warnings, and, when publishing was off, how many applications were
  created unpublished; after a repeat import also how many applications
  were archived, unarchived, deleted (moved to the trash) or restored, when
  there are any. The sentence above it, which a screen reader announces,
  names those counts too, for example *Applications: 1 archived, 1 deleted.*;
- **warnings for the whole file**, for example an optional column that is
  missing;
- the **rows** table: sheet, row number, APPID, application, outcome,
  and the reasons and warnings for that row. An application archived or
  deleted because it left the export has no row number; its sheet is
  `Gearchiveerde Applicaties` when that sheet listed it. Filter it with **Show rows with
  outcome**, and sort it by clicking a column header. It shows 100 rows at a
  time; **Show 100 more rows** adds the next ones. The application name links
  to the module in stackiq.

The municipality stays selected after an import, so a second import goes to
the same organisation.

## The file

The import reads the two CMDB sheets of the export, and of the sheet
`Gearchiveerde Applicaties` only the `APPID` column (see
[Repeat imports](#repeat-imports)). It ignores all other sheets, including
the `Invoer` sheets they are derived from. The archive sheet is read in every
import, also with **Keep them as they are**, and it is bounded like the two
CMDB sheets (see [Limits](#limits)): an archive sheet over the limits refuses
the import even when you keep missing applications. Delete the sheet from a
copy of the export to import it without one; the import then archives and
never deletes.

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
are ignored and not counted. A sheet, the archive sheet included, may hold at
most 10,000 rows with data by default (see [Limits](#limits)).

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

This is the mapping as it ships. On an OpenRegister with the migration-pack
editor an administrator can change it there (see
[Viewing and editing the mapping](#viewing-and-editing-the-mapping)).

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
| Applicatie Status | usage status | follows the export on every import (see [Repeat imports](#repeat-imports)); In productie → In production, In voorraad → Planned, In ontwikkeling → Acquisition, Uit te faseren and Moet verwijderd worden → To be phased out, Uitgefaseerd and Verwijderd → Phased out, Besteld and Wordt getest → Acquisition, Stand-by voor continuïteit → In production; another value is dropped with a warning |
| Classificatie | usage TIME classification | set only when the usage is new or its TIME classification is empty; Tolereren/Tolerate (also `1. Tolereren (wordt ingelezen)`), Investeren/Invest, Migreren/Migrate, Elimineren/Eliminate |
| End-of-Life Functioneel | usage phase-out date | Excel date, stored as is |
| (the sheet), Cluster, Applicatie Eigenaar (Afdeling) | usage internal annotation | `Beheer geregeld: ja` or `nee`, the cluster and the department, joined with ` / `; written only when the usage is new or the note is empty |
| Applicatie Eigenaar (Persoon), Applicatie Eigenaar (Functie) | usage business owner (contact person) | see [Owners](#owners) |
| Applicatie Eigenaar (E-mail), Applicatie Eigenaar (Personeelsnummer) | how the business owner is recognised | optional, placeholder names; see [Owners](#owners) |

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

- **New APPID**: a module and a usage are created. With **Publish the
  applications this import creates** on, the module gets a publication date
  (the moment the import started), so OpenCatalogi lists it; with it off,
  the module has no publication date and is not public.
- **Known APPID, values changed**: only the fields in the column table
  are updated, and of those, the usage's TIME classification and internal
  note only when they are empty: a classification set in stackiq stays,
  whatever the export says. A re-import does overwrite the application's
  name, descriptions, application type, hosting model, BBN level, source
  fields and supplier, and the usage's status, phase-out date and business
  owner, so a status change in TOPdesk comes through. Everything else on the module stays as it is, for example a
  website an administrator added. The publication date and the depublication
  date are never changed: a module an administrator depublished stays
  depublished. The row is reported as *updated*.
- **Known APPID, status changed in TOPdesk**: the usage gets the new status,
  also where the regular steps of the usage lifecycle (Acquisition →
  Planned → In production → To be phased out → Phased out) do not lead
  there. The usage schema allows this jump to administrators only; the
  import is open to Nextcloud administrators only, so it may always make
  it. Other users still follow the regular steps.
- **Known APPID, nothing changed**: nothing is saved; the row is reported
  as *unchanged*. Importing the same export twice creates nothing the second
  time.
- **APPID missing from a newer export**: see
  [Applications that left the export](#applications-that-left-the-export)
  below.
- Each application keeps exactly one usage for the municipality.
- **Known APPID, but the application belongs to another organisation**: an
  application found by its match key is only updated when the municipality
  already uses it, or when no organisation uses it yet. When only other
  organisations use it, the row is *skipped* with reason `conflict: the
  application with this import key is used by another organisation, so it
  is not changed`; nothing is changed and no second application is created.
  Check the application's import key in stackiq. Only a Nextcloud
  administrator can change an import key.

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

### Applications that left the export

With **Archive or delete them** (the default; `missingRecords=archive` in
the API), the import compares the municipality's applications with the
export after the last row. It looks only at the municipality's own usages
and the applications its import created, never at an application only
another organisation uses or one added by hand. For an application whose
APPID is on neither CMDB sheet:

| The APPID is | The application and its usage |
|---|---|
| on the sheet `Gearchiveerde Applicaties` | are **archived**: OpenRegister's archive state, with the reason `cmdb-import: on sheet "Gearchiveerde Applicaties"`. Reported as *archived*. |
| on no sheet at all | are **deleted**: moved to OpenRegister's trash, where they stay restorable for 30 days. Reported as *deleted*. |
| on no sheet, but the archive sheet cannot be relied on: the workbook has no sheet `Gearchiveerde Applicaties`, the sheet has no `APPID` column, or an `APPID` in it has no cached value (a formula whose result the file does not store), or an `APPID` on one of the two CMDB sheets has no cached value (the import cannot tell which application that row stands for), or the workbook has only one of the two CMDB sheets (the applications of the other may be among those missing) | are **archived**, never deleted. Each case has its own warning in the result: *sheet not found* (for the archive sheet or the missing CMDB sheet), *no column APPID*, or *an APPID without a cached value in row …* (for more rows: *in N rows (…)*, naming the first ten; for a CMDB sheet the warning names that sheet). Such a workbook therefore never moves anything to the trash. |

An archived or deleted application disappears from the stackiq lists, from
OpenCatalogi and from Portaliq's **Software we use**. In stackiq, the quick
filter **Archived** on the **Applications** and **Applications in use** pages
lists the archive. On the **Applications** page the **Archived** tab lists
the whole archive: it ignores the GEMMA filters (the page says so in a note above the list while that tab is active), because
their counts and matches describe the working set, which leaves archived
applications out. The search in the **Search** sidebar does apply to that tab. An OpenCatalogi caller finds archived applications by
adding `_archived=true` to its request. Contact persons are never archived
or deleted: an owner is shared with the municipality's other applications.
An application that is already in the state the export asks for is left
alone and not reported again.

When an archived or deleted application **returns** in a later export, it is
taken out of the archive (*unarchived*) or out of the trash (*restored*),
and then updated as usual. No second application is created. With **Update
existing records** off, it is skipped as `exists` and stays archived or in
the trash. When an application was deleted by hand and a later import
created it again, the working one is updated and the deleted one stays in
the trash.

The comparison runs only when the import went through all rows: an import
cancelled or failed before the comparison archives or deletes nothing.
**Cancel import** during the comparison stops it before the next
application; what was archived or deleted until then stays so, and the
result lists it. During the comparison the progress line reads
*Checking the applications of this municipality against the export* without
a count, and the bar stays full: the comparison covers every application of
the municipality, not only the ones missing from the export, so its length
is not known in rows. Each
application is handled on its own; one that cannot be archived is reported
as *failed* with the step (`archive`, `restore` or `delete`) and the others
continue. Archiving needs module schema 0.3.9 and usage schema 1.5.7 or
later, which declare that their objects can be archived; on an older register configuration each application fails
with the step `archive`, and **Force Update** at the top of the stackiq admin
settings fixes it.

With **Keep them as they are** (`missingRecords=keep`), the application, its
usage and its contact persons are left as they are: not changed, archived,
depublished or deleted.

## Owners

The owner becomes a **contact person of the municipality**, never a
Nextcloud user account. It comes from `Applicatie Eigenaar (Persoon)`; its
function (`Applicatie Eigenaar (Functie)`) is stored as the contact person's
role, and the department (`Applicatie Eigenaar (Afdeling)`) goes into the
usage's internal note. When TOPdesk has no owner, the CMDB sheet shows the
owner's function in the person column; the import then uses that function as
the contact's name. No technical owner is imported: the functional
administrator (FB contactpersoon) is not read.

The identity is kept in **Nextcloud Contacts**, in a dedicated address book,
**Stackiq CMDB owners**, of the administrator who runs the import; the import
creates that address book the first time it needs it. The import looks for
an existing contact only in that address book, never in the administrator's
own address books: a personal contact who happens to have the owner's name
is not linked to the application. Within **Stackiq CMDB owners** the import
recognises an owner, in this order:

1. by the **employee number**, when the row has one. Two people with the same
   name stay two owners, also when they share an e-mail address, and an owner
   whose name changes keeps their contact. The number is not stored on the
   contact; the contact's id is a keyed hash of it, the municipality and the
   server's secret, so the number cannot be read back from it. A value
   without a digit other than 0, such as `-`, `0` or `n.v.t.`, is not an
   employee number; leading zeros and a decimal `.0` are ignored.
2. by the **e-mail address**, when the row has one and no employee number.
3. by an exact match on the **name** otherwise.

A row without an employee number never takes a contact that was created
for an employee number, even with the same e-mail address or name: that
contact belongs to that employee. When the server has no `secret` in
`config.php`, employee numbers are not used (the log says so once per
import) and owners are matched by e-mail address or name. Changing that
`secret` gives every owner with an employee number a new contact on the
next import; the old contacts stay.

When nothing matches, the contact is created there, with the e-mail address
when the row has one. The stackiq contact person object only holds the link
to that contact, the role and the municipality. The same owner on several
rows is one contact person.

The e-mail address and employee number come from the optional columns
`Applicatie Eigenaar (E-mail)` and `Applicatie Eigenaar (Personeelsnummer)`.
Those names are placeholders: the export does not have these columns yet.
When your export names them differently, change the source column of the
business-owner pack in OpenRegister (**Edit in OpenRegister** in the
**CMDB import** section); no stackiq update is needed. An export without the
columns imports as before, without a warning. That holds for the
placeholder names only: once you rename the columns, an export that has
them on only one of the two CMDB sheets warns that the column is missing
on the other. Once you add the employee number, an owner who was found by
name or e-mail address until then gets a new contact; the old contact
stays in **Stackiq CMDB owners**. The same happens when an owner first
imported by name alone arrives with an e-mail address: the name-only
contact is not found by that address, so a second contact is created next
to it. An instance without a `secret` in `config.php` cannot use the
employee number: owners are then matched on e-mail address or name, so
two namesakes become one contact (the log warns once).

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
| `FILE_TOO_LARGE` | The file is larger than the upload limit (10 MB by default). The message names the limit in force. | Remove sheets the import does not read. Or split the export and import every part with **Keep them as they are**: an import that archives treats the applications of the other parts as missing. Applications that left the export are then not archived or deleted. |
| `NO_FILE_UPLOADED` | No file arrived. | Choose the file again. |
| `MUNICIPALITY_REQUIRED` | No municipality was chosen. | Pick or type a municipality. |
| `MUNICIPALITY_INVALID` | The chosen organisation does not exist or is not of type Municipality. | Pick an organisation of type Municipality, or type a new name. |
| `NO_SOURCE_SHEET` | Neither `Onbeh Applicaties CMDB` nor `Beheerde Applicaties CMDB` is in the workbook. | Check the sheet names; they must match exactly. |
| `MISSING_COLUMN` | A present CMDB sheet has no `APPID` or `Applicatie Naam` column. The message names the sheet and the column. | Add the column to that sheet. |
| `TOO_MANY_ROWS` | A CMDB sheet has more rows with data than the row limit (10,000 by default). The message names the sheet and the limit. | Split the export and import the parts one after the other with **Keep them as they are**: an import that archives treats the applications of the other parts as missing. Applications that left the export are then not archived or deleted. |
| `WORKBOOK_TOO_LARGE` | Unpacked, the workbook is larger than the import reads: 50 MB in all, 10 MB for one sheet the import reads (the two CMDB sheets and the archive sheet, in every import) or for the table of texts the sheets share, or more than 200,000 different texts, by default. Sheets the import does not read may be larger than 10 MB. An `.xlsx` is a compressed package, so a file of a few MB can unpack to far more. The message names the limit, and for a sheet the sheet and its unpacked size. | For a sheet that is too large: split its rows over two copies of the export and import both with **Keep them as they are**. For a workbook that is too large in all: delete the sheets the import does not read from a copy and import that copy. |
| `MUNICIPALITY_AMBIGUOUS` | More than one municipality has the typed name. The import does not guess which one. | Pick the municipality from the list instead of typing its name. |
| `IMPORT_IN_PROGRESS` | Another CMDB import is running. Only one import runs at a time. | Wait until it has finished and try again. |
| `FIELD_INVALID` | A form field of the request has a value the import does not accept, for example an `updateExisting` or `publish` that is neither `true` nor `false`. The message names the field. | Not reachable from the section; reported for API callers. |
| `UPLOAD_FAILED` | The file reached the server but could not be stored there. | Try again; the Nextcloud log has the details. |
| `MISSING_RECORDS_UNSUPPORTED` | The request asked for something other than `keep` or `archive` for records missing from the export, for example `mark` or `remove`. The details list the accepted values. | Not reachable from the section; reported for API callers. |
| `ARCHIVE_UNAVAILABLE` | **Archive or delete them** was chosen, but the OpenRegister on this server cannot archive or restore objects (it is missing or too old). Nothing was read or written. | Choose **Keep them as they are** and import again, or update OpenRegister. |
| `MAPPING_UNAVAILABLE` | OpenRegister's mapping engine or pack store is missing, or a mapping pack is invalid or breaks a rule of the import. The message names the pack when one is at fault. | Update or enable OpenRegister. If you changed a pack, correct it in OpenRegister; see [Viewing and editing the mapping](#viewing-and-editing-the-mapping). |
| `READER_UNAVAILABLE` | The Excel reader that ships with OpenRegister cannot be loaded. | Make sure OpenRegister is installed and enabled. |
| `NOT_CONFIGURED` | The stackiq register or its schemas cannot be found. | Run **Auto Configure** at the top of the stackiq admin settings. |
| `SCHEMA_OUTDATED` | A stackiq schema lacks a property the import recognises records by, for example `externalKey` on the module schema, or the module schema is older than version 0.3.8: it has no `applicationType`, or `externalKey` lacks the rule that only an administrator may change it. Importing anyway would create every application again, or leave the import key open to every editor. The message names the schema. | Press **Force Update** at the top of the stackiq admin settings to import the register configuration again. |
| `IMPORT_FAILED` | Something unexpected went wrong. | The Nextcloud log has the details. |

**The connection was cut off.** The import runs in one request. When that
request ends without an answer from the import (the connection drops, or a
proxy gives up waiting with a 502, 503 or 504), the import may still be
running on the server. The section then keeps following the import's
progress and shows its report when it finishes. When it cannot find out,
it shows `IMPORT_INTERRUPTED`: wait a few minutes and check the
municipality's applications before importing again. Importing the same file
again creates no duplicates.

A message that you are not signed in, not an administrator, or that your
session expired comes from Nextcloud itself: sign in again, use an
administrator account (see [Who can import](#who-can-import)), or reload the
page.

## Limits

Six limits are read from `lib/Settings/cmdb-import/topdesk-profile.json` on
every import:

| Setting | Default | What it limits |
|---|---|---|
| `maxFileBytes` | `10485760` (10 MB) | the size of the uploaded file |
| `maxRowsPerSheet` | `10000` | the rows with data on one CMDB sheet, and on the archive sheet |
| `maxUncompressedBytes` | `52428800` (50 MB) | the size of the workbook once unpacked, checked before a sheet is parsed |
| `maxPartBytes` | `10485760` (10 MB) | the size of one part the import parses once unpacked, such as a source sheet or the shared-strings table, checked before a sheet is parsed; a sheet the import does not read may be larger, and is read as an empty sheet; the archive sheet is read, so it is bounded like a CMDB sheet |
| `maxSharedStrings` | `200000` | the number of different texts in the workbook's shared-strings table, each formatted piece of a text with mixed formatting counted as one more, also in text written in a cell itself, counted before a sheet is parsed |
| `maxReferencedStringBytes` | `67108864` (64 MB) | the shared text all cells of the workbook reference together, counted once per cell and once per sheet the import reads (the two CMDB sheets and the archive sheet, also when the workbook has no archive sheet) before a sheet is parsed; a long text that many cells repeat counts as often as it is repeated |

The section's help text shows the defaults; when the server refuses a file,
the message shows the limit the server applied. A larger file also has to
pass PHP's `upload_max_filesize` and `post_max_size` and the web server's
request size limit. The profile is part of the app: a change made on the
server is overwritten by the next app update.

## Viewing and editing the mapping

**What you need.** Editing the mapping needs an OpenRegister with the
migration-pack editor, whose pack writes are admin-only and need a CSRF
token: OpenRegister 2.1.41 or later. On an older OpenRegister
nothing is stored in OpenRegister and there is nothing to edit: the import
runs the packs that ship with stackiq, and the import result says so.
Changing those shipped files on the server does not last, because the next
app update replaces them. Once the packs have been stored in OpenRegister,
the import does not fall back to the shipped copies: when OpenRegister's
pack store is gone, it refuses with `MAPPING_UNAVAILABLE` until OpenRegister
is back.

The mapping from export columns to stackiq fields is not in code. It is a
set of mapping packs that OpenRegister's mapping engine runs, one per kind of
record the import writes. With the editor, four of them are kept in
OpenRegister, where you can view and change them:

| Pack (in OpenRegister) | What it maps |
|---|---|
| `stackiq-topdesk-module` | a row to the application (hosting model and BBN lookups, dates) |
| `stackiq-topdesk-manufacturer` | "Vendor" to the Supplier organisation |
| `stackiq-topdesk-usage` | a row to the usage (status and TIME lookups, phase-out date, internal note) |
| `stackiq-topdesk-business-owner` | the owner columns |

When OpenRegister has the editor, stackiq puts these four packs into
OpenRegister when it is installed or updated, and an import stores any that
is missing. From then on the import runs what is saved in OpenRegister. The
mapping endpoint (`GET /index.php/apps/stackiq/api/settings/cmdb-import/mapping`,
Nextcloud admins only) answers, per pack, where the import takes it from
(OpenRegister, or the copy that ships with stackiq), its version and the
link to its editor in OpenRegister. Reading it changes nothing: it does not
store or update a pack.

**In the settings.** Open **Administration settings → Stackiq → CMDB import**
and press **Mapping** under the import form. One table lists the sheets the
import reads and its match, name, required and date columns; a second table
lists the four packs, each with where the import takes it from, its version
and, for a pack kept in OpenRegister, an **Edit in OpenRegister** link. The
municipality pack is not listed: it maps the municipality you choose for the
import, not a column. Opening the page changes nothing.

Each pack has a list of field mappings, one per column: the column name in
the export, the field it goes to, whether it is required, and a
transformation such as trim, a date, or a lookup table of export values to
stored values. For example, to accept a new "Applicatie Status" value, add it
to the lookup table of the status mapping in `stackiq-topdesk-usage`. To
accept a new "Applicatiesoort" value as a hosting model, add it to the lookup
table of the hosting-model mapping in `stackiq-topdesk-module`, for example
`Cloud` → `SaaS`. When a pack maps a column with a date transformation, the
import reads that column as an Excel date.

**What the import refuses.** The import writes past OpenRegister's access
rules, so it checks every pack before it reads a row. It refuses a pack, with
`MAPPING_UNAVAILABLE` and the name and id of the pack, when:

- OpenRegister finds the pack invalid;
- the application name, the APPID (to the source number), the vendor name or
  the owner name is no longer mapped, or no longer marked required;
- a mapping or a default writes a field the pack may not write. A pack may
  write any plain field of the record it writes (the application, the
  organisation or the usage), as the register defines it; the owner pack
  writes the name and the role only. Refused are the fields the import sets
  itself (the import key, the provider, the consumer, the application of a
  usage, the business owner, the type of an organisation: a Supplier stays a
  Supplier), every link to another record, files, the publication dates, the
  fields the register hides from forms or calculates (the service desk
  exchange's keys and stamps, the suggested TIME classification) and the
  installed version the service desk owns, and the fields only an
  administrator or the catalogue sets, such as an organisation's status and
  who registered it. The status and registration
  defaults of the vendor and municipality packs stay as shipped.

The mapping endpoint answers the same reason, with the link to the pack's
editor in `details.editUrl`, and the **Mapping** block shows it with an
**Edit in OpenRegister** link, so you can correct the pack there. After saving
it, reload the settings page: **Mapping** then shows the new version, or the
reason the pack is still refused.

**What the Mapping block shows.** Below the table of packs, each pack has a
collapsible block "Columns and fields": which column of the export goes to
which field, whether the mapping is required and which transform it uses, and
the rules for what the import writes (the values it sets, the fields written
only on a new record, the fields it never writes on an existing record). When
an unedited pack in OpenRegister is older than the shipped one, the row says
"Update pending": the next import replaces it with the shipped version.

**What a pack does not change.** Which sheets are read, the APPID and
"Applicatie Naam" columns, the values that mean empty (`NB`), the constant
each sheet adds (`Beheer geregeld: ja` or `nee`) and the limits are part of
the app (`lib/Settings/cmdb-import/topdesk-profile.json`) and cannot be
changed; the **Mapping** block shows the sheets and the match, name, required
and date columns, not the empty values, the constants or the limits. The
municipality is mapped from the name you choose for the import, not from the
export; that pack is not stored in OpenRegister and the block does not list it.

**Reading more columns.** The import reads only the columns the mapping
names. When you map a new column in a pack, the import reads that column from
then on.

**One mapping per Nextcloud.** The packs in OpenRegister apply to every
import on this Nextcloud, whatever the municipality.

**App updates.** A pack you have not changed follows the app: when an update
ships a newer version, the stored pack is replaced by it (the mapping
endpoint already shows the new pack, with `updatePending` true, before the
next import runs). A pack you changed
is never overwritten, also not when you save it while an import is updating
it. When an update ships a newer version of a pack you changed, the mapping
endpoint reports it (`newerShipped`) and the **Mapping** block says so; compare the two and copy what you need
into your pack in OpenRegister.

**Back to the shipped pack.** Delete the pack in OpenRegister. The next
import, or the next app update, puts the shipped pack back. Do not rename a
pack: the import finds it by its id (such as `stackiq-topdesk-usage`), and
refuses to run while a pack it stored has another id.
