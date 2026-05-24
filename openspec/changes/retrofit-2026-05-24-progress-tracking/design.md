# Design — Retrofit Progress Tracking

Retrofit change. Tasks describe retroactive annotation, not new implementation work.

## Context

`ProgressTracker` is a session-backed reporter created circa the ArchiMate import work and reused by sync workflows. It has no existing spec. This change captures observed behavior so the cluster moves from Bucket 2b to Bucket 1 in future scans.

## Decisions

- **Cluster mode (`--cluster progress-tracking`)**, not `--extend`. No existing capability covers this behavior; `method-decomposition` is a refactoring spec, not a feature spec.
- **5 REQs** consolidate 13 methods. `startOperation` / mutators / error collectors / completion / snapshot access are 5 distinct observable behaviors. Per-method REQs would inflate granularity (e.g. `setPhase` and `updateProgress` describe the same "mutate the active operation" contract from different entry points).
- **Bug flagged in Notes, not fixed.** `calculateOverallPercentage()` returns 0 because of an empty if-block. The REQ language describes the contract (weighted-phase percentage); the bug is captured in Notes for a follow-up issue. Reverse-spec doctrine: observe, do not fix.
- **`cleanupOldProgress()` not specified.** Method body is a no-op log statement. Not surfaced as a REQ; track as TODO via follow-up.

## Out of scope

- Refactoring `calculateOverallPercentage()` — open a separate issue.
- Implementing `cleanupOldProgress()` — open a separate issue.
- Multi-operation-per-session support — current design is single-slot.

## References

- Umbrella: ConductionNL/softwarecatalog#285
- Coverage report: openspec/coverage-report.md (2026-05-24)
- Source: lib/Service/ProgressTracker.php
