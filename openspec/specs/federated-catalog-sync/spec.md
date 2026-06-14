# federated-catalog-sync Specification

## Purpose
TBD - created by archiving change federated-catalog-sync. Update Purpose after archive.
## Requirements
### Requirement: Instance registers with an OpenCatalogi directory

The app SHALL register the instance's software catalog with an OpenCatalogi
directory by delegating to OpenCatalogi's `BroadcastService`/`DirectoryService`
(never a bespoke HTTP client). The directory URL SHALL come from the
`softwarecatalog/federation_directory_url` app-config key (default
`https://directory.opencatalogi.nl`). When OpenCatalogi is not installed or
enabled, federation SHALL be reported as unavailable in the admin settings and
no directory traffic SHALL be attempted.

#### Scenario: Admin enables federation and the instance announces itself

- **WHEN** an admin enables federation in the softwarecatalog admin settings with a directory URL configured
- **THEN** the instance's catalog is announced to the directory via the OpenCatalogi broadcast machinery
- **AND** the settings UI shows the registration status (registered / failed with reason)

#### Scenario: OpenCatalogi missing degrades cleanly

@e2e exclude Capability-degradation path requires an environment without OpenCatalogi installed; covered by PHPUnit tests mocking IAppManager.

- **WHEN** federation is enabled but the OpenCatalogi app is not installed or not enabled
- **THEN** the federation section reports "unavailable — requires OpenCatalogi" instead of erroring
- **AND** no outbound directory or peer request is made

### Requirement: Only published entries are exposed to the federation

The catalog published to the directory and readable by peers SHALL contain
only software entries (applicaties/voorzieningen, modules, koppelingen) and
organisation profiles whose OpenRegister published predicate
(`@self.published`) is set and not depublished. Visibility SHALL be enforced
by the OpenRegister/OpenCatalogi published-predicate read surface — the app
SHALL NOT implement its own anonymous filtering.

#### Scenario: Published entry is visible to a peer

@e2e exclude Cross-instance read path needs two federated NC instances (fed-testbed topology); covered by Newman against the public publications API and PHPUnit service tests.

- **WHEN** a software entry has its published predicate set and a peer pulls this instance's catalog
- **THEN** the entry is present in the peer-readable catalog response

#### Scenario: Draft entry never leaves the instance

@e2e exclude Cross-instance read path needs two federated NC instances; covered by Newman anonymous-read assertions.

- **WHEN** a software entry exists without the published predicate (draft/internal)
- **THEN** the peer-readable catalog response does not contain the entry
- **AND** depublishing a previously published entry removes it from the peer-readable response

### Requirement: Peer catalogs are discoverable and subscribable

The app SHALL list peer organisations' catalogs found in the configured
directory and let an admin subscribe to specific peers. Subscriptions SHALL be
stored in the `softwarecatalog/federation_peers` app-config allowlist. The app
SHALL NOT auto-subscribe to any peer.

#### Scenario: Admin discovers and subscribes to a peer

- **WHEN** an admin opens the federation settings and the directory lists peer catalogs
- **THEN** the peers are shown with organisation name and instance URL
- **AND** subscribing adds the peer to the allowlist and an unsubscribe control appears

#### Scenario: Unlisted instance cannot be subscribed

- **WHEN** an admin attempts to add a peer URL that is not present in the directory listing
- **THEN** the subscription is rejected with a message explaining the peer must be listed in the directory

### Requirement: Peer entries are merged read-only with source attribution

Entries pulled from a subscribed peer SHALL be stored as read-only objects
with provenance metadata: `_source.instance` (peer base URL),
`_source.organisation`, and `_source.syncedAt`. All local write paths SHALL
refuse mutation of objects carrying a foreign `_source.instance`. Sync SHALL
never modify locally-created entries; peer pulls SHALL only create, update, or
remove objects originating from that same peer.

#### Scenario: Peer entry shows its source and cannot be edited

- **WHEN** a user views a software entry that was merged from a peer catalog
- **THEN** the entry displays the source organisation and instance attribution
- **AND** edit/delete actions are not offered for the entry

#### Scenario: Write attempt against a peer entry is refused server-side

@e2e exclude Forged-request rejection is an HTTP contract; covered by Newman (authenticated PUT/DELETE against a peer-sourced object expecting 403) and PHPUnit.

- **WHEN** a write request targets an object whose `_source.instance` is a peer
- **THEN** the request is rejected with HTTP 403 and the object is unchanged

#### Scenario: Local entries survive a sync untouched

@e2e exclude Backend merge invariant; covered by PHPUnit service tests on FederationService merge.

- **WHEN** a scheduled sync pulls a peer catalog containing an entry with the same name as a locally-created entry
- **THEN** the local entry is not modified
- **AND** the peer entry is stored as a separate, source-attributed object

### Requirement: Sync runs on a schedule and handles staleness

A background job SHALL pull all subscribed peer catalogs at the interval
configured in `softwarecatalog/federation_sync_interval` (default 3600
seconds), with a per-peer timeout so one unreachable peer cannot block the
rest. After 3 consecutive failed pulls (configurable) a peer's merged entries
SHALL be marked stale (`_source.stale: true`) and surfaced as such — never
silently deleted. Entries are removed only on admin unsubscribe, or when a
healthy peer's catalog no longer lists them.

#### Scenario: Scheduled sync refreshes peer entries

@e2e exclude Background TimedJob execution; covered by PHPUnit job tests and verified via occ background-job:list in the deploy checklist.

- **WHEN** the federation sync job runs for a subscribed, reachable peer
- **THEN** new peer entries are created, changed entries updated, and entries no longer in the peer catalog removed
- **AND** the per-peer last-sync timestamp and result are recorded for the settings UI

#### Scenario: Unreachable peer marks entries stale instead of deleting

- **WHEN** a peer has failed 3 consecutive sync rounds
- **THEN** that peer's entries are marked stale and the UI shows a staleness indicator with the last successful sync time
- **AND** the entries remain readable

#### Scenario: Unsubscribing removes the peer's entries

- **WHEN** an admin unsubscribes from a peer
- **THEN** all entries with that peer's `_source.instance` are removed from the local catalog
- **AND** locally-created entries are unaffected

### Requirement: Federation respects the SSRF guard with a config-gated local allowlist

All outbound federation requests (directory, broadcast, peer pulls) SHALL pass
the SSRF guard used by OpenCatalogi federation: private, loopback, and
non-resolvable hosts are refused. For local development and test federation,
the `softwarecatalog/local_federation_hosts` app-config key (comma-separated
hostnames, **empty by default**) SHALL allowlist specific private hosts past
the guard — mirroring the proven `opencatalogi/local_federation_hosts`
pattern. The allowlist SHALL be settable only via server config/occ, not from
the web UI.

#### Scenario: Private peer host is refused by default

@e2e exclude SSRF-guard negative path; covered by PHPUnit tests on the guard and the OC-federation-testbed negative test recipe.

- **WHEN** a sync or subscription targets a private/loopback host and `local_federation_hosts` is empty
- **THEN** the request is refused before any connection is attempted
- **AND** the refusal is logged with the blocked host

#### Scenario: Allowlisted local host is permitted for test federation

@e2e exclude Requires a two-instance local federation topology; proven manually via the OC federation testbed procedure.

- **WHEN** `softwarecatalog/local_federation_hosts` contains the target host
- **THEN** federation requests to that host proceed despite it being private/loopback

