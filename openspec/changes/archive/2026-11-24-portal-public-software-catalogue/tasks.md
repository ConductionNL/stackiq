# Tasks: portal-public-software-catalogue

- [x] **T1**: `lib/Service/PublicCatalogueRecords.php`: `entries()` (published modules as `{id, title, subtitle}`, bounded) and `record(id)` (summary and reference component rows, null when not published). Test: PHPUnit `tests/Unit/Service/PublicCatalogueRecordsTest.php`.
- [x] **T2**: `lib/Portal/PortalContributionProvider.php` declares `publicRecords` with `softwareCatalogue` on both audiences and exposes `publicCatalogue()` and `publicCatalogueEntry(id)`. Test: PHPUnit `tests/Unit/Portal/PortalContributionProviderTest.php`.
- [x] **T3**: portaliq places the list on the example site's Softwarecatalogus page (portaliq change `site-software-catalogue-page`).
  - Done in portaliq: `site-software-catalogue-page` is archived there (2026-10-30); `lib/Settings/sites/zuiddrecht.json` carries the `/softwarecatalogus` page with the `publicRecords` block on `stackiq` / `softwareCatalogue`, tested by portaliq's `tests/site-software-catalogue.spec.mjs`. Nothing left in stackiq.
