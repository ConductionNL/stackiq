---
kind: code
depends_on:
  - architecture-views-editor
  - architecture-reference-component-coverage
  - landscape-usage-registration
---

# Compare a future landscape with today's

## Summary

A municipal information manager picks a date and sees the organisation's landscape on that date next to today's: which applications come in, go out or are replaced, and which reference component gaps and overlaps that closes or opens. The future comes from what the data already plans (planned usages, phase-out dates, planned replacements). On top of that plan they can write a named scenario, a what-if with its own additions, phase-outs and replacements, compare it the same way, take it through a proposal and adoption, and apply an adopted scenario to the landscape as planned changes.

## Why

This change builds one row of the stackiq parity matrix: `stackiq:arch-scenarios`, "Model a future-state landscape and compare it with today's." No tender or feature request names it.

- SAP LeanIX rates yes: "plan your target architecture and monitor initiative progress" (https://help.sap.com/docs/leanix/ea/sap-leanix-architecture-and-road-map-planning), and "Understanding your architecture across the past, present, and future ... introducing the committed future" (https://updates.leanix.net/announcements/plan-with-consistent-future-architecture-data-introducing-the-committed-future).
- BlueDolphin rates yes: "Objects can be either Current (default) or Future state" (https://help.bluedolphin.io/en/articles/11967531-object-lifecycle-state) and "Map current and future state capabilities" (https://bluedolphin.io/capability-based-planning/).
- GEMMA Softwarecatalogus rates partial: "geplande harmonisaties ... met een status gepland met bijbehorende datum. Zo kan ook het uiteindelijke doel-landschap in 1 overzicht inzichtelijk worden gemaakt" (https://www.softwarecatalogus.nl/node/19703), with no side-by-side comparison.

The lane decided build because two competitors rate yes and architecture is a core area. The matrix note holds: planned usage and planned replacements exist per record, but there is no future-state model and no comparison with today.

## What stackiq has today

- A usage carries five phase start dates, from `startDateAcquisition` to `startDateOutPhased`, a `status` with the value Planned, and `plannedReplacement` with `plannedReplacementDate` (`lib/Settings/softwarecatalogus_register.json:3070` and the usage schema at :2654). The archived change `2026-06-14-application-lifecycle-tracking` added the replacement fields as "an organisation's portfolio decision about its usage" (its design Decision 3).
- The phase of a usage at any moment is a pure function of those dates, in the browser (`src/utils/lifecyclePhase.js:101`, `derivePhase(gebruik, now)`) and in PHP (`lib/Service/PortfolioReportDerivation.php:58`, `deriveLifecyclePhase`). Both take the moment as an argument.
- The Portfolio roadmap page (`src/manifest.json:1013`, `src/views/LifecycleRoadmapView.vue`) groups today's usages by phase and orders them by the nearest EOL, phase-out or replacement date (`buildEntry`, :394). It shows one moment, today, and no comparison.
- `architecture-reference-component-coverage` adds a pure coverage derivation and the shared organisation check `OrganisationReportAccess`.

## What this change builds

- A landscape comparison: `lib/Service/LandscapeComparisonService.php` and `GET /api/landscape-comparison`, which builds today's landscape and the landscape on a date (the plan, plus a scenario when one is named) and returns the application differences and the coverage differences.
- Two schemas in the `stackiq` register: `scenario` (name, organisation, target date, status) and `scenarioChange` (add, phase out or replace).
- A Scenarios index and a scenario page with the comparison, under the Architecture menu group, and a Compare with the plan page reached from the Portfolio roadmap.
- An Apply to landscape action on an adopted scenario that writes its changes into the usages as planned dates and replacements.

## Out of scope

- Cost of a future landscape. Cost stays with the contract administration, as the archived lifecycle change decided.
- Future states of GEMMA elements or views. BlueDolphin marks objects as future; this change works on the organisation's applications in use.
- Drawing a target architecture view. The view editor from `architecture-views-editor` can draw one; linking a view to a scenario can follow.
- Approval of a scenario by a board. The adopt transition records the decision; routing it to decidiq is `architecture-decision-register`'s question.

## Risks

- A plan built from dates is only as good as the dates. Usages without dates have phase Onbekend at every moment; the comparison lists them apart as "not dated" instead of guessing.
- Applying a scenario writes to usages other people own. It runs only on an adopted scenario, as the signed-in user with their rights, and every write shows in the usage's History tab.
