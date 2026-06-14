# module-compliance-assessment Specification

## Purpose
TBD - created by archiving change module-compliance-assessment. Update Purpose after archive.
## Requirements
### Requirement: Compliance records link modules to standard versions with evidence

A compliance assertion SHALL be a `compliancy` object linking one `module`
to one `standaardversie` (a GEMMA `element` with
`gemmaType=standaardversie`), optionally carrying evidence: the legacy
`bewijs` file, a `bewijsReferentie` NC Files reference (new, optional), or a
`url`. The `standaardversie` relation SHALL be the canonical key for all
compliance views; the `standaardGemma` string SHALL be used only as a
fallback for records whose relation is unresolved, and such records SHALL be
marked unresolved rather than merged silently.

#### Scenario: Supplier records compliance with evidence

- **WHEN** a user creates a compliancy record linking a module to a standard version and attaches evidence via an NC Files reference
- **THEN** the record is stored in the register with the module and standaardversie relations and the evidence link
- **AND** the module's detail view lists the standard as supported with the evidence accessible

#### Scenario: Unresolved standard reference is flagged

@e2e exclude Legacy-data rendering edge; covered by unit tests on the matrix data mapper.

- **WHEN** a compliancy record has only a `standaardGemma` string and no resolved `standaardversie` relation
- **THEN** compliance views show the record as unresolved instead of matching it to a standard column

### Requirement: Module standards are derived by the compliance subscriber

The compliance subscriber pipeline SHALL derive module standards from
compliancy records. On module create/update events the existing
`ModuleComplianceSubscriber`/`ModuleComplianceService` pipeline collects the
`standaardversie` UUIDs of all compliancy objects linked to the module and
synchronises the module's `standaarden` array to exactly that set. The module
SHALL be re-saved only when the derived set differs from the stored one (loop
guard). All compliance views SHALL treat `module.standaarden` as derived data
— the compliancy records are the source of truth.

#### Scenario: Adding a compliancy record updates the module's standards

@e2e exclude Event-subscriber pipeline; covered by PHPUnit tests on ModuleComplianceService (existing behavior, retrofit).

- **WHEN** a compliancy record linking a module to a new standard version is created and the module is subsequently updated
- **THEN** the module's `standaarden` array contains that standard version's UUID exactly once

#### Scenario: Unchanged standards do not trigger a re-save

@e2e exclude Idempotency/loop guard; covered by PHPUnit tests asserting no second save call.

- **WHEN** the subscriber processes a module whose derived standards set equals the stored `standaarden`
- **THEN** the module is not saved again
- **AND** no further module-update event is emitted by the sync

### Requirement: Compliance matrix distinguishes verified from claimed

The app SHALL provide a compliance matrix view of modules × standard
versions in which every cell shows one of three states: **verified** (a
compliancy record with evidence — `bewijs`, `bewijsReferentie`, or `url`),
**claimed** (a compliancy record without any evidence), or **none**. The
verified and claimed states SHALL be visually distinct, and a verified or
claimed cell SHALL open the underlying compliancy record with its evidence.
The matrix SHALL be filter-first: the user selects standards (and optionally
a module subset or organisation scope) before cells render; the selection
SHALL be encoded in the page URL so a comparison is shareable.

#### Scenario: Matrix renders the three cell states

- **WHEN** a user selects standards in the matrix view covering modules with evidenced, unevidenced, and absent compliance records
- **THEN** the corresponding cells render as verified, claimed, and none respectively, with verified and claimed visually distinct

#### Scenario: Cell opens the evidence

- **WHEN** a user activates a verified cell
- **THEN** the underlying compliancy record is shown with its evidence link or file
- **AND** an NC Files-referenced evidence document opens via Nextcloud Files

#### Scenario: Matrix selection is shareable

- **WHEN** a user opens a matrix URL containing an encoded standards/module selection
- **THEN** the matrix renders that same selection without re-picking filters

### Requirement: Catalog can be filtered by supported standard

Module listings and catalog search SHALL offer a filter by supported
standard version, returning modules that have a compliancy record for the
selected standard(s). The filter results SHALL indicate per module whether
support is verified or claimed.

#### Scenario: Buyer shortlists modules by standard

- **WHEN** a user filters the module catalog on a selected standard version
- **THEN** only modules with a compliancy record for that standard are listed
- **AND** each result indicates verified or claimed support

### Requirement: Organisation compliance coverage is reportable

For a selected organisation and standard version(s), the app SHALL report
coverage over the organisation's in-use applications (gebruiken → modules):
per application, whether the module's support for the standard is verified,
claimed, or absent. Applications whose module has no compliance data SHALL
be listed as such — never omitted.

#### Scenario: Organisation sees its compliance posture for a standard

- **WHEN** a user selects their organisation and a standard version in the coverage view
- **THEN** every in-use application is listed with verified / claimed / none for that standard
- **AND** applications without any compliance data are visibly listed as having none

### Requirement: New evidence is linked via NC Files, legacy evidence stays readable

The `compliancy` schema SHALL gain an optional `bewijsReferentie` NC Files
reference; the UI SHALL offer NC Files linking for new or edited evidence
(link, don't store). Existing base64 `bewijs` content SHALL remain readable
and downloadable; no automated migration of stored evidence SHALL occur in
this change.

#### Scenario: Legacy base64 evidence remains accessible

- **WHEN** a user opens a compliancy record that carries legacy base64 `bewijs` content
- **THEN** the evidence can be viewed or downloaded as before
- **AND** editing the record offers NC Files linking without destroying the legacy evidence

