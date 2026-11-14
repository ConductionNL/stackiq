## ADDED Requirements

### Requirement: REQ-UAP-006 A usage records the connections the organisation runs within it

The usage form SHALL let an organisation pick the connections it actually runs for that application. The usage page SHALL list those connections, each opening the connection page.

#### Scenario: An information manager records a running connection

- **GIVEN** a usage of application X and a published connection between X and Y
- **WHEN** the information manager edits the usage, picks that connection and saves
- **THEN** the usage page lists the connection under Connections and services
- **AND** clicking it opens the connection page
