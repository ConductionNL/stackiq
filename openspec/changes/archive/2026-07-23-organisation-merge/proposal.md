# Proposal: organisation-merge

## Summary
Adds an admin-triggered organisation-merge capability: re-point every relation that references a source organisation (gebruik, contracts, contactpersoon role records, aanbod/koppelingen ownership, compliance records, NC group membership) onto a target organisation, soft-retire the source with a tombstone rather than deleting it, and expose a dry-run preview (per-relation-type counts) before an idempotent, audited execute step. This gives municipalities a supported path through gemeentelijke herindeling (municipal mergers) and leveranciersovername (supplier takeovers) without hand-editing dozens of OpenRegister objects.

## Motivation
VNG Softwarecatalogus issue #141 asks for organisation merge with all relations preserved — a municipal-specific lifecycle event no competitor product supports. Dutch gemeente count shrinks nearly every year through herindeling, and supplier consolidations happen independently; today an admin has no supported way to fold organisation A into organisation B — every gebruik, contract, contactpersoon role record, aanbod/koppeling ownership record, compliance record, and NC group membership referencing A becomes orphaned or must be hand-edited object by object, which is error-prone and leaves no audit trail. Specter has flagged `organisation-merge` as a canonical "should" feature (demand 5).

## Affected Projects
- [ ] Project: `softwarecatalog` — new merge service (Controller → Service), dry-run + execute REST endpoints, tombstone status on the source organisation, NC group-membership migration, audit log entries, a confirm dialog on the organisation detail page, progress reporting via the existing SSE mechanism

## Scope

### In Scope
- A `MergeOrganisatieService` (Controller → Service layering per ADR-008) that, given a source and target organisation UUID, re-points: `gebruik` (afnemer/deelnemers), `contract` (all organisation-referencing fields), `contactpersoon` role records (`organisatie`), `aanbod`/`koppeling` ownership (`aanbieder`), `compliancy` records owned by the source (`@self.organisation`), and any other object whose `@self.organisation` equals the source UUID.
- A dry-run endpoint that returns per-relation-type counts of what *would* change, without writing anything.
- An execute endpoint, admin-only, that performs the re-pointing per relation type, is idempotent (safe to re-run/resume after partial failure — transactional-per-type), and reports progress via the existing SSE progress-tracking mechanism for long-running merges.
- Soft-retiring the source organisation with a tombstone: a status field (not deletion) that excludes it from normal listings and carries a reference to the target organisation it was merged into.
- NC group-membership migration: users in the source organisation's NC group are added to the target organisation's NC group (per sc-handlers `OrganizationHandler` conventions).
- Audit log entries for the merge operation (who, when, source, target, per-type counts).
- A confirm dialog on the organisation detail page to trigger a merge (no multi-step wizard).
- Preserving every carried-forward field on re-pointed objects (OR `saveObject` is PUT-semantic — omitted fields are nulled).
- Tests (dry-run/execute parity, idempotency, PUT-semantics field preservation, tombstone exclusion, admin-only authorization) and documentation.

### Out of Scope
- Undo/rollback of a completed merge — the audit trail is the only record; a botched merge is corrected manually or via a follow-up merge, not an automated undo.
- Bulk multi-organisation merges (merging more than one source into one target in a single operation).
- A multi-step UI wizard beyond a simple confirm dialog on the organisation detail page.

## Approach
Introduce a `MergeOrganisatieService` alongside the existing `OrganisatieService`, invoked by a new `MergeController` (or a merge action on the existing organisation controller) with two endpoints: `POST /api/organisaties/{uuid}/merge/dry-run` and `POST /api/organisaties/{uuid}/merge`, both admin-only and both taking a `targetUuid`. The dry-run and execute paths share one internal relation-walking routine parameterised by a `commit: bool` flag, guaranteeing dry-run/execute parity by construction. Execute processes relation types one at a time inside per-type transactions (via OpenRegister's object store), recording progress through `ProgressTracker` (`progress-tracking` spec) and writing an audit log entry per relation type plus a final summary entry. On completion the source organisation is updated (PUT-semantic: full payload re-saved) with a tombstone status and a `mergedInto` reference to the target; it is excluded from listings via that status field. Details land in design.md.

## New Dependencies
None.

## Impact
- Backend: new `MergeOrganisatieService`, new controller endpoints, reuse of `ProgressTracker` (progress-tracking), `OrganisatieService` / `OrganisationMapper` (organisatie-service), `sc-handlers` `OrganizationHandler`/`GroupHandler` for NC group migration, and the OpenRegister object store for every re-pointed schema (`gebruik`, `contract`, `contactpersoon`, `aanbod`/`koppeling`, `compliancy`, `organisatie`).
- Frontend: a confirm dialog on the organisation detail page (fe-organizations) and a dry-run preview surface (counts per relation type) before the admin confirms execute.
- Audit: new audit log entries for merge dry-run and execute actions.
- Data: source organisation objects gain a tombstone status + `mergedInto` reference; no schema field is removed from any existing schema.

## Cross-Project Dependencies
None outside softwarecatalog. Builds entirely on existing softwarecatalog specs (`organisatie-service`, `organization-sync`, `softwarecatalog-contacts-to-nc`, `sc-handlers`, `contract-administration`, `progress-tracking`) and stays consistent with the organisation-hierarchy semantics defined in the pending `organisation-parent-hierarchy-rbac-fix` change (a merged source organisation that has children keeps its parent/child links pointing at whatever UUID is still valid — the merge does not currently re-parent children; see Open Questions).

## Risks

### Risk 1: Partial-failure mid-merge leaves the catalog in a mixed state
**Severity:** High — **Mitigation:** execute is transactional-per-relation-type and idempotent: each type is fully re-pointed and audited before the next begins, and re-running execute against a partially-merged pair only re-processes relation types not yet marked complete (a per-merge-operation progress/audit record tracks which types finished). The source organisation is only tombstoned after all relation types report complete.

### Risk 2: PUT-semantic saves silently null out fields on re-pointed objects
**Severity:** High — **Mitigation:** every re-point reads the full existing object, mutates only the organisation-reference field(s), and re-saves the complete payload; a dedicated test asserts an untouched field on a re-pointed object survives the merge unchanged.

### Risk 3: Dry-run and execute drift apart over time (dry-run undercounts or overcounts what execute actually changes)
**Severity:** Medium — **Mitigation:** dry-run and execute share one internal relation-walking implementation gated by a `commit` flag, so they cannot structurally diverge; a parity test asserts dry-run counts equal the number of objects execute actually re-points for the same input.

### Risk 4: A non-admin triggers or observes a merge on organisations they don't manage
**Severity:** Medium — **Mitigation:** both endpoints are `#[NoAdminRequired]` with an explicit admin-group authorization guard in the method body (per the no-admin-idor gate), not just route-level annotation.

### Risk 5: Child organisations of a tombstoned source become unreachable via hierarchy navigation
**Severity:** Low — **Mitigation:** out of scope for this change (see Open Questions); the tombstone carries a `mergedInto` forward-reference so any hierarchy-aware UI can redirect, and a follow-up change can re-parent children if `organisation-parent-hierarchy-rbac-fix` ships first.

## Rollback Strategy
This change is additive (new service, new endpoints, one new status value + one new field on the `organisatie` schema for the tombstone). Reverting the code removes the merge endpoints and UI entry point without touching existing data. Because there is no automated undo, any merge already executed before a rollback remains applied — the mitigation is to disable the feature (route removal) rather than data rollback; already-tombstoned source organisations and already-repointed relation objects stay as-is, which is the documented (out-of-scope-undo) behaviour, not a bug introduced by the rollback.

## Open Questions
- Should a merge on a source organisation that has children (per `organisation-parent-hierarchy-rbac-fix`) be blocked, or should children be re-parented to the target as part of the merge? This change assumes blocked-with-a-clear-error for now — deferred to the parent-hierarchy change landing first.
