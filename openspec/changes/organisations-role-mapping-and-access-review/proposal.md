---
kind: code
depends_on:
  - operations-sync-status-and-progress
---

# Map catalogue roles onto your own groups, and review who still needs access

## Summary

Stackiq's access rules name fixed groups such as `gebruik-beheerder` and `aanbod-beheerder`. A municipality that already keeps its buyers in a group called Inkoop cannot tell stackiq that those people are buyers; someone has to copy them into the fixed group by hand and keep it up to date. Nor can anyone check whether the people who hold a role still need it: stackiq fetches each user's last login and never shows it. This change lets a Nextcloud admin choose, per catalogue role, which groups grant it, keeps the role groups in step with that choice, fixes two paths that stopped assigning roles, and adds an Access review page where a functional administrator sees each person's roles, where they come from and when they last signed in, and confirms or withdraws them.

## Why

This change covers two matrix rows.

- `stackiq:org-roles`, "Map catalogue roles such as administrator, buyer and civil servant onto user groups." Stackiq rates itself partial: an admin configures which groups count as generic users, organisation admins and super users, while the catalogue roles map to fixed group names. SAP LeanIX rates yes: "The role to be assigned to the user. Required values: ADMIN, MEMBER, or VIEWER" and "customer_roles ... The custom role to be assigned", mapped from identity provider groups (https://help.sap.com/docs/leanix/ea/sso-attribute-overview). BlueDolphin rates yes: "BlueDolphin uses Role-Based Access Control (RBAC). Every user is linked to one or more roles ... Add and delete custom roles" (https://help.bluedolphin.io/en/articles/11967624-manage-roles-and-permissions). GLPI rates yes from its source at 11.0.9: roles are profiles (`src/Profile.php:55`) mapped onto groups by authorisation rules (`src/RuleRight.php:236` group criterion, `:297` profile action). TOPdesk rates yes: "Assign these permissions via Supporting Files > Permission Groups > [Permission Group]" (https://docs.topdesk.com/en/automated-actions.html).
- `stackiq:org-access-review`, "Review periodically whether every user still needs their access, and withdraw what is no longer needed." Stackiq rates itself no. The demand is a tender: Helmond REQ78 asks administrators to check periodically that every user still needs access (https://www.tenderned.nl/aankondigingen/overzicht/398728).

`org-roles` is partial and built: this change builds the mapping of each catalogue role onto a group the administrator chooses. `org-access-review` is built new.

## What stackiq has today

Read at development 49e65cb4.

- `src/views/settings/StackiqSettings.vue:80` mounts `UserGroupsConfiguration`, which reads and writes `/api/user-groups/config` (`src/store/modules/settings.js:830`, `appinfo/routes.php:153` and `:154`, `lib/Controller/SettingsController.php:3379`, admin only). It holds generic groups, organisation admin groups and super user groups (`lib/Service/SettingsService.php:6375`). No catalogue role appears in it.
- `lib/Service/Stackiq/GroupHandler.php:167` creates the fixed role groups `aanbod-beheerder`, `gebruik-beheerder`, `gebruik-raadpleger`, `functioneel-beheerder`, `vng-raadpleger`, `organisatie-beheerder`, `organisaties-beheerder` and `ambtenaar`. The register's `authorization` rules name these groups.
- `GroupHandler::updateRoleBasedGroups()` (`:286`) walks only the configured generic groups (default `software-catalog-users`, `:103`) and adds a user when a group name equals one of the contact person's `roles` exactly. The roles are written with a capital (Aanbod-beheerder, `contactPerson.roles` enum) and the groups in lower case, so a contact person's role never puts them in its role group this way.
- `lib/Service/Stackiq/ContactPersonHandler.php:1615` `getRoleGroupByOrganizationType()` gives a new contact person a role group by organisation type, keyed on `gemeente`, `leverancier`, `samenwerking` and `community` (`:1624`). `organization.type` now holds Municipality, Supplier, Collaboration and Community (`lib/Repair/RenameDutchCatalogValues.php:66` to `:68`), so only Community still matches. `GroupHandler::updateGemeenteGroups()` (`:457`) does nothing any more.
- `lib/Service/ContactpersoonService.php:888` returns each account's `lastLogin` through `GET /api/contactpersonen/organisation/{organizationUuid}/with-user-details` (`appinfo/routes.php:170`), and `src/components/ContactpersonenList.vue:482` stores it. Nothing renders it. No review, recertification or expiry of access exists in `lib/` or `src/`.

## What this change builds

- A role mapping in the admin User groups section: for each catalogue role, the Nextcloud groups whose members hold it, and the default role per organisation type.
- One role service that every path uses: a person's roles come from their contact person's `roles`, from the mapped groups they are in, and from their organisation's type; stackiq keeps them in exactly the matching role groups, at sign-in, when the mapping changes, and nightly.
- The two broken paths fixed through that service: roles to role groups, and organisation type to role.
- An Access review page under Organisations, for functional administrators of an organisation and Nextcloud admins: each person with an account, their roles and where each comes from, last sign-in, account status, when their access was last reviewed and whether a review is due. Keep access records the review; Remove role withdraws a role; a Nextcloud admin can also disable the account.
- A review interval in admin settings.

## Out of scope

- Mapping identity provider claims to roles. OpenRegister derives groups from sign-in claims (`lib/Service/Rbac/DerivedGrantResolver.php` in OpenRegister); an admin who signs in through SAML or OpenID Connect maps claims to the role groups there.
- New catalogue roles or changing what a role may do. The register's `authorization` rules stay as they are and keep naming the role groups.
- Reminders when a review is due. The page shows what is due; a notification can follow as a declarative rule.
- Reviewing Nextcloud admins or accounts outside the catalogue.

## Risks

- Role groups become managed. A person an admin put in `gebruik-beheerder` by hand, without the role, would be taken out at the next sync. A migration step writes the role onto the contact person of every current member first, so nobody loses access at upgrade.
- Withdrawing a role that came from a mapped group does not stick while the person stays in that group. The page says where each role comes from and offers Remove role only for roles on the contact person; for a group role it names the group to change.
