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

### Requirement: REQ-CDQ-003 A Data quality page shows how complete and current an organisation's register is

The app SHALL offer a Data quality page (board SkDatakwaliteit) for one organisation's applications in use. It SHALL show the register score (the average score, out of 100, against the declared rules), the average completeness (the share of the required rules met), and how many entries were never confirmed or were confirmed longer ago than the freshness rule allows. It SHALL list every declared rule with how many entries meet it, and the entries with the lowest completeness first, each with what is missing and when it was last confirmed. The figures SHALL be computed on the server from the rules the usage schema declares, over a bounded, organisation-scoped set of usages, and a user SHALL only see an organisation they may see the portfolio report of.

#### Scenario: An information manager finds what to fix first
@e2e tests/e2e/workflows/data-quality.spec.ts

- **GIVEN** Gemeente Zuiddrecht has applications in use, some without a business owner and some never confirmed
- **WHEN** the information manager opens Data quality and picks Gemeente Zuiddrecht
- **THEN** the page shows the register score, the average completeness and the number not confirmed
- **AND** the rule "business owner" shows how many entries meet it out of the total
- **AND** the first row of the completeness list is the entry with the most missing, naming what is missing

#### Scenario: A user cannot read another organisation's data quality
@e2e exclude Needs a second, non-admin user in another organisation; tests/Unit/Controller/DataQualityReportControllerTest.php asserts the 403 and that the report service is never called.

- **GIVEN** a user whose organisation is Gemeente Alblasserdam and who is not an administrator or civil servant
- **WHEN** they ask for the data quality of Gemeente Zuiddrecht
- **THEN** the request is refused before any usage is read
