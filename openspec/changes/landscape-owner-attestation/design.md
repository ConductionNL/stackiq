# Design: landscape-owner-attestation

Read at development `49e65cb4`, OpenRegister development `4fee776`.

## Context

The entries to confirm are usages (an organisation's applications in use, owners from `landscape-usage-registration`) and modules (a supplier's products, contact from `module.contactPerson`). `landscape-completeness-score` gives both `lastConfirmedAt` and a freshness rule. Owners act as Nextcloud users; contact persons get accounts through `ContactPersonHandler::createUserAccount()` (called from `lib/Controller/ContactpersonenController.php:393` onwards).

## D1. Two schemas in a fragment

`lib/Settings/register.d/owner-attestation.json`, both added to the `stackiq` register list:

`attestationRound`: `name`, `deadline` (date), `scopeSchema` (enum `usage`, `module`), `scopeOrganisation` (`$ref organization`), `startedBy` (string, uid), `status` (enum `open`, `closed`) with an `x-openregister-lifecycle` (open to closed), `requestCount`, `answeredCount` (numbers written by the service).

`attestationRequest`: `round` (`$ref attestationRound`), `entrySchema`, `entryId`, `entryName` (string, for lists), `assigneeUserId` (string, Nextcloud uid), `assigneeContact` (`$ref contactPerson`), `status` (enum `pending`, `confirmed`, `corrected`, `overdue`), `respondedAt`.

Authorization: an organisation reads its own rounds and requests (`_organisation` match); a request is also readable and updatable by its assignee (`{"match": {"assigneeUserId": "$userId"}}` in the read and update rules).

## D2. Creating a round

`lib/Service/AttestationService.php`, `start(string $scopeSchema, string $organisationId, string $deadline, IUser $caller)`:

1. Load the entries: usages whose `consumer` is the organisation, or modules whose `provider` is the organisation.
2. For each entry pick owners: `businessOwner` and `technicalOwner` for a usage, `contactPerson` for a module. Resolve each contact person to a Nextcloud uid through `ContactPersonHandler`; entries whose owner has no account are returned as `unassigned`.
3. Create the round and one request per entry and owner.

Route `POST /api/attestation-rounds` (`#[NoAdminRequired]`), guarded: the caller is a Nextcloud admin, or passes the maintainer rule of `OrganisationMembersController::authorizeMaintainer()` (`lib/Controller/OrganisationMembersController.php:207`: in the `maintainer` group and a member of the scope organisation). `SettingsService::getOrganizationAdminGroups()` is not used: it returns an empty list (`lib/Service/SettingsService.php:2105-2110`). The response lists the unassigned entries.

## D3. Answering

- Confirm: `POST /api/attestation-requests/{id}/confirm` sets the entry's `lastConfirmedAt` to now (a normal update under the caller's rights, so OpenRegister rescores it) and the request's status to `confirmed`.
- Correct: the owner edits the entry through its normal page; a listener on `ObjectUpdatedEvent` for `usage` and `module` marks an open pending request for that entry and that user `corrected` and sets `lastConfirmedAt`.
- Overdue: a daily `TimedJob` (`lib/BackgroundJob/AttestationOverdueJob.php`, registered in `appinfo/info.xml`) sets pending requests past the round's deadline to `overdue` and updates the round's counts.

## D4. Notifications, declared

`attestationRequest.configuration["x-openregister-notifications"]`:

- `request-created`: trigger `created`, channels `nc-notification` and `email`, recipient `{ "kind": "field", "field": "assigneeUserId" }`, subject "Please confirm your entry {{entryName}}".
- `deadline-near`: trigger `scheduled` daily with filter on the round deadline within three days and status `pending`, same recipient.

## D5. Pages

`src/manifest.d/owner-attestation.json`:

- `MyAttestations` (`/my-confirmations`), an index over `attestationRequest` filtered on `assigneeUserId` of the current user and status `pending`, with row actions Confirm and Open entry.
- `AttestationRounds` (`/confirmation-rounds`) and `AttestationRoundDetail`, with a Start round action (dialog `src/dialogs/StartAttestationRoundDialog.vue`), the counts as stat widgets and the requests as an object list grouped by status.
- Menu: both as children of an existing entry (Organisations), not new top-level entries (ADR-097).

## Declarative versus imperative

Schemas, lifecycle, notifications and pages are declarative (ADR-031). Creating requests from a scope, resolving owners to users and the overdue sweep are imperative: they join several schemas and Nextcloud users.

## Seed data

None beyond the schemas; the demo shows a round only after an administrator starts one.

## Risks

- The update listener must only mark requests of the user who saved; a colleague's edit must not answer someone else's request.
- The overdue job must stay idempotent; it only moves `pending` to `overdue`.
