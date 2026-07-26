<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

# BIO compliance assessment

Adds the Dutch government security/privacy compliance stack on top of the
existing GEMMA compliance model: a seedable **BIO 2.0 measures** catalog,
per-application **BBN level** and **DPIA** tracking, a reference to the
organisation's **register van verwerkingen** entry, catalog filters, a
per-organisation **BIO coverage report**, and a declarative notification for
overdue DPIA reviews.

Specifications:
[`openspec/specs/bio-compliance-assessment/spec.md`](../../openspec/specs/bio-compliance-assessment/spec.md)
(new capability) and the `module-compliance-assessment` MODIFIED delta
(BIO-measure column source on the compliance matrix).

## Why this extends `compliancy`, not a new schema

BIO measure compliance is asserted through the **same** `compliancy` record
model already used for GEMMA standards (`module ↔ standaardversie`,
evidence, verified/claimed). `compliancy` gains one more optional relation,
`bioMaatregel`, parallel to `standaardversie`. A record links a module to
**exactly one** of the two relations — never both (a record carrying both is
flagged as a data-quality issue and excluded from every matrix rather than
matched to either column). This reuses the entire evidence/verified-claimed
mechanism and the matrix mapper instead of forking a parallel "BIO
assessment" object.

## The BIO measures catalog

A `bioMaatregel` object is one BIO 2.0 measure: `code`, `naam`,
`omschrijving`, `thema`, `bioVersie`, the applicable `bbnNiveau`(s), and a
`bron` reference to the published measure list. It is a publicly readable
reference catalog (`authorization.read: ["public"]`), seeded on
install/upgrade from `lib/Settings/softwarecatalogus_register.json`'s
`x-openregister.seedData.objects.bioMaatregel` array — the same
seed-and-reimport pattern the GEMMA `element` catalog already uses, so
re-running the `InitializeSettings` repair step is idempotent (upsert by
`slug`).

Browsable via **BIO measures** (`BioMaatregelen` index / `BioMaatregelDetail`
detail page), which lists every compliance claim referencing the measure —
the same "claims for this X" pattern `StandaardDetail` already uses.

## Application-level fields

`module` gains six optional fields (no `required` change, so existing
objects stay valid without migration):

| Field | Type | Notes |
|---|---|---|
| `bbnLevel` | enum `BBN1`/`BBN2`/`BBN3` | `facetable: true` — drives the catalog filter and the coverage report |
| `dpiaStatus` | enum `not required`/`required`/`executed` | |
| `dpiaDate` | date | Meaningful only when `dpiaStatus` is `executed`; not enforced at write time |
| `dpiaVolgendeBeoordeling` | date | Next DPIA review due date; drives the overdue notification |
| `dpiaDocumentRef` | string (NC Files reference) | Link-don't-store, mirroring `compliancy.bewijsReferentie` |
| `verwerkingsregisterRef` | string (URL or identifier) | Reference only — this change does not model the register van verwerkingen itself |

These render on the `ModuleDetail` page's data widget alongside the
application's other fields, and on the `Modules` index/catalog listing.

## Catalog filters

The `Modules` index page's quick filters include `BBN1`/`BBN2`/`BBN3` and a
compound **"Without DPIA (BBN2+)"** filter. The compound filter is expressed
as two bare-array IN-clauses —
`{"bbnLevel": ["BBN2", "BBN3"], "dpiaStatus": ["not required", "required"]}`
— rather than a `{dpiaStatus: {ne: "executed"}}` operator object: the
frontend's `useObjectStore.buildQueryString` JSON.stringifies plain-object
filter values into a single GET query-string value, which OpenRegister's
`MagicSearchHandler` never `json_decode`s back into an array (only
bracket-repeated `field[]=` array params survive as a real PHP array on
that path), so an operator object would silently no-op. The IN-list only
matches the two explicit "not executed" enum values — a module with
`dpiaStatus` entirely unset is not caught by this quick filter (SQL `IN()`
never matches `NULL`), though the catalog's DPIA column still shows it as
unset for a human reviewer.

## BIO coverage report

Extends the existing `ComplianceMatrixView` (`src/views/ComplianceMatrixView.vue`,
`src/utils/complianceMatrix.js`) rather than adding a new page. A radio
switch picks the column source — **Standards** (`standaardversie`) or
**BIO measures** (`bioMaatregel`) — and, in the BIO scope, each row also
shows the module's BBN level and DPIA status. An organisation picker scopes
the rows to that organisation's in-use applications (`gebruik.afnemer` →
`gebruik.module`); applications with no BBN level, DPIA data, or BIO measure
compliance are still listed, rendered as "none" / "Not set" — never omitted.
The selection (column source, selected columns, organisation) is encoded in
the URL so a comparison is shareable.

`complianceMatrix.js`'s `partitionCompliancy()` / `buildComplianceMatrix()` /
`buildOrganisationCoverage()` all take a `columnSource` parameter
(`COLUMN_SOURCE.STANDAARDVERSIE` or `COLUMN_SOURCE.BIO_MAATREGEL`) so the
BIO-measure matrix reuses the exact same mapper as the standards matrix — no
second cell-state computation exists.

## Overdue DPIA notification

`module` declares a `dpia-review-overdue` rule in the canonical
`x-openregister-notifications` dialect (ADR-031 — declarative, no bespoke
PHP notification service):

```json
{
  "trigger": {
    "type": "scheduled",
    "intervalSec": 86400,
    "filter": {
      "dpiaStatus": { "operator": "equals", "value": "executed" },
      "dpiaVolgendeBeoordeling": { "operator": "withinNext", "value": "P0D" }
    }
  },
  "channels": ["nc-notification", "email"],
  "recipients": [
    { "kind": "groups", "groups": ["software-catalog-admins"] },
    { "kind": "object-acl", "permission": "manage" }
  ],
  "subject": {
    "nl": "DPIA-beoordeling verlopen: {{naam}} (uiterlijk {{dpiaVolgendeBeoordeling}})",
    "en": "DPIA review overdue: {{naam}} (due {{dpiaVolgendeBeoordeling}})"
  }
}
```

`withinNext` with a zero-day window (`P0D`) reads as "due on or before
today" — the same trigger/filter shape already proven in this register by
`contract`'s `contract-expiry` and `gebruik`'s `phaseout-approaching` rules,
so no new engine behaviour is assumed.

## Fixed along the way

- The `Modules`/`Diensten` navigation pages were, on disk before this
  change resumed, mid-replacement: the pre-existing `type: custom`
  `FacetedCatalogIndexView` pages (proposed by the still-open
  `gemma-faceted-search` change) point at a component that was never
  registered in `customComponents.js` — a pre-existing dangling reference.
  An earlier pass of this change had silently deleted `Diensten`
  (nav entry and page) while replacing `Modules`; both are restored/kept
  here — `Modules` becomes a working `type: index` page (needed for the
  BBN/DPIA fields and filters), `Diensten` is left exactly as it was
  (broken, pending `gemma-faceted-search`, out of scope for this change).
- `organisation-merge`'s Organisaties index filter had the same
  `{"$ne": ...}` defect this change's own compound filter would have
  introduced (see docs/features/organisation-merge.md) — fixed to the
  working IN-list shape while investigating the operator dialect.

## Out of scope

- Automated BIO evidence collection.
- ISMS workflows beyond the DPIA review date.
- NIS2 incident reporting, audit certification flows (ENSIA, DigiD).
- Modelling the register van verwerkingen itself (reference only).
- Computing `bbnLevel` or `dpiaStatus` automatically — both are user-entered.

## Screenshots

Not captured in this change — the implementing session had no live
Nextcloud instance to drive Playwright against without touching the shared
dev environment (out of bounds for this change). Follow-up: capture the
Modules BBN/DPIA fields, the BIO measures catalog, the BIO coverage report,
and the DPIA filter per ADR-010 once verified against a running instance.
