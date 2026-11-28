# Tasks: capability-rows-the-code-does-not-yet-meet

## Implementation tasks

### Task 1: List SaaS subscriptions with the purchases made outside IT
- **spec_ref**: openspec/changes/capability-rows-the-code-does-not-yet-meet/specs/saas-subscriptions/spec.md
- **files**: `lib/Settings/softwarecatalogus_register.json`, `src/manifest.json`, `lib/Service/PortfolioReportService.php`
- **acceptance_criteria**:
  - GIVEN a SaaS usage bought outside IT WHEN the filter Bought outside IT is chosen THEN it is listed with its yearly cost and department
- [x] Implement (`register.d/saas-subscriptions.json`: `usage.buyingDepartment` and `usage.boughtOutsideIt` (default false), usage version 1.6.4, shown on GebruikDetail's data widget; the License posture page (`/license-posture`, `LicensePostureView`) gets a SaaS subscriptions section over `src/utils/saasSubscriptions.js`: every usage hosted as SaaS with its supplier, organisation, buying department and the yearly cost of its contracts (contract-administration's `totalAnnualisedCost`, one-off costs left out), and the filters All subscriptions and Bought outside IT. Derived in the browser like the rest of that page, so `PortfolioReportService.php` is not touched. Tests: vitest `saasSubscriptions.spec.js` (8), `licensePostureSaas.spec.js` (3))
- [x] Test (Playwright `tests/e2e/workflows/saas-subscriptions.spec.ts`: written from both scenarios, not yet run against a live instance) (e2e written, runs in nightly; not verified on a live instance)

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
- [x] Implement (`register.d/vulnerability-affected-versions.json`: `vulnerability.moduleVersions`, related module versions next to `modules`, version 1.0.23, shown by KwetsbaarheidDetail's data widget; ModuleversieDetail lists the vulnerabilities recorded against the version (`mv-vulnerabilities`, filter `moduleVersions` = the version))
- [x] Test (PHPUnit `tests/Unit/Settings/VulnerabilityAffectedVersionsTest.php`; Playwright `tests/e2e/workflows/vulnerability-versions.spec.ts`, written, needs a live run)

### Task 5: Link AI systems to business processes
- **spec_ref**: openspec/changes/capability-rows-the-code-does-not-yet-meet/specs/ai-system-inventory/spec.md
- **files**: `lib/Settings/softwarecatalogus_register.json` (aiSystem.processes), `src/manifest.d/ai-systems.json`
- **depends_on**: architecture-process-mapping
- [x] Implement (`register.d/ai-system-processes.json`: `aiSystem.processes`, related business processes labelled by name, version 0.1.1, shown by AiSystemDetail's data widget; ProcesDetail lists the AI systems that support the process (`process-ai-systems`, filter `processes` = the process, rows open AiSystemDetail). Test: vitest `tests/vitest/aiSystemProcesses.spec.js`)
- [x] Test (Playwright `tests/e2e/workflows/ai-systems.spec.ts`: the process scenario is written, not yet run against a live instance) (e2e written, runs in nightly; not verified on a live instance)

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
- [x] Test
- Progress 21 Nov: `tests/e2e/workflows/catalog-ratings.spec.ts` (REQ-CLR-009) is written; like the other specs this task lists, it has not run against a live instance yet.
- Progress 23 Nov: `tests/Unit/Settings/FieldUpdateRulesTest.php` (REQ-CLR-017) is written and passes: the import key is updated by admins only and stays readable, the maintenance notification fields declare their update group, the application keeps its own rules, and every field-level update rule is well formed. The task stays open until the e2e specs run against a live instance.
- Live 25 Nov 2026 (sandbox Nextcloud 34, PostgreSQL 16, OpenRegister main, stackiq development): passing REQ-CLR-009 `catalog-ratings.spec.ts` 1/1, REQ-CLR-005 `vulnerabilities.spec.ts` 1/1, REQ-CLR-011 `bulk-actions.spec.ts` 1/1, REQ-CLR-013 `connections.spec.ts` 4/4, REQ-CLR-015 `federation-settings.spec.ts` 2/2 (specs corrected to the page: required seed fields, the in-production start date, the record sidebar, the row link, below-the-fold widgets, the Actions menu, the switch role), REQ-CLR-017 `FieldUpdateRulesTest`. Still open: REQ-CLR-014 (`contracts.spec.ts` stays `test.fixme`: the contract page shows no supplier, a product gap) and REQ-CLR-016 (`portfolio-report.spec.ts`: the report costs only contracts owned by the selected organisation, and the seed's contract belongs to the admin's organisation; creating it inside the municipality's organisation is refused because the register's schemas do not resolve there).

- Built 26 Nov 2026 (REQ-CLR-014): the contract page shows its supplier. `ContractSupplierPanel` (a ContractDetail body widget, `ct-supplier`) reads the contract, then its service, then the service's provider, and links the supplier's name to OrganisatieDetail; it says so when the contract names no service or the service no supplier. vitest `tests/vitest/contractSupplierPanel.spec.js`; `contracts.spec.ts` is no longer fixme and passes live 1/1 (26 Nov 2026, sandbox Nextcloud 34.0.6, PostgreSQL 16, OpenRegister main 01e805cd + openregister#11305; seed corrected to the required catalogService.type and catalogContract.usage, status In negotiation because Active needs an approved decision). REQ-CLR-016 also passes live 1/1 the same day: `portfolio-report.spec.ts` seeds the municipality as an OpenRegister organisation (same uuid) and moves the contract into it, and `PortfolioReportDerivation::normalizeResults()` now keeps an entity's owning organisation (`_organisation`); before, `ownedContracts()` dropped every contract read as an entity, so no usage ever showed a cost. PHPUnit `PortfolioReportDerivationGoldenTest::testNormalizedEntityKeepsItsOwningOrganisation`. Every REQ this task names now has a passing test, so Test is ticked.

### Task 11: Open a vulnerability on its own page with the exposed organisations
- **spec_ref**: openspec/changes/capability-rows-the-code-does-not-yet-meet/specs/module-vulnerability-tracking/spec.md
- **files**: `src/views/KwetsbaarhedenView.vue` (row opens KwetsbaarheidDetail, not the generic modal), `src/manifest.json` (VulnerabilityExposurePanel)
- [x] Implement (`KwetsbaarhedenView.openDetail` routes a row to KwetsbaarheidDetail, whose data widget shows severity, affected applications and versions and whose Exposure tab (`VulnerabilityExposurePanel`, already on the page) lists the organisations and their in-production usages; the record modal stays only as the fallback without a router)
- [x] Test (vitest `kwetsbaarhedenViewRender.spec.js`; Playwright `tests/e2e/workflows/vulnerabilities.spec.ts`, written, needs a live run)

### Task 12: A public sign-up form for new organisations
- **spec_ref**: openspec/changes/capability-rows-the-code-does-not-yet-meet/specs/open-data-publishing/spec.md
- **files**: `src/views/public/SignUpView.vue`, `src/manifest.json`, `lib/Settings/softwarecatalogus_register.json` (organization authorization.create without public)
- [ ] Implement
- [ ] Test (Playwright `tests/e2e/workflows/self-registration.spec.ts`, PHPUnit `tests/Unit/Settings/OrganisationCreateRuleTest.php`)
- Open 21 Nov: stackiq serves no public (signed-out) page, so where the form lives is a decision (a public stackiq page, or the portal site posting to `POST /api/intake/register`). Dropping `public` from the organisation schema's create also needs the intake to save without RBAC, and a check that no other client creates organisations anonymously through the objects API. `tests/e2e/workflows/self-registration.spec.ts` is written against the intake and marked fixme until the form exists.

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
- [x] Implement (`UsageProviderListener` on every usage create and update sets `provider` to the supplier of the module it names, in the same write, so a usage made on the usage form, which hides the field, reaches its supplier through the usage read rule on `provider`; a module without a supplier leaves the provider as it is. `BackfillUsageProvider` after the register import sets it on existing usages. `UsageProviderSync` reads the module with RBAC off, once per request)
- [x] Test (PHPUnit `UsageProviderSyncTest`, `UsageProviderListenerTest`, `BackfillUsageProviderTest`; Playwright scenario added to `tests/e2e/workflows/usages.spec.ts`, needs a live run)

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
- [x] Implement
- [x] Test (Playwright `tests/e2e/workflows/federation-settings.spec.ts`) (e2e written, runs in nightly; not verified on a live instance)
- Done 21 Nov: the Catalog federation section has a switch (Federation is on) that sends `PUT /api/federation/enabled` (`FederationController::setEnabled`, admin setting). Switching on writes `federation_enabled`, announces the instance through OpenCatalogi's BroadcastService and records the registration (`federation_registration`: registered, unavailable when OpenCatalogi is missing, failed with the reason; `off` when switched off); integriq is told the new state. `GET /api/federation/status` carries `registration`, and the line under the switch says registered on a date or why not. The integriq disabled message now points to the section instead of occ. Tests: PHPUnit `FederationServiceOpenCatalogiTest` (on/registered, refused/failed, unavailable, off), `FederationConfigTest` (switch, registration round trip), `FederationControllerTest` (switch); vitest `tests/vitest/federationSwitch.spec.js` (8). The Playwright case in `federation-settings.spec.ts` is written (no longer fixme) and not run, so Test stays open.
