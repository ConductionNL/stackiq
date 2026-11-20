# 1. Federate catalogs through OpenCatalogi

## Status

Accepted

## Context

Stackiq instances share catalog data with each other over a federated network. That needs a wire format, a directory of peers, peer discovery and protection against server-side request forgery. OpenCatalogi already provides a directory, broadcast and pull path with an SSRF guard and an allowlist, and it was tested on a federation testbed.

## Decision

Stackiq does not implement its own federation protocol. An instance's catalog is exposed as an OpenCatalogi catalog, and federation uses OpenCatalogi's broadcast, directory listing and anonymous published-only catalog endpoint. Stackiq's `FederationService` is a thin orchestrator over those OpenCatalogi services plus the app-specific merge.

## Consequences

- OpenCatalogi is an optional runtime dependency: when it is not installed or its directory service is missing, federation is unavailable and every federation entry point degrades gracefully.
- There is no Stackiq-specific federation format to maintain or secure.
- The implementation lives in `lib/Service/Federation/FederationService.php`, run on a schedule by `lib/BackgroundJob/FederationSyncJob.php`.

Source: `openspec/changes/archive/2026-06-14-federated-catalog-sync/design.md` (Decision 1)
