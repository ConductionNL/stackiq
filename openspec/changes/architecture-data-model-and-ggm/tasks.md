# Tasks: architecture-data-model-and-ggm

## Implementation tasks

### Task 1: Register fragment for data entities, attributes and the usage link
- **spec_ref**: openspec/changes/architecture-data-model-and-ggm/specs/data-model-and-ggm/spec.md#requirement-req-dmg-003-an-application-in-use-shall-record-the-data-entities-it-holds-data-for
- **files**: `lib/Settings/register.d/architecture-data-model-and-ggm.json`, `tests/Unit/Settings/DataModelRegisterShapeTest.php`
- **acceptance_criteria**:
  - GIVEN the merged register WHEN it loads THEN `usage.dataEntities` relates to `element` with the query type=BusinessObject and is facetable
  - GIVEN the merged register WHEN it loads THEN `element.attributes` has name, dataType, isKey and description, and `relation` has both cardinality enums
  - GIVEN the fragment WHEN it is compared with development THEN `usage`, `element` and `relation` carry bumped versions
  - GIVEN a fresh install WHEN the seed runs THEN the two own entities, their relation and the usage link exist
- [ ] Implement
- [ ] Test (PHPUnit `DataModelRegisterShapeTest`, `RegisterFragmentMergeTest`)

### Task 2: Data model index and entity page
- **spec_ref**: openspec/changes/architecture-data-model-and-ggm/specs/data-model-and-ggm/spec.md#requirement-req-dmg-001-stackiq-shall-show-the-data-entities-of-the-imported-model-on-a-data-model-page
- **files**: `src/manifest.d/architecture-data-model.json`, `src/menu-layout.json`
- **acceptance_criteria**:
  - GIVEN the effective manifest WHEN it is built THEN `Gegevensmodel` filters `element` on type BusinessObject with the quick filters All, GGM and Own
  - GIVEN the add dialog WHEN it opens THEN it asks only for name, documentation and attributes and creates type BusinessObject with origin drawn
  - GIVEN the effective menu WHEN it renders THEN Data model sits under the Architecture group
  - GIVEN an entity page WHEN it renders THEN "Applications that hold this data" lists usages filtered on `dataEntities`
- [ ] Implement
- [ ] Test (`tests/validate-manifest.js`, Playwright `tests/e2e/workflows/data-model.spec.ts`)

### Task 3: Relations drawn around an entity
- **spec_ref**: openspec/changes/architecture-data-model-and-ggm/specs/data-model-and-ggm/spec.md#requirement-req-dmg-002-a-data-entity-page-shall-draw-the-entitys-relations-to-other-entities
- **files**: `src/views/architecture/DataEntityRelations.vue`, `src/utils/dataEntityGraph.js`, `src/customComponents.js`, `tests/vitest/dataEntityRelations.spec.js`
- **acceptance_criteria**:
  - GIVEN an imported and a drawn entity WHEN their relations load THEN both are found through the identifier field
  - GIVEN a relation with cardinalities WHEN it is drawn THEN the edge label holds its name and both cardinalities
  - GIVEN the graph WHEN it renders THEN every colour prop is a CSS variable and a text table lists the same relations
- [ ] Implement
- [ ] Test (vitest `dataEntityRelations.spec.js`, Playwright relation scenario)

### Task 4: Attributes with keys
- **spec_ref**: openspec/changes/architecture-data-model-and-ggm/specs/data-model-and-ggm/spec.md#requirement-req-dmg-004-a-municipality-shall-add-its-own-data-entities-with-attributes-keys-and-cardinalities
- **files**: `src/views/architecture/DataEntityAttributes.vue`, `tests/vitest/dataEntityAttributes.spec.js`
- **acceptance_criteria**:
  - GIVEN a drawn entity WHEN its page renders THEN key attributes come first, marked key in text, and can be edited
  - GIVEN an imported entity WHEN its page renders THEN no edit control shows and the notice about the GGM file does
- [ ] Implement
- [ ] Test (vitest `dataEntityAttributes.spec.js`, Playwright new entity scenario)

### Task 5: Data entities on the usage page
- **spec_ref**: openspec/changes/architecture-data-model-and-ggm/specs/data-model-and-ggm/spec.md#requirement-req-dmg-003-an-application-in-use-shall-record-the-data-entities-it-holds-data-for
- **files**: `src/manifest.d/usages.json`
- **acceptance_criteria**:
  - GIVEN a usage page WHEN it is edited THEN the data entities picker offers business objects only, and the saved list shows on the page
- [ ] Implement
- [ ] Test (Playwright usage link scenario)

### Task 6: Documentation and translations
- **spec_ref**: openspec/changes/architecture-data-model-and-ggm/specs/data-model-and-ggm/spec.md#requirement-req-dmg-001-stackiq-shall-show-the-data-entities-of-the-imported-model-on-a-data-model-page
- **files**: `docs/features/data-model.md`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN the feature page WHEN it is read THEN it shows the Data model page, an entity page with its graph and a usage with data entities, each in a screenshot, and says how to import a newer GGM
  - GIVEN a Dutch instance WHEN the Data model page renders THEN every new label reads in Dutch
- [ ] Implement
- [ ] Test (`tests/l10n` key parity, screenshots captured with Playwright)

## Verification

- `openspec validate architecture-data-model-and-ggm --type change --strict`
- PHPUnit: `DataModelRegisterShapeTest`, `RegisterFragmentMergeTest`
- vitest: `dataEntityRelations.spec.js`, `dataEntityAttributes.spec.js`
- Playwright: `tests/e2e/workflows/data-model.spec.ts`
- Documentation in `docs/features/data-model.md` with screenshots (ADR-010)
- English and Dutch strings for every new label and enum value (ADR-005)
