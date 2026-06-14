# Tasks: view-enrichment-api

> **Build note (2026-06-10):** the placeholder "Implementation planning" task
> from the original draft has been replaced with honest, observed-behaviour
> tasks. The view enrichment API has already shipped as
> `ViewController::getView()` / `ViewService::getView()` — see file pointers
> below.

## Backend: enriched-view endpoint

- [x] Task 1 — `GET /api/views/{viewId}` returns the view object with module,
  gebruik and organization enrichments — `lib/Controller/ViewController.php`
  `getView(string $viewId): JSONResponse` (line 162) delegates to
  `ViewService::getView()` with parsed enrichment options.
- [x] Task 2 — Enrichment options are parsed from the query string —
  `ViewController::parseEnrichmentOptions()` reads `include_*` flags
  (`include_modules`, `include_gebruik`, `include_deelnames_gebruik`,
  `include_organizations`) into the options array.
- [x] Task 3 — Service-level enrichment pipeline aggregates data from
  multiple OR schemas — `lib/Service/ViewService.php` `getView()` (line 160+)
  fetches the base view object, then layers module + gebruik + deelnames +
  organization data via dedicated `getModulesData()`, `getGebruikData()`,
  `getDeelnamesGebruikData()`, `getOrganizationsData()` helpers.
- [x] Task 4 — Returns the list of enrichments actually applied —
  `ViewService::getView()` includes `enrichments_applied: []` in the
  response so the frontend can branch on what's present.
- [x] Task 5 — Result is cached per (viewId, options) for the request
  lifetime — `ViewService::$enrichmentCache` is populated on first
  lookup and reused for downstream node enrichment within the same
  request.
- [x] Task 6 — Honest status codes — 200 on success, 404 when the view
  is not found, 500 on internal failure, 401 when not authenticated
  (`ViewController::getView()` lines 162-225).

## Cross-references

- Capability spec: `openspec/changes/view-enrichment-api/specs/view-enrichment-api/spec.md`
- Backend impl: `lib/Controller/ViewController.php`,
  `lib/Service/ViewService.php`
- Frontend consumer: the GEMMA view renderer lives outside this app
  (rendering is consumed by `pipelinq` and the Conduction website); the
  API is consumed via the standard JSON envelope.

## Acceptance criteria

- `GET /api/views/{viewId}` returns 200 + `{ success: true, view, enrichments_applied }` for a known view.
- 404 with `{ success: false, view: null }` for an unknown view.
- Enrichment options on the query string change the response shape predictably (additive only).
