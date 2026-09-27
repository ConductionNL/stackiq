# Tasks: sharing-itsm-exchange

## Implementation tasks

### Task 1: External references and the connection entry
- **spec_ref**: openspec/changes/sharing-itsm-exchange/specs/itsm-exchange/spec.md#requirement-req-itx-002-an-application-in-use-shows-its-service-desk-record
- **files**: `lib/Settings/register.d/itsm-exchange.json`, `lib/Settings/connections.json`, `tests/Unit/Settings/ConnectionsDeclarationTest.php`
- **acceptance_criteria**:
  - GIVEN the merged register WHEN it is imported THEN usage carries externalReferences
  - GIVEN integriq installed WHEN the Integrations page opens THEN it lists Service desk
- [ ] Implement
- [ ] Test (PHPUnit `tests/Unit/Settings/ItsmExchangeFragmentTest.php`; the existing `ConnectionsDeclarationTest.php` extended for the itsm entry)

### Task 2: Flow templates and the set-up action
- **spec_ref**: openspec/changes/sharing-itsm-exchange/specs/itsm-exchange/spec.md#requirement-req-itx-001-the-organisations-applications-in-use-reach-its-service-desk
- **files**: `lib/Settings/flows/itsm-outbound.json`, `lib/Settings/flows/itsm-inbound.json`, `lib/Service/ItsmExchangeService.php`, `lib/Controller/ItsmExchangeController.php`, `appinfo/routes.php`, `src/views/settings/sections/ItsmExchange.vue`, `lib/Service/ConnectionReportService.php` (report for the itsm key)
- **acceptance_criteria**:
  - GIVEN the administrator set up the exchange with a TOPdesk source WHEN a usage goes live THEN the flow sends it to the source and writes the returned record id back
  - GIVEN a nightly run WHEN a service desk record matches a usage by name and supplier THEN the usage gets its link
- [ ] Implement
- [ ] Test (PHPUnit `tests/Unit/Service/ItsmExchangeServiceTest.php` fills and validates the templates; a flow test run against a mock source in `tests/e2e/workflows/itsm-exchange.spec.ts`)

### Task 3: Service desk link on the pages
- **spec_ref**: openspec/changes/sharing-itsm-exchange/specs/itsm-exchange/spec.md#requirement-req-itx-002-an-application-in-use-shows-its-service-desk-record
- **files**: `src/manifest.d/usages.json`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN a usage with a service desk reference WHEN Applications in use opens THEN the Service desk column links to the record
- [ ] Implement
- [ ] Test (Playwright case in `tests/e2e/workflows/itsm-exchange.spec.ts`)

### Task 4: Documentation
- **spec_ref**: openspec/changes/sharing-itsm-exchange/specs/itsm-exchange/spec.md#requirement-req-itx-001-the-organisations-applications-in-use-reach-its-service-desk
- **files**: `docs/features/service-desk-exchange.md`, `docs/images/service-desk-exchange.png`
- **acceptance_criteria**:
  - GIVEN the docs site WHEN a reader opens Service desk exchange THEN setting up the integriq source, installing the set and reading the results are explained with a screenshot
- [ ] Implement
- [ ] Test (docs build, screenshot with Playwright)

## Verification

- `openspec validate sharing-itsm-exchange --type change --strict` passes.
- `composer check:strict` and `npm run lint` pass; the PHPUnit and Playwright cases above pass.
- English and Dutch strings for every new label (ADR-005); docs with a screenshot (ADR-010).
