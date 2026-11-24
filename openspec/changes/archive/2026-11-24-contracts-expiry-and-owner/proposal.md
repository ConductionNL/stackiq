---
kind: code
depends_on: []
---

# Contracts show when they are expiring and name who is responsible

## Summary

A contract in stackiq jumps from Active straight to Expired on the day its end date passes. Nobody sees it coming, and the only person named on a contract is a free-text name and email that no warning can reach. This change adds an Expiring status that the daily contract job sets inside the notice window, and a responsible Nextcloud user on each contract, so the expiry warning has someone to go to.

## Why

This change covers two matrix rows.

- `stackiq:ctr-status`, "See each contract's status move from active to expiring to expired on its own." Stackiq rates itself partial: the daily job moves Active to Expired, but there is no expiring state in between. SAP LeanIX rates yes: "The contract fact sheet uses lifecycle phases to represent the current state of a contract: Plan, Phase In, Contract Start Date (active), Contract Notice Period, Contract End Date (expired)" (https://help.sap.com/docs/leanix/ea/contract-extension-to-meta-model). TOPdesk rates yes: "Status: Configurable drop-down showing the contract's lifecycle status, e.g. draft, active ... Reminder date" (https://docs.topdesk.com/en/creating-a-contract.html) and "The contract will terminate once the end date passes" (https://docs.topdesk.com/en/terminating-a-contract.html).
- `stackiq:ctr-contract-owner`, "Name the person or team responsible for a contract, so expiry warnings go to them." Demand: a GLPI feature request asks for contract assignees as notification recipients (https://github.com/glpi-project/roadmap/discussions/290). SAP LeanIX rates yes: automations "notify contract owners at key milestones" (https://help.sap.com/docs/leanix/ea/step-2-set-up-contract-lifecycle-automations). TOPdesk rates yes: "Operator The TOPdesk operator responsible for managing the contract ... Reminder date Date on which an operator should be reminded about the contract, e.g. ahead of expiry" (https://docs.topdesk.com/en/creating-a-contract.html) and "notify a manager that a contract will expire in a month" (https://docs.topdesk.com/en/events-that-trigger-actions.html).

Both rows are partial and built. This change builds the missing half of each: the expiring state between active and expired, and an expiry warning that reaches the named person.

## What stackiq has today

Read at development 49e65cb4.

- `lib/Service/ContractStatusService.php:77` `shouldExpire()` returns true only for status `Active` with a parseable `endDate` in the past. `:114` `expirePastContracts()` queries Active contracts (`:136`) and saves them as `Expired` (`:157`).
- `lib/BackgroundJob/ContractStatusJob.php:57` runs that pass once a day. It is registered in `appinfo/info.xml:99`.
- `lib/Settings/softwarecatalogus_register.json:3428` `catalogContract.status` has the enum Active, Expired, In negotiation. There is no expiring value.
- `lib/Settings/softwarecatalogus_register.json:3531` the schema's `x-openregister-lifecycle` still names the Dutch states Actief, Verlopen and In onderhandeling, while the enum and the stored rows are English. A lifecycle whose states match no row offers no transition.
- `lib/Settings/softwarecatalogus_register.json:3398` `contactPersonUser` is a nested object with a name and an email. It is not a Nextcloud user, so no notification recipient can resolve it.
- `lib/Settings/softwarecatalogus_register.json:3253` declares the `contract-expiry` notification. Its recipients (`:3258`) are the `software-catalog-admins` group and users with manage rights on the record. The person responsible is not among them.
- `lib/Repair/InitializeSettings.php:103` seeds `contract_expiry_window_days` with 90. No code reads it.
- `src/manifest.json:545` the Contracts page quick filter "Expiring / expired" filters `status` equal to `Expired` only, so it never shows a contract that is about to expire.

## What this change builds

- A fourth status value, `Expiring`, on `catalogContract.status`.
- The daily contract job moves an Active contract to Expiring when its end date falls inside the notice window, moves an Expiring contract to Expired once the end date has passed, and moves an Expiring contract back to Active when someone extends its end date past the window.
- The notice window comes from `contract_expiry_window_days`, which the job starts reading.
- The schema's lifecycle block names the English states the enum and the rows use, with Expiring added.
- The Contracts page gets separate Expiring and Expired quick filters.
- A `responsibleUser` property on `catalogContract`: a Nextcloud user picked in the contract form, shown on the contract detail page and as a column on the Contracts page.
- The `contract-expiry` notification rule gains a recipient that reads `responsibleUser`, so the responsible person gets the warning together with the administrators.

## Out of scope

- Making the `contract-expiry` rule fire. Its filter compares `status` with `Actief` and its subject uses the old Dutch field names. That is the pending row `stackiq:ctr-expiry-alert`, marked specified, and it is fixed there, not here. This change only adds a recipient to the rule.
- Dispatching scheduled notifications. OpenRegister owns the notification engine (ADR-031) and resolves the `field` recipient kind.
- A responsible team. OpenRegister resolves a `field` recipient only as a single user id (`NotificationRecipientResolver.php:187` in OpenRegister). A group held in a contract field needs a new recipient kind in OpenRegister first.
- Renewal chains, obligations and spend. Shillinq owns the contract lifecycle beyond the catalogue view (ADR-066), as the archived change 2026-06-14-contract-administration decided.
- An admin screen for the notice window. The setting is changed with `occ config:app:set` until a settings section asks for it.

## Risks

- A stored status can drift from the end date when someone edits the date. The job re-evaluates Expiring contracts every day, so the drift lasts at most one day.
- Adding an enum value and a property changes the schema. The schema version and the register version must both move up, or the import skips the change (register changelog 2.4.4, `lib/Settings/softwarecatalogus_register.json:7`).
- Contracts saved as Expiring by the job are visible to every reader of the contract. That is the intent, but a Nextcloud admin who filters on Active in a script sees fewer contracts than before.
