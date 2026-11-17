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
- [x] Implement
- [x] Test (PHPUnit `tests/Unit/Settings/VulnerabilityNotificationRuleTest.php`)

### Task 3: Tell the users of an application about its new version
- **spec_ref**: openspec/changes/capability-rows-the-code-does-not-yet-meet/specs/softwarecatalog-notifications/spec.md
- **files**: `lib/Settings/softwarecatalogus_register.json` (module-version-published recipients)
- **acceptance_criteria**:
  - GIVEN a usage of an application WHEN a version of it is published THEN the usage's application owner is a recipient
- [x] Implement
- [x] Test (PHPUnit `tests/Unit/Settings/VersionNotificationRuleTest.php`)

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
- [x] Implement (`lib/Settings/register.d/reference-component-placement.json`: `referenceComponents` is no longer `hideOnForm`, and its items carry `x-external-register: vng-gemma` and `x-label-field: name`, so the form's picker reads the GEMMA elements, filtered to reference components, from the register that holds them; ModuleDetail's md-data shows the field)
- [x] Test (PHPUnit `tests/Unit/Settings/ReferenceComponentPlacementTest.php`; Playwright `tests/e2e/workflows/reference-component-placement.spec.ts`, written, needs a live run. The GEMMA plate draws applications from usages' `usedForReferenceComponents`, not from this field: the plate half of the scenario is not built here)

### Task 7: Keep pending reviews hidden on the Reviews index, and take status off the form
- **spec_ref**: openspec/changes/capability-rows-the-code-does-not-yet-meet/specs/catalog-ratings/spec.md
- **files**: `lib/Settings/register.d/catalog-ratings.json`, `src/manifest.json` (Reviews index)
- [x] Implement (`catalog-ratings.json`: every read rule but `software-catalog-admins` matches `status: approved`, so a pending or rejected review reaches only its author, through OpenRegister's owner admit, and the admins; `status` is `hideOnForm`, so the index's form no longer offers it and a new review keeps the default `pending`; the Reviews index reads through the same rules, no manifest change needed)
- [x] Test (PHPUnit `tests/Unit/Service/ReviewModerationOverlayReachesTheSchemaTest.php`: the read rules and the hidden status; Playwright `tests/e2e/workflows/review-moderation.spec.ts`, written, needs a live run)

### Task 8: Make an approved organisation active
- **spec_ref**: openspec/changes/capability-rows-the-code-does-not-yet-meet/specs/open-data-publishing/spec.md
- **files**: `lib/Service/ModerationService.php`
- [x] Implement
- [x] Test (PHPUnit `tests/Unit/Service/ModerationServiceTest.php`)

### Task 9: Resolve the standard version picker on a connection
- **spec_ref**: openspec/changes/capability-rows-the-code-does-not-yet-meet/specs/catalogue-connection-pages/spec.md
- **files**: `lib/Settings/softwarecatalogus_register.json` (connection.standardVersions), `src/manifest.d/connections.json`
- [x] Implement (`register.d/standard-version-pickers.json`: `connection.standardVersions` items carry `x-external-register: vng-gemma` and `x-label-field: name`, so the form's picker lists the GEMMA standard versions (still filtered on `gemmaType=standaardversie`) from the register that holds them; KoppelingDetail's kp-data shows `standardVersions`)
- [x] Test (PHPUnit `tests/Unit/Settings/StandardVersionPickersTest.php`; the Playwright scenario in `tests/e2e/workflows/connections.spec.ts` needs a live run)

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
- [x] Test (PHPUnit `tests/Unit/Controller/ViewControllerTest.php`: REQ-CLR-021, the list with id and name and a view without enrichment parameters returned as imported; `tests/Unit/Settings/HostingModelFieldsTest.php`: REQ-CLR-020, Hosting offers IaaS and PaaS next to on-premises and SaaS and is filterable, hosting location and jurisdiction record NL)

### Task 15: A reviewer outside the supplier marks a compliance claim verified
- **spec_ref**: openspec/changes/capability-rows-the-code-does-not-yet-meet/specs/module-compliance-assessment/spec.md
- **files**: `lib/Settings/softwarecatalogus_register.json` (compliancy verifiedBy, verifiedAt), `src/utils/complianceMatrix.js` (verified needs verifiedBy, not evidence alone), `src/views/ComplianceMatrixView.vue`
- **acceptance_criteria**:
  - GIVEN a claim with evidence and no reviewer WHEN the matrix renders THEN the cell reads claimed
- [x] Implement (`register.d/compliance-claim-verification.json`: compliancy `verifiedBy`, `verifiedAt`, `verifiedByOrganisation`, hidden on the form, version 0.0.13; `POST /api/compliancy/{id}/verify` (`ComplianceVerificationController`, `ComplianceVerificationService`): a user in admin, software-catalog-admins or functioneel-beheerder whose active organisation is not the module's provider marks an evidenced claim verified, every refusal before the write, an unknown supplier refuses; `ComplianceVerificationGuard` refuses those fields on every other create and update and clears them when the evidence changes; `complianceMatrix.js` reads verified only with evidence and `verifiedBy`; the matrix cell shows "Verified by <name> on <date>" and offers Mark as verified on a claimed cell with evidence)
- [x] Test (PHPUnit `ComplianceVerificationTest`, `ComplianceVerificationServiceTest`, `ComplianceVerificationGuardTest`, `ComplianceVerificationControllerTest`; vitest `complianceMatrix.spec.js`; Playwright `tests/e2e/workflows/compliance-matrix.spec.ts`, written, needs a live run)

### Task 16: List the organisations waiting in draft on the dashboard widget
- **spec_ref**: openspec/specs/fe-organizations/spec.md
- **files**: `src/views/widgets/ConceptOrganisatiesWidget.vue` (filter on status `Draft`, accept writes `Active`)
- **acceptance_criteria**:
  - GIVEN an organisation with status Draft WHEN the dashboard opens THEN the widget lists it, and accepting it saves status Active
- [x] Implement (already on development: `src/utils/organisationStatus.js` filters on Draft and accepts with Active; `tests/vitest/cmdbOverviewAndConceptWidget.spec.js`)
- [x] Test (Vitest on the widget)

### Task 17: Re-point the remaining relations on an organisation merge
- **spec_ref**: openspec/specs/organisation-merge/spec.md
- **files**: `lib/Service/MergeOrganisatieService.php` (FIELD_RELATION_TYPES: usage provider, organisation memberships and participants, model organisations), the dry-run counts
- **acceptance_criteria**:
  - GIVEN a merge WHEN it completes THEN no usage, organisation membership or model still points at the merged-away organisation, and the preview counted each
- [x] Implement (already on development: `CatalogueReferenceMap::REFERENCES['organization']` walks usage provider, organisation deelnames and participants and model organizations; `MergeOrganisatieServiceTest` counts each in the dry run)
- [x] Test (PHPUnit `tests/Unit/Service/MergeOrganisatieServiceTest.php`)

### Task 18: Resolve the standard version picker on a compliance record, and offer it from the application page
- **spec_ref**: openspec/specs/module-compliance-assessment/spec.md
- **files**: `lib/Settings/softwarecatalogus_register.json` (compliancy standard version relation register), `src/manifest.json` (ModuleDetail declare a standard)
- **acceptance_criteria**:
  - GIVEN an application page WHEN a user declares support for a standard version THEN the version resolves and the record shows on the compliance matrix
- [x] Implement (`register.d/standard-version-pickers.json`: `compliancy.standardVersion` reads the vng-gemma register the same way; ModuleDetail's Compliance claims list offers "Declare a standard" (create on, with standard version, URL and evidence reference, the module set by the list's filter), so the record shows on the compliance matrix as claimed until a reviewer verifies it, task 15)
- [x] Test (PHPUnit `tests/Unit/Settings/StandardVersionPickersTest.php`; Playwright `tests/e2e/workflows/compliance-matrix.spec.ts` covers the matrix half, needs a live run)

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
- [x] Implement (the module's `{group: public, match: {registeredBy: Supplier}}` read rule is gone, and so is its mirror `moduleRegisteredBy` on moduleVersion (`register.d/publication-field-rules.json`) and in `ModuleVersionPublicationService::isPublicNow()`; the public rule left is the publication date at or before now)
- [x] Test (PHPUnit `PublishGateRbacTest::testAnUnpublishedSupplierModuleIsNotPublic`; Newman folder `11. Open data: anonymous module reads` in `tests/integration/stackiq.postman_collection.json`, written, needs a live run)

### Task 21: Switch federation on in the exchange settings and show the registration status
- **spec_ref**: openspec/specs/federated-catalog-sync/spec.md
- **files**: `src/views/settings/sections/FederationSettings.vue`, `src/manifest.d/connections.json`
- **acceptance_criteria**:
  - GIVEN federation is off WHEN an admin switches it on in the exchange settings THEN the instance registers with the directory and the page shows the registration status
- [ ] Implement
- [ ] Test (Playwright `tests/e2e/workflows/federation-settings.spec.ts`)
