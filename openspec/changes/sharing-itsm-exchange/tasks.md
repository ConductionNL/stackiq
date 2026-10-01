# Tasks: sharing-itsm-exchange

## Implementation tasks

### Task 1: Fields, contract licence fields and the connection entry
- **spec_ref**: openspec/changes/sharing-itsm-exchange/specs/itsm-exchange/spec.md#requirement-req-itx-005-licences-and-contracts-carry-what-a-cmdb-needs
- **files**: `lib/Settings/register.d/sharing-itsm-exchange.json`, `lib/Settings/register.d/value-assessment.json` (usage version only), `lib/Settings/softwarecatalogus_register.json` (`catalogContract` no longer requires `service`; register version), `lib/Settings/connections.json`, `tests/Unit/Settings/ItsmExchangeFragmentTest.php`, `tests/Unit/Settings/ConnectionsDeclarationTest.php`
- **acceptance_criteria**:
  - GIVEN the merged register WHEN it is read THEN usage, connection and catalogContract carry the service desk reference, usage carries installedVersion and publicationDate, catalogContract carries vendorReference, currency and supplier, and each schema's version is higher than on development
  - GIVEN a licence contract payload without a service WHEN it is validated against the merged catalogContract schema THEN it is valid
  - GIVEN integriq installed WHEN the Integrations page opens THEN it lists Service desk
- [ ] Implement
- [ ] Test (PHPUnit `tests/Unit/Settings/ItsmExchangeFragmentTest.php`; `ConnectionsDeclarationTest.php` covers the itsm entry)

### Task 2: Flow templates
- **spec_ref**: openspec/changes/sharing-itsm-exchange/specs/itsm-exchange/spec.md#requirement-req-itx-002-the-import-creates-and-updates-stackiq-records-and-never-duplicates-them
- **files**: `lib/Settings/flows/itsm-inbound-applications.json`, `lib/Settings/flows/itsm-inbound-relations.json`, `lib/Settings/flows/itsm-inbound-contracts.json`, `lib/Settings/flows/itsm-outbound-applications.json`, `lib/Settings/flows/itsm-file-applications.json`, `tests/Unit/Settings/ItsmFlowTemplatesTest.php`
- **acceptance_criteria**:
  - GIVEN each template filled with a source, synchronizations and presets WHEN it is checked THEN every node type is one OpenRegister or integriq registers, no edge carries a step, there is one trigger and one end, and no placeholder is left
  - GIVEN the outbound template WHEN its hash input is read THEN it holds only stackiq-owned fields, and the inbound hash input is the ownership-filtered mapping
- [ ] Implement
- [ ] Test (PHPUnit `tests/Unit/Settings/ItsmFlowTemplatesTest.php`; preflight on the live instance in Task 3)

### Task 3: Set-up action
- **spec_ref**: openspec/changes/sharing-itsm-exchange/specs/itsm-exchange/spec.md#requirement-req-itx-001-an-administrator-sets-up-the-exchange-without-stackiq-holding-a-credential
- **files**: `lib/Service/ItsmExchangeService.php`, `lib/Controller/ItsmExchangeController.php`, `appinfo/routes.php`, `lib/Service/ConnectionReportService.php` (report for the itsm key), `tests/Unit/Service/ItsmExchangeServiceTest.php`
- **acceptance_criteria**:
  - GIVEN a TOPdesk source WHEN the admin sets up THEN the synchronizations and flows are created, every flow validated before any is saved, then published and enabled
  - GIVEN preflight answers blocking for one flow WHEN the admin sets up THEN nothing is created and the answer names the node and reason
  - GIVEN the set-up ran before WHEN it runs again THEN the stored flows are updated, not duplicated
- [ ] Implement
- [ ] Test (PHPUnit `tests/Unit/Service/ItsmExchangeServiceTest.php`)

### Task 4: File import
- **spec_ref**: openspec/changes/sharing-itsm-exchange/specs/itsm-exchange/spec.md#requirement-req-itx-006-a-file-feeds-the-same-import
- **files**: `lib/Service/ItsmFileImportService.php`, `lib/Controller/ItsmExchangeController.php`, `tests/Unit/Service/ItsmFileImportServiceTest.php`
- **acceptance_criteria**:
  - GIVEN a CSV or XLSX with stackiq column names WHEN it is imported THEN the file flow runs once with the rows as payload
  - GIVEN a row without recordId WHEN it is imported THEN nothing runs and the answer names the row
- [ ] Implement
- [ ] Test (PHPUnit `tests/Unit/Service/ItsmFileImportServiceTest.php`)

### Task 5: CMDB page, admin section and the service desk column
- **spec_ref**: openspec/changes/sharing-itsm-exchange/specs/itsm-exchange/spec.md#requirement-req-itx-007-the-cmdb-page-says-what-stackiq-is
- **files**: `src/manifest.d/cmdb.json`, `src/views/cmdb/CmdbOverview.vue`, `src/customComponents.js`, `src/views/settings/sections/ItsmExchange.vue`, `src/views/settings/StackiqSettings.vue`, `src/manifest.d/usages.json`, `l10n/en.json`, `l10n/nl.json`, `tests/e2e/workflows/itsm-exchange.spec.ts`
- **acceptance_criteria**:
  - GIVEN a signed-in admin WHEN the CMDB page opens THEN it names what stackiq records and what it does not, links to each list, and shows the exchange status
  - GIVEN a usage with a service desk reference WHEN Applications in use opens THEN the Service desk column links to the record
- [ ] Implement
- [ ] Test (Playwright `tests/e2e/workflows/itsm-exchange.spec.ts`)

### Task 6: Live run against the mocks
- **spec_ref**: openspec/changes/sharing-itsm-exchange/specs/itsm-exchange/spec.md#requirement-req-itx-004-a-write-never-echoes-back
- **files**: `tests/live/itsm-exchange-live.sh`
- **acceptance_criteria**:
  - GIVEN lane iq's TOPdesk and ServiceNow mocks WHEN the script runs THEN the first import creates, the second updates, an outbound change reaches the mock once, a conflict keeps each owner's field, and no echo call follows
- [ ] Run against the TOPdesk mock
- [ ] Run against the ServiceNow mock
- [ ] Run against Ruben's ServiceNow developer instance (comes from Ruben later)

### Task 7: Documentation
- **spec_ref**: openspec/changes/sharing-itsm-exchange/specs/itsm-exchange/spec.md#requirement-req-itx-001-an-administrator-sets-up-the-exchange-without-stackiq-holding-a-credential
- **files**: `docs/features/service-desk-exchange.md`
- **acceptance_criteria**:
  - GIVEN the docs site WHEN a reader opens Service desk exchange THEN setting up the integriq source, the ownership rule, the file import and what to do with duplicate candidates are explained
- [ ] Implement

## Dependencies

- Integriq (lane iq, `connectors-service-desk-templates`): the `topdesk` and `servicenow` source templates, the mapping presets `itsm-<desk>-application-inbound`, `-application-outbound`, `-relation-inbound`, `-licence-inbound`, `-contract-inbound` and `itsm-file-application-inbound` with the `ownership` marker, the `apply-mapping` keys `ownership` and `exists`, and the TOPdesk and ServiceNow mocks.

## Verification

- `openspec validate sharing-itsm-exchange --type change --strict` passes.
- `composer check:strict` and `npm run lint` pass; `phpunit -c phpunit-unit.xml` runs the new tests (the strict run's own test step skips outside a Nextcloud tree).
- English and Dutch strings for every new label (ADR-005).
