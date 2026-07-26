# Test Plan: organisation-merge

## Test Cases

### TC-1: Dry-run reports per-relation-type counts without writing
- **spec_ref**: `openspec/changes/organisation-merge/specs/organisation-merge/spec.md#requirement-the-system-shall-preview-a-merge-with-per-relation-type-counts-before-any-write`
- **type**: api
- **persona**: n/a (admin-only backend behaviour)
- **preconditions**: organisation A has 12 gebruik, 4 contract, 7 contactpersoon, 3 aanbod, 9 compliancy objects referencing it, and 5 NC group members
- **steps**: `POST /api/organisaties/{A}/merge/dry-run` with `{targetUuid: B}`
- **expected result**: response reports `{gebruik:12, contract:4, contactpersoon:7, aanbod:3, compliancy:9, groupMembers:5}`; none of those objects or organisation A are modified
- **test command**: `/test-api`

### TC-2: Dry-run on a relation-free organisation reports all zeros, no blockers
- **spec_ref**: `openspec/changes/organisation-merge/specs/organisation-merge/spec.md#requirement-the-system-shall-preview-a-merge-with-per-relation-type-counts-before-any-write`
- **type**: api
- **preconditions**: organisation A has no referencing objects and no group members
- **steps**: `POST /api/organisaties/{A}/merge/dry-run` with `{targetUuid: B}`
- **expected result**: every count is `0`; `blockers` is empty
- **test command**: `/test-api`

### TC-3: Execute re-points exactly what dry-run counted (parity)
- **spec_ref**: `openspec/changes/organisation-merge/specs/organisation-merge/spec.md#requirement-dry-run-and-execute-must-report-structurally-identical-counts-for-the-same-unchanged-input`
- **type**: api
- **preconditions**: dry-run for (A, B) reported known counts; no relation objects change between the two calls
- **steps**: `POST /api/organisaties/{A}/merge/dry-run`, then `POST /api/organisaties/{A}/merge` with the same target
- **expected result**: execute's response `counts` equal dry-run's `counts`; the exact number of objects of each type are re-pointed
- **test command**: `/test-api`

### TC-4: Untouched fields survive re-pointing (PUT-semantics)
- **spec_ref**: `openspec/changes/organisation-merge/specs/organisation-merge/spec.md#requirement-execute-must-re-point-every-relation-type-while-preserving-every-unrelated-field-on-each-object`
- **type**: api
- **preconditions**: a `contract` owned by A has `contractNummer: "C-100"`, `kosten: 5000`, `documentReferentie` set
- **steps**: execute merge (A → B)
- **expected result**: the contract's organisation-reference field equals B; `contractNummer`, `kosten`, `documentReferentie` are unchanged
- **test command**: `/test-api`

### TC-5: A gebruik object with the source as one of several deelnemers only replaces the matching entry
- **spec_ref**: `openspec/changes/organisation-merge/specs/organisation-merge/spec.md#requirement-execute-must-re-point-every-relation-type-while-preserving-every-unrelated-field-on-each-object`
- **type**: api
- **preconditions**: a `gebruik` object has `deelnemers: [A, C, D]`
- **steps**: execute merge (A → B)
- **expected result**: `deelnemers` becomes `[B, C, D]`; C and D unaffected
- **test command**: `/test-api`

### TC-6: Re-running execute after a partial failure only finishes remaining relation types
- **spec_ref**: `openspec/changes/organisation-merge/specs/organisation-merge/spec.md#requirement-execute-must-be-idempotent-and-resumable-per-relation-type`
- **type**: api
- **preconditions**: a prior execute call completed `gebruik` and `contract` then failed before `contactpersoon`
- **steps**: re-invoke `POST /api/organisaties/{A}/merge` with the same target
- **expected result**: gebruik/contract objects are not re-pointed a second time; contactpersoon/aanbod/compliancy complete; final audit summary counts each type exactly once
- **test command**: `/test-api`

### TC-7: Re-running a fully completed merge is a safe no-op
- **spec_ref**: `openspec/changes/organisation-merge/specs/organisation-merge/spec.md#requirement-execute-must-be-idempotent-and-resumable-per-relation-type`
- **type**: api
- **preconditions**: execute (A → B) previously completed and tombstoned A
- **steps**: re-invoke `POST /api/organisaties/{A}/merge` with the same target
- **expected result**: no relation object is modified; response reports the merge as already completed, not an error
- **test command**: `/test-api`

### TC-8: Source organisation is tombstoned only after every relation type completes
- **spec_ref**: `openspec/changes/organisation-merge/specs/organisation-merge/spec.md#requirement-the-source-organisation-must-be-tombstoned-never-hard-deleted`
- **type**: api
- **preconditions**: execute (A → B) is mid-run with gebruik/contract done, contactpersoon/aanbod/compliancy pending
- **steps**: read organisation A at that point; then let execute finish and re-read A
- **expected result**: mid-run, A's `status` is not yet `samengevoegd`; after completion, `status = 'samengevoegd'`, `mergedInto = B`, A still exists (not deleted), all other fields unchanged
- **test command**: `/test-api`

### TC-9: Tombstoned organisation is excluded from the default listing but resolvable by direct lookup
- **spec_ref**: `openspec/changes/organisation-merge/specs/organisation-merge/spec.md#requirement-the-source-organisation-must-be-tombstoned-never-hard-deleted`
- **type**: functional
- **preconditions**: organisation A has `status = 'samengevoegd'`
- **steps**: open the Organisaties index; then navigate directly to A's detail URL
- **expected result**: A does not appear in the default index listing; A's detail page still resolves (deep link works, e.g. for a `mergedInto` redirect)
- **test command**: `/test-functional`

### TC-10: mapStatus treats the tombstone status as inactive
- **spec_ref**: `openspec/changes/organisation-merge/specs/organisatie-service/spec.md#requirement-the-system-shall-update-the-active-flag-of-an-openregister-organisation-from-a-softwarecatalog-status-req-002`
- **type**: api
- **preconditions**: an OR organisation entity exists for A
- **steps**: call `updateOrganizationStatus(A, {beoordeling: 'samengevoegd'})` (invoked by the merge tombstone step)
- **expected result**: the OR entity's `active` flag becomes `false`; `mapStatus('samengevoegd')` returns `false`
- **test command**: `/test-api`

### TC-11: NC group membership is migrated from source to target
- **spec_ref**: `openspec/changes/organisation-merge/specs/organisation-merge/spec.md#requirement-nc-group-membership-must-be-migrated-from-source-to-target`
- **type**: api
- **preconditions**: A's NC group has members `[alice, bob]`; B's NC group has member `[carol]`
- **steps**: execute merge (A → B)
- **expected result**: B's NC group contains `[alice, bob, carol]`; no error for pre-existing membership overlap
- **test command**: `/test-api`

### TC-12: Non-admin user is rejected on both endpoints
- **spec_ref**: `openspec/changes/organisation-merge/specs/organisation-merge/spec.md#requirement-both-merge-endpoints-must-be-admin-only-with-an-explicit-per-object-authorization-guard`
- **type**: security
- **preconditions**: an authenticated user who is not a member of the `admin` group
- **steps**: call `POST /api/organisaties/{A}/merge/dry-run` and `POST /api/organisaties/{A}/merge`
- **expected result**: both return 403; no relation object modified; no audit entry written
- **test command**: `/test-security`

### TC-13: Admin user is authorized
- **spec_ref**: `openspec/changes/organisation-merge/specs/organisation-merge/spec.md#requirement-both-merge-endpoints-must-be-admin-only-with-an-explicit-per-object-authorization-guard`
- **type**: api
- **preconditions**: an authenticated user in the `admin` group
- **steps**: call `POST /api/organisaties/{A}/merge/dry-run` with a valid target
- **expected result**: 200 with per-type counts
- **test command**: `/test-api`

### TC-14: Self-merge, already-tombstoned-source, and already-tombstoned-target are rejected
- **spec_ref**: `openspec/changes/organisation-merge/specs/organisation-merge/spec.md#requirement-merge-requests-must-be-validated-and-rejected-with-blockers-before-any-write`
- **type**: api
- **preconditions**: (a) `sourceUuid == targetUuid`; (b) target B already has `status = 'samengevoegd'`; (c) source A already has `status = 'samengevoegd'` with a different target C requested
- **steps**: call execute for each precondition
- **expected result**: each returns an error (400/409); no object is modified; a previously-tombstoned A's `mergedInto` is unchanged by (c)
- **test command**: `/test-api`

### TC-15: Long-running merge is observable via the existing progress endpoint
- **spec_ref**: `openspec/changes/organisation-merge/specs/organisation-merge/spec.md#requirement-execute-must-report-progress-via-the-existing-sse-progress-tracking-mechanism`
- **type**: api
- **preconditions**: execute (A → B) is in flight, 2 of 5 relation types complete
- **steps**: `getProgress(operationId)`
- **expected result**: snapshot's `processed_items`/`statistics` reflect the 2 completed types; `phase` is not `completed`
- **test command**: `/test-api`

### TC-16: Every dry-run and execute call is audit-logged
- **spec_ref**: `openspec/changes/organisation-merge/specs/organisation-merge/spec.md#requirement-every-dry-run-and-execute-call-must-produce-an-audit-log-entry`
- **type**: security
- **preconditions**: admin `admin1` runs dry-run then execute for (A, B)
- **steps**: query the audit log for this operation
- **expected result**: entries exist for the dry-run call and for execute's summary (actor `admin1`, source/target UUIDs, per-type counts)
- **test command**: `/test-security`

### TC-17: Admin confirm-dialog flow end-to-end
- **spec_ref**: `openspec/changes/organisation-merge/specs/organisation-merge/spec.md#requirement-the-system-shall-preview-a-merge-with-per-relation-type-counts-before-any-write`
- **type**: functional
- **persona**: Noor (Municipal CISO / Functional Admin) — the persona who would actually run a herindeling merge
- **preconditions**: logged in as an admin on an organisation detail page with a mergeable target organisation available
- **steps**: open the merge confirm dialog, select a target, review the dry-run preview counts, confirm execute, observe progress
- **expected result**: dry-run counts render before any write; execute runs with visible progress; on completion the source shows as merged/tombstoned and is gone from the default listing
- **test command**: `/test-persona-noor`

### TC-18: Merge dialog and messages are fully localized
- **spec_ref**: `openspec/changes/organisation-merge/specs/organisation-merge/spec.md#non-functional-requirements`
- **type**: functional
- **preconditions**: Nextcloud locale set to `nl_NL`, then `en_US`
- **steps**: open the merge dialog, trigger a blocker (e.g. self-merge attempt) to see the error message, in each locale
- **expected result**: no raw translation keys or English fallback text appears under `nl_NL`
- **test command**: `/test-functional`

### TC-19: Merge confirm dialog and progress indicator meet WCAG 2.2 AA
- **spec_ref**: `openspec/changes/organisation-merge/specs/organisation-merge/spec.md#non-functional-requirements`
- **type**: accessibility
- **preconditions**: merge dialog implemented
- **steps**: open the dialog via keyboard only; run the accessibility checker against the dialog and progress indicator
- **expected result**: dialog is keyboard-operable with correct focus management and screen-reader announcement; progress state is exposed as text, not color alone
- **test command**: `/test-accessibility`

### TC-20: Pre-existing organisation/contract/gebruik flows are unaffected by the new schema fields
- **spec_ref**: `openspec/changes/organisation-merge/specs/organisation-merge/spec.md#requirement-the-source-organisation-must-be-tombstoned-never-hard-deleted`
- **type**: regression
- **preconditions**: existing organisations, contracts, and gebruik records created before this change (no `mergedInto`, `status` never `samengevoegd`)
- **steps**: exercise existing organisatie-service, organization-sync, and contract-administration flows (create, sync, status transitions) against those records
- **expected result**: behaviour is unchanged; the new optional `mergedInto` field and `samengevoegd` status value do not alter existing flows
- **test command**: `/test-regression`

## Coverage Summary
- Dry-run preview + zero-relations edge case — covered (TC-1, TC-2)
- Dry-run/execute parity — covered (TC-3)
- PUT-semantics field preservation (scalar + array relation fields) — covered (TC-4, TC-5)
- Idempotency / resumability (partial failure, full re-run) — covered (TC-6, TC-7)
- Tombstone behaviour (timing, exclusion from listing, direct resolvability, OR `active` flag) — covered (TC-8, TC-9, TC-10)
- NC group membership migration — covered (TC-11)
- Admin-only authorization (reject + accept) — covered (TC-12, TC-13)
- Validation/blockers (self-merge, tombstoned source/target) — covered (TC-14)
- Progress tracking — covered (TC-15)
- Audit logging — covered (TC-16)
- End-to-end admin UX (persona) — covered (TC-17)
- i18n — covered (TC-18)
- Accessibility — covered (TC-19)
- Regression on pre-existing flows — covered (TC-20)

## Out of Scope
- Undo/rollback of a completed merge — no test cases, since the capability itself is explicitly out of scope (proposal.md).
- Bulk multi-organisation merges — not tested, out of scope.
- Multi-step wizard UX — only the simple confirm-dialog flow (TC-17) is tested; no wizard exists to test.
- Performance/load testing at scale (hundreds of relation types across thousands of objects) is deferred; TC coverage validates correctness, not throughput, beyond the qualitative non-functional target in design.md.
