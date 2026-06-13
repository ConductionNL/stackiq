# Design: contract-administration

## Decision 1 — SC-local catalog metadata, not shillinq CLM

Evaluated against shillinq's `contract-lifecycle-management` change (generic
CLM with obligations, spend rollups, NC Tasks bridge). Rejected as a
dependency:

- the `contract` schema is part of the GEMMA/VNG Softwarecatalogus data model
  and already lives in `softwarecatalogus_register.json` — it federates and
  exports with the catalog;
- SC's question is portfolio-level (expiry, annual cost per application),
  shillinq's is financial-management-level (obligations, spend vs budget);
- shillinq CLM is unmerged and chained on other unmerged bookkeeping changes.

Hard boundary: SC contract administration adds **no** obligation tracking, no
spend engine, no renewal automation. A future integration change may link a
shillinq `Contract` to an SC `contract`/`gebruik` by reference; neither app
duplicates the other's fields.

## Decision 2 — Reuse the existing schema; CRUD via manifest pages

No new schema, no structural field changes, no app-local contract
controllers. The existing `contract` schema + OR object store + the manifest
`index`/`detail` renderer are the whole CRUD surface (ADR-022). The only
manifest work is fixing drift (the index `columns` name fields the schema
does not have) and adding the in-context views.

## Decision 3 — Expiry status is maintained server-side, derived from `eindDatum`

`status` (`Actief`/`Verlopen`/`In onderhandeling`) is user-set today and
silently goes stale. The transition `Actief → Verlopen` when `eindDatum`
passes becomes automatic and server-side:

1. **Preferred:** declarative date-driven transition in the OR lifecycle
   engine, if expressible (check first — same engine family as the
   `scheduled` notification trigger).
2. **Fallback:** `ContractStatusJob` TimedJob (daily) in the app, registered
   via `IRegistrationContext::registerJob` (fleet gotcha: invalid
   registration = job never runs; verify with `occ background-job:list`).

The job/engine only performs `Actief → Verlopen` on a past `eindDatum`. It
never touches `In onderhandeling` and never reverses a manual `Verlopen`.
"Expiring soon" is **not** a status — it is a query (eindDatum within the
window), so there is no third state to keep consistent.

## Decision 4 — Notification rule stays in the notifications change; enabling moves here

`softwarecatalog-notifications` declares `contract-expiry` disabled because
date-window filter support ("eindDatum within 30 days") is unconfirmed. This
change owns: confirm engine support → set the window filter → flip
`enabled: true`. If the engine cannot express the window, file the OR gap and
leave the rule disabled — no per-app notification cron (ADR-031).

## Decision 5 — Annualised cost is a derived view, not stored data

`kostenPerJaar = kosten × {Maandelijks: 12, Jaarlijks: 1}`; `Eenmalig` is
excluded from the annual figure and listed separately. Computed at render /
via OR aggregation — never persisted (a stored duplicate of a derivable
number drifts). Totals per application (all contracts on its `gebruik`) and
per organisation (all contracts of its gebruiken) answer the basic TCO
question without a cost-management subsystem.

## Decision 6 — Documents are NC Files links

`documentReferentie` carries an NC Files reference (link, don't store).
Full-text search over contract documents is Nextcloud's. No contract PDF is
ever stored in the register.

## Out of scope

- Obligation/deliverable tracking, renewal chains, spend-vs-budget — shillinq
  CLM territory.
- Procurement workflow (tendering, EU thresholds) — not a catalog concern.
- Supplier-side contact uid resolution for notifications — blocked on nested
  contactpersoon objects (documented in `softwarecatalog-notifications`).
