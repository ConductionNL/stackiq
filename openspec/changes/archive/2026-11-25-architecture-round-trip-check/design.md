# Design: architecture-round-trip-check

Read at development 49e65cb4. Line numbers below are from that sha.

## Where it fits

| Layer | Touched | Read at |
|---|---|---|
| Comparator | new `lib/Service/ArchiMateModelComparator.php` | pure |
| Service | new `lib/Service/ArchiMateRoundTripService.php` | uses the import's conversion and the export's generation |
| Import service | `lib/Service/ArchiMateImportService.php`: the steps before the save in `importArchiMateFileFromPathOptimized` (:347), that is `validateArchiMateFile` (:204), `parseArchiMateXml` (:629), `extractModelIdentifier` (:663) and `transformArchiMateXmlToObjectsBatch` (:4175), move into one public method `convertFileToObjects(string $filePath): array`, which the import then calls | |
| Export service | `lib/Service/ArchiMateExportService.php`: a public `generateXmlFromObjects(array $objects): string` that runs `generateXmlDirectly` (:1009) and `runQualityAssuranceChecks` (:2143), which `exportArchiMateXml` (:951) then calls | |
| Controller and route | `SettingsController::checkArchiMateRoundTrip`, route `settings#checkArchiMateRoundTrip` at `POST /api/archimate/round-trip-check`; the old route (`appinfo/routes.php:107`) and `testArchiMateRoundTrip` (`lib/Controller/SettingsController.php:2886`) go | the upload handling of `importArchiMate` (:1490) is the pattern |
| Removed | `ArchiMateService::testRoundTrip` (`lib/Service/ArchiMateService.php:1496`), `createTestArchiMateXml` (:1559), `createTempFile` (:1585); the store action `testRoundTrip` (`src/store/modules/settings.js:1953`) | |
| View | new `src/views/settings/sections/ArchiMateRoundTripCheck.vue`, placed in `src/views/settings/sections/ArchiMateImportExport.vue` after its Export part (:555) | |
| Store | `src/store/modules/settings.js` gains `checkRoundTrip(file, mode)` | |

## Decisions

### D1. The check compares two exchange files by identifier

`ArchiMateModelComparator::compare(string $sourceXml, string $resultXml): array` reads both files into maps keyed by identifier:

| Category | Compared on |
|---|---|
| elements | type, name, documentation, property values by property definition name |
| relationships | type, source, target, name, property values |
| views | name, viewpoint, the set of node element references, the set of connection relationship references |
| view nodes | per view: element reference, parent, position and size |
| property definitions | name, type |
| organizations | the folder tree and the items under each folder |

For each category it returns the count in the source, the count in the result, and the identifiers that are missing, extra or changed, each changed one with the fields that differ. It returns the first 50 examples per category with their names and the full counts, so a report on a GEMMA release stays readable. Order of elements in a file is never a difference.

Rejected: counting objects, which is what the current code tries. Equal counts can hide one element lost and another added, and a count says nothing about a relationship whose target changed.

### D2. Two modes, neither writes

`ArchiMateRoundTripService::check(string $filePath, string $mode): array`:

- `before-import`: `ArchiMateImportService::convertFileToObjects` turns the file into the objects the import would save, `ArchiMateExportService::generateXmlFromObjects` turns those objects into an exchange file, and the comparator compares it with the source. Nothing is saved. This covers loss in the import's conversion and the export's generation.
- `against-imported`: reads the model identifier from the file, reads the stored AMEF objects whose `model_identifier` equals it (the import stamps it on every object, `ArchiMateImportService.php:592`) through the export's reader (`getObjectsFromDatabase`, `ArchiMateExportService.php:808`), generates the exchange file from them and compares. This also covers what OpenRegister dropped when it stored the objects. When no object carries that identifier, it answers that the model is not imported.

Both modes run the code paths the real import and export run, because the import and the full export call the two new public methods themselves. A check that ran a copy of the conversion would pass while the real import lost data.

Rejected: importing a test model and exporting it again, which the current code does (`lib/Service/ArchiMateService.php:1504`). It writes a test model into the register every user reads and leaves it there.

Rejected: a built-in test model. A small hand-written file shows only that the small file survives; the admin's question is whether their GEMMA release does.

### D3. Admin only, upload only

`POST /api/archimate/round-trip-check` takes a multipart upload `archiMateFile` and a `mode`, with the same upload handling as the import (:1490): no `file_path` parameter, the presence check of `validateArchiMateFile` (`ArchiMateImportService.php:204`), and the admin check the import makes (`SettingsController.php:1496`). The uploaded temporary file is PHP's own and is not copied. The response is the comparator's result with the mode, the model identifier and the time taken.

The current endpoint is `@NoAdminRequired` (:2880) and writes to the register, so a signed-in user without admin rights can put objects into the AMEF register today. Removing it closes that.

### D4. The report on the settings page

`ArchiMateRoundTripCheck.vue` sits under the ArchiMate import and export on the admin settings page. It holds a file picker, a choice between "Before import (nothing is written)" and "Against the imported model", and a Check button. The result is a table with one row per category (in the file, after the round trip, missing, extra, changed) and, per category, an expandable list of examples with identifier, name and the fields that differ. A summary line says "No losses found" or names the categories with losses. Colours are Nextcloud CSS variables and every state is also written as text.

## Declarative versus imperative

The change adds no lifecycle, aggregation, notification, relation or widget behaviour. It is a read-only comparison of two files, imperative by nature, and it removes code.

## Risks

- **Moving import steps.** `convertFileToObjects` wraps code the import already runs, in the same order. The existing decomposition tests (`tests/Unit/Service/ArchiMateImportServiceDecompositionTest.php`, `ArchiMateExportServiceDecompositionTest.php`) must stay green, and a new test imports `lib/Settings/GEMMA_testdata_below_1_5mb.xml` through both the old and the new entry and compares the object lists.
- **Findings on day one.** The check will likely report losses on a real GEMMA file, for example property names the import lowercases (`convertToCamelCase`, `ArchiMateImportService.php:2256`). That is the point; the report names them and the fixes are separate changes.
- **Memory.** A before-import check on a GEMMA release holds the converted objects and two XML documents at once. The route runs under the import's limits, and the page warns that a large file takes as long as an import.
- **Tests that mock the old endpoint.** `tests/Unit/Controller/SettingsControllerEmailArchiMateContractTest.php:458` and :482 mock `testRoundTrip`; they move to the new route.
