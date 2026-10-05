# CMDB import fixtures

Test workbooks for the TOPdesk CMDB import (`openspec/changes/cmdb-export-import`).
They are used by the PHPUnit tests under `tests/Unit/` and by the Playwright test
`tests/e2e/spec-coverage/cmdb-import.spec.ts`.

The import reads the two CMDB sheets, "Onbeh Applicaties CMDB" and "Beheerde
Applicaties CMDB". Their cells are formulas over the "Invoer" sheets; the import
reads the value Excel cached for each formula. `build-fixtures.py` writes a
placeholder cached value into the formula cells of the mapped columns that the
anonymised export left empty (Roepnaam, Applicatiesoort, the owner's function and
person on "Beheerde", BNN Classificatie, Nickname, …); the list is `CACHED_VALUES`
in the script. The "Invoer" sheets are not read and stay as they are.

| File | What it is |
|---|---|
| `topdesk-export-anonymised.xlsx` | An anonymised TOPdesk export with one fake data row per CMDB sheet (APPID 1234 on "Onbeh", APPID 2 on "Beheerde"), formatted but empty rows below them, and on "Beheerde" ten formula rows whose cached value is `0` (Excel's result for a reference to an empty cell). Removed: custom properties, `customXml/`, the workbook's absolute save path, `xl/connections.xml` and the printer settings of every sheet; the author, company and other document properties are empty, the creation and modification dates are `2026-01-01T00:00:00Z`, and the revision GUIDs, the hidden filter ranges and saved sort orders of the original data are gone. |
| `topdesk-missing-appid.xlsx` | The same, but the "APPID" header of "Beheerde Applicaties CMDB" is renamed, so the required column is missing there. |
| `topdesk-shuffled-columns.xlsx` | The same rows with the columns of both CMDB sheets in reverse order, and the header "Vendor" written as `Vendor⚡`. Reads to the same rows as the original. |
| `topdesk-formula-and-connection.xlsx` | On "Beheerde", "Applicatie Naam" is a formula that would evaluate to `Evaluated` with the cached value `Rekenmodel`, "Roepnaam" is a formula without any cached value, and the package declares a synthetic external web connection to `https://example.invalid/`. |
| `topdesk-no-source-sheet.xlsx` | A minimal workbook with only a sheet "Blad1". |

## Placeholder data only

Every person value is a placeholder: `Achternaam, Voornaam`,
`letter.achternaam@gemeente.nl`, `groepsmail.test@gemeente.nl`, personnel
number `123456`, and the function `Teamleider Applicatiebeheer` where the CMDB
sheet shows a function instead of an owner. `tests/Unit/Fixtures/CmdbFixtureHygieneTest.php`
fails when a fixture holds document metadata, an e-mail address, a linked host or
a long number that is not on its placeholder list. Never commit a municipality's
own export, not even temporarily.

## Rebuilding

`build-fixtures.py` uses the Python standard library only:

```bash
# Sanitise the committed export again, write the cached values (both idempotent) and derive the variants
python3 tests/fixtures/cmdb/build-fixtures.py

# Sanitise a new anonymised export first, then write the cached values and derive the variants
python3 tests/fixtures/cmdb/build-fixtures.py --source path/to/anonymised-export.xlsx
```

Run the hygiene test afterwards.
