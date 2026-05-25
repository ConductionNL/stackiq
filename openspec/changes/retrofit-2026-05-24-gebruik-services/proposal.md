# Retrofit — gebruik-services

Describes observed behavior of 3 public methods in `GebruikService` as 2 new REQs under a new `gebruik-services` capability. Code already exists — this change retroactively specifies it. The companion `GebruikSyncService` is already covered by `method-decomposition#REQ-DECOMP-011`.

## Affected code units

- lib/Service/GebruikService.php::getGebruiksConfiguration
- lib/Service/GebruikService.php::getGebruiken
- lib/Service/GebruikService.php::getApplicationIds

## Approach

- Describe configuration resolution via SettingsService (no hardcoded fallback) and OpenRegister search behaviour.
- Notes flag the `getApplicationIds()` early-fall-through bug (empty `else if` followed by an unconditionally executed `getObject()` call) — observed-but-suspicious.

Source: openspec/coverage-report.md generated 2026-05-24. Umbrella: ConductionNL/softwarecatalog#285.
