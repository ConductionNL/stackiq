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
- [~] 3.2 subscribe/unsubscribe persistence — PARTIAL. `FederationConfig`
  get/setPeers + the SSRF/listing validation primitives
  (`isPeerHostAllowed`) are built + tested; the subscribe/unsubscribe action
  endpoints wiring directory-listing membership + peer-object cleanup are
  deferred with the live pull leg.
- [~] 3.3 `pullPeer()` merge with `_source` provenance — DEFERRED (blocked on
  2.2: needs the published read surface + two live instances). The provenance
  shape (`_source.instance/organisation/syncedAt`) is defined and enforced
  read-only (3.4).
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
- [~] 4.2 Per-peer staleness tracking — DEFERRED (depends on the pull leg, 3.3).
- [~] 4.3 PHPUnit for staleness/recovery — DEFERRED (depends on 4.2).

## 5. Admin UI

- [~] 5.1 Federation settings section UI — DEFERRED. The status contract
  (`FederationService::getStatus()`: available/enabled/directory/peers/message)
  is built + tested, ready to render; the settings-page section +
  subscribe/unsubscribe controls land with the live subscription leg.
- [~] 5.2 Source attribution + stale indicator on peer entries — DEFERRED
  (no peer-sourced entries exist until the pull leg, 3.3).
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
- [~] Peer-sourced entries are attributed + read-only (403 on write) + marked
  stale — the read-only detection primitive is built + tested; the live
  enforcement + staleness land with the pull leg.
