---
kind: code
depends_on: []
---

# Check that an ArchiMate model survives import and export

## Summary

A Nextcloud admin uploads an ArchiMate exchange file on the settings page and gets a report of what would be lost if stackiq imported and exported it: elements, relationships, views, view nodes, connections and property values that go missing, appear or change on the way. The check can run before an import, writing nothing, or against a model already imported. It replaces a round-trip endpoint that can never succeed, is open to any signed-in user, and imports a test model into the live register.

## Why

This change builds one row of the stackiq parity matrix: `stackiq:arch-round-trip`, "Check that a model survives import and export without losing elements or relations." The row comes from stackiq's own code (feature archimate-import-and-export); no tender, feature request or competitor names it, and no competitor rates yes. The lane decided build: "Built state but rated no: the code exists and does not work, so the change repairs it." The matrix note: "There is an endpoint, but its comparison is broken (a missing key against a placeholder string) and no page calls it."

## What stackiq has today

- `POST /api/archimate/test-round-trip` (`appinfo/routes.php:107`) runs `SettingsController::testArchiMateRoundTrip` (`lib/Controller/SettingsController.php:2886`), marked `@NoAdminRequired`: any signed-in user may call it, while the ArchiMate import itself requires an admin (:1496).
- It calls `ArchiMateService::testRoundTrip` (`lib/Service/ArchiMateService.php:1496`), which imports a built-in test model into the live AMEF register through `importArchiMateFileFromPath` (:1504) and leaves it there. The test model (:1559) uses Archi's native namespace instead of the exchange format, uses the `xsi` prefix without declaring it, and relates `test-element-1` to a `test-element-2` that does not exist. Its temporary file (:1585) is never removed.
- The comparison reads `$importResult['imported_count']` (:1528), a key no import path sets; the import returns per-section `statistics` (`lib/Service/ArchiMateImportService.php:444` and :572). It compares that with `exported_count`, which the export returns as the literal string `calculated_in_export_service` (`lib/Service/ArchiMateService.php:271`), and the export covers the whole register, not the test model. So the check can never report success. The service returns an `error` key while the controller reads `message`.
- The store action `testRoundTrip` (`src/store/modules/settings.js:1953`) has no caller. Only `tests/Unit/Controller/SettingsControllerEmailArchiMateContractTest.php:458` and :482 exercise the endpoint, with the service mocked.
- The import the settings page runs is the optimised one by default (`SettingsController.php:1594`, `useOptimized` defaults to true). It runs as separate steps: parse (`ArchiMateImportService.php:629`), read the model identifier (:663), convert to objects (`transformArchiMateXmlToObjectsBatch`, :4175) and save (:1291). The export reads objects (`ArchiMateExportService.php:808`), generates XML from them (`generateXmlDirectly`, :1009) and runs quality checks (:2143). Every imported object carries its `model_identifier` (`ArchiMateImportService.php:592`).

## What this change builds

- `lib/Service/ArchiMateModelComparator.php`, a pure comparison of two exchange files by identifier: elements, relationships, views with their nodes and connections, property definitions and property values.
- `lib/Service/ArchiMateRoundTripService.php` with two modes: before import (the import's conversion steps and the export's generation in memory, no write) and against the imported model (an export of the stored objects of that model, read-only).
- `POST /api/archimate/round-trip-check`, admin only, taking an uploaded file and a mode.
- A Check a model file part in the ArchiMate section of the admin settings, with a report per category and examples.
- Removal of the old endpoint, the service method, its test model and temporary file, and the unused store action.

## Out of scope

- Fixing what the check finds. The report names the losses; repairs to the import or export are their own changes.
- Comparing with Archi's native `.archimate` format. The check works on the Open Group exchange format that the import and export use.
- A schedule that runs the check on every import.

## Risks

- A check before import converts the whole file in memory, as an import does. On a GEMMA release that takes the same time and memory as an import, so the page says so and the route keeps the import's limits.
- The before-import mode cannot see what OpenRegister drops when it stores an object. The against-imported mode can, which is why both exist.
