# maintenance-and-supplier-roadmap Specification

## Purpose
Organisations follow the maintenance suppliers plan on the products they use, and read each supplier's roadmap and planned releases. Matrix rows `stackiq:life-maintenance-window` and `stackiq:mkt-supplier-roadmap`.

## Requirements

### Requirement: REQ-MSR-001 A supplier announces planned maintenance on a product

A supplier SHALL record a maintenance window on its own product with a title, a start and an end, the expected impact and a status that moves from planned to in progress to completed, or to cancelled.

#### Scenario: A supplier announces a maintenance window
@e2e tests/e2e/workflows/maintenance.spec.ts

- **GIVEN** a supplier of product X
- **WHEN** the supplier opens the page of X, clicks Add under Planned maintenance and saves a window next Saturday 08:00 to 12:00 with impact unavailable
- **THEN** the Planned maintenance section of X lists the window as planned

### Requirement: REQ-MSR-002 Organisations that use a product see its planned maintenance

The product page SHALL list its maintenance windows, and the dashboard SHALL list the planned windows of the next 30 days for products the user's organisation uses.

#### Scenario: A municipality sees the window on its dashboard
@e2e tests/e2e/workflows/maintenance.spec.ts

- **GIVEN** a municipality with a usage of product X and a planned window on X next Saturday
- **WHEN** its information manager opens the dashboard
- **THEN** the Planned maintenance widget lists X with next Saturday's window and impact unavailable

### Requirement: REQ-MSR-003 The owners of every usage are notified

When a supplier announces a window, stackiq SHALL notify the business and technical owners of every usage of the product, and SHALL remind them a day before the window starts while it is still planned.

#### Scenario: Owners get the announcement
@e2e exclude Delivered by OpenRegister's notification engine; tests/Unit/EventListener/MaintenanceRecipientsListenerTest.php asserts the resolved owners and tests/Unit/Settings/MaintenanceRoadmapFragmentTest.php the rules.

- **GIVEN** two municipalities use product X and both usages have a business owner
- **WHEN** the supplier announces a window on X
- **THEN** both business owners receive a Nextcloud notification naming X and the window

### Requirement: REQ-MSR-004 A product page shows the supplier's roadmap

The product page SHALL show the supplier's roadmap statement and the product's versions on a timeline, planned versions first, and the Module versions list SHALL filter on planned releases. A supplier SHALL release a planned version from its page through the version lifecycle.

#### Scenario: A buyer reads what ships next
@e2e tests/e2e/workflows/maintenance.spec.ts

- **GIVEN** product X with a roadmap statement and version 3.0 in development with a planned date in March
- **WHEN** a municipal buyer opens the page of X
- **THEN** the roadmap shows the statement and version 3.0 in March on the timeline

#### Scenario: A supplier releases the planned version
@e2e tests/e2e/workflows/maintenance.spec.ts

- **GIVEN** version 3.0 of product X in development
- **WHEN** the supplier opens the version and clicks Release
- **THEN** its status reads in use
