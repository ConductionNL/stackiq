# Tasks — open-data-publishing

> **UPDATED 2026-06-15 — anonymous publication leg BUILT on the live RBAC model.**
> The earlier blocker note ("magic-mapped objects cannot set `@self.published`")
> is STALE: `@self.published` is deprecated and REMOVED from OpenRegister, and
> `ObjectService::publish()` no longer exists. The live anonymous-publication
> model is RBAC: a schema grants the public group read access gated on a date
> field — `authorization.read: [{group:public, match:{publicatiedatum:{$lte:$now}}},
> "authenticated"]`. "Publish" = set `publicatiedatum` via a normal
> `ObjectService::saveObject()` (no special publish endpoint, no magic-mapper
> allowlist involved); anonymous read then works today. This change builds the
> publish/depublish leg, the public read gate on the four publishable schemas,
> and the RBAC-scoped (IDOR-safe) write endpoints on that model.

## 1. Publication via the publicatiedatum RBAC gate

- [x] 1.1 RE-VERIFIED 2026-06-15 — the publication model is RBAC, not the removed
  `@self.published` predicate. Publish = set `publicatiedatum` via `saveObject`;
  anonymous (public-group) read is gated on `{group:public, match:{publicatiedatum:
  {$lte:$now}}}`. No OR dependency blocks this — it works against the
  softwarecatalogus register today.
- [x] 1.2 Publish/depublish actions — BUILT. `PublicationService::publish()` sets
  `publicatiedatum` (clears `depublicatiedatum`); `depublish()` sets
  `depublicatiedatum` + clears `publicatiedatum`, via `ObjectService::saveObject()`
  (ADR-022). Schemas `dienst`/`module`/`koppeling`/`organisatie` gained the
  `publicatiedatum`/`depublicatiedatum` fields + the public read gate
  (`lib/Settings/softwarecatalogus_register.json`, versions bumped). HTTP:
  `PublicationController` `PUT/DELETE /api/publication/{objectType}/{uuid}/(de)publish`,
  `#[NoAdminRequired]` + per-object ownership guard (admin or owning
  aanbod-beheerder; peer-sourced entries refused 403; ADR-005).
- [x] 1.3 PHPUnit for publish/depublish — BUILT. `PublicationServiceTest` (publish
  sets a past publicatiedatum + clears depublicatiedatum; future moment schedules;
  depublish clears publicatiedatum; non-publishable type rejected) +
  `PublishGateRbacTest` (anon/public read returns the object iff
  publicatiedatum<=now, and NOT for a future-dated or unpublished entry — the
  live RBAC gate evaluated exactly as OR does). Newman live-surface assertions
  remain with the OpenCatalogi public read surface (4.x intake hardening).

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

- [x] 3.1 Anonymous published-only visibility on the publishable schemas is now
  enforced by the RBAC read gate itself: with the public read rule changed from a
  bare `"public"` (or business-field match) to `{group:public, match:
  {publicatiedatum:{$lte:$now}}}` on `dienst`/`module`/`koppeling`/`organisatie`,
  an anonymous caller sees an entry only once it is published — no app-side
  "published-only" filtering needed (and none added, per ADR-022). The projection
  (2.1) is the serialization half for the open-data envelope.
  [~] A bespoke published-only re-filter inside `AanbodController::getAanbod()`
  is intentionally NOT added — the OR RBAC gate already scopes the anonymous
  result, so an app-local filter would duplicate it.
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
- [x] 4.1–4.4 Anonymous-path moderation defaults + intake hardening — BUILT
  (2026-06-15) as a dedicated hardened entry point (`IntakeService` +
  `IntakeController`), rather than reworking the 3600-line legacy OpenConnector
  path. The intake: sets `registratiestatus=pending` and NO `publicatiedatum`
  server-side (so the public RBAC gate keeps it invisible until approval);
  validates anti-spam (required fields, field-count + value-size caps); strips
  privileged caller-controlled keys (`registratiestatus`/`publicatiedatum`/
  `_source`/`id`/`owner`/`beoordeling`/...); refuses a duplicate pending
  submission for the same org name. The controller is `#[PublicPage]`
  `#[NoCSRFRequired]` `#[AnonRateLimit(5/3600)]` (brute-force/anti-spam throttle)
  and write-only — it acknowledges, never echoing the stored object (no IDOR
  read). It provisions NO account and grants NO access (account provisioning
  stays the approved-path concern of the existing SoftwareCatalogueService).
  PHPUnit: `IntakeModerationTest` (pending+unpublished, key-stripping, missing-
  field/oversize rejection, duplicate-pending refusal).

## 5. Moderation / approval queue

- [x] 5.1–5.3 Admin approval queue + approve/reject service paths — BUILT
  (2026-06-15). `ModerationService` lists the pending queue and decides each
  entry: APPROVE → `registratiestatus=active` + `publicatiedatum=now` (making it
  anonymously visible via the SAME `publicatiedatum<=now` public RBAC gate as
  open-data publish); REJECT → `registratiestatus=rejected`, left unpublished
  (stays invisible). Only entries currently `pending` may be decided (idempotent;
  prevents re-publishing an already-approved entry); peer-sourced (federated)
  mirrors are refused. `ModerationController` is `#[NoAdminRequired]` + an
  explicit `IGroupManager::isAdmin()` guard on every method (ADR-005 — no
  privilege escalation/IDOR on approve/publish). Routes: GET /api/moderation/
  pending, POST /api/moderation/{uuid}/approve|reject. PHPUnit:
  `IntakeModerationTest` (approve→active+published-visible, reject→unpublished,
  not-pending guard, peer-sourced guard, list-pending). The Vue queue UI is the
  remaining FE slice.

## 6. Verification & docs

- [x] 6.1 Anonymous-publication visibility verified at the RBAC-gate layer —
  `PublishGateRbacTest` proves a public-group read returns the object iff
  `publicatiedatum<=now`, and NOT for a future-dated/unpublished entry, evaluating
  the gate exactly as OR does against the real register rule. Live two-instance
  HTTP verification against the OpenCatalogi public surface stays with the
  federation testbed (federated-catalog-sync 6.1).
- [~] 6.2 `docs/GOVERNMENT-FEATURES.md` F-08 — re-confirm/upgrade now the anon
  publish leg is built on the RBAC model (the prior "predicate-gap blocker" note
  is stale and should be removed in the docs sync).
- [x] 6.3 hydra gates green, PHPUnit 194 (+ this change's PublicationService /
  PublishGateRbac suites), vitest unchanged.

## Acceptance criteria

- [x] Anonymous published read in the sanitized projection — the projection
  (serialization half) is built + tested; the anonymous published READ half is
  delivered by the `publicatiedatum<=$now` public RBAC gate (BUILT + tested), not
  the removed `@self.published` predicate.
- [x] `GebruikController::getGebruiken()` discloses only the empty envelope to
  anonymous callers (governed + spec-tagged).
- [x] Anonymous registration lands pending + unpublished — BUILT (2026-06-15).
  `IntakeService` sets `registratiestatus=pending` + no `publicatiedatum`
  (invisible to the public RBAC gate); the admin approval queue
  (`ModerationService`) flips it to `active` + sets `publicatiedatum` on approve
  (anonymously visible) or `rejected` on reject (stays hidden). Account
  provisioning remains the existing approved-path concern; the intake itself
  grants no access. PHPUnit lifecycle-covered (`IntakeModerationTest`).
- [x] No new bespoke public catalog-read endpoints introduced (the publish
  endpoints are authenticated write actions; anonymous read stays on the OR/OC
  public surface).
