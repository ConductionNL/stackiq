# Tasks: landscape-owner-attestation

## Implementation tasks

### Task 1: Round and request schemas with notifications
- **spec_ref**: openspec/changes/landscape-owner-attestation/specs/owner-attestation/spec.md#requirement-req-oat-002-each-owner-is-notified-and-reminded
- **files**: `lib/Settings/register.d/owner-attestation.json`
- **acceptance_criteria**:
  - GIVEN the merged register WHEN it is imported THEN attestationRound and attestationRequest exist with their lifecycle and notification rules
- [ ] Implement
- [ ] Test (PHPUnit `tests/Unit/Settings/OwnerAttestationFragmentTest.php`)

### Task 2: Start a round
- **spec_ref**: openspec/changes/landscape-owner-attestation/specs/owner-attestation/spec.md#requirement-req-oat-001-an-information-manager-starts-a-confirmation-round-for-a-scope
- **files**: `lib/Service/AttestationService.php`, `lib/Controller/AttestationController.php`, `appinfo/routes.php`, `lib/AppInfo/Application.php`
- **acceptance_criteria**:
  - GIVEN four usages with owners and one without WHEN an organisation admin starts a round THEN four requests exist and one entry is reported unassigned
  - GIVEN a user who is not an organisation admin WHEN they post a round THEN the answer is 403
- [ ] Implement
- [ ] Test (PHPUnit `tests/Unit/Service/AttestationServiceTest.php`, `tests/Unit/Controller/AttestationControllerTest.php`)

### Task 3: Confirm, correct and overdue
- **spec_ref**: openspec/changes/landscape-owner-attestation/specs/owner-attestation/spec.md#requirement-req-oat-003-an-owner-confirms-or-corrects-each-entry
- **files**: `lib/Controller/AttestationController.php`, `lib/Listener/AttestationCorrectionListener.php`, `lib/BackgroundJob/AttestationOverdueJob.php`, `appinfo/info.xml`
- **acceptance_criteria**:
  - GIVEN a pending request WHEN its owner confirms THEN the entry's lastConfirmedAt is today and the request is confirmed
  - GIVEN a pending request WHEN another user edits the entry THEN the request stays pending
  - GIVEN a pending request past its deadline WHEN the job runs THEN it is overdue
- [ ] Implement
- [ ] Test (PHPUnit `tests/Unit/Listener/AttestationCorrectionListenerTest.php` with the real ObjectUpdatedEvent class, `tests/Unit/BackgroundJob/AttestationOverdueJobTest.php`)

### Task 4: Owner and round pages
- **spec_ref**: openspec/changes/landscape-owner-attestation/specs/owner-attestation/spec.md#requirement-req-oat-004-the-round-shows-who-answered-and-who-is-overdue
- **files**: `src/manifest.d/owner-attestation.json`, `src/dialogs/StartAttestationRoundDialog.vue`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN an owner with two pending requests WHEN they open My confirmation requests THEN both show with Confirm
  - GIVEN a round with answers WHEN the information manager opens it THEN it shows confirmed, corrected, pending and overdue counts
- [ ] Implement
- [ ] Test (Playwright `tests/e2e/workflows/owner-attestation.spec.ts`)

### Task 5: Documentation
- **spec_ref**: openspec/changes/landscape-owner-attestation/specs/owner-attestation/spec.md#requirement-req-oat-001-an-information-manager-starts-a-confirmation-round-for-a-scope
- **files**: `docs/features/owner-attestation.md`, `docs/images/confirmation-round.png`
- **acceptance_criteria**:
  - GIVEN the docs site WHEN a reader opens Confirmation rounds THEN starting a round, answering and following it are explained with a screenshot
- [ ] Implement
- [ ] Test (docs build, screenshot with Playwright)

## Verification

- `openspec validate landscape-owner-attestation --type change --strict` passes.
- `composer check:strict` and `npm run lint` pass; the PHPUnit and Playwright cases above pass.
- English and Dutch strings for every new label and notification (ADR-005); docs with a screenshot (ADR-010).
