---
kind: code
depends_on: []
---

# Copy an application with its links ("Kopiëren met koppelingen")

## Summary

A user who starts a new application entry from an existing one gets the fields, but not the links that make it useful: the connections, the services that cover it, the reference components and the documents. nextcloud-vue's change `index-copy-with-relations` (all ten tasks ticked) adds a copy dialog that lists the source's links and lets the user tick what the copy takes along, switched on per page with `config.copy.include`. Nothing switches it on in stackiq: the Applications page is a custom page (`FacetedCatalogIndexView`) that embeds `CnIndexPage`, and its manifest entry has no `copy` config. Board SkApplicaties draws "Kopiëren" in the selection bar and the copy with its links. This change adopts the library feature on the Applications and Services pages.

## Rows

- `land-copy-entry` "Start a new application entry by copying an existing one with its links." State specified; this change is its stackiq plan.

## Dependencies

- nextcloud-vue `index-copy-with-relations` (done, awaiting archive): `CnCopyDialog` link list, `config.copy.include`, `useObjectCopy`.
- OpenRegister object copy endpoint `POST /api/objects/{register}/{schema}/{id}/copy`: not on OpenRegister development on 30 Oct (the routes file has file copy and content-report copy only). Until it lands, the library shows the links read only and copies fields only, so task 3 (the live check) waits for it.

## Screens

See `screens.md`: SkApplicaties.
