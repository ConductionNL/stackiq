# Tasks: sharing-compliance-documents

## Implementation tasks

### Task 1: Schema and audience rules
- **spec_ref**: openspec/changes/sharing-compliance-documents/specs/shared-compliance-documents/spec.md#requirement-req-scd-002-the-audience-decides-who-reads-a-document
- **files**: `lib/Settings/register.d/compliance-documents.json`, `lib/Settings/stackiq_mock_register.json`
- **acceptance_criteria**:
  - GIVEN a pentest report shared with municipality A WHEN municipality B lists compliance documents THEN it is not listed
  - GIVEN a public processing agreement WHEN an anonymous visitor reads the product's documents through the API THEN it is listed
- [ ] Implement
- [ ] Test (PHPUnit `tests/Unit/Settings/ComplianceDocumentsFragmentTest.php`, `tests/Unit/Settings/SchemaRbacTest.php` cases for the three audiences)

### Task 2: Product page section and the documents list
- **spec_ref**: openspec/changes/sharing-compliance-documents/specs/shared-compliance-documents/spec.md#requirement-req-scd-001-an-organisation-publishes-a-compliance-document-about-a-product
- **files**: `src/manifest.json` (ModuleDetail list), `src/manifest.d/compliance-documents.json`, `src/customComponents.js` (public audience confirmation), `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN a supplier on its product page WHEN it adds a processing agreement with audience government THEN the product page lists it for a municipal user
  - GIVEN documents with different validity WHEN the user picks Expiring within 90 days THEN only those remain
- [ ] Implement
- [ ] Test (Playwright `tests/e2e/workflows/compliance-documents.spec.ts`, including an organisation outside a named share)

### Task 3: Documentation
- **spec_ref**: openspec/changes/sharing-compliance-documents/specs/shared-compliance-documents/spec.md#requirement-req-scd-001-an-organisation-publishes-a-compliance-document-about-a-product
- **files**: `docs/features/compliance-documents.md`, `docs/images/compliance-documents.png`
- **acceptance_criteria**:
  - GIVEN the docs site WHEN a reader opens Compliance documents THEN publishing, the three audiences and expiry are explained with a screenshot
- [ ] Implement
- [ ] Test (docs build, screenshot with Playwright)

## Verification

- `openspec validate sharing-compliance-documents --type change --strict` passes.
- `composer check:strict` and `npm run lint` pass; the PHPUnit and Playwright cases above pass.
- English and Dutch strings for every new label (ADR-005); docs with a screenshot (ADR-010).
