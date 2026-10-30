# Tasks: supplier-accepts-claimed-usage

## Implementation tasks

### Task 1: Claimed usages and the answer in the API
- **spec_ref**: openspec/changes/supplier-accepts-claimed-usage/specs/aanbod-listings/spec.md#requirement-a-supplier-answers-the-usages-claimed-of-its-products-req-010
- **files**: `lib/Service/AanbodService.php`, `lib/Controller/AanbodController.php`, `appinfo/routes.php`, `lib/Settings/softwarecatalogus_register.json`, `tests/Unit/Service/AanbodServiceClaimedUsageTest.php`, `tests/Unit/Controller/AanbodControllerClaimedUsageTest.php`
- **acceptance_criteria**:
  - GIVEN the usage schema WHEN read THEN it has `providerResponse` (enum accepted, declined), `providerResponseAt` and `providerResponseBy`
  - GIVEN `GET /api/aanbod/claimed` WHEN called by a member of a supplier THEN it returns unanswered usages with provider = that supplier and an owner other than it
  - GIVEN `PUT /api/aanbod/claimed/{uuid}/accept` or `/decline` WHEN called by a member of the provider THEN the response fields are set with the service identity and the usage is not deleted; otherwise 403 before any write
- [ ] Implement
- [ ] Test (PHPUnit `AanbodServiceClaimedUsageTest`, `AanbodControllerClaimedUsageTest`)

### Task 2: The list on the supplier's page (board SkOrganisatie)
- **spec_ref**: openspec/changes/supplier-accepts-claimed-usage/specs/aanbod-listings/spec.md#requirement-a-supplier-answers-the-usages-claimed-of-its-products-req-010
- **files**: `src/manifest.json`, `src/components/organisation/ClaimedUsageList.vue`, `src/customComponents.js`, `tests/vitest/claimedUsageList.spec.js`, `tests/e2e/spec-coverage/claimed-usage.spec.ts`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN OrganisatieDetail of a supplier WHEN a member opens it THEN "Claimed usage of our offering" lists each claim with organisation, product, version, module and date, and Accept and Decline
  - GIVEN a non-member WHEN the page opens THEN the list is not shown
  - GIVEN Decline WHEN chosen THEN a confirmation dialog (in `src/dialogs/`) asks first
- [ ] Implement
- [ ] Test (vitest `claimedUsageList.spec.js`, Playwright `claimed-usage.spec.ts`)

### Task 3: The claiming organisation sees the answer
- **spec_ref**: openspec/changes/supplier-accepts-claimed-usage/specs/aanbod-listings/spec.md#requirement-a-supplier-answers-the-usages-claimed-of-its-products-req-010
- **files**: `src/manifest.d/usages.json`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN a usage with `providerResponse: declined` WHEN its owner opens GebruikDetail THEN a notice says the supplier does not recognise this usage, with the date
- [ ] Implement
- [ ] Test (`tests/validate-manifest.js`)
