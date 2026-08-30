# Design: software-license-posture

## Decision 1 — A distinct capability, bounded against contract-administration

`contract-administration` already ships "Annualised cost is derived and totalled"
(per contract set) and owns per-contract expiry. This change does **not** touch
contract cost derivation. Its unit of analysis is the **application portfolio**,
not the contract:

| Concern | Owner |
|---|---|
| Per-contract cost, annualisation, expiry, status | contract-administration |
| Portfolio licence-type mix (OSS vs closed), deployment counts, per-vendor / per-org posture | this change |

Where this surface shows cost, it **consumes** `totalAnnualisedCost` from
contract-administration; it never re-implements the Maandelijks×12 / Jaarlijks×1
maths. If contract-administration is absent, cost columns degrade to empty and
the posture (licence mix, deployments) still works.

## Decision 2 — Posture is weighted by in-production deployment, not catalogue rows

The policy-relevant number is "what we **run**", not "what exists in the
catalogue". So every aggregate is weighted by in-production `gebruik`, reusing
the exact `application-lifecycle-tracking` predicate (`startDatumInProductie`
set, `startDatumUitGefaseerd` empty). A closed-source product registered but
never deployed does not inflate the closed-source share. This keeps the two
capabilities' notion of "in use" identical.

## Decision 3 — Aggregate in OpenRegister where expressible, not in a new service

Per ADR-022 and the MDM/declarative-calc direction, the rollups (count of
in-production usages per module; group by `licentietype` / `licentie` /
`aanbieder`) should be OR declarative aggregation (`@aggregate`) or OR
list/facet queries, surfaced by the manifest renderer — not a bespoke
`LicensePostureService`. Only the presentation (a dashboard widget / CnDataTable)
is app-side. If a specific rollup is not expressible in OR aggregation at
implement time, the fallback is a thin read-time query util (like the lifecycle
roadmap), never a stored/materialised posture table.

## Decision 4 — `licentietype` is the policy axis; `licentie` is the detail

`licentietype` (`Open source` / `Closed source`) is the binary policy axis for
the open-source-first signal. `licentie` (MIT/GPL/Apache/BSD/EUPL-1.2) is the
drill-down. Modules with an empty `licentietype` are reported as "Unknown" — and
counted, because an unclassified running application is itself a posture gap
worth surfacing (it drives data-quality remediation, complementing the MDM
quality score).

## Decision 5 — Read-only, no new schema

No schema field is added. Posture, deployment counts, and shares are all derived
at query time. Nothing is persisted, so nothing can drift.
