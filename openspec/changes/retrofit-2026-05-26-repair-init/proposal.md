# Retrofit — repair-init

Describes observed behavior of 2 methods as 1 REQ(s) under the `repair-init` capability. Code already exists — this change retroactively specifies it (strict coverage policy: every method gets a spec).

## Affected code units

- lib/Repair/InitializeSettings.php::run
- lib/Repair/InitializeSettings.php::getName

## Approach

- Describe observed inputs/outputs/side-effects of each method.
- Group methods implementing the same observable behavior under one REQ.

Source: openspec/coverage-report (csc.py --mode report) generated 2026-05-26. Umbrella: ConductionNL/softwarecatalog#294.
