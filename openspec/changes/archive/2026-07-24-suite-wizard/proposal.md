# Proposal: suite-wizard

## Summary
Add a guided wizard to register an application **suite** (e.g. "Centric Leefomgeving") together with its existing member applications in one pass, plus a suite index page and suite detail page so the resulting suites and their membership are browsable, and surface suite membership on the module (application) detail page. Closes softwarecatalog#372.

## Motivation
VNG Softwarecatalogus issue #242 flags that the retired "product" concept in the incumbent system should be replaced by a suite grouping. The `suite` schema already exists in `lib/Settings/softwarecatalogus_register.json` (naam, beschrijvingKort, beschrijvingLang, logo, website, contactpersoon, applicaties) but has no Vue surface at all — no index, no detail, no way to create one. Municipalities buying a bundled product (e.g. a "leefomgeving" suite covering several separately-licensed applications) currently have no way to record that grouping, and the broader UX research (21 wizard-labelled VNG issues) shows guided flows are one of the top user-wish clusters. This change ships the missing UI over the existing schema.

## Affected Projects
- [x] Project: `softwarecatalog` — new suite wizard, suite index/detail pages, module-detail suite membership widget, nav entry

## Scope

### In Scope
- A multi-step wizard (create suite → attach existing member applications → confirm) reachable from a nav entry.
- Suite index page (`type: index`) listing existing suites.
- Suite detail page (`type: detail`) showing suite data and its member applications, linking through to each module's detail page.
- Attaching **existing** `module` objects to a suite's `applicaties` array field.
- Showing suite membership (which suite(s) a module belongs to) on the `ModuleDetail` page.
- i18n: English keys plus `l10n/nl.js`/`.json` and `l10n/en_US.js`/`.json` translations.
- Unit tests for new wizard/store logic.
- A docs page for the new feature.

### Out of Scope
- Creating brand-new modules from inside the wizard — the wizard only attaches modules that already exist in the catalogue.
- Suite-level contracts or licensing.
- Migrating or importing the legacy "product" concept from the incumbent system.

## Approach
Reuse the existing `suite` OpenRegister schema unchanged (no fragment needed — verified during grounding that `naam`, `beschrijvingKort`, `beschrijvingLang`, `logo`, `website`, `contactpersoon` and `applicaties` already cover the wizard's needs). Add manifest-driven `Suites` (index) and `SuiteDetail` (detail) pages following the existing `Organisaties`/`OrganisatieDetail` and `Contactpersonen`/`ContactpersoonDetail` conventions. Build the wizard as a dedicated multi-step modal component under `src/modals/`, registering the `suite` and `module` object types by schema slug against the voorzieningen register id (the `useSelfFetchList.js` pattern already proven in `PortfolioReport.vue`), never via the unreliable `voorzieningen_config.<x>_schema` keys. Add a small `object-list`/`related`-style widget to `ModuleDetail` surfacing the suite(s) that reference this module in their `applicaties` array.

## New Dependencies
None.

## Impact
- `src/manifest.json`: new nav entry, new `Suites`/`SuiteDetail` pages, extended `ModuleDetail` page config.
- `src/modals/`: new `SuiteWizardModal.vue` (or step sub-components) orchestrating the three-step flow.
- `src/customComponents.js`: registered only if a custom component is required beyond manifest-driven pages/widgets.
- `l10n/`: new EN/NL translation keys.
- `docs/features/`: new suite-wizard feature doc with screenshots.

## Cross-Project Dependencies
None — this is a self-contained softwarecatalog frontend change over an existing OpenRegister schema; no backend controller or other app is touched.

## Risks

### Risk 1: Editing the register monolith by mistake
**Severity:** Medium — **Mitigation:** No schema change is planned (the `suite` schema already has everything needed); if a later iteration needs a schema tweak, it MUST land in a new `lib/Settings/register.d/suite-wizard.json` fragment per ADR-037, never in `softwarecatalogus_register.json` directly, since a monolith edit is a silent no-op on installed instances (softwarecatalog#391).

### Risk 2: Resolving the module/suite object type via the wrong config key
**Severity:** Low — **Mitigation:** Register object types by schema slug against `voorzieningenConfig.register`, mirroring the proven `PortfolioReport.vue` pattern, instead of `voorzieningen_config.<x>_schema` (several of those keys are never populated — the exact mistake that shipped a dead org picker, sc#392).

## Rollback Strategy
Revert the commit(s) on `wip/suite-wizard`. No schema/data migration is introduced (the `suite` schema and any existing `suite` objects are untouched), so rollback is a pure code revert with no data cleanup required.

## Open Questions
None.
