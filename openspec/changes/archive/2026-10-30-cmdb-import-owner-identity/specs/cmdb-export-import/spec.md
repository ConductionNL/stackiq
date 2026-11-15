# cmdb-export-import Specification (delta: cmdb-import-owner-identity)

**Status**: in-progress
**Scope**: stackiq
**OpenSpec changes**:
- [cmdb-export-import](../../../archive/2026-10-05-cmdb-export-import/) _(archived 2026-10-05)_
- [cmdb-import-owner-identity](../../)

## Purpose

The CMDB import recognises an owner by display name, so two people with the same name become one contact (Jira WOO-586, finding F29/F51). The municipality will add the owner's e-mail address and employee number to its export, but has not named the columns yet. This delta lets the business-owner pack map both, matches an owner on the employee number first, and ships placeholder column names that the administrator renames in OpenRegister's pack editor once the export's names are known, without a stackiq release.

Nextcloud OCP interfaces used: `OCP\Contacts\IManager` (through `StackiqContactSyncService`), `OCP\IL10N` (messages).

## MODIFIED Requirements

### Requirement: The owner SHALL become a contact person of the municipality through Nextcloud Contacts, never a user account, and SHALL never be publicly readable (REQ-CMDB-010)

The business owner pack SHALL map "Applicatie Eigenaar (Persoon)" (the display name, which may be a function instead of a person's name) to `name` and "Applicatie Eigenaar (Functie)" (the role) to `role`, and SHALL map the optional columns "Applicatie Eigenaar (E-mail)" to `email` and "Applicatie Eigenaar (Personeelsnummer)" to `employeeNumber`. Those two column names are placeholders until the municipality names the columns of its export; an administrator renames their `source` in OpenRegister's pack editor (REQ-CMDB-021), and the import then reads the renamed columns without a stackiq release. The profile SHALL list the two placeholder columns as absent on both CMDB sheets, so an export without them raises no "optional column not found" warning; after a rename the absent list no longer names them, so an export with the renamed columns on only one CMDB sheet warns for the other. The business-owner pack SHALL map, and set as a default, only `name`, `role`, `email` and `employeeNumber` (extending the list in REQ-CMDB-021). No technical owner SHALL be imported; the functional administrator columns SHALL NOT be read. For the owner the service SHALL resolve a Nextcloud contact through `StackiqContactSyncService`, all in a dedicated address book "Stackiq CMDB owners" of the admin who runs the import (`StackiqContactSyncService::syncToNamedAddressBook()` and `searchNamedAddressBook()`), which is created on first use: when the row has an employee number, by the contact whose UID is `stackiq-cmdb-owner-` followed by the HMAC-SHA256, keyed with a key derived from the instance's `secret`, of the municipality's uuid, `|` and the lower-cased, trimmed number (a number Excel stored as `1234.0`, or with leading zeros as `01234`, counts as `1234`), and otherwise by creating the contact with that UID, without matching another contact on the row's e-mail address, so two namesakes with their own number stay two owners, also with a shared e-mail address, and a renamed owner keeps the contact; the number itself SHALL NOT be stored on the contact, and SHALL NOT be recoverable from the UID without the instance's secret. A value without a digit other than 0 (such as `-`, `0` or `n.v.t.`) SHALL count as no employee number; without an instance secret the number SHALL NOT be used, and a warning SHALL be logged once per import. Without an employee number it SHALL match on the e-mail address when the row has one (any address of the contact), and otherwise on an exact match on the display name, and otherwise create one; neither match SHALL return a contact whose UID carries the `stackiq-cmdb-owner-` prefix, which belongs to an employee number. A new contact SHALL carry the e-mail address when the row has one. It SHALL NOT match a contact in any other address book of the admin, and SHALL NOT add an owner to them. It SHALL then reuse or create one `contactPerson` with that `contactsUid`, `organization` = the municipality and `role` = "Applicatie Eigenaar (Functie)" when given, and SHALL set `usage.businessOwner` to it. An owner first imported by name or e-mail address and later with an employee number gets the employee-number contact and its own `contactPerson`; the usage follows the export (REQ-CMDB-009) and the earlier contact stays in the address book. The import SHALL NOT create Nextcloud user accounts. When Nextcloud Contacts is unavailable, the row SHALL be imported without an owner and SHALL carry a warning. `contactPerson` and `usage` SHALL have no public read rule, so the owner is never readable by an anonymous visitor; a published module SHALL refer to them by relation only.

#### Scenario: The owner becomes the business owner
@e2e exclude Needs a Contacts address book; tests/Unit/Service/CmdbExportImportServiceTest.php asserts the calls to a StackiqContactSyncService test double and the saved contactPerson.

- **GIVEN** the "Onbeh" row with "Applicatie Eigenaar (Persoon)" `Achternaam, Voornaam` and "Applicatie Eigenaar (Functie)" `Afdelingshoofd`, and the "Beheerde" row whose person column holds the function `Teamleider Applicatiebeheer`
- **WHEN** they are imported for "Gemeente Voorbeeldstad"
- **THEN** one `contactPerson` SHALL exist per owner with the resolved `contactsUid`, `organization` = "Gemeente Voorbeeldstad" and the function as `role`
- **AND** each usage SHALL have `businessOwner` = its owner's contact person and no `technicalOwner`
- **AND** no Nextcloud user account SHALL be created

#### Scenario: Imported owners are never readable anonymously
@e2e tests/e2e/spec-coverage/cmdb-import.spec.ts

- **GIVEN** the anonymised export was imported, creating contact persons for its owners
- **WHEN** a visitor who is not signed in lists the `contactPerson` and `usage` objects through OpenRegister, or searches OpenCatalogi for an imported application
- **THEN** OpenRegister SHALL return no contact person and no usage
- **AND** the OpenCatalogi search hit SHALL carry no owner name, and its `contactPerson` and `usages` SHALL be empty or ids only

#### Scenario: The same owner on two rows is one contact person
@e2e exclude Covered by the service test; tests/Unit/Service/CmdbExportImportServiceTest.php testTheSameOwnerOnTwoRowsIsOneContactPerson.

- **GIVEN** two rows with the same "Applicatie Eigenaar (Persoon)"
- **WHEN** they are imported
- **THEN** exactly one `contactPerson` for that contact SHALL exist for the municipality, referenced by both usages

#### Scenario: A namesake in the admin's own address book is not linked
@e2e exclude Needs a CardDAV backend with two address books; tests/Unit/Service/CmdbExportImportServiceTest.php testOwnersAreMatchedOnlyInTheOwnersAddressBook asserts a personal contact with the owner's exact name is not linked while a contact in the owners' address book is reused, and tests/Unit/Service/StackiqContactSyncServiceTest.php testAnExistingContactIsMatchedOnlyInTheNamedAddressBook asserts the same for an e-mail address against the contacts manager's address book keys.

- **GIVEN** an admin whose personal address book holds a contact "Voornaam Achternaam", and whose "Stackiq CMDB owners" address book holds "Teamleider Applicatiebeheer"
- **WHEN** they import a row with owner `Achternaam, Voornaam` and a row with owner `Teamleider Applicatiebeheer`
- **THEN** the first owner SHALL get a new contact in "Stackiq CMDB owners", and the personal contact SHALL NOT be linked or changed
- **AND** the second owner SHALL reuse the contact already in "Stackiq CMDB owners"

#### Scenario: A new owner contact goes into the dedicated address book
@e2e exclude Needs a CardDAV backend; tests/Unit/Service/StackiqContactSyncServiceTest.php testNewContactsGoIntoTheNamedAddressBook asserts the "Stackiq CMDB owners" address book is created once and holds every new card, with no other address book written, and tests/Unit/Service/CmdbExportImportServiceTest.php testTheOwnerBecomesTheBusinessOwner asserts the import asks for that address book.

- **GIVEN** an admin with a personal address book and no "Stackiq CMDB owners" address book
- **WHEN** they import a row whose owner has no Nextcloud contact yet
- **THEN** an address book "Stackiq CMDB owners" SHALL be created for that admin and SHALL hold the new contact
- **AND** the admin's personal address book SHALL NOT receive a contact
- **AND** when the address book cannot be created, the row SHALL be imported without an owner and SHALL carry a warning

#### Scenario: Contacts disabled does not block the import
@e2e exclude Environment condition; tests/Unit/Service/CmdbExportImportServiceTest.php sets isAvailable() to false.

- **GIVEN** the Nextcloud Contacts app is disabled
- **WHEN** the anonymised export is imported
- **THEN** both modules and usages SHALL be saved without owners
- **AND** each row with an owner SHALL carry the warning that owners were skipped because Contacts is unavailable

#### Scenario: An owner with an employee number is matched on it, not on the name
@e2e exclude Needs a CardDAV backend; tests/Unit/Service/CmdbExportImportServiceTest.php testAnOwnerWithAnEmployeeNumberIsMatchedOnItNotOnTheName asserts the contacts and contact persons through a StackiqContactSyncService test double, and tests/Unit/Service/StackiqContactSyncServiceTest.php testANewContactTakesTheGivenUid asserts the card is created with the given UID.

- **GIVEN** two rows whose owner is `Achternaam, Voornaam`, with employee numbers `1001` and `2002`
- **WHEN** they are imported for "Gemeente Voorbeeldstad"
- **THEN** two contacts and two `contactPerson` objects SHALL exist, and the UID of the first SHALL be the one derived from `1001` and the municipality, without the number in it
- **AND** when a later export names the owner of `1001` `Nieuwenaam, Voornaam` with the number stored as `1001.0`, the usage SHALL keep that same contact person

#### Scenario: A shared e-mail address does not merge owners with their own employee number
@e2e exclude Needs a CardDAV backend; tests/Unit/Service/CmdbExportImportServiceTest.php testASharedEmailAddressDoesNotMergeOwnersWithTheirOwnNumber asserts two contacts, and tests/Unit/Service/StackiqContactSyncServiceTest.php testAGivenUidIsNotOverriddenByAnEmailMatch asserts the given UID wins over an e-mail match.

- **GIVEN** two rows whose owner is `Achternaam, Voornaam`, both with e-mail address `team@example.org`, with employee numbers `1001` and `2002`
- **WHEN** the export is imported
- **THEN** two contacts SHALL exist, with the UIDs derived from `1001` and `2002`, and two `contactPerson` objects

#### Scenario: An owner without an employee number never takes an employee-number contact
@e2e exclude Needs a CardDAV backend; tests/Unit/Service/CmdbExportImportServiceTest.php testAnOwnerWithoutANumberNeverTakesANumberContact covers it.

- **GIVEN** a row with owner `Achternaam, Voornaam`, e-mail address `team@example.org` and employee number `1001`
- **AND** a row with another owner and the same e-mail address, and a row with owner `Achternaam, Voornaam`, both without an employee number
- **WHEN** the export is imported
- **THEN** three contacts and three `contactPerson` objects SHALL exist

#### Scenario: A placeholder in the employee number column is no employee number
@e2e exclude Needs a CardDAV backend; tests/Unit/Service/CmdbExportImportServiceTest.php testAPlaceholderInTheNumberColumnIsNoEmployeeNumber and tests/Unit/Service/Cmdb/CmdbOwnerIdentityTest.php testAValueWithoutANumberGivesNoUid cover it.

- **GIVEN** two rows with different owners whose employee number cell holds `-`
- **WHEN** the export is imported
- **THEN** the owners SHALL be matched on their names and two contacts SHALL exist, neither with a UID derived from an employee number

#### Scenario: Without an employee number the e-mail address matches the owner
@e2e exclude Needs a CardDAV backend; tests/Unit/Service/CmdbExportImportServiceTest.php testAnOwnerWithOnlyAnEmailAddressIsMatchedOnIt.

- **GIVEN** the owners' address book holds a contact "Voornaam Achternaam" without an e-mail address
- **WHEN** a row with owner `Achternaam, Voornaam` and e-mail address `v.achternaam@example.org` is imported twice
- **THEN** one new contact with that e-mail address SHALL exist next to the namesake, and one `contactPerson`

#### Scenario: Renamed owner columns are read without a stackiq release
@e2e exclude Needs OpenRegister's pack store; tests/Unit/Service/CmdbExportImportServiceTest.php testRenamedOwnerColumnsInOpenRegisterAreRead uses the fake migration-pack service, and tests/Unit/Service/Cmdb/CmdbImportPackRulesTest.php testTheOwnerPackMayMapAnEmailAddressAndAnEmployeeNumber asserts the renamed pack passes the import's rules.

- **GIVEN** the business-owner pack in OpenRegister maps "E-mailadres eigenaar" to `email` and "Pers.nr. eigenaar" to `employeeNumber`
- **WHEN** a row with those columns is imported
- **THEN** the owner SHALL be matched on the employee number and the new contact SHALL carry the e-mail address
- **AND** the row SHALL carry no warning
