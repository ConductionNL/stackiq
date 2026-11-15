# application-value-assessment specification

**Status**: proposed
**Scope**: stackiq
**OpenSpec changes**:
- lifecycle-application-value-assessment

## Purpose

Each application in use carries scores for business value, technical fit and risk, next to its cost, so a TIME decision has recorded reasons. Matrix row `stackiq:life-value-assessment`.

## ADDED Requirements

### Requirement: REQ-AVA-001 An organisation scores each application it uses on value, fit and risk

A usage SHALL record business value, technical fit and risk, each from 1 to 5, and the date they were scored. Stackiq SHALL derive a suggested TIME class from value and fit: Invest when both are 3 or more, Migrate when value is 3 or more and fit is lower, Tolerate when fit is 3 or more and value is lower, Eliminate when both are lower, and no suggestion while either is missing. The recorded TIME class SHALL NOT change by itself.

#### Scenario: An information manager scores an application
@e2e tests/e2e/workflows/portfolio-value.spec.ts

- **GIVEN** a usage of application X recorded as Tolerate
- **WHEN** the information manager sets business value 5 and technical fit 2 on its page and saves
- **THEN** the page shows the suggested class Migrate
- **AND** the recorded class still reads Tolerate

### Requirement: REQ-AVA-002 The usage page shows the risk signals next to the risk score

The usage page SHALL show, next to the risk score, the end-of-support state of the version the organisation runs and the number of vulnerabilities linked to the application.

#### Scenario: Signals that back a high risk score
@e2e exclude Read-only widget; tests/vitest/valueAssessment.spec.js (riskSignals, the usage page) covers the EOL state and the vulnerability count.

- **GIVEN** a usage whose version passed its end of support and whose application has two linked vulnerabilities
- **WHEN** the information manager opens the usage page
- **THEN** it shows end of support passed and two vulnerabilities next to the risk score

### Requirement: REQ-AVA-003 The portfolio report plots value against fit and flags classes the scores contradict

The portfolio report SHALL plot the organisation's scored usages by business value and technical fit with annualised cost as point size, SHALL show the suggested class beside the recorded one, SHALL offer a filter on usages whose recorded class differs from the suggestion, and SHALL include the scores and the suggestion in its CSV export.

#### Scenario: Finding the classes to revisit
@e2e tests/e2e/workflows/portfolio-value.spec.ts

- **GIVEN** three scored usages, one recorded Tolerate whose scores suggest Eliminate
- **WHEN** the information manager opens the portfolio report and picks "Recorded class differs from scores"
- **THEN** only that usage is listed with Tolerate recorded and Eliminate suggested
