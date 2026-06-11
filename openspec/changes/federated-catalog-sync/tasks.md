# Tasks — federated-catalog-sync

## 1. Foundations

- [ ] 1.1 Add app-config keys with defaults: `federation_enabled` (false),
  `federation_directory_url` (`https://directory.opencatalogi.nl`),
  `federation_peers` (empty JSON array), `federation_sync_interval` (3600),
  `federation_stale_after_failures` (3), `local_federation_hosts` (empty) —
  read via `IAppConfig` in a small `FederationConfig` value object.
- [ ] 1.2 Detect OpenCatalogi availability (`IAppManager::isEnabledForUser` /
  class_exists on `OCA\OpenCatalogi\Service\DirectoryService`) and expose a
  `federationAvailable` flag to settings; all federation entry points no-op
  with a logged notice when unavailable.

## 2. Publication leg (own catalog → network)

- [ ] 2.1 Implement `FederationService::announce()` delegating to OpenCatalogi
  `BroadcastService` with the configured directory URL; record result + timestamp
  in app config for the settings UI.
- [ ] 2.2 Verify softwarecatalog's catalog objects expose the published
  predicate through the OC public publications API (`/api/{catalogSlug}`);
  confirm the magic-mapped `@self.published` gap (OR, 2026-06-11) does not
  apply to the softwarecatalogus register — if it does, file/land the OR fix
  first and reference it here (blocker note, do not work around per-app).
- [ ] 2.3 PHPUnit: announce success/failure paths, OpenCatalogi-missing no-op.

## 3. Subscription leg (network → local catalog)

- [ ] 3.1 Implement `FederationService::discoverPeers()` — pull the directory
  listing via OC `DirectoryService`, return peer org name + instance URL +
  catalog slug.
- [ ] 3.2 Implement subscribe/unsubscribe — validate target is in the
  directory listing AND passes the SSRF guard; persist to `federation_peers`;
  unsubscribe removes all of that peer's merged objects.
- [ ] 3.3 Implement `FederationService::pullPeer()` — fetch the peer's
  published catalog, map entries onto local schemas, upsert with
  `_source.instance` / `_source.organisation` / `_source.syncedAt` metadata,
  remove entries the healthy peer no longer lists; system-actor ownership so
  local RBAC never grants write.
- [ ] 3.4 Enforce read-only: reject writes (HTTP 403) to objects with a
  foreign `_source.instance` in every softwarecatalog write path; PHPUnit +
  Newman negative tests.
- [ ] 3.5 SSRF guard + `local_federation_hosts` allowlist (mirror the
  `opencatalogi/local_federation_hosts` semantics); PHPUnit for blocked
  private host and allowlisted host.

## 4. Scheduling & staleness

- [ ] 4.1 Add `lib/BackgroundJob/FederationSyncJob.php` (TimedJob, interval
  from config, serial per-peer pulls with per-peer timeout); register via
  `IRegistrationContext::registerJob` in `Application::register()` and verify
  with `occ background-job:list` (fleet gotcha: invalid registration = job
  never runs).
- [ ] 4.2 Track consecutive failures per peer; mark merged entries
  `_source.stale: true` after the configured threshold, clear on recovery;
  never delete on failure.
- [ ] 4.3 PHPUnit: job iteration, timeout isolation, staleness threshold,
  recovery.

## 5. Admin UI

- [ ] 5.1 Federation section in admin settings: enable toggle, directory URL,
  registration status, discovered-peer list with subscribe/unsubscribe,
  per-peer last-sync status + staleness, sync interval. NL + EN strings
  (English i18n keys).
- [ ] 5.2 Source attribution + stale indicator on peer entries in catalog
  list/detail views; hide edit/delete actions for peer-sourced entries.
- [ ] 5.3 Playwright e2e for the UI-coverable scenarios (settings flow,
  discover/subscribe with a mocked directory response, read-only peer entry,
  staleness indicator); Newman collection for the HTTP contracts (403 on
  peer-entry write, published-only catalog read).

## 6. Verification & docs

- [ ] 6.1 Two-instance verification against the OC federation testbed
  (fed1:8081/fed2:8082): publish on fed1, subscribe + pull on fed2, assert
  attribution, read-only, unsubscribe cleanup, draft invisibility.
- [ ] 6.2 Update `docs/GOVERNMENT-FEATURES.md` F-06 with the honest scope
  (OpenCatalogi-based federation; non-OpenCatalogi import out of scope) and
  cross-link this spec; align README/info.xml wording.
- [ ] 6.3 `composer check:strict` green; hydra gates green (route-auth on any
  new routes, spec-coverage `@spec` tags on all new methods).

## Acceptance criteria

- With OpenCatalogi enabled and federation configured, a peer instance can
  discover, subscribe to, and pull this catalog — and only published entries.
- Peer-sourced entries are visibly attributed, read-only (403 on write), and
  marked stale instead of deleted when the peer goes dark.
- Empty `local_federation_hosts` blocks all private/loopback federation
  targets; populated allowlist permits exactly the listed hosts.
- Without OpenCatalogi, the app shows federation as unavailable and makes no
  outbound federation requests.
