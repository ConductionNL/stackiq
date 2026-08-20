<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

# Catalog ratings

Turns the previously dormant `beoordeeling` (review) schema into a working,
**moderated** ratings-and-testimonials feature for modules and services —
and closes the authorization hole it shipped with (world-readable, no
create/update/delete rules, no attributable author). See
[VNG Softwarecatalogus issue #49](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/49)
and softwarecatalog#375.

Specification: [`openspec/specs/catalog-ratings/spec.md`](../../openspec/specs/catalog-ratings/spec.md).

## Why it existed but didn't work

The `beoordeeling` schema was part of the published VNG data model but had
never been wired up: `authorization` was `{"read": ["public"]}` with **no**
create/update/delete rules at all, and no author or owning-organisation
binding. Shipping a ratings UI on top of that as-is would have been
world-readable with undefined write rules and no accountability. This
change fixes the schema first, then builds the feature on top of the fixed
schema.

## Submitting a review

From a module's detail page, a signed-in catalog user clicks **"Write a
review"** (`ReviewsPanel.vue`, a body widget on `ModuleDetail`), which opens
`SubmitReviewModal.vue`: a title, a 1-10 rating, and a testimonial. There is
**no "your name" field** — the author is always the authenticated Nextcloud
session, bound server-side by `ReviewService`; anything the client sends for
`auteur` is discarded.

```
POST /apps/softwarecatalog/api/reviews
{ "review": {"naam": "Solid intake flow", "waardering": 9, "beschrijvingLang": "..."},
  "subjectType": "module", "subjectId": "<module uuid>" }
```

Every submission lands `status: "pending"` — it is not yet visible to
anyone outside the catalog's internal groups.

## Moderation

Reviews are approved/rejected through the **same** `ModerationQueue.vue`
component already used for anonymous organisation registration, now
parameterised by a `type` prop (`organisatie`, default, or `beoordeeling`).
A second instance renders in **Settings → Review moderation**, backed by the
same admin-gated `ModerationController`/`ModerationService`
(`#[AuthorizedAdminSetting]`), selected via `?type=beoordeeling`. Approving
sets `status: "approved"`; rejecting sets `status: "rejected"` and the
review stays hidden.

## Fail-closed public read

`beoordeeling.authorization.read` is no longer an unconditional `["public"]`
grant. It is `[{"group":"public","match":{"status":"approved"}}, <internal
catalog groups>]` — unauthenticated readers only ever see `approved`
reviews; `pending`/`rejected` reviews are invisible to them. This is
declared in a new **fragment**, `lib/Settings/register.d/catalog-ratings.json`
(ADR-037) — the shipped monolith `softwarecatalogus_register.json` is never
edited directly (an edit there is a silent no-op on installed instances).

A subtlety in the fragment merge itself was fixed as part of closing this
hole: the generic register-fragment merge concatenates list values (correct
for most schema properties), which would have left the dangerous bare
`"public"` entry in place even after the fragment "added" a narrower rule.
`SettingsService::deepMergeConfig()` now replaces (rather than
concatenates) list values within any `authorization` block specifically, so
the fragment genuinely removes the wide-open base rule.

## Aggregate rating

Module (and, once a `DienstDetail` page exists — see Known gaps below,
dienst) detail pages show an average rating + review count, computed by
`ReviewAggregateService` from **approved reviews only**. A module with zero
approved reviews shows a null average / zero count rather than an error.

## Authorization summary

| Action | Who |
|---|---|
| Read approved reviews | Anyone (public) |
| Read pending/rejected reviews | Internal catalog groups + the review's own author (owner privilege) |
| Create | Authenticated catalog-user groups (never anonymous) |
| Update | The review's author (owner privilege) or an org-scoped admin group |
| Delete | `software-catalog-admins` only, or the review's author (owner privilege) |

## Known gaps / follow-ups

- **No `DienstDetail` page yet.** The submit/aggregate backend is
  subject-type-agnostic (`module` or `dienst`), and `beoordeeling` already
  supports a `diensten` relation, but the softwarecatalog manifest has no
  `/diensten/:id` detail route today (`Diensten` is a `type: custom` faceted
  index with no per-row detail page) — that is a pre-existing gap unrelated
  to the authorization fix this change makes. Filed as a follow-up to wire
  `ReviewsPanel` onto that page once it exists.
- **Residual direct-API risk.** A user already in an authorized `create`
  group could bypass `ReviewController` and call OpenRegister's generic
  object API directly, setting `auteur`/`status` themselves on that path.
  This is an existing, accepted trust boundary shared by every other schema
  in this app; the public read gate is enforced independently of which path
  wrote the object.

## Screenshots

Not captured in this change — per this repo's convention (see
`organisation-merge.md`), Playwright screenshot capture against a live
instance was out of bounds for this session (no live Nextcloud instance
without touching the shared dev environment). Follow-up: capture the
"Write a review" flow, the aggregate rating panel, and the review moderation
queue per ADR-010 once verified against a running instance.
