---
kind: code
depends_on:
  - landscape-usage-registration
---

# Move entries to another organisation without recreating them

## Summary

A functional administrator moves one entry, or a selection of entries, to another organisation: an application that belongs to a sister municipality, usages registered under the wrong organisation, or a contact person who changed employer. The entries keep their identity and relations; only who owns them changes. A dry run shows what will move before anything does.

## Why

Row from the stackiq matrix:

- `stackiq:land-move-between-workspaces`, "Move one or many entries to another workspace or section without recreating them." Rated no. Two competitors rate yes: BlueDolphin (June 2026 update, https://bluedolphin.io/blog/june-2026-bluedolphin-updates/, "You can move one or multiple objects to another workspace directly from the Repository without leaving your current view", also the changelog demand row) and GLPI (source read at 11.0.9, `src/Transfer.php:50` moves selected records to another entity with their links, queued through `src/MassiveAction.php:598`). Core area (landscape).

In stackiq the workspace is the organisation: every record belongs to one through OpenRegister multitenancy (`@self.organisation`), and the domain fields `module.provider`, `usage.consumer`, `connection.provider` and `contactPerson.organization` say the same thing in the data.

## What stackiq has today

- No page or action moves an entry to another organisation.
- The organisation merge moves everything of one organisation into another (`lib/Service/MergeOrganisatieService.php`): it re-points domain fields per type (`FIELD_RELATION_TYPES`, :111) and `@self.organisation` for contracts and compliance claims (`repointBySelfOrganisation`, :442), with a dry run and an execute (`lib/Controller/MergeController.php:82`, `:106`), for a Nextcloud admin only (:142). It moves all of an organisation, never a chosen set.

## What this change builds

1. A transfer service that moves a chosen set of entries (applications, services, usages, connections, contracts, compliance claims, contact persons) from one organisation to another, re-pointing `@self.organisation` and the type's owning field, reusing the merge service's per-type map.
2. A dry run that lists what will move and what will not (entries of another organisation, entries the caller may not edit).
3. A "Move to organisation" action on the list pages for the selected rows, and on each detail page.
4. Authorisation: a Nextcloud admin, or a user who is organisation admin in both the source and the target organisation.

## Out of scope

- Moving between registers or schemas: `landscape-change-entry-type`.
- Merging whole organisations, which stays in the merge panel.
- Moving files between Nextcloud folders; files stay attached to the entry's uuid.

## Risks

- A usage moved to another organisation keeps its connections, which may belong to the old organisation. The dry run lists such links so the administrator moves them together.
