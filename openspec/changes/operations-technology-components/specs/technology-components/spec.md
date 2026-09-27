# technology-components specification

**Status**: proposed
**Scope**: stackiq
**OpenSpec changes**:
- operations-technology-components

## Purpose

A municipal information manager records the servers, virtual machines, databases, network devices and end-user devices their organisation runs, how they depend on each other, and which applications in use run on them. They see when a component reaches its end of support and which applications that affects. Stackiq records these; it does not discover them.

## ADDED Requirements

### Requirement: REQ-TCO-001 An organisation SHALL record technology components with a class, a status and an owner

The `stackiq` register SHALL hold a `technologyComponent` schema with a required name, class and owning organisation, a status with a working lifecycle, the hardware fields manufacturer, model, serial number, asset tag and location, an optional `software` version, an end of support date and the components it runs on. Only users of the owning organisation and catalogue admins SHALL read a component.

#### Scenario: An information manager registers a database server
@e2e tests/e2e/spec-coverage/technology-components.spec.ts

- **GIVEN** a municipal information manager of Gemeente Voorbeeld on `/technologie`
- **WHEN** they add Database 01 with class Database, running on App server 01, and save
- **THEN** `/technologie/:id` SHALL show Database 01 with class Database, owner Gemeente Voorbeeld and Runs on App server 01

#### Scenario: A supplier cannot read an organisation's components
@e2e exclude An authorisation rule held by OpenRegister; tests/Unit/Settings/TechnologyComponentDeclarationTest.php asserts the read rule is organisation-scoped and names no supplier or public group.

- **GIVEN** a technology component of Gemeente Voorbeeld
- **WHEN** a supplier's user asks OpenRegister for it
- **THEN** it SHALL NOT be returned

#### Scenario: A component moves through its lifecycle
@e2e tests/e2e/spec-coverage/technology-components.spec.ts

- **GIVEN** a component with status In use
- **WHEN** the information manager chooses Phase out on its detail page
- **THEN** its status SHALL be To be phased out

### Requirement: REQ-TCO-002 The Technology pages SHALL list components and show their relations both ways

A `Technologie` index at `/technologie`, reached from a menu entry under Applications, SHALL list components with name, class, organisation, status and end of support, with quick filters for hardware and platform software. `TechnologieDetail` SHALL show the component's data, the components it runs on, the components running on it, and the applications in use that run on it, each opening its own page.

#### Scenario: An information manager sees what runs on a host
@e2e tests/e2e/spec-coverage/technology-components.spec.ts

- **GIVEN** Host 01 with App server 01 running on it, and an application in use running on App server 01
- **WHEN** a municipal information manager opens Host 01
- **THEN** the page SHALL list App server 01 under Runs on this component
- **AND** opening App server 01 SHALL list that application in use

### Requirement: REQ-TCO-003 An application in use SHALL record the components it runs on and show their support state

`usage` SHALL carry `runsOn`, a list of technology components of the same organisation. `GebruikDetail` SHALL show those components with their end of support and a state of supported, ending within 180 days, ended, or unknown, and SHALL show the worst state including the components they run on, one level down.

#### Scenario: An application runs on a database past its end of support
@e2e tests/e2e/spec-coverage/technology-components.spec.ts

- **GIVEN** the demo application in use running on Database 01, whose end of support is 2026-03-03, and today is later
- **WHEN** a municipal information manager opens `/gebruik/:id`
- **THEN** the Runs on panel SHALL list Database 01 as ended

#### Scenario: The state follows the host underneath
@e2e exclude A pure derivation; tests/vitest/technologyLifecycle.spec.js asserts the worst state is taken over the listed components and the components they run on.

- **GIVEN** an application in use running on a virtual machine without an end of support date, on a host whose support ended
- **WHEN** the Runs on panel computes the state
- **THEN** the worst state SHALL be ended

### Requirement: REQ-TCO-004 The end-of-life sync SHALL stamp a component's end of support from the software version it is

When `EolSyncService` stamps a version's end of support from the feed, it SHALL also stamp `endOfSupport`, `eolSource` and `eolUpdatedOn` on every technology component whose `software` is that version. A component without `software` SHALL keep its typed date.

#### Scenario: A database gets PostgreSQL's end of support
@e2e exclude Needs the feed register from integriq; tests/Unit/Service/EolSyncServiceTest.php asserts that stamping a version also stamps the components pointing at it and leaves others alone.

- **GIVEN** a component Database 01 whose `software` is PostgreSQL 13, mapped to the feed
- **WHEN** the EOL sync stamps PostgreSQL 13 with an end of support
- **THEN** Database 01 SHALL carry the same `endOfSupport` with `eolSource` set to the feed
