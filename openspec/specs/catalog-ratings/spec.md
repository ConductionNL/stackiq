# catalog-ratings Specification

**Status**: done
**Scope**: stackiq
**OpenSpec changes**:
- catalog-ratings (archived 2026-07-24)

## Purpose
Turns the dormant `beoordeeling` (review) schema into a working, moderated
ratings-and-testimonials feature for modules and services, while closing the
authorization hole it shipped with (world-readable, no create/update/delete
rules, no attributable author). Reviews are submitted by authenticated
catalog users, land pending, and only become publicly visible once approved
through the same admin moderation pattern already used for anonymous
organisation registration.

## Requirements
### Requirement: Public read access to a review MUST be restricted to approved reviews
The `beoordeeling` schema MUST grant the `public` (unauthenticated) group
read access only to objects whose `status` property equals `approved`. The
schema MUST NOT grant an unconditional `public` read rule.

#### Scenario: An approved review is publicly readable
- **GIVEN** a `beoordeeling` object with `status: "approved"`
- **WHEN** an unauthenticated client reads it (directly, or via the module/dienst aggregate endpoint)
- **THEN** the review is returned

#### Scenario: A pending review is not publicly readable
- **GIVEN** a `beoordeeling` object with `status: "pending"`
- **WHEN** an unauthenticated client attempts to read it, either directly or via a list request
- **THEN** the review is absent from list responses and a direct fetch returns not-found or forbidden

#### Scenario: A rejected review is not publicly readable
- **GIVEN** a `beoordeeling` object with `status: "rejected"`
- **WHEN** an unauthenticated client attempts to read it
- **THEN** the review is absent from list responses and a direct fetch returns not-found or forbidden

### Requirement: The register fragment merge MUST replace authorization rule lists, not concatenate them
`SettingsService::deepMergeConfig()` MUST treat any key literally named
`authorization` as replace-on-merge for its entire subtree (including list
values), rather than the general-purpose list-concatenation behavior used
for every other key. A register fragment narrowing a schema's authorization
MUST fully remove a dangerous base entry (such as a bare `"public"` read
grant), not append a narrower rule alongside it.

#### Scenario: An authorization list in a fragment replaces the base list
- **GIVEN** a base schema authorization block with `read: ["public"]`
- **AND** a register fragment overlaying that schema's `authorization.read` with `[{"group":"public","match":{"status":"approved"}}]`
- **WHEN** `deepMergeConfig()` merges the fragment onto the base
- **THEN** the merged `authorization.read` MUST be exactly `[{"group":"public","match":{"status":"approved"}}]`
- **AND** MUST NOT contain the bare string `"public"`

#### Scenario: Non-authorization list keys still concatenate (unchanged regression)
- **GIVEN** a base schema with `required: ["naam"]`
- **AND** a register fragment overlaying that schema's `required` with `["waardering"]`
- **WHEN** `deepMergeConfig()` merges the fragment onto the base
- **THEN** the merged `required` MUST be `["naam", "waardering"]` (concatenated, not replaced)

### Requirement: Creating, updating, or deleting a review MUST be governed by explicit authorization rules
The `beoordeeling` schema MUST declare explicit `authorization.create`,
`authorization.update`, and `authorization.delete` rules. `create` MUST be
limited to authenticated catalog-user groups (never `public`). `update` and
`delete` MUST NOT grant the full breadth of catalog-user groups; `delete`
MUST be restricted to catalog-admin groups only.

#### Scenario: An authenticated catalog user can submit a review
- **GIVEN** a user in the `software-catalog-users` group
- **WHEN** the user submits a review through `POST /api/reviews`
- **THEN** the review is created with `status: "pending"`

#### Scenario: An unauthenticated request cannot create a review
- **GIVEN** no active Nextcloud session
- **WHEN** a request is made to `POST /api/reviews`
- **THEN** the request is rejected (401/403) and no `beoordeeling` object is created

### Requirement: The submitting user's identity MUST be bound server-side and MUST NOT be accepted from client input
`ReviewService::submit()` MUST discard any client-supplied `auteur`,
`status`, `id`, `uuid`, `_owner`, `_organisation`, and `_source` keys before
persisting, and MUST set `auteur` from the authenticated
`IUserSession::getUser()` display name.

#### Scenario: The stored review carries the authenticated user's name
- **GIVEN** a user "Jan Jansen" is authenticated
- **WHEN** they submit a review with no `auteur` field in the payload
- **THEN** the persisted object's `auteur` equals "Jan Jansen"

#### Scenario: A client-supplied author is ignored
- **GIVEN** a user "Jan Jansen" is authenticated
- **WHEN** they submit a review with `auteur: "Someone Else"` in the payload
- **THEN** the persisted object's `auteur` equals "Jan Jansen", not "Someone Else"

### Requirement: Only the review's author or an organisation-scoped admin MAY update it; unrelated users MUST be refused
A user MUST be able to update their own review by virtue of OpenRegister's
object-owner privilege (no bespoke authorization-code check required). A
user who is neither the review's owner nor a member of a group granted in
`beoordeeling.authorization.update` MUST NOT be able to update the review.

#### Scenario: The author edits their own review
- **GIVEN** a review created by user "Jan Jansen" (`_owner: "jan.jansen"`)
- **WHEN** "jan.jansen" updates the review's `beschrijvingLang`
- **THEN** the update succeeds

#### Scenario: A non-author, non-admin user cannot edit another user's review
- **GIVEN** a review created by user "Jan Jansen" (`_owner: "jan.jansen"`)
- **AND** user "Piet Peters" is authenticated, is not the owner, and is not in any `beoordeeling.authorization.update` group
- **WHEN** "piet.peters" attempts to update the review
- **THEN** the update is refused (403)

### Requirement: Review deletion MUST be restricted to catalog admins (plus the owner)
`beoordeeling.authorization.delete` MUST NOT include the broad catalog-user
groups other schemas in this register grant delete to; only catalog-admin
groups are listed (owner deletion remains available via the OpenRegister
owner privilege, independent of this list).

#### Scenario: A regular catalog user cannot delete another user's review
- **GIVEN** a review created by user "Jan Jansen"
- **AND** user "Piet Peters" is in `software-catalog-users` but not `software-catalog-admins` and is not the owner
- **WHEN** "piet.peters" attempts to delete the review
- **THEN** the delete is refused (403)

### Requirement: A newly submitted review MUST require moderation approval before becoming public
Every review created through `ReviewService::submit()` MUST be created with
`status: "pending"`, regardless of any client-supplied value. Only an
explicit admin approval decision MAY transition it to `status: "approved"`.

#### Scenario: A submission lands pending and is not yet public
- **WHEN** an authenticated user submits a valid review
- **THEN** the stored object has `status: "pending"`
- **AND** it is not returned to unauthenticated readers

#### Scenario: Admin approval makes the review public
- **GIVEN** a review with `status: "pending"`
- **WHEN** an admin approves it through the moderation queue
- **THEN** the review's `status` becomes `"approved"`
- **AND** it is now returned to unauthenticated readers

#### Scenario: Admin rejection keeps the review hidden
- **GIVEN** a review with `status: "pending"`
- **WHEN** an admin rejects it through the moderation queue
- **THEN** the review's `status` becomes `"rejected"`
- **AND** it remains absent from unauthenticated read results

### Requirement: Review moderation MUST reuse the existing moderation queue mechanism, not a second one
`ModerationService`/`ModerationController` MUST support moderating
`beoordeeling` objects (`status` field, `approved`/`rejected` values) through
the same `listPending()`/`approve()`/`reject()` methods and the same
admin-gated endpoints already used for `organisatie` (`registratiestatus`
field, `active`/`rejected` values), selected by an explicit type parameter
that defaults to the existing `organisatie` behavior. The existing
`ModerationQueue.vue` component MUST be reused (parameterised), not
duplicated, for the review moderation UI.

#### Scenario: An admin moderates pending reviews through the existing queue UI
- **GIVEN** at least one `beoordeeling` object with `status: "pending"`
- **WHEN** an admin opens the review moderation section in Settings
- **THEN** the pending review appears in a `ModerationQueue.vue` instance with Approve/Reject actions

#### Scenario: A non-admin cannot reach the review moderation endpoints
- **GIVEN** a user who is not a Nextcloud admin
- **WHEN** they call `GET /api/moderation/pending?type=beoordeeling`
- **THEN** the request is rejected before the controller body runs (Nextcloud's `AuthorizedAdminSetting` middleware)

#### Scenario: The default (unparameterised) organisatie moderation path is unchanged
- **GIVEN** an admin approves a pending `organisatie` registration via `POST /api/moderation/{uuid}/approve` with no `type` query parameter
- **WHEN** the request is processed
- **THEN** the behavior is identical to the pre-existing `organisatie`/`registratiestatus`/`active` flow (unaffected by the `beoordeeling` generalisation)

### Requirement: Module and dienst detail pages MUST display an aggregate rating computed only from approved reviews
The aggregate (average `waardering` and count) MUST be computed only from
`beoordeeling` objects with `status: "approved"` for the given module or
dienst. When there are zero approved reviews for the subject, the aggregate
MUST report a count of `0` and a null average rather than erroring.

#### Scenario: Aggregate reflects only approved reviews
- **GIVEN** a module has one approved review (`waardering: 8`) and one pending review (`waardering: 2`)
- **WHEN** the aggregate is requested for that module
- **THEN** the average is `8` and the count is `1`

#### Scenario: Aggregate with no approved reviews
- **GIVEN** a module has zero approved reviews
- **WHEN** the aggregate is requested for that module
- **THEN** the average is `null` and the count is `0`

### Requirement: The Reviews index MUST display columns that exist on the beoordeeling schema
The `Reviews` index page's `columns` configuration MUST reference only
properties actually declared on the `beoordeeling` schema (`src/manifest.json`).

#### Scenario: Every configured column resolves to a real schema property
- **GIVEN** the `Reviews` index page's `config.columns` array
- **WHEN** each column name is checked against `beoordeeling`'s declared properties
- **THEN** every column name (`naam`, `auteur`, `waardering`, `status`) is a real property
- **AND** none of the previously dead column names (`titel`, `score`, `datum`) remain

### Requirement: A register fragment MUST overlay a schema the register declares (REQ-CR-020)

Every schema key in a `lib/Settings/register.d/*.json` fragment MUST already
exist in `softwarecatalogus_register.json`.

A fragment is an OVERLAY and the merge unions by key, so a fragment naming a
schema the base does not declare does not fail. It quietly creates one, and the
overlay then applies perfectly, to nothing. There is no error, no warning and no
failing test, because a schema nobody declares is also a schema nobody reads.

This is not hypothetical. `catalog-ratings.json` keyed its overlay `assessment`,
which is the schema's TITLE; its slug is `software-review`
(`beoordeeling` → `software-review`, `RenameDutchSchemaSlugs`). The
status-gated public read it exists to install therefore never reached the
schema holding the reviews, which kept the base `read: ["public"]` that
REQ-CR-001 forbids. Every pending and rejected review was publicly readable,
which is the exact hole this capability was written to close.

#### Scenario: No fragment overlays a schema the register does not declare

- **WHEN** every `register.d` fragment's schema keys are compared against the
  register's declared schemas
- **THEN** every key is present in the register.

### Requirement: The gated read MUST be asserted on the merged register, not on a synthetic base (REQ-CR-021)

The test for REQ-CR-001 MUST merge the REAL fragments onto the REAL register
JSON and assert on the schema the review services name, resolved from their own
constants rather than from a literal.

A test that builds its own base keyed to match the fragment proves the merge
function and nothing else. `DeepMergeAuthorizationTest` did exactly that, and
passed throughout the period the overlay was landing on a schema that did not
exist.

The test MUST also assert that the BASE register alone is NOT gated. Without
that control it cannot distinguish "the overlay applied" from "the base was
already correct", and it would keep passing if the fragment stopped being
merged at all.

#### Scenario: The merged register gates the review schema

- **GIVEN** the register JSON with every `register.d` fragment merged over it
- **WHEN** the `authorization.read` of the schema named by
  `ReviewService::REVIEW_TYPE` is inspected
- **THEN** it contains `{"group": "public", "match": {"status": "approved"}}`
- **AND** it does not contain the bare string `"public"`.

#### Scenario: The base register on its own is not gated

- **WHEN** the register JSON is inspected WITHOUT its fragments
- **THEN** the review schema's `authorization.read` contains the bare
  `"public"` that the fragment removes.
