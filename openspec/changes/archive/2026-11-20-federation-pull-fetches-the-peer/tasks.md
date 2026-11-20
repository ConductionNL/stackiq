# Tasks: federation-pull-fetches-the-peer

## Implementation tasks

### Task 1: Fetch the peer over HTTP
- **spec_ref**: openspec/changes/federation-pull-fetches-the-peer/specs/federated-catalog-sync/spec.md#requirement-a-pull-reads-the-peers-own-published-catalogue-req-fed-010
- **files**: `lib/Service/Federation/FederationService.php`, `lib/Service/Federation/PeerCatalogClient.php`, `lib/Service/Federation/FederationConfig.php`, `tests/Unit/Service/PeerCatalogClientTest.php`, `tests/Unit/Service/FederationServiceTest.php`
- **acceptance_criteria**:
  - GIVEN `fetchPeerCatalog()` WHEN called THEN it uses `PeerCatalogClient` (Nextcloud `IClientService`) to GET the peer's public published listing, follows pages up to `federation_max_entries` and returns the peer's organisation name and entries; `DirectoryService::getDirectory()` is no longer called for a pull
  - GIVEN a timeout, non-2xx or invalid JSON WHEN fetching THEN the result is `ok: false` with a reason, and `recordPullFailure()` runs
  - GIVEN a host the SSRF guard blocks WHEN pulling THEN no HTTP request is made
- [x] Implement
- [x] Test (PHPUnit `PeerCatalogClientTest`, `FederationServiceTest`)
  > Built 20 Nov: `PeerCatalogClient` GETs `<peer>/index.php/apps/opencatalogi/api/publications` (`_page`, `_limit` 100) with the peer timeout, `http_errors` off and a private address allowed only for a `local_federation_hosts` host, until the peer has no more pages or `federation_max_entries` (default 10000) is reached. A transport error, a status outside 2xx or a body that is no listing fails the whole fetch. `FederationService::fetchPeerCatalog()` uses it; `DirectoryService` is no longer called for a pull. Tests: `PeerCatalogClientTest` (7), `FederationServiceTest` (both files), `FederationServiceOpenCatalogiTest`, `FederationConfigTest`, `ServiceFactoriesTest`.

### Task 2: Peer status in the table (board SkInstellingenUitwisseling)
- **spec_ref**: openspec/changes/federation-pull-fetches-the-peer/specs/federated-catalog-sync/spec.md#requirement-mirrored-entries-show-where-they-came-from-req-fed-011
- **files**: `lib/Service/Federation/FederationService.php`, `src/views/settings/sections/FederationSettings.vue`, `tests/vitest/federationSettingsPeers.spec.js`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN `getStatus()` WHEN called THEN each peer carries name, lastPulledAt, objectCount and lastError
  - GIVEN the peers table WHEN it renders THEN it shows catalogue, address, last pulled, objects and an error state, with Pull now
- [x] Implement
- [x] Test (vitest `federationSettingsPeers.spec.js`)
  > Built 20 Nov: each pull records the peer's state (`federation_peer_state`): catalogue name, last successful pull, mirrored objects (entries with a stable id) and the last error (`blocked`, `not reachable`, `peer answered HTTP 503`, ...); removing a peer forgets it. `getStatus()` returns them, the name falling back to the host. The peers list shows catalogue, address, last pulled, objects and "Error: ...", with Pull now in the header. Tests: `federationSettingsPeers.spec.js`, `adminApi.spec.js`, `FederationServiceTest`.

### Task 3: Provenance on mirrored entries
- **spec_ref**: openspec/changes/federation-pull-fetches-the-peer/specs/federated-catalog-sync/spec.md#requirement-mirrored-entries-show-where-they-came-from-req-fed-011
- **files**: `src/components/federation/ProvenanceNotice.vue`, `src/manifest.json`, `src/customComponents.js`, `tests/vitest/provenanceNotice.spec.js`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN an object with `_source.instance` WHEN its detail page renders THEN "From: <name>" links to the original and edit and delete are hidden
  - GIVEN a list with mirrors WHEN it renders THEN mirrored rows carry a source badge
- [x] Implement
- [x] Test (vitest `provenanceNotice.spec.js`)
  > Built 20 Nov: `ProvenanceNotice` on OrganisatieDetail (`before-body`) says "From: <catalogue>" with a link to the entry at the peer; the page's `readOnlyWhen` on `_source.instance` turns edit off. The Organisations cards show a "From:" badge and offer a mirror no edit or delete (`src/utils/provenance.js`). Mirrors are organisations (`PEER_MIRROR_TYPE`), so these are the pages that show them. Test: `provenanceNotice.spec.js` (6).
