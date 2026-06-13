# Design — Retrofit Organisatie Service

Retrofit change. Tasks describe retroactive annotation, not new implementation work.

## Context

`OrganisatieService` is the SC↔OR integration layer for organisation records. It is one of three organisation-touching surfaces in the codebase — distinct from `OrganizationSyncService` (sync pipeline, in Bucket 1) and `SoftwareCatalogue\OrganizationHandler` (group/role helpers, in Bucket 1). The split predates the coverage scan and has not been consolidated.

## Decisions

- **Cluster mode** — no existing capability covers this surface; the other two organisation services are already in Bucket 1 under `method-decomposition#REQ-DECOMP-007 / REQ-DECOMP-011`.
- **5 REQs / 8 methods** — within the 5-REQ cap. Private helpers (`createOrganisationEntityInternal`, `getActiveOrganisationUuid`, `mapOrganizationDataForOpenRegister`) are folded into the public-method REQs. `mapStatus` is its own block inside REQ-002 because it has independent contract value (status enum mapping table).
- **Status enum mapping spelled out as a table-style REQ.** Defaulting unknown statuses to `true` is a deliberate (if questionable) choice — captured verbatim, not "fixed".
- **HOTFIX flagged in Notes.** The parent-organisation feature is disabled by comments. The REQ describes the *current* behaviour (no parent). Restoring requires RBAC work — not for this retrofit.

## Out of scope

- Consolidating the three organisation-touching services into one.
- Restoring parent-organisation assignment (requires OR RBAC redesign).
- Removing the duplicate exception logging in `addUsersToOrganization`.

## References

- Umbrella: ConductionNL/softwarecatalog#285
- Coverage report: openspec/coverage-report.md (2026-05-24)
- Source: lib/Service/OrganisatieService.php
