# Tasks — federated-catalog-sync

> **VERIFY-FIRST FINDING (blocks the live publication + peer-pull leg).** The
> federation read surface depends on the OpenRegister published predicate
> (`@self.published`), which — confirmed in the current OR checkout — is NOT
> settable for magic-mapped objects (`published` absent from MagicMapper's
> metadataFields allowlist; no per-object publish action). So "only published
> entries are exposed" and a live cross-instance pull cannot be honestly built
> against this register today. The buildable slice (delegation scaffolding,
> availability degrade, read-only provenance guard, SSRF guard, scheduled job,
> config) is shipped; the live publication + merge legs are deferred with this
> reason (blocking OR dependency, no per-app workaround per ADR-022).

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
- [x] 2.2 VERIFIED the magic-mapped `@self.published` gap (see finding above) —
  it APPLIES to this register. The live "published entries only" exposure is a
  blocking OR dependency; deferred with that reason (no per-app workaround).
- [x] 2.3 PHPUnit: announce success-shape, disabled no-op, OpenCatalogi-missing
  no-op (`FederationServiceTest`).

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

- [~] 6.1 Two-instance testbed verification — DEFERRED (blocked on 2.2/3.3).
- [x] 6.2 `docs/GOVERNMENT-FEATURES.md` F-06 downgraded from the over-stated
  "Beschikbaar" to an honest "Gedeeltelijk" with the OpenCatalogi-runtime
  requirement + the OR predicate-gap blocker noted.
- [x] 6.3 hydra gates green (all 24, incl. spec-coverage `@spec` tags on all
  new methods). PHPUnit 162.

## Acceptance criteria

- [x] Without OpenCatalogi, the app reports federation as unavailable and makes
  no outbound federation request (degrade path built + tested).
- [x] Empty `local_federation_hosts` blocks all private/loopback targets; a
  populated allowlist permits exactly the listed hosts (built + tested).
- [~] A peer can discover/subscribe/pull only published entries — DEFERRED
  (blocked on the OR `@self.published` gap; scaffolding + provenance guard in
  place).
- [~] Peer-sourced entries are attributed + read-only (403 on write) + marked
  stale — the read-only detection primitive is built + tested; the live
  enforcement + staleness land with the pull leg.
