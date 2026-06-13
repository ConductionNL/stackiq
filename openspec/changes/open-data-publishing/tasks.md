# Tasks — open-data-publishing

## 1. Publication via the published predicate

- [ ] 1.1 Verify the softwarecatalogus register's catalog objects can carry
  `@self.published` and that the OC publications API surfaces them when set
  (check the magic-mapped published-predicate gap, OR 2026-06-11, against
  this register FIRST — if affected, the OR fix is a blocking dependency; do
  not build an app-local visibility workaround).
- [ ] 1.2 Add publish/depublish actions (manage-permission-gated) to software
  entry and organisation detail views, wired to the OR publish/depublish
  object actions; published indicator in list + detail. NL + EN strings
  (English i18n keys).
- [ ] 1.3 PHPUnit + Newman: publish sets the predicate, depublish clears it,
  403 on forged publish without manage permission.

## 2. Open-data serialization

- [ ] 2.1 Implement the sanitized projection at the publication boundary:
  deny-list RBAC/ownership metadata, internal notes, and all contact-person
  PII; keep UUID + slug; envelope with license
  (`softwarecatalog/open_data_license`, default CC0), publisher public name,
  last-modified.
- [ ] 2.2 PHPUnit serializer tests (PII never present, identifiers stable);
  Newman field-level assertions on the anonymous response.
- [ ] 2.3 Admin setting for the license value in the settings UI.

## 3. Govern the legacy @PublicPage endpoints

- [ ] 3.1 Constrain anonymous responses of `AanbodController::getAanbod()` and
  the `AangebodenGebruikController` `@PublicPage` endpoints to published data
  in the sanitized projection; keep authenticated behaviour byte-identical
  (regression-tested) under `aanbod-listings`/`aangeboden-gebruik-api`.
- [ ] 3.2 Document `GebruikController::getGebruiken()`'s anonymous
  empty-result envelope as the explicit contract (org-scoped endpoint);
  Newman assertion that no org data leaks anonymously.
- [ ] 3.3 Move/duplicate the anonymous-read `@spec` tags on these methods to
  `openspec/changes/open-data-publishing/...` per gate-16; add supersession
  cross-reference notes to the two legacy specs.

## 4. Anonymous registration intake hardening

- [ ] 4.1 Add `registratiestatus: 'pending'` handling to the
  `SoftwareCatalogueService` anonymous path: pending default, admin
  ownership (as today), never published; strip caller-supplied
  ownership/RBAC/status/published fields before persistence.
- [ ] 4.2 Create contact-person Nextcloud accounts **disabled** in the
  anonymous path (`IUser::setEnabled(false)` at provisioning); enable only on
  approval.
- [ ] 4.3 Register the intake with the brute-force throttler (`IThrottler`)
  keyed on remote address; duplicate-pending check (same org name or contact
  email ⇒ 409).
- [ ] 4.4 Port the `test_anonymous_registration*.sh` shell harnesses to a
  Newman collection (valid registration, privileged-field stripping,
  duplicate refusal) and PHPUnit service tests; retire the shell scripts.

## 5. Moderation / approval queue

- [ ] 5.1 Admin approval queue view in settings: list pending registrations
  (org name, contacts, submitted date) with approve/reject actions.
- [ ] 5.2 Approve: status → active, enable contact accounts, registrant email
  via the existing `email-delivery` capability; Reject: remove objects, delete
  disabled accounts, log + email. Both paths audited in the NC log.
- [ ] 5.3 Playwright e2e for the UI-coverable scenarios (publish/depublish
  actions + indicator, permission-gated publish, approval queue
  approve/reject); PHPUnit for the approve/reject service paths.

## 6. Verification & docs

- [ ] 6.1 End-to-end verification on the dev instance: publish an entry, read
  it anonymously via the OC publications API, assert sanitized projection;
  submit an anonymous registration, assert invisibility until approval.
- [ ] 6.2 Update `docs/GOVERNMENT-FEATURES.md` F-08 and
  `docs/ANONYMOUS_USER_REGISTRATION_USECASE.md` to reference this spec and
  the moderation flow; align info.xml wording with the shipped scope.
- [ ] 6.3 `composer check:strict` green; hydra gates green (route-auth /
  semantic-auth on touched `@PublicPage` methods, no-admin-idor on the
  approval endpoints, spec-coverage tags).

## Acceptance criteria

- A published entry is anonymously readable through the OC publications API
  in the sanitized projection (no PII/internal fields, license + publisher
  metadata present); an unpublished entry is not retrievable anonymously.
- Legacy `@PublicPage` endpoints disclose only published data (or the
  explicit empty envelope) to anonymous callers; authenticated responses are
  unchanged.
- An anonymous registration lands pending + unpublished with disabled
  accounts, survives privileged-field injection attempts, is throttled, and
  becomes active (still unpublished) only on admin approval.
- No new bespoke public catalog-read endpoints are introduced.
