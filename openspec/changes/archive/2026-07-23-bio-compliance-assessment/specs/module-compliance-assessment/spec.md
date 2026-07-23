# module-compliance-assessment (delta)

This change extends the `compliancy` record model — module ↔ standard
version, evidence, verified-vs-claimed — to also cover BIO 2.0 measures
(`bioMaatregel`), so BIO measure compliance reuses the exact same
mechanism instead of a parallel one. A `compliancy` record now links a
module to either a `standaardversie` or a `bioMaatregel` (never both).
The compliance matrix gains a BIO-measure column source alongside the
existing standard-version columns. Everything else in this capability —
the subscriber pipeline, the catalog standard filter, and the
organisation coverage report mechanics — is unchanged.

## MODIFIED Requirements

### Requirement: Compliance records link modules to standard versions with evidence

A compliance assertion SHALL be a `compliancy` object linking one
`module` to exactly one of: a `standaardversie` (a GEMMA `element` with
`gemmaType=standaardversie`) or a `bioMaatregel` (a BIO 2.0 measure
catalog entry), optionally carrying evidence: the legacy `bewijs` file,
a `bewijsReferentie` NC Files reference (new, optional), or a `url`. The
`standaardversie` relation SHALL be the canonical key for standards
compliance views and the `bioMaatregel` relation SHALL be the canonical
key for BIO measure compliance views; the `standaardGemma` string SHALL
be used only as a fallback for standards records whose relation is
unresolved, and such records SHALL be marked unresolved rather than
merged silently. A record that carries both a `standaardversie` and a
`bioMaatregel` relation SHALL be treated as a data-quality issue and
flagged rather than matched to either column.

#### Scenario: Supplier records compliance with evidence

@e2e exclude Pre-existing functionality unchanged by bio-compliance-assessment; no live Nextcloud instance available in this implementing session to add e2e coverage retroactively (see docs/features/bio-compliance-assessment.md).

- **WHEN** a user creates a compliancy record linking a module to a standard version and attaches evidence via an NC Files reference
- **THEN** the record is stored in the register with the module and standaardversie relations and the evidence link
- **AND** the module's detail view lists the standard as supported with the evidence accessible

#### Scenario: Unresolved standard reference is flagged

@e2e exclude Legacy-data rendering edge; covered by unit tests on the matrix data mapper.

- **WHEN** a compliancy record has only a `standaardGemma` string and no resolved `standaardversie` relation
- **THEN** compliance views show the record as unresolved instead of matching it to a standard column

#### Scenario: Supplier records BIO measure compliance with evidence

@e2e exclude No live Nextcloud instance available in this implementing session to drive Playwright without touching the shared dev environment (see docs/features/bio-compliance-assessment.md); the `bioMaatregel` relation renders through KompliantieDetail's existing generic data widget (no `include` list — every compliancy property renders, same as the pre-existing `standaardversie` field), no bespoke Vue code.

- **WHEN** a user creates a compliancy record linking a module to a `bioMaatregel` and attaches evidence via an NC Files reference or URL
- **THEN** the record is stored in the register with the module and `bioMaatregel` relations and the evidence link
- **AND** the module's detail view lists the BIO measure as supported with the evidence accessible

#### Scenario: A record with both relations set is flagged, not matched

@e2e exclude Data-transform logic (partitionCompliancy's conflicted-record branch), not a UI event; covered by unit tests on the matrix data mapper (tests/vitest/complianceMatrix.spec.js — "flags a record with both standaardversie and bioMaatregel set as conflicted"), matching the existing "Unresolved standard reference is flagged" scenario's exclusion in this same spec.

- **WHEN** a compliancy record has both a `standaardversie` and a `bioMaatregel` relation populated
- **THEN** compliance views flag the record as a data-quality issue
- **AND** the record is not counted toward either the standards matrix or the BIO measure matrix

### Requirement: Compliance matrix distinguishes verified from claimed

The app SHALL provide a compliance matrix view of modules × standard
versions or BIO measures — column source selected by the user — in
which every cell shows one of three states: **verified** (a compliancy
record with evidence — `bewijs`, `bewijsReferentie`, or `url`),
**claimed** (a compliancy record without any evidence), or **none**. The
verified and claimed states SHALL be visually distinct, and a verified
or claimed cell SHALL open the underlying compliancy record with its
evidence. The matrix SHALL be filter-first: the user selects a column
source (standard versions or BIO measures) and the specific columns
(and optionally a module subset or organisation scope) before cells
render; the selection SHALL be encoded in the page URL so a comparison
is shareable.

#### Scenario: Matrix renders the three cell states

- **WHEN** a user selects standards in the matrix view covering modules with evidenced, unevidenced, and absent compliance records
- **THEN** the corresponding cells render as verified, claimed, and none respectively, with verified and claimed visually distinct

#### Scenario: Cell opens the evidence

@e2e exclude Pre-existing functionality unchanged by bio-compliance-assessment; no live Nextcloud instance available in this implementing session to add e2e coverage retroactively (see docs/features/bio-compliance-assessment.md).

- **WHEN** a user activates a verified cell
- **THEN** the underlying compliancy record is shown with its evidence link or file
- **AND** an NC Files-referenced evidence document opens via Nextcloud Files

#### Scenario: Matrix selection is shareable

- **WHEN** a user opens a matrix URL containing an encoded standards/module selection
- **THEN** the matrix renders that same selection without re-picking filters

#### Scenario: Matrix renders BIO measure columns

- **WHEN** a user switches the matrix's column source to BIO measures and selects one or more `bioMaatregel` entries covering modules with evidenced, unevidenced, and absent compliance records
- **THEN** the corresponding cells render as verified, claimed, and none respectively, using the same visual states as the standards matrix
- **AND** the BIO-measure selection is encoded in the page URL so it is shareable
