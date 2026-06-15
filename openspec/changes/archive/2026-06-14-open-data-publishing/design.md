# Design: open-data-publishing

## Decision 1 — One publication model: the published predicate

"Open data" is not a new flag, table, or endpoint family. An entry is open
data iff its OpenRegister published predicate (`@self.published`) is set and
not depublished. Consequences:

- Publish/depublish from the softwarecatalog UI calls the OR
  publish/depublish object actions — no app-local state.
- The anonymous read surface is the OpenCatalogi publications API
  (`/api/{catalogSlug}`), which already enforces published-only visibility,
  CORS, and anonymous access. Softwarecatalog adds zero public routes for
  catalog reads.
- `federated-catalog-sync` consumes the identical surface; publishing once
  serves citizens, integrators, and peer instances.

Rejected alternative: bespoke `@PublicPage` open-data endpoints per schema —
that is exactly the unowned surface that produced today's gap (public
endpoints that return empty or RBAC-leaky data), and ADR-022 forbids
re-implementing the OR/OC abstraction per app.

## Decision 2 — Open-data serialization is a projection, not a schema fork

The public representation of an entry is the published object minus a
deny-list of internal fields:

- strip RBAC/ownership metadata, internal notes, and **all contact-person
  PII** (names, emails, phone numbers — organisations expose only a generic
  public contact field if explicitly published);
- keep stable identifiers (UUID, slug) so external consumers can link;
- envelope carries `license` (default CC0, app-config
  `softwarecatalog/open_data_license`), publisher (the owning organisation's
  public name), and last-modified timestamp.

Implemented as a serializer applied at the publication boundary (what gets
published into the OC-visible representation), not as response-time filtering
in controllers — so every consumer of the published surface (API, federation,
sitemap) sees the same sanitized projection.

## Decision 3 — Legacy @PublicPage endpoints are governed, not expanded

`GebruikController::getGebruiken()`, `AanbodController::getAanbod()`, and the
`AangebodenGebruikController` endpoints keep `@PublicPage` for backward
compatibility, but their anonymous contract becomes explicit:

- anonymous caller ⇒ published data only (or the documented empty-result
  contract where the endpoint is inherently org-scoped, e.g. gebruik);
- authenticated behaviour is unchanged and stays specced in
  `aanbod-listings` / `aangeboden-gebruik-api`;
- no NEW `@PublicPage` endpoints are added by this change.

This folds the orphan HTTP contracts under a capability with an owner, which
is what gate-16/19 traceability needs.

## Decision 4 — Anonymous registration is an intake queue, not a publish path

The implemented anonymous organisation self-registration
(`SoftwareCatalogueService` anonymous path) is retained but bounded:

- **Posture:** public, unauthenticated intake; input validated against the
  organisation + contactpersoon schemas; payload size capped; no caller-
  controlled ownership, RBAC, or published fields are accepted (server strips
  them).
- **Moderation:** registrations land with `registratiestatus: 'pending'`,
  owned by admin (as today), **unpublished**, and invisible to the open-data
  surface and federation. An admin approval queue lists pending
  registrations; approve ⇒ organisation becomes a normal (still unpublished)
  catalog entry + provisioned contact accounts are enabled; reject ⇒ objects
  removed and accounts deleted.
- **Accounts:** NC accounts provisioned for nested contact persons are
  created **disabled** and only enabled on approval — closing the current
  hole where an anonymous POST mints live accounts.
- **Throttling:** the intake is registered with Nextcloud's brute-force
  throttler (`IThrottler`, `#[AnonRateLimit]`-class protection) keyed on
  remote address, plus a duplicate-suppression check (same org name/email
  pending ⇒ 409).

## Decision 5 — Spec supersession

The anonymous-read scenarios currently living in `aanbod-listings` and
`aangeboden-gebruik-api` (whole-spec `@e2e exclude`, HTTP-contract-only) are
superseded by this capability for the anonymous dimension; those specs retain
the authenticated contracts. On archive, `openspec/specs/open-data-publishing/`
becomes the canonical home and the two legacy specs get a cross-reference
note.

## Out of scope

- DCAT/data.overheid.nl harvesting feeds — a follow-up once the published
  surface exists (the serializer is designed so a DCAT projection can be
  added without reshaping objects).
- CSV/tabular export (tracked separately as `catalog-tabular-export` in the
  re-evaluation; likely satisfied by OR generic export).
- Any change to the OpenConnector transport of the registration intake.
