# Tasks: capability-requirements-from-the-matrix

## Implementation tasks

### Task 1: A wizard for applications, services and connections
- **spec_ref**: openspec/changes/capability-requirements-from-the-matrix/specs/guided-entry-wizard/spec.md
- **files**: `src/dialogs/EntryWizardDialog.vue`, `src/manifest.json`, `src/manifest.d/connections.json`
- **acceptance_criteria**:
  - GIVEN the Applications page WHEN a supplier chooses Add THEN a wizard opens on its first step
- [ ] Implement
- [ ] Test (Playwright `tests/e2e/workflows/entry-wizard.spec.ts`)

### Task 2: Contact conversion reads the email from the linked contact
- **spec_ref**: openspec/changes/capability-requirements-from-the-matrix/specs/contactpersoon-sync/spec.md
- **files**: `lib/Service/ContactpersoonService.php`, `lib/Service/Stackiq/ContactPersonHandler.php`, `lib/Controller/ContactpersonenController.php`
- **acceptance_criteria**:
  - GIVEN a contact person made with the current form WHEN it is converted THEN the account gets the email address of the linked Nextcloud contact
- [ ] Implement
- [ ] Test (PHPUnit `tests/Unit/Service/ContactpersoonServiceTest.php`)

### Task 3: A contract becomes Active only through an approved decision
- **spec_ref**: openspec/changes/capability-requirements-from-the-matrix/specs/contract-decision-delegation/spec.md
- **files**: `lib/Settings/register.d/contracts-to-decidesk.json`, `lib/Settings/softwarecatalogus_register.json`
- **acceptance_criteria**:
  - GIVEN a contract without an approved decision WHEN a user saves it as Active THEN the save is refused
- [ ] Implement
- [ ] Test (PHPUnit `tests/Unit/Settings/ContractApprovalGuardTest.php`)

### Task 4: Record the requirements in the capability matrix
- **spec_ref**: openspec/changes/capability-requirements-from-the-matrix/proposal.md
- **files**: `openspec/parity/capabilities.json`
- [x] Implement
- [x] Test (spec coverage measure v2)
