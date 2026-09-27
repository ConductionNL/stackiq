# Tasks: landscape-usage-registration

## Implementation tasks

### Task 1: Usage schema fixes and owner fields
- **spec_ref**: openspec/changes/landscape-usage-registration/specs/application-usage-pages/spec.md#requirement-req-uap-003-a-usage-names-a-business-owner-and-a-technical-owner
- **files**: `lib/Settings/softwarecatalogus_register.json`, `lib/Settings/register.d/usage-owners.json`, `lib/Settings/stackiq_mock_register.json`
- **acceptance_criteria**:
  - GIVEN the merged register WHEN a usage in Planned is opened THEN Go live is offered
  - GIVEN a usage WHEN its business owner field opens THEN it lists contact persons of the consumer organisation only
- [ ] Implement
- [ ] Test (PHPUnit `tests/Unit/Settings/UsageSchemaTest.php`: lifecycle states are enum values, owner filters, name template keys exist)

### Task 2: Usage index and detail pages
- **spec_ref**: openspec/changes/landscape-usage-registration/specs/application-usage-pages/spec.md#requirement-req-uap-001-an-organisation-records-and-browses-the-applications-it-uses
- **files**: `src/manifest.d/usages.json`, `src/manifest.json` (ModuleDetail row route, OrganisatieDetail list), `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN two usages of the organisation WHEN the user opens Applications in use THEN both show with version and status
  - GIVEN a usage row WHEN the user opens it THEN the detail page shows version, status and owners
- [ ] Implement
- [ ] Test (Playwright `tests/e2e/workflows/usages.spec.ts`)

### Task 3: Add to our landscape
- **spec_ref**: openspec/changes/landscape-usage-registration/specs/application-usage-pages/spec.md#requirement-req-uap-002-an-organisation-adds-an-application-to-its-landscape-from-the-application-page
- **files**: `src/manifest.json` (ModuleDetail header action), `src/customComponents.js` if the action needs a handler
- **acceptance_criteria**:
  - GIVEN application X WHEN an information manager clicks Add to our landscape and saves version 2.1 THEN a usage of X by their organisation exists with version 2.1
- [ ] Implement
- [ ] Test (Playwright `tests/e2e/workflows/usages.spec.ts`, add case)

### Task 4: Documentation
- **spec_ref**: openspec/changes/landscape-usage-registration/specs/application-usage-pages/spec.md#requirement-req-uap-004-a-usage-moves-through-its-lifecycle-from-its-page
- **files**: `docs/features/applications-in-use.md`, `docs/images/applications-in-use.png`
- **acceptance_criteria**:
  - GIVEN the docs site WHEN a reader opens Applications in use THEN adding, the lifecycle and the owners are explained with a screenshot
- [ ] Implement
- [ ] Test (docs build, screenshot with Playwright)

## Verification

- `openspec validate landscape-usage-registration --type change --strict` passes.
- `composer check:strict` and `npm run lint` pass; the PHPUnit and Playwright cases above pass.
- English and Dutch strings for every new label (ADR-005); docs with a screenshot (ADR-010).
