# Tasks — Move softwarecatalog Contacts/Organisations identity to Nextcloud

## Phase 0: Deduplication Check (ADR-012)

- [ ] Confirm the Nextcloud addressbook (`OCP\Contacts\IManager`) is the
      canonical store for person/org identity (cross-app contract #2).
- [ ] Confirm `pipelinq/lib/Service/ContactSyncService.php` (and
      `zaakafhandelapp/lib/Service/KlantContactSyncService.php`) already provide
      the reusable bridge (`searchContacts` / `importContact` /
      `syncToContacts → contactsUid`) — reuse, do not reinvent.
- [ ] Confirm `lib/Settings/softwarecatalogus_register.json` `organisatie` +
      `contactpersoon` schemas currently embed raw identity (names, e-mail,
      phone, website, logo, `cbsCode`) — the duplication to retire.
- [ ] Confirm the catalog-specific relationship/role fields to KEEP (`rollen`,
      `functie`, product/vendor links, `deelnames`/`deelnemers`, VNG
      `status`/`type`, `geregistreerdDoor`).
- [ ] Confirm the external `Softwarecatalogus/` VNG repo is OUT of scope.

## Phase 1: Contact-sync service (reuse ADR-019 pattern)

- [ ] Add `lib/Service/SoftwareCatalogContactSyncService.php` modeled on
      pipelinq `ContactSyncService` (`OCP\Contacts\IManager`): `searchContacts`,
      `importContact`, `syncToContacts(objectType, objectId) → ?contactsUid`,
      `findContactByUid`. No bespoke HTTP (ADR-019).
- [ ] Resolve identity reads (name/e-mail/logo/website) through the contacts
      abstraction; remove app-local identity-write paths from
      `ContactpersonenController` / `OrganisatieService` (ADR-022 — no redundant
      identity CRUD).

## Phase 2: Schema reduction (identity → NC, relationship keyed by contactsUid)

- [ ] In `lib/Settings/softwarecatalogus_register.json` (or an ADR-037 fragment
      under `lib/Settings/register.d/`): add required string `contactsUid` to
      `contactpersoon` and `organisatie`.
- [ ] Drop identity properties from `contactpersoon` (`voornaam`,
      `tussenvoegsel`, `achternaam`, `e-mailadres`, `telefoonnummer`,
      `username`); keep `functie`, `rollen`, `organisatie`, `notificaties`.
- [ ] Drop identity properties from `organisatie` (`naam`, `beschrijvingKort`,
      `beschrijvingLang`, `logo`, `e-mailadres`, `website`, `telefoonnummer`,
      `cbsCode`); keep `type`, `status`, `samenwerkingtype`, `contactpersonen`,
      `deelnames`, `deelnemers`, `geregistreerdDoor`.

## Phase 3: Nav + pages (remove top-level identity nav, keep routes)

- [ ] Remove `menu[]` entries `Organisaties` (order 20) and `Contactpersonen`
      (order 30) from `src/manifest.json` primary navigation.
- [ ] Keep `pages[]` `Organisaties`, `Contactpersonen`, `ContactpersoonDetail`
      routable (deep links); relabel as catalog relationship views.
- [ ] Update `Contactpersonen` index `config.columns` from identity columns
      (`voornaam`, `achternaam`, `email`, `telefoonnummer`) to relationship
      columns (`contactsUid`, `functie`, `rollen`, `organisatie`).
- [ ] Surface contact-role and organisation-participation administration from
      the existing **Settings** area and in-context on product/organisation
      detail (docudesk IA model), not as top-level transactional nav.

## Phase 4: Idempotent fail-safe migration

- [ ] Add `lib/Repair/MigrateContactsToNc.php`, registered in
      `Application::register`, run on `occ upgrade`.
- [ ] For each `organisatie`/`contactpersoon` object
      (`setRegister('voorzieningen')->setSchema(...)->findAll([])`): resolve NC
      Contact by e-mail (and `cbsCode` for orgs); else create from identity
      fields; write `contactsUid` onto the kept record.
- [ ] Guard on `contactsUid` presence → re-run is a no-op (idempotent).
- [ ] Never delete/retire the source object's identity fields until
      `contactsUid` is successfully set (fail-safe, no data loss).
- [ ] Use POSITIONAL args for OCP calls (`getAppValue`, `IManager`) per the
      fleet occ gotcha.
- [ ] Repoint `lib/BackgroundJob/OrganizationContactSyncJob.php` to refresh
      `contactsUid` links rather than mirror identity into OR.

## Phase 5: Validate

- [ ] `cd softwarecatalog && openspec validate softwarecatalog-contacts-to-nc --strict` passes (exit 0).
