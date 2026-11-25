# Tasks: sharing-compliance-documents

## Implementation tasks

### Task 1: Schema and audience rules
- **spec_ref**: openspec/changes/sharing-compliance-documents/specs/shared-compliance-documents/spec.md#requirement-req-scd-002-the-audience-decides-who-reads-a-document
- **files**: `lib/Settings/register.d/compliance-documents.json`, `lib/Settings/stackiq_mock_register.json`
- **acceptance_criteria**:
  - GIVEN a pentest report shared with municipality A WHEN municipality B lists compliance documents THEN it is not listed
  - GIVEN a public processing agreement WHEN an anonymous visitor reads the product's documents through the API THEN it is listed
- [x] Implement
- [x] Test (PHPUnit `tests/Unit/Settings/ComplianceDocumentsFragmentTest.php`, `tests/Unit/Settings/SchemaRbacTest.php` cases for the three audiences)

### Task 2: Product page section and the documents list
- **spec_ref**: openspec/changes/sharing-compliance-documents/specs/shared-compliance-documents/spec.md#requirement-req-scd-001-an-organisation-publishes-a-compliance-document-about-a-product
- **files**: `src/manifest.json` (ModuleDetail list), `src/manifest.d/compliance-documents.json`, `src/customComponents.js` (public audience confirmation), `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN a supplier on its product page WHEN it adds a processing agreement with audience government THEN the product page lists it for a municipal user
  - GIVEN documents with different validity WHEN the user picks Expiring within 90 days THEN only those remain
- [ ] Implement. Built: the list on the application page (publish from it, soonest expiry first), the documents list with a quick filter per type, the detail page, menu entry, en and nl strings, and `tests/vitest/complianceDocuments.spec.js`. Built 17 Nov: the "Expiring within 90 days" quick filter, on the materialised `expiresWithin90Days` calculation over `validUntil` (with `daysUntilExpiry`; schema 0.2.0), kept current by OpenRegister's temporal sweep. Left: the confirmation before choosing the public audience. The document is created in the shared `CnObjectListWidget` form, which has no hook to confirm one field value before saving, so it needs that hook in @conduction/nextcloud-vue first.
      25 Nov 2026: the hook is built as `content.confirmCreate` on CnObjectListWidget (nextcloud-vue#2397, merge queued for the coordinator), and the ModuleDetail list declares it: a pentest report with audience public asks "Anyone can read this pentest report, also without an account. Publish it publicly?" (en, nl, de) before it is saved. Left: raise @conduction/nextcloud-vue (lock 2.212.0) to the first release that contains #2397; until then the key is inert. Tick this task then.
- [x] Test (Playwright `tests/e2e/workflows/compliance-documents.spec.ts`, including an organisation outside a named share) — written 17 Nov (the supplier publishes from the product page, a named share read by A and not by B, the 90-day filter); not run yet, needs a live instance (e2e written, runs in nightly; not verified on a live instance)

### Task 3: Documentation
- **spec_ref**: openspec/changes/sharing-compliance-documents/specs/shared-compliance-documents/spec.md#requirement-req-scd-001-an-organisation-publishes-a-compliance-document-about-a-product
- **files**: `docs/features/compliance-documents.md`, `docs/images/compliance-documents.png`
- **acceptance_criteria**:
  - GIVEN the docs site WHEN a reader opens Compliance documents THEN publishing, the three audiences and expiry are explained with a screenshot
- [x] Implement. Built: `docs/features/compliance-documents.md` (publishing, the three audiences, finding documents). The screenshot `docs/images/compliance-documents.png` was added 25 Nov 2026.
- [x] Test (docs build, screenshot with Playwright) (screenshot captured 25 Nov 2026 against a live sandbox instance with `npx playwright test --project docs` (`tests/e2e/docs/screenshots.spec.ts`); docs build passes)

## Verification

- `openspec validate sharing-compliance-documents --type change --strict` passes.
- `composer check:strict` and `npm run lint` pass; the PHPUnit and Playwright cases above pass.
- English and Dutch strings for every new label (ADR-005); docs with a screenshot (ADR-010).
