# Tasks: sharing-generated-api-docs

## Implementation tasks

### Task 1: Extractor and the public controllers
- **spec_ref**: openspec/changes/sharing-generated-api-docs/specs/generated-api-docs/spec.md#requirement-req-gad-001-stackiqs-own-endpoints-are-described-by-a-generated-openapi-document
- **files**: `vendor-bin/openapi-extractor/composer.json`, `composer.json`, `lib/ResponseDefinitions.php`, `lib/Controller/AanbodController.php`, `lib/Controller/AangebodenGebruikController.php`, `lib/Controller/ViewController.php`, `openapi.json`
- **acceptance_criteria**:
  - GIVEN the annotated controllers WHEN composer openapi runs THEN openapi.json lists the offer, offered usage and view paths
- [ ] Implement
- [ ] Test (`composer openapi:check` exit 0; PHPUnit unchanged)

### Task 2: The remaining external controllers and the freshness check
- **spec_ref**: openspec/changes/sharing-generated-api-docs/specs/generated-api-docs/spec.md#requirement-req-gad-001-stackiqs-own-endpoints-are-described-by-a-generated-openapi-document
- **files**: `lib/Controller/GebruikController.php`, `lib/Controller/ContactpersonenController.php`, `lib/Controller/FacetController.php`, `lib/Controller/PortfolioReportController.php`, `lib/Controller/PublicationController.php`, settings controllers (ignore scope), `composer.json` (openapi:check in check:strict)
- **acceptance_criteria**:
  - GIVEN a controller change without regenerating WHEN check:strict runs THEN openapi:check fails and names the difference
- [ ] Implement
- [ ] Test (`composer check:strict` including openapi:check)

### Task 3: API documentation page
- **spec_ref**: openspec/changes/sharing-generated-api-docs/specs/generated-api-docs/spec.md#requirement-req-gad-002-a-developer-reads-both-documents-on-one-page-in-stackiq
- **files**: `src/views/ApiDocumentationView.vue`, `src/components/api/OpenApiReader.vue`, `src/customComponents.js`, `src/manifest.json` (page and footer entry), `lib/Controller/ApiDocsController.php`, `appinfo/routes.php`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN the page WHEN the developer opens Catalogue objects THEN the operations of the stackiq register show grouped by schema
  - GIVEN the page WHEN the developer clicks Download JSON on Stackiq endpoints THEN openapi.json downloads
- [ ] Implement
- [ ] Test (vitest `tests/vitest/openApiReader.spec.js`; Playwright `tests/e2e/workflows/api-docs.spec.ts`)

### Task 4: Point the hand-written docs at the generated ones
- **spec_ref**: openspec/changes/sharing-generated-api-docs/specs/generated-api-docs/spec.md#requirement-req-gad-003-the-hand-written-documentation-points-to-the-generated-documents
- **files**: `lib/Controller/ViewController.php`, `lib/Controller/AangebodenGebruikController.php`, `docs/API_REFERENCE.md`, `docs/features/api-documentation.md`, `docs/images/api-documentation.png`
- **acceptance_criteria**:
  - GIVEN GET /api/aangeboden-gebruik/docs WHEN it answers THEN the body carries a documentation link to the API documentation page
- [ ] Implement
- [ ] Test (PHPUnit `tests/Unit/Controller/AangebodenGebruikControllerTest.php` docs case; docs build with a screenshot)

## Verification

- `openspec validate sharing-generated-api-docs --type change --strict` passes.
- `composer check:strict` (with openapi:check) and `npm run lint` pass; the vitest and Playwright cases above pass.
- English and Dutch strings for every new label (ADR-005); docs with a screenshot (ADR-010).
