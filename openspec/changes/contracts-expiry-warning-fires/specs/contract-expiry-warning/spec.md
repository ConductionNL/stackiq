# contract-expiry-warning specification

**Status**: proposed
**Scope**: stackiq
**OpenSpec changes**:
- contracts-expiry-warning-fires

## Purpose

A catalogue administrator, and everyone who manages a contract, gets a Nextcloud notification and an email when the contract's end date comes within 90 days. The warning names the contract number and the end date, and it comes once per end date.

## ADDED Requirements

### Requirement: REQ-CEW-001 The contract-expiry rule MUST match active and expiring contracts whose end date falls within 90 days

The `contract-expiry` rule on `catalogContract` SHALL filter on `status` in Active and Expiring, and on `endDate` within the next 90 days. Every status value that the rule names and the schema already holds SHALL be spelled exactly as the `status` enum spells it.

#### Scenario: An active contract ending in 30 days is warned about
@e2e exclude The rule fires from OpenRegister's daily cron job, not from a browser; tests/Unit/Settings/ContractExpiryRuleTest.php checks the declaration and the live check in tasks.md checks the dispatch.

- **GIVEN** a contract with status `Active` and an `endDate` 30 days from now
- **WHEN** OpenRegister's `ScheduledNotificationJob` evaluates the `contract-expiry` rule
- **THEN** the contract SHALL match the rule
- **AND** the members of `software-catalog-admins` SHALL receive a Nextcloud notification and an email

#### Scenario: A contract in negotiation is not warned about
@e2e exclude Covered by tests/Unit/Settings/ContractExpiryRuleTest.php, which asserts the status clause lists only Active and Expiring.

- **GIVEN** a contract with status `In negotiation` and an `endDate` 30 days from now
- **WHEN** the rule is evaluated
- **THEN** the contract SHALL NOT match the rule

#### Scenario: A contract ending in 200 days is not warned about yet
@e2e exclude The window is OpenRegister's withinNext operator; tests/Unit/Settings/ContractExpiryRuleTest.php asserts the operand is P90D.

- **GIVEN** a contract with status `Active` and an `endDate` 200 days from now
- **WHEN** the rule is evaluated
- **THEN** the contract SHALL NOT match the rule

### Requirement: REQ-CEW-002 The warning MUST name the contract number and the end date

The rule's subject SHALL read the `contractNumber` and `endDate` properties in both the Dutch and the English locale. Every placeholder in the subject SHALL be a property of `catalogContract`.

#### Scenario: The warning shows the contract number and end date
@e2e exclude The subject is rendered by OpenRegister's notifier; tests/Unit/Settings/ContractExpiryRuleTest.php checks every placeholder against the schema.

- **GIVEN** a contract with `contractNumber` `CT-2026-014` and `endDate` `2026-12-31`
- **WHEN** the warning is sent to an English-speaking user
- **THEN** its subject SHALL read `Contract expiring: CT-2026-014 (end date 2026-12-31)`

#### Scenario: A renamed field breaks the test, not the warning
@e2e exclude This scenario is the unit test itself.

- **GIVEN** a subject placeholder that is not a property of `catalogContract`
- **WHEN** `tests/Unit/Settings/ContractExpiryRuleTest.php` runs
- **THEN** the test SHALL fail and name the placeholder

### Requirement: REQ-CEW-003 The warning SHALL come once per end date

The rule SHALL name `endDate` in `trigger.dedupeFields`. A contract SHALL be warned about once while its end date stays the same, and again when its end date changes and the new date falls within the window.

#### Scenario: A daily run does not repeat the warning
@e2e exclude Deduplication is OpenRegister's NotificationDedupeState; the live check in tasks.md runs the job twice.

- **GIVEN** a contract that was warned about yesterday and whose `endDate` has not changed
- **WHEN** the rule is evaluated today
- **THEN** no new warning SHALL be sent for that contract

#### Scenario: An extended contract warns again for its new end date
@e2e exclude Covered by tests/Unit/Settings/ContractExpiryRuleTest.php for the declaration; the re-arm itself is OpenRegister's fingerprint on dedupeFields.

- **GIVEN** a contract that was warned about, and its manager moves `endDate` 60 days later, still within 90 days from now
- **WHEN** the rule is evaluated
- **THEN** a new warning SHALL be sent naming the new end date
