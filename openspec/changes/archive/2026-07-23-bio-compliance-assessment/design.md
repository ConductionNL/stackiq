# Design: bio-compliance-assessment

## Architecture Overview
This change extends the existing `module-compliance-assessment`
architecture rather than adding a new one:

```
module ──────────────┬── compliancy ──── standaardversie (element, gemmaType=standaardversie)
  (bbnLevel,          │        (existing)
   dpiaStatus,         └── compliancy ──── bioMaatregel   (NEW: parallel relation)
   dpiaDate,                    (evidence: bewijs/bewijsReferentie/url — reused)
   dpiaVolgendeBeoordeling,
   dpiaDocumentRef,
   verwerkingsregisterRef)
```

`compliancy` keeps its single-record shape and gains one optional
relation (`bioMaatregel`) alongside the existing `standaardversie` /
`standaardGemma` pair. A record links a module to a standard version OR
a BIO measure — never a third parallel schema. The matrix/coverage code
(`src/utils/complianceMatrix.js`) already treats "column key" as a
parameter; this change generalises it to accept either relation family
instead of forking a second mapper.

BBN level, DPIA status, and the verwerkingsregister reference are
application-level attributes (they describe the module as a whole, not a
specific measure), so they live directly on `module`, following the same
placement as other application-level fields (`hostingJurisdictie`,
`licentietype`).

## Goals / Non-Goals

**Goals:**
- Reuse the `compliancy` verified/claimed/evidence model for BIO measure
  compliance instead of forking a parallel assessment object.
- Make BBN level, DPIA status, and DPIA review due-dates filterable and
  reportable per organisation.
- Ship a working, declarative overdue-DPIA notification using the
  canonical `x-openregister-notifications` dialect.

**Non-Goals:**
- Computing BBN level or DPIA requirement automatically from other data
  (e.g. deriving BBN from `hostingJurisdictie`) — both are user-entered.
- A generic "review cadence" engine — `dpiaVolgendeBeoordeling` is a
  single user-set date field, not a recurring-schedule primitive.
- Modelling the register van verwerkingen itself.

## Decisions

### Decision 1: Extend `compliancy` with a `bioMaatregel` relation, not a new `bioCompliancy` schema
**Why:** The context brief and the `module-compliance-assessment` spec
are explicit: this change extends that model, it does not fork a
parallel one. `compliancy` already carries the verified/claimed logic,
the evidence fields (`bewijs`, `bewijsReferentie`, `url`), and the
matrix/coverage machinery. Adding a `bioMaatregel` relation (mirroring
`standaardversie`) reuses all of it for zero new UI code paths beyond
column-source selection.
**Alternatives considered:** a dedicated `bioAssessment` schema —
rejected, duplicates `compliancy`'s evidence/verified-claimed shape and
would require a second matrix mapper, directly contradicting the "do not
fork a parallel model" instruction.

### Decision 2: BBN/DPIA fields live on `module`, not on a per-measure record
**Why:** BBN level and DPIA status describe the application as a whole
("this application is classified BBN2 and had a DPIA executed on
2026-03-01"), not a per-standard or per-measure claim. Placing them on
`compliancy` would force one BBN/DPIA value per compliance record, which
is meaningless — an application has exactly one BBN level and one DPIA
status, independent of how many BIO measures or standards it claims.
**Alternatives considered:** a `bioBeoordeling` header record wrapping
BBN/DPIA plus a list of measure claims — rejected as unnecessary
indirection; `module` already is the one-per-application anchor object
every other per-application attribute (licence, hosting) hangs off.

### Decision 3: DPIA overdue notification uses a stored `dpiaVolgendeBeoordeling` date + `scheduled`/`withinNext`, not a computed field
**Why:** Per ADR-031, "overdue" detection should default to declarative
schema metadata. `x-openregister-calculations` can derive an `isOverdue`
boolean at read time, but `x-openregister-notifications`' `scheduled`
trigger filters on **stored** object data — a calculated field computed
at read time is not evaluated during a scheduled sweep. A stored
`dpiaVolgendeBeoordeling` date (set by the user/vendor when a DPIA is
executed) lets the rule reuse the exact filter shape already proven
working in this register: `contract`'s `contract-expiry` and
`gebruik`'s `phaseout-approaching` both use `scheduled` +
`{ "operator": "withinNext", "value": "P<n>D" }`. `withinNext` with a
0-day window (`"P0D"`) reads as "due on or before today" — i.e. due
today or already overdue — using the exact same operator family instead
of introducing an unproven "before"/"past-due" operator this register
has never used.
**Alternatives considered:** (a) an `x-openregister-calculations`
`dpiaOverdue` boolean + `calculatedChange` trigger — rejected because
`calculatedChange` only watches NUMERIC calculated fields for boundary
crossings, and a boolean derived at read time has no natural "change
event" to watch under a scheduled sweep; (b) a bespoke PHP background
job walking modules daily — rejected per ADR-031 (a scheduled sweep over
stored fields is exactly what the declarative engine already does; a
custom job would be the anti-pattern the ADR calls out).
**Declarative-vs-imperative decision (ADR-031):** declarative — no new
PHP service or job is introduced by this change.

### Decision 4: BIO coverage report extends `ComplianceMatrixView`, not a new page
**Why:** The context brief frames this explicitly as "extends the
existing compliance matrix." `ComplianceMatrixView` is already a
`type: custom` manifest page whose whole reason for existing is that no
index/detail archetype can express a runtime-selected two-dimensional
grid. Adding a BIO scope/tab to the same component (module rows ×
BIO-measure columns, plus a BBN/DPIA summary strip) reuses the filter-
first, URL-shareable-selection pattern instead of duplicating it.
**Alternatives considered:** a standalone `BioCoverageReportView` —
rejected as an unnecessary fork of the same filter-first
matrix/shareable-URL pattern; a toggle within the existing view is a
smaller diff and keeps one canonical "compliance view" surface.

## Risks / Trade-offs
- [Risk] `dpiaVolgendeBeoordeling` is user-entered, not computed from a
  fixed BIO/AVG review interval → some vendors may leave it blank,
  silencing the overdue notification for that application. →
  **Mitigation:** the "applications without a DPIA at BBN2+" filter
  (in scope) surfaces missing DPIA data independently of the
  notification, so the gap stays visible in the catalog UI even when
  the notification is silent.
- [Risk] `withinNext P0D` semantics assume the OpenRegister notification
  engine's `withinNext` operator is boundary-inclusive of past dates
  (i.e. `date <= now`), matching how `contract-expiry` and
  `phaseout-approaching` are already deployed in this register. →
  **Mitigation:** this reuses the exact operator already live in
  production rules in this same file; no new engine behaviour is
  assumed. If verified otherwise during implementation, the fallback is
  a small positive window (e.g. `"P1D"`) — documented here so the
  builder does not need to guess.
- [Risk] Extending `compliancy` with a second optional relation
  (`bioMaatregel`) means a record could theoretically carry both
  `standaardversie` and `bioMaatregel` set. → **Mitigation:** the spec
  requires records to link to exactly one of the two; UI form
  validation and the matrix mapper both treat "both set" as a data
  -quality issue to flag, not a new dual-purpose record type.

## Migration Plan
No Nextcloud DB migration class is introduced — per ADR-001 this app
owns no custom database tables. Schema changes are register-JSON patches
to `lib/Settings/softwarecatalogus_register.json`, applied the same way
every prior schema change in this app was: `ConfigurationService::importFromApp()`
re-imports the register on the existing `InitializeSettings` repair step
(see `repair-init`), so the change ships and self-applies on the next
app upgrade — no separate `migration.md` artefact is produced for this
change (see Notes below).

## Open Questions
- Should `bbnLevel` be `facetable: true` from day one (needed for the
  "applications without a DPIA at BBN2+" filter) — yes, confirmed in
  the spec; noted here so the register patch does not miss it.
- Fixed BIO/AVG review interval for a default `dpiaVolgendeBeoordeling`
  — deferred to DEFERRED_QUESTIONS; out of scope for this change.

## Nextcloud Integration
- Controllers: none new — this app queries OpenRegister's object API
  directly from the frontend (no bespoke CRUD controller), per the
  existing `settings-admin-controller` pattern.
- Services: none new — see Decision 3; the overdue-DPIA behaviour is
  entirely declarative schema metadata, not a service class.
- Mappers/Entities: none — OpenRegister owns storage; `compliancy` and
  `module` remain the only entities involved.
- Events/Hooks: the existing `InitializeSettings` repair step (see
  `repair-init`) re-imports the extended register on upgrade; no new
  hook is added.

## Security Considerations
No new authorization surface. `bioMaatregel` is a read-mostly reference
catalog (`authorization.read: ["public"]`, matching `element`); write
access follows the existing `softwarecatalog-admins`/manage-ACL pattern
already used by `compliancy` and `module`. The new `module` fields
(`bbnLevel`, `dpiaStatus`, `dpiaDate`, `dpiaVolgendeBeoordeling`,
`dpiaDocumentRef`, `verwerkingsregisterRef`) inherit `module`'s existing
field-level authorization — no new read/write scope is introduced.
`dpiaDocumentRef` follows the established `bewijsReferentie` pattern
(link via NC Files, not store the file) so no new file-handling
authorization path is created.

## NL Design System
New fields render through the existing `CnFormDialog`/`data`-widget
patterns already used for `module` and `compliancy` (see the manifest
`_note`s on `KompliantieDetail` / `ModuleDetail`); no new form or table
component is introduced. The BIO coverage report reuses
`ComplianceMatrixView`'s existing tri-state cell styling (verified /
claimed / none) via NL Design System CSS variables — no hardcoded
colors.

## File Structure
```
lib/
  Settings/
    softwarecatalogus_register.json   # bioMaatregel schema; compliancy + module extensions; notification rule
src/
  manifest.json                       # bioMaatregel catalog pages; module form fields; matrix BIO scope; filters
  views/
    ComplianceMatrixView.vue          # extended with a BIO scope/tab
  utils/
    complianceMatrix.js               # generalised to key on bioMaatregel alongside standaardversie
  l10n/
    nl.json / en.json (or equivalent) # new field labels, filter labels, notification subjects
tests/
  Unit/                                # register import / schema validation, matrix mapper unit tests
```

## Seed Data

### Schema: `bioMaatregel`
| Field | Object 1 | Object 2 | Object 3 | Object 4 | Object 5 |
|-------|----------|----------|----------|----------|----------|
| slug | `bio-5-1-1` | `bio-9-2-1` | `bio-10-1-1` | `bio-12-1-1` | `bio-13-2-1` |
| code | 5.1.1 | 9.2.1 | 10.1.1 | 12.1.1 | 13.2.1 |
| naam | Toegangsbeveiligingsbeleid | Beheer van gebruikerstoegang | Cryptografisch beleid | Netwerkbeveiligingsbeheer | Gegevensoverdracht­beleid |
| thema | Toegangsbeveiliging | Toegangsbeveiliging | Cryptografie | Communicatiebeveiliging | Communicatiebeveiliging |
| bioVersie | BIO 2.0 | BIO 2.0 | BIO 2.0 | BIO 2.0 | BIO 2.0 |
| bbnNiveau | [BBN1, BBN2, BBN3] | [BBN2, BBN3] | [BBN2, BBN3] | [BBN1, BBN2, BBN3] | [BBN2, BBN3] |
| bron | baseline­informatie­beveiligingoverheid.nl | (same) | (same) | (same) | (same) |

**Related items per object:** none (reference catalog entries; no
files/notes/tasks/contacts attached).

### Schema: `module` (existing objects gain new field values — no new seed objects)
Existing seed `module` objects (from `module-compliance-assessment`)
gain example values for the new fields on 3 of the existing records:
one BBN2 application with an executed DPIA and evidence document, one
BBN3 application with a required-but-not-yet-executed DPIA (for the
"without a DPIA at BBN2+" filter demo), and one BBN1 application with
DPIA not applicable.

## Trade-offs
Reusing `compliancy` for BIO measures (Decision 1) means the schema now
serves two conceptually distinct catalogs (GEMMA standards, BIO
measures) through one relation-pair shape. This is a deliberate
trade-off: it costs a small amount of schema ambiguity (two optional
relations, "exactly one populated") in exchange for zero duplicated
evidence/verified-claimed logic and one matrix mapper instead of two —
judged worthwhile given the explicit "do not fork a parallel model"
constraint.
