---
kind: feature
depends_on: []
---

# softwarecatalog — application lifecycle & end-of-life tracking

## Why

Lifecycle tracking is the core job of application-portfolio management — it
is what LeanIX/ServiceNow APM sell and what the VNG Softwarecatalogus
use-case (portfolio rationalisation, PvE checks, sanering) exists for. A
municipal IT manager's first questions are "which of my applications are
approaching end-of-support?", "what is scheduled for phase-out?", and "what
replaces it?". The 2026-06-11 feature re-evaluation lists this as
EXPECTED-GAP 1 (`application-lifecycle-tracking`).

The data model is already half there — and completely unspecced and
unsurfaced:

- `moduleVersie` carries `datumInOntwikkeling`, `datumInGebruik`,
  `datumEindeOndersteuning` (end-of-support), `datumTeruggetrokken`
  (withdrawn), and `status`;
- `gebruik` carries the five phase dates (`startDatumVerwerving`,
  `startDatumGepland`, `startDatumInProductie`, `startDatumUitTeFaseren`,
  `startDatumUitGefaseerd`) and `status`.

Nothing derives a lifecycle phase from those dates, nothing flags a gebruik
whose module version has passed end-of-support, no portfolio view shows the
timeline, and no notification fires when EOL approaches — the
`softwarecatalog-notifications` change covers vulnerabilities/contracts/
versions/reviews but not lifecycle events.

## What Changes

- **Derived lifecycle phase** for each application-in-use (gebruik): computed
  from the existing phase dates + status (single source of truth — no new
  stored state field). Phases: Verwerving → Gepland → In productie →
  Uit te faseren → Uitgefaseerd.
- **End-of-support surfacing**: a gebruik whose `moduleVersie` has passed
  `datumEindeOndersteuning` (or is `teruggetrokken`) gets an EOL badge in
  list and detail views; an "EOL approaching" filter (configurable window,
  default 180 days).
- **Portfolio lifecycle view**: a roadmap page per organisation listing
  applications grouped by lifecycle phase with upcoming EOL and phase-out
  dates, ordered by urgency — the rationalisation overview.
- **Planned replacement**: a new optional `geplandeVervanging` relation on
  `gebruik` (reference to the successor module + planned date), so phase-out
  decisions are recorded and the roadmap shows successors.
- **Lifecycle notifications** joining the `x-openregister-notifications`
  dialect: scheduled "end-of-support approaching" (on `moduleVersie`) and
  "phase-out date approaching" (on `gebruik`) rules.

## Capabilities

### New Capabilities

- `application-lifecycle-tracking`: derived lifecycle phases on existing
  gebruik/moduleVersie date fields, EOL badges + approaching-EOL filters, a
  per-organisation portfolio roadmap with planned replacements, and
  scheduled lifecycle notifications via the OR notification engine.

## Impact

- **Changed:** `src/manifest.json` — lifecycle phase column/badges on
  application listings, EOL filter, new roadmap page, replacement field on
  gebruik detail.
- **Changed:** `lib/Settings/softwarecatalogus_register.json` — add the
  optional `geplandeVervanging` relation (+ `geplandeVervangingsDatum`) to
  the `gebruik` schema; add `x-openregister-notifications` rules to
  `moduleVersie` (EOL approaching) and `gebruik` (phase-out approaching).
- **No PHP lifecycle engine:** phase derivation is a pure function of
  existing fields, computed at render/query time; notifications dispatch via
  the OR engine (ADR-031).
- **Relation to `softwarecatalog-notifications`:** same dialect, additive
  rules; both changes touch the register file (rebase-order note, no
  semantic conflict).
- **Relation to `contract-administration`:** the roadmap may show contract
  end dates alongside phase-out dates when both land; soft, render-only.

## Caveats

- **`scheduled` date-window filtering** must be confirmed in the OR
  notification engine (same caveat as `contract-expiry`); the lifecycle rules
  ship disabled until confirmed.
- The derived phase is only as good as the entered dates — the roadmap SHALL
  visibly mark applications with no lifecycle dates at all ("unknown") rather
  than hiding them; an unknown-phase portfolio entry is itself a
  rationalisation finding.
- GEMMA alignment: phase names follow the existing Dutch field semantics
  (verwerving/gepland/in productie/uit te faseren/uitgefaseerd) so exports
  and federation stay consistent with the VNG model; i18n keys are English.
