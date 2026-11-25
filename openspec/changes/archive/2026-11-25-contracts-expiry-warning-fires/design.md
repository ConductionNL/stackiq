# Design: contracts-expiry-warning-fires

Read at stackiq development 8f74f890 and OpenRegister development.

No board covers this change: stackiq is one of the apps without a canvas board (decision 75), and the change adds no screen.

## Where it fits

| Part | File and line | What changes |
|---|---|---|
| Rule | `lib/Settings/softwarecatalogus_register.json:3254` `contract-expiry.trigger` | `status` clause becomes `{"operator": "in", "values": ["Active", "Expiring"]}`; `dedupeFields: ["endDate"]` added |
| Rule | `lib/Settings/softwarecatalogus_register.json:3261` `contract-expiry.subject` | placeholders become `{{contractNumber}}` and `{{endDate}}` |
| Versions | `catalogContract.version` (now 0.1.2) and `info.version` (now 2.5.7) | both move up a patch, with a changelog line in `info.changelog` |
| Test | new `tests/Unit/Settings/ContractExpiryRuleTest.php` | loads the merged register and checks the rule against the schema |

No controller, route, service, page or store changes. OpenRegister reads the declaration and sends the warning (ADR-031).

## Decisions

### D1. Fix the rule in the monolith, not in a fragment

`SettingsService::loadSettings()` deep-merges `lib/Settings/register.d/*.json` into the register and appends lists. Replacing a scalar inside the rule (`value`, the subject strings) through a fragment would leave the reader guessing which side wins. The rule lives in the monolith, so the fix goes there. `contracts-expiry-and-owner` appends a recipient through its fragment, which still merges cleanly on top.

### D2. Match Active and Expiring with `in`

OpenRegister's scheduled filter grammar (`lib/Service/Notification/ScheduledFilterGrammar.php`) accepts `in` with the list under `values`. `membershipMatches()` compares strictly, so the values are written exactly as the enum holds them. Expiring is listed ahead of its enum value on purpose: the moment `contracts-expiry-and-owner` starts saving contracts as Expiring inside the window, an `equals Active` rule would stop matching every contract it should warn about.

Rejected: dropping the status clause. Expired contracts have an end date in the past and fall outside `withinNext` anyway, but a contract In negotiation with an end date in the window would warn, and a negotiation is not an expiry.

### D3. Warn once per end date

`ScheduledNotificationJob::resolveWatchedFields()` already fingerprints the fields under a date operator when `dedupeFields` is absent, so the rule would dedupe on `endDate` today. The rule names `dedupeFields: ["endDate"]` anyway, so a later clause with another date operator does not silently change when the warning repeats. Extending a contract changes `endDate`, which re-arms the warning for the new date.

### D4. Guard the rule with a test that reads the schema

The bug survived because nothing compared the rule to the schema after the enum and the field names were translated. `ContractExpiryRuleTest` loads the register the way `SettingsService` does (monolith plus fragments), and asserts:

- every value in the `status` clause that is meant to exist today (Active) is in the `status` enum;
- every `{{placeholder}}` in every subject locale is a property of `catalogContract`;
- every field named in the filter and in `dedupeFields` is a property of `catalogContract`.

## Follow-ups seen while reading

These are not part of this row and stay unchanged here:

- `usage.x-openregister-notifications.phaseout-approaching` filters on `startDateOutPhasing` and its subject reads `{{contractNummer}}` and `{{startDatumUitTeFaseren}}`.
- `module.x-openregister-notifications.dpia-review-overdue` reads `{{naam}}` and `{{dpiaVolgendeBeoordeling}}`.

The same test pattern can cover both once someone checks them against their schemas.
