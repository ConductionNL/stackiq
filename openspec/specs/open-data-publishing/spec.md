# open-data-publishing Specification

## Purpose
TBD - created by archiving change open-data-publishing. Update Purpose after archive.
## Requirements
### Requirement: Catalog entries can be marked as published open data

Catalogue maintainers SHALL be able to publish and depublish software entries
(applicaties/voorzieningen, modules, koppelingen) and organisation profiles
from the softwarecatalog UI. Publishing SHALL set the OpenRegister
`publicatiedatum` field (and clear any `depublicatiedatum`) via a normal
`ObjectService::saveObject()`; depublishing SHALL set `depublicatiedatum` and
clear `publicatiedatum`. Anonymous (public group) visibility SHALL be governed
by the schema RBAC read rule `{group:public, match:{publicatiedatum:{$lte:$now}}}`.
The app SHALL NOT use the removed `@self.published` predicate, SHALL NOT call the
removed `ObjectService::publish()`, and SHALL NOT introduce an app-local
published flag. The action SHALL be available only to users with manage
permission on the entry.

#### Scenario: Maintainer publishes a software entry

@e2e exclude Authenticated write action on the OR object store; covered by PHPUnit PublicationServiceTest (publish sets publicatiedatum) + PublishGateRbacTest (the resulting anon visibility). The publish control is a detail-view action, not its own in-app route the e2e suite drives.

- **WHEN** a user with manage permission chooses "Publish as open data" on a software entry
- **THEN** the entry's `publicatiedatum` is set to now (or a chosen moment) and `depublicatiedatum` is cleared
- **AND** the entry's list/detail view shows a published indicator

#### Scenario: Depublication removes the entry from the public surface

@e2e exclude Authenticated write action + anonymous-visibility contract; covered by PHPUnit PublicationServiceTest (depublish clears publicatiedatum) + PublishGateRbacTest (no longer anon-visible).

- **WHEN** a maintainer depublishes a previously published entry
- **THEN** the entry's `depublicatiedatum` is set and `publicatiedatum` is cleared
- **AND** subsequent anonymous reads of the public surface no longer contain the entry

#### Scenario: User without manage permission cannot publish

@e2e exclude Forged-request authorization (IDOR) contract; covered by the PublicationController per-object ownership guard (403) — a server-side HTTP contract, asserted by Newman/PHPUnit, not reachable from the in-app router.

- **WHEN** a user without manage permission on an entry attempts the publish action
- **THEN** the action is refused (not offered in the UI; HTTP 403 on a forged request)

### Requirement: Published entries are exposed through the existing public read surface

Published entries SHALL be readable anonymously through the OpenCatalogi
publications API (`/api/{catalogSlug}` family) backed by the
`publicatiedatum<=$now` public RBAC read rule. The app SHALL NOT add bespoke
public catalog-read endpoints. Anonymous discoverability (listing, single read,
search/facets) SHALL be whatever the OC public surface provides — the app
contributes only the catalog mapping and the schema read gate.

#### Scenario: Anonymous consumer lists the published catalog

@e2e exclude Anonymous HTTP contract on the OC publications API; covered by Newman (anonymous GET list/single/negative) per the Playwright-UI-only / Newman-API convention.

- **WHEN** an unauthenticated client requests the catalog through the OpenCatalogi publications API
- **THEN** the response contains exactly the entries whose `publicatiedatum` is set and not in the future
- **AND** each entry is retrievable individually by its stable identifier

#### Scenario: Unpublished entry is not anonymously retrievable

@e2e exclude Anonymous HTTP negative contract; covered by Newman.

- **WHEN** an unauthenticated client requests an entry whose `publicatiedatum` is not set or is in the future (draft, internal, scheduled, or pending registration)
- **THEN** the entry is absent from list responses and a direct fetch returns not-found

### Requirement: Open-data serialization strips internal fields and carries reuse metadata

The published (anonymous-visible) representation of an entry SHALL be a
sanitized projection: no RBAC/ownership metadata, no internal notes, and no
contact-person PII (names, email addresses, phone numbers). It SHALL retain
stable identifiers (UUID, slug) and SHALL carry reuse metadata: a license
(default CC0, configurable via `softwarecatalog/open_data_license`), the
publishing organisation's public name, and a last-modified timestamp. The
projection SHALL be applied at the publication boundary so every consumer of
the published surface (API, federation, sitemap) sees the same shape.

#### Scenario: Published entry contains no PII or internal fields

@e2e exclude Serialization contract; covered by Newman field-level assertions on the anonymous response and PHPUnit serializer tests.

- **WHEN** an entry with contact persons and internal notes is published and read anonymously
- **THEN** the response contains no contact-person names, email addresses, or phone numbers, and no RBAC/ownership metadata
- **AND** the response retains the entry's UUID and slug

#### Scenario: Reuse metadata is present

@e2e exclude Serialization contract; covered by Newman.

- **WHEN** any published entry is read anonymously
- **THEN** the response carries the configured license, the publisher's public organisation name, and a last-modified timestamp

### Requirement: Legacy @PublicPage read endpoints serve only published data to anonymous callers

The legacy `@PublicPage` read endpoints SHALL serve only published data to
anonymous callers. The existing `@PublicPage` endpoints on `GebruikController`,
`AanbodController`, and `AangebodenGebruikController` have an explicit
anonymous contract under this capability: an unauthenticated caller receives
only published data, or the documented empty-result envelope where the
endpoint is inherently organisation-scoped (e.g. `gebruik#getGebruiken`).
Anonymous responses SHALL never include RBAC-scoped internal data. No new
`@PublicPage` endpoints SHALL be added by this capability. Authenticated
behaviour remains governed by `aanbod-listings` and `aangeboden-gebruik-api`.

#### Scenario: Anonymous aanbod listing is published-only

@e2e exclude Anonymous HTTP contract on legacy endpoints; covered by Newman (anonymous vs authenticated response diff).

- **WHEN** an unauthenticated client calls `GET /api/aanbod`
- **THEN** the response contains only published aanbod entries in the sanitized open-data projection

#### Scenario: Org-scoped gebruik endpoint keeps its explicit empty contract

@e2e exclude Anonymous HTTP contract; covered by Newman.

- **WHEN** an unauthenticated client calls `GET /api/gebruik`
- **THEN** the documented empty-result envelope is returned (HTTP 200, empty results)
- **AND** no organisation-scoped gebruik data is disclosed

### Requirement: Anonymous organisation self-registration is validated, throttled, and moderated

The anonymous organisation self-registration flow SHALL be validated,
throttled, and moderated. The flow
(`SoftwareCatalogueService` anonymous path, entering via the OpenConnector
API per `docs/ANONYMOUS_USER_REGISTRATION_USECASE.md`) is governed as a
public intake: input is validated against the organisation and contactpersoon
schemas with caller-supplied ownership/RBAC/published fields stripped; the
intake is rate-limited per remote address via Nextcloud's brute-force
throttler; duplicate pending registrations (same organisation name or contact
email) are refused. A successful registration SHALL create the organisation
with `registratiestatus: 'pending'`, owned by admin, **unpublished** and
invisible to the open-data surface and federation; Nextcloud accounts
provisioned for nested contact persons SHALL be created **disabled**.

#### Scenario: Valid anonymous registration lands in the pending queue

@e2e exclude Unauthenticated intake API flow; covered by Newman (replacing the test_anonymous_registration*.sh harnesses) and PHPUnit service tests.

- **WHEN** an anonymous client submits a valid organisation registration with nested contact persons
- **THEN** the organisation and contact objects are created with pending status, admin ownership, and no `publicatiedatum`
- **AND** the provisioned contact-person Nextcloud accounts are disabled
- **AND** the registration does not appear in any anonymous read surface

#### Scenario: Caller-controlled privileged fields are stripped

@e2e exclude Intake hardening contract; covered by Newman negative payloads and PHPUnit.

- **WHEN** an anonymous registration payload includes ownership, RBAC, status, or published fields
- **THEN** those fields are discarded server-side and the registration is created with the server-assigned pending defaults

#### Scenario: Repeated submissions are throttled

@e2e exclude Brute-force throttling; covered by PHPUnit throttler-interaction tests (live throttle resets via occ security:bruteforce:reset in CI teardown).

- **WHEN** the same remote address submits registrations beyond the throttle limit
- **THEN** further submissions are delayed/rejected by the brute-force throttler
- **AND** a duplicate of a still-pending registration (same organisation name or contact email) is refused with a conflict response

### Requirement: Admins moderate pending registrations through an approval queue

The admin settings SHALL include an approval queue listing pending anonymous
registrations (organisation name, contact persons, submitted date). Approving
a registration SHALL set the organisation to a normal active state (still
unpublished — publication is a separate maintainer decision) and enable the
provisioned contact accounts. Rejecting SHALL remove the registered objects
and delete the disabled accounts. Both outcomes SHALL be logged and SHALL
notify the registrant via the existing email-delivery capability when an
email address was provided.

#### Scenario: Admin approves a pending registration

@e2e exclude DEFERRED — the admin approval-queue UI + approve/reject service paths are the deferred intake-hardening leg (tasks 4.x/5.x); admin settings are admin-only (ADR-004), outside the in-app router the e2e suite drives. To be covered by Playwright/Newman when the queue is built.

- **WHEN** an admin approves a pending registration in the approval queue
- **THEN** the organisation's status becomes active and its contact accounts are enabled
- **AND** the organisation remains unpublished until a maintainer explicitly publishes it

#### Scenario: Admin rejects a pending registration

@e2e exclude DEFERRED — see the approve scenario; the approval queue is the deferred intake-hardening leg, admin-only and outside the e2e router.

- **WHEN** an admin rejects a pending registration
- **THEN** the registration's organisation and contact objects are removed and the disabled accounts are deleted
- **AND** the rejection is recorded in the log

