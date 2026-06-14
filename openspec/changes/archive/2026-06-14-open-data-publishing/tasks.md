# Tasks — open-data-publishing

> **VERIFY-FIRST FINDING (BLOCKING for the anonymous publication leg).**
> Task 1.1 confirmed the known OpenRegister gap is REAL in the current OR
> checkout: magic-mapped objects cannot set the `@self.published` predicate.
> `published` is NOT in `MagicMapper`'s `metadataFields` allowlist (the @self
> write path drops it), and there is no per-object publish action endpoint on
> `ObjectsController` (only register/configuration GitHub-publish routes). So
> the anonymous publication + published-only read leg CANNOT be honestly built
> against this register today — it is a blocking OpenRegister dependency, not an
> app-local fix. Those tasks are deferred with this reason (no workaround, per
> ADR-022 / the proposal caveat). The buildable slice below is shipped.

## 1. Publication via the published predicate

- [x] 1.1 VERIFIED — the magic-mapped `@self.published` gap is REAL for the
  softwarecatalogus register (see finding above). The OR fix is the blocking
  dependency; no app-local visibility workaround built.
- [~] 1.2 Publish/depublish actions — DEFERRED (blocked on 1.1). There is no OR
  per-object publish action to wire to, and setting the predicate via a normal
  object save is dropped by the magic-mapper. Will land once OR exposes a
  publish path.
- [~] 1.3 PHPUnit/Newman for publish/depublish — DEFERRED (blocked on 1.2).

## 2. Open-data serialization

- [x] 2.1 Sanitized projection at the publication boundary
  (`src/utils/openDataProjection.js` `projectOpenData`): deny-lists
  RBAC/ownership metadata, internal notes, and all contact-person PII; keeps
  UUID + slug; envelopes with license (default CC0-1.0), publisher public name,
  and last-modified. This is the publication-boundary contract, ready to wire
  to the anonymous surface once the OR predicate gap (1.1) is closed.
- [x] 2.2 vitest serializer tests (8): PII never present, identifiers stable,
  reuse metadata present, `isClean()` guard. (Newman field-level assertions on
  a live anonymous response are deferred with the anonymous surface, 1.2.)
- [~] 2.3 Admin setting for the license value — DEFERRED (the projection
  accepts a configurable license param; the admin-settings UI control lands
  with the publish actions).

## 3. Govern the legacy @PublicPage endpoints

- [~] 3.1 Constrain `AanbodController::getAanbod()` / `AangebodenGebruikController`
  anonymous responses to published-only — DEFERRED (blocked on 1.1: a
  "published-only" filter needs the published predicate, which isn't settable
  on these objects yet). The projection (2.1) is the serialization half, ready.
- [x] 3.2 `GebruikController::getGebruiken()`'s anonymous empty-result envelope
  is now the explicit, documented contract (inline comment + this capability's
  `@spec` tag) — an org-scoped endpoint discloses no org data anonymously.
- [x] 3.3 Added this capability's `@spec` tag to `getGebruiken()` (gate-16
  green). The remaining tag moves on the published-only endpoints land with 3.1.

## 4. Anonymous registration intake hardening

- [x] 4.0 Schema: added the optional `registratiestatus` moderation field
  (`pending`/`active`/`rejected`) to the organisatie schema (0.3.0 → 0.4.0),
  the persisted state the pending-until-approved flow keys on.
  PHPUnit register-shape test (field present, optional, enumerated).
- [~] 4.1–4.4 Anonymous-path moderation defaults, disabled-account
  provisioning, brute-force throttling, duplicate-pending refusal, and the
  Newman/PHPUnit port — DEFERRED. This is a deep, security-critical rework of
  the existing OpenConnector anonymous intake path
  (`SoftwareCatalogueService` + handlers); it needs careful end-to-end work and
  live-throttle CI plumbing beyond this backlog-draining pass. The schema state
  it keys on (4.0) is in place. Tracked as the follow-up build.

## 5. Moderation / approval queue

- [~] 5.1–5.3 Admin approval queue UI + approve/reject service paths —
  DEFERRED (depends on 4.x). The `registratiestatus` field is the data model
  it operates on.

## 6. Verification & docs

- [~] 6.1 Live end-to-end anonymous publication verification — DEFERRED (blocked
  on 1.1).
- [x] 6.2 `docs/GOVERNMENT-FEATURES.md` F-08 downgraded from the over-stated
  "Beschikbaar" to an honest "Gedeeltelijk" with the real scope + the OR
  predicate-gap blocker noted (per the proposal: downgrade if the anonymous
  leg can't be applied).
- [x] 6.3 hydra gates green (all 24, incl. gate-16 `@spec`, route/semantic-auth
  on the touched `@PublicPage` method). vitest 72, PHPUnit 156.

## Acceptance criteria

- [~] Anonymous published read in the sanitized projection — the projection
  (serialization half) is built + tested; the anonymous published READ half is
  blocked on the OR `@self.published` gap (1.1) and deferred with that reason.
- [x] `GebruikController::getGebruiken()` discloses only the empty envelope to
  anonymous callers (governed + spec-tagged).
- [~] Anonymous registration lands pending + unpublished with disabled
  accounts — the `registratiestatus` data model is in place; the intake
  hardening + moderation queue are deferred (deep security rework).
- [x] No new bespoke public catalog-read endpoints introduced.
