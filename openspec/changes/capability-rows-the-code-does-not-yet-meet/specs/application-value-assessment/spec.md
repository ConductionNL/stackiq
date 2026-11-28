# application-value-assessment specification

## Purpose

The portfolio report puts each application's yearly cost beside its value and risk scores. Matrix row `stackiq:life-value-assessment`.

## ADDED Requirements

### Requirement: The portfolio report shows each usage's yearly cost beside its value and risk scores (REQ-CLR-016)

Each row of the portfolio report SHALL show the usage's yearly cost from its contracts, its business value and its risk score. The CSV export SHALL carry the same three columns.

#### Scenario: An information manager compares cost with value
@e2e tests/e2e/workflows/portfolio-report.spec.ts

- **GIVEN** a usage with business value 4, risk score 2 and contracts of 10,000 euro a year
- **WHEN** an information manager opens the portfolio report
- **THEN** its row SHALL show 10,000 euro a year, business value 4 and risk score 2
