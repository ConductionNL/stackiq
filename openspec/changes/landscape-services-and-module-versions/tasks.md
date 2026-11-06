# Tasks: landscape-services-and-module-versions

## Implementation tasks

### Task 1: Tag and test module versions
- **spec_ref**: openspec/changes/landscape-services-and-module-versions/specs/module-versions/spec.md#requirement-a-released-version-of-an-application-is-recorded-with-its-lifecycle-dates-req-mvr-001
- **files**: `src/manifest.json`, `tests/vitest/moduleVersionPages.spec.js`
- **acceptance_criteria**:
  - GIVEN the Moduleversies and ModuleversieDetail pages WHEN read THEN their `_note` cites REQ-MVR-001
  - GIVEN the merged manifest WHEN the vitest reads Moduleversies THEN it lists version, module, dateInDevelopment, dateInUse and status and has the "Planned releases" quick filter on status "in development"
- [ ] Implement
- [ ] Test (vitest `moduleVersionPages.spec.js`)

### Task 2: Tag and test services
- **spec_ref**: openspec/changes/landscape-services-and-module-versions/specs/catalogue-services/spec.md#requirement-a-suppliers-service-is-registered-over-one-or-more-applications-req-csv-001
- **files**: `src/manifest.json`, `tests/Unit/Settings/CatalogServiceSchemaTest.php`
- **acceptance_criteria**:
  - GIVEN the Diensten page and the org-diensten widget WHEN read THEN their `_note` cites REQ-CSV-001
  - GIVEN `lib/Settings/softwarecatalogus_register.json` WHEN the test reads `catalogService` THEN name, provider and type are required and type holds the six service types
- [ ] Implement
- [ ] Test (PHPUnit `CatalogServiceSchemaTest`)

### Task 3: Matrix
- **spec_ref**: openspec/changes/landscape-services-and-module-versions/specs/module-versions/spec.md
- **files**: `openspec/parity/capabilities.json`
- **acceptance_criteria**:
  - GIVEN the change is archived WHEN the matrix is read THEN both rows cite the merged specs
- [ ] Implement
