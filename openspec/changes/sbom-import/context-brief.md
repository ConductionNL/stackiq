# Context Brief: sbom-import

## What
Import an **SBOM file (CycloneDX JSON; SPDX JSON if cheap)** for a module version: parse components into OpenRegister objects linked to the moduleversie, show the component list with licenses, match components against the existing kwetsbaarheden (vulnerability) register by CVE/package where possible, and show summary counts (components, distinct licenses, matched vulnerabilities).

## Why (evidence)
- The dependency-management research domain (Specter domain 160) has ZERO implementation in the app today.
- Reference architecture: OWASP Dependency-Track (20k+ orgs) — SBOM ingestion correlating against advisory data; CycloneDX is ECMA-424, SPDX is ISO 5962 (both logged in Specter external_sources).
- Sonatype 2026: 80% of dependencies stay un-upgraded >1yr — freshness visibility is the demand driver.
- NIS2/BIO supply-chain requirements make SBOM handling a near-term procurement checkbox (561 compliance reqs in mapped tenders).
- Specter canonical feature: `sbom-file` (should, demand 8, upgraded 2026-07-23).

## Current state (read these specs first)
- `openspec/specs/module-vulnerability-tracking` — kwetsbaarheden register, CVSS bands, per-org exposure. SBOM matching feeds THIS, do not fork a parallel vuln model.
- `openspec/specs/progress-tracking` — SSE progress for long-running work; large SBOM parse should report progress.
- `openspec/specs/archimate-import` — existing pattern for file upload → parse → object creation → status/cancel; mirror its ergonomics.
- Schemas: lib/Settings/softwarecatalogus_register.json via repair step.

## Scope
IN: upload endpoint (bounded file size, JSON only), CycloneDX 1.5/1.6 JSON parser service (SPDX JSON optional second format), sbomComponent schema (purl, name, version, licenses[], hashes optional, moduleversie relation), local vulnerability matching against existing kwetsbaarheden objects (by CVE id and/or purl/package name), components tab on the module-version detail page, summary counts, re-import replaces previous component set for that version (idempotent), i18n, tests with real small CycloneDX fixtures, docs.
OUT: calling external services (OSV.dev/NVD API queries — that belongs in openconnector later), SBOM generation/export, license-policy evaluation, transitive dependency graphs.

## Design constraints
- ADR-001 OR storage only; no custom tables. Mind OR bulk-save performance for SBOMs with hundreds of components (bounded batches).
- OR DELETE is soft-delete — "replace previous component set" must handle trash rows (filter `_deleted`).
- Parser is a pure service (ADR-008), unit-testable without OR.
- ADR-012 Cn components; ADR-005 i18n; ADR-009 tests ≥75%; ADR-010 docs.
- OpenSpec delta headers MUST be `### Requirement: <name>`.
