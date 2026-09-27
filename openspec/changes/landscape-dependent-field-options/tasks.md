# Tasks: landscape-dependent-field-options

## Implementation tasks

### Task 1: Dependent value tables and the enum fix
- **spec_ref**: openspec/changes/landscape-dependent-field-options/specs/dependent-field-options/spec.md#requirement-req-dfo-001-a-value-outside-its-dependent-list-is-refused-on-save
- **files**: `lib/Settings/register.d/dependent-field-options.json`, `lib/Settings/softwarecatalogus_register.json` (samenwerkingtype enum, organization version)
- **acceptance_criteria**:
  - GIVEN a closed-source module WHEN a licence is saved on it THEN OpenRegister refuses with dependent-value-not-allowed
  - GIVEN the merged register WHEN the licence enum and the Open source row are compared THEN they hold the same values
- [ ] Implement
- [ ] Test (PHPUnit `tests/Unit/Settings/DependentFieldOptionsTest.php`)

### Task 2: Clear disallowed values in existing rows
- **spec_ref**: openspec/changes/landscape-dependent-field-options/specs/dependent-field-options/spec.md#requirement-req-dfo-002-existing-rows-that-break-a-table-are-cleaned-before-it-applies
- **files**: `lib/Repair/ClearDisallowedDependentValues.php`, `appinfo/info.xml`
- **acceptance_criteria**:
  - GIVEN a closed-source module with a licence WHEN the repair step runs THEN its licence is empty and the count is logged
- [ ] Implement
- [ ] Test (PHPUnit `tests/Unit/Repair/ClearDisallowedDependentValuesTest.php`)

### Task 3: Adopt the library form support
- **spec_ref**: openspec/changes/landscape-dependent-field-options/specs/dependent-field-options/spec.md#requirement-req-dfo-003-the-form-offers-only-the-allowed-options
- **files**: `package.json`, `package-lock.json`
- **acceptance_criteria**:
  - GIVEN the application form WHEN the licence type is Closed source THEN the licence field offers no options
- [ ] Implement (after ConductionNL/nextcloud-vue releases CnFormDialog support for x-openregister-dependent-values)
- [ ] Test (Playwright `tests/e2e/workflows/dependent-field-options.spec.ts`)

## Verification

- `openspec validate landscape-dependent-field-options --type change --strict` passes.
- `composer check:strict` and `npm run lint` pass; the PHPUnit and Playwright cases above pass.
- No new user-facing strings beyond the error text OpenRegister already translates.
