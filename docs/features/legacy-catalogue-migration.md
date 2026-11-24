<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

# Bring over from the previous Softwarecatalogus

Organisations registered their applications, versions and suppliers in the
previous Softwarecatalogus for years. Moving to stackiq does not mean typing
that again: bring over your organisation's export, check how stackiq reads
each row, and confirm. Nothing is saved before you confirm.

Specification: [`openspec/changes/landscape-migrate-from-previous-catalogue`](https://github.com/ConductionNL/stackiq/blob/development/openspec/changes/landscape-migrate-from-previous-catalogue/specs/legacy-catalogue-migration/spec.md).

## Who may bring over an export

A member of an organisation who may register applications (create usages)
for it. Make that organisation your active organisation first. Anyone else
does not see the entry, and the server refuses them before it reads a file.

## Getting the export

Export your organisation's applications in use from the previous
Softwarecatalogus as an Excel workbook (`.xlsx`, at most 10 MB). stackiq reads
the sheet **Applicaties**, one row per application in use, with these columns:

| Column | Used for |
|---|---|
| `ID` | The previous catalogue's id of the row. Required; a second run matches on it. |
| `Leverancier` | The supplier. Required. |
| `KvK-nummer leverancier` | The supplier's KvK number, matched before the name. |
| `Applicatie` | The application. Required. |
| `Omschrijving` | The application's short description, for an application stackiq creates. |
| `Versie` | The version in use. Empty, `-` or `Onbekend` means no version. |
| `Status` | The status of the usage: `In gebruik`, `Gepland`, `In aanschaf`, `Uitfaseren`, `Uitgefaseerd` (or the English names). |
| `Opmerking` | An internal note on the usage. |

Other columns, such as `Referentiecomponenten` and `Koppelingen`, are not read
yet. The mapping is configuration in `lib/Settings/cmdb-import/vng-swc-profile.json`
and its four packs; an export with other headers needs a change there only.

## Bringing it over

1. Open **Applications** and choose **Actions** › **Bring over from the
   previous Softwarecatalogus**.
2. Choose the export file and press **Preview**.
3. The preview lists every row with, for the supplier, the application and the
   version, whether stackiq **matched** an existing one or will **create** it.
   A row without a required value is shown greyed out with the reason and is
   not brought over. Uncheck a row to leave it out.
4. Press **Import**. The result counts the usages **created**, **updated** and
   **skipped**, and lists each skipped row with its reason.

## How matching works

- A **supplier** matches an organisation on its KvK number, else on its exact
  name. A name two organisations share matches neither, so nothing is linked
  to the wrong one.
- An **application** matches a module of that supplier on its exact name.
- A **version** matches a version of that application on its number.

Nothing is matched by a guess. What does not match is created as a concept:
a supplier organisation in status Draft, registered by your organisation, and
an application and version without a publication date, so they stay out of the
public catalogue until they are reviewed. A supplier or application that
several rows name is created once.

Everything is saved with your own rights: the import never creates what you
could not create by hand. A row stackiq could not save is skipped with that
reason, and the other rows are still brought over.

## Running it again

Bring over a newer export the same way. Each usage is found again on the
previous catalogue's id (`legacyId`) and updated, so nothing is duplicated:
the result then reads, for example, *created 0, updated 116*. A supplier or
application created by the first run is matched by the second.

## Endpoints

- `GET /apps/stackiq/api/legacy-import/access` — whether you may bring over an export.
- `POST /apps/stackiq/api/legacy-import/preview` — the preview of a file (multipart `file`); writes nothing.
- `POST /apps/stackiq/api/legacy-import/apply` — bring it over (multipart `file` and `leftOut`, a JSON array of the ids left out).

**Key services:** `lib/Service/LegacyCatalogueMigrationService.php`, `lib/Service/LegacyCatalogue/`
**Controller:** `lib/Controller/LegacyCatalogueImportController.php`
