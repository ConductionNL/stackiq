# Design: application-lifecycle-tracking

## Decision 1 — Derive phase from existing dates; never store a parallel state

`gebruik` already carries five phase-start dates and a `status` string. The
lifecycle phase is a **pure function** of those fields (latest phase whose
start date is in the past wins; explicit `status` overrides when set):

```
startDatumUitGefaseerd ≤ today   → Uitgefaseerd
startDatumUitTeFaseren ≤ today   → Uit te faseren
startDatumInProductie  ≤ today   → In productie
startDatumGepland      ≤ today   → Gepland
startDatumVerwerving   ≤ today   → Verwerving
none set                         → Onbekend (unknown — shown, not hidden)
```

Storing a derived `lifecyclePhase` field would create a second source of
truth that drifts the moment a date is edited. The function lives once
(shared FE util + the same expression as an OR query filter), not per view.

## Decision 2 — EOL is a property of the version, surfaced on the usage

`datumEindeOndersteuning`/`datumTeruggetrokken` stay on `moduleVersie`
(supplier facts). What a municipality needs is the join: "this gebruik runs a
version past (or approaching) end-of-support". The EOL badge and the
approaching-EOL filter therefore evaluate the gebruik's linked `moduleVersie`
dates — no EOL fields are copied onto `gebruik`. Window from app-config
`softwarecatalog/eol_warning_window_days` (default 180; municipalities need
budget lead time, hence wider than the contract window).

## Decision 3 — Planned replacement is a relation on gebruik

New optional fields on the `gebruik` schema: `geplandeVervanging`
(related-object reference to the successor `module`) and
`geplandeVervangingsDatum` (date). On `gebruik` because replacement is an
organisation's portfolio decision about *its* usage, not a fact about the
module (two municipalities replace the same module with different
successors). This is the only schema addition in the change; both fields
optional → no migration impact on existing objects.

## Decision 4 — Roadmap is a query view, not a subsystem

The portfolio roadmap page is a manifest page over existing OR queries:
gebruiken of the selected organisation, grouped by derived phase, ordered by
the nearest urgency date (EOL date, phase-out date, or replacement date),
with successor links rendered when `geplandeVervanging` is set. Unknown-phase
entries render in their own group at the top — an undated portfolio entry is
a data-quality finding the rationalisation exercise must see. No new backend
endpoints; the view-enrichment / dashboard API patterns already in the app
cover any aggregation needs.

## Decision 5 — Lifecycle notifications via the OR engine, shipped disabled

Two `scheduled` rules in `x-openregister-notifications` (ADR-031 dialect,
same conventions as `softwarecatalog-notifications`):

- `moduleVersie.eol-approaching` — `datumEindeOndersteuning` within the EOL
  window; recipients: `softwarecatalog-admins` group + object-ACL manage.
- `gebruik.phaseout-approaching` — `startDatumUitTeFaseren` within the
  window; same recipient shape.

Both ship `enabled: false` pending confirmation of date-window filtering in
the engine — the exact caveat `contract-expiry` carries. If unsupported, the
OR gap issue is filed once and referenced by both changes. No app-local
notification cron, ever.

## Out of scope

- Technology/standard obsolescence risk scoring (LeanIX-style "technology
  risk" indices) — needs market data the catalog does not have.
- Automated version-upgrade detection (supplier publishes new version →
  suggest upgrade) — `softwarecatalog-notifications` already notifies on new
  `moduleVersie`; acting on it stays manual.
- Budget/cost projection of replacements — cost stays with
  `contract-administration`; the roadmap links, never computes.
