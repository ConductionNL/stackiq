# Tasks: security-baseline-classification

## Implementation tasks

### Task 1: Add the classification properties to usage
- **spec_ref**: openspec/changes/security-baseline-classification/specs/baseline-classification/spec.md#requirement-req-bcl-001-an-organisation-shall-classify-each-application-it-uses-on-availability-integrity-and-confidentiality
- **files**: `lib/Settings/register.d/security-baseline-classification.json`, `tests/Unit/Settings/BaselineClassificationDeclarationTest.php`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN the merged register WHEN usage is read THEN it has the nine properties, each with the owning-organisation read and update rule, and a higher version
  - GIVEN a Dutch instance WHEN the form renders THEN Beschikbaarheid, Integriteit and Vertrouwelijkheid and their reasons are Dutch
- [ ] Implement
- [ ] Test (PHPUnit tests/Unit/Settings/BaselineClassificationDeclarationTest.php)

### Task 2: Derive the overall level on every save
- **spec_ref**: openspec/changes/security-baseline-classification/specs/baseline-classification/spec.md#requirement-req-bcl-002-the-overall-bbn-level-shall-be-the-highest-of-the-three-aspects-on-every-save
- **files**: `lib/EventListener/UsageClassificationSubscriber.php`, `lib/AppInfo/Application.php`, `tests/Unit/EventListener/UsageClassificationSubscriberTest.php`
- **acceptance_criteria**:
  - GIVEN aspects BBN1, BBN2, BBN3 WHEN a real object event for a usage arrives THEN bbnLevel is BBN3
  - GIVEN no aspect WHEN the usage is saved THEN bbnLevel is empty
  - GIVEN an unchanged save WHEN the subscriber runs THEN it writes nothing
  - GIVEN an aspect change WHEN the subscriber runs THEN classifiedAt and classifiedBy are stamped
- [ ] Implement
- [ ] Test (PHPUnit tests/Unit/EventListener/UsageClassificationSubscriberTest.php, constructing the real OpenRegister event classes)

### Task 3: Add the GEMMA suggestion and the summary endpoint
- **spec_ref**: openspec/changes/security-baseline-classification/specs/baseline-classification/spec.md#requirement-req-bcl-004-municipalities-shall-see-how-organisations-classify-an-application-without-names-and-not-below-three
- **files**: `lib/Service/UsageClassificationService.php`, `lib/Controller/UsageClassificationController.php`, `appinfo/routes.php`, `tests/Unit/Service/UsageClassificationServiceTest.php`, `tests/Unit/Controller/UsageClassificationControllerTest.php`
- **acceptance_criteria**:
  - GIVEN reference components with GEMMA scores WHEN the suggestion runs THEN it returns the highest per aspect mapped onto BBN1 to BBN3 and the number of scored components
  - GIVEN three classifying organisations WHEN the summary runs THEN it returns counts per level per aspect and overall, and no organisation name or id
  - GIVEN two WHEN the summary runs THEN it returns only the organisation count
  - GIVEN a user of a supplier WHEN they call the summary THEN they get 403, and a municipal user gets 200
- [ ] Implement
- [ ] Test (PHPUnit tests/Unit/Service/UsageClassificationServiceTest.php and tests/Unit/Controller/UsageClassificationControllerTest.php)

### Task 4: Add the panels and the usage list column
- **spec_ref**: openspec/changes/security-baseline-classification/specs/baseline-classification/spec.md#requirement-req-bcl-003-stackiq-shall-suggest-the-levels-from-gemmas-reference-components
- **files**: `src/components/usages/UsageClassificationPanel.vue`, `src/components/modules/ModuleClassificationSummary.vue`, `src/customComponents.js`, `src/manifest.d/usages.json`, `src/manifest.json`, `tests/vitest/usageClassificationPanel.spec.js`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN /gebruik/:id WHEN Suggest from GEMMA is chosen THEN the three levels fill and nothing is saved until Save
  - GIVEN /gebruik WHEN it opens THEN BBN level is a column with quick filters BBN1, BBN2, BBN3 and Not classified
  - GIVEN /modules/:id with three classifying organisations WHEN it opens for a municipal user THEN the summary shows counts and no names
- [ ] Implement
- [ ] Test (vitest tests/vitest/usageClassificationPanel.spec.js and Playwright tests/e2e/spec-coverage/baseline-classification.spec.ts)

### Task 5: Seed classifications and document the feature
- **spec_ref**: openspec/changes/security-baseline-classification/specs/baseline-classification/spec.md#requirement-req-bcl-004-municipalities-shall-see-how-organisations-classify-an-application-without-names-and-not-below-three
- **files**: `lib/Settings/stackiq_mock_register.json`, `docs/features/baseline-classification.md`
- **acceptance_criteria**:
  - GIVEN a fresh demo import WHEN Voorbeeld Name 1 opens THEN the summary shows three organisations, BBN2 twice and BBN3 once
  - GIVEN the docs WHEN a reader opens the feature page THEN it shows the panel, the suggestion and the summary in screenshots and explains who sees what
- [ ] Implement
- [ ] Test (Playwright tests/e2e/spec-coverage/baseline-classification.spec.ts against the demo data)

## Verification

- `openspec validate security-baseline-classification --type change --strict`
- PHPUnit: tests/Unit/Settings/BaselineClassificationDeclarationTest.php, tests/Unit/EventListener/UsageClassificationSubscriberTest.php, tests/Unit/Service/UsageClassificationServiceTest.php, tests/Unit/Controller/UsageClassificationControllerTest.php
- vitest: tests/vitest/usageClassificationPanel.spec.js
- Playwright: tests/e2e/spec-coverage/baseline-classification.spec.ts
- Docs in docs/features/baseline-classification.md with screenshots (ADR-010)
- English and Dutch strings for the aspects, the levels, the panels and the quick filters (ADR-005)
