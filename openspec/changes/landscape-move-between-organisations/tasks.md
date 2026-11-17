# Tasks: landscape-move-between-organisations

## Implementation tasks

### Task 1: Shared ownership map
- **spec_ref**: openspec/changes/landscape-move-between-organisations/specs/move-between-organisations/spec.md#requirement-req-mbo-001-an-administrator-moves-chosen-entries-to-another-organisation-and-they-keep-their-identity
- **files**: `lib/Service/Organisation/OwnershipMap.php`, `lib/Service/MergeOrganisatieService.php`
- **acceptance_criteria**:
  - GIVEN the merge service WHEN it runs after the extraction THEN its dry run and execute give the same counts as before
- [x] Implement
- [x] Test (PHPUnit merge tests unchanged and green; `tests/Unit/Service/Organisation/OwnershipMapTest.php`)

### Task 2: Transfer service and endpoints
- **spec_ref**: openspec/changes/landscape-move-between-organisations/specs/move-between-organisations/spec.md#requirement-req-mbo-002-only-an-administrator-of-both-organisations-moves-entries
- **files**: `lib/Service/OwnershipTransferService.php`, `lib/Controller/OwnershipTransferController.php`, `appinfo/routes.php`, `lib/AppInfo/Application.php`
- **acceptance_criteria**:
  - GIVEN two applications of organisation A WHEN an admin of A and B executes a transfer to B THEN both carry organisation B and provider B with the same uuids
  - GIVEN a user who administers only A WHEN they post a transfer to B THEN the answer is 403 and nothing changes
- [x] Implement
- [x] Test (PHPUnit done; the Newman request is not added, it needs a running instance) (PHPUnit `tests/Unit/Service/OwnershipTransferServiceTest.php`, `tests/Unit/Controller/OwnershipTransferControllerTest.php`; Newman request in `postman/stackiq-tests.json`)

### Task 3: Move to organisation dialog
- **spec_ref**: openspec/changes/landscape-move-between-organisations/specs/move-between-organisations/spec.md#requirement-req-mbo-003-the-dry-run-shows-what-moves-and-what-stays-behind
- **files**: `src/dialogs/MoveToOrganisationDialog.vue`, `src/manifest.json` and `src/manifest.d/*.json` (mass and header actions), `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN three selected usages WHEN the admin picks Move to organisation THEN the dialog lists the three and their connections that stay behind
- [x] Implement (`src/dialogs/MoveToOrganisationDialog.vue`, opened by the Move to organisation bulk action on Applications, Services, Applications in use, Connections and Contracts through the handlers in `src/services/moveToOrganisation.js`; and by the Move to organisation header action on the Application, Contract, Connection and Application-in-use detail pages, an `open-modal` action on the registered `MoveToOrganisationLauncher`, which takes the record id from the route because a detail page's header actions reach no named app handler and do not resolve `@objectId` in modal props. Services has no detail page.)
- [ ] Test (Playwright `tests/e2e/workflows/move-to-organisation.spec.ts`; vitest `moveToOrganisation.spec.js`, `moveToOrganisationDialog.spec.js` and `moveToOrganisationLauncher.spec.js` cover the handlers, the request, the dry-run lists and the detail-page action; the Playwright spec needs a running instance)

### Task 4: Documentation
- **spec_ref**: openspec/changes/landscape-move-between-organisations/specs/move-between-organisations/spec.md#requirement-req-mbo-003-the-dry-run-shows-what-moves-and-what-stays-behind
- **files**: `docs/features/move-to-organisation.md`, `docs/images/move-to-organisation.png`
- **acceptance_criteria**:
  - GIVEN the docs site WHEN a reader opens Move to organisation THEN who may move, the dry run and what stays are explained with a screenshot
- [ ] Implement (page `docs/features/move-to-organisation.md` written; the screenshot `docs/images/move-to-organisation.png` needs a running instance and is left)
- [ ] Test (docs build, screenshot with Playwright)

## Verification

- `openspec validate landscape-move-between-organisations --type change --strict` passes.
- `composer check:strict` and `npm run lint` pass; the PHPUnit, Newman and Playwright cases above pass.
- English and Dutch strings for every new label (ADR-005); docs with a screenshot (ADR-010).
