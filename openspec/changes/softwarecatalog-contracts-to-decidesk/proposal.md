---
kind: refactor
depends_on: []
---

# Proposal: softwarecatalog-contracts-to-decidesk

kind: refactor — delegates a decision flow to the canonical hub
(cites **ADR-019** integration-registry, **ADR-012** deduplication,
cross-app interface contract #1 "Decisions / contracts / approvals → decidesk").

## Summary

softwarecatalog owns a `contract` schema and a top-level **Contracts** nav
group (manifest pages `Contracten` index + `ContractDetail` detail). The
contract schema carries a `status` enum `Actief / Verlopen / In onderhandeling`
("Active / Expired / In negotiation"). The transition **`In onderhandeling`
→ `Actief`** is an *approval / sign-off decision*: a human or governance body
deciding the contract terms are agreed and the contract may take effect. The
same is true for a renewal (re-approving an expiring contract).

Per cross-app interface contract #1, **decidesk is the canonical decision
authority** and its hub is merged: a consuming app raises a Decision via the
ADR-019 integration registry (`POST /api/v1/decisions` with
`decisionType: contract` / `contract-renewal` + provenance fields) and
consumes the **outcome** (`GET /api/v1/decisions/{id}/outcome`, or a
push subscription), keeping its own domain record as a **projection** of that
outcome. softwarecatalog must NOT own a parallel approval/sign-off surface.

This change draws the boundary precisely:

- **Stays in softwarecatalog (contract RECORD / catalog metadata):** the
  `contract` schema and its fields (`dienst`, `gebruik`, `startDatum`,
  `eindDatum`, `contractNummer`, `contractType`, `kosten`, `kostenPeriode`,
  `contactpersoonAanbieder/Gebruiker`, `documentReferentie`, `opmerkingen`),
  the `Contracten` index + `ContractDetail` pages, and the catalog need to
  know *which contract governs which software/`gebruik`/`dienst`*. The catalog
  must federate and export contracts with the rest of the GEMMA/VNG data model.
- **Delegated to decidesk (contract APPROVAL / sign-off / renewal DECISION):**
  the act of approving an `In onderhandeling` contract into `Actief`, and the
  act of approving a renewal of an expiring contract. softwarecatalog raises a
  decidesk Decision and **projects** the outcome back onto the contract record;
  it never flips `status` to `Actief` on its own authority.

The `status` field becomes a **projection** of the decidesk outcome for the
approval transitions, plus the date-driven `Actief → Verlopen` expiry
transition (which is NOT a decision and stays catalog-local).

## Depends on

- decidesk decision hub (MERGED): `POST /api/v1/decisions`,
  `GET /api/v1/decisions/{id}/outcome`, `POST /api/v1/decisions/{id}/subscriptions`,
  `decisionType` values `contract` and `contract-renewal`.
- ADR-019 integration registry (the cross-app call transport).
- softwarecatalog `contract` schema + `Contracten`/`ContractDetail` manifest
  pages (the existing `contract-administration` change owns the record CRUD;
  this change is additive on the approval seam — see Phase 0).

## Deduplication rationale (ADR-012)

The in-flight `contract-administration` change consciously keeps **contract
catalog metadata** local (and rejected a shillinq CLM bookkeeping dependency —
correctly, per cross-app contract #3 that is a *bookkeeping* boundary, not a
*decision* boundary). This change does NOT duplicate or contradict it: it
leaves the record, the schema, the pages, the CRUD, the expiry tracking, and
the cost rollups exactly where `contract-administration` puts them. It touches
**only the approval/sign-off/renewal decision seam** — a capability neither
softwarecatalog nor `contract-administration` owns today (Phase 0 confirms
there is no contract-approval controller, service, or state-machine in
`lib/`). Building a contract-approval workflow inside softwarecatalog would
duplicate decidesk's merged decision hub (ADR-012 violation); delegating it
via ADR-019 is the dedup-correct path. The contract `status` field is reused,
not replaced — it becomes a projection of the decidesk outcome for the two
approval transitions.
