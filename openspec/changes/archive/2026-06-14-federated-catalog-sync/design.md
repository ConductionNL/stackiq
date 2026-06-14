# Design: federated-catalog-sync

## Decision 1 — Federate through OpenCatalogi, not a new protocol

Softwarecatalog does not implement its own federation wire format. An
instance's catalog becomes an OpenCatalogi catalog (publications backed by the
softwarecatalog register's schemas), and federation is:

```
instance A                          directory                       instance B
----------                          ---------                       ----------
BroadcastService.announce()  --->   listing(A)
                                    listing(B)   <---  BroadcastService.announce()
DirectoryService.pull()      <---   [A, B, ...]
GET /apps/opencatalogi/api/{catalogSlug}  (anon, published-only)  <--- A pulls B
```

Softwarecatalog's `FederationService` is a thin orchestrator over those OC
services plus the app-specific merge. Rationale: the OC stack is live-tested
(federation testbed 2026-06-11, directory.opencatalogi.nl pull path works),
already carries the SSRF guard + allowlist, and ADR-022 forbids rebuilding
platform abstractions per app.

## Decision 2 — Published-predicate is the only publication gate

Only objects with the OpenRegister published predicate (`@self.published` set,
not depublished) are visible to peers — identical to what anonymous readers
see via `open-data-publishing`. There is no separate "federation visibility"
flag. One switch, one mental model: *published = visible to the network*.

## Decision 3 — Peer entries are read-only with provenance

Merged peer entries are stored in the local register but:

- carry `_source.instance` (peer base URL), `_source.organisation` (peer org
  name/UUID), `_source.syncedAt` (ISO timestamp);
- are owned by a system actor, not any local organisation, so local RBAC
  never grants write;
- are skipped by all local write paths (controllers/services MUST refuse
  mutation of objects with a foreign `_source.instance`);
- are updated/removed only by the sync job (peer is authoritative for peer
  data).

Local entries are never modified by sync (local is authoritative for local
data). Because writes are partitioned by origin, there are **no write
conflicts by construction** — "conflict handling" reduces to staleness
handling.

## Decision 4 — Staleness over deletion

If a subscribed peer stops responding, its merged entries are **marked stale**
(`_source.stale: true` after N failed sync rounds, N configurable, default 3)
and surfaced as such in the UI — not deleted. Government catalog data is used
for procurement decisions; silently vanishing entries is worse than visibly
stale ones. Entries are removed only when the admin unsubscribes from the
peer or the peer's catalog no longer lists them while the peer is healthy.

## Decision 5 — Allowlist-gated peers, SSRF posture mirrored from OC

- Subscriptions may only target peers that appear in the directory listing
  **and** pass the SSRF guard (public, resolvable, non-loopback hosts).
- For local/dev federation, mirror the proven OC pattern: app-config key
  `softwarecatalog/local_federation_hosts` (comma-separated, **empty by
  default**) explicitly allowlists private/loopback hosts past the guard.
  Same key semantics as `opencatalogi/local_federation_hosts` so a testbed
  configures both apps identically.
- The peer subscription list itself (`softwarecatalog/federation_peers`) is
  an admin-managed allowlist: no auto-subscribe, ever.

## Decision 6 — Scheduling

One `TimedJob` (`FederationSyncJob`), interval from
`softwarecatalog/federation_sync_interval` (seconds, default 3600). The job
iterates subscribed peers serially with a per-peer timeout so one dead peer
cannot starve the rest. Registered via `IRegistrationContext::registerJob` in
`Application::register()` (note the fleet bug class: invalid registration =
job never runs; verify with `occ background-job:list`).

## Out of scope

- Generic non-OpenCatalogi external-listing import (F-07) — possible
  follow-up `external-listing-import`.
- Push-based (webhook) sync — pull-on-schedule is sufficient for catalog
  freshness requirements and avoids inbound auth complexity.
- Federating write access (cross-instance editing) — explicitly never.
