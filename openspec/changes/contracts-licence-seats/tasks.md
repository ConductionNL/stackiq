# Tasks: contracts-licence-seats

## Implementation tasks

### Task 1: Add the licence metric and the two counts to the contract schema
- **spec_ref**: openspec/changes/contracts-licence-seats/specs/licence-seats/spec.md#requirement-req-lsc-001-a-contract-shall-record-its-licence-metric-and-the-number-of-licences-bought-and-in-use
- **files**: `lib/Settings/register.d/contracts-licence-seats.json`, `tests/Unit/Settings/LicenceSeatsDeclarationTest.php`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN the merged register WHEN `catalogContract` is read THEN it has `licenceMetric` with six values and `licencesBought` and `licencesInUse` as integers with minimum 0
  - GIVEN the merged register WHEN the `catalogContract` version is read THEN it is higher than 0.1.1
  - GIVEN a Dutch instance WHEN the form renders THEN the three field titles and the six metric values are Dutch
- [ ] Implement
- [ ] Test (PHPUnit tests/Unit/Settings/LicenceSeatsDeclarationTest.php)

### Task 2: Compute the seat position and the seat rows
- **spec_ref**: openspec/changes/contracts-licence-seats/specs/licence-seats/spec.md#requirement-req-lsc-003-the-license-posture-page-shall-list-every-counted-licence-contract-with-its-seat-state-over-use-first
- **files**: `src/utils/licensePosture.js`, `tests/vitest/licensePosture.spec.js`
- **acceptance_criteria**:
  - GIVEN 400 bought and 460 in use WHEN seatPosition() runs THEN it returns over by 60
  - GIVEN Per organisation or Other WHEN seatPosition() runs THEN it returns not counted
  - GIVEN an empty count WHEN seatPosition() runs THEN it returns unknown
  - GIVEN mixed contracts WHEN seatRows() runs THEN over-licence rows come first, most over first, and uncounted contracts are left out
- [ ] Implement
- [ ] Test (vitest tests/vitest/licensePosture.spec.js)

### Task 3: Show the seats panel on the contract detail page
- **spec_ref**: openspec/changes/contracts-licence-seats/specs/licence-seats/spec.md#requirement-req-lsc-002-the-contract-detail-page-must-show-licences-in-use-against-licences-bought
- **files**: `src/components/contracts/ContractSeatsPanel.vue`, `src/customComponents.js`, `src/manifest.json`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN a contract over its licence WHEN ContractDetail opens THEN the panel reads Over licence by N with an error bar
  - GIVEN a contract with Per organisation WHEN ContractDetail opens THEN the panel reads Not counted
  - GIVEN any contract WHEN the panel renders THEN it shows the last change date
- [ ] Implement
- [ ] Test (Playwright tests/e2e/spec-coverage/licence-seats.spec.ts)

### Task 4: Add the Seats section to the License posture page
- **spec_ref**: openspec/changes/contracts-licence-seats/specs/licence-seats/spec.md#requirement-req-lsc-003-the-license-posture-page-shall-list-every-counted-licence-contract-with-its-seat-state-over-use-first
- **files**: `src/views/LicensePostureView.vue`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN one over-licence and one within-licence contract WHEN /license-posture opens THEN the Seats section lists the over-licence contract first
  - GIVEN no counted contracts WHEN the section renders THEN it shows an empty state in English or Dutch
- [ ] Implement
- [ ] Test (Playwright tests/e2e/spec-coverage/licence-seats.spec.ts)

### Task 5: Seed licence counts, mark the overlay and document the feature
- **spec_ref**: openspec/changes/contracts-licence-seats/specs/licence-seats/spec.md#requirement-req-lsc-001-a-contract-shall-record-its-licence-metric-and-the-number-of-licences-bought-and-in-use
- **files**: `lib/Settings/stackiq_mock_register.json`, `openspec/features.overlay.json`, `docs/features/licence-seats.md`
- **acceptance_criteria**:
  - GIVEN a fresh demo import WHEN /license-posture opens THEN the Seats section shows one over-licence and one within-licence contract
  - GIVEN the overlay WHEN `license-and-seat-tracking` is read THEN its status is available
  - GIVEN the docs WHEN a reader opens the feature page THEN it shows a screenshot of the seats panel and of the Seats section
- [ ] Implement
- [ ] Test (Playwright tests/e2e/spec-coverage/licence-seats.spec.ts against the demo data)

## Verification

- `openspec validate contracts-licence-seats --type change --strict`
- PHPUnit: tests/Unit/Settings/LicenceSeatsDeclarationTest.php
- vitest: tests/vitest/licensePosture.spec.js
- Playwright: tests/e2e/spec-coverage/licence-seats.spec.ts and the existing tests/e2e/spec-coverage/license-posture.spec.ts
- Docs in docs/features/licence-seats.md with screenshots (ADR-010)
- English and Dutch strings for the metric values, the panel states and the section title (ADR-005)
