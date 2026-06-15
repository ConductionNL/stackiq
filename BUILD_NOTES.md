# Build: federation pull/merge + intake hardening (WIP)

Branch base: origin/development @ 1fb24bb (PR #45 — RBAC anon-publish merged).

Building the two deferred legs:
1. federated-catalog-sync 3.3/4.2 — live pull/merge + staleness (unit-tested, mocked peer).
2. open-data-publishing 4.x/5.x — anon intake hardening + admin approval queue.
