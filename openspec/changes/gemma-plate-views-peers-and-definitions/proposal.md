---
kind: code
depends_on:
  - architecture-reference-component-coverage
---

# The GEMMA plate picks a view, shows what comparable organisations use, and explains GEMMA terms in place

## Summary

The GEMMA plate page (`src/views/organisaties/GemmaPlate.vue`, from `architecture-reference-component-coverage`) shows one organisation's applications per reference component with gaps and overlap, and shows the GEMMA definition of the component a user selects. Board SkGemmaPlaat draws three things the page does not do yet: choose which GEMMA view (plate) to look at, a layer and table "Wat vergelijkbare gemeenten gebruiken", and the definition of a GEMMA term everywhere it appears, including the reference component facet on the Applications page (board SkApplicaties: "Zaaksysteem, uit GEMMA", the definition, "Meer in GEMMA Online"). Three rows say built while their own evidence says the visible half is missing. The board is the canon (decision 130), so this change writes their requirements and the build tasks, and the rows move to building.

## Rows

- `arch-gemma-views` "Open a GEMMA architecture view with your own applications drawn inside it." Built today: the view API (`GET /api/views`, `/api/views/{viewId}`, `lib/Service/ViewService.php` enrichment) and the org export into Archi. Missing: a view picker on the plate, so the plate draws the reference components of the chosen GEMMA view in its groups; `src/store/modules/view.js` (`useViewStore`) is still imported by nothing.
- `mkt-compare-peers` "See which software comparable organisations use for the same reference component." Built today: the shared-usage enrichment for participants only (`ViewService.php:1206-1265`). Missing: counts of other organisations' usages per reference component, and the page that renders them. The usage read rules limit a usage manager to its own organisation, so the counts are aggregated server side and never name an organisation.
- `arch-definitions` "Read the definition of a GEMMA term in place while working in the catalogue." Built today: a standard's page shows its definition, and the plate's side panel shows `element.documentation` for the selected component. Missing: the definition in the reference component facet on the Applications and Services pages.

## Decisions taken in this change (to confirm in review)

- "Comparable" means the same organisation type (`organization.type`, for example municipality) and the same inhabitants band. The organisation schema has no inhabitants field, so this change adds an optional `inhabitants` integer. Bands: under 30,000; 30,000 to 60,000; 60,000 to 100,000; 100,000 to 250,000; above 250,000. Without a value, comparable falls back to the same type.
- A count below 3 organisations is shown as "fewer than 3", so a single municipality's choice cannot be read off the table.

## Out of scope

- The "Gedeeld met partners" layer (`architecture-shared-usage-on-views`) and the GGM objects layer (`architecture-data-model-and-ggm`) on the same board.
- Drawn views and their editor (`architecture-views-editor`).

## Screens

See `screens.md`: SkGemmaPlaat, SkApplicaties, SkStandaarden.
