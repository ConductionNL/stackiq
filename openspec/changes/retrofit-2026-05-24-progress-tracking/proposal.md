# Retrofit — progress-tracking

Describes observed behavior of 13 methods in `ProgressTracker` as 5 new REQs under a new `progress-tracking` capability. Code already exists — this change retroactively specifies it.

## Affected code units

- lib/Service/ProgressTracker.php::startOperation
- lib/Service/ProgressTracker.php::setPhase
- lib/Service/ProgressTracker.php::updateProgress
- lib/Service/ProgressTracker.php::incrementProgress
- lib/Service/ProgressTracker.php::addError
- lib/Service/ProgressTracker.php::addWarning
- lib/Service/ProgressTracker.php::updateStatistics
- lib/Service/ProgressTracker.php::completeOperation
- lib/Service/ProgressTracker.php::getProgress
- lib/Service/ProgressTracker.php::calculateOverallPercentage
- lib/Service/ProgressTracker.php::calculateEstimatedCompletion
- lib/Service/ProgressTracker.php::saveProgress
- lib/Service/ProgressTracker.php::cleanupOldProgress

## Approach

- For each method: describe observed inputs, outputs, pre/postconditions, failure modes from the existing source.
- Draft REQs that match observed behavior (not aspirational).
- Notes section surfaces observed-but-suspicious behavior — most notably the `calculateOverallPercentage()` empty-if bug that always returns 0, and the stubbed `cleanupOldProgress()` which only logs.

Source: openspec/coverage-report.md generated 2026-05-24. See [retrofit playbook](../../../../.github/docs/claude/retrofit.md). Umbrella: ConductionNL/softwarecatalog#285.
