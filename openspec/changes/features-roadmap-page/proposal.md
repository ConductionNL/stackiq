---
kind: code
depends_on: []
---

# The Features and roadmap page, with the store as its Uitbreidingen tab

## Summary

stackiq has a Features and roadmap page (`/features-roadmap`, footer menu) that lists what the app can do now, what is in beta and what is coming, fed by `openspec/features.overlay.json`. No requirement says it, so row `ins-feature-roadmap` cites nothing. Board SkFunctiesRoadmap draws the same page with a second tab, Uitbreidingen: the plug-ins from the store, with what each does and whether it is installed. In the app the store is a separate page (`/store`) behind its own footer entry. The board is the canon (decision 130), so this change writes the requirement for the page and builds the tab.

## Rows

- `ins-feature-roadmap` "See in the app which features are available, in beta or coming soon." Built: `src/manifest.json` FeaturesRoadmap page (type roadmap) and FeaturesRoadmapMenu; `openspec/features.overlay.json` (15 stable, 2 beta, 3 soon). Requirement written after the fact.
- `ops-plugins` (requirement REQ-PLAT-007 in `platform-properties`): the visible half drawn on SkFunctiesRoadmap, the Uitbreidingen tab, is built here.

## What changes

- A `features-roadmap` capability with two requirements.
- The Features and roadmap page gets two tabs: "Functies en roadmap" (the current content) and "Uitbreidingen" (the store list for stackiq, with a count). The footer Store entry opens the same page on the Uitbreidingen tab, so there is one place to look.

## Screens

See `screens.md`: SkFunctiesRoadmap.
