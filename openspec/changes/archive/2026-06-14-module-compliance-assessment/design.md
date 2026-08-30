# Design: module-compliance-assessment

## Decision 1 — Retrofit before build

The compliancy schema, the `ModuleComplianceSubscriber` →
`ModuleComplianceService` sync pipeline, and the GEMMA-element standards are
running production code with zero spec coverage. Step one is specifying the
existing behavior exactly as it is (requirement 1), adding `@spec` tags, and
hardening the one real risk in the documented design: the subscriber saves
the module from inside a module-update listener — the spec pins the guard
(only save when `standaarden` actually changed) that prevents an event loop.
New functionality builds on the specced base, not beside it.

## Decision 2 — Three cell states; verified vs claimed is non-negotiable

Matrix cell semantics:

- **verified** — a compliancy record links the module to the standaardversie
  AND carries evidence (`bewijs` file or `bewijsReferentie` NC Files link or
  `url`);
- **claimed** — the link exists without any evidence;
- **none** — no compliancy record for that pair.

A municipal PvE reader acting on "supports ZGW APIs" must be able to see
whether that is a supplier claim or an evidenced fact. Collapsing the two
states (or rendering claims as green checkmarks) is the failure mode of every
self-reported catalog; the spec forbids it.

## Decision 3 — The relation is the key; the string is the fallback

`compliancy.standaardversie` (related GEMMA `element`,
`gemmaType=standaardversie`) is the canonical key for matrix columns and
filters. `standaardGemma` (free string GEMMA id) is consulted only when the
relation is unresolved (legacy/import artifacts) and such cells are marked
as unresolved rather than silently merged. `module.standaarden` remains what
it is today: a derived convenience array maintained by the subscriber —
views MUST NOT treat it as an independent data source.

## Decision 4 — Filter-first matrix, not a cartesian wall

The matrix page opens with selectors (standards multi-select, optionally a
module subset / organisation scope) and renders the selection — not all
modules × all standards. Government standard sets run into the hundreds of
standaardversies; an unfiltered grid is unusable and slow. Deep links encode
the selection so a PvE comparison is shareable.

## Decision 5 — Organisation coverage reuses the gebruik join

"Do MY applications support standard X" = the organisation's gebruiken →
their modules → compliancy records for X. This is a query/render concern on
existing relations (same join family as the lifecycle roadmap), not a new
data structure. Output per application: verified / claimed / none for the
selected standard(s).

## Decision 6 — Evidence: NC Files for new, base64 stays readable

The existing `bewijs` property stores base64 file content in the register —
against the fleet "documents = NC Files" rule, but migrating stored evidence
is risky and out of scope. Compromise:

- new optional `bewijsReferentie` (NC Files reference) on `compliancy`;
- the UI offers NC Files linking for new/edited evidence;
- legacy base64 `bewijs` remains readable/downloadable;
- no automated migration in this change (a follow-up can offer "move to
  Files" per record).

## Out of scope

- Certification workflows / third-party verification — the catalog records
  claims and evidence, it does not adjudicate.
- Standards authoring — standards arrive as GEMMA elements via
  `archimate-import`.
- Compliance scoring/weighting ("87% Common Ground compliant") — a number
  like that implies a methodology nobody has agreed on; the matrix shows
  facts per standard.
- Publishing/federation specifics — compliance data rides the existing
  publication surfaces.
