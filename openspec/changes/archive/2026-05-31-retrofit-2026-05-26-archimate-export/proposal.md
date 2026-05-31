# Retrofit — archimate-export

Describes observed behavior of 12 methods as 2 REQ(s) under the `archimate-export` capability. Code already exists — this change retroactively specifies it (strict coverage policy: every method gets a spec).

## Affected code units

- lib/Service/ArchiMateExportService.php::exportArchiMateXml
- lib/Service/ArchiMateExportService.php::createCleanArchiMateXml
- lib/Service/ArchiMateExportService.php::getObjectsFromDatabase
- lib/Service/ArchiMateExportService.php::convertFromOpenRegisterObjects
- lib/Service/ArchiMateExportService.php::addObjectsToXml
- lib/Service/ArchiMateExportService.php::addElementsToXml
- lib/Service/ArchiMateExportService.php::addRelationshipsToXml
- lib/Service/ArchiMateExportService.php::addViewsToXml
- lib/Service/ArchiMateExportService.php::addOrganizationsToXml
- lib/Service/ArchiMateExportService.php::addPropertyDefinitionsToXml
- lib/Service/ArchiMateExportService.php::arrayToXml
- lib/Service/ArchiMateExportService.php::exportOrganizationArchiMateXml

## Approach

- Describe observed inputs/outputs/side-effects of each method.
- Group methods implementing the same observable behavior under one REQ.

Source: openspec/coverage-report (csc.py --mode report) generated 2026-05-26. Umbrella: ConductionNL/softwarecatalog#294.
