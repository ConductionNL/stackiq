# Tasks: contracts-expiry-and-owner

## Implementation tasks

### Task 1: Add Expiring, responsibleUser and the English lifecycle to the contract schema
- **spec_ref**: openspec/changes/contracts-expiry-and-owner/specs/contract-expiry-and-owner/spec.md#requirement-req-ceo-002-the-daily-job-shall-expire-an-expiring-contract-after-its-end-date-and-shall-return-it-to-active-when-its-end-date-moves-past-the-window
- **files**: `lib/Settings/softwarecatalogus_register.json`, `tests/Unit/Settings/ContractLifecycleDeclarationTest.php`
- **acceptance_criteria**:
  - GIVEN the register WHEN `catalogContract.status` is read THEN its enum is Active, Expiring, Expired, In negotiation
  - GIVEN the register WHEN the `catalogContract` lifecycle is read THEN every state is an enum value and approach, extend and expire exist
  - GIVEN the register WHEN `responsibleUser` is read THEN it is a string with `referenceType` `nextcloud-user`
  - GIVEN the register WHEN the versions are read THEN the `catalogContract` version and `info.version` are higher than 0.1.1 and 2.5.0, with a changelog line
- [ ] Implement
- [ ] Test (PHPUnit tests/Unit/Settings/ContractLifecycleDeclarationTest.php)

### Task 2: Move contracts into and out of Expiring in the daily job
- **spec_ref**: openspec/changes/contracts-expiry-and-owner/specs/contract-expiry-and-owner/spec.md#requirement-req-ceo-001-the-contract-status-must-include-expiring-set-by-the-daily-contract-job-inside-the-notice-window
- **files**: `lib/Service/ContractStatusService.php`, `lib/BackgroundJob/ContractStatusJob.php`, `tests/Unit/Service/ContractStatusServiceTest.php`
- **acceptance_criteria**:
  - GIVEN an Active contract ending in 30 days and a 90 day window WHEN the pass runs THEN it is saved as Expiring
  - GIVEN an Expiring contract that ended yesterday WHEN the pass runs THEN it is saved as Expired
  - GIVEN an Expiring contract extended by two years WHEN the pass runs THEN it is saved as Active
  - GIVEN a contract In negotiation or Expired WHEN the pass runs THEN it is not saved
  - GIVEN `contract_expiry_window_days` is empty or zero WHEN the pass runs THEN it uses 90
- [ ] Implement
- [ ] Test (PHPUnit tests/Unit/Service/ContractStatusServiceTest.php)

### Task 3: Split the Contracts quick filter and show the responsible user
- **spec_ref**: openspec/changes/contracts-expiry-and-owner/specs/contract-expiry-and-owner/spec.md#requirement-req-ceo-003-the-contracts-page-must-let-a-user-filter-on-expiring-and-on-expired-contracts-separately
- **files**: `src/manifest.json`, `l10n/en.json`, `l10n/nl.json`, `tests/e2e/spec-coverage/contract-expiry-and-owner.spec.ts`
- **acceptance_criteria**:
  - GIVEN the Contracten page WHEN it renders THEN it offers All, Active, Expiring, Expired and In negotiation quick filters
  - GIVEN the Contracten page WHEN it renders THEN its columns include `responsibleUser`
  - GIVEN a Dutch instance WHEN the page renders THEN the filter and column labels are Dutch
- [ ] Implement
- [ ] Test (Playwright tests/e2e/spec-coverage/contract-expiry-and-owner.spec.ts, and node tests/validate-manifest.js)

### Task 4: Pick the responsible user in the contract form
- **spec_ref**: openspec/changes/contracts-expiry-and-owner/specs/contract-expiry-and-owner/spec.md#requirement-req-ceo-004-a-contract-shall-name-one-responsible-nextcloud-user-picked-from-the-users-of-the-instance
- **files**: `tests/vitest/contractResponsibleUser.spec.js`, `tests/e2e/spec-coverage/contract-expiry-and-owner.spec.ts`
- **acceptance_criteria**:
  - GIVEN the `catalogContract` schema WHEN the library resolves the field widget for `responsibleUser` THEN it is `user`
  - GIVEN an application owner on ContractDetail WHEN they pick a user and save THEN the detail shows that user
- [ ] Implement
- [ ] Test (vitest tests/vitest/contractResponsibleUser.spec.js and the Playwright spec above)

### Task 5: Add the responsible user to the contract-expiry recipients
- **spec_ref**: openspec/changes/contracts-expiry-and-owner/specs/contract-expiry-and-owner/spec.md#requirement-req-ceo-005-the-contract-expiry-warning-must-include-the-responsible-user-among-its-recipients
- **files**: `lib/Settings/softwarecatalogus_register.json`, `tests/Unit/Settings/ContractLifecycleDeclarationTest.php`
- **acceptance_criteria**:
  - GIVEN the `contract-expiry` rule WHEN its recipients are read THEN they include `{"kind": "field", "field": "responsibleUser"}`, the admins group and the manage rights recipient
- [ ] Implement
- [ ] Test (PHPUnit tests/Unit/Settings/ContractLifecycleDeclarationTest.php)

### Task 6: Seed an expiring contract and document the feature
- **spec_ref**: openspec/changes/contracts-expiry-and-owner/specs/contract-expiry-and-owner/spec.md#requirement-req-ceo-001-the-contract-status-must-include-expiring-set-by-the-daily-contract-job-inside-the-notice-window
- **files**: `lib/Settings/stackiq_mock_register.json`, `docs/features/contract-expiry-and-owner.md`
- **acceptance_criteria**:
  - GIVEN a fresh demo import WHEN the Contracts page opens THEN at least one contract reads Expiring and names a responsible user
  - GIVEN the docs WHEN a reader opens the feature page THEN it explains the window setting and shows a screenshot of the Expiring filter
- [ ] Implement
- [ ] Test (Playwright tests/e2e/spec-coverage/contract-expiry-and-owner.spec.ts against the demo data)

## Verification

- `openspec validate contracts-expiry-and-owner --type change --strict`
- PHPUnit: tests/Unit/Service/ContractStatusServiceTest.php and tests/Unit/Settings/ContractLifecycleDeclarationTest.php
- vitest: tests/vitest/contractResponsibleUser.spec.js
- Playwright: tests/e2e/spec-coverage/contract-expiry-and-owner.spec.ts
- Docs in docs/features/contract-expiry-and-owner.md with a screenshot of the Contracts page (ADR-010)
- English and Dutch strings for Expiring, Expired, Responsible user in l10n/en.json and l10n/nl.json (ADR-005)
