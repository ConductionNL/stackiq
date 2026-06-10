# Tasks: deelnames-gebruik

> **Build note (2026-06-10):** the placeholder "Implementation planning"
> task from the original draft has been replaced with observed-behaviour
> tasks. The deelnames data layer has already shipped inside
> `ViewService` — see file pointers below.

## Backend: deelnames data layer

- [x] Task 1 — Opt-in deelnames enrichment via `include_deelnames_gebruik=1` —
  `lib/Service/ViewService.php` `isDeelnamesGebruikIncluded()` (line 525+)
  guards the additional fetch; absent or false-y option keeps the legacy
  shape.
- [x] Task 2 — Deelnames items fetched with RBAC disabled so participation
  data is complete across organisation scopes — `ViewService::getDeelnamesGebruikData()`
  (line 1150+) calls `ObjectService::searchObjects(..., _rbac: false)`
  per the deelnames query.
- [x] Task 3 — Deelnames items are merged into the gebruik pool with a
  `type: 'deelnames'` discriminator so the renderer can colour-code them
  — `ViewService::tagGebruikItems()` (line 880+) stamps the `type` field.
- [x] Task 4 — Per-node deelnames aggregation — `ViewService::getNodeDeelnamesGebruik()`
  (line 477+) returns the deelnames slice per architecture node so the
  module overlay renderer can position them.
- [x] Task 5 — Enrichment ledger names `deelnames_gebruik` when applied —
  `ViewService::getView()` appends `'deelnames_gebruik'` to `enrichments_applied`
  so consumers know to render the overlay.

## Cross-references

- Capability spec: `openspec/changes/deelnames-gebruik/specs/deelnames-gebruik/spec.md`
- Backend impl: `lib/Service/ViewService.php` (deelnames methods)
- Consumed by: `view-enrichment-api` GET response when `include_deelnames_gebruik=1`

## Acceptance criteria

- A view request with `?include_deelnames_gebruik=1` returns enrichment markers + per-node deelnames slices.
- Without the flag the response is byte-identical to the pre-deelnames shape (no regression).
