# Design: insight-exports-and-custom-reports

Read at development 49e65cb4, against `@conduction/nextcloud-vue` 2.57.1 (`package.json:45` pins `^2.57.1`) and OpenRegister development 4fee776.

## Where it fits

| Part | File and line | What changes |
|---|---|---|
| Fragment | new `lib/Settings/register.d/insight-exports-and-custom-reports.json` (ADR-037) | `exportable: true` on `catalogContract` (`lib/Settings/softwarecatalogus_register.json:3250`), `organization` (`:2020`), `compliancy` (`:7404`) and `moduleVersion` (`:7649`) |
| Pages | `src/manifest.json:527` `Contracten`, `:386` `Organisaties`, `:857` `Komplianties`, `:915` `Moduleversies` | `allowExport: true` in `config` |
| View | `src/views/FacetedCatalogIndexView.vue` | an Export menu in the view's own toolbar (`:51`), next to the Saved views `NcActions` (`:68`); it listens to `quick-filter-change` from its `CnIndexPage` (`:108`) |
| Controller and route | new `lib/Controller/CatalogExportController.php`, route `GET /api/catalog/{schema}/export` in `appinfo/routes.php` | `#[NoAdminRequired]`, `#[NoCSRFRequired]`, schema limited to `module` and `catalogService` |
| Service | new `lib/Service/CatalogExportService.php` | asks `FacetService` for the matched ids, reads the rows with RBAC on, writes CSV or XLSX |
| Helper | new `lib/Service/OrganisationScopeGuard.php` | the rule now private in `PortfolioReportController::isAuthorisedForOrganisation()` (`lib/Controller/PortfolioReportController.php:139`), shared by both controllers |
| Controller | `lib/Controller/SettingsController.php:1685` `exportOrgArchiMate()` | calls the helper instead of `verifyOrgExportPermission()` (`:1737`) |
| Page | `src/manifest.json:403` `OrganisatieDetail` | `actionsComponent: "OrganisationExportAction"`, registered in `src/customComponents.js` |
| Component | new `src/components/organisations/OrganisationExportAction.vue` | the Export as ArchiMate button in the detail page's actions slot |
| Page and view | new `src/manifest.d/custom-reports.json` with page `CustomReports` at `/reports/custom`, view `src/views/reports/CustomReportsView.vue`, dialog `src/modals/reports/CustomReportDialog.vue` | lists, creates, runs and deletes the user's OpenRegister export profiles over catalogue schemas |
| Page | `src/manifest.json:1021` `Reports` | a second card, Custom reports |

## Decisions

### D1. List pages use the library's Export menu and OpenRegister's export

The library renders Export as CSV and Export as Excel when a page sets `allowExport` and the schema is `exportable` (`src/components/CnIndexPage/CnIndexPage.vue:3589` in the library). It sends the browser to `GET /apps/openregister/api/objects/{register}/{schema}/export` (OpenRegister `appinfo/routes.php:1175`), where OpenRegister serialises, filters and checks rights. OpenRegister checks its own `export` verb on that path, falling back to the `read` grant when a schema does not name `export` (`lib/Service/Export/ExportRightService.php:58` and `:65` in OpenRegister). Stackiq writes no CSV code for these pages (ADR-012, ADR-022).

Rejected: the `showMassExport` mass action. In self-fetch mode it exports the whole schema without filters (`src/components/CnIndexPage/selfModeActions.js:151` `handleMassExport` in the library).

### D2. The flag must survive the import

OpenRegister 4fee776 has no `exportable` field on `Schema` (`lib/Db/Schema.php` field list from `:133`), and `hydrate()` swallows the error from a missing setter (`lib/Db/Schema.php:1972` to `:1977`). A schema flagged `exportable` in stackiq's register is imported without the flag, the schema the library fetches has no `exportable`, and `showExportMenu` stays false. The OpenRegister half is to keep the flag on `Schema` and serve it. Stackiq's half is to declare it, which does no harm before then.

Rejected: putting the flag in the schema's `configuration`, which OpenRegister does keep. The library reads the top-level `exportable` (`CnIndexPage.vue:3590`), so a flag in `configuration` would need a library change as well and would leave two places to set one switch.

### D3. The filter the user sees must reach the file

The Export menu forwards `$route.query` only (`onExportClick` at `CnIndexPage.vue:5852` into `src/utils/indexExportHelpers.js:33` `buildExportUrl`). `config.filter`, the active quick filter and the search box live in component state, not in the URL. So the Organisations page, whose `config.filter` keeps only Draft, Active and Inactive (`src/manifest.json:396`) and so hides merged tombstones, would export the tombstones, and the Contracts page with Active selected would export every contract. A `type: index` page is drawn by the library's page renderer, so stackiq has no place to listen to the page's `quick-filter-change` event and write it into the route. This change turns the menu on for Komplianties and Moduleversies as soon as the flag is kept (no page filter, no quick filters), and for Contracten and Organisaties only with the nextcloud-vue release that forwards the merged page filter. The spec asserts the filtered result, so the e2e test fails on a library that does not forward it.

Rejected: an `actionsComponent` with stackiq's own export on these index pages. The library's `actions` slot on `CnIndexPage` binds no props (`CnIndexPage.vue:105`), so that component cannot see the filter either.

### D4. The faceted lists get a stackiq endpoint

On Modules and Diensten the facet selection is not an OpenRegister filter. Two of the four GEMMA dimensions live on the linked `element` object, and the view narrows the list to `{ id: matchedObjectIds }` (`src/views/FacetedCatalogIndexView.vue:24` to `:28`). OpenRegister's export cannot take that id list through a URL at catalogue size. So `CatalogExportService` does what the list does: it calls `FacetService` with the same `_gf_` keys and search, which returns the matched ids bounded by `BASE_OBJECT_LIMIT` times `MAX_BASE_PAGES` (`lib/Service/FacetService.php:82` and `:90`) and scoped to the caller. It then reads those objects through `ObjectServiceInterface::searchObjects()` with RBAC on, adds the fields of the active quick filter, and writes the page's columns as CSV (as `PortfolioReportService::buildCsv()` does, `lib/Service/PortfolioReportService.php:166`) or XLSX.

Here the view does own its `CnIndexPage`, so it listens to `quick-filter-change` (emitted at `CnIndexPage.vue:4831` in the library) and sends the active quick filter's `filter` object with the request. On `/modules` that carries the BBN and DPIA quick filters (`src/manifest.json:611` onwards).

Rejected: a proxy to OpenRegister's export with `id[]` in the query. A few hundred ids already pass common URL limits.

The endpoint honours OpenRegister's `export` verb: when the schema's `authorization` names `export`, the caller must be in one of those groups; otherwise the `read` grant applies, the same default OpenRegister uses.

### D5. The own-organisation export moves to the organisation page, with an ownership check

`OrganisatieDetail` gets `actionsComponent: "OrganisationExportAction"`. The library's page renderer maps that key onto the detail page's `actions` slot (`src/components/CnPageRenderer/CnPageRenderer.vue:1441` in the library), and the slot passes the `object` and `objectId` (`src/components/CnDetailPage/CnDetailPage.vue:204`). The component shows Export as ArchiMate when the caller's active organisation is the page's organisation, or the caller is a Nextcloud admin or in `ambtenaar`, and calls the existing `GET /api/archimate/export/organization/{organizationUuid}`.

The endpoint's guard changes. Today `verifyOrgExportPermission()` (`SettingsController.php:1737`) allows a Nextcloud admin or an organisation admin group, and the group list is always empty (`lib/Service/SettingsService.php:2105` to `:2110`), so only a Nextcloud admin gets through, for any uuid. The new guard is the rule `PortfolioReportController::isAuthorisedForOrganisation()` already applies (`lib/Controller/PortfolioReportController.php:139`): admin or `ambtenaar` for any organisation, otherwise only the caller's own active organisation (user value `core`/`organisation`). The rule moves into `OrganisationScopeGuard` so the two controllers cannot drift.

Rejected: a second endpoint for the user page. The export logic is the same, and a second door is a second place to forget the check.

### D6. Custom reports are OpenRegister export profiles

OpenRegister stores export profiles: a name, an ordered field list, a value mode (`stored` or `rendered`), a format (`csv` or `json`), an optional `filters` object, bound to one register and schema, owned by a user (`lib/Db/ExportProfile.php:97` onwards in OpenRegister, routes `/api/export-profiles` and `/api/export-profiles/{id}/run` at OpenRegister `appinfo/routes.php:1941` to `:1947`). The run checks the export verb and reads through `ExportService::fetchExportObjects()` with the profile's filters (`lib/Service/Export/ExportProfileService.php:236` in OpenRegister).

Stackiq adds only the page. `CustomReportsView` lists profiles for the catalogue register. The profile index returns every profile to a Nextcloud admin and the caller's own to anyone else (`ExportProfileService::listFor()`, `:109`), so the view also keeps only `owner` equal to the current user. `CustomReportDialog` creates one through the library's `CnFormDialog`: name, catalogue schema, fields in order, value mode, format, and optional filter rows of a field and a value from the chosen schema. Run downloads `/api/export-profiles/{id}/run`. Delete calls the profile's `DELETE`.

Rejected: a saved view as the report filter. The only saved views on the catalogue pages are the GEMMA facet views, whose `query` holds facet keys and a marker (`src/store/modules/facets.js:415`), not schema fields, so OpenRegister cannot apply them to a profile run.

Rejected: a stackiq report store and builder. It would duplicate OpenRegister's profiles and its export rights check (ADR-022).

## Declarative versus imperative

- The list exports are declarative: two flags per page and schema.
- The faceted export is imperative because the GEMMA facets are stackiq's own computation in `FacetService`; nothing in OpenRegister can express them.
- Custom reports are OpenRegister data (export profiles) driven through its API. No aggregation, lifecycle or notification rule is added.

## Seed data

No schema changes shape; `exportable` is a schema flag, not a property. No seed objects are needed. The e2e tests use the demo import (`lib/Settings/stackiq_mock_register.json`) for rows, and create their own profiles.

## Risks

- **Two upstream halves.** D2 needs OpenRegister to keep the schema flag, and D3 needs a nextcloud-vue release. Until then the four list pages keep what they have today, no export. The faceted export, the organisation export and custom reports do not wait on either.
- **Large faceted exports.** The faceted export is bounded by the same ceiling as the list. Today `FacetService` only logs a warning when it hits the ceiling (`lib/Service/FacetService.php:461`) and the list does not tell the user. The export writes a last row saying the set was cut, so a file never looks complete when it is not.
- **A widened endpoint.** D5 opens the organisation export from Nextcloud admins to members of that organisation. The uuid comparison runs before any export work, and `tests/Unit/Service/OrganisationScopeGuardTest.php` pins both sides.
