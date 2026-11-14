# saas-subscriptions specification

## Purpose

An information manager sees every SaaS subscription with its yearly cost, including the ones a department bought without IT. Matrix row `stackiq:ctr-saas-spend`.

## ADDED Requirements

### Requirement: The licences page lists SaaS subscriptions with their yearly cost, including ones bought outside IT (REQ-CLR-001)

A usage SHALL record who bought it: the buying department and whether IT was involved. The Licences page SHALL list every usage whose cloud model is SaaS, with its supplier, its yearly cost from the linked contracts and the buying department. It SHALL offer a filter for subscriptions bought outside IT.

#### Scenario: A department's own subscription shows on the list
@e2e tests/e2e/workflows/saas-subscriptions.spec.ts

- **GIVEN** a usage of Mentimeter with cloud model SaaS, bought by the department Communicatie outside IT, with a contract of 1,200 euro a year
- **WHEN** an information manager opens the Licences page and chooses the filter Bought outside IT
- **THEN** the list SHALL show Mentimeter with 1,200 euro a year and the department Communicatie

#### Scenario: An on-premises application is not a subscription
@e2e tests/e2e/workflows/saas-subscriptions.spec.ts

- **GIVEN** a usage with cloud model On premises
- **WHEN** an information manager opens the SaaS subscriptions list
- **THEN** that usage SHALL NOT be listed
