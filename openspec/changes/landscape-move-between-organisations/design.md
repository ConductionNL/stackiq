# Design: landscape-move-between-organisations

Read at development `49e65cb4`.

## Context

Ownership in stackiq has two layers. OpenRegister multitenancy stamps `@self.organisation` on every object, and read rules match on it (`{"_organisation": "$organisation"}` throughout `lib/Settings/softwarecatalogus_register.json`). Domain fields name the organisation too: `module.provider`, `catalogService.provider`, `usage.consumer` and `participants`, `connection.provider`, `contactPerson.organization`. `MergeOrganisatieService` already knows both layers per type (`FIELD_RELATION_TYPES` :111, `SELF_ORGANISATION_RELATION_TYPES` :122) and saves the full object so unrelated fields survive (`repointBySelfOrganisation` :442, reading the owner through `readOwningOrganisation`).

## D1. A transfer service on the merge primitives

New `lib/Service/OwnershipTransferService.php`:

- `plan(array $objectRefs, string $targetOrganisation, IUser $caller): array` returns, per object, `move` or `skip` with a reason (not owned by the source, caller cannot edit, target unknown), plus linked objects of the source organisation that stay behind (for a usage: its connections; for an application: its versions).
- `execute(...)` runs the plan's `move` items: sets `@self.organisation` to the target and the type's owning field (from the same map the merge uses), saving the full object.

The per-type map and the owner reader move out of `MergeOrganisatieService` into a small shared class `lib/Service/Organisation/OwnershipMap.php`, used by both services, so the merge and the transfer cannot drift.

Rejected: calling the merge with a filter. The merge tombstones the source organisation at the end; a transfer must never do that.

## D2. Endpoints and authorisation

`POST /api/ownership-transfers/plan` and `POST /api/ownership-transfers/execute` in `appinfo/routes.php`, body `{ objects: [{ schema, id }], targetOrganisation }`, controller `lib/Controller/OwnershipTransferController.php`, `#[NoAdminRequired]` with an explicit guard: the caller is a Nextcloud admin, or is in an organisation admin group (`SettingsService::getOrganizationAdminGroups()`, as `SettingsController::verifyOrgExportPermission()` uses at `lib/Controller/SettingsController.php:1737`) AND is a member of both organisations (the multi-org membership from the archived change `multi-org-membership`). Anything else is a 403 before any read.

## D3. The action

A dialog `MoveToOrganisationDialog` (`src/dialogs/`) with an organisation picker, the dry-run list and Confirm. It opens from:

- a mass action on the list pages that support selection (Applications, Services, Applications in use, Connections, Contracts), through `CnIndexPage` mass actions (`CnMassActionBar` in the library);
- a header action on each of their detail pages.

## Declarative versus imperative

Imperative: a transfer rewrites ownership across types with a guard, which no `x-openregister-*` construct expresses. The per-type map is data in one class.

## Seed data

None.

## Risks

- Extracting the map touches the merge service; its existing tests (`tests/Unit/Service/MergeOrganisatieServiceTest.php` and the merge e2e) must stay green.
- `@self.organisation` is written through a full save. The archived merge change recorded why a partial write drops fields; the transfer uses the same full save.
