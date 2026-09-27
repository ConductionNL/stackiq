# Design: organisations-role-mapping-and-access-review

Read at development 49e65cb4, with OpenRegister development 4fee776.

## Where it fits

| Part | File and line | What changes |
|---|---|---|
| Service | new `lib/Service/RoleGroupMap.php` | the six catalogue roles, their role group (the lower-case name the register's `authorization` uses), the admin-chosen source groups per role, and the default role per organisation type |
| Service | new `lib/Service/RoleGrantSync.php` | computes a person's roles and sets their role group memberships to exactly match |
| Service | `lib/Service/Stackiq/GroupHandler.php:286` `updateRoleBasedGroups()`, `:457` `updateGemeenteGroups()` | the first delegates to `RoleGrantSync`; the second, which does nothing, goes |
| Service | `lib/Service/Stackiq/ContactPersonHandler.php:1615` `getRoleGroupByOrganizationType()` | reads the organisation type default from `RoleGroupMap`, on the English enum |
| Settings | `lib/Service/SettingsService.php:6375` `getUserGroupsConfig()` and its update; `lib/Controller/SettingsController.php:3379` | `roleMapping`, `organisationTypeRoles` and `accessReviewIntervalDays` in the same admin-only config |
| Admin view | `src/views/settings/sections/UserGroupsConfiguration.vue`, `src/store/modules/settings.js:826` | a Role mapping table and the review interval |
| Listener | new `lib/EventListener/RoleGrantLoginListener.php`, registered next to `UserLoggedInEvent` in `lib/AppInfo/Application.php:779` | syncs the signing-in user |
| Job | new `lib/BackgroundJob/RoleGrantSyncJob.php` | nightly sync of every catalogue user |
| Repair | new step in `lib/Repair/` | writes the role onto the contact person of each current role group member who lacks it |
| Fragment | new `lib/Settings/register.d/organisations-role-mapping-and-access-review.json` | `accessReviewedAt` and `accessReviewedBy` on `contactPerson`, higher version |
| Controller and routes | new `lib/Controller/AccessReviewController.php`; `GET /api/access-review/{organisationUuid}` and `POST /api/access-review/{contactPersonId}/confirm` in `appinfo/routes.php` | the review list and the Keep access action |
| Page and menu | new `src/manifest.d/organisations-role-mapping-and-access-review.json`: page `AccessReview` at `/organisaties/access-review`, `type: custom`, component `AccessReviewView`; menu entry with `permission: access.review`; `src/menu-layout.json` relocation under `Organisaties` | |
| View | new `src/views/access/AccessReviewView.vue`, registered in `src/customComponents.js` | `CnDataTable` from the library (`src/components/CnDataTable`), organisation picker for admins |
| Shell | `lib/Controller/DashboardController.php:54` (from `operations-sync-status-and-progress`) | adds `access.review` to the permission list for admins and functional administrators |

## Decisions

### D1. Role groups stay the access vocabulary; the mapping says who belongs in them

The register's `authorization` rules name the role groups (for example `usage.authorization.read` names `gebruik-beheerder`), and a register import replaces those lists as written. Renaming the group a role uses would mean rewriting every schema's rules and losing the change on the next import. So a role keeps its group, and the admin chooses which of their own groups grant the role: Inkoop grants Gebruik-beheerder. `RoleGrantSync` puts the members of Inkoop into `gebruik-beheerder`. Nextcloud has no nested groups, which is why stackiq keeps the membership in step itself.

Rejected: rewriting `authorization` lists in OpenRegister from the mapping. It fights the import, and a missed schema would silently lock a role out.

### D2. A person's roles have three sources, and the sync sets exactly those

`RoleGrantSync::rolesFor(user)` is the union of the contact person's `roles`, the roles whose source groups the user is in, and the default role of their organisation's type. The sync adds the user to each matching role group and removes them from role groups they no longer derive. It touches only the role groups in `RoleGroupMap`, never another group. Role and group names are compared case-insensitively, which ends the mismatch between Aanbod-beheerder and `aanbod-beheerder` in `updateRoleBasedGroups()`.

The organisation type defaults are keyed on the stored enum: Municipality and Collaboration to Gebruik-beheerder, Supplier and Community to Aanbod-beheerder, the same intent as the comment at `ContactPersonHandler.php:1620` to `:1623`, and editable in the mapping.

### D3. When the sync runs

At sign-in (a listener on `UserLoggedInEvent`, the event `TestEventListener` already receives at `lib/AppInfo/Application.php:779`), after the admin saves the mapping (for the members of every changed source group), when a contact person's `roles` change (the existing `updateUserGroups()` path), and nightly for everyone, so a change in a directory group reaches people who do not sign in.

### D4. The review lives on the contact person

`contactPerson` gains `accessReviewedAt` and `accessReviewedBy`. Keep access sets both through OpenRegister, so the audit trail shows who confirmed whom and when. A person is due when `accessReviewedAt` is empty or older than the interval (default 365 days). Remove role edits the contact person's `roles`, and the sync follows. Disable account calls the existing admin-only `POST /api/contactpersonen/{contactpersoonId}/disable` (`appinfo/routes.php:177`).

Rejected: a separate review campaign schema. The row asks whether every user still needs access; one date per person answers it, and a campaign can build on it later.

### D5. Who reviews whom

`AccessReviewController` answers a Nextcloud admin for any organisation, and a member of `functioneel-beheerder` or `organisatie-beheerder` for their own active organisation (user value `core`/`organisation`, the rule `PortfolioReportController::isAuthorisedForOrganisation()` applies at `lib/Controller/PortfolioReportController.php:139`). It reads through `ContactpersoonService::getContactPersonsWithUserDetailsForOrganization()` (`lib/Service/ContactpersoonService.php:832`) and adds each role's source from `RoleGroupMap`. A reviewer cannot confirm their own access; the page disables the button on their own row and the endpoint refuses it.

## Declarative versus imperative

- The new contact person fields are declarative schema properties.
- Keeping group membership in step is imperative: it acts on Nextcloud groups, which no `x-openregister-*` rule manages.
- A reminder when reviews fall due fits an `x-openregister-notifications` scheduled rule on `contactPerson`; it is named as a follow-up.

## Seed data

`contactPerson` gains two properties. The demo descriptor `lib/Settings/stackiq_mock_register.json` sets `accessReviewedAt` on the demo contact persons: one a week ago, one fourteen months ago and one empty, so the Access review page shows one current and two due rows on a fresh instance. The seed writer computes the dates from the import date.

## Risks

- **Managed groups.** D2 removes people from role groups they do not derive. The repair step runs first and writes the role onto every current member's contact person, and logs each one, so the upgrade changes nobody's access.
- **A role from a group cannot be removed on the page.** The page names the source group instead of offering Remove role for it.
- **Load of the nightly sync.** It walks catalogue users in batches with an explicit limit, the same bound the organisation sync uses.
- **`SyncAccessPolicy`** from `operations-sync-status-and-progress` checks membership of `functioneel-beheerder`. That group keeps its name under D1 and the sync keeps it filled, so the policy needs no change.
