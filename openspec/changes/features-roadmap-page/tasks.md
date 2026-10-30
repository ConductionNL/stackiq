# Tasks: features-roadmap-page

## Implementation tasks

### Task 1: Tabs on the Features and roadmap page (board SkFunctiesRoadmap)
- **spec_ref**: openspec/changes/features-roadmap-page/specs/features-roadmap/spec.md#requirement-the-uitbreidingen-tab-lists-the-extensions-from-the-store-req-frm-002
- **files**: `src/manifest.json`, `src/views/FeaturesRoadmapTabs.vue`, `src/customComponents.js`, `tests/vitest/featuresRoadmapTabs.spec.js`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN the FeaturesRoadmap page WHEN it renders THEN it shows the tabs "Features and roadmap" and "Extensions" with the store item count on the second
  - GIVEN the Extensions tab WHEN it renders THEN it shows the store list the Store page shows today (the nextcloud-vue store component with `app: stackiq` and the store types from `src/manifest.json`), with name, publisher, description and status
  - GIVEN a non-admin user WHEN the tab renders THEN no install action is shown
  - GIVEN the route `/features-roadmap?tab=extensions` WHEN opened THEN the Extensions tab is active
- [ ] Implement
- [ ] Test (vitest `featuresRoadmapTabs.spec.js`)

### Task 2: One entry for the store
- **spec_ref**: openspec/changes/features-roadmap-page/specs/features-roadmap/spec.md#requirement-the-uitbreidingen-tab-lists-the-extensions-from-the-store-req-frm-002
- **files**: `src/manifest.json`, `tests/validate-manifest.js`
- **acceptance_criteria**:
  - GIVEN StoreMenu WHEN chosen THEN it routes to FeaturesRoadmap with `tab=extensions`
  - GIVEN `/store` WHEN opened from an old link THEN it redirects to the Extensions tab
- [ ] Implement
- [ ] Test (`tests/validate-manifest.js`, Playwright `tests/e2e/spec-coverage/features-roadmap.spec.ts`)

### Task 3: Tag and test the status groups
- **spec_ref**: openspec/changes/features-roadmap-page/specs/features-roadmap/spec.md#requirement-the-features-and-roadmap-page-groups-the-apps-features-by-status-req-frm-001
- **files**: `src/manifest.json`, `tests/vitest/featuresOverlay.spec.js`
- **acceptance_criteria**:
  - GIVEN `openspec/features.overlay.json` WHEN the vitest reads it THEN every entry has slug, title, title_nl, summary, summary_nl and a status of stable, beta or soon
  - GIVEN the FeaturesRoadmap page WHEN read THEN its `_note` cites REQ-FRM-001
- [ ] Implement
- [ ] Test (vitest `featuresOverlay.spec.js`)
