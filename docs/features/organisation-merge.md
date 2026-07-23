<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

# Organisation merge

Lets an administrator fold one organisation ("source") into another
("target") — the supported path through **gemeentelijke herindeling**
(municipal mergers) and **leveranciersovername** (supplier takeovers).
Every relation that references the source organisation is re-pointed onto
the target, and the source is soft-retired with a tombstone rather than
deleted. See [VNG Softwarecatalogus issue #141](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/141).

Specification: [`openspec/specs/organisation-merge/spec.md`](../../openspec/specs/organisation-merge/spec.md).

## What gets re-pointed

A merge walks every object type that can reference an organisation:

| Relation type    | Reference field(s)                    |
|-------------------|----------------------------------------|
| `gebruik`         | `afnemer` (scalar), `deelnemers` (array) |
| `contract`         | `@self.organisation` (owning organisation) |
| `contactpersoon`   | `organisatie`                          |
| `aanbod`/`koppeling` | `aanbieder`                          |
| `compliancy`       | `@self.organisation` (owning organisation) |

Nextcloud group membership is also migrated: every user in the source
organisation's group is added to the target organisation's group (existing
target members are left untouched — no error on overlap).

## Preview before you commit: dry-run

Before executing a merge, an admin previews it — a **dry-run** enumerates
every object above and returns a count per relation type, without writing
anything:

```
POST /apps/softwarecatalog/api/organisaties/{sourceUuid}/merge/dry-run
{ "targetUuid": "<target organisation uuid>" }
```

```json
{
  "sourceUuid": "a1b2c3d4-...",
  "targetUuid": "b2c3d4e5-...",
  "counts": { "gebruik": 12, "contract": 4, "contactpersoon": 7, "aanbod": 3, "compliancy": 9, "groupMembers": 5 },
  "blockers": []
}
```

`dryRun` and `execute` share **one** relation-enumeration routine (gated by
a `commit` flag) — dry-run counts and execute's actual re-point counts can
never structurally drift apart. A non-empty `blockers` array means the merge
is not legal (self-merge, an already-merged source/target, or an unresolved
UUID) and execute will refuse it too, with the same validation.

## Executing a merge

```
POST /apps/softwarecatalog/api/organisaties/{sourceUuid}/merge
{ "targetUuid": "<target organisation uuid>", "confirm": true }
```

Execute is:

- **Admin-only** — both endpoints require Nextcloud `admin` group membership,
  checked by an explicit guard in `MergeController`'s method body (not just
  the `#[NoAdminRequired]` route annotation).
- **PUT-semantic-safe** — OpenRegister's `saveObject()` is a full replace;
  every re-point reads the object's complete current payload and mutates
  only the organisation-reference field(s) before saving, so untouched
  fields (contract numbers, costs, document references, ...) survive
  unchanged.
- **Idempotent / resumable** — re-invoking execute against a partially
  completed merge does not re-point already-completed relation types a
  second time; re-invoking a fully completed merge is a safe no-op that
  reports `status: "already_completed"`.
- **Progress-tracked** — reported through the existing SSE
  `ProgressTracker` mechanism (`org_merge` operation type), one phase per
  relation type.
- **Audited** — every dry-run and execute call writes a structured log
  entry plus Nextcloud's `CriticalActionPerformedEvent` (actor, timestamps,
  source/target UUIDs, per-type counts).

## Tombstoning — never a hard delete

Once every relation type has completed, the source organisation is updated
(again PUT-semantic, so no other field is lost) with:

- `status = "samengevoegd"`
- `mergedInto = "<target uuid>"`

The source is **never deleted**. It disappears from the default
Organisaties index listing (`config.filter: {"status": {"$ne": "samengevoegd"}}`
in `src/manifest.json`), but stays resolvable by direct UUID lookup — its
detail page renders a read-only notice with a link to the organisation it
was merged into.

`OrganisatieService::mapStatus('samengevoegd')` also returns `false`, so the
linked OpenRegister core `Organisation` entity's `active` flag is kept in
sync (organisatie-service spec delta).

## UI

An **"Merge organisation"** panel is rendered on the organisation detail
page (`OrganisatieDetail`, via the `OrganisationMergePanel` bodyWidget —
visible to admins only):

1. Pick a target organisation from the dropdown.
2. **Preview merge** runs the dry-run and, if there are no blockers, opens a
   confirm dialog (`MergeOrganisationConfirmDialog`) showing the
   per-relation-type counts that will be re-pointed.
3. Confirming runs execute; on success the panel switches to the read-only
   "merged" state with a link to the target organisation.

An already-tombstoned organisation shows the read-only notice immediately
and offers no merge controls.

## Out of scope

- Undo/rollback of a completed merge — the audit trail is the record; a
  botched merge is corrected manually or with a follow-up merge.
- Bulk multi-organisation merges (more than one source into one target in a
  single operation).
- Re-parenting children of a merged-away organisation with children — see
  the Open Question in `openspec/specs/organisation-merge/spec.md`; today
  such a merge is blocked pending the `organisation-parent-hierarchy-rbac-fix`
  change.

## Screenshots

Not captured in this change — the implementing session had no live
Nextcloud instance to drive Playwright against without touching the shared
dev environment (out of bounds for this change). Follow-up: capture the
dry-run preview, confirm dialog, and post-merge tombstone notice per
ADR-010 once verified against a running instance.
