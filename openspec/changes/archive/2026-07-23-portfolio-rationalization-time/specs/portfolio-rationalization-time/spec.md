## ADDED Requirements

### Requirement: TIME classification fields are recorded on the gebruik schema

The `gebruik` schema SHALL gain three optional fields: `timeClassification`
(enum: `Tolerate`, `Invest`, `Migrate`, `Eliminate`), `timeRationale` (free
text), and `timeReviewDate` (date). TIME classification SHALL be recorded
per gebruik (per organisation's usage of an application), never on the
module or application itself, mirroring how `geplandeVervanging` is scoped
per gebruik rather than per module. Existing gebruik objects SHALL remain
valid without the new fields, and SHALL be treated as unclassified
(no TIME quadrant) until a value is set.

#### Scenario: User classifies a gebruik as Migrate with a rationale

- **WHEN** a user edits a gebruik and sets `timeClassification` to
  `Migrate`, a `timeRationale`, and a `timeReviewDate`
- **THEN** the gebruik stores all three fields
- **AND** the gebruik appears in the `Migrate` quadrant of the portfolio
  report for its organisation

#### Scenario: Existing objects are unaffected by the schema addition

- **WHEN** the updated register definition is imported over existing
  gebruik data
- **THEN** existing gebruik objects without the new fields load and save
  unchanged
- **AND** they are excluded from every TIME quadrant count until classified

#### Scenario: Clearing the classification returns the gebruik to unclassified

- **WHEN** a user clears a previously set `timeClassification` on a gebruik
- **THEN** the gebruik has no `timeClassification` value
- **AND** it no longer counts toward any TIME quadrant in the report

### Requirement: Editing TIME fields preserves every other gebruik field

The gebruik edit flow SHALL read the complete current gebruik object before
submitting a save and SHALL carry every existing field forward unchanged
alongside the edited TIME fields, because OpenRegister's `saveObject` is
PUT-semantic. Editing only `timeClassification`, `timeRationale`, or
`timeReviewDate` SHALL NOT null out, omit, or otherwise alter any other
gebruik field (including `status`, phase-start dates, relations such as
`module`, `deelnemers`, `koppelingen`, or `cloudDienstverleningsmodel`).

#### Scenario: A TIME-only edit leaves unrelated fields intact

- **GIVEN** a gebruik with `status: "In productie"`,
  `startDatumInProductie` set, and `cloudDienstverleningsmodel: ["SaaS"]`
- **WHEN** a user edits only the TIME fields and saves
- **THEN** the saved gebruik still has `status: "In productie"`,
  the same `startDatumInProductie`, and `cloudDienstverleningsmodel: ["SaaS"]`
  unchanged

### Requirement: Portfolio rationalization report aggregates per organisation

The app SHALL provide a portfolio rationalization report for a selected
organisation that shows: TIME quadrant counts (Tolerate / Invest / Migrate /
Eliminate, plus an Unclassified count) across the organisation's gebruiken;
EOL exposure reusing the `application-lifecycle-tracking` end-of-support
derivation (count and list of gebruiken whose linked `moduleVersie` has
passed or approaching end-of-support); cloud-transition share derived from
the existing `cloudDienstverleningsmodel` field (share of gebruiken per
hosting model — no new deployment-model field is introduced); and an
annualised cost overlay per TIME quadrant, reusing the
`contract-administration` annualised-cost derivation over each gebruik's
linked contracts. Figures SHALL be computed at query time, never persisted.

#### Scenario: Report shows quadrant counts with EOL and cost overlay

- **GIVEN** an organisation with gebruiken classified across all four TIME
  quadrants, some with end-of-support versions, and some with active
  contracts
- **WHEN** a user opens the portfolio rationalization report for that
  organisation
- **THEN** the report shows a count per TIME quadrant (including
  Unclassified)
- **AND** each quadrant shows its EOL-exposed gebruik count
- **AND** each quadrant shows its cloud-transition share by hosting model
- **AND** each quadrant shows its summed annualised contract cost

#### Scenario: Unclassified gebruiken are visible, not hidden

- **GIVEN** an organisation with gebruiken that have no `timeClassification`
  set
- **WHEN** the report is opened
- **THEN** those gebruiken appear in an `Unclassified` group rather than
  being omitted from the report

### Requirement: Report aggregation queries are bounded

Every query the portfolio report endpoint issues against OpenRegister SHALL
set an explicit `_limit` or use `searchObjectsPaginated` — the report SHALL
NOT issue an unbounded `searchObjects()` call, per the
`bound-unbounded-searchobjects-scans` bounded-query requirement. The report
SHALL apply an explicit page-size ceiling per organisation in addition to
the natural bound of "one organisation's gebruiken", and SHALL disclose when
the result set is truncated at that ceiling rather than silently dropping
rows.

#### Scenario: Report query sets an explicit limit

- **WHEN** the portfolio report endpoint builds its query for an
  organisation's gebruiken
- **THEN** the query array MUST include an explicit `_limit` value
- **AND** the value MUST NOT be silently omitted or left to default

#### Scenario: Truncation is disclosed, not silent

- **GIVEN** an organisation's gebruik count exceeds the report's page-size
  ceiling
- **WHEN** the report is generated
- **THEN** the report indicates it is showing a bounded subset (e.g. "first
  N of M")
- **AND** does not present the truncated figures as a complete total
  without that disclosure

### Requirement: Report and CSV export are scoped to the requester's authorised organisation(s)

The portfolio report endpoint (and its CSV export variant) SHALL scope every
result to organisations the requesting user is authorised to see, using the
current tenant/organisation-scoping mechanism that gates other gebruik and
contract reads. A request naming an organisation the requesting user is not
authorised to see SHALL be denied (fail closed), never silently returned
empty or narrowed after an initial broader fetch.

#### Scenario: Report request for an unauthorised organisation is denied

- **GIVEN** a user is not authorised to see organisation B's gebruiken
- **WHEN** that user requests the portfolio report for organisation B
- **THEN** the request is denied
- **AND** no organisation B gebruik, contract, or cost data is included in
  the response

#### Scenario: Report request for an authorised organisation returns only that organisation's data

- **GIVEN** a user is authorised to see organisation A
- **WHEN** that user requests the portfolio report for organisation A
- **THEN** the response contains only gebruiken, EOL exposure, and cost
  figures belonging to organisation A

### Requirement: CSV export of the portfolio report

The portfolio report SHALL offer a CSV export of its underlying gebruik-level
rows (organisation, application/module, TIME classification, rationale,
review date, lifecycle phase, EOL status, hosting/deployment model, and
annualised cost), scoped and bounded identically to the on-screen report —
the export SHALL NOT be a separate unbounded or unscoped data path.

#### Scenario: CSV export matches the on-screen report's scope

- **GIVEN** a user views the portfolio report for an organisation
- **WHEN** the user exports it as CSV
- **THEN** the CSV contains one row per gebruik shown in the report, with
  the same organisation scoping and page-size bound as the on-screen view
- **AND** each row includes TIME classification, rationale, review date,
  lifecycle phase, EOL status, hosting/deployment model, and annualised cost

#### Scenario: CSV export is denied for an unauthorised organisation

- **GIVEN** a user is not authorised to see organisation B's gebruiken
- **WHEN** that user requests the CSV export for organisation B
- **THEN** the request is denied and no CSV is returned
