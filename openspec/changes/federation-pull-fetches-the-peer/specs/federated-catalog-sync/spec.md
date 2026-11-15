# federated-catalog-sync Specification (delta)

## ADDED Requirements

### Requirement: A pull reads the peer's own published catalogue (REQ-FED-010)

Pulling a peer SHALL fetch that peer's public listing of published entries over HTTP at the peer's address, after the SSRF guard allows the host, following pages up to a configured cap. Only those entries SHALL be merged. A fetch that fails SHALL count as a failure for that peer and SHALL NOT change its mirrors.

#### Scenario: Pulling a reachable peer
- GIVEN the peer catalogus.drechtsteden.nl publishes 1,204 entries
- WHEN an administrator chooses Pull now
- THEN 1,204 mirrors exist for that peer, and none of them is a local entry

#### Scenario: An unreachable peer
- GIVEN the peer apps.wshd.nl does not answer
- WHEN the scheduled pull runs
- THEN its mirrors are unchanged, its failure count rises, and the peers table shows "Error: not reachable"

#### Scenario: A blocked host
- GIVEN a peer address that resolves to a private network
- WHEN a pull runs
- THEN no request is made and the peer shows as blocked

### Requirement: Mirrored entries show where they came from (REQ-FED-011)

The peers table SHALL show per peer its name, address, last successful pull, number of mirrored objects and last error. A mirrored entry SHALL show "From: <peer catalogue name>" with a link to the entry at the peer on its detail page and a source badge in lists, and SHALL offer no edit or delete action.

#### Scenario: A user opens a mirrored application
- GIVEN an application mirrored from Softwarecatalogus Drechtsteden
- WHEN a user opens it
- THEN the page says "From: Softwarecatalogus Drechtsteden" with a link to the original, and the edit action is not offered
