# Design: softwarecatalog-contacts-to-nc

## Problem

Two top-level nav sections and two OR schemas in softwarecatalog store raw
person/organisation **identity**, duplicating the Nextcloud addressbook
(contract #2, ADR-012). Identity must move to NC Contacts; only the
catalog-specific *relationship/role* may remain, keyed by `contactsUid`.

## What exists today (Phase 0 inventory)

**Nav — `src/manifest.json` `menu[]`:**

| id              | label         | route           | order | disposition |
|-----------------|---------------|-----------------|-------|-------------|
| `Organisaties`  | Organisations | `Organisaties`  | 20    | **remove from primary nav** |
| `Contactpersonen` | Contacts    | `Contactpersonen` | 30  | **remove from primary nav** |

**Pages — `src/manifest.json` `pages[]` (all stay routable):**

| page id              | route                  | type   | schema          | disposition |
|----------------------|------------------------|--------|-----------------|-------------|
| `Organisaties`       | `/organisaties`        | custom | (OrganisatieIndexView) | identity browsing → NC Contacts; page kept routable, relabelled to catalog relationships |
| `Contactpersonen`    | `/contactpersonen`     | index  | `contactpersoon`| identity columns dropped; becomes a `contactsUid`-keyed role list; kept routable |
| `ContactpersoonDetail` | `/contactpersonen/:id` | detail | `contactpersoon`| identity widgets read from NC Contact; role fields kept; kept routable |

**Schemas — `lib/Settings/softwarecatalogus_register.json` `components.schemas`:**

| schema          | identity properties (→ NC Contact)                                                                 | relationship/role properties (→ keep, keyed by `contactsUid`) |
|-----------------|---------------------------------------------------------------------------------------------------|----------------------------------------------------------------|
| `contactpersoon`| `voornaam`, `tussenvoegsel`, `achternaam`, `e-mailadres`, `telefoonnummer`, `username`             | `functie`, `rollen`, `organisatie` (link), `notificaties` (catalog notif prefs) |
| `organisatie`   | `naam`, `beschrijvingKort`, `beschrijvingLang`, `logo`, `e-mailadres`, `website`, `telefoonnummer`, `cbsCode` | `type`, `status` (VNG access), `samenwerkingtype`, `contactpersonen` (links), `deelnames`, `deelnemers`, `geregistreerdDoor` (RBAC partytype) |

**Existing sync surface (confirms identity is app-owned today):**
`lib/BackgroundJob/OrganizationContactSyncJob.php`,
`lib/Service/OrganisatieService.php`, `lib/Service/ContactpersoonService.php`,
`lib/Service/SoftwareCatalogue/ContactPersonHandler.php`.

**Reusable bridge to reuse (do not reinvent):**
`pipelinq/lib/Service/ContactSyncService.php` (canonical:
`searchContacts(query)`, `importContact(uid, addressBookKey, type, clientId)`,
`syncToContacts(objectType, objectId) → ?contactsUid`,
`findContactByUid(uid)`), and `zaakafhandelapp/.../KlantContactSyncService.php`.

## Key decisions

1. **Identity → NC Contact; relationship → softwarecatalog, keyed by
   `contactsUid`.** We do **not** keep a local party/identity schema. The
   `contactpersoon` and `organisatie` schemas are reduced to relationship/role
   records: every identity property is dropped and a single `contactsUid`
   reference (string, into `OCP\Contacts\IManager`) is added. Reads of name /
   e-mail / logo / website resolve through the contacts abstraction.

2. **Reuse `ContactSyncService`, ADR-019 for cross-app calls.** Softwarecatalog
   gains a `SoftwareCatalogContactSyncService` modeled on pipelinq's service
   (same method names/semantics, `OCP\Contacts\IManager`). No bespoke HTTP; any
   cross-app contact resolution goes through the abstraction / integration
   registry per ADR-019.

3. **No redundant CRUD controllers (ADR-022).** Relationship records remain on
   the OR object store via manifest pages; identity CRUD is delegated entirely
   to the Nextcloud Contacts app. The existing
   `ContactpersonenController`/`OrganisatieService` identity-write paths are
   retired in favour of `IManager` writes through the sync service. The
   `OrganizationContactSyncJob` is repointed to keep `contactsUid` links fresh
   instead of mirroring identity into OR.

4. **Remove top-level nav, keep routes.** `Organisaties` and `Contactpersonen`
   `menu[]` entries are removed from primary navigation. Their pages stay in
   `pages[]` (routable for deep links) and are relabelled as catalog
   relationship views; administration is reachable from the **Settings** area
   and in-context on product/organisation detail (the docudesk IA model:
   identity/config is not top-level transactional nav).

5. **Match strategy for migration.** Resolve an existing NC Contact by **e-mail**
   first; for organisations also by **CBS/KvK code** (`cbsCode`). On no match,
   **create** a Contact from the identity fields. Always idempotent: re-running
   resolves the same Contact and is a no-op once `contactsUid` is set.

## Affected nav entries / pages / schemas (exact)

- `src/manifest.json` → remove `menu[]` entries `Organisaties` (order 20) and
  `Contactpersonen` (order 30); keep `pages[]` `Organisaties`,
  `Contactpersonen`, `ContactpersoonDetail` routable; drop identity columns
  (`voornaam`, `achternaam`, `email`, `telefoonnummer`) from the
  `Contactpersonen` index `config.columns`, replacing with `contactsUid`,
  `functie`, `rollen`, `organisatie`.
- `lib/Settings/softwarecatalogus_register.json` (or ADR-037 fragment under
  `lib/Settings/register.d/`) → `contactpersoon` and `organisatie` schemas lose
  identity properties and gain `contactsUid` (required string ref).
- New `lib/Service/SoftwareCatalogContactSyncService.php` (mirrors pipelinq).
- New `lib/Repair/MigrateContactsToNc.php` (idempotent, fail-safe).
- Repointed `lib/BackgroundJob/OrganizationContactSyncJob.php` (link refresh,
  not identity mirror).

## Migration / rollout

- **Repair step** `MigrateContactsToNc` (registered in `Application::register`)
  runs on `occ upgrade`. For each `organisatie` and `contactpersoon` object in
  the `voorzieningen` register (read via
  `setRegister('voorzieningen')->setSchema('Organisatie'|'Contactpersoon')->findAll([])`):
  1. resolve an NC Contact by e-mail (and `cbsCode` for orgs);
  2. else create one from the identity fields;
  3. write `contactsUid` onto the kept relationship record;
  4. only after a successful write, clear/retire the embedded identity fields.
  - **Never deletes** the source object before its `contactsUid` is set.
  - Re-running is a **no-op** for already-migrated records (guarded on
    `contactsUid` presence) — idempotent per the fleet repair convention.
  - Uses **positional args** for OCP service calls (`getAppValue`, `IManager`)
    per the fleet gotcha (named args FATAL on occ).

## Alternatives considered

- **Keep a local `organisatie`/`contactpersoon` identity schema and "sync"
  to NC.** Rejected — that is the current state and is exactly the parallel
  identity store ADR-012 / contract #2 forbid. Sync mirrors identity instead
  of owning it in one place.
- **Map SC organisation to an NC "group" instead of a Contact.** Rejected — an
  organisation here is an external party identity (vendor/municipality), not an
  NC group; the addressbook (org-type Contact, `kind=org`) is the correct home.
- **Delete the pages outright.** Rejected — deep links and the catalog
  relationship views must keep working; pages stay routable, only nav entries
  and identity fields go.

## Risks

- **No matchable e-mail / CBS code** on some legacy objects → repair creates a
  fresh NC Contact (acceptable; never silently drops). Logged for review.
- **Duplicate NC Contacts** if the same party exists under multiple e-mails →
  mitigated by CBS/KvK match for orgs; residual dedupe is an addressbook
  housekeeping concern, not a data-loss risk.
- **RBAC `geregistreerdDoor` partytype** must stay on the kept relationship
  record (not the Contact) so access control is unaffected — explicitly
  preserved.
