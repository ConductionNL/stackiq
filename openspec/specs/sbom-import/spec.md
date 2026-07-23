# sbom-import Specification

## Purpose
TBD - created by archiving change sbom-import. Update Purpose after archive.
## Requirements
### Requirement: CycloneDX SBOM files are parsed into a normalized component list

`SbomParserService` SHALL parse a CycloneDX JSON document whose
`bomFormat` equals `CycloneDX` and whose `specVersion` is `1.5` or `1.6` into
a list of component records (`name`, `version`, `purl`, `licenses`, optional
`hashes`, optional `type`, `bomRef`) from the document's `components[]`
array. The parser SHALL be a pure service with no dependency on
OpenRegister's `ObjectService` or any HTTP client, so it is unit-testable
against fixture files alone.

#### Scenario: A valid CycloneDX 1.6 document parses into components

- **WHEN** `SbomParserService::parse()` is called with a well-formed
  CycloneDX 1.6 JSON document containing three `components[]` entries with
  `name`, `version`, `purl`, and `licenses`
- **THEN** it returns three component records with those fields populated
- **AND** no OpenRegister call and no HTTP call occurs during parsing

#### Scenario: An unsupported bomFormat or specVersion is rejected

- **WHEN** `SbomParserService::parse()` is called with a JSON document whose
  `bomFormat` is not `CycloneDX`, or whose `specVersion` is not `1.5` or
  `1.6`
- **THEN** the parser throws an `UnsupportedSbomFormatException` naming the
  offending format/version
- **AND** no partial component list is returned

### Requirement: Uploaded SBOM files are bounded in size and JSON-only

The SBOM upload endpoint SHALL reject any upload exceeding the configured
maximum file size (default 10 MB) and any upload that is not valid JSON,
before invoking the parser, and SHALL require admin group membership or
manage-ACL on the target `moduleVersie`'s parent `module`.

#### Scenario: An oversized file is rejected before parsing

- **WHEN** a user uploads an SBOM file larger than the configured maximum
- **THEN** the endpoint responds with an error before `SbomParserService` is
  invoked
- **AND** no `sbomComponent` objects are created or replaced

#### Scenario: A non-JSON file is rejected

- **WHEN** a user uploads a file that is not valid JSON
- **THEN** the endpoint responds with a 400 error identifying the problem
- **AND** the previous component set for the target `moduleVersie`, if any,
  is left unchanged

#### Scenario: Import requires admin or manage-ACL

- **WHEN** a user without admin group membership and without manage-ACL on
  the target version's module attempts to import an SBOM
- **THEN** the endpoint responds with a 403 error
- **AND** no component objects are created

### Requirement: Imported components persist as OpenRegister objects scoped to a moduleVersie

Each parsed component SHALL persist as an `sbomComponent` OpenRegister object
with a required `moduleVersie` relation, `name`, and the parsed `version`,
`purl`, and `licenses` fields; optional `hashes`, `type`, and `bomRef` SHALL
be stored when present in the source SBOM. No app-local database table SHALL
be introduced (ADR-001).

#### Scenario: A parsed component persists with its moduleVersie relation

- **WHEN** an SBOM import for a given `moduleVersie` completes
- **THEN** each parsed component exists as an `sbomComponent` object whose
  `moduleVersie` relation resolves to that version
- **AND** its `name`, `version`, `purl`, and `licenses` match the source SBOM

### Requirement: Re-import replaces the previous component set and is soft-delete aware

The app SHALL replace a `moduleVersie`'s previously imported component set
when a new SBOM is imported for that same version: the previous non-deleted
`sbomComponent` objects for that version SHALL be soft-deleted, and the newly
parsed set SHALL then be created. Already-trashed rows from a prior replace
SHALL NOT be re-processed or double-counted. A failed import SHALL leave the
version with no component set rather than a mixed old/new set.

#### Scenario: A second import replaces the first

- **WHEN** a `moduleVersie` already has an imported component set and a user
  imports a new SBOM for the same version
- **THEN** the previously imported `sbomComponent` objects are soft-deleted
- **AND** only the components from the new SBOM appear on the version's
  Components tab afterwards

#### Scenario: A prior replace's trashed rows are not reprocessed

- **WHEN** a `moduleVersie` has already had one replace cycle (its first
  component set is soft-deleted, its second is live)
- **AND** a third import runs for the same version
- **THEN** only the live (second) component set is soft-deleted before the
  third set is created
- **AND** the count of soft-deleted `sbomComponent` objects from the first
  cycle does not change

### Requirement: Large imports run in bounded batches with progress reporting

`SbomImportService` SHALL persist and soft-delete `sbomComponent` objects in
bounded batches rather than a single unbounded bulk call. For imports whose
parsed component count exceeds 50, the service SHALL start a
`progress-tracking` operation, update it per batch, and complete it when the
import finishes, exposing the operation id in the import response.

#### Scenario: A large SBOM import reports incremental progress

- **WHEN** an uploaded SBOM parses into more than 50 components
- **THEN** the import response includes an operation id
- **AND** `getProgress(operationId)` returns increasing `processed_items`
  values while the import is in flight
- **AND** the operation reaches `phase = completed` with `percentage = 100`
  when the import finishes

#### Scenario: A small SBOM import completes without a progress operation

- **WHEN** an uploaded SBOM parses into 50 or fewer components
- **THEN** the import completes synchronously
- **AND** the response includes the final component count without requiring
  a progress poll

### Requirement: The module-version detail page shows imported components with summary counts

The `ModuleversieDetail` manifest page SHALL gain a Components tab showing
the imported `sbomComponent` list (name, version, purl, licenses) and summary
counts: total component count, distinct license count, and matched-
vulnerability count (per the matching requirement below).

#### Scenario: The Components tab reflects an import

- **WHEN** a user opens the Components tab of a `moduleVersie` that has an
  imported SBOM
- **THEN** the component list shows each component's name, version, purl,
  and licenses
- **AND** the summary counts show the total component count and the count of
  distinct licenses across those components

#### Scenario: A version with no imported SBOM shows an empty state

- **WHEN** a user opens the Components tab of a `moduleVersie` with no
  imported component set
- **THEN** the tab shows an empty state with an upload control
- **AND** no summary counts are shown as non-zero

### Requirement: Components are matched against existing kwetsbaarheden without external calls

For each `sbomComponent`, the app SHALL compute (at render time, never
persisted) matches against the existing `kwetsbaarheid` register using two
bounded local strategies: a confirmed match by exact CVE id when the source
SBOM carries CycloneDX VEX vulnerability data, compared against
`kwetsbaarheid.cveCode`; and a possible match by case-insensitive
name/purl-package comparison against `kwetsbaarheid.naam`, scoped to
`kwetsbaarheid` records whose `modules` already reference the version's
parent `module`. No matched-vulnerability reference SHALL be written back to
either the `sbomComponent` or `kwetsbaarheid` schema, and no HTTP request to
an external vulnerability feed (OSV.dev, NVD, or otherwise) SHALL be made by
the import or matching path.

#### Scenario: A component with VEX-declared CVE data gets a confirmed match

- **WHEN** an uploaded CycloneDX document's `vulnerabilities[]` block
  references a component by `bom-ref` with `id` equal to an existing
  `kwetsbaarheid.cveCode`
- **THEN** that component's Components-tab row shows a confirmed match to
  that `kwetsbaarheid`
- **AND** the match is computed at render time, not stored on the
  `sbomComponent` object

#### Scenario: A component name matching a module-scoped vulnerability gets a possible match

- **WHEN** a `kwetsbaarheid` record's `modules` includes the parent `module`
  of an imported `moduleVersie`, and one of that version's `sbomComponent`
  names case-insensitively matches (or is contained in) the
  `kwetsbaarheid.naam`
- **THEN** that component's Components-tab row shows a possible match,
  visually distinguished from a confirmed match

#### Scenario: A name match outside the module's own vulnerabilities is not surfaced

- **WHEN** a `kwetsbaarheid` record's `modules` does NOT include the parent
  `module` of an imported `moduleVersie`, even if a component name would
  textually match that `kwetsbaarheid.naam`
- **THEN** no possible match is shown for that pairing

#### Scenario: Editing a vulnerability changes the match with no re-import

- **WHEN** a `kwetsbaarheid`'s `cveCode` or `naam` is edited after an SBOM
  has already been imported for an affected version
- **THEN** the Components tab's matches reflect the edited `kwetsbaarheid`
  data the next time it is rendered, with no re-import of the SBOM required

#### Scenario: No outbound HTTP call is made during matching

- **WHEN** the Components tab computes matches for a version's component
  list
- **THEN** the computation reads only the local `sbomComponent` and
  `kwetsbaarheid` OpenRegister data
- **AND** no HTTP request is issued to any external vulnerability or
  advisory service

### Requirement: moduleVersie records SBOM import provenance

The `moduleVersie` schema SHALL gain three optional fields —
`sbomLastImportedAt` (date-time), `sbomFormat` (`cyclonedx-json` |
`spdx-json`), and `sbomFileName` (string) — populated on each successful
import. Existing `moduleVersie` objects SHALL remain valid with these fields
unset.

#### Scenario: A successful import records provenance on the version

- **WHEN** an SBOM import for a `moduleVersie` completes successfully
- **THEN** that version's `sbomLastImportedAt`, `sbomFormat`, and
  `sbomFileName` are set to the import's timestamp, format, and source file
  name

#### Scenario: Existing versions are unaffected by the schema addition

- **WHEN** the updated register definition is imported over existing data
- **THEN** existing `moduleVersie` objects without the new fields load and
  save unchanged

