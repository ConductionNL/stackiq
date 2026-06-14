---
kind: feature
depends_on: []
---

# softwarecatalog — open data publishing

## Why

Open data publishing is the second headline claim of the app description with
no spec coverage: `appinfo/info.xml` promises "**Open data publishing** —
Automatically publish your software catalog for transparency and reuse", and
`docs/GOVERNMENT-FEATURES.md` F-08 claims *Beschikbaar* ("Open data publicatie
via API"). The reality is partial and unspecced
(see `FEATURE-REEVALUATION-2026-06-11/softwarecatalog.md`):

- `@PublicPage` read endpoints exist on `GebruikController`,
  `AanbodController`, and `AangebodenGebruikController` — but they are specced
  only as HTTP contracts (`aanbod-listings`, `aangeboden-gebruik-api`), with
  no publication capability behind them (what is published, published-state,
  anonymous discoverability). Notably `GebruikController::getGebruiken()`
  returns an **empty result** for anonymous callers, so the "public" surface
  publishes nothing.
- The **anonymous organisation self-registration** flow is implemented
  (`lib/Service/SoftwareCatalogueService.php` anonymous path + admin-ownership
  assignment, documented in `docs/ANONYMOUS_USER_REGISTRATION_USECASE.md`,
  shell-script-tested) and security-sensitive — a public path that creates
  organisations, contact persons, and Nextcloud user accounts — yet it is
  invisible to the spec/gate system. Adjacent specs
  (`softwarecatalogue-orchestration` `handleNewOrganization`, `email-delivery`)
  touch it but nothing specs the public entry point, its auth posture, or its
  abuse resistance.

## Design constraint: route via the published-predicate abstraction

Per ADR-022, the app consumes the OpenRegister/OpenCatalogi publication
abstraction instead of building bespoke public endpoints:

- "Published as open data" = the OpenRegister published predicate
  (`@self.published`) on the object, surfaced through the OpenCatalogi public
  read surface (`/api/{catalogSlug}` publications API). No per-app
  publication pipeline, no app-local published flag.
- The existing softwarecatalog `@PublicPage` endpoints are **folded under this
  capability** as the legacy read surface: their anonymous behaviour is
  specced (and constrained to published data only), so they stop being
  orphan HTTP contracts.
- This is the same surface `federated-catalog-sync` reads from — one
  publication model serves both anonymous reuse and federation.

## What Changes

- Catalogue maintainers can mark software entries and organisation profiles
  as published open data (set/unset the published predicate from the
  softwarecatalog UI, permission-gated).
- Published entries are exposed anonymously through the existing OpenCatalogi
  public read surface with an open-data-friendly serialization: stable
  identifiers, no internal/RBAC/contact-PII fields, license + publisher
  metadata on the response.
- The existing `@PublicPage` read endpoints (gebruik/aanbod/aangeboden-gebruik)
  get a specced auth posture: anonymous callers receive published data only
  (or an explicit empty contract), never RBAC-scoped internal data.
- The anonymous organisation self-registration flow gets a spec: public entry
  point, validation, rate limiting, and a moderation/approval queue —
  anonymously registered organisations are NOT published (and not visible in
  the open-data surface) until approved.

## Capabilities

### New Capabilities

- `open-data-publishing`: published-predicate-based open data publication of
  the software catalog — publish/depublish controls, anonymous read surface
  + serialization, governed legacy `@PublicPage` endpoints, and the
  moderated anonymous organisation self-registration intake.

## Impact

- **Modified:** `lib/Controller/GebruikController.php`,
  `lib/Controller/AanbodController.php`,
  `lib/Controller/AangebodenGebruikController.php` (anonymous-path behaviour
  constrained to published data; `@spec` tags moved to this capability),
  `lib/Service/SoftwareCatalogueService.php` (registration moderation status),
  catalog entry UI (`src/`) for publish/depublish + pending-approval badge,
  admin settings (approval queue view).
- **New:** publish/depublish action wiring to the OR published predicate;
  registration approval queue (admin lists pending anonymous registrations,
  approves/rejects); brute-force/rate-limit protection on the anonymous
  intake.
- **Spec moves:** the anonymous-read scenarios of `aanbod-listings` and
  `aangeboden-gebruik-api` are superseded by this capability's requirements
  (those specs keep the authenticated HTTP contracts).
- **Relation to `federated-catalog-sync`:** federation reads the same
  published surface; nothing here is federation-specific.

## Caveats

- **Magic-mapped published-predicate gap (OR, 2026-06-11):** magic-mapped
  objects currently cannot set `@self.published`, which would make published
  entries invisible to the anonymous surface. Verify against the
  softwarecatalogus register early (task 1.2); if affected, the OR fix is a
  blocking dependency — do not build an app-local visibility workaround.
- **Anonymous intake transport:** the current flow enters via the
  OpenConnector API (per `docs/ANONYMOUS_USER_REGISTRATION_USECASE.md`), not a
  softwarecatalog route. The spec governs the flow wherever it enters; if the
  intake is given a first-party softwarecatalog route, it must satisfy the
  same requirements (posture, throttle, moderation).
- **User-account creation on registration** stays as implemented (contact
  persons get provisioned NC accounts) but accounts created from an anonymous
  registration MUST remain disabled until the registration is approved —
  this is the security-critical delta over today's behaviour.
- F-08 stays over-stated until applied; otherwise downgrade to *Gepland*.
