# Context Brief: gemma-faceted-search

## What
Faceted search & filtering across the catalog's application/voorziening listing pages on GEMMA architecture dimensions: **referentiecomponent, standaard (open standards), applicatieservice, domein**. Facet value counts, combinable with existing text search, deep-linkable filter URLs, and integration with the existing saved-views API.

## Why (evidence)
- VNG Softwarecatalogus issues #146 and #70 (top open user wish: "zoeken/filteren op GEMMA architectuur"), plus 20 `Zoeken`-labelled issues total.
- 281 usability requirements in the 301 mapped tenders (Specter DB, tender_app_relevance app_slug='softwarecatalog').
- Competitor gap: GEMMA Softwarecatalogus (incumbent) offers only basic search; no OSS competitor offers GEMMA-dimension facets.
- Specter canonical feature: `gemma-faceted-search` (must, demand 24).

## Current state (read these specs first)
- `openspec/specs/view-enrichment-api` — GEMMA views + module overlays; referentiecomponent relationships already resolvable server-side.
- `openspec/specs/dashboard-views-api` — saved views + per-user preferences (facet selections should be saveable as views).
- `openspec/changes/bound-unbounded-searchobjects-scans` (pending change on development) — ALL OR searchObjects calls must stay bounded; facet aggregation must not introduce unbounded scans.
- Frontend: manifest v1 pages (`src/manifest.json`), Pinia stores querying OR API directly.

## Scope
IN: facet aggregation endpoint (Controller → Service, bounded OR queries, cached), facet sidebar UI on the main catalog index page(s), URL-encoded filter state, counts per facet value, i18n NL+EN, tests, docs.
OUT: free-text relevance ranking changes, new GEMMA data imports, cross-app search.

## Design constraints
- ADR-001: no custom tables — facets aggregate over OpenRegister objects (registers: voorzieningen + vng-gemma).
- ADR-008 Controller → Service; ADR-012 use @conduction/nextcloud-vue Cn components (CnIndexPage sidebar filter patterns); ADR-003 NL Design tokens; ADR-005 i18n (translation keys in ENGLISH).
- Facet counts must respect the caller's RBAC/tenant context (no count-leak of objects the user cannot see).
- OpenSpec delta format: spec delta headers MUST be `### Requirement: <name>` (nothing else parses).
- Manifest schema refs use SLUGS, not PascalCase.
