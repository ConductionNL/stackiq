# 2. Federated peer entries are read-only and go stale instead of being deleted

## Status

Accepted

## Context

Entries pulled from peer instances are merged into the local register. Local users must not be able to change data owned by another instance, and a peer that stops responding should not make its entries silently disappear, because government catalog data is used for procurement decisions.

## Decision

- Merged peer entries carry `_source` provenance (peer instance, peer organisation, sync time), are owned by a system actor and are changed only by the sync job. Local entries are never modified by sync. Because writes are partitioned by origin, there are no write conflicts by construction.
- When a subscribed peer stops responding, its merged entries are marked stale (`_source.stale: true`) after a configurable number of failed sync rounds, default 3, and shown as stale in the UI. They are removed only when the admin unsubscribes from the peer, or when a healthy peer no longer lists them.

## Consequences

- Conflict handling reduces to staleness handling.
- Users can see that an entry may be out of date instead of losing it.
- The merge and staleness logic is in `lib/Service/Federation/FederationMerger.php` (`DEFAULT_STALE_AFTER_FAILURES = 3`).

Source: `openspec/changes/archive/2026-06-14-federated-catalog-sync/design.md` (Decisions 3 and 4)
