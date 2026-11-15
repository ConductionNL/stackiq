---
kind: code
depends_on:
  - landscape-usage-registration
  - landscape-completeness-score
---

# Ask owners to confirm or correct their entries

## Summary

An information manager starts a confirmation round: every owner of an application in use, or every supplier contact of a product, gets a request to confirm the entry is current or to correct it, by a deadline. Owners answer from a notification, see exactly which entries are theirs, and confirm or edit each. The round shows who answered, who corrected and who is overdue, and a confirmed entry's data quality score goes back to fresh.

## Why

Row from the stackiq matrix:

- `stackiq:land-data-quality-survey`, "Ask application owners through a survey to confirm or correct their entries." Rated no. Two competitors rate yes: SAP LeanIX (https://help.sap.com/docs/leanix/ea/application-modernization-collect-data, "Create a new survey to get key information from application or business owners", and https://help.sap.com/docs/leanix/ea/reviewing-responses, "Review and approve survey responses before they are saved") and BlueDolphin (https://help.bluedolphin.io/en/articles/11967524-create-a-survey, surveys "allowing external stakeholders to contribute directly to the Enterprise Architecture repository"). Core area (landscape).

No tender, feature request or roadmap row names it.

## What stackiq has today

- Nothing asks owners to confirm or correct entries: no survey, attestation or confirmation code in `lib/` or `src/`.
- `landscape-usage-registration` adds `businessOwner` and `technicalOwner` to a usage; `module.contactPerson` names the supplier's contact per product. Contact persons become Nextcloud users through `ContactpersonenController::convertToUser()` (`lib/Controller/ContactpersonenController.php:393`).
- `landscape-completeness-score` adds `lastConfirmedAt` and a freshness rule, and a manual Confirm action.
- OpenRegister notifications resolve a recipient from an object field (`kind: field`, `openregister lib/Service/Notification/NotificationRecipientResolver.php:187`), and stackiq already declares notification rules in its register (for example the usage phase-out rule, `lib/Settings/softwarecatalogus_register.json:2662`).

## What this change builds

1. Two schemas: a confirmation round (name, deadline, which entries, who started it) and a confirmation request per entry and owner (status pending, confirmed, corrected or overdue).
2. A service that creates a round's requests from a scope (the organisation's usages, or a supplier's products), resolving each owner to a Nextcloud user.
3. A notification to each owner when a request is created and a reminder three days before the deadline, declared with `x-openregister-notifications`.
4. A "My confirmation requests" page for the owner with Confirm and Edit per entry; confirming sets the entry's `lastConfirmedAt`, editing and saving marks the request corrected.
5. A round page for the information manager with the answer counts and the overdue owners.

## Out of scope

- Free-form survey questions. A request asks one thing: is this entry right. Custom questions per object type belong to a later change.
- An approval step before an owner's correction is saved. Owners already have edit rights on their entries; the round records that they changed it.
- Owners without a Nextcloud account: portaliq's contribution contract covers outside parties (open change `portal-contribution`).

## Risks

- An entry without an owner cannot be asked. The round lists those entries separately so the information manager assigns owners first.
