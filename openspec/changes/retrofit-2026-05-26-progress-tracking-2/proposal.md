# Retrofit — progress-tracking-2

Describes observed behavior of 1 methods as 1 REQ(s) under the `progress-tracking` capability. Code already exists — this change retroactively specifies it (strict coverage policy: every method gets a spec).

## Affected code units

- lib/Service/ProgressTracker.php::cleanupOldProgress

## Approach

- Describe observed inputs/outputs/side-effects of each method.
- Group methods implementing the same observable behavior under one REQ.

Source: openspec/coverage-report (csc.py --mode report) generated 2026-05-26. Umbrella: ConductionNL/softwarecatalog#294.
