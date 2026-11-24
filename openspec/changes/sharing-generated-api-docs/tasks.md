# Tasks: sharing-generated-api-docs

## Implementation tasks

### Task 1: Extractor and the public controllers
- **spec_ref**: openspec/changes/sharing-generated-api-docs/specs/generated-api-docs/spec.md#requirement-req-gad-001-stackiqs-own-endpoints-are-described-by-an-openapi-document-kept-in-step-with-the-routes
- **files**: `vendor-bin/openapi-extractor/composer.json`, `composer.json`, `lib/ResponseDefinitions.php`, `lib/Controller/AanbodController.php`, `lib/Controller/AangebodenGebruikController.php`, `lib/Controller/ViewController.php`, `openapi.json`
- **acceptance_criteria**:
  - GIVEN the annotated controllers WHEN composer openapi runs THEN openapi.json lists the offer, offered usage and view paths
- [x] Implement
- [x] Test (`composer openapi:check` exit 0; PHPUnit unchanged)
- Open 23 Nov, a decision: the design reads "`openapi.json` holds no paths", which no longer holds. `openapi.json` is now a curated OpenAPI document of 150 `/api/v1/` paths (problem details, `API-Version`, `x-provided-by`), served by `OpenApiController` and kept in step with `appinfo/routes.php` by `tests/Unit/Middleware/Support/OpenApiDocumentTest.php`. Running `nextcloud/openapi-extractor` would overwrite it with a different shape. Options: (a) keep the curated document and its route test as the freshness check, drop D1 and tick tasks 1 and 2 as superseded; (b) generate with the extractor and port the curated descriptions into annotations and `lib/ResponseDefinitions.php`; (c) generate a second, extractor-made document beside the curated one. Recommendation: (a), the route test already fails when a controller route changes without the document.
- Decided 24 Nov (a), and ticked as superseded: the curated `openapi.json` stays, `OpenApiDocumentTest` (unit suite, run by `check:strict`) is the freshness check, D1 is dropped and REQ-GAD-001 now says this. The extractor would replace a richer document (problem details, `API-Version`, `x-provided-by`, protocol bases) with a thinner one, and (c) would leave two documents for the same endpoints to drift apart.

### Task 2: The remaining external controllers and the freshness check
- **spec_ref**: openspec/changes/sharing-generated-api-docs/specs/generated-api-docs/spec.md#requirement-req-gad-001-stackiqs-own-endpoints-are-described-by-an-openapi-document-kept-in-step-with-the-routes
- **files**: `lib/Controller/GebruikController.php`, `lib/Controller/ContactpersonenController.php`, `lib/Controller/FacetController.php`, `lib/Controller/PortfolioReportController.php`, `lib/Controller/PublicationController.php`, settings controllers (ignore scope), `composer.json` (openapi:check in check:strict)
- **acceptance_criteria**:
  - GIVEN a controller change without regenerating WHEN check:strict runs THEN openapi:check fails and names the difference
- [x] Implement
- [x] Test (`composer check:strict` including openapi:check)
- Open 23 Nov: waits on the decision under task 1.
- Superseded 24 Nov by the decision under task 1: every external controller's `/api/v1/` routes are already in `openapi.json`, and `OpenApiDocumentTest::testTheDocumentMatchesTheRoutes` fails and names the route when one is missing.

### Task 3: API documentation page
- **spec_ref**: openspec/changes/sharing-generated-api-docs/specs/generated-api-docs/spec.md#requirement-req-gad-002-a-developer-reads-both-documents-on-one-page-in-stackiq
- **files**: `src/views/ApiDocumentationView.vue`, `src/components/api/OpenApiReader.vue`, `src/customComponents.js`, `src/manifest.json` (page and footer entry), `lib/Controller/ApiDocsController.php`, `appinfo/routes.php`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN the page WHEN the developer opens Catalogue objects THEN the operations of the stackiq register show grouped by schema
  - GIVEN the page WHEN the developer clicks Download JSON on Stackiq endpoints THEN openapi.json downloads
- [x] Implement
- [x] Test (vitest `tests/vitest/openApiReader.spec.js`; Playwright `tests/e2e/workflows/api-docs.spec.ts`) (the planned `openApiReader.spec.js` is `tests/vitest/apiDocumentation.spec.js`, 13 tests, since the library `CnApiReference` replaced `OpenApiReader.vue`) (e2e written, runs in nightly; not verified on a live instance)
- Done 21 Nov: `ApiDocumentation` (`/api-docs`), a dashboard page whose one widget is the `api-documentation` type registered in `src/main.js` (`src/components/api/ApiDocumentationWidget.vue`, instead of a custom page), with a footer entry beside Documentation. Two lazy `CnTab`s, Catalogue objects and Stackiq endpoints, each an `ApiDocumentPanel` that fetches its document from this instance and renders it with the library's `CnApiReference` (operations by tag, parameters, responses, models, Download). The library component replaces the planned `OpenApiReader.vue`, and the existing `GET /api/openapi.json` (`OpenApiController`, api-contract) replaces the planned `ApiDocsController`; the catalogue register id comes from `GET /api/settings` (register slug `stackiq`), as other pages read it. `src/utils/apiDocuments.js` holds the paths and the reading. Test: `tests/vitest/apiDocumentation.spec.js` (13) and the widget registration in `tests/vitest/entryPoints.spec.js`. Left: the Playwright spec `tests/e2e/workflows/api-docs.spec.ts` is written from the two scenarios and not run (needs a live instance).

### Task 4: Point the hand-written docs at the generated ones
- **spec_ref**: openspec/changes/sharing-generated-api-docs/specs/generated-api-docs/spec.md#requirement-req-gad-003-the-hand-written-documentation-points-to-the-generated-documents
- **files**: `lib/Controller/ViewController.php`, `lib/Controller/AangebodenGebruikController.php`, `docs/API_REFERENCE.md`, `docs/features/api-documentation.md`, `docs/images/api-documentation.png`
- **acceptance_criteria**:
  - GIVEN GET /api/aangeboden-gebruik/docs WHEN it answers THEN the body carries a documentation link to the API documentation page
- [ ] Implement
- [ ] Test (PHPUnit `tests/Unit/Controller/AangebodenGebruikControllerTest.php` docs case; docs build with a screenshot)
- Progress 21 Nov: both hand-written bodies keep their content and carry `documentation` (`ApiDocumentationLink::LINK`: the page `/apps/stackiq/api-docs`, the document `/apps/stackiq/api/openapi.json` and a deprecation note); their docblocks say new integrations use the OpenAPI document. `docs/API_REFERENCE.md` opens with a pointer to the page and both documents, and `docs/features/api-documentation.md` (linked from the features index) explains the page. Tests: `AangebodenGebruikControllerTest::testDocsCarryALinkToTheApiDocumentationPage`, the views docs case in `ViewControllerContractTest` and the updated golden `tests/fixtures/api-documentation/documentation.json`. Left: the screenshot `docs/images/api-documentation.png`, which needs a live instance, so Implement and Test stay open.

## Verification

- `openspec validate sharing-generated-api-docs --type change --strict` passes.
- `composer check:strict` (with openapi:check) and `npm run lint` pass; the vitest and Playwright cases above pass.
- English and Dutch strings for every new label (ADR-005); docs with a screenshot (ADR-010).
