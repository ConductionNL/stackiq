# Tasks: connections-diagram-and-graph-export

## Implementation tasks

### Task 1: Connection map on the application page
- **spec_ref**: openspec/changes/connections-diagram-and-graph-export/specs/connection-diagram-and-export/spec.md#requirement-req-cdx-001-the-application-page-draws-the-applications-connections-as-a-map
- **files**: `src/components/connections/ConnectionMapWidget.vue`, `src/customComponents.js`, `src/manifest.json` (ModuleDetail bodyWidgets), `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN an application with three connections WHEN its page opens THEN the map shows the application in the centre and three linked nodes with transport labels
  - GIVEN a node on the map WHEN the user clicks it THEN the linked application or connection opens
- [ ] Implement
- [ ] Test (vitest `tests/vitest/connectionMap.spec.js` for nodes and edges from connections; Playwright `tests/e2e/workflows/connections.spec.ts` map case)

### Task 2: Diagram view on the connections list
- **spec_ref**: openspec/changes/connections-diagram-and-graph-export/specs/connection-diagram-and-export/spec.md#requirement-req-cdx-002-the-connections-list-can-be-shown-as-a-diagram-of-the-filtered-rows
- **files**: `src/components/connections/ConnectionsDiagram.vue`, `src/utils/connectionLayout.js`, `src/manifest.d/connections.json`
- **acceptance_criteria**:
  - GIVEN the connections list filtered on type api WHEN the user switches to Diagram THEN only the api connections are drawn
  - GIVEN the same rows WHEN the diagram renders twice THEN every node keeps its position
- [ ] Implement
- [ ] Test (vitest `tests/vitest/connectionLayout.spec.js` for a stable layered layout)

### Task 3: Downloads from the map
- **spec_ref**: openspec/changes/connections-diagram-and-graph-export/specs/connection-diagram-and-export/spec.md#requirement-req-cdx-003-a-user-can-download-an-applications-map-and-its-links
- **files**: `src/components/connections/ConnectionMapWidget.vue`, `src/utils/connectionExport.js`
- **acceptance_criteria**:
  - GIVEN the map of an application WHEN the user picks Download links as CSV THEN a CSV with one line per connection downloads
  - GIVEN the same map WHEN the user picks Download as SVG THEN an SVG with the drawn nodes downloads
- [ ] Implement
- [ ] Test (vitest `tests/vitest/connectionExport.spec.js` for CSV columns and escaping)

### Task 4: Connections in the organisation ArchiMate export
- **spec_ref**: openspec/changes/connections-diagram-and-graph-export/specs/connection-diagram-and-export/spec.md#requirement-req-cdx-004-the-organisation-archimate-export-can-carry-the-organisations-connections
- **files**: `lib/Controller/SettingsController.php`, `lib/Service/ArchiMateService.php`, `lib/Service/ArchiMateExportService.php`, `src/views/settings/sections/ArchiMateImportExport.vue`
- **acceptance_criteria**:
  - GIVEN an organisation with two connections between its applications WHEN the export runs with connections on THEN the file holds two flow relationships with transport properties
  - GIVEN a connection whose other end is outside the export WHEN the export runs THEN it is skipped and counted
- [ ] Implement
- [ ] Test (PHPUnit `tests/Unit/Service/ArchiMateExportConnectionsTest.php` against a fixture model; Playwright `tests/e2e/org-archimate-export.spec.ts` extended with the checkbox)

### Task 5: Documentation
- **spec_ref**: openspec/changes/connections-diagram-and-graph-export/specs/connection-diagram-and-export/spec.md#requirement-req-cdx-001-the-application-page-draws-the-applications-connections-as-a-map
- **files**: `docs/features/connections.md`, `docs/images/connection-map.png`
- **acceptance_criteria**:
  - GIVEN the docs site WHEN a reader opens Connections THEN the map, the diagram and the export option are explained with a screenshot
- [ ] Implement
- [ ] Test (docs build, screenshot with Playwright)

## Verification

- `openspec validate connections-diagram-and-graph-export --type change --strict` passes.
- `composer check:strict` and `npm run lint` pass; the PHPUnit, vitest and Playwright cases above pass.
- English and Dutch strings for every new label (ADR-005); docs with a screenshot (ADR-010).
