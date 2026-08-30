---
kind: feature
depends_on: []
---

# softwarecatalog — federated catalog synchronisation

## Why

Federated synchronisation is a headline claim of the app: `appinfo/info.xml`
promises "**Federated synchronization** — Share and sync catalog data with
other organizations", the README describes sharing "over a federated network",
and `docs/GOVERNMENT-FEATURES.md` marks F-06 as *Beschikbaar*. In reality there
is **no spec, no change, and essentially no code** — the only "federat" hits in
the codebase are two frontend files (`src/sidebars/search/SearchSideBar.vue`,
`src/navigation/Configuration.vue`). The existing `organization-sync` spec is
internal SC→OpenRegister sync, not cross-instance federation. Shipping a
marketing claim that municipal buyers will check against a PvE is a
reputational risk (see `FEATURE-REEVALUATION-2026-06-11/softwarecatalog.md`).

This change closes the truth gap by specifying federated catalog
synchronisation between softwarecatalog instances of different government
organisations.

## Design constraint: reuse OpenCatalogi federation, do NOT invent a protocol

OpenCatalogi already ships a proven, live-tested federation stack
(see the OC federation testbed, 2026-06-11):

- **Directory listing** — `DirectoryService` pulls peer listings from a
  directory (e.g. `directory.opencatalogi.nl`); `GET/POST /api/directory`
  are the public endpoints.
- **Broadcast** — `BroadcastService` announces this instance to peers and the
  directory.
- **RBAC publish-gate visibility (updated 2026-06-15)** — only objects whose
  `publicatiedatum<=$now` (the public read rule `{group:public, match:
  {publicatiedatum:{$lte:$now}}}`) are visible to anonymous federation traffic;
  the public publications API is the read surface. The previously-cited
  `@self.published` predicate is deprecated/removed from OpenRegister.
- **SSRF local-federation allowlist** — the config-gated
  `opencatalogi/local_federation_hosts` app-config key (comma-separated,
  empty by default) lets private/loopback peer hosts through the SSRF guard
  for local/test federation (`DirectoryService` + `BroadcastService`).

Softwarecatalog therefore federates by **publishing its catalog as an
OpenCatalogi catalog** and **pulling peer catalogs through the OpenCatalogi
directory**, not by speaking a new wire protocol. The app contributes only:
the mapping of its own schemas (applicatie/voorziening, module, koppeling,
organisatie) onto the published catalog, the merge/provenance semantics for
peer entries, the sync schedule, and the admin controls.

## What Changes

- Register this instance's software catalog with an OpenCatalogi directory
  (configurable directory URL; default `directory.opencatalogi.nl`).
- Publish own software entries to the catalog — **only** entries whose
  `publicatiedatum` is set (and not in the future); drafts and internal records
  never leave the instance.
- Discover peer organisations' catalogs via the directory and let an admin
  subscribe to specific peers.
- Pull subscribed peer catalogs on a schedule (background job) and merge the
  entries into the local register as **read-only, source-attributed** objects.
- Conflict/staleness semantics: peer data is never authoritative for local
  objects and vice versa; stale peers are marked, never silently dropped.
- Admin settings: directory URL, peer allowlist, subscription management,
  sync interval, last-sync status — including the config-gated local-federation
  allowlist pattern mirrored from OpenCatalogi for dev/test federation.

## Capabilities

### New Capabilities

- `federated-catalog-sync`: cross-instance catalog federation — directory
  registration, published-entry publication, peer discovery + subscription,
  scheduled pull + read-only provenance-attributed merge, and admin controls
  with an SSRF-safe peer allowlist.

## Impact

- **New:** `lib/Service/FederationService.php` (directory registration,
  peer pull, merge), `lib/BackgroundJob/FederationSyncJob.php`,
  federation section in the admin settings (`lib/Controller/SettingsController.php`
  + `src/` settings UI), app-config keys
  `softwarecatalog/federation_directory_url`,
  `softwarecatalog/federation_peers`, `softwarecatalog/local_federation_hosts`,
  `softwarecatalog/federation_sync_interval`.
- **Depends on (runtime):** OpenCatalogi `DirectoryService`/`BroadcastService`
  and its public publications API; the OpenRegister `publicatiedatum<=$now`
  public RBAC read gate on catalog objects (NOT the removed `@self.published`).
- **Schema impact:** peer-merged objects carry provenance metadata
  (`_source.instance`, `_source.organisation`, `_source.syncedAt`) — stored via
  OR object metadata, no new app-local tables.
- **Relation to `open-data-publishing`:** the publication leg (what is visible
  to anonymous/peer readers) is the same `publicatiedatum<=$now` RBAC gate +
  `PublicationService` specced there; this change consumes it for the federation
  direction (`FederationService::publishEntryForFederation()`).

## Caveats

- **OpenCatalogi is a hard runtime dependency for federation.** When
  OpenCatalogi is not installed/enabled, federation features MUST degrade to
  a disabled state with a clear admin message — not error.
- **The `@self.published` blocker is RESOLVED/STALE (2026-06-15):** the predicate
  is deprecated and removed from OpenRegister; the live model is the RBAC
  `publicatiedatum<=$now` gate, which works today via a normal `saveObject`.
  Publication of software entries to the federation goes through
  `FederationService::publishEntryForFederation()` → `PublicationService` (task
  2.2). No magic-mapper allowlist or upstream OR fix is required.
- **Import/merge from external (non-OpenCatalogi) sources** — F-07 — is out of
  scope here; the directory/publication-gate path only federates
  OpenCatalogi-speaking instances. A generic `external-listing-import` can
  follow if needed.
- The description/F-06 claim stays over-stated until this change is applied;
  if it is rejected, info.xml and GOVERNMENT-FEATURES.md must be downgraded to
  *Gepland* instead.
