# Tasks: deelnames-gebruik

## Task 1: Implementation planning
- **Spec ref**: specs/deelnames-gebruik/spec.md
- **Status**: done
- **Acceptance criteria**: Requirements from spec are decomposed into implementable tasks

## Task 2: Fix ViewService two-phase retrieval and RBAC bypass
- **Spec ref**: specs/deelnames-gebruik/spec.md
- **Status**: todo
- **Acceptance criteria**:
  - `getGebruikData()` returns only regular (RBAC-filtered) gebruik
  - `getDeelnamesGebruikData()` queries with `_rbac: false` and `_multitenancy: false`
  - Deelnames query filters on `deelnemers` field containing the current org UUID
  - Uses `getVoorzieningenConfig()` for correct register/schema identifiers
  - `getCurrentOrganisation()` uses OrganisationService instead of placeholder

## Task 3: Add source organization metadata to deelnames nodes
- **Spec ref**: specs/deelnames-gebruik/spec.md
- **Status**: todo
- **Acceptance criteria**:
  - Each deelnames item carries `_sourceOrganizationId` from the afnemer field
  - Each deelnames item carries `_sourceOrganization` name from the afnemer field
  - `_type: "deelnames"` is set on each deelnames item

## Task 4: Add deduplication logic in ViewService
- **Spec ref**: specs/deelnames-gebruik/spec.md
- **Status**: todo
- **Acceptance criteria**:
  - When the same elementRef appears in both owned and deelnames results, the owned version wins
  - `enrichViewNodes()` applies `array_diff_key` deduplication after fetching both datasets
  - Double-assignment bugs in `enrichViews()` and `enrichViewNodes()` are fixed

## Task 5: Add deelnames frontend toggle and view store
- **Spec ref**: specs/deelnames-gebruik/spec.md
- **Status**: todo
- **Acceptance criteria**:
  - Pinia store module `src/store/modules/view.js` with `includeGebruik`, `includeDeelnamesGebruik` state
  - `fetchViews()` action passes both flags to `/api/views`
  - `src/views/gemmaviews/GemmaViewIndex.vue` renders a toggle for deelnames, disabled by default
  - Deelnames toggle is independent from gebruik toggle

## Task 6: Add test data with deelnemers to seed data
- **Spec ref**: specs/deelnames-gebruik/spec.md
- **Status**: todo
- **Acceptance criteria**:
  - At least one gebruiksobject with `deelnemers` array containing a different org UUID
  - At least one gebruiksobject with 2+ organizations in `deelnemers`
  - Seed data added to `lib/Settings/softwarecatalogus_register.json` objects array

## Task 7: Write unit tests for ViewService
- **Spec ref**: specs/deelnames-gebruik/spec.md
- **Status**: todo
- **Acceptance criteria**:
  - `tests/Unit/Service/ViewServiceTest.php` exists and tests pass
  - Tests cover `shouldIncludeGebruik`, `shouldIncludeDeelnamesGebruik`, `getAppliedEnrichments`
  - Tests cover `processGebruikItems` adding deelnames metadata
  - Tests cover deduplication behavior
