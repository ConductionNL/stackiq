---
kind: code
depends_on: []
---

# Bring over what an organisation registered in the previous Softwarecatalogus

## Summary

Municipalities have registered their applications, versions, suppliers and connections in the VNG GEMMA Softwarecatalogus for years. Moving to stackiq should not mean typing that again. Board SkApplicaties draws the entry point: "Overnemen uit de vorige Softwarecatalogus" in the actions menu of the Applications page. Nothing in stackiq does it today: row `land-migrate-legacy` has state none, no spec and no change.

stackiq already has a profile-driven workbook import (`lib/Service/Cmdb/`, `POST /api/cmdb-import`, profile `lib/Settings/cmdb-import/topdesk-profile.json` with packs per target), used for the TOPdesk CMDB export. This change adds a second profile for the previous catalogue's export, a preview step before anything is written, and a result step. It reuses the reader, the bounds, the owner identity and the report.

## Rows

- `land-migrate-legacy` "Bring over what was registered in the previous catalogue, so nobody types it again."

## Decisions taken in this change (to confirm in review)

- Source: the organisation's export from the previous Softwarecatalogus as a spreadsheet (xlsx or csv: applications in use with supplier, product, version, reference components, status and connections). An API pull from softwarecatalogus.nl is out of scope until VNG offers an export API with organisation authorisation.
- Matching: a supplier matches an existing organisation on KvK number, else on exact name; a product matches an existing module of that supplier on name; a version on version number. Anything unmatched is created as a concept, never merged by fuzzy guess.
- Usages are created for the active organisation only, and a second run updates instead of duplicating (keyed on the previous catalogue's id, stored as `legacyId`).

## Out of scope

- The external VNG frontend repository (`Softwarecatalogus/`), which this team does not commit to.
- Other organisations' registrations: each organisation brings over its own.

## Screens

See `screens.md`: SkApplicaties. The board draws the entry only; a board lane draws the mapping and result steps (matrix gap list (b)).
