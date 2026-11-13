# Tasks: capability-rows-the-code-does-not-yet-meet

## Implementation tasks

### Task 1: List SaaS subscriptions with the purchases made outside IT
- **spec_ref**: openspec/changes/capability-rows-the-code-does-not-yet-meet/specs/saas-subscriptions/spec.md
- **files**: `lib/Settings/softwarecatalogus_register.json`, `src/manifest.json`, `lib/Service/PortfolioReportService.php`
- **acceptance_criteria**:
  - GIVEN a SaaS usage bought outside IT WHEN the filter Bought outside IT is chosen THEN it is listed with its yearly cost and department
- [ ] Implement
- [ ] Test (Playwright `tests/e2e/workflows/saas-subscriptions.spec.ts`)

### Task 2: Alert the organisations that use the affected software, and name the right fields
- **spec_ref**: openspec/changes/capability-rows-the-code-does-not-yet-meet/specs/softwarecatalog-notifications/spec.md
- **files**: `lib/Settings/softwarecatalogus_register.json` (vulnerability-reported subject `{{naam}}` to `{{name}}`, review subject `{{naam}}` and `{{waardering}}` to the current fields, recipients through the usages)
- **acceptance_criteria**:
  - GIVEN a usage of an application WHEN a vulnerability is reported for it THEN the usage's application owner is a recipient
  - GIVEN the subject templates WHEN checked THEN every placeholder names a schema property
- [ ] Implement
- [ ] Test (PHPUnit `tests/Unit/Settings/VulnerabilityNotificationRuleTest.php`)

### Task 3: Tell the users of an application about its new version
- **spec_ref**: openspec/changes/capability-rows-the-code-does-not-yet-meet/specs/softwarecatalog-notifications/spec.md
- **files**: `lib/Settings/softwarecatalogus_register.json` (module-version-published recipients)
- **acceptance_criteria**:
  - GIVEN a usage of an application WHEN a version of it is published THEN the usage's application owner is a recipient
- [ ] Implement
- [ ] Test (PHPUnit `tests/Unit/Settings/VersionNotificationRuleTest.php`)

### Task 4: Record the module versions a vulnerability affects
- **spec_ref**: openspec/changes/capability-rows-the-code-does-not-yet-meet/specs/module-vulnerability-tracking/spec.md
- **files**: `lib/Settings/softwarecatalogus_register.json` (vulnerability.moduleVersions), `src/manifest.json` (vulnerability and module version pages)
- [ ] Implement
- [ ] Test (Playwright `tests/e2e/workflows/vulnerability-versions.spec.ts`)

### Task 5: Link AI systems to business processes
- **spec_ref**: openspec/changes/capability-rows-the-code-does-not-yet-meet/specs/ai-system-inventory/spec.md
- **files**: `lib/Settings/softwarecatalogus_register.json` (aiSystem.processes), `src/manifest.d/ai-systems.json`
- **depends_on**: architecture-process-mapping
- [ ] Implement
- [ ] Test (Playwright `tests/e2e/workflows/ai-systems.spec.ts`)

### Task 6: Offer the reference components on the application form
- **spec_ref**: openspec/changes/capability-rows-the-code-does-not-yet-meet/specs/reference-component-placement/spec.md
- **files**: `lib/Settings/softwarecatalogus_register.json` (module.referenceComponents not hideOnForm, picker register), `src/manifest.json` (ModuleDetail md-data)
- [ ] Implement
- [ ] Test (Playwright `tests/e2e/workflows/reference-component-placement.spec.ts`)

### Task 7: Keep pending reviews hidden on the Reviews index, and take status off the form
- **spec_ref**: openspec/changes/capability-rows-the-code-does-not-yet-meet/specs/catalog-ratings/spec.md
- **files**: `lib/Settings/register.d/catalog-ratings.json`, `src/manifest.json` (Reviews index)
- [ ] Implement
- [ ] Test (Playwright `tests/e2e/workflows/review-moderation.spec.ts`)

### Task 8: Make an approved organisation active
- **spec_ref**: openspec/changes/capability-rows-the-code-does-not-yet-meet/specs/open-data-publishing/spec.md
- **files**: `lib/Service/ModerationService.php`
- [ ] Implement
- [ ] Test (PHPUnit `tests/Unit/Service/ModerationServiceTest.php`)

### Task 9: Resolve the standard version picker on a connection
- **spec_ref**: openspec/changes/capability-rows-the-code-does-not-yet-meet/specs/catalogue-connection-pages/spec.md
- **files**: `lib/Settings/softwarecatalogus_register.json` (connection.standardVersions), `src/manifest.d/connections.json`
- [ ] Implement
- [ ] Test (Playwright `tests/e2e/workflows/connections.spec.ts`)

### Task 10: Test the built behaviour the matrix rows claim
- **spec_ref**: openspec/changes/capability-rows-the-code-does-not-yet-meet/proposal.md
- **files**: `tests/e2e/workflows/catalog-ratings.spec.ts`, `tests/e2e/workflows/vulnerabilities.spec.ts`, `tests/e2e/workflows/bulk-actions.spec.ts`, `tests/e2e/workflows/connections.spec.ts`, `tests/e2e/workflows/contracts.spec.ts`, `tests/e2e/workflows/federation-settings.spec.ts`, `tests/e2e/workflows/portfolio-report.spec.ts`, `tests/Unit/Settings/FieldUpdateRulesTest.php`
- **acceptance_criteria**:
  - REQ-CLR-005, 009, 011, 013, 014, 015, 016 and 017 each have a test that passes against the current code
- [ ] Test

### Task 11: Open a vulnerability on its own page with the exposed organisations
- **spec_ref**: openspec/changes/capability-rows-the-code-does-not-yet-meet/specs/module-vulnerability-tracking/spec.md
- **files**: `src/views/KwetsbaarhedenView.vue` (row opens KwetsbaarheidDetail, not the generic modal), `src/manifest.json` (VulnerabilityExposurePanel)
- [ ] Implement
- [ ] Test (Playwright `tests/e2e/workflows/vulnerabilities.spec.ts`)

### Task 12: A public sign-up form for new organisations
- **spec_ref**: openspec/changes/capability-rows-the-code-does-not-yet-meet/specs/open-data-publishing/spec.md
- **files**: `src/views/public/SignUpView.vue`, `src/manifest.json`, `lib/Settings/softwarecatalogus_register.json` (organization authorization.create without public)
- [ ] Implement
- [ ] Test (Playwright `tests/e2e/workflows/self-registration.spec.ts`, PHPUnit `tests/Unit/Settings/OrganisationCreateRuleTest.php`)

### Task 13: Record the requirements in the capability matrix
- **spec_ref**: openspec/changes/capability-rows-the-code-does-not-yet-meet/proposal.md
- **files**: `openspec/parity/capabilities.json`
- [x] Implement
- [x] Test (spec coverage measure v2, strict)

### Task 14: Test the hosting model and the views API the matrix rows claim
- **spec_ref**: openspec/changes/capability-rows-the-code-does-not-yet-meet/specs/application-hosting-model/spec.md, openspec/changes/capability-rows-the-code-does-not-yet-meet/specs/view-enrichment-api/spec.md
- **files**: `tests/Unit/Controller/ViewControllerTest.php` (new)
- **acceptance_criteria**:
  - REQ-CLR-020 and REQ-CLR-021 each have a test that passes against the current code
- [ ] Test

### Task 15: A reviewer outside the supplier marks a compliance claim verified
- **spec_ref**: openspec/changes/capability-rows-the-code-does-not-yet-meet/specs/module-compliance-assessment/spec.md
- **files**: `lib/Settings/softwarecatalogus_register.json` (compliancy verifiedBy, verifiedAt), `src/utils/complianceMatrix.js` (verified needs verifiedBy, not evidence alone), `src/views/ComplianceMatrixView.vue`
- **acceptance_criteria**:
  - GIVEN a claim with evidence and no reviewer WHEN the matrix renders THEN the cell reads claimed
- [ ] Implement
- [ ] Test (Playwright `tests/e2e/workflows/compliance-matrix.spec.ts`)

### Task 16: List the organisations waiting in draft on the dashboard widget
- **spec_ref**: openspec/specs/fe-organizations/spec.md
- **files**: `src/views/widgets/ConceptOrganisatiesWidget.vue` (filter on status `Draft`, accept writes `Active`)
- **acceptance_criteria**:
  - GIVEN an organisation with status Draft WHEN the dashboard opens THEN the widget lists it, and accepting it saves status Active
- [ ] Implement
- [ ] Test (Vitest on the widget)

### Task 17: Re-point the remaining relations on an organisation merge
- **spec_ref**: openspec/specs/organisation-merge/spec.md
- **files**: `lib/Service/MergeOrganisatieService.php` (FIELD_RELATION_TYPES: usage provider, organisation memberships and participants, model organisations), the dry-run counts
- **acceptance_criteria**:
  - GIVEN a merge WHEN it completes THEN no usage, organisation membership or model still points at the merged-away organisation, and the preview counted each
- [ ] Implement
- [ ] Test (PHPUnit `tests/Unit/Service/MergeOrganisatieServiceTest.php`)

### Task 18: Resolve the standard version picker on a compliance record, and offer it from the application page
- **spec_ref**: openspec/specs/module-compliance-assessment/spec.md
- **files**: `lib/Settings/softwarecatalogus_register.json` (compliancy standard version relation register), `src/manifest.json` (ModuleDetail declare a standard)
- **acceptance_criteria**:
  - GIVEN an application page WHEN a user declares support for a standard version THEN the version resolves and the record shows on the compliance matrix
- [ ] Implement
- [ ] Test (Playwright `tests/e2e/workflows/compliance-matrix.spec.ts`)

### Task 19: Usages made on stackiq's own forms carry the supplier
- **spec_ref**: openspec/specs/application-page/spec.md
- **files**: usage create and edit (set the hidden provider from the module's provider), a repair step for existing usages
- **acceptance_criteria**:
  - GIVEN a municipality adds a usage on the usage form WHEN the supplier opens its application page THEN the supplier sees that organisation in the usages list
- [ ] Implement
- [ ] Test (PHPUnit on the usage provider, Playwright `tests/e2e/workflows/usages.spec.ts`)

### Task 20: Anonymous readers get only published applications
- **spec_ref**: openspec/specs/open-data-publishing/spec.md
- **files**: `lib/Settings/softwarecatalogus_register.json` (module public read rule: publication date at or before now, drop the registeredBy Supplier branch)
- **acceptance_criteria**:
  - GIVEN a supplier's module without a publication date WHEN an anonymous reader asks for it THEN it is not returned
- [ ] Implement
- [ ] Test (Newman open-data collection)

### Task 21: Switch federation on in the exchange settings and show the registration status
- **spec_ref**: openspec/specs/federated-catalog-sync/spec.md
- **files**: `src/views/settings/sections/FederationSettings.vue`, `src/manifest.d/connections.json`
- **acceptance_criteria**:
  - GIVEN federation is off WHEN an admin switches it on in the exchange settings THEN the instance registers with the directory and the page shows the registration status
- [ ] Implement
- [ ] Test (Playwright `tests/e2e/workflows/federation-settings.spec.ts`)
