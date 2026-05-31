# Retrofit — aanbod-listings

Describes observed behavior of 4 methods in `AanbodController` as 3 new REQs under a new `aanbod-listings` capability. Code already exists — this change retroactively specifies it.

## Affected code units

- lib/Controller/AanbodController.php::getAanbod
- lib/Controller/AanbodController.php::acceptAanbod
- lib/Controller/AanbodController.php::denyAanbod
- lib/Controller/AanbodController.php::parseQueryOptions

## Approach

- For each method: describe observed inputs, outputs, pre/postconditions, failure modes from the existing source.
- Draft REQs that match observed behavior (not aspirational).
- Notes section flags overlap with `AanbodService` REQs already captured in Bucket 1 (Service-side methods inherit from method-decomposition spec scenarios).

Source: openspec/coverage-report.md generated 2026-05-24. Umbrella: ConductionNL/softwarecatalog#285.
