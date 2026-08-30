# software-license-posture

## ADDED Requirements

### Requirement: Portfolio license posture is derived from in-production usage

The capability SHALL aggregate the in-production application portfolio by
`module.licentietype` (`Open source` / `Closed source`) and by `module.licentie`,
producing the open-source vs closed-source share and the licence-type mix of
applications actually in production. Aggregation SHALL be weighted by
in-production `gebruik` — using the same predicate as
`application-lifecycle-tracking` (`startDatumInProductie` set,
`startDatumUitGefaseerd` empty) — SHALL be computed at query time, and SHALL NOT
be stored. Applications with an empty `licentietype` SHALL be counted as
"Unknown".

#### Scenario: Open-source vs closed-source share reflects deployments, not catalogue rows

- **WHEN** a closed-source module is registered but has no in-production usage, and an open-source module has in-production usages
- **THEN** the closed-source module does not contribute to the posture
- **AND** the open-source share reflects the in-production deployments
- **AND** a module with no `licentietype` in production is counted under "Unknown"

### Requirement: License consumption is derived per licensed application

For each application, the capability SHALL derive the count of in-production
`gebruik` records as its deployment count (license consumption), computed at
query time from existing relations and never stored.

#### Scenario: Deployment count reflects in-production usages

- **WHEN** an application has three in-production usages and one phased-out usage
- **THEN** its deployment count is three
- **AND** phasing out a usage decreases the count without any stored edit

### Requirement: Cost is consumed from contract administration, not re-derived

Where the posture surface shows cost, it SHALL consume the annualised cost
produced by `contract-administration` (`totalAnnualisedCost`) and SHALL NOT
re-implement contract cost annualisation. When `contract-administration` is
absent, cost columns SHALL degrade to empty while licence mix and deployment
counts continue to work.

#### Scenario: Per-vendor cost reuses contract-administration's annualised cost

- **WHEN** the per-vendor rollup shows cost for a supplier with contracts
- **THEN** the cost equals the annualised total derived by contract-administration for those contracts
- **AND** no second cost-annualisation implementation exists in this capability

#### Scenario: Posture works without contract data

- **WHEN** no contracts exist for a vendor
- **THEN** the vendor's licence mix and deployment counts are still shown
- **AND** its cost column is empty

### Requirement: License posture is reportable per organisation and per vendor

The capability SHALL report, for a given organisation, the open-source vs
closed-source share of its in-use applications (its open-source-first posture),
and SHALL provide a per-vendor (`aanbieder`) rollup of deployments and licence
mix. Both SHALL be surfaced through the manifest dashboard / CnDataTable renderer
(ADR-012), with no app-local CRUD controllers.

#### Scenario: Organisation open-source-first report

- **WHEN** an architect opens the license posture for an organisation whose in-use applications are a mix of open- and closed-source
- **THEN** the report shows the organisation's open-source vs closed-source share
- **AND** it lists the closed-source applications contributing to the closed share

#### Scenario: Per-vendor rollup

- **WHEN** the per-vendor view is opened
- **THEN** each supplier shows its in-production deployment count and licence-type mix
- **AND** the rollup is grouped by the `aanbieder` vendor reference
