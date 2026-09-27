# Tasks: operations-record-reconciliation

## Implementation tasks

### Task 1: Declare the duplicate and merge rules and the record status
- **spec_ref**: openspec/changes/operations-record-reconciliation/specs/record-reconciliation/spec.md#requirement-req-rrc-001-applications-services-and-organisations-shall-declare-duplicate-rules-and-applications-and-services-shall-declare-how-they-merge
- **files**: `lib/Settings/register.d/operations-record-reconciliation.json`, `lib/Settings/softwarecatalogus_register.json`, `lib/Repair/` (a step that sets recordStatus Active on existing rows), `tests/Unit/Settings/ReconciliationDeclarationTest.php`
- **acceptance_criteria**:
  - GIVEN the merged register WHEN module and catalogService are read THEN both carry x-openregister-dedup and x-openregister-merge in configuration, recordStatus and mergedInto in properties, and higher versions
  - GIVEN existing rows WHEN the repair step runs THEN every module and service has recordStatus Active
- [ ] Implement
- [ ] Test (PHPUnit tests/Unit/Settings/ReconciliationDeclarationTest.php)

### Task 2: Share one reference map and complete the organisation merge
- **spec_ref**: openspec/changes/operations-record-reconciliation/specs/record-reconciliation/spec.md#requirement-req-rrc-005-the-organisation-merge-must-re-point-every-reference-to-the-merged-organisation
- **files**: `lib/Service/CatalogueReferenceMap.php`, `lib/Service/MergeOrganisatieService.php`, `tests/Unit/Service/CatalogueReferenceMapTest.php`, `tests/Unit/Service/MergeOrganisatieServiceTest.php`
- **acceptance_criteria**:
  - GIVEN the merged register WHEN every $ref to module, catalogService or organization is collected THEN the map lists each one, and the test fails for a missing one
  - GIVEN an organisation merge WHEN it runs in dry run or execute THEN module.provider, catalogService.provider, usage.provider, organization.deelnames, organization.participants and model.organizations are counted and re-pointed
- [ ] Implement
- [ ] Test (PHPUnit tests/Unit/Service/CatalogueReferenceMapTest.php and tests/Unit/Service/MergeOrganisatieServiceTest.php)

### Task 3: Re-point references after OpenRegister merges applications or services
- **spec_ref**: openspec/changes/operations-record-reconciliation/specs/record-reconciliation/spec.md#requirement-req-rrc-003-after-openregister-merges-two-applications-or-services-every-catalogue-reference-shall-point-at-the-survivor
- **files**: `lib/EventListener/CatalogueMergeRelinker.php`, `lib/AppInfo/Application.php`, `tests/Unit/EventListener/CatalogueMergeRelinkerTest.php`
- **acceptance_criteria**:
  - GIVEN a real ObjectsMergedEvent for module WHEN the listener runs THEN every mapped reference to the loser points at the survivor and the loser has mergedInto
  - GIVEN an array holding both uuids WHEN the listener runs THEN it holds the survivor once
  - GIVEN an event for another schema WHEN the listener runs THEN it changes nothing
  - GIVEN a moved reference WHEN the listener finishes THEN one audit entry names it with the merge operation id
- [ ] Implement
- [ ] Test (PHPUnit tests/Unit/EventListener/CatalogueMergeRelinkerTest.php, constructing the real OpenRegister event class)

### Task 4: Hide merged records and add the banner and the Find duplicates action
- **spec_ref**: openspec/changes/operations-record-reconciliation/specs/record-reconciliation/spec.md#requirement-req-rrc-004-merged-applications-and-services-shall-leave-the-lists-and-point-readers-to-the-survivor
- **files**: `lib/Service/FacetService.php`, `src/components/merge/MergedRecordBanner.vue`, `src/views/FacetedCatalogIndexView.vue`, `src/manifest.json`, `src/customComponents.js`, `tests/Unit/Service/FacetServiceTest.php`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN a Merged module WHEN /modules and its facets load THEN it is not counted or listed
  - GIVEN a Merged module WHEN its detail page opens THEN the banner links to the survivor
  - GIVEN an admin or functional administrator WHEN /modules, /diensten or /organisaties opens THEN Find duplicates opens OpenRegister's Duplicate candidates page, and other users do not see it
- [ ] Implement
- [ ] Test (PHPUnit tests/Unit/Service/FacetServiceTest.php and Playwright tests/e2e/spec-coverage/record-reconciliation.spec.ts)

### Task 5: Remove the unused merge modal, seed a duplicate and document the flow
- **spec_ref**: openspec/changes/operations-record-reconciliation/specs/record-reconciliation/spec.md#requirement-req-rrc-002-the-catalogue-pages-shall-lead-an-administrator-to-openregisters-duplicate-candidates
- **files**: `src/modals/object/MergeObject.vue`, `src/modals/Modals.vue`, `src/store/plugins/stackiqPlugin.js`, `lib/Settings/stackiq_mock_register.json`, `docs/features/record-reconciliation.md`
- **acceptance_criteria**:
  - GIVEN the source tree WHEN it is searched THEN MergeObject.vue, the mergeOrganisatie modal branch and mergeObjects are gone and the build passes
  - GIVEN a fresh demo import WHEN OpenRegister's candidates page is opened for applications THEN the seeded pair is listed
  - GIVEN the docs WHEN a reader opens the feature page THEN it shows the steps with screenshots and says what a reversal does not restore yet
- [ ] Implement
- [ ] Test (Playwright tests/e2e/spec-coverage/record-reconciliation.spec.ts against the demo data)

## Verification

- `openspec validate operations-record-reconciliation --type change --strict`
- PHPUnit: tests/Unit/Settings/ReconciliationDeclarationTest.php, tests/Unit/Service/CatalogueReferenceMapTest.php, tests/Unit/Service/MergeOrganisatieServiceTest.php, tests/Unit/EventListener/CatalogueMergeRelinkerTest.php, tests/Unit/Service/FacetServiceTest.php
- Playwright: tests/e2e/spec-coverage/record-reconciliation.spec.ts
- Docs in docs/features/record-reconciliation.md with screenshots (ADR-010)
- English and Dutch strings for the action, the banner and the record status values (ADR-005)
