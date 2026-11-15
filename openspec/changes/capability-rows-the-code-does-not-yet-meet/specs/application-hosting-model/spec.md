# application-hosting-model specification

## Purpose

An application run by a hosting party records that model. Matrix row `stackiq:land-hosting-model`.

## ADDED Requirements

### Requirement: An application run by a hosting party records it as IaaS or PaaS (REQ-CLR-020)

The Hosting field of an application SHALL offer `IaaS` and `PaaS` for a product that a hosting party runs on its own infrastructure, next to `On-premises (self-managed)` and `SaaS`. The hosting location and jurisdiction SHALL record where that party runs it.

#### Scenario: A municipality's product runs at a hosting party
@e2e exclude generic CnIndexPage form and CnDetailPage data widget, tested in nextcloud-vue; the field definitions are schema data

- **GIVEN** a product that a hosting party runs in a Dutch data centre
- **WHEN** a user with write rights saves the application with Hosting `IaaS` and Hosting location `NL`
- **THEN** the application page shows Hosting `IaaS` and Hosting location `NL`
- **AND** the Applications page can be filtered on Hosting `IaaS`
