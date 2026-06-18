---
status: done
---

# softwarecatalog-contacts-to-nc Specification

## Purpose
Moves person and organisation identity out of app-local schemas and into the Nextcloud addressbook as the source of record, reducing the contactpersoon and organisatie schemas to catalog-specific relationship records keyed by a required contactsUid. A ContactSyncService bridges to Nextcloud Contacts via IManager, an idempotent fail-safe repair step migrates existing identity, and the top-level Organisations and Contacts navigation is removed while their pages stay routable for deep links.
## Requirements
### Requirement: REQ-SCNC-001 — The system SHALL store person and organisation identity in the Nextcloud addressbook, not in app-local schemas

The system SHALL treat every person and organisation identity (name, e-mail,
phone, website, logo, CBS/KvK code, job function as identity) as a Nextcloud
Contact owned by `OCP\Contacts\IManager`, and SHALL NOT retain an app-local
party/identity schema for raw identity, per cross-app interface contract #2 and
ADR-012.

#### Scenario: Identity lives in Nextcloud Contacts

- **GIVEN** a person or organisation known to softwarecatalog
- **WHEN** its identity fields (name, e-mail, phone, website, logo, code) are read
- **THEN** they are resolved from the Nextcloud addressbook via `OCP\Contacts\IManager`
- **AND** no softwarecatalog schema stores those identity fields as the source of record

### Requirement: REQ-SCNC-002 — The system SHALL keep only catalog-specific relationship/role records keyed by contactsUid

The system SHALL reduce the `contactpersoon` and `organisatie` schemas to
catalog-specific relationship/role records that each carry a required
`contactsUid` reference into the NC addressbook, retaining only non-identity
catalog fields and dropping all embedded identity properties.

#### Scenario: Contactpersoon reduced to a role record

- **GIVEN** the `contactpersoon` schema
- **WHEN** the change is applied
- **THEN** it carries a required `contactsUid` and keeps `functie`, `rollen`, `organisatie`, `notificaties`
- **AND** it no longer defines `voornaam`, `tussenvoegsel`, `achternaam`, `e-mailadres`, `telefoonnummer`, or `username`

#### Scenario: Organisatie reduced to a relationship record

- **GIVEN** the `organisatie` schema
- **WHEN** the change is applied
- **THEN** it carries a required `contactsUid` and keeps `type`, `status`, `samenwerkingtype`, `contactpersonen`, `deelnames`, `deelnemers`, `geregistreerdDoor`
- **AND** it no longer defines `naam`, `beschrijvingKort`, `beschrijvingLang`, `logo`, `e-mailadres`, `website`, `telefoonnummer`, or `cbsCode`

### Requirement: REQ-SCNC-003 — The system SHALL bridge to Nextcloud Contacts through the reused ContactSyncService pattern

The system SHALL provide a `SoftwareCatalogContactSyncService` modeled on the
canonical `pipelinq/lib/Service/ContactSyncService.php`, exposing
`searchContacts`, `importContact`, `syncToContacts(objectType, objectId)`
returning a `contactsUid`, and `findContactByUid`, using `OCP\Contacts\IManager`
and never bespoke HTTP, per ADR-019 and ADR-022.

#### Scenario: A relationship record is linked to a Contact via the sync service

- **GIVEN** a catalog relationship record without a `contactsUid`
- **WHEN** `syncToContacts(objectType, objectId)` is invoked
- **THEN** the service resolves or creates a matching NC Contact through `IManager`
- **AND** returns its `contactsUid`, which is written onto the relationship record

#### Scenario: No redundant identity CRUD controller is introduced

- **GIVEN** the contacts-to-NC abstraction
- **WHEN** identity is created or edited
- **THEN** the write is performed against the Nextcloud Contacts app via `IManager`
- **AND** softwarecatalog adds no controller that re-implements identity CRUD on top of the OR object store

### Requirement: REQ-SCNC-004 — The system SHALL remove the top-level Organisations and Contacts navigation while keeping their pages routable

The system SHALL remove the `Organisaties` and `Contactpersonen` entries from
the `src/manifest.json` primary `menu[]`, and SHALL keep the `Organisaties`,
`Contactpersonen`, and `ContactpersoonDetail` `pages[]` routable for deep links,
relabelled as catalog relationship views administered from the Settings area and
in product/organisation context.

#### Scenario: Identity browsing is no longer top-level nav

- **GIVEN** the softwarecatalog primary navigation
- **WHEN** the change is applied
- **THEN** the `Organisaties` (order 20) and `Contactpersonen` (order 30) menu entries are absent from the primary `menu[]`

#### Scenario: Existing routes still resolve

- **GIVEN** a deep link to `/organisaties`, `/contactpersonen`, or `/contactpersonen/:id`
- **WHEN** the link is opened after the change
- **THEN** the corresponding page still resolves (route preserved in `pages[]`)
- **AND** the `Contactpersonen` index columns show relationship fields (`contactsUid`, `functie`, `rollen`, `organisatie`) rather than identity columns

### Requirement: REQ-SCNC-005 — The system SHALL migrate existing identity to Nextcloud Contacts idempotently and fail-safe

The system SHALL provide an idempotent repair step
(`lib/Repair/MigrateContactsToNc.php`) that, for every existing `organisatie`
and `contactpersoon` object, resolves an NC Contact by e-mail (and by `cbsCode`
for organisations) or creates one, writes the resulting `contactsUid` onto the
kept relationship record, and SHALL NOT remove a source object's identity until
its `contactsUid` has been successfully set.

#### Scenario: Resolve an existing Contact on re-run

- **GIVEN** an `organisatie`/`contactpersoon` whose `contactsUid` is already set
- **WHEN** the repair step runs again
- **THEN** it performs no change for that record (idempotent no-op)

#### Scenario: Create a Contact when none matches

- **GIVEN** a legacy `contactpersoon` with an e-mail that matches no NC Contact
- **WHEN** the repair step runs
- **THEN** a new NC Contact is created from the identity fields via `IManager`
- **AND** its `contactsUid` is written onto the relationship record before any identity field is cleared

#### Scenario: Never lose data on failure

- **GIVEN** the repair step fails to set `contactsUid` for a record
- **WHEN** the step completes
- **THEN** that source object and its identity fields are left intact for a later re-run

