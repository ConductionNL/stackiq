# Design: architecture-future-state-scenarios

Read at development 49e65cb4. Line numbers below are from that sha. `ReferenceComponentCoverageDerivation`, `ReferenceComponentCoverageService` and `OrganisationReportAccess` come from `architecture-reference-component-coverage` (its D1 and D2); the Architecture menu group from `architecture-views-editor` (its D8); `GebruikDetail` and the usage lifecycle on English values from `landscape-usage-registration`.

## Where it fits

| Layer | Touched | Read at |
|---|---|---|
| Register | `stackiq` register (`lib/Settings/softwarecatalogus_register.json:817`), new schemas `scenario` and `scenarioChange`; `usage` (:2654) read and, on apply, written | through a new fragment `lib/Settings/register.d/architecture-future-state-scenarios.json` |
| Derivation | new `lib/Service/LandscapeAtDateDerivation.php` | pure, uses `PortfolioReportDerivation::deriveLifecyclePhase` (`lib/Service/PortfolioReportDerivation.php:58`) |
| Service | new `lib/Service/LandscapeComparisonService.php` | reads usages through `ReferenceComponentCoverageService` and scores both landscapes with `ReferenceComponentCoverageDerivation` |
| Controller and route | new `lib/Controller/LandscapeComparisonController.php`, route `landscapeComparison#index` at `GET /api/landscape-comparison`, next to `portfolioReport#index` (`appinfo/routes.php:303`) | |
| Pages | new `src/manifest.d/architecture-future-state-scenarios.json` with `Scenarios` (index), `ScenarioDetail` (detail) and `LandscapeComparison` (custom) | |
| Views | new `src/views/architecture/LandscapeComparisonView.vue`; `src/views/LifecycleRoadmapView.vue` gains a Compare with the plan button in its header (:4 to :24) | registered in `src/customComponents.js` |
| Store | new `src/store/modules/scenarioApply.js` | writes through OpenRegister's objects API |
| Menu | `src/menu-layout.json` relocates `Scenarios` under the `Architecture` group | |

The fragment merges through `SettingsService::loadSettings` (`lib/Service/SettingsService.php:1653-1680`, `deepMergeConfig` at :7338) with `components.schemas`, `components.registers.stackiq.schemas` and `components.registers.stackiq.configuration.schemas` entries for both new schemas.

## Decisions

### D1. The plan is read from the dates, at any date

The landscape on date D holds every usage whose phase on D, by `deriveLifecyclePhase($usage, D)` (`PortfolioReportDerivation.php:58`), is In production or To be phased out. A usage whose `plannedReplacementDate` is on or before D leaves the landscape on that date and its `plannedReplacement` module enters it as a planned successor, carrying the usage's `usedForReferenceComponents`. A usage with no phase date but a `status` of In production or To be phased out (the schema default is In production, register.json usage `status`) is in today's landscape with that phase and stays in every later landscape until a date or a scenario change says otherwise, because most usages carry a status and no dates. A usage with neither a phase date nor one of those status values reads Onbekend and is listed apart as not dated.

Today's landscape is the same function with D set to today. So "today" and "the plan on D" come from one rule. The Portfolio roadmap uses the same date rule in the browser (`src/utils/lifecyclePhase.js:101`); the one difference is the status fallback, and a usage it adds is one the roadmap shows in its Onbekend lane.

Rejected: a stored snapshot of the landscape per date. It would be a second copy of the usages that drifts when a date is edited, which the archived lifecycle change ruled out for the phase (its design Decision 1).

### D2. A scenario is a change set on top of the plan

`scenario`:

| Field | Type | Notes |
|---|---|---|
| name | string, required | |
| description | string | |
| organisation | related `organization`, required | the landscape it changes; the comparison checks access on it |
| targetDate | date, required | |
| status | enum draft, proposed, adopted, rejected, default draft, facetable | lifecycle in the Declarative section |
| appliedAt | date-time | set when the scenario is applied |

`scenarioChange`:

| Field | Type | Notes |
|---|---|---|
| scenario | related `scenario`, required | |
| action | enum add, phase out, replace, required | |
| usage | related `usage` | required for phase out and replace |
| module | related `module` | required for add and replace |
| referenceComponents | list of related `element`, query `gemmaType=referentiecomponent` | for add and replace; the form fills it from `module.referenceComponents` |
| effectiveDate | date | defaults to the scenario's target date |
| note | string | |
| appliedTo | related `usage` | set on apply, so a second apply changes nothing |

The scenario landscape on its target date is the plan on that date with the changes applied in order of `effectiveDate`. Each difference in the comparison names its source: plan or scenario.

Rejected: future-state flags on usages, as BlueDolphin does on objects. A flag holds one future; two options for the same decision (replace A by B, or by C) need two change sets side by side.

Rejected: a copy of every usage per scenario. Copies go stale the moment today's landscape changes, while a change set stays small and is always read against the current data.

### D3. One comparison endpoint

`GET /api/landscape-comparison?organisation=<uuid>&date=<date>&scenario=<uuid>` (scenario optional; with a scenario, its organisation and target date are used). The controller runs `OrganisationReportAccess::isAuthorised` before any read and fails closed, as the two report controllers do. `LandscapeComparisonService` reads the organisation's usages once with the bounded query of `ReferenceComponentCoverageService`, and reads the scenario's changes with RBAC on. `LandscapeAtDateDerivation` builds both landscapes, and `ReferenceComponentCoverageDerivation` scores each for coverage. The response:

```json
{
  "organisation": "00000000-0000-0000-0000-000000000000",
  "today": "2026-09-27",
  "date": "2027-06-30",
  "scenario": null,
  "applications": [
    { "moduleName": "Zaaksysteem A", "change": "removed", "source": "plan", "date": "2027-03-01" }
  ],
  "coverage": [
    { "component": "Zaakregistratiecomponent", "today": "overlap", "future": "covered" }
  ],
  "notDated": 2,
  "truncated": false
}
```

`change` is one of added, removed, replaced (with `replacedBy`) and unchanged; `coverage` lists only components whose state differs.

Rejected: computing the comparison in the browser with `lifecyclePhase.js`. The coverage half needs the reference components and the organisation-scoped usage read that `architecture-reference-component-coverage` put behind one bounded endpoint; a second path in the browser would read them differently.

### D4. The pages

`Scenarios` (`/scenarios`) is a `CnIndexPage` over `scenario` with columns name, organisation, targetDate and status, and quick filters All, Draft, Proposed and Adopted. `ScenarioDetail` (`/scenarios/:id`) is a `type: detail` page with a `data` widget, an `object-list` widget over `scenarioChange` with filter `{"scenario": "@objectId"}` and columns action, usage, module and effectiveDate, the body widget `LandscapeComparisonView` bound to the scenario, `lifecycleActions` on, and the History tab.

`LandscapeComparison` (`/landscape-comparison`) is a custom page holding `LandscapeComparisonView` with an organisation picker and a date picker, for the plan alone. The Portfolio roadmap header gets a button Compare with the plan that opens it with the roadmap's organisation.

`LandscapeComparisonView.vue` shows two columns, Today and the chosen date, with a `CnDataTable` of application differences (a text tag Added, Removed or Replaced by, and the source), a table of coverage differences (gap closed, gap opened, overlap resolved, overlap created) and the not dated count. Colours are Nextcloud CSS variables and every state is also a word.

### D5. Applying an adopted scenario

On an adopted scenario that has no `appliedAt`, the scenario page offers Apply to landscape. `src/store/modules/scenarioApply.js` writes each change through OpenRegister's objects API as the signed-in user:

| Action | Write |
|---|---|
| add | a new `usage` with `consumer` the scenario's organisation, `module`, `usedForReferenceComponents`, `status` Planned and `startDateInProduction` the effective date |
| phase out | `startDateOutPhased` on the usage set to the effective date |
| replace | `plannedReplacement` and `plannedReplacementDate` on the usage |

It stores the written usage in `appliedTo` after each write and sets `appliedAt` last. A retry skips changes that have `appliedTo`. After the apply, the plan comparison and the Portfolio roadmap show the changes, because they read the same fields.

Rejected: a PHP service for the apply. It is three plain object writes per change with no rule the platform does not already enforce (ADR-022, config rule "Uses OpenRegister API directly from frontend").

## Declarative versus imperative

- The scenario status lifecycle is declared as `configuration.x-openregister-lifecycle` on `scenario`: initial draft, final adopted, transitions propose (draft to proposed), adopt (proposed to adopted), reject (proposed to rejected) and rework (proposed or rejected to draft). The `from` and `to` values are the enum values exactly (register changelog 2.4.4, register.json:7).
- The scenario to change and change to usage links are `related-object` properties; the change list is a manifest `object-list`. No PHP.
- The comparison is imperative, because it joins usages, planned dates, a change set and reference components across two registers, which no `x-openregister-aggregation` expresses. It is a pure derivation behind one bounded endpoint.
- The apply is imperative and runs in the browser store.

## Seed data

All objects live in the `stackiq` register. They use the three usages the register already seeds (register.json `components.objects`).

### Schema: `scenario`

| Field | Object 1 |
|---|---|
| slug | `seed-scenario-delft-2027` |
| name | Servicedesk en schuldhulp in 2027 |
| organisation | `gemeente-delft` |
| targetDate | 2027-06-30 |
| status | proposed |

### Schema: `scenarioChange`

| Field | Object 1 | Object 2 |
|---|---|---|
| slug | `seed-scenario-change-uitfaseren` | `seed-scenario-change-toevoegen` |
| scenario | `seed-scenario-delft-2027` | `seed-scenario-delft-2027` |
| action | phase out | add |
| usage | `gebruik-suite4-gem-delft-eigenaar` | |
| module | | `topdesk-itsm` |
| effectiveDate | 2027-06-30 | 2027-03-01 |
| note | Schuldhulp gaat naar de regio. | Eigen servicedesk in plaats van de gedeelde. |

The module slug `topdesk-itsm` is the module the seeded TOPdesk usage of Servicecenter Rijnland points at, so the demo needs no new module. The seeded usages hold `status` `in-gebruik`, which is not an enum value, and no phase dates, so the Suite4 usage reads not dated until its status is set; the demo scenario shows that case on purpose. The Playwright spec builds its own usages with dates through OpenRegister's objects API instead of relying on the seed.

## Risks

- **Undated usages.** A usage with no phase date and no current status value is in neither landscape. The count of not dated usages sits next to the comparison so a reader sees why an application is missing.
- **Two sources for one date.** A plan replacement and a scenario change can touch the same usage. The scenario change wins, and the difference says both sources.
- **Partial apply.** A failed write stops the apply with a notice naming the change; `appliedTo` makes the retry safe, and `appliedAt` is set only when every change is written.
- **Schema versions.** Both schemas are new, so no version bump is needed on existing schemas.
