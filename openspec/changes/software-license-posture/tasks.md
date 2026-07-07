# Tasks — software-license-posture

## 1. Aggregation (OpenRegister-first)

- [ ] 1.1 Express the rollups as OR declarative aggregation / list-facet queries
  where possible: count of in-production `gebruik` per `module`; group by
  `licentietype`, `licentie`, and `aanbieder`. Reuse the lifecycle
  "in production" predicate.
- [ ] 1.2 For any rollup not expressible in OR aggregation, add a thin read-time
  query util (no stored/materialised posture). Vitest tests incl. the
  Unknown-licentietype and phased-out-exclusion cases.

## 2. Posture surface

- [ ] 2.1 License posture dashboard (manifest / CnDataTable): open-source vs
  closed-source share of the in-production portfolio + licence-type mix.
- [ ] 2.2 Deployment-count column per licensed application.
- [ ] 2.3 Per-vendor rollup view (deployments + licence mix), cost column
  consuming contract-administration `totalAnnualisedCost` (degrade to empty if
  absent).
- [ ] 2.4 Per-organisation open-source-first report (share + closed-source
  contributors list).

## 3. Boundaries

- [ ] 3.1 Confirm NO re-implementation of contract cost annualisation — import
  contract-administration's `totalAnnualisedCost`.
- [ ] 3.2 Confirm NO new schema field and NO stored posture.

## 4. i18n + docs

- [ ] 4.1 NL + EN strings (English i18n keys) across en/nl + required locales.
- [ ] 4.2 `docs/features/` page with Playwright screenshots: portfolio posture,
  per-vendor rollup, per-organisation open-source-first report.

## 5. Traceability

- [ ] 5.1 `@spec openspec/specs/software-license-posture/...` on new utils;
  Playwright e2e per ADDED scenario (or `@e2e exclude <reason>`).
