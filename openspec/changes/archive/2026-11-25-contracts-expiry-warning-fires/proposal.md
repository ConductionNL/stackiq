---
kind: code
depends_on: []
---

# The contract expiry warning reaches someone before the contract ends

## Summary

Stackiq declares a warning for contracts that are about to expire, and OpenRegister runs it every day. It has never sent one. The rule looks for contracts with status `Actief`, a value no contract holds since the status enum became English, and its subject names two fields the schema no longer has. This change makes the rule match the contracts it was written for, fills its subject from the real fields, and adds a test that fails when the rule and the schema drift apart again.

## Why

This change covers one matrix row.

- `stackiq:ctr-expiry-alert`, "Get warned before a contract expires." Stackiq rates no, state specified. The delivered change `2026-06-14-contract-administration` (archived) declared the rule but never checked that it fires. TOPdesk rates yes: "TOPdesk warns you when contracts are about to expire" (https://docs.topdesk.com/en/managing-your-service-and-supplier-contracts.html). SAP LeanIX rates yes: "Prevent missed renewals through proactive notification workflows" (https://help.sap.com/docs/leanix/ea/step-2-set-up-contract-lifecycle-automations). GLPI rates yes: `src/Contract.php:1092` `cronContract` sends end-of-contract and notice events.

The row's open question was whether OpenRegister dispatches scheduled rules at all, and whether it would forgive `Actief` against `Active`. Read on OpenRegister development: it does dispatch them (`lib/BackgroundJob/ScheduledNotificationJob.php:215` picks every rule with `trigger.type` `scheduled`), and it does not forgive the mismatch (`lib/Service/Notification/ScheduledFilterEvaluator.php` `leafMatches()`, `equals` is `$actual === $operand`). So the missing part sits in stackiq alone.

## What stackiq has today

Read at development 8f74f890.

- `lib/Settings/softwarecatalogus_register.json:3253` declares `contract-expiry` on `catalogContract`: scheduled once a day, filter `status equals Actief` and `endDate withinNext P90D`, channels Nextcloud notification and email, recipients the `software-catalog-admins` group and users with manage rights on the contract.
- `catalogContract.status` has the enum Active, Expired, In negotiation. No row holds `Actief`, so the filter matches nothing and the rule never fires.
- The subject reads `{{contractNummer}}` and `{{eindDatum}}`. The properties are `contractNumber` and `endDate`, so a fired warning would show two empty gaps.
- `lib/BackgroundJob/ContractStatusJob.php:78` moves a contract to Expired the day after its end date, without a warning beforehand.
- No test reads the rule.

## What this change builds

- The rule's status clause becomes `status in [Active, Expiring]`. Active is today's value. Expiring is the status that the open change `contracts-expiry-and-owner` adds; listing it now keeps the warning firing once that change moves contracts out of Active inside the window. A value that is not yet in the enum matches no row and does no harm.
- The subject reads `{{contractNumber}}` and `{{endDate}}`, in Dutch and English.
- The rule names `endDate` in `dedupeFields`, so a contract warns once per end date, and warns again when someone extends it and the new end date comes into the window.
- The schema version and the register version move up, with a changelog line, so the import picks the change up.
- A PHPUnit test checks every filter value and every subject placeholder of `contract-expiry` against the `catalogContract` schema.

## Out of scope

- The Expiring status, the responsible user as a recipient and the Expiring quick filter on the Contracts page. Those are `contracts-expiry-and-owner` (rows `ctr-status`, `ctr-contract-owner`), which leaves this row to this change.
- A configurable warning window. The rule keeps P90D, which matches the default of `contract_expiry_window_days`. A rule cannot read an app setting; changing the window means changing the declaration.
- The other rules in this register with Dutch placeholders (`phaseout-approaching` on `usage`, `dpia-review-overdue` on `module`). They are listed in design.md as follow-ups, not fixed here.
- Dispatching. OpenRegister owns the notification engine (ADR-031).

## Risks

- On the first daily run after deploy, every contract that ends within 90 days warns at once. That is the intended backlog, but an instance with many contracts sends a burst. The release note says so.
- If `contracts-expiry-and-owner` lands first and renames the status values again, the test in this change fails, which is the point.
