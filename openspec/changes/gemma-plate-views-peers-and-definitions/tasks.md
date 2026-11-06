# Tasks: gemma-plate-views-peers-and-definitions

## Implementation tasks

### Task 1: View picker on the plate
- **spec_ref**: openspec/changes/gemma-plate-views-peers-and-definitions/specs/gemma-plate/spec.md#requirement-the-plate-draws-the-gemma-view-the-user-picks-req-gpl-001
- **files**: `src/views/organisaties/GemmaPlate.vue`, `src/store/modules/view.js`, `src/utils/referenceComponentCoverage.js`, `tests/vitest/gemmaPlateViews.spec.js`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN imported GEMMA views WHEN the plate loads THEN a picker lists them with the GEMMA release, and `useViewStore` fetches the chosen view with `include_gebruik`
  - GIVEN a chosen view WHEN it renders THEN reference components are grouped by the view's groups, keeping the gap, covered and overlap states
  - GIVEN `?view=<id>` in the URL WHEN the page opens THEN that view is chosen
- [ ] Implement
- [ ] Test (vitest `gemmaPlateViews.spec.js`)

### Task 2: Inhabitants on the organisation
- **spec_ref**: openspec/changes/gemma-plate-views-peers-and-definitions/specs/gemma-plate/spec.md#requirement-the-plate-shows-what-comparable-organisations-use-req-gpl-002
- **files**: `lib/Settings/softwarecatalogus_register.json`, `src/manifest.json`, `tests/Unit/Settings/OrganizationInhabitantsTest.php`
- **acceptance_criteria**:
  - GIVEN the organization schema WHEN read THEN it has an optional integer `inhabitants` (minimum 0) shown on the organisation page and form
- [ ] Implement
- [ ] Test (PHPUnit `OrganizationInhabitantsTest`)

### Task 3: Peer counts per reference component
- **spec_ref**: openspec/changes/gemma-plate-views-peers-and-definitions/specs/gemma-plate/spec.md#requirement-the-plate-shows-what-comparable-organisations-use-req-gpl-002
- **files**: `lib/Service/PeerUsageService.php`, `lib/Controller/ReferenceComponentCoverageController.php`, `appinfo/routes.php`, `tests/Unit/Service/PeerUsageServiceTest.php`, `tests/Unit/Controller/ReferenceComponentCoverageControllerTest.php`
- **acceptance_criteria**:
  - GIVEN `GET /api/reference-component-coverage/peers?organisation=<uuid>` WHEN called by a member of that organisation THEN it returns, per reference component, the applications comparable organisations use with a count, computed over all usages with the service identity and bounded like the coverage endpoint
  - GIVEN a count below 3 WHEN returned THEN it is `null` with `fewerThan: 3`, and no organisation id or name appears anywhere in the response
  - GIVEN a caller who is not a member of the organisation WHEN calling THEN it is refused (the check in `OrganisationReportAccess` runs before the query)
  - GIVEN the organisation has no inhabitants value WHEN computing THEN comparable is the same `type`
- [ ] Implement
- [ ] Test (PHPUnit `PeerUsageServiceTest`, `ReferenceComponentCoverageControllerTest`)

### Task 4: The peers layer, table and side panel line
- **spec_ref**: openspec/changes/gemma-plate-views-peers-and-definitions/specs/gemma-plate/spec.md#requirement-the-plate-shows-what-comparable-organisations-use-req-gpl-002
- **files**: `src/views/organisaties/GemmaPlate.vue`, `src/components/gemma/PeerUsageTable.vue`, `tests/vitest/peerUsageTable.spec.js`, `tests/e2e/spec-coverage/gemma-plate-peers.spec.ts`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN the Layers control WHEN "What comparable organisations use" is switched on THEN the table shows per component the own applications and the peer applications with counts, under a heading naming the band
  - GIVEN a selected component WHEN the side panel renders THEN it lists the peer applications with counts
- [ ] Implement
- [ ] Test (vitest `peerUsageTable.spec.js`, Playwright `gemma-plate-peers.spec.ts`)

### Task 5: Definitions in the reference component facet
- **spec_ref**: openspec/changes/gemma-plate-views-peers-and-definitions/specs/gemma-plate/spec.md#requirement-gemma-terms-show-their-definition-where-they-appear-req-gpl-003
- **files**: `src/views/FacetedCatalogIndexView.vue`, `src/components/gemma/GemmaDefinitionPopover.vue`, `lib/Service/FacetService.php`, `tests/vitest/gemmaDefinitionPopover.spec.js`, `tests/Unit/Service/FacetServiceDefinitionTest.php`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN the reference component facet WHEN it renders THEN each value has an information button whose popover shows the name, "from GEMMA", `element.documentation` and a "More in GEMMA Online" link to `element.gemmaUrl`
  - GIVEN the facet endpoint WHEN it returns reference component buckets THEN each bucket carries `documentation` and `gemmaUrl`
  - GIVEN a component without documentation WHEN its popover opens THEN only the name and the link show
- [ ] Implement
- [ ] Test (vitest `gemmaDefinitionPopover.spec.js`, PHPUnit `FacetServiceDefinitionTest`)

### Task 6: Documentation and matrix
- **spec_ref**: openspec/changes/gemma-plate-views-peers-and-definitions/specs/gemma-plate/spec.md
- **files**: `docs/features/reference-component-coverage.md`, `openspec/parity/capabilities.json`
- **acceptance_criteria**:
  - GIVEN the feature doc WHEN read THEN it explains the view picker, the comparable organisations layer and the anonymity rule
  - GIVEN the tasks are done WHEN the change is archived THEN the three rows move back to built with new evidence
- [ ] Implement
