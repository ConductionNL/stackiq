# Design — view-products-enrichment

## 1. Domain data model decision (task 1.1 / 1.2)

**Products = the existing `dienst` schema** in the stackiq register
(`lib/Settings/softwarecatalogus_register.json`, schema key `dienst`). No new
schema is introduced. This is the closest existing concept to "product" in
this catalog's domain (a VNG-style software catalog): `dienst` (service/
product) already carries `aanbieder` (the offering organisation), `modules`
(related `module` objects), `website`, descriptions, etc. — it IS the
catalog's product record. This mirrors the ADR-022 "reuse, don't duplicate"
spirit explicitly called out in the proposal.

The `dienst_schema` config key already exists end-to-end: it is exposed in
the settings UI (`src/views/settings/sections/OpenRegisterIntegration.vue`,
key `voorzieningen_dienst_schema`) and normalized into
`SettingsService::getVoorzieningenConfig()['dienst_schema']`
(`SettingsService::normalizeVoorzieningenConfig()`, `schemaKeys` list) — the
same register/config surface `getGebruikData()` and
`getDeelnamesGebruikData()` already use (stackiq register, NOT the
separate AMEF register `getModulesData()` reads from).

**Node-linkage field**: mirrors `getModulesData()`'s established convention
— index by `$product['elementRef'] ?? $product['identifier']`. This is a
conscious, documented trade-off: `dienst` does not declare an `elementRef`
property in the schema today (no code path currently stamps one), so in
practice `getNodeProducts()` will return an empty match set until a `dienst`
record has been explicitly linked to an ArchiMate view element (by setting
`elementRef` — e.g. via a future explicit "link to ArchiMate element" action,
out of scope for this change). This is NOT a regression: it is the same
"gracefully empty until linked" behavior `getModulesData()`/`getNodeModules()`
already tolerate for any module lacking an `elementRef`/`identifier`, and
matches task 4.1's requirement that `getProductsData()` "returns `[]` when
the schema is unconfigured (graceful, not fatal)" — extended here to also
cover "configured but not yet linked to any view node."

Once a future change stamps `elementRef` on `dienst` records at ArchiMate-
link time (out of scope here — no such link-authoring UI exists yet), the
enrichment starts returning real per-node matches with zero further backend
changes, because `getNodeProducts()` performs the same direct-lookup-by-
elementRef `getNodeModules()` already does.

## 2. Why not the AMEF register (module_schema/component_schema path)

`getModulesData()`'s AMEF-register path
(`amefConfig['module_schema'] ?? amefConfig['component_schema'] ??
amefConfig['element_schema']`) reads from a *different* register — the AMEF
register holds ArchiMate-imported artifacts (elements/views/models), and
`module_schema`/`component_schema` are not exposed anywhere in the settings
UI (only `element_schema`, `model_schema`, `organization_schema`,
`property_definition_schema`, `relation_schema`, `view_schema` are — see
`OpenRegisterIntegration.vue`). In practice that fallback chain resolves to
`element_schema` — i.e. "modules" enrichment today effectively re-queries
the AMEF elements themselves. Following that exact path for "products" would
mean products enrichment queries the SAME AMEF elements a second time under
a different name, which does not match the domain intent ("Product filter"
in the spec) and would not reuse the catalog's actual product record
(`dienst`). Using the stackiq-register `dienst` schema is the correct,
domain-accurate choice.
