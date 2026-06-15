# Proposal: softwarecatalog-contacts-to-nc

kind: refactor — cites ADR-022 (apps-consume-or-abstractions), ADR-019
(integration-registry), ADR-012 (deduplication), and **cross-app interface
contract #2** (people/organisations/contacts → Nextcloud addressbook, keyed by
`contactsUid`, reusing the `ContactSyncService`/contacts-sync pattern).

**Depends on:** none. (Coordinates with — but does not block on — the fleet-wide
contacts-to-NC programme. The canonical `ContactSyncService` reference
implementation already exists in `pipelinq/lib/Service/ContactSyncService.php`
and `zaakafhandelapp/lib/Service/KlantContactSyncService.php`.)

## Summary

Softwarecatalog's top-level navigation currently exposes **Organisations**
(`Organisaties`) and **Contacts** (`Contactpersonen`) as first-class
transactional sections backed by two app-local OpenRegister schemas —
`organisatie` and `contactpersoon` — in the `voorzieningen` register. These two
schemas store raw **person/organisation identity** (names, e-mail, phone,
website, logo, CBS/KvK-style code, job function), which per cross-app contract
\#2 is the responsibility of the **Nextcloud addressbook**
(`OCP\Contacts\IManager`), not of an app-local party schema.

This change moves person/org **identity** into Nextcloud Contacts and reduces
softwarecatalog's footprint to only the **catalog-specific relationship/role**
that genuinely belongs to it: which contact is the maintainer/vendor/registrant
of which software product, in which catalog role, under which participation.
Each relationship record is keyed by **`contactsUid`** (a reference into the NC
addressbook) instead of embedding identity. The identity fields (`voornaam`,
`achternaam`, `e-mailadres`, `naam`, `website`, `logo`, `telefoonnummer`, …)
are owned by the NC Contact; softwarecatalog reads them through the contacts
abstraction, never duplicating them.

The top-level **Organisations** and **Contacts** menu entries are removed from
the primary navigation; their identity-listing function is served by the
Nextcloud Contacts app. The remaining catalog-specific relationship records
(organisation participation, contact roles on products) are surfaced **in
context** on the software product / organisation detail views and grouped
under the existing **Settings** area where they are administered, not as
top-level identity browsers. All existing routes stay **routable** for deep
links during the transition.

A **fail-safe, idempotent** repair step (`lib/Repair/MigrateContactsToNc.php`)
resolves or creates an NC Contact for every existing `organisatie` and
`contactpersoon` object — matching on **e-mail** (and on **CBS/KvK code** for
organisations) — writes the resulting `contactsUid` onto the kept relationship
record, and **never deletes the source object until it has been migrated**.

## Deduplication rationale (ADR-012)

Phase 0 of `tasks.md` proves this is **de-duplication, not new capability**:

- The capability "store a person/organisation identity" is **already owned** by
  the Nextcloud addressbook (`OCP\Contacts\IManager`). The `organisatie` and
  `contactpersoon` schemas are a **second, parallel implementation** of that
  same capability inside softwarecatalog — exactly the duplication ADR-012 and
  contract #2 forbid.
- The reusable mechanism to bridge an app object to an NC Contact
  (`searchContacts` / `importContact` / `syncToContacts(objectType,id) →
  contactsUid`) **already exists** as `ContactSyncService` in pipelinq and
  `KlantContactSyncService` in zaakafhandelapp. This change **reuses that
  pattern** rather than inventing a softwarecatalog-specific identity store.
- Softwarecatalog already runs an `OrganizationContactSyncJob` /
  `OrganisationSyncService` that syncs organisations and contacts between SC
  objects and OR entities — confirming the identity is currently **owned
  app-locally** and must be redirected to the canonical addressbook, not
  re-implemented.

What is **NOT** duplicated and therefore **stays** in softwarecatalog: the
catalog-specific *relationship/role* — `rollen` (catalog roles), `functie`
(role at the org in catalog context), product/vendor/maintainer links,
organisation participation (`deelnames`/`deelnemers`), and the VNG access
`status`/`type` classification used for RBAC (`geregistreerdDoor`). These are
kept as thin records **keyed by `contactsUid`**, never carrying identity.

The external `Softwarecatalogus/` VNG client repo is explicitly **out of
scope** — only the `softwarecatalog` app is touched.
