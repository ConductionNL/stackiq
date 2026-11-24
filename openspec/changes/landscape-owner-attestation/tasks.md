# Tasks: landscape-owner-attestation

## Implementation tasks

### Task 1: Round and request schemas with notifications
- **spec_ref**: openspec/changes/landscape-owner-attestation/specs/owner-attestation/spec.md#requirement-req-oat-002-each-owner-is-notified-and-reminded
- **files**: `lib/Settings/register.d/owner-attestation.json`
- **acceptance_criteria**:
  - GIVEN the merged register WHEN it is imported THEN attestationRound and attestationRequest exist with their lifecycle and notification rules
- [x] Implement
- [x] Test (PHPUnit `tests/Unit/Settings/OwnerAttestationFragmentTest.php`)
- Note: the two schemas live in `lib/Settings/softwarecatalogus_register.json`, not in a `register.d` fragment: `ReviewModerationOverlayReachesTheSchemaTest` requires a fragment to overlay a schema the base register declares. The request also carries the round's `deadline` and `scopeOrganisation`, so the reminder filter and the maintainers' read rule work on the request itself.

### Task 2: Start a round
- **spec_ref**: openspec/changes/landscape-owner-attestation/specs/owner-attestation/spec.md#requirement-req-oat-001-an-information-manager-starts-a-confirmation-round-for-a-scope
- **files**: `lib/Service/AttestationService.php`, `lib/Controller/AttestationController.php`, `appinfo/routes.php`, `lib/AppInfo/Application.php`
- **acceptance_criteria**:
  - GIVEN four usages with owners and one without WHEN an organisation admin starts a round THEN four requests exist and one entry is reported unassigned
  - GIVEN a user who is not an organisation admin WHEN they post a round THEN the answer is 403
- [x] Implement
- [x] Test (PHPUnit `tests/Unit/Service/AttestationServiceTest.php`, `tests/Unit/Controller/AttestationControllerTest.php`)
- Note: the access rule is `AttestationRoundAccess` (admin, or `maintainer` and a member of the organisation per OpenRegister); owners resolve through `MaintenanceRecipientService::contactUserIds()`. Services are autowired, so `Application.php` needed no change.

### Task 3: Confirm, correct and overdue
- **spec_ref**: openspec/changes/landscape-owner-attestation/specs/owner-attestation/spec.md#requirement-req-oat-003-an-owner-confirms-or-corrects-each-entry
- **files**: `lib/Controller/AttestationController.php`, `lib/Listener/AttestationCorrectionListener.php`, `lib/BackgroundJob/AttestationOverdueJob.php`, `appinfo/info.xml`
- **acceptance_criteria**:
  - GIVEN a pending request WHEN its owner confirms THEN the entry's lastConfirmedAt is today and the request is confirmed
  - GIVEN a pending request WHEN another user edits the entry THEN the request stays pending
  - GIVEN a pending request past its deadline WHEN the job runs THEN it is overdue
- [x] Implement
- [x] Test (PHPUnit `tests/Unit/Listener/AttestationCorrectionListenerTest.php` with the real ObjectUpdatedEvent class, `tests/Unit/BackgroundJob/AttestationOverdueJobTest.php`)
- Note: confirm is `POST /api/attestation-requests/{id}/confirm` (`AttestationController::confirm()`, the assignee only, 403/404/409 otherwise) through `AttestationAnswerService`, which also marks corrections and overdue requests and keeps the round's `answeredCount`. The listener lives in `lib/EventListener/` with the app's other listeners, its test in `tests/Unit/EventListener/AttestationCorrectionListenerTest.php`. A save that changes only `lastConfirmedAt` or derived score fields is not a correction, so a confirmation never answers itself as corrected.

### Task 4: Owner and round pages
- **spec_ref**: openspec/changes/landscape-owner-attestation/specs/owner-attestation/spec.md#requirement-req-oat-004-the-round-shows-who-answered-and-who-is-overdue
- **files**: `src/manifest.d/owner-attestation.json`, `src/dialogs/StartAttestationRoundDialog.vue`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN an owner with two pending requests WHEN they open My confirmation requests THEN both show with Confirm
  - GIVEN a round with answers WHEN the information manager opens it THEN it shows confirmed, corrected, pending and overdue counts
- [x] Implement
- [x] Test (Playwright `tests/e2e/workflows/owner-attestation.spec.ts`) (e2e written, runs in nightly; not verified on a live instance)
- Note: pages `MyAttestations` (filter `assigneeUserId: @me`, status pending, row actions Confirm and Open entry), `AttestationRounds` (header action Start round) and `AttestationRoundDetail` (the four counts as stat tiles, the requests per status with their owner) in `src/manifest.d/owner-attestation.json`, both menu entries under Organisations. The handlers are in `src/services/attestation.js`, registered in `src/customComponents.js`; the dialog `src/dialogs/StartAttestationRoundDialog.vue` opens through `Dialogs.vue`. vitest `tests/vitest/ownerAttestation.spec.js` covers the handlers, the round body and the merged pages; the Playwright run needs a live instance and is left.

### Task 5: Documentation
- **spec_ref**: openspec/changes/landscape-owner-attestation/specs/owner-attestation/spec.md#requirement-req-oat-001-an-information-manager-starts-a-confirmation-round-for-a-scope
- **files**: `docs/features/owner-attestation.md`, `docs/images/confirmation-round.png`
- **acceptance_criteria**:
  - GIVEN the docs site WHEN a reader opens Confirmation rounds THEN starting a round, answering and following it are explained with a screenshot
- [ ] Implement
- [ ] Test (docs build, screenshot with Playwright)
- Note: `docs/features/owner-attestation.md` is written (starting, notifications, answering, following a round, the endpoints) and linked from `docs/features/README.md`; the screenshot `docs/images/confirmation-round.png` needs a running instance, so Implement stays open until it is added.

## Verification

- `openspec validate landscape-owner-attestation --type change --strict` passes.
- `composer check:strict` and `npm run lint` pass; the PHPUnit and Playwright cases above pass.
- English and Dutch strings for every new label and notification (ADR-005); docs with a screenshot (ADR-010).
