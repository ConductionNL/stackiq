# Tasks — softwarecatalog-contracts-to-decidesk

## Phase 0: Deduplication Check (ADR-012)

- [x] 0.1 Confirm decidesk decision hub is reachable on the target instance:
  `POST /api/v1/decisions`, `GET /api/v1/decisions/{id}/outcome`,
  `POST /api/v1/decisions/{id}/subscriptions`, with `decisionType` values
  `contract` and `contract-renewal` present. (Cross-app contract #1.)
- [x] 0.2 Confirm softwarecatalog has NO existing contract-approval capability
  to duplicate: grep `lib/Controller`/`lib/Service` for a contract
  status/approval state-machine — Phase 0 found only `AangebodenGebruik`
  `StatusTransitionValidator`/`GebruikStatusHandler` (offered-use, unrelated),
  and no contract controller/service. Record the result.
- [x] 0.3 Confirm this change does NOT contradict the in-flight
  `contract-administration` change: the record, schema, pages, CRUD, expiry
  tracking, and cost rollups stay where `contract-administration` puts them;
  this change touches only the approval/sign-off/renewal decision seam.
- [x] 0.4 Confirm the integration call goes through the ADR-019 integration
  registry (no hard-coded decidesk HTTP URL).

## Phase 1: Schema projection fields (ADR-037 fragment)

- [x] 1.1 In `lib/Settings/register.d/*.json` add to the `contract` schema:
  `approvalDecisionId` (string) and `approvalState`
  (enum `none / pending / approved / rejected`, default `none`). Do NOT change
  the existing `status` enum (`Actief / Verlopen / In onderhandeling`).
- [x] 1.2 Document in the schema that `approvalState` is a PROJECTION of the
  decidesk outcome and `status` remains the catalog lifecycle field; the
  `In onderhandeling → Actief` transition is driven by an `approved` outcome,
  never by SC on its own authority.

## Phase 2: Raise the decision (ADR-019, fail closed)

- [x] 2.1 Add a thin integration call (ADR-019 registry, resolve the decidesk
  endpoint by capability — no hard-coded URL) that on user "Submit for
  approval" POSTs `/api/v1/decisions` with `decisionType: contract`,
  `sourceApp: softwarecatalog`, `subjectRegister: voorzieningen_register`,
  `subjectSchema: contract`, `subjectId`, `subjectLabel`,
  `externalReference: <contractNummer>`, `outcomeCallbackUrl`.
- [x] 2.2 Add a "Submit renewal" path that POSTs the same shape with
  `decisionType: contract-renewal` for an expiring/`Verlopen` contract.
- [x] 2.3 On success, persist the returned decision id to
  `contract.approvalDecisionId` and set `approvalState = pending`.
- [x] 2.4 Fail CLOSED: if no decidesk endpoint resolves or the call errors,
  surface a visible error, leave `status` = `In onderhandeling` and
  `approvalState` unchanged; NEVER set `status = Actief`.
- [x] 2.5 Subscribe to the outcome via
  `POST /api/v1/decisions/{id}/subscriptions` with the SC callback.

## Phase 3: Consume + project the outcome

- [x] 3.1 Add the SC outcome callback endpoint that receives the decidesk push,
  validates it maps to a known `approvalDecisionId`, and projects idempotently:
  `approved` → `approvalState = approved` AND `status = Actief`;
  `rejected`/`withdrawn` → `approvalState = rejected`, `status` stays
  `In onderhandeling`.
- [x] 3.2 Add a daily reconcile job that polls
  `GET /api/v1/decisions/{id}/outcome` for every contract with
  `approvalState = pending` and applies the same idempotent projection (covers
  missed pushes). Use the genuine-exception path (ADR-031), not a CRUD wrapper.
- [x] 3.3 Ensure the projection is idempotent (re-receiving the same outcome is
  a no-op).

## Phase 4: ContractDetail Approval panel (no nav change)

- [x] 4.1 Add a read-only **Approval** panel to the `ContractDetail` page
  showing `approvalState`, the decidesk decision link/id, and a
  "Submit for approval" / "Submit renewal" action (action hidden when no
  decidesk endpoint resolves — "approval delegation not configured" state).
- [x] 4.2 Add a manual "refresh outcome" action that polls the outcome on demand.
- [x] 4.3 Do NOT move, rename, or unroute the `Contracten` nav entry, the
  `Contracten` index, or the `ContractDetail` page — record surface unchanged.
- [x] 4.4 NL + EN strings (English i18n keys) for all new labels.

## Phase 5: Migration + tests

- [x] 5.1 `lib/Repair/*` idempotent, fail-safe step backfills
  `approvalState = none` on contracts missing the field
  (`setRegister(slug)->setSchema(contract)->findAll([])`, positional OCP args);
  never drop data; grandfather existing `Actief` contracts (no retroactive
  decisions).
- [x] 5.2 Tests: raise-decision call shape + provenance fields; fail-closed when
  decidesk unavailable; outcome projection (approved → Actief, rejected → stays);
  idempotent re-projection; reconcile-job poll path.
- [x] 5.3 `cd softwarecatalog && openspec validate softwarecatalog-contracts-to-decidesk --strict` passes.
