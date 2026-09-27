# Tasks: insight-supplier-facet

## Implementation tasks

### Task 1: Compute the supplier dimension in FacetService
- **spec_ref**: openspec/changes/insight-supplier-facet/specs/supplier-facet/spec.md#requirement-req-sfc-001-the-facet-endpoint-shall-return-a-supplier-facet-with-a-count-per-organisation
- **files**: `lib/Service/FacetService.php`, `tests/Unit/Service/FacetServiceTest.php`
- **acceptance_criteria**:
  - GIVEN three modules with two suppliers WHEN getFacets('module') runs THEN the supplier bucket holds two entries with the organisation id as value, the name as label and counts 2 and 1
  - GIVEN two organisations with the same name WHEN the bucket is built THEN they stay two entries
  - GIVEN a service whose provider differs from its module's supplier WHEN getFacets('catalogService') runs THEN the service counts under its own provider
  - GIVEN a supplier selection WHEN the facets are computed THEN matchedObjectIds holds only that supplier's objects and the supplier counts ignore the supplier selection
  - GIVEN an organisation without a name WHEN the label is built THEN the label is its id
  - GIVEN any request WHEN the organisation labels are read THEN it is one bounded query with an explicit limit
- [ ] Implement
- [ ] Test (PHPUnit tests/Unit/Service/FacetServiceTest.php)

### Task 2: Move the dimension set together and key the cache on it
- **spec_ref**: openspec/changes/insight-supplier-facet/specs/supplier-facet/spec.md#requirement-req-sfc-003-the-facet-dimension-set-must-be-the-same-in-the-service-the-controller-and-the-client
- **files**: `lib/Service/FacetService.php`, `lib/Controller/FacetController.php`, `src/services/facets.js`, `src/services/facets.spec.js`, `tests/Unit/Controller/FacetControllerTest.php`, `tests/Unit/Service/FacetServiceTest.php`
- **acceptance_criteria**:
  - GIVEN a request with supplier[] WHEN FacetController parses it THEN the supplier filter reaches FacetService
  - GIVEN the three declarations WHEN the tests run THEN they hold the same five names
  - GIVEN two dimension lists WHEN buildCacheKey() runs for the same request THEN the keys differ
- [ ] Implement
- [ ] Test (PHPUnit tests/Unit/Controller/FacetControllerTest.php and tests/Unit/Service/FacetServiceTest.php, vitest src/services/facets.spec.js)

### Task 3: Show Supplier in the facet sidebar
- **spec_ref**: openspec/changes/insight-supplier-facet/specs/supplier-facet/spec.md#requirement-req-sfc-002-the-applications-and-services-pages-shall-offer-supplier-as-a-facet-that-narrows-the-list
- **files**: `src/views/FacetedCatalogIndexView.vue`, `tests/vitest/facetSchema.spec.js`, `tests/vitest/facetStore.spec.js`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN /modules WHEN the sidebar renders THEN it shows Supplier with the label from each entry, not its id
  - GIVEN a supplier chosen WHEN the URL updates THEN it carries _gf_supplier, and a reload restores the choice
  - GIVEN a saved facet view with a supplier WHEN it is applied THEN the supplier is chosen again
  - GIVEN a Dutch instance WHEN the sidebar renders THEN the facet title reads Leverancier
- [ ] Implement
- [ ] Test (vitest tests/vitest/facetSchema.spec.js and tests/vitest/facetStore.spec.js, Playwright tests/e2e/spec-coverage/supplier-facet.spec.ts)

### Task 4: Seed suppliers and document the facet
- **spec_ref**: openspec/changes/insight-supplier-facet/specs/supplier-facet/spec.md#requirement-req-sfc-002-the-applications-and-services-pages-shall-offer-supplier-as-a-facet-that-narrows-the-list
- **files**: `lib/Settings/stackiq_mock_register.json`, `docs/features/supplier-facet.md`
- **acceptance_criteria**:
  - GIVEN a fresh demo import WHEN /modules opens THEN the Supplier facet shows Voorbeeld Name 2 with 2 and Voorbeeld Name 3 with 1
  - GIVEN the docs WHEN a reader opens the feature page THEN it shows a screenshot of the Supplier facet on /modules
- [ ] Implement
- [ ] Test (Playwright tests/e2e/spec-coverage/supplier-facet.spec.ts against the demo data)

## Verification

- `openspec validate insight-supplier-facet --type change --strict`
- PHPUnit: tests/Unit/Service/FacetServiceTest.php and tests/Unit/Controller/FacetControllerTest.php
- vitest: src/services/facets.spec.js, tests/vitest/facetSchema.spec.js and tests/vitest/facetStore.spec.js
- Playwright: tests/e2e/spec-coverage/supplier-facet.spec.ts
- Docs in docs/features/supplier-facet.md with a screenshot (ADR-010)
- English and Dutch strings for the facet title (ADR-005)
