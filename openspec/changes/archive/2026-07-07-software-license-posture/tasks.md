# Tasks — software-license-posture

## 1. Aggregation (OpenRegister-first)

- [~] 1.1 Express the rollups as OR declarative aggregation / list-facet queries
  where possible. DEVIATION: the rollups needed here (in-production `gebruik`
  count per `module`; group by `licentietype`/`licentie`/`aanbieder`; join to
  contract cost) require the shared lifecycle in-production predicate AND
  consumption of contract-administration's `totalAnnualisedCost`, which OR
  declarative `@aggregate` cannot yet express as a single query. Implemented as
  the thin read-time query util below (per design Decision 3's stated fallback) —
  no stored/materialised posture.
- [x] 1.2 `src/utils/licensePosture.js` read-time query util: `deploymentCount`,
  `portfolioPosture`, `perVendorRollup`, `perOrganisationPosture`; reuses the
  lifecycle in-production predicate (`isInProduction` from vulnerabilityExposure,
  which itself matches application-lifecycle-tracking). Vitest tests incl. the
  Unknown-licentietype and phased-out-exclusion cases (8 tests).

## 2. Posture surface

- [x] 2.1 License posture dashboard (custom manifest page `LicensePostureView`):
  open-source vs closed-source share of the in-production portfolio + KPI block.
- [x] 2.2 Deployment-count weighting per application (each in-production usage is
  one weight unit; surfaced in the per-vendor deployment column).
- [x] 2.3 Per-vendor rollup (deployments + licence mix), cost column consuming
  contract-administration `totalAnnualisedCost` (degrades to "—" when absent).
- [x] 2.4 Per-organisation open-source-first report (share + closed-source
  contributors list) with an organisation selector.

## 3. Boundaries

- [x] 3.1 NO re-implementation of contract cost annualisation — imports
  `totalAnnualisedCost` from `src/utils/contractCost.js` (contract-administration).
  Unit test asserts the per-vendor cost equals the consumed annualised total.
- [x] 3.2 NO new schema field, NO stored posture — everything derived at query
  time.

## 4. i18n + docs

- [x] 4.1 NL + EN strings (English i18n keys) added to l10n/en.js, en.json,
  nl.js, nl.json (18 new keys).
- [~] 4.2 `docs/features/` Playwright screenshots DEFERRED: the change is not
  deployed to the shared dev instance (served app is the main checkout;
  deploying the worktree there is disallowed), so live screenshots can't be
  captured. Feature documented in the change; capture follows deployment.

## 5. Traceability

- [x] 5.1 `@spec openspec/specs/software-license-posture/spec.md` on all new
  utils/methods. Playwright e2e
  (`tests/e2e/spec-coverage/license-posture.spec.ts`) references all 6 ADDED
  scenarios via `@e2e`. LIVE RUN DEFERRED (worktree not deployed to the shared
  instance; policy forbids deploying there) — specs are authored +
  gate-19-traceable and run green post-deploy.
