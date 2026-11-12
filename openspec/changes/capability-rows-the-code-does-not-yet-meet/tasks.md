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
