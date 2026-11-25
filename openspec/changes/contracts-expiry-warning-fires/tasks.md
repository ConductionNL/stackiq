# Tasks: contracts-expiry-warning-fires

## Implementation tasks

### Task 1: Fix the contract-expiry rule
- **spec_ref**: openspec/changes/contracts-expiry-warning-fires/specs/contract-expiry-warning/spec.md#requirement-req-cew-001-the-contract-expiry-rule-must-match-active-and-expiring-contracts-whose-end-date-falls-within-90-days
- **files**: `lib/Settings/softwarecatalogus_register.json`
- **acceptance_criteria**:
  - GIVEN the register WHEN `catalogContract.x-openregister-notifications.contract-expiry.trigger.filter.status` is read THEN it is `{"operator": "in", "values": ["Active", "Expiring"]}`
  - GIVEN the register WHEN the trigger is read THEN `dedupeFields` is `["endDate"]` and the `endDate` clause is still `withinNext P90D`
  - GIVEN the register WHEN the subject is read THEN `nl` is `Contract verloopt: {{contractNumber}} (einddatum {{endDate}})` and `en` is `Contract expiring: {{contractNumber}} (end date {{endDate}})`
  - GIVEN the register WHEN the versions are read THEN `catalogContract.version` is above 0.1.2 and `info.version` above 2.5.7, with a changelog line naming this change
- [x] Implement
- [x] Test (PHPUnit tests/Unit/Settings/ContractExpiryRuleTest.php)

### Task 2: Guard the rule against the schema
- **spec_ref**: openspec/changes/contracts-expiry-warning-fires/specs/contract-expiry-warning/spec.md#requirement-req-cew-002-the-warning-must-name-the-contract-number-and-the-end-date
- **files**: `tests/Unit/Settings/ContractExpiryRuleTest.php`
- **acceptance_criteria**:
  - GIVEN the merged register (monolith plus `lib/Settings/register.d/*.json`) WHEN the test runs THEN every `{{placeholder}}` in both subject locales is a property of `catalogContract`
  - GIVEN the merged register WHEN the test runs THEN every field in `trigger.filter` and `trigger.dedupeFields` is a property of `catalogContract`
  - GIVEN the merged register WHEN the test runs THEN `Active` is in the status clause and in the `status` enum
  - GIVEN a copy of the rule with `{{contractNummer}}` WHEN the test's checker runs on it THEN it reports `contractNummer`
- [x] Implement
- [x] Test (PHPUnit tests/Unit/Settings/ContractExpiryRuleTest.php)

### Task 3: Live check on a dev instance
- **spec_ref**: openspec/changes/contracts-expiry-warning-fires/specs/contract-expiry-warning/spec.md#requirement-req-cew-003-the-warning-shall-come-once-per-end-date
- **files**: none (a check, recorded in the PR body)
- **acceptance_criteria**:
  - GIVEN the register re-imported and one Active contract with an end date 30 days out WHEN OpenRegister's `ScheduledNotificationJob` runs (`occ background-job:execute <id> --force-execute`) THEN an admin in `software-catalog-admins` has a notification reading `Contract expiring: <number> (end date <date>)`
  - GIVEN the same contract WHEN the job runs a second time THEN no second notification appears
  - GIVEN the job's log line `fired "contract-expiry"` WHEN it is read THEN `matched` and `dispatched` are at least 1
- [x] Run and record (25 Nov 2026, sandbox Nextcloud 34 + OpenRegister main, admin in `software-catalog-admins`; contracts made Active through `approvalState: approved`, as the approved decision writes them)
  - Contract VBS-2026-017, end date 30 days out: the job's run left admin one notification `Contract expiring: VBS-2026-017 (end date 2026-12-25)`.
  - A second forced run (`occ background-job:execute <ScheduledNotificationJob id> --force-execute`) logged `fired "contract-expiry" on schema 48: matched=2 dispatched=0 deduped=2 of 4`, and no second notification appeared.
  - A fresh contract VBS-2026-018 (end date 45 days out) and one run: `matched=3 dispatched=1 deduped=2 of 5` and the notification `Contract expiring: VBS-2026-018 (end date 2027-01-09)`; the next run `matched=3 dispatched=0 deduped=3 of 5`.

### Task 4: Document the warning
- **spec_ref**: openspec/changes/contracts-expiry-warning-fires/specs/contract-expiry-warning/spec.md#requirement-req-cew-001-the-contract-expiry-rule-must-match-active-and-expiring-contracts-whose-end-date-falls-within-90-days
- **files**: `docs/features/contract-expiry-warning.md`
- **acceptance_criteria**:
  - GIVEN the docs WHEN a reader opens the page THEN it says who gets the warning, when (90 days before the end date, once per end date) and that the first run after an upgrade warns about every contract already in the window
- [x] Implement

## Verification

- `openspec validate contracts-expiry-warning-fires --type change --strict`
- PHPUnit: tests/Unit/Settings/ContractExpiryRuleTest.php
- The live check in Task 3, written into the PR body
