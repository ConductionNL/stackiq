# Context Brief: organisation-merge

## What
Admin-triggered merge of organisation A into organisation B (gemeentelijke herindeling or leveranciersovername): re-point all relations — gebruik, contracts, contactpersoon role records, koppelingen, compliance records, aanbod ownership — to the target org; soft-retire the source org with a tombstone/redirect reference; dry-run preview with per-type counts before execution; full audit trail.

## Why (evidence)
- VNG Softwarecatalogus issue #141 (merge organisations after herindeling/leveranciersovername with all relations) — a municipal-specific lifecycle no competitor supports.
- Dutch municipal reality: herindelingen happen nearly every year (gemeente count shrinks annually).
- Specter canonical feature: `organisation-merge` (should, demand 5).

## Current state (read these specs first)
- `openspec/specs/organisatie-service`, `openspec/specs/organization-sync`, `openspec/specs/softwarecatalog-contacts-to-nc` (role records keyed by contactsUid), `openspec/specs/sc-handlers` (org groups + manager hierarchy), `openspec/specs/contract-administration`.
- `openspec/changes/organisation-parent-hierarchy-rbac-fix` (pending) — org hierarchy semantics; stay consistent.
- Progress: `openspec/specs/progress-tracking` — long-running merge should report progress via the existing SSE progress mechanism.

## Scope
IN: merge service (Controller → Service), dry-run endpoint returning per-relation-type counts, execute endpoint (admin-only, idempotent, resumable or transactional-per-type), tombstone on source org, NC group membership migration, audit log entries, tests incl. dry-run/execute parity, docs.
OUT: undo/rollback of a completed merge (audit trail only), bulk multi-org merges, UI wizard beyond a simple confirm dialog on the organisation detail page.

## Design constraints
- **OR saveObject is PUT-semantic** — when re-pointing objects you MUST carry ALL existing fields forward; omitting a property nulls it. Test that an untouched field survives the merge.
- OR DELETE is soft-delete; the tombstoned source org must be excluded from listings via its own status field, not via deletion.
- Admin-only: `#[NoAdminRequired]` methods need explicit per-object authorization guards (no-admin-idor gate).
- ADR-001 OR storage; ADR-008 layering; ADR-005 i18n; ADR-009 tests ≥75%.
- OpenSpec delta headers MUST be `### Requirement: <name>`.
