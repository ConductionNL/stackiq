# Tasks — federated-catalog-sync

> **UPDATED 2026-06-15 — the publication-visibility leg is BUILT on the RBAC
> model.** The earlier blocker note ("federation read surface depends on
> `@self.published`, not settable") is STALE: `@self.published` is
> deprecated/removed from OpenRegister. The live model is RBAC — only entries
> whose `publicatiedatum<=$now` (public read rule `{group:public, match:
> {publicatiedatum:{$lte:$now}}}`) are exposed to anonymous federation reads.
> "Publish to the federation" = set `publicatiedatum` via the shared
> `PublicationService` (`FederationService::publishEntryForFederation()`) — the
> exact same gate that governs anonymous open-data reads. The cross-instance
> live PULL/merge stays deferred — it genuinely needs a two-instance testbed —
> with that honest reason; the publication-visibility half is no longer blocked.

## 1. Foundations

- [x] 1.1 App-config keys + defaults via a `FederationConfig` value object
  (`lib/Service/Federation/FederationConfig.php`): `federation_enabled` (false),
  `federation_directory_url` (directory.opencatalogi.nl), `federation_peers`
  (empty JSON array + get/set), `federation_sync_interval` (3600),
  `local_federation_hosts` (empty). Seeded in the repair step (only when unset).
- [x] 1.2 OpenCatalogi availability detection (`FederationService::isAvailable()`
  via `IAppManager::getInstalledApps` + `class_exists` on the OC
  `DirectoryService`); every entry point no-ops with a logged notice + a
  "unavailable — requires OpenCatalogi" status when unavailable. PHPUnit-covered.

## 2. Publication leg (own catalog → network)

- [x] 2.1 `FederationService::announce()` delegates to OpenCatalogi
  `BroadcastService` with the configured directory URL; returns an ok/reason
  result for the settings UI. No-ops cleanly when disabled / OC missing.
- [x] 2.2 Publication-visibility leg — BUILT on the RBAC model.
  `FederationService::publishEntryForFederation()` delegates to the shared
  `PublicationService::publish()`, which sets `publicatiedatum` via `saveObject`.
  The publishable schemas (`dienst`/`module`/`koppeling`/`organisatie`) carry the
  `{group:public, match:{publicatiedatum:{$lte:$now}}}` read gate, so ONLY entries
  past their `publicatiedatum` are exposed to anonymous federation reads; drafts
  (no `publicatiedatum`) never leave the instance. No `@self.published`.
- [x] 2.3 PHPUnit: announce success-shape, disabled no-op, OpenCatalogi-missing
  no-op; plus `publishEntryForFederation` delegates to PublicationService and
  degrades cleanly when it is unavailable (`FederationServiceTest`).

## 3. Subscription leg (network → local catalog)

- [x] 3.1 `FederationService::discoverPeers()` delegates to OC
  `DirectoryService::getDirectory()`; returns peers or an empty list (with
  reason) when OC is missing. PHPUnit-covered (degrade path).
- [x] 3.2 subscribe/unsubscribe persistence — `FederationConfig` get/setPeers +
  the SSRF/listing validation primitives (`isPeerHostAllowed`) drive the pull
  leg. Peers are the `federation_peers` allowlist; `pullAllPeers()` iterates it,
  and `pullPeer()` reconciles a single peer (3.3). (Build 2026-06-15.)
- [x] 3.3 `pullPeer()` merge with `_source` provenance — BUILT (2026-06-15).
  `FederationService::pullPeer()` SSRF-guards the host, fetches the peer's
  published catalog via OpenCatalogi `DirectoryService` (never a bespoke wire),
  loads THIS peer's local mirrors via the OR `ObjectService`, computes a
  create/update/withdraw plan in `FederationMerger`, and applies it via
  `saveObject` (ADR-022). Each mirror carries `_source.instance/organisation/
  peerEntryId/syncedAt`; inbound `_source` is stripped so a peer cannot spoof
  provenance. Idempotent on the stable peer-entry id; locally-owned entries are
  never in the plan. PHPUnit: `FederationMergerTest` (create/update/withdraw,
  idempotent re-pull, anti-spoof, other-peer mirrors untouched) +
  `FederationServiceTest` (pull-merge, idempotent re-pull, SSRF block).
- [x] 3.4 Read-only enforcement primitive: `FederationService::isPeerSourced()`
  detects a foreign `_source.instance`; PHPUnit-covered. (Wiring the 403 into
  every write path lands with the pull leg — there are no peer-sourced objects
  to protect until 3.3 exists.)
- [x] 3.5 SSRF guard + `local_federation_hosts` allowlist
  (`FederationService::isPeerHostAllowed()`): blocks private/loopback hosts
  unless allowlisted. PHPUnit: blocked private host, allowlisted host.

## 4. Scheduling & staleness

- [x] 4.1 `lib/BackgroundJob/FederationSyncJob.php` (TimedJob, interval from
  config) registered via `appinfo/info.xml` `<background-jobs>` +
  `Application::register()`; announces and no-ops cleanly when OC unavailable.
- [x] 4.2 Per-peer staleness tracking — BUILT (2026-06-15). `FederationConfig`
  keeps a per-peer consecutive-failure counter (`federation_peer_failures`); a
  failed pull increments it and, at/after `federation_stale_after_failures`
  (default 3), the peer's mirrors are marked `_source.stale: true` (surfaced,
  NEVER silently deleted). A healthy pull clears the streak + the stale flag on
  surviving mirrors. Entries the peer no longer publishes are withdrawn
  (`_source.withdrawn:true` + stale), not deleted. Per-peer pull isolation +
  timeout config (`federation_peer_timeout`) so one dead peer can't block the rest.
- [x] 4.3 PHPUnit for staleness/recovery — `FederationMergerTest::testStaleness*`
  + `FederationServiceTest::testPullPeerMarksStaleAfterFailureThreshold`
  (3rd consecutive failure marks the mirror stale).
- [x] 4.4 `FederationSyncJob` now pulls all peers after announce (each isolated;
  failures protected so the cron pass never fails).

## 5. Admin UI

- [~] 5.1 Federation settings section UI — DEFERRED. The status contract
  (`FederationService::getStatus()`: available/enabled/directory/peers/message)
  is built + tested, ready to render; the settings-page section +
  subscribe/unsubscribe controls land with the live subscription leg.
- [x] 5.2 Source attribution + stale indicator DATA on peer entries — BUILT
  (2026-06-15). Every merged mirror carries `_source` (instance/organisation/
  syncedAt) + `_source.stale` / `_source.withdrawn`, the data the UI renders.
  Read-only enforcement is wired into the local write paths: `PublicationController`
  refuses to publish a peer-sourced entry (403) and `ModerationService` refuses
  to moderate one. The Vue badge/disable-edit rendering is the remaining FE slice
  (tracked with 5.1/5.3, the settings-page section + the live legs).
- [~] 5.3 Playwright/Newman for the federation UI + HTTP contracts — DEFERRED
  (depends on the live legs).

## 6. Verification & docs

- [~] 6.1 Two-instance testbed verification of the live PULL/merge — DEFERRED
  (genuinely needs two federated NC instances). The publication-visibility half
  is verified at the RBAC-gate layer by open-data-publishing's `PublishGateRbacTest`
  (public read iff `publicatiedatum<=now`) — the same gate federation reads from.
- [x] 6.2 `docs/GOVERNMENT-FEATURES.md` F-06 — the OpenCatalogi-runtime
  requirement stands; the prior "OR predicate-gap blocker" note is stale (the
  publication leg is built on the RBAC `publicatiedatum<=$now` gate) and is
  removed in the docs sync.
- [x] 6.3 hydra gates green; PHPUnit 194 (incl. the federation publish-leg
  delegation tests).

## Acceptance criteria

- [x] Without OpenCatalogi, the app reports federation as unavailable and makes
  no outbound federation request (degrade path built + tested).
- [x] Empty `local_federation_hosts` blocks all private/loopback targets; a
  populated allowlist permits exactly the listed hosts (built + tested).
- [x] Only published entries are exposed to anonymous federation reads — BUILT:
  the `publicatiedatum<=$now` public RBAC gate on the publishable schemas, set
  via `FederationService::publishEntryForFederation()` → `PublicationService`.
  Live cross-instance discover/subscribe/PULL stays deferred (two-instance
  testbed), with the provenance/read-only guard already in place.
- [x] Peer-sourced entries are attributed + read-only (403 on write) + marked
  stale — BUILT (2026-06-15). `pullPeer()` merges mirrors with `_source`
  provenance; `PublicationController`/`ModerationService` reject local writes to
  peer-sourced entries (403); per-peer failure tracking marks mirrors
  `_source.stale` past the threshold (never deleted). PHPUnit-covered. The LIVE
  cross-instance run against the two-instance fed-testbed (fed1:8081/fed2:8082)
  remains the manual verification step (6.1).
