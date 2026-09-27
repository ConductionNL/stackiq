---
kind: code
depends_on: []
---

# Export the catalogue lists, your own organisation and your own reports

## Summary

Today a municipal information manager can export exactly one thing from a user page: the portfolio report CSV for one organisation. The Applications, Services and Contracts lists have no export, the ArchiMate export of your own organisation sits in admin settings, and nobody can define a report of their own. This change opts the catalogue list pages into the component library's Export menu, adds an export for the two faceted lists, puts the own-organisation ArchiMate export on the organisation page with an ownership check, and adds a custom reports page over OpenRegister's export profiles.

## Why

This change covers three matrix rows.

- `stackiq:ins-export-list`, "Export a filtered list to a spreadsheet." Stackiq rates itself partial: one fixed report exports to CSV and the filtered catalogue lists cannot be exported. GEMMA Softwarecatalogus rates yes: "Export to CSV" on the filtered package-version list (https://www.softwarecatalogus.nl/pakketversies) and "Ook beschikbaar via knop [Export to csv] op pagina Alle pakketten" (https://www.softwarecatalogus.nl/Beschikbare%20downloads). SAP LeanIX rates yes: "In the inventory, apply filters to narrow down to the fact sheets that you need to export ... export fact sheet data as an Excel file" (https://help.sap.com/docs/leanix/ea/exporting-fact-sheet-data-as-excel-file). GLPI rates yes from its source at 11.0.9: `src/Glpi/Search/Output/Csv.php`, `Ods.php`, `Xlsx.php` and `Pdf.php` export the filtered list. TOPdesk rates yes: "Export to .CSV Export to Excel" (https://docs.topdesk.com/en/asset-dashboard.html).
- `stackiq:share-export`, "Export your own catalogue data for use elsewhere." Stackiq rates itself partial: a full ArchiMate export exists but is only reached from admin settings. GEMMA Softwarecatalogus rates yes: "Mijn pakketten, Mijn koppelingen: Knop [Exporteren]" (https://www.softwarecatalogus.nl/Beschikbare%20downloads). SAP LeanIX rates yes: "export fact sheet data as an Excel file" (https://help.sap.com/docs/leanix/ea/exporting-fact-sheet-data-as-excel-file) and "Export snapshots of your workspace data through the Pathfinder REST API" (https://help.sap.com/docs/leanix/ea/exporting-workspace-snapshots). BlueDolphin rates yes: "working with the data available through the BlueDolphin OData service" (https://help.bluedolphin.io/en/articles/11967710-using-the-odata-feed) and views export as AMEFF (https://help.bluedolphin.io/en/articles/11967514-download-a-view). GLPI rates yes from its source: every search list exports to CSV, PDF, ODS and XLSX. TOPdesk rates yes: tile actions "Export to .CSV Export to Excel" (https://docs.topdesk.com/en/asset-dashboard.html) and "generate reports by using the TOPdesk OData feed" (https://docs.topdesk.com/en/create-odata-reports-for-asset-management.html).
- `stackiq:ins-custom-report`, "Build your own report over the whole portfolio and export it." Stackiq rates itself no. SAP LeanIX rates yes: "GraphQL is used to create custom reports" (https://help.sap.com/docs/leanix/ea/sap-leanix-apis), with a reporting library that can "Export to PDF and PNG files" (https://help.sap.com/docs/leanix/ea/reporting-framework-and-cli). GLPI rates yes from its source at 11.0.9: any list takes arbitrary criteria (`src/Glpi/Search/Input/QueryBuilder.php:72`), selectable columns and export, and the result can be saved (`src/SavedSearch.php:52`).

`ins-export-list` and `share-export` are partial and built: this change builds export on the filtered catalogue list pages, and an export of your own data from a user page instead of admin settings. `ins-custom-report` is built new.

## What stackiq has today

Read at development 49e65cb4, with `@conduction/nextcloud-vue` 2.57.1 and OpenRegister development 4fee776.

- `lib/Controller/PortfolioReportController.php:105` answers `format=csv` with a `DataDownloadResponse` for one organisation. `src/views/organisaties/PortfolioReport.vue:567` is its button. It is the only export on a user page.
- No page in `src/manifest.json` sets `allowExport`, and no schema in `lib/Settings/softwarecatalogus_register.json` sets `exportable`. The library's native Export menu renders only when both are true (`src/components/CnIndexPage/CnIndexPage.vue:3589` `showExportMenu` in the library).
- OpenRegister does not keep a schema `exportable` flag. `lib/Db/Schema.php` in OpenRegister has no such field, and `hydrate()` calls a setter per key and swallows the error for an unknown one (`lib/Db/Schema.php:1972` to `:1977`). So the flag is dropped on import, the schema the library fetches never carries it, and the Export menu cannot render on any page today.
- The Modules and Diensten pages are `FacetedCatalogIndexView` (`src/views/FacetedCatalogIndexView.vue:108` mounts its `CnIndexPage`). They narrow the list with `{ id: matchedObjectIds }` from `lib/Service/FacetService.php` and keep the facet state in `_gf_` query keys (`:371` `syncUrl`), which OpenRegister's export does not understand.
- `lib/Controller/SettingsController.php:1685` `exportOrgArchiMate()` (`GET /api/archimate/export/organization/{organizationUuid}`, `appinfo/routes.php:98`) is called only from `src/views/settings/sections/ArchiMateImportExport.vue:960`, in admin settings. Its guard `verifyOrgExportPermission()` (`:1737`) lets a Nextcloud admin through, or a member of an organisation admin group. `SettingsService::getOrganizationAdminGroups()` returns an empty list on purpose (`lib/Service/SettingsService.php:2105` to `:2110`), so in practice only a Nextcloud admin can export an organisation, and the guard never compares the uuid with the caller's own organisation.
- The Reports page (`src/manifest.json:1021`, type `reports`) has one card, Portfolio rationalization.

## What this change builds

- The Export menu, CSV and Excel, on the Contracts, Organisations, Compliance and Module versions list pages, through the library and OpenRegister's export endpoint. Stackiq sets the two flags; the menu appears once OpenRegister keeps the schema flag.
- An Export menu on the Applications and Services pages that exports the rows the facets, the quick filter and the search leave, through a new stackiq endpoint.
- An Export as ArchiMate action on the organisation detail page for a user whose active organisation it is, and a guard on the endpoint behind it that allows exactly that, plus Nextcloud admins and `ambtenaar` as the portfolio report allows.
- A Custom reports page, reached from a card on the Reports page, where a user defines a report as a named, ordered field list over one catalogue schema with optional field filters, runs it, and downloads CSV or JSON. The reports are OpenRegister export profiles.

## Out of scope

- Storing and serving the schema `exportable` flag. That is OpenRegister's half: a field on `Schema`, kept on import and returned by the schema API. Until it lands the four list pages keep what they have today, no export.
- Passing the page filter and the active quick filter into the library's export. In `@conduction/nextcloud-vue` 2.57.1 the Export menu forwards only `$route.query` (`src/utils/indexExportHelpers.js:33` `buildExportUrl`, called from `onExportClick` at `CnIndexPage.vue:5852`), so a page filter or quick filter that is not in the URL is not applied to the file. That is nextcloud-vue's half; this change moves the pin to the release that carries it.
- Charts, PDF layouts and scheduled delivery. OpenRegister's scheduled reports (`/api/scheduled-reports`) can run a profile on a schedule later; this change does not surface them.
- Exporting contact persons. They hold personal data, and who may export them is a decision for the functional administrator through OpenRegister's `export` verb.
- An OData or API feed. The generated API description is `sharing-generated-api-docs`.

## Risks

- Two upstream halves gate REQ-CER-001: the OpenRegister schema flag and the nextcloud-vue filter forwarding. The tasks turn the flags on per page only when both have shipped, so no page exports rows the user did not ask for.
- The new guard on `exportOrgArchiMate()` widens the endpoint from Nextcloud admins to members of the organisation, for their own organisation only. The uuid check runs before any export work, and a unit test pins it.
