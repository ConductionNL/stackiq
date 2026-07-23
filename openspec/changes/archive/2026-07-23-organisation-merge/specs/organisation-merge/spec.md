# organisation-merge Specification

**Status**: planned
**Scope**: softwarecatalog
**OpenSpec changes**:
- [organisation-merge](../../changes/organisation-merge/)

## Purpose
Lets an admin fold organisation A (gemeentelijke herindeling or leveranciersovername) into organisation B: re-pointing gebruik, contracts, contactpersoon role records, aanbod/koppeling ownership, compliance records, and NC group membership onto the target, then soft-retiring the source with a tombstone. Provides a dry-run preview before any write and an idempotent, audited execute step, per VNG Softwarecatalogus issue #141 and ADR-001/ADR-008/ADR-009.

## ADDED Requirements

### Requirement: The system SHALL preview a merge with per-relation-type counts before any write
`MergeOrganisatieService::dryRun(sourceUuid, targetUuid)` MUST enumerate every object referencing `sourceUuid` across `gebruik` (`afnemer`, `deelnemers`), `contract`, `contactpersoon` (`organisatie`), `aanbod`/`koppeling` (`aanbieder`), and `compliancy` (`@self.organisation`) plus the count of NC group members who would be migrated, and MUST return a count per relation type without writing, saving, or otherwise mutating any object. The dry-run MUST use the same relation-enumeration logic execute uses (see the parity requirement below), gated by a `commit: false` flag rather than a separate implementation.

#### Scenario: Dry-run reports counts without writing
- GIVEN organisation A has 12 gebruik, 4 contract, 7 contactpersoon, 3 aanbod, and 9 compliancy objects referencing it, and 5 NC group members
- WHEN `dryRun('A-uuid', 'B-uuid')` is called
- THEN the response MUST report `{gebruik: 12, contract: 4, contactpersoon: 7, aanbod: 3, compliancy: 9, groupMembers: 5}`
- AND no object referencing A MUST have been modified
- AND organisation A's `status` MUST remain unchanged

#### Scenario: Dry-run on organisations with no relations reports all zeros
- GIVEN organisation A has no objects referencing it and no group members
- WHEN `dryRun('A-uuid', 'B-uuid')` is called
- THEN every count in the response MUST be `0`
- AND `blockers` MUST be empty (a merge with zero relations is still a legal, executable merge)

### Requirement: Dry-run and execute MUST report structurally identical counts for the same unchanged input
Because dry-run and execute share one relation-walking routine gated by `commit`, the per-type counts `dryRun` reports for a given `(sourceUuid, targetUuid)` pair MUST equal the number of objects `execute` actually re-points for that same pair, provided no relation objects are created, deleted, or re-pointed by another process between the two calls.

#### Scenario: Execute re-points exactly what dry-run counted
- GIVEN `dryRun('A-uuid', 'B-uuid')` reported `{gebruik: 12, contract: 4, contactpersoon: 7, aanbod: 3, compliancy: 9}`
- AND no relation object is created, deleted, or modified between the dry-run and the execute call
- WHEN `execute('A-uuid', 'B-uuid')` is called
- THEN exactly 12 `gebruik`, 4 `contract`, 7 `contactpersoon`, 3 `aanbod`, and 9 `compliancy` objects MUST be re-pointed
- AND the execute response's `counts` MUST equal the dry-run response's `counts`

### Requirement: Execute MUST re-point every relation type while preserving every unrelated field on each object
`MergeOrganisatieService::execute(sourceUuid, targetUuid)` MUST, for every object identified by the relation walk, read the object's full current payload, replace only the organisation-reference field(s) that equal `sourceUuid` with `targetUuid` (including array fields such as `deelnemers` where only the matching entry is replaced), and re-save the complete payload — because OpenRegister's `saveObject` is PUT-semantic, omitting any existing field would null it.

#### Scenario: An untouched field survives re-pointing
- GIVEN a `contract` object owned by organisation A with `contractNummer: "C-100"`, `kosten: 5000`, and `documentReferentie` set to an NC Files link
- WHEN `execute('A-uuid', 'B-uuid')` re-points that contract
- THEN the contract's organisation-reference field MUST equal `B-uuid`
- AND `contractNummer`, `kosten`, and `documentReferentie` MUST be unchanged from their pre-merge values

#### Scenario: A gebruik object with the source as one of several deelnemers only replaces the matching entry
- GIVEN a `gebruik` object with `deelnemers: ['A-uuid', 'C-uuid', 'D-uuid']`
- WHEN `execute('A-uuid', 'B-uuid')` re-points that gebruik object
- THEN `deelnemers` MUST equal `['B-uuid', 'C-uuid', 'D-uuid']`
- AND `C-uuid` and `D-uuid` MUST be unaffected

### Requirement: Execute MUST be idempotent and resumable per relation type
Execute MUST process relation types one at a time, each inside its own transactional unit, and MUST record which types have completed for a given merge operation. Re-invoking `execute` for a merge operation that already completed some relation types MUST NOT re-process or double-move already-completed types, and MUST NOT fail the objects that were never touched by re-pointing them twice (no duplicate re-point, no double-count in the audit log).

#### Scenario: Re-running execute after a partial failure only finishes remaining types
- GIVEN a prior `execute('A-uuid', 'B-uuid')` call completed the `gebruik` and `contract` types then failed before processing `contactpersoon`
- WHEN `execute('A-uuid', 'B-uuid')` is called again
- THEN `gebruik` and `contract` objects MUST NOT be re-pointed a second time
- AND `contactpersoon`, `aanbod`, and `compliancy` MUST be processed to completion
- AND the final audit summary MUST report each relation type's count exactly once

#### Scenario: Re-running a fully completed merge is a safe no-op
- GIVEN `execute('A-uuid', 'B-uuid')` previously completed all relation types and tombstoned A
- WHEN `execute('A-uuid', 'B-uuid')` is called again
- THEN no relation object MUST be modified
- AND the response MUST report the merge as already completed rather than erroring

### Requirement: The source organisation MUST be tombstoned, never hard-deleted
On successful completion of all relation types, `execute` MUST update the source organisation (via a full, PUT-semantic re-save preserving all other fields) to set `status = 'samengevoegd'` and `mergedInto = targetUuid`. The source organisation MUST NOT be deleted. Listing queries for organisations MUST exclude organisations whose `status` equals `'samengevoegd'` by filtering on that status field, not by relying on soft-delete.

#### Scenario: Source organisation is tombstoned after a successful merge
- GIVEN `execute('A-uuid', 'B-uuid')` completes all relation types successfully
- WHEN organisation A is subsequently read
- THEN A's `status` MUST equal `'samengevoegd'`
- AND A's `mergedInto` MUST equal `'B-uuid'`
- AND A MUST still exist as a readable object (not deleted)
- AND every other pre-existing field on A MUST be unchanged

#### Scenario: Tombstoned organisation is excluded from the default organisation listing
- GIVEN organisation A has `status = 'samengevoegd'`
- WHEN the default organisation index listing is queried
- THEN A MUST NOT appear in the results
- AND A MUST still be resolvable by direct UUID lookup (e.g. for the redirect the tombstone's `mergedInto` supports)

#### Scenario: The tombstone is applied only after every relation type completes
- GIVEN `execute('A-uuid', 'B-uuid')` has completed `gebruik` and `contract` but not yet `contactpersoon`, `aanbod`, or `compliancy`
- WHEN organisation A is read at that point
- THEN A's `status` MUST NOT yet equal `'samengevoegd'`

### Requirement: NC group membership MUST be migrated from source to target
Execute MUST add every Nextcloud user who is a member of the source organisation's NC group (per `sc-handlers` `OrganizationHandler`/`GroupHandler`) to the target organisation's NC group, without removing them from the source group during execute (group cleanup, if any, happens as part of the tombstone step, not as a data-loss risk mid-merge).

#### Scenario: Source group members gain target group membership
- GIVEN organisation A's NC group has members `[alice, bob]` and organisation B's NC group has member `[carol]`
- WHEN `execute('A-uuid', 'B-uuid')` completes
- THEN organisation B's NC group MUST contain `[alice, bob, carol]`
- AND no error MUST occur if `alice` or `bob` was already a member of B's group

### Requirement: Both merge endpoints MUST be admin-only with an explicit per-object authorization guard
`POST /api/organisaties/{uuid}/merge/dry-run` and `POST /api/organisaties/{uuid}/merge` MUST require the calling user to be a member of the Nextcloud `admin` group, verified by an explicit guard in the controller/service method body — the `#[NoAdminRequired]` route annotation alone MUST NOT be treated as sufficient authorization (no-admin-idor gate).

#### Scenario: Non-admin user is rejected
- GIVEN a user who is not a member of the `admin` group
- WHEN that user calls `POST /api/organisaties/{uuid}/merge` with any `targetUuid`
- THEN the response MUST have status 403
- AND no relation object MUST be modified
- AND no audit log entry for a merge MUST be written

#### Scenario: Admin user is authorized
- GIVEN a user who is a member of the `admin` group
- WHEN that user calls `POST /api/organisaties/{uuid}/merge/dry-run` with a valid `targetUuid`
- THEN the response MUST have status 200 with the per-type counts

### Requirement: Merge requests MUST be validated and rejected with blockers before any write
Both endpoints MUST reject a merge (dry-run returns non-empty `blockers`; execute returns HTTP 400/409 and performs no write) when: `sourceUuid` equals `targetUuid`; either UUID does not resolve to an existing organisation; the source organisation already has `status = 'samengevoegd'`; or the target organisation already has `status = 'samengevoegd'`.

#### Scenario: Self-merge is rejected
- WHEN `execute('A-uuid', 'A-uuid')` is called
- THEN the response MUST be an error (400/409) and no object MUST be modified

#### Scenario: Merging into an already-tombstoned target is rejected
- GIVEN organisation B has `status = 'samengevoegd'` (B was itself merged into another organisation)
- WHEN `execute('A-uuid', 'B-uuid')` is called
- THEN the response MUST be an error and no object MUST be modified

#### Scenario: Re-merging an already-tombstoned source is rejected as a validation error, not a silent success
- GIVEN organisation A has `status = 'samengevoegd'` with `mergedInto = 'B-uuid'`
- WHEN `execute('A-uuid', 'C-uuid')` is called with a different target C
- THEN the response MUST be an error and A's `mergedInto` MUST remain `'B-uuid'`

### Requirement: Execute MUST report progress via the existing SSE progress-tracking mechanism
Execute MUST call `ProgressTracker::startOperation('org_merge', ...)` at the start of the merge, `setPhase`/`incrementProgress`/`updateStatistics` as each relation type is processed, and `completeOperation` on success, so the operation is observable through the existing progress-tracking SSE surface without a new progress mechanism (no app-local notification/progress dispatch, per ADR-031 precedent).

#### Scenario: A long-running merge is observable via the existing progress endpoint
- GIVEN `execute('A-uuid', 'B-uuid')` is in flight and has completed 2 of 5 relation types
- WHEN `getProgress(operationId)` is called (per the progress-tracking spec)
- THEN the returned snapshot's `processed_items`/`statistics` MUST reflect the 2 completed types
- AND `phase` MUST NOT be `'completed'` until all types finish

### Requirement: Every dry-run and execute call MUST produce an audit log entry
`dryRun` and `execute` MUST each write an audit log entry recording the acting user, timestamp, `sourceUuid`, `targetUuid`, and (for execute) per-relation-type counts; execute MUST additionally write one entry per relation type as it completes plus a final summary entry, so a partially-completed merge is traceable from the audit log alone.

#### Scenario: Execute writes a summary audit entry
- GIVEN `execute('A-uuid', 'B-uuid')` completes successfully as user `admin1`
- WHEN the audit log is queried for this operation
- THEN it MUST contain a summary entry with actor `admin1`, `sourceUuid = 'A-uuid'`, `targetUuid = 'B-uuid'`, and the final per-type counts

#### Scenario: Dry-run writes an audit entry without a completion count
- GIVEN `dryRun('A-uuid', 'B-uuid')` is called as user `admin1`
- WHEN the audit log is queried
- THEN it MUST contain an entry recording the dry-run call with actor `admin1` and the reported counts

## Non-Functional Requirements

- **Performance:** Dry-run over a source organisation with up to a few hundred referencing objects across all five relation types MUST complete within a few seconds; execute over the same volume runs as a tracked long-running operation (progress-tracking) rather than being required to complete synchronously within a request timeout.
- **Accessibility:** The confirm dialog (CnFormDialog) and progress display MUST meet WCAG 2.2 AA — destructive-adjacent action confirmation MUST be keyboard-operable and screen-reader announced (dialog role, focus management), and the progress indicator MUST expose textual percentage/phase, not color alone.
- **Internationalization:** Dutch and English MUST be supported (ADR-005) for the confirm dialog, dry-run preview counts labels, and any merge-related error/blocker messages.

## Acceptance Criteria

- [ ] Dry-run returns per-relation-type counts (`gebruik`, `contract`, `contactpersoon`, `aanbod`, `compliancy`, `groupMembers`) without writing any object
- [ ] Execute re-points all five relation types, preserving every untouched field on each re-pointed object (PUT-semantics test)
- [ ] Dry-run and execute report identical counts for the same unchanged input (parity test)
- [ ] Execute is idempotent: re-running against a partially or fully completed merge does not double-process any relation type
- [ ] Source organisation is tombstoned (`status = 'samengevoegd'`, `mergedInto` set) only after all relation types complete, never deleted, and excluded from default listings
- [ ] NC group membership is migrated from source to target
- [ ] Both endpoints reject non-admin callers (403) with an explicit per-object guard, not just route annotation
- [ ] Self-merge, already-tombstoned-source, and already-tombstoned-target requests are rejected with blockers/errors and no writes
- [ ] Execute reports progress via the existing `ProgressTracker`/SSE mechanism
- [ ] Every dry-run and execute call is captured in the audit log
- [ ] Test coverage ≥75% for new code (ADR-009)
- [ ] i18n: Dutch and English translations for all new user-facing strings (ADR-005)
- [ ] Documentation with Playwright screenshots in `docs/features/organisation-merge.md` (ADR-010)

## Notes
- Out of scope (see proposal.md): undo/rollback of a completed merge, bulk multi-organisation merges, a multi-step UI wizard.
- Open question (see proposal.md): whether a source organisation with children (per the pending `organisation-parent-hierarchy-rbac-fix` change) should be blocked from merging or have its children re-parented; this change blocks it via the validation requirement above until that question is resolved.
- Related ADRs: ADR-001 (OpenRegister storage, no custom tables), ADR-008 (Controller → Service → Mapper layering), ADR-005 (i18n), ADR-009 (test coverage ≥75%), ADR-031 (no app-local notification dispatch — progress uses the existing mechanism).
