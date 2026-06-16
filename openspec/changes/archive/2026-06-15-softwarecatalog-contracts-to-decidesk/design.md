# Design: softwarecatalog-contracts-to-decidesk

## Phase 0 findings (record vs decision flow)

Inspected `lib/Settings/softwarecatalogus_register.json` (the `contract`
schema), `lib/Controller/`, `lib/Service/`, and `src/manifest.json`.

| Element | What it is | Verdict |
|---|---|---|
| `contract` schema fields `dienst`, `gebruik`, `startDatum`, `eindDatum`, `contractNummer`, `contractType` (SLA/Licentie/Onderhoud), `kosten`, `kostenPeriode`, `contactpersoonAanbieder/Gebruiker`, `documentReferentie`, `opmerkingen` | Plain record / catalog metadata | **STAYS** in softwarecatalog |
| `contract.status` enum `Actief / Verlopen / In onderhandeling` | Mixed: `In onderhandeling → Actief` is an approval DECISION; `Actief → Verlopen` is a date-driven expiry (not a decision) | **status field stays, but the approval transition is a projection of decidesk; the expiry transition stays catalog-local** |
| `Contracten` nav entry (`src/manifest.json` line ~30, `order: 40`) + `Contracten` index page (`/contracten`) + `ContractDetail` page (`/contracten/:id`) | Record surface (list + detail) | **STAYS** routable; gains a read-only "Approval" panel sourced from decidesk |
| Approval / sign-off / state-machine logic for contracts in `lib/` | **DOES NOT EXIST** — `StatusTransitionValidator` and `GebruikStatusHandler` exist only for `AangebodenGebruik` (offered-use), not for `contract`; there is no contract controller or contract service | **NEW capability — delegate to decidesk, do not build locally** |

Conclusion: there is no contract-approval flow to "move" — there is a *gap*.
Filling that gap by building a workflow inside softwarecatalog would duplicate
decidesk's merged decision hub. The dedup-correct design is to raise the
decision in decidesk via ADR-019 and project the outcome onto `contract.status`.

## Key decisions

1. **`contract.status` is a projection, not an authority, for the approval
   transition.** softwarecatalog never writes `status = Actief` as a result of
   a user clicking "approve". The user action raises a decidesk Decision; the
   outcome (`approved` / `rejected`) drives the projection back onto `status`.
   A rejected/withdrawn decision leaves the contract `In onderhandeling`.

2. **Two decision types, mapped to the existing decidesk enum.**
   - First activation of an `In onderhandeling` contract → `decisionType: contract`.
   - Re-approval of an expiring/`Verlopen` contract → `decisionType: contract-renewal`.

3. **Provenance fields are filled from the contract object** so decidesk and any
   federation/audit consumer can trace back: `sourceApp: softwarecatalog`,
   `subjectRegister: voorzieningen_register` (the contract register slug),
   `subjectSchema: contract`, `subjectId: <OR object id>`,
   `subjectLabel: <contractNummer + dienst/gebruik label>`,
   `externalReference: <contractNummer>`,
   `outcomeCallbackUrl: <SC outcome projection endpoint>`.

4. **Fail CLOSED.** If decidesk is unavailable / the integration registry has no
   decidesk endpoint, the "Submit for approval" action errors visibly and the
   contract stays `In onderhandeling`. softwarecatalog never auto-approves
   (cross-app contract #1, and ADR-005-style fail-closed for an authorization
   boundary).

5. **Outcome consumption = subscription preferred, polling fallback.** SC calls
   `POST /api/v1/decisions/{id}/subscriptions` with its callback so decidesk
   pushes the outcome; a daily reconcile job polls `GET .../outcome` for any
   pending decisions whose push was missed (idempotent projection).

6. **The expiry transition is untouched.** The date-driven `Actief → Verlopen`
   transition is owned by `contract-administration` (its status-maintenance
   phase). It is not a decision and is out of scope here — this change only
   adds the approval/renewal seam.

7. **No redundant controller (ADR-022).** Contract CRUD continues to run
   through the OpenRegister object store via the manifest renderer. The only
   new server code is the thin ADR-019 integration call + the idempotent
   outcome-projection repair/job — the genuine exception path (ADR-031), not a
   CRUD wrapper.

## Integration call shape (ADR-019)

Raise (on user "Submit for approval"):
```
POST /api/v1/decisions   (via integration registry → decidesk endpoint)
{
  "decisionType": "contract" | "contract-renewal",
  "sourceApp": "softwarecatalog",
  "subjectRegister": "voorzieningen_register",
  "subjectSchema": "contract",
  "subjectId": "<contract OR id>",
  "subjectLabel": "<contractNummer> — <dienst/gebruik>",
  "externalReference": "<contractNummer>",
  "outcomeCallbackUrl": "<SC callback>"
}
→ persists the returned decision id on the contract (e.g. `approvalDecisionId`).
```
Consume:
```
push: decidesk → SC callback (subscription), OR
poll: GET /api/v1/decisions/{id}/outcome
→ if outcome=approved: project status = Actief (idempotent)
   if outcome=rejected/withdrawn: status stays In onderhandeling
```

## Schema delta

Add provenance/projection fields to the `contract` schema (catalog-local,
exportable) so the projection is auditable and federates:
- `approvalDecisionId` (string) — the decidesk decision id (last raised).
- `approvalState` (enum `none / pending / approved / rejected`) — mirror of the
  decidesk outcome for the *approval/renewal* decision (distinct from the
  date-driven `status`; `status` remains the catalog lifecycle field).

No `status` enum values are added or removed; `status` keeps
`Actief / Verlopen / In onderhandeling`.

## Menu / pages touched

| File | Entry | Edit |
|---|---|---|
| `src/manifest.json` | nav `Contracten` (order 40), pages `Contracten` (`/contracten`) + `ContractDetail` (`/contracten/:id`) | UNCHANGED location; `ContractDetail` gains a read-only **Approval** panel (decidesk outcome + "Submit for approval" / "Submit renewal" action) |
| `lib/Settings/register.d/*.json` (ADR-037 fragment) | `contract` schema | add `approvalDecisionId`, `approvalState` projection fields |

No nav entry is removed; no page is unrouted. The Contracts record surface
stays exactly where it is — only the *decision* leaves.

## Alternatives considered

- **Build a contract approval workflow in softwarecatalog** (status
  state-machine + approver roles in `lib/`). Rejected: duplicates decidesk's
  merged decision hub (ADR-012), and re-implements authorization that decidesk
  already owns (cross-app contract #1).
- **Route approval through shillinq CLM.** Rejected: shillinq is the
  *bookkeeping* boundary (cross-app contract #3), not the *decision* boundary;
  approval/sign-off is a decision, which is decidesk's domain.
- **Let `status` flip locally on user click, then notify decidesk
  asynchronously.** Rejected: fail-open authorization (the contract would be
  `Actief` before/whether-or-not the decision is approved). Must fail closed.

## Migration / rollout

- Additive schema fields default to `approvalState: none` for existing
  contracts; no data is dropped or rewritten.
- Existing `In onderhandeling` contracts are unaffected until a user submits one
  for approval; existing `Actief` contracts keep `status` and get
  `approvalState: none` (grandfathered — no retroactive decisions raised).
- `lib/Repair/*` idempotent step backfills `approvalState: none` on contracts
  missing the field (fail-safe; uses `setRegister(slug)->setSchema(contract)
  ->findAll([])` + positional OCP args).

## Risks

- decidesk endpoint not registered in the integration registry on a given
  instance → "Submit for approval" fails closed (visible error). Mitigation:
  the Approval panel shows an "approval delegation not configured" state and
  hides the submit action when no decidesk endpoint resolves.
- Missed push + reconcile-job lag → a contract sits `pending` longer than the
  real outcome. Mitigation: daily idempotent poll reconcile; the panel offers a
  manual "refresh outcome" that polls on demand.
- Federation/export must carry the new fields. Mitigation: fields live on the
  `contract` schema (catalog-local), so they export and federate with the rest.
