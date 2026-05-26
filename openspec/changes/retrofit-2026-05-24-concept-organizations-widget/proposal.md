# Retrofit — concept-organizations-widget

Describes observed behavior of `ConceptOrganisatiesWidget::load()` as 1 new REQ under a new `concept-organizations-widget` capability. Code already exists — this change retroactively specifies it. The widget's IWidget metadata getters (`getId`, `getTitle`, `getOrder`, `getIconClass`, `getUrl`) are framework plumbing already bucketed as `plumbing`; only `load()` carries the actual loading contract.

## Affected code units

- lib/Dashboard/ConceptOrganisatiesWidget.php::load

## Approach

- Describe the bundle-loading order (runtime → vendor → nc-vue → widget) that the widget enforces via `Util::addScript` calls.
- Note the dependency on webpack `splitChunks` + `runtimeChunk` config — the script names are not arbitrary.

Source: openspec/coverage-report.md generated 2026-05-24. Umbrella: ConductionNL/softwarecatalog#285.
