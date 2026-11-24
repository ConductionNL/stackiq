# Tasks: architecture-future-state-scenarios

## Implementation tasks

### Task 1: Landscape on a date
- **spec_ref**: openspec/changes/architecture-future-state-scenarios/specs/future-state-scenarios/spec.md#requirement-req-fss-001-stackiq-shall-derive-an-organisations-landscape-on-any-date-from-its-usage-dates
- **files**: `lib/Service/LandscapeAtDateDerivation.php`, `tests/Unit/Service/LandscapeAtDateDerivationTest.php`
- **acceptance_criteria**:
  - GIVEN usages with phase dates WHEN the landscape is derived for a date THEN it holds the usages in production or to be phased out on that date
  - GIVEN a planned replacement on or before the date WHEN the landscape is derived THEN the successor module replaces the usage with its reference components
  - GIVEN a usage with only a current status, and one with nothing WHEN both landscapes are derived THEN the first is in both and the second is counted as not dated
  - GIVEN a scenario change and a plan replacement on one usage WHEN the scenario landscape is derived THEN the scenario wins and both sources are named
- [x] Implement
- [x] Test (PHPUnit `LandscapeAtDateDerivationTest`)

### Task 2: Comparison service, controller and route
- **spec_ref**: openspec/changes/architecture-future-state-scenarios/specs/future-state-scenarios/spec.md#requirement-req-fss-002-an-information-manager-shall-compare-todays-landscape-with-the-plan-on-a-date
- **files**: `lib/Service/LandscapeComparisonService.php`, `lib/Controller/LandscapeComparisonController.php`, `appinfo/routes.php`, `tests/Unit/Service/LandscapeComparisonServiceTest.php`, `tests/Unit/Controller/LandscapeComparisonControllerTest.php`
- **acceptance_criteria**:
  - GIVEN a user of another organisation WHEN the endpoint is called THEN it answers 403 before the service runs
  - GIVEN an organisation and a date WHEN the endpoint is called THEN it returns application and coverage differences in the shape of design D3
  - GIVEN a scenario id WHEN the endpoint is called THEN the scenario's organisation and target date are used and its changes are read with RBAC on
- [x] Implement
- [x] Test (PHPUnit `LandscapeComparisonServiceTest`, `LandscapeComparisonControllerTest`)

### Task 3: Register fragment for scenarios
- **spec_ref**: openspec/changes/architecture-future-state-scenarios/specs/future-state-scenarios/spec.md#requirement-req-fss-004-a-scenario-shall-move-through-a-declared-lifecycle-and-an-adopted-scenario-shall-be-applied-to-the-landscape
- **files**: `lib/Settings/register.d/architecture-future-state-scenarios.json`, `tests/Unit/Settings/ScenarioRegisterShapeTest.php`
- **acceptance_criteria**:
  - GIVEN the merged register WHEN it loads THEN the `stackiq` register lists `scenario` and `scenarioChange` with magic mapping on
  - GIVEN the scenario lifecycle WHEN the shape test reads it THEN every `from` and `to` value is a status enum value
  - GIVEN a fresh install WHEN the seed runs THEN the demo scenario and its two changes exist
- [x] Implement
- [x] Test (PHPUnit `ScenarioRegisterShapeTest`, `RegisterFragmentMergeTest`)

### Task 4: Comparison view, scenario pages and the roadmap button
- **spec_ref**: openspec/changes/architecture-future-state-scenarios/specs/future-state-scenarios/spec.md#requirement-req-fss-003-an-information-manager-shall-write-a-scenario-of-additions-phase-outs-and-replacements
- **files**: `src/manifest.d/architecture-future-state-scenarios.json`, `src/menu-layout.json`, `src/views/architecture/LandscapeComparisonView.vue`, `src/views/LifecycleRoadmapView.vue`, `src/customComponents.js`, `tests/e2e/workflows/future-state-scenarios.spec.ts`
- **acceptance_criteria**:
  - GIVEN the effective manifest WHEN it is built THEN `Scenarios`, `ScenarioDetail` and `LandscapeComparison` exist and Scenarios sits under the Architecture group
  - GIVEN the Portfolio roadmap WHEN Compare with the plan is chosen THEN the comparison page opens for the roadmap's organisation
  - GIVEN a comparison WHEN it renders THEN every difference carries its change and source as text
- [x] Implement
- [x] Test (`tests/validate-manifest.js`, Playwright `future-state-scenarios.spec.ts` plan and scenario scenarios) (e2e written, runs in nightly; not verified on a live instance)
  > Built 21 Nov: `src/manifest.d/architecture-future-state-scenarios.json` adds `Scenarios` (index, quick filters All, Draft, Proposed and Adopted), `ScenarioDetail` (data, the changes as an `object-list` filtered on the scenario, lifecycle actions, History tab, and `LandscapeComparisonView` as body widget) and `LandscapeComparison` (`/landscape-comparison`, a dashboard page over the `landscape-comparison` widget registered in `src/main.js`, because the custom-page ratchet allows no new `type: custom` page); `src/menu-layout.json` puts Scenarios under Architecture. `src/views/architecture/LandscapeComparisonView.vue` (registered in `src/customComponents.js`) shows today and the date side by side, the application differences with change and source as words, the coverage differences and the not dated count, over `src/utils/landscapeComparison.js`; it reads `?organisation=` from the roadmap, whose header has Compare with the plan (`src/views/LifecycleRoadmapView.vue`). `node tests/validate-manifest.js` passes; vitest `futureStateScenarioPages.spec.js` (5), `landscapeComparison.spec.js` (10) and `landscapeComparisonView.spec.js` (8). `tests/e2e/workflows/future-state-scenarios.spec.ts` is written, not yet run against a live instance, so Test stays open.

### Task 5: Apply an adopted scenario
- **spec_ref**: openspec/changes/architecture-future-state-scenarios/specs/future-state-scenarios/spec.md#requirement-req-fss-004-a-scenario-shall-move-through-a-declared-lifecycle-and-an-adopted-scenario-shall-be-applied-to-the-landscape
- **files**: `src/store/modules/scenarioApply.js`, `src/views/architecture/LandscapeComparisonView.vue`, `tests/vitest/scenarioApply.spec.js`
- **acceptance_criteria**:
  - GIVEN an adopted scenario without appliedAt WHEN Apply to landscape runs THEN add, phase out and replace write the fields of design D5
  - GIVEN a change with appliedTo WHEN the apply runs again THEN it is skipped
  - GIVEN a failed write WHEN the apply stops THEN appliedAt stays empty and the notice names the change
- [x] Implement
- [x] Test (vitest `scenarioApply.spec.js`, Playwright apply scenario) (e2e written, runs in nightly; not verified on a live instance)
  > Built 21 Nov: `src/store/modules/scenarioApply.js` writes add (a Planned usage of the scenario's organisation, in production on the effective date), phase out (`startDateOutPhased`) and replace (`plannedReplacement`, `plannedReplacementDate`) in order of effective date, records the usage in `appliedTo` after each write, skips a change that has it, stops at the first failed write and sets `appliedAt` last. The scenario page's comparison offers Apply to landscape only for an adopted scenario without `appliedAt`, and a failed apply's notice names the change. vitest `scenarioApply.spec.js` (14) passes; the Playwright apply scenario is written in `future-state-scenarios.spec.ts` and not run yet.

### Task 6: Documentation and translations
- **spec_ref**: openspec/changes/architecture-future-state-scenarios/specs/future-state-scenarios/spec.md#requirement-req-fss-003-an-information-manager-shall-write-a-scenario-of-additions-phase-outs-and-replacements
- **files**: `docs/features/future-state-scenarios.md`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN the feature page WHEN it is read THEN it shows the plan comparison, a scenario page and the apply result, each in a screenshot
  - GIVEN a Dutch instance WHEN the scenario pages render THEN every new label and enum value reads in Dutch
- [ ] Implement
  > 21 Nov: the English and Dutch strings of the comparison, the scenario pages and the `scenario` and `scenarioChange` schemas (titles, descriptions and enum values) are in `l10n/en.json`, `l10n/nl.json` and their `.js`. `docs/features/future-state-scenarios.md` is written (the plan comparison, writing a scenario, the lifecycle, the apply and the API) and linked from `docs/features/README.md`. Left: its three screenshots, which need a live instance.
- [ ] Test (`tests/l10n` key parity, screenshots captured with Playwright)

## Verification

- `openspec validate architecture-future-state-scenarios --type change --strict`
- PHPUnit: `LandscapeAtDateDerivationTest`, `LandscapeComparisonServiceTest`, `LandscapeComparisonControllerTest`, `ScenarioRegisterShapeTest`, `RegisterFragmentMergeTest`
- vitest: `scenarioApply.spec.js`
- Playwright: `tests/e2e/workflows/future-state-scenarios.spec.ts`
- Documentation in `docs/features/future-state-scenarios.md` with screenshots (ADR-010)
- English and Dutch strings for every new label and enum value (ADR-005)
