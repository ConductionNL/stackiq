# Tasks: cmdb-import-owner-identity

Spec: `openspec/changes/cmdb-import-owner-identity/specs/cmdb-export-import/spec.md` (`SPEC` below).

## Implementation Tasks

### Task 1: The business-owner pack maps an e-mail address and an employee number from placeholder columns
- **spec_ref**: `SPEC#requirement-the-owner-shall-become-a-contact-person-of-the-municipality-through-nextcloud-contacts-never-a-user-account-and-shall-never-be-publicly-readable-req-cmdb-010` (cmdb-export-import#REQ-CMDB-010)
- **files**: `lib/Service/Cmdb/CmdbImportPackRules.php`, `lib/Settings/cmdb-import/topdesk-business-owner.json`, `lib/Settings/cmdb-import/topdesk-profile.json`, `docs/features/cmdb-import.md`, `tests/Unit/Service/Cmdb/CmdbImportPackRulesTest.php`, `tests/Unit/Service/Cmdb/CmdbImportProfileTest.php`, `tests/Unit/Repair/SeedCmdbImportPacksTest.php`, `tests/Unit/Service/CmdbExportImportServiceTest.php`
- **acceptance_criteria**:
  - GIVEN the business-owner pack with its sources renamed WHEN the import's rules check it THEN it passes; GIVEN it maps `telephone` THEN it is refused
  - GIVEN the shipped pack THEN it maps `Applicatie Eigenaar (E-mail)` to `email` and `Applicatie Eigenaar (Personeelsnummer)` to `employeeNumber`, version 1.1.0
  - GIVEN an export without those columns THEN no "optional column not found" warning is raised
  - GIVEN the pack in OpenRegister maps renamed columns WHEN a row with them is imported THEN they are read
- [x] Implement
- [x] Test

### Task 2: The owner is matched on the employee number, then the e-mail address, then the name
- **spec_ref**: `SPEC#requirement-the-owner-shall-become-a-contact-person-of-the-municipality-through-nextcloud-contacts-never-a-user-account-and-shall-never-be-publicly-readable-req-cmdb-010` (cmdb-export-import#REQ-CMDB-010)
- **files**: `lib/Service/CmdbExportImportService.php`, `lib/Service/StackiqContactSyncService.php`, `tests/Unit/Service/CmdbExportImportServiceTest.php`, `tests/Unit/Service/StackiqContactSyncServiceTest.php`
- **acceptance_criteria**:
  - GIVEN two namesakes with their own employee number THEN two contacts and two contact persons
  - GIVEN a renamed owner with the same number (also as `1001.0`) THEN the same contact person
  - GIVEN the same number at another municipality THEN another contact UID; the number is not readable from the UID
  - GIVEN only an e-mail address THEN the owner is matched on it, not on a namesake without one
  - GIVEN a fixed UID WHEN a new contact is created THEN the card has that UID
- [x] Implement
- [x] Test
