# Tasks: publish-and-lock-from-the-list

## Implementation tasks

### Task 1: Selection bar actions on the Applications and Services lists (board SkApplicaties)
- **spec_ref**: openspec/changes/publish-and-lock-from-the-list/specs/open-data-publishing/spec.md#requirement-selected-entries-are-published-withdrawn-or-locked-together-from-the-list-req-odp-010
- **files**: `src/views/FacetedCatalogIndexView.vue`, `src/services/publicationActions.js`, `src/modals/object/MassPublishObjects.vue`, `src/modals/object/MassLockObjects.vue`, `tests/vitest/publicationActions.spec.js`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN selected rows WHEN the bar renders THEN it shows the count and Publish, Withdraw, Lock and Delete
  - GIVEN Publish or Withdraw WHEN confirmed THEN `publicationActions.js` calls `PUT /api/publication/{objectType}/{uuid}/publish` or `DELETE .../depublish` per entry and returns succeeded and refused lists
  - GIVEN a refusal WHEN the run ends THEN the dialog lists each refused entry with the endpoint's message
  - GIVEN Lock WHEN confirmed THEN the selected objects are locked through OpenRegister's lock endpoint for the chosen duration
- [ ] Implement
- [ ] Test (vitest `publicationActions.spec.js`, Playwright `tests/e2e/spec-coverage/publish-from-list.spec.ts`)

### Task 2: Publish and withdraw on the entry's page (board SkApplicatie)
- **spec_ref**: openspec/changes/publish-and-lock-from-the-list/specs/open-data-publishing/spec.md#requirement-an-entrys-page-publishes-or-withdraws-that-entry-req-odp-011
- **files**: `src/manifest.json`, `src/components/publication/PublicationAction.vue`, `src/customComponents.js`, `tests/vitest/publicationAction.spec.js`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN ModuleDetail and the service detail WHEN the actions menu opens THEN Publish (now or a date) or Withdraw is offered according to the publication dates
  - GIVEN the endpoint refuses WHEN the action runs THEN the error is shown and the state does not change
- [ ] Implement
- [ ] Test (vitest `publicationAction.spec.js`)

### Task 3: Remove the dead view-modal path
- **spec_ref**: openspec/changes/publish-and-lock-from-the-list/specs/open-data-publishing/spec.md#requirement-selected-entries-are-published-withdrawn-or-locked-together-from-the-list-req-odp-010
- **files**: `src/modals/object/ViewObject.vue`, `src/dialogs/Dialogs.vue`, `src/store/plugins/stackiqPlugin.js`
- **acceptance_criteria**:
  - GIVEN the old publish buttons in ViewObject WHEN the change lands THEN they are removed or call `publicationActions.js`, and no code path opens the mass dialogs except the selection bar
- [ ] Implement
- [ ] Test (`npm run lint`, vitest suite)
