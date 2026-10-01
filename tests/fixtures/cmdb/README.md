# CMDB import fixtures

Test workbooks for the TOPdesk CMDB import (`openspec/changes/cmdb-export-import`).
They are used by the PHPUnit tests under `tests/Unit/` and by the Playwright test
`tests/e2e/spec-coverage/cmdb-import.spec.ts`.

| File | What it is |
|---|---|
| `topdesk-export-anonymised.xlsx` | An anonymised TOPdesk export with one fake data row per source sheet ("Invoer AIA data" and "Invoer APP data") and hundreds of formatted but empty rows below them. Document metadata, custom properties, `customXml/`, the workbook's absolute save path and `xl/connections.xml` are removed. |
| `topdesk-missing-middel-id.xlsx` | The same, but the "Middel-ID" header of "Invoer APP data" is renamed, so the required column is missing. |
| `topdesk-shuffled-columns.xlsx` | The same rows with the columns of both source sheets in reverse order, and the header "Eigenaar e-mail" written as `Eigenaar e-mail⚡`. Reads to the same rows as the original. |
| `topdesk-formula-and-connection.xlsx` | "Naam" of the APP row is a formula that would evaluate to `Evaluated` with the cached value `Rekenmodel`, and the package declares a synthetic external web connection to `https://example.invalid/`. |
| `topdesk-no-source-sheet.xlsx` | A minimal workbook with only a sheet "Blad1". |

## Placeholder data only

Every person value is a placeholder: `Achternaam, Voornaam`,
`letter.achternaam@gemeente.nl`, `groepsmail.test@gemeente.nl`, personnel
number `123456`. `tests/Unit/Fixtures/CmdbFixtureHygieneTest.php` fails when a
fixture holds document metadata, an e-mail address, a linked host or a long
number that is not on its placeholder list. Never commit a municipality's own
export, not even temporarily.

## Rebuilding

`build-fixtures.py` uses the Python standard library only:

```bash
# Re-derive the variants from the committed sanitised export
python3 tests/fixtures/cmdb/build-fixtures.py

# Sanitise a new anonymised export first, then derive the variants
python3 tests/fixtures/cmdb/build-fixtures.py --source path/to/anonymised-export.xlsx
```

Run the hygiene test afterwards.
