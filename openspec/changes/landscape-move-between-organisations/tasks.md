# Tasks: landscape-move-between-organisations

## Implementation tasks

### Task 1: Shared ownership map
- **spec_ref**: openspec/changes/landscape-move-between-organisations/specs/move-between-organisations/spec.md#requirement-req-mbo-001-an-administrator-moves-chosen-entries-to-another-organisation-and-they-keep-their-identity
- **files**: `lib/Service/Organisation/OwnershipMap.php`, `lib/Service/MergeOrganisatieService.php`
- **acceptance_criteria**:
  - GIVEN the merge service WHEN it runs after the extraction THEN its dry run and execute give the same counts as before
- [ ] Implement
- [ ] Test (PHPUnit merge tests unchanged and green; `tests/Unit/Service/Organisation/OwnershipMapTest.php`)

### Task 2: Transfer service and endpoints
- **spec_ref**: openspec/changes/landscape-move-between-organisations/specs/move-between-organisations/spec.md#requirement-req-mbo-002-only-an-administrator-of-both-organisations-moves-entries
- **files**: `lib/Service/OwnershipTransferService.php`, `lib/Controller/OwnershipTransferController.php`, `appinfo/routes.php`, `lib/AppInfo/Application.php`
- **acceptance_criteria**:
  - GIVEN two applications of organisation A WHEN an admin of A and B executes a transfer to B THEN both carry organisation B and provider B with the same uuids
  - GIVEN a user who administers only A WHEN they post a transfer to B THEN the answer is 403 and nothing changes
- [ ] Implement
- [ ] Test (PHPUnit `tests/Unit/Service/OwnershipTransferServiceTest.php`, `tests/Unit/Controller/OwnershipTransferControllerTest.php`; Newman request in `postman/stackiq-tests.json`)

### Task 3: Move to organisation dialog
- **spec_ref**: openspec/changes/landscape-move-between-organisations/specs/move-between-organisations/spec.md#requirement-req-mbo-003-the-dry-run-shows-what-moves-and-what-stays-behind
- **files**: `src/dialogs/MoveToOrganisationDialog.vue`, `src/manifest.json` and `src/manifest.d/*.json` (mass and header actions), `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN three selected usages WHEN the admin picks Move to organisation THEN the dialog lists the three and their connections that stay behind
- [ ] Implement
- [ ] Test (Playwright `tests/e2e/workflows/move-to-organisation.spec.ts`)

### Task 4: Documentation
- **spec_ref**: openspec/changes/landscape-move-between-organisations/specs/move-between-organisations/spec.md#requirement-req-mbo-003-the-dry-run-shows-what-moves-and-what-stays-behind
- **files**: `docs/features/move-to-organisation.md`, `docs/images/move-to-organisation.png`
- **acceptance_criteria**:
  - GIVEN the docs site WHEN a reader opens Move to organisation THEN who may move, the dry run and what stays are explained with a screenshot
- [ ] Implement
- [ ] Test (docs build, screenshot with Playwright)

## Verification

- `openspec validate landscape-move-between-organisations --type change --strict` passes.
- `composer check:strict` and `npm run lint` pass; the PHPUnit, Newman and Playwright cases above pass.
- English and Dutch strings for every new label (ADR-005); docs with a screenshot (ADR-010).
