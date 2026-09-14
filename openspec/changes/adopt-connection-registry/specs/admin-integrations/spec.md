# admin-integrations Specification Delta

**Status**: proposed
**Scope**: stackiq
**OpenSpec changes**:
- [adopt-connection-registry](../../)

## Purpose

Admins see stackiq's outside connections on one page, with a status stackiq can back.

## ADDED Requirements

### Requirement: REQ-STACKIQ-CONN-001 Stackiq declares its outside connections in one static file

Stackiq SHALL declare `email`, `federation` and `eol-feed` in `lib/Settings/connections.json` in the shape of hydra connection-registry design D2 (hydra REQ-CONN-001). The `email` entry SHALL name `email_transport_type` as its adapter key with `simulatedValues` holding `null` and not the empty string, because an empty transport sends mail through SMTP. The `federation` and `eol-feed` entries SHALL be `reportedOnly`. The `eol-feed` entry SHALL offer integriq's `endoflife-date` source template. Every `settingsUrl` SHALL point at a section id that exists in the admin settings page.

#### Scenario: The declaration names this app and passes integriq's schema
@e2e exclude A static file with no browser surface; tests/Unit/Settings/ConnectionsDeclarationTest.php checks the shape, the app id, unique keys and the anchors.

- **GIVEN** `lib/Settings/connections.json`
- **WHEN** it is validated against integriq's `connections.schema.json`
- **THEN** it SHALL validate
- **AND** its `app` SHALL equal the id in `appinfo/info.xml`
- **AND** every key SHALL be unique
- **AND** every `#section-…` anchor SHALL be an id in a settings section component

#### Scenario: The null transport reads simulated, and an empty one does not
@e2e exclude Rule 3 runs in integriq against app config; tests/Unit/Settings/ConnectionsDeclarationTest.php applies the declared values the way integriq's reader does.

- **GIVEN** integriq has synced stackiq's declaration
- **WHEN** `email_transport_type` holds `null`
- **THEN** the Email row SHALL read Simulated with the declared message
- **AND** when `email_transport_type` is empty or `smtp`, rule 3 SHALL NOT apply

### Requirement: REQ-STACKIQ-CONN-002 A save asks integriq to look again, and a run reports what it met

When a save writes the settings of a declared connection, stackiq SHALL send `ConnectionRefreshRequestedEvent` with app `stackiq` and that key, and SHALL send it before any report for that key (hydra REQ-CONN-004, hydra#674). An email settings save SHALL then report what `SymfonyEmailService::isEmailSystemConfigured()` sees. A peer add or remove SHALL report OpenCatalogi missing as `unavailable`, and federation off or without peers as `unconfigured`. A federation pull SHALL report every peer answering as `configured`, some as `limited` and none as `error`. An EOL sync run SHALL report its recorded outcome. A message SHALL name a peer by host only. Both events SHALL be named by string and sent only when the class exists. Neither SHALL change the response of the request, job or run that sent it. No page request SHALL send an event.

#### Scenario: Saving email settings refreshes, then reports
@e2e exclude The event is not observable from a browser; tests/Unit/Service/ConnectionReportServiceTest.php and tests/Unit/Controller/SettingsControllerConnectionReportTest.php assert the order and the unchanged response.

- **GIVEN** integriq is installed
- **WHEN** an admin saves the email settings with email switched off
- **THEN** stackiq SHALL send a refresh for `email`
- **AND** then a report `unconfigured` saying email is switched off

#### Scenario: A pull where some peers fail reads limited
@e2e exclude A pull needs OpenCatalogi and reachable peers, which the CI instance does not have; tests/Unit/Service/ConnectionReportServiceTest.php drives the outcomes.

- **GIVEN** federation is on with two peers
- **WHEN** a pull reaches one peer and not the other
- **THEN** stackiq SHALL report `federation` as `limited`
- **AND** the message SHALL name the failing peer's host and not its path

#### Scenario: An EOL run without integriq's register reads not configured
@e2e exclude The run's outcome depends on integriq's register on the instance; tests/Unit/Service/EolSyncServiceConnectionReportTest.php asserts the report per reason.

- **GIVEN** EOL sync is switched on
- **WHEN** a run cannot find the `eol_product` or `eol_cycle` schema
- **THEN** stackiq SHALL report `eol-feed` as `unconfigured` with a message naming integriq's endoflife.date source

#### Scenario: Without integriq nothing is sent
@e2e exclude The CI instance installs integriq; tests/Unit/Service/ConnectionReportServiceTest.php asserts nothing is sent or logged when the class is absent.

- **GIVEN** integriq is not installed
- **WHEN** an admin saves email settings, or a pull or a sync runs
- **THEN** no event SHALL be sent and nothing SHALL be logged
- **AND** the save, pull or run SHALL answer as it did before this change

### Requirement: REQ-STACKIQ-CONN-003 An admin reads the connections on an Integrations page

Stackiq SHALL render an `index` page at `/settings/integrations` over `integriq/app_connection`, reached from the settings gear and preset to `app` equal to `stackiq` through its menu entry's `query` (hydra REQ-CONN-006). The page and its menu entry SHALL be admin only. The page SHALL require Integriq, and the menu entry SHALL only render when integriq is installed. The status column SHALL name all six statuses, `limited` included. The page SHALL NOT offer a generic Add button. Its Add integration action SHALL open `/apps/integriq/connections?app=stackiq&link=1`.

#### Scenario: The page lists only the rows of stackiq
@e2e tests/e2e/workflows/integrations-page.spec.ts

- **GIVEN** stackiq and integriq are installed and integriq has synced the declaration
- **WHEN** an admin opens the Integrations page
- **THEN** the page SHALL list the three declared connections
- **AND** every listed row SHALL have `app` equal to `stackiq`

#### Scenario: Add integration goes to integriq
@e2e tests/e2e/workflows/integrations-page.spec.ts

- **GIVEN** the Integrations page
- **WHEN** the admin chooses Add integration
- **THEN** the browser SHALL open integriq's Connections overview with `app=stackiq` and `link=1`

#### Scenario: A connection that works in part reads Limited
@e2e exclude Only a federation pull with a failing peer produces limited; tests/vitest/connectionRegistry.spec.js asserts the label in English and Dutch.

- **GIVEN** a row whose status is `limited`
- **WHEN** the page renders it
- **THEN** the cell SHALL read Limited, or Beperkt on a Dutch instance
