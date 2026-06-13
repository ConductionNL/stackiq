---
kind: feature
depends_on: []
---

# softwarecatalog — contract administration

## Why

The README promises "**Contract Administration** — Link contracts to
applications and track license agreements" and lists `Contract` in the
data-model table. The in-flight `softwarecatalog-notifications` change already
declares a `contract-expiry` notification rule — **shipped disabled** because
no capability owns the contract lifecycle and the date-window filter is
unconfirmed. The reality today:

- the `contract` schema **exists** in
  `lib/Settings/softwarecatalogus_register.json` (`dienst`, `gebruik`,
  `startDatum`, `eindDatum`, `contractNummer`, `contractType` SLA/Licentie/
  Onderhoud, `kosten`, `kostenPeriode`, `contactpersoonAanbieder/Gebruiker`,
  `documentReferentie`, `status` Actief/Verlopen/In onderhandeling);
- generic manifest pages `Contracten` (index) and `ContractDetail` exist in
  `src/manifest.json`, but the index `columns` reference fields that are not
  on the schema (`naam`, `leverancier`, `ingangsdatum`, `einddatum`) — drift
  that renders empty columns;
- **no spec covers any of it**: no expiry tracking, no status maintenance, no
  contract visibility on the application it belongs to, no cost view.

This change specs contract administration as a first-class catalog capability,
fixes the manifest drift, and unblocks enabling the `contract-expiry`
notification rule (see `FEATURE-REEVALUATION-2026-06-11/softwarecatalog.md`,
MISSING table + recommendation 2).

## Design decision: SC-local contract metadata, NOT a shillinq CLM integration

Shillinq just gained a `contract-lifecycle-management` change (generic CLM:
obligations, NC Tasks bridge, committed-vs-invoiced spend rollups). We
consciously do **not** make softwarecatalog depend on it:

1. **The `contract` schema is part of the GEMMA/VNG Softwarecatalogus data
   model** — it describes the procurement relationship behind a `gebruik`
   (which organisation uses which module under which terms). It must federate
   and export with the rest of the catalog; it cannot live in another app's
   register.
2. **Different audience, different question.** SC answers the portfolio
   question ("which contracts back this application, when do they expire,
   what do they cost per year"); shillinq CLM answers the financial-management
   question (obligations, spend vs budget, renewals chains). SC needs the
   first; municipalities without shillinq must still get it.
3. **Dependency hygiene.** Shillinq CLM is an unmerged change chained on
   other unmerged bookkeeping changes (PurchaseOrder/APInvoice schemas).
   Coupling an SC README promise + the notification unblock to that chain is
   wrong sequencing.

The boundary is explicit: SC contract administration carries **catalog
metadata only** — no obligations, no spend engine, no renewal automation.
An organisation running both apps can later link a shillinq `Contract` to an
SC `contract`/`gebruik` via a reference (follow-up integration change,
out of scope here; cf. shillinq's own `specializationReference` pattern).

## What Changes

- Spec the contract capability on the **existing** `contract` schema; CRUD
  stays on the OR object store via the manifest `index`/`detail` pages — no
  app-local contract controllers (ADR-022).
- Fix the `Contracten` manifest index columns to the real schema fields
  (`contractNummer`, `contractType`, `startDatum`, `eindDatum`, `kosten`,
  `status`).
- Surface contracts in context: the application (gebruik/module) detail view
  gains a Contracts tab listing linked contracts with expiry dates.
- Expiry tracking: an "expiring soon" filter/view (window configurable,
  default 90 days) and automatic status maintenance — `status` flips to
  `Verlopen` when `eindDatum` passes (scheduled, server-side).
- Enable the `contract-expiry` notification rule from
  `softwarecatalog-notifications` once the date-window filter is confirmed,
  with the window aligned to this capability.
- Annualised cost view: derive cost-per-year from `kosten` × `kostenPeriode`
  (Maandelijks ×12 / Jaarlijks ×1 / Eenmalig excluded) and total it per
  application and per organisation — a light TCO answer, declaratively
  (OR facets/aggregation), no PHP report service.
- Contract documents are **NC Files references** via `documentReferentie`
  (link, don't store).

## Capabilities

### New Capabilities

- `contract-administration`: catalog-scoped contract management on the
  existing `contract` schema — in-context visibility on applications, expiry
  tracking with automated status maintenance and notifications, annualised
  cost overview, and NC Files document linking.

## Impact

- **Changed:** `src/manifest.json` (`Contracten` columns fix, expiring-soon
  filter, Contracts tab on application detail, cost columns).
- **Changed:** `lib/Settings/softwarecatalogus_register.json` — enable the
  `contract-expiry` rule (flip `enabled` once date-window support is
  confirmed); no structural schema changes.
- **New:** `lib/BackgroundJob/ContractStatusJob.php` (daily TimedJob flipping
  `Actief` → `Verlopen` past `eindDatum`) — only if the OR lifecycle engine
  cannot express the date-driven transition declaratively; check first.
- **Relation to `softwarecatalog-notifications`:** that change ships the
  `contract-expiry` rule disabled; this change owns confirming the
  `scheduled` date-window filter and enabling it. No `depends_on` — the rule
  block exists either way; enabling is gated on the engine check (task 3.2).
- **Relation to shillinq `contract-lifecycle-management`:** none at runtime;
  see the design decision above.

## Caveats

- **`scheduled` date-window filtering** ("eindDatum within N days") must be
  confirmed in the OR notification engine before the rule is enabled — same
  caveat the notifications change carries. If unsupported, file the OR engine
  gap and keep the rule disabled (blocker note, no per-app cron workaround
  for notifications).
- `contactpersoonAanbieder`/`contactpersoonGebruiker` are nested name/email
  objects, not NC uids — contract notifications reach admins + manage-ACL
  only (documented limitation, unchanged from the notifications change).
- `Eenmalig` (one-off) costs are excluded from the annualised figure and
  shown separately — annualising a one-off is a lie.
