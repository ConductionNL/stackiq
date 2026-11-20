# 4. Match end-of-life data only on an unambiguous single candidate

## Status

Accepted

## Context

Module versions are stamped with end-of-life dates from the endoflife.date feed. A module version string does not always map cleanly onto a release cycle in the feed, and a wrong date shown with provenance that implies it is sourced is worse than no date at all.

## Decision

Matching is conservative. For each module version, candidate cycles are those whose cycle value is a version prefix of, or equal to, the version string, ranked most specific first. A stamp is written only when exactly one cycle exists at the most specific matching level. Ties and versions without a match are skipped and left untouched. A best-effort closest match with a confidence score was rejected.

## Consequences

- Some versions get no automatic end-of-life date and stay editable by hand, as before.
- An automatically stamped date can be trusted to be an exact match.
- The matcher is `lib/Service/EolMatcherService.php`.

Source: `openspec/changes/archive/2026-07-23-eol-feed-integration/design.md` (Decision 2)
