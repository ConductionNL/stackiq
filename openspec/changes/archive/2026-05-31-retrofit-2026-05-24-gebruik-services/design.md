# Design — Retrofit Gebruik Services

Retrofit change. Tasks describe retroactive annotation, not new implementation work.

## Context

`GebruikService` is the read-side facade for gebruik (usage) records — distinct from the sync-side `GebruikSyncService`, which is already in Bucket 1 (`method-decomposition#REQ-DECOMP-011`). The companion `getGebruiksConfiguration()` and `getObjectService()` are private plumbing folded into the public REQs.

## Decisions

- **Cluster mode** — no existing capability covers read-side gebruik behaviour.
- **2 REQs / 3 methods** — `getGebruiksConfiguration` is plumbing for both public methods; folded into the REQ descriptions and not annotated separately because it is private.
- **Bug flagged in Notes for `getApplicationIds()`.** The implementation has a malformed if/else-if/unconditional-call sequence that always invokes `getObject()` even when `jsonSerialize` was used or when `getId` doesn't exist. The REQ describes the contract (UUID extraction with fallback order), not the bug. Follow-up issue tracked.
- **`@SuppressWarnings(PHPMD.*)` posture** — the class has no suppressions; PHPMD passes against it cleanly.

## Out of scope

- Fixing the `getApplicationIds()` ObjectEntity bug.
- Adding hardcoded fallback configuration (deliberately removed).
- Refactoring against OR abstractions (ADR-022) — covered by `softwarecatalog-adopt-or-abstractions` change.

## References

- Umbrella: ConductionNL/softwarecatalog#285
- Coverage report: openspec/coverage-report.md (2026-05-24)
- Source: lib/Service/GebruikService.php
