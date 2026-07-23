# Design: organisation-merge

## Architecture Overview
The merge feature is a new `MergeOrganisatieService`, sitting alongside the existing `OrganisatieService` (organisatie-service spec), invoked by a new `MergeController` per ADR-008's Controller → Service → Mapper layering. It performs no new persistence mechanism: every write is an OpenRegister `saveObject` (via `ObjectService`/mappers already used by `organisatie-service`, `sc-handlers`, and `contract-administration`), and every relation type it walks is one already modeled by an existing schema (`gebruik`, `contract`, `contactpersoon`, `aanbod`/`koppeling`, `compliancy`, `organisatie`). Long-running execute runs report through the existing `ProgressTracker` (progress-tracking spec) and NC group membership is migrated through the existing `GroupHandler`/`OrganizationHandler` (sc-handlers spec).

```
OrganisatieDetail.vue (confirm dialog)
        │ POST /merge/dry-run, POST /merge
        ▼
MergeController (admin-only, per-object auth guard)
        │
        ▼
MergeOrganisatieService
   ├── walkRelations(sourceUuid, targetUuid, commit: bool) ─┬─ per relation type:
   │                                                          │   gebruik (afnemer/deelnemers)
   │                                                          │   contract (organisation refs)
   │                                                          │   contactpersoon (organisatie)
   │                                                          │   aanbod/koppeling (aanbieder)
   │                                                          │   compliancy (@self.organisation)
   ├── migrateGroupMembership(sourceUuid, targetUuid) ── sc-handlers GroupHandler/OrganizationHandler
   ├── ProgressTracker (progress-tracking) ── phase/percentage per relation type
   ├── AuditLogService (existing audit mechanism) ── one entry per type + one summary entry
   └── tombstoneSource(sourceUuid, targetUuid) ── PUT-semantic full-object re-save
```

## API Design

### `POST /api/organisaties/{sourceUuid}/merge/dry-run`
**Request:**
```json
{
  "targetUuid": "b2c3d4e5-...-target-org-uuid"
}
```
**Response:**
```json
{
  "sourceUuid": "a1b2c3d4-...",
  "targetUuid": "b2c3d4e5-...",
  "counts": {
    "gebruik": 12,
    "contract": 4,
    "contactpersoon": 7,
    "aanbod": 3,
    "compliancy": 9,
    "groupMembers": 5
  },
  "blockers": []
}
```
`blockers` is a non-empty array (each `{type, message}`) when the merge cannot proceed — e.g. `sourceUuid === targetUuid`, target already tombstoned, or source has children per the pending parent-hierarchy change (blocked, not auto-reparented, per proposal Open Questions). A non-empty `blockers` array means execute MUST be refused with the same validation, so dry-run and execute can never structurally disagree on whether a merge is legal.

### `POST /api/organisaties/{sourceUuid}/merge`
**Request:**
```json
{
  "targetUuid": "b2c3d4e5-...-target-org-uuid",
  "confirm": true
}
```
**Response:**
```json
{
  "operationId": "org_merge_6f9a...",
  "sourceUuid": "a1b2c3d4-...",
  "targetUuid": "b2c3d4e5-...",
  "status": "completed",
  "counts": {
    "gebruik": 12,
    "contract": 4,
    "contactpersoon": 7,
    "aanbod": 3,
    "compliancy": 9,
    "groupMembers": 5
  }
}
```
`operationId` is the `ProgressTracker` operation id (progress-tracking `startOperation('org_merge', ...)`); the SSE progress endpoint already exposed by progress-tracking is reused to poll/stream phase updates for this id. On a resumed/re-run call against a partially-completed merge, `status` is `"completed"` once all types finish, and per-type counts reflect only what that call itself re-pointed (already-completed types are skipped and reported from the stored audit record, not re-counted).

## Database Changes
No custom database tables (ADR-001). Two additive, non-breaking schema changes on the existing `organisatie` schema (softwarecatalogus register, per `lib/Settings/softwarecatalogus_register.json`):
- `status` gains an additional allowed value `samengevoegd` ("merged") alongside existing values, used as the tombstone marker.
- New optional field `mergedInto` (string, organisation UUID) — set on the source organisation once merge completes; absent/`null` on every organisation that has never been a merge source.

No fields are removed from any schema; `gebruik`, `contract`, `contactpersoon`, `aanbod`/`koppeling`, and `compliancy` are re-pointed using their existing organisation-reference fields (`afnemer`/`deelnemers`, contract's organisation-referencing fields, `organisatie`, `aanbieder`, `@self.organisation`) — no new fields on those schemas.

## Nextcloud Integration
- Controllers: `MergeController` (new) — `#[NoAdminRequired]` on both endpoints with an explicit admin-group authorization guard in the method body (no-admin-idor gate; route-level annotation alone is insufficient).
- Services: `MergeOrganisatieService` (new, softwarecatalog `lib/Service/`), reusing `OrganisatieService`/`OrganisationMapper` (organisatie-service), `ProgressTracker` (progress-tracking), `GroupHandler`/`OrganizationHandler` (sc-handlers), and the existing audit-log service used elsewhere in the app.
- Mappers/Entities: no new OR schemas; existing mappers for `gebruik`, `contract`, `contactpersoon`, `aanbod`/`koppeling`, `compliancy`, `organisatie` via `ObjectService`.
- Events/Hooks: none new — the merge is a synchronous (or SSE-tracked long-running) service call, not an OpenRegister save/update/delete event handler. It does not hook into `sc-handlers`' lifecycle handlers; it calls their public group/membership methods directly.

## Security Considerations
- Both `merge/dry-run` and `merge` are admin-only: `#[NoAdminRequired]` route annotation plus an explicit `IGroupManager::isInGroup($uid, 'admin')`-style guard in the controller/service, satisfying the no-admin-idor and semantic-auth gates (annotation alone does not imply the check ran).
- `sourceUuid`/`targetUuid` are validated as existing, non-tombstoned organisations before any read of relation objects; a non-existent or already-tombstoned UUID on either side is a `blockers` entry (dry-run) or a 400/409 (execute), never a silent no-op.
- Execute is guarded against `sourceUuid === targetUuid` (would otherwise silently no-op-tombstone a live organisation).
- All writes go through OpenRegister's own RBAC/ACL layer (ObjectService/saveObject) — the merge service does not bypass OR-level authorization on the objects it re-points; it only supplies the elevated context an admin action requires (consistent with the `SystemOperationContext` pattern used elsewhere in the fleet for admin-initiated cross-organisation writes).
- Audit log entries record actor, timestamp, source/target UUIDs, and per-type counts for every dry-run and execute call, satisfying traceability for a destructive-adjacent operation.

## NL Design System
The confirm dialog on the organisation detail page uses `CnFormDialog` (ADR-012 — no custom modal), NL Design System tokens via NC CSS variables for warning/destructive styling (ADR-003 — no hardcoded colors), and reuses the existing SSE progress UI pattern (if one exists in fe-settings-ui/fe-organizations for sync operations) to show the per-type progress bar during execute rather than introducing a new progress-UI component.

## File Structure
```
lib/
  Controller/
    MergeController.php          (new)
  Service/
    MergeOrganisatieService.php  (new)
src/
  modals/
    MergeOrganisationDialog.vue  (new — confirm dialog, CnFormDialog-based)
  store/
    organisationsStore.js        (modified — dry-run/execute actions)
tests/
  phpunit/
    MergeOrganisatieServiceTest.php  (new)
    MergeControllerTest.php          (new)
  vitest/
    MergeOrganisationDialog.spec.js  (new)
docs/
  features/
    organisation-merge.md        (new, with Playwright screenshots per ADR-010)
```

## Seed Data
No new schema is introduced (only additive fields on the existing `organisatie` schema), so no new seed objects are required. Existing `organisatie` seed data is sufficient to exercise dry-run/execute in dev; one existing seed organisation SHOULD be exercised as a merge source in manual/dev testing, but no seed data ships pre-tombstoned (a tombstoned seed organisation would need to be excluded from every other feature's seed-data assumptions, which is unnecessary complexity for a demo dataset).

## Trade-offs
- **Shared dry-run/execute walk vs. separate implementations**: chosen a single `walkRelations(..., commit: bool)` routine over two separate code paths specifically to make dry-run/execute parity structural rather than a testing convention (Risk 3 in proposal.md). Trade-off: the routine is slightly more complex (branches on `commit`) than two simple functions would be.
- **Transactional-per-relation-type vs. one big transaction**: chosen per-type transactions (with resumability) over a single all-or-nothing transaction because OpenRegister's object store does not offer cross-object-type multi-row transactions, and a merge can touch hundreds of objects across 5+ types — an all-or-nothing approach would make any single failure (e.g. one malformed `contract` object) block re-pointing everything else, including types that succeeded. Trade-off: a merge can be observed in a partially-completed state (mitigated by per-type audit + resumability, and the source is only tombstoned once every type is confirmed complete).
- **Tombstone via status field vs. hard delete**: chosen per OR's existing soft-delete convention and the proposal's explicit design constraint — deletion loses the audit-visible "this org still exists, just merged" fact the tombstone `mergedInto` reference preserves.
- **Blocking merges on organisations with children vs. auto-reparenting**: chosen to block (Open Question in proposal.md) rather than guess a reparenting policy while `organisation-parent-hierarchy-rbac-fix` is still pending — reparenting semantics should be decided once parent/child creation itself is confirmed working end-to-end.
