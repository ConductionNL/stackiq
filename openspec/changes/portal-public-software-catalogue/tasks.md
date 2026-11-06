# Tasks: portal-public-software-catalogue

- [x] **T1**: `lib/Service/PublicCatalogueRecords.php`: `entries()` (published modules as `{id, title, subtitle}`, bounded) and `record(id)` (summary and reference component rows, null when not published). Test: PHPUnit `tests/Unit/Service/PublicCatalogueRecordsTest.php`.
- [x] **T2**: `lib/Portal/PortalContributionProvider.php` declares `publicRecords` with `softwareCatalogue` on both audiences and exposes `publicCatalogue()` and `publicCatalogueEntry(id)`. Test: PHPUnit `tests/Unit/Portal/PortalContributionProviderTest.php`.
- [ ] **T3**: portaliq places the list on the example site's Softwarecatalogus page (portaliq change `site-software-catalogue-page`).
