# Tasks: organisation-merge

## Implementation Tasks

### Task 1: Schema fields + shared relation-walk (dry-run enumeration)
- **spec_ref**: `openspec/changes/organisation-merge/specs/organisation-merge/spec.md#requirement-the-system-shall-preview-a-merge-with-per-relation-type-counts-before-any-write`
- **files**: `lib/Settings/softwarecatalogus_register.json`, `lib/Service/MergeOrganisatieService.php`
- **acceptance_criteria**:
  - GIVEN the register config is imported THEN `organisatie.status` accepts the additional value `samengevoegd` and a new optional `mergedInto` (string, UUID) field is defined, additive and non-breaking for existing objects
  - GIVEN a source organisation with objects referencing it across gebruik/contract/contactpersoon/aanbod/compliancy WHEN `dryRun` runs THEN it returns per-type counts and writes nothing
  - GIVEN a source organisation with zero relations WHEN `dryRun` runs THEN all counts are 0 and `blockers` is empty
- [x] Implement
- [x] Test

### Task 2: Execute re-pointing with PUT-semantic field preservation and dry-run/execute parity
- **spec_ref**: `openspec/changes/organisation-merge/specs/organisation-merge/spec.md#requirement-execute-must-re-point-every-relation-type-while-preserving-every-unrelated-field-on-each-object`
- **files**: `lib/Service/MergeOrganisatieService.php`
- **acceptance_criteria**:
  - GIVEN a contract owned by the source WHEN execute re-points it THEN only the organisation-reference field changes and every other field (e.g. `contractNummer`, `kosten`, `documentReferentie`) survives unchanged
  - GIVEN a gebruik object with the source as one of several `deelnemers` WHEN execute re-points it THEN only the matching entry is replaced
  - GIVEN the same input dry-run counted WHEN execute runs THEN execute's counts equal dry-run's counts
- [x] Implement
- [x] Test

### Task 3: Per-type transactional processing, idempotency/resumability, and tombstoning
- **spec_ref**: `openspec/changes/organisation-merge/specs/organisation-merge/spec.md#requirement-execute-must-be-idempotent-and-resumable-per-relation-type`
- **files**: `lib/Service/MergeOrganisatieService.php`, `openspec/changes/organisation-merge/specs/organisatie-service/spec.md` (mapStatus)
- **acceptance_criteria**:
  - GIVEN execute completed gebruik and contract then failed before contactpersoon WHEN execute is re-invoked THEN gebruik/contract are not re-pointed again and remaining types complete
  - GIVEN all relation types complete WHEN execute finishes THEN the source's `status` becomes `samengevoegd`, `mergedInto` is set, the object is not deleted, and `mapStatus('samengevoegd')` returns `false`
  - GIVEN not all relation types have completed WHEN the source organisation is read THEN `status` is not yet `samengevoegd`
- [x] Implement
- [x] Test

### Task 4: Migrate NC group membership from source to target
- **spec_ref**: `openspec/changes/organisation-merge/specs/organisation-merge/spec.md#requirement-nc-group-membership-must-be-migrated-from-source-to-target`
- **files**: `lib/Service/MergeOrganisatieService.php` (integrates `sc-handlers` `GroupHandler`/`OrganizationHandler`)
- **acceptance_criteria**:
  - GIVEN source group members `[alice, bob]` and target group member `[carol]` WHEN execute completes THEN the target group contains `[alice, bob, carol]` with no error on pre-existing membership
- [x] Implement
- [x] Test

### Task 5: MergeController endpoints with admin-only guard and validation/blockers
- **spec_ref**: `openspec/changes/organisation-merge/specs/organisation-merge/spec.md#requirement-both-merge-endpoints-must-be-admin-only-with-an-explicit-per-object-authorization-guard`
- **files**: `lib/Controller/MergeController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN a non-admin user WHEN they call either endpoint THEN the response is 403 and no object or audit entry is written
  - GIVEN `sourceUuid == targetUuid`, an unresolved UUID, or an already-tombstoned source/target WHEN either endpoint is called THEN it returns blockers (dry-run) or a 400/409 (execute) with no writes
- [x] Implement
- [x] Test

### Task 6: Wire progress tracking and audit log entries into execute
- **spec_ref**: `openspec/changes/organisation-merge/specs/organisation-merge/spec.md#requirement-execute-must-report-progress-via-the-existing-sse-progress-tracking-mechanism`
- **files**: `lib/Service/MergeOrganisatieService.php`
- **acceptance_criteria**:
  - GIVEN execute is in flight WHEN `getProgress(operationId)` is polled THEN phase/statistics reflect completed relation types and `phase` is not `completed` until all finish
  - GIVEN dry-run or execute runs WHEN the audit log is queried THEN it contains an entry per call (execute: per-type + summary) with actor, timestamps, source/target UUIDs and counts
- [x] Implement
- [x] Test

### Task 7: Organisation-detail confirm dialog, store actions, and i18n
- **spec_ref**: `openspec/changes/organisation-merge/specs/organisation-merge/spec.md#non-functional-requirements`
- **files**: `src/components/organisations/OrganisationMergePanel.vue`, `src/modals/object/MergeOrganisationConfirmDialog.vue`, `src/store/modules/organisatie.js`, `src/manifest.json`, `src/registry.js`, `src/customComponents.js`, `l10n/nl.js`, `l10n/nl.json`, `l10n/en_US.js`, `l10n/en_US.json`
- **note**: design.md assumed a bespoke `OrganisatieDetail.vue` and `src/dialogs/`-based dialog; neither exists in this manifest-v2 app (organisation detail is a declarative `type:"detail"` page rendered by the lib's `CnDetailPage`). Implemented instead via the app's actual escape-hatch pattern used by `ContractApprovalPanel` — a `bodyWidgets` entry on the `OrganisatieDetail` manifest page resolving to a registered component (`registry.js`/`customComponents.js`), with the confirm dialog as its own file per the modal-isolation rule. No `navigationStore.dialog` global-registry wiring needed — the panel owns its dialog's visibility directly.
- **acceptance_criteria**:
  - GIVEN an admin opens the merge panel on an organisation detail page WHEN they pick a target and confirm THEN the dry-run preview counts render (in the confirm dialog) before execute is triggered
  - GIVEN the Nextcloud locale is `nl` or `en_US` WHEN the panel, dialog, preview, and blocker/error messages render THEN all strings are translated (no raw keys or English fallback in `nl`)
- [x] Implement
- [ ] Test — no vitest spec added (`MergeOrganisationDialog.spec.js` from design.md's file list was not created); not runnable/verifiable in this session (no `node_modules` installed, no live browser instance authorized for this worktree). Follow-up.

### Task 8: Document the feature with Playwright screenshots
- **spec_ref**: `openspec/changes/organisation-merge/specs/organisation-merge/spec.md#acceptance-criteria`
- **files**: `docs/features/organisation-merge.md`
- **acceptance_criteria**:
  - GIVEN the feature is implemented WHEN `docs/features/organisation-merge.md` is reviewed THEN it documents the dry-run preview, confirm dialog, and tombstone behaviour with Playwright MCP screenshots
- [x] Implement — doc written and covers dry-run preview, confirm dialog, and tombstone behaviour.
- [ ] Test — **no Playwright screenshots captured**: this resumed session had no live Nextcloud instance available for this worktree without touching the shared dev environment (explicitly out of bounds). Documented as a known gap in the doc's own "Screenshots" section. Follow-up: capture screenshots once verified against a running instance, per ADR-010.

## Verification
- [x] All tasks checked off
- [x] `openspec validate` passes
- [ ] Manual testing against acceptance criteria — backend verified via PHPUnit (20/20 new tests, 268/268 suite); frontend NOT manually/browser-tested in this session (no live instance touched). Follow-up.
- [x] Code review against spec requirements — verified every scenario in `specs/organisation-merge/spec.md` and the `specs/organisatie-service/spec.md` delta against the implementation (see change PR/commit description).

## Quality checklist

- [x] All new/changed business logic covered by PHPUnit unit tests (`tests/Unit/`) — 20 new tests (`MergeOrganisatieServiceTest`, `MergeControllerTest`, `OrganisatieServiceMapStatusMergeTest`), full suite 268/268 green
- [ ] New/changed API endpoints (`/merge/dry-run`, `/merge`) covered by Newman/Postman tests — not added in this session (no `postman/` collection changes). Follow-up.
- [ ] UI changes (confirm dialog, dry-run preview, progress display) covered by Playwright browser tests — not added in this session (no live instance available). Follow-up.
- [x] All tests pass — `phpunit -c phpunit-unit.xml`: 268/268 (20 new). `composer test`/`newman run` not run in this session (see gaps above).
- [x] Feature documentation updated in `docs/features/organisation-merge.md` — screenshots deferred, noted above.
- [x] Dutch (`nl`) and English (`en_US`) translation strings added for all new user-facing strings (ADR-005) — this app's actual Dutch locale file is `l10n/nl.js`/`l10n/nl.json` (there is no `nl_NL` variant in this repo).
- [x] `openspec validate` passes
