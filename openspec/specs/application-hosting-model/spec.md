# application-hosting-model Specification

## Purpose
A supplier or catalogue manager records how an application is offered: on premises, IaaS, PaaS or SaaS, in which country or region it is hosted, and which jurisdiction governs its data. A municipality records the hosting model it actually uses per usage. The fields live in the voorzieningen register (`lib/Settings/softwarecatalogus_register.json`), are edited on the Applications form and shown on the application page, and the portfolio report reads them. Written after the fact (7 Oct 2026) to describe what the code does today. Matrix row `stackiq:land-hosting-model`.

## Requirements

### Requirement: An application records its hosting model, location and jurisdiction

The `module` schema SHALL carry `cloudDienstverleningsmodel` (title "Hosting"), a list of one or more of `On-premises (self-managed)`, `IaaS`, `PaaS` and `SaaS`; `hostingLocation` (title "Hosting location"), one of `NL`, `EU`, `US` and `Elsewhere`; and `hostingJurisdiction` (title "Jurisdiction"), one of the same four values. All three SHALL be optional and facetable. The Applications page (`/modules`) create and edit form SHALL offer them, and the application page `ModuleDetail` SHALL show them in its `md-data` widget (`src/manifest.json`).

#### Scenario: A supplier records a SaaS application hosted in the EU
- **WHEN** a user with write rights on the module saves an application with Hosting `SaaS`, Hosting location `EU` and Jurisdiction `NL`
- **THEN** the module object stores `cloudDienstverleningsmodel: ["SaaS"]`, `hostingLocation: "EU"` and `hostingJurisdiction: "NL"`
- **AND** the application page shows all three values
- @e2e exclude generic CnIndexPage form and CnDetailPage data widget, tested in nextcloud-vue; the field definitions are schema data

#### Scenario: A value outside the list is refused
- **WHEN** a module is saved with `hostingLocation: "Mars"`
- **THEN** OpenRegister rejects the object against the schema enum
- @e2e exclude enum validation is OpenRegister's, tested there

### Requirement: A usage records the hosting model the organisation actually runs

The `usage` schema SHALL carry its own optional, facetable `cloudDienstverleningsmodel` list with the same four values, so a municipality can run a product on premises that its supplier also offers as SaaS. `lib/Service/PortfolioReportService.php` SHALL read the usage's value, not the module's, for the cloud-transition share per TIME quadrant.

#### Scenario: The portfolio report counts the usage's hosting model
- **GIVEN** a module offered as `SaaS` and `On-premises (self-managed)`
- **AND** a usage of it with `cloudDienstverleningsmodel: ["On-premises (self-managed)"]`
- **WHEN** the organisation's portfolio report is built
- **THEN** that usage adds one to `cloudTransition["On-premises (self-managed)"]` of its quadrant
- **AND** the CSV row carries the same value in `hostingModel`
- @e2e exclude server-side aggregate; covered by tests/Unit/Service/PortfolioReportServiceTest.php

### Requirement: Old Dutch hosting values are renamed on upgrade

The repair step `lib/Repair/RenameDutchCatalogValues.php` SHALL rewrite a stored `hostingLocation` or `hostingJurisdiction` of `Elders` to `Elsewhere`, so objects saved before the English vocabulary stay valid.

#### Scenario: An object saved with Elders is migrated
- **GIVEN** a module stored with `hostingLocation: "Elders"`
- **WHEN** the app is upgraded and the repair step runs
- **THEN** the module holds `hostingLocation: "Elsewhere"`
- @e2e exclude repair step; covered by tests/Unit/Repair/RenameDutchCatalogValuesTest.php
