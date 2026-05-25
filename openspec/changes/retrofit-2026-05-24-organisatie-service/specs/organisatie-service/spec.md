---
status: draft
retrofit: true
---

# Organisatie Service Specification

## Purpose

Captures observed behavior of `OCA\SoftwareCatalog\Service\OrganisatieService`, the integration layer that synchronises SoftwareCatalog organisation records into OpenRegister's `OrganisationService`. Covers creation, status transition, user assignment, and helper queries. Distinct from `OrganizationSyncService` (which orchestrates the full SC↔OR sync pipeline) and `OrganizationHandler` (sub-handler under `SoftwareCatalogueService`). Reverse-spec'd from existing code 2026-05-24.

## ADDED Requirements

### REQ-001: The system SHALL create an OpenRegister organisation entity from SoftwareCatalog object data

`createOrganisationInOpenRegister(objectData)` MUST require a non-empty `objectData['id']` (logs error + returns `null` otherwise). It MUST map the input to OpenRegister format via `mapOrganizationDataForOpenRegister`, lookup `OrganisationService` from the OpenRegister app (returning `null` if the app is not enabled), and call `OrganisationService::createOrganisation` with `addCurrentUser: false` and the original SC UUID. On success it MUST return the created `Organisation` entity; on any exception it MUST log + return `null` (never propagate).

#### Scenario: Missing id is rejected
- GIVEN `objectData['id']` is null or empty string
- WHEN `createOrganisationInOpenRegister(objectData)` is called
- THEN it MUST log an error and return `null`
- AND it MUST NOT invoke OpenRegister

#### Scenario: OpenRegister disabled returns null
- GIVEN the OpenRegister app is not enabled for the user
- WHEN `createOrganisationInOpenRegister(['id' => 'uuid-1', 'naam' => 'Org'])` is called
- THEN it MUST return `null`
- AND it MUST log an error referencing OrganisationService unavailability

#### Scenario: Successful creation returns entity
- GIVEN OpenRegister is enabled and `OrganisationService::createOrganisation` succeeds
- WHEN the public method is called with valid data
- THEN the returned value MUST be the `OCA\OpenRegister\Db\Organisation` entity
- AND the call to `createOrganisation` MUST pass `addCurrentUser: false` and the original SC UUID

### REQ-002: The system SHALL update the active flag of an OpenRegister organisation from a SoftwareCatalog status

`updateOrganizationStatus(organizationUuid, objectData)` MUST find the OpenRegister `Organisation` by SC UUID via `OrganisationMapper::findByUuid`, map `objectData['beoordeling']` (default `'actief'`) through `mapStatus` to a boolean, call `setActive` + `save`. On success it MUST return `true`; on any exception it MUST log + return `false` (never propagate).

`mapStatus(status)` MUST normalise its input (lowercase + trim) and return: `true` for `actief` / `active`; `false` for `inactief` / `inactive` / `deactief`; `true` for any other value (default-active for unknown statuses).

#### Scenario: Active status maps to true
- WHEN `mapStatus('Actief')` is called
- THEN the return value MUST be `true`

#### Scenario: Inactive variants map to false
- WHEN `mapStatus(' inactief ')` is called
- THEN the return value MUST be `false`
- AND `mapStatus('deactief')` MUST also return `false`

#### Scenario: Unknown status defaults to active
- WHEN `mapStatus('pending')` is called
- THEN the return value MUST be `true`

#### Scenario: Update success
- GIVEN an organisation exists in OR with the supplied UUID
- WHEN `updateOrganizationStatus('uuid-1', ['beoordeling' => 'inactief'])` is called
- THEN the OR organisation's `active` flag MUST be `false` after the call
- AND the method MUST return `true`

### REQ-003: The system SHALL map SoftwareCatalog organisation data to the OpenRegister payload shape

`mapOrganizationDataForOpenRegister(objectData)` MUST return an associative array with keys `naam`, `type`, `website`, `active`, `contactpersonen`, `deelnemers`. The `naam` MUST be resolved from `objectData['naam']` then `objectData['name']`, falling back to `Organisation <first-8-chars-of-id>` when both are missing or equal to `Unknown`. The `active` field MUST be the result of `mapStatus(status ?? beoordeling ?? 'actief')`.

#### Scenario: Naam fallback uses id prefix
- GIVEN `objectData = { id: 'abc12345-xxxx-...' }` with no `naam` or `name`
- WHEN `mapOrganizationDataForOpenRegister` is called
- THEN the returned `naam` MUST equal `"Organisation abc12345"`

#### Scenario: Naam prefers naam over name
- GIVEN `objectData = { naam: 'Org NL', name: 'Org EN', id: 'x' }`
- WHEN the method is called
- THEN the returned `naam` MUST equal `'Org NL'`

### REQ-004: The system SHALL add users to an OpenRegister organisation and notify each one

`addUsersToOrganization(organizationUuid, usernames)` MUST find the OR `Organisation` by SC UUID, merge the new usernames into the existing `users` array (dedup via `array_unique`), persist via `OrganisationMapper::save`, and for each supplied username call `SymfonyEmailService::sendUserUpdateEmail` with the user's `{username, email, name}` + the organisation's `jsonSerialize()`. On success it MUST return `true`; on any exception it MUST log + return `false` (never propagate).

#### Scenario: User assignment merges and dedups
- GIVEN the OR organisation already has users `['alice']`
- WHEN `addUsersToOrganization('uuid', ['bob', 'alice'])` is called
- THEN the OR organisation's `users` MUST equal `['alice', 'bob']` (order preserved, dedup applied)
- AND `setUsers` + `save` MUST be called

#### Scenario: Notification is sent per new user
- GIVEN the merge produced 2 new usernames
- WHEN the method runs
- THEN `sendUserUpdateEmail` MUST be invoked once per supplied username with the user payload

### REQ-005: The system SHALL list the usernames of the Nextcloud admin group

`getAdminGroupUsernames()` MUST resolve the `admin` group via `IGroupManager::get('admin')`, return the UID of every user in the group as a flat array of strings. If the admin group cannot be found OR if any exception is thrown, it MUST return `[]` (never propagate).

#### Scenario: Admin group resolved
- GIVEN the `admin` group exists with members `[admin1, admin2]`
- WHEN `getAdminGroupUsernames()` is called
- THEN the returned array MUST equal `['admin1', 'admin2']`

#### Scenario: Admin group missing returns empty
- GIVEN `IGroupManager::get('admin')` returns `null`
- WHEN `getAdminGroupUsernames()` is called
- THEN the returned array MUST equal `[]`

## Notes

- **HOTFIX — parent-organisation assignment is disabled.** `createOrganisationEntityInternal` originally set every new organisation as a child of the active organisation, but this caused RBAC permission problems (users couldn't access their newly-created orgs due to hierarchical RBAC filtering). The code is commented out (lines 267-271). REQ-001 describes the *current* contract (no parent assignment); restoring the parent relationship requires the RBAC investigation noted in the file's TODO. Follow-up: track as a separate issue tied to OR RBAC work.
- **`getActiveOrganisationUuid()` is unused.** Its only caller is the commented-out HOTFIX block. The class is annotated with `@SuppressWarnings(PHPMD.UnusedPrivateMethod)` to silence the lint warning. Not specified as a REQ; remove after HOTFIX resolution or keep as documentation of the disabled flow.
- **`createOrganisationEntityInternal` is private plumbing** for REQ-001 — folded into the REQ-001 description, not exposed as its own scenario.
- **Redundant logging in `addUsersToOrganization` catch block.** The exception is logged twice (lines 388-403) — the second call is dead code. Not in spec; track for cleanup.
- **Acceptance Criteria:** Covered indirectly by the organisation-sync integration tests and Newman runs against `/api/organisations`; no direct unit coverage at retrofit time.
