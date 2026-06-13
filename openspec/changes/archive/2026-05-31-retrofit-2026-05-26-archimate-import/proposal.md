# Retrofit — archimate-import

Describes observed behavior of 23 methods as 2 REQ(s) under the `archimate-import` capability. Code already exists — this change retroactively specifies it (strict coverage policy: every method gets a spec).

## Affected code units

- lib/Service/ArchiMateImportService.php::importArchiMateFileFromPath
- lib/Service/ArchiMateImportService.php::importArchiMateFileFromPathOptimized
- lib/Service/ArchiMateImportService.php::xmlToArray
- lib/Service/ArchiMateImportService.php::getPropertyNameMapping
- lib/Service/ArchiMateImportService.php::getAmefConfig
- lib/Service/ArchiMateService.php::importArchiMateFileFromPath
- lib/Service/ArchiMateService.php::importArchiMateFileFromPathOptimized
- lib/Service/ArchiMateService.php::exportToArchiMate
- lib/Service/ArchiMateService.php::exportOrgArchiMate
- lib/Service/ArchiMateService.php::testRoundTrip
- lib/Service/ArchiMateService.php::getAmefConfig
- lib/Service/ArchiMateService.php::getArchiMateStatus
- lib/Service/ArchiMateService.php::getElementObjects
- lib/Service/ArchiMateService.php::getOrganizationObjects
- lib/Service/ArchiMateService.php::getViewObjects
- lib/Service/ArchiMateService.php::getRelationshipObjects
- lib/Service/ArchiMateService.php::getModelObjects
- lib/Service/ArchiMateService.php::getPropertyObjects
- lib/Service/ArchiMateService.php::getPropertyDefinitionObjects
- lib/Service/ArchiMateService.php::isImportInProgress
- lib/Service/ArchiMateService.php::isExportInProgress
- lib/Service/ArchiMateService.php::isOperationInProgress
- lib/Service/ArchiMateService.php::getPropertyNameMapping

## Approach

- Describe observed inputs/outputs/side-effects of each method.
- Group methods implementing the same observable behavior under one REQ.

Source: openspec/coverage-report (csc.py --mode report) generated 2026-05-26. Umbrella: ConductionNL/softwarecatalog#294.
