# catalogue-data-quality specification

**Status**: proposed
**Scope**: stackiq
**OpenSpec changes**:
- landscape-completeness-score

## Purpose

Applications and usages carry a data quality score from a declared rule set, so users see what to fix and whether entries are current. Matrix rows `stackiq:land-completeness-score` and `stackiq:comp-health-scoring`.

## ADDED Requirements

### Requirement: REQ-CDQ-001 Every application and usage carries a data quality score from a declared rule set

The `module` and `usage` schemas SHALL declare `x-openregister-quality` with weighted `required`, `format` and `freshness` rules and `good` and `fair` thresholds, so OpenRegister scores each entry on save. Stackiq SHALL show the resulting status (good, fair or poor) on the Applications list, on Applications in use and on the application page.

#### Scenario: An incomplete application scores poor
@e2e tests/e2e/workflows/data-quality.spec.ts

- **GIVEN** a supplier saves an application with only a name and a supplier
- **WHEN** a municipal information manager opens the Applications list
- **THEN** that application shows data quality poor

#### Scenario: The rule set is valid for OpenRegister
@e2e exclude Config check; tests/Unit/Settings/DataQualityFragmentTest.php asserts the annotation shape and that every rule names an existing field.

- **GIVEN** the merged register
- **WHEN** OpenRegister validates the `x-openregister-quality` annotation of `module` and `usage`
- **THEN** it reports no errors

### Requirement: REQ-CDQ-002 A user confirms an entry is current and its freshness resets

The application page and the usage page SHALL offer "Confirm this entry is current" to a user who may edit the entry. Confirming SHALL set `lastConfirmedAt` to now and nothing else, and the score SHALL be recomputed.

#### Scenario: An application owner confirms a stale entry
@e2e tests/e2e/workflows/data-quality.spec.ts

- **GIVEN** an application last confirmed 14 months ago with status fair
- **WHEN** its owner opens the page and clicks Confirm this entry is current
- **THEN** the page shows today as last confirmed
- **AND** the data quality score is higher than before

### Requirement: REQ-CDQ-003 A data quality report shows the spread and the weakest entries

The Reports page SHALL offer a Data quality report that shows, for applications and for usages, how many entries are good, fair and poor, and lists the twenty weakest entries first, each opening its page.

#### Scenario: An information manager finds what to fix first
@e2e tests/e2e/workflows/data-quality.spec.ts

- **GIVEN** the catalogue has good, fair and poor applications
- **WHEN** the information manager opens Reports, then Data quality
- **THEN** the page shows the three counts for applications
- **AND** the first rows of the weakest list are poor entries
