## ADDED Requirements

### Requirement: REQ-CCP-005 A connection records its two applications, its direction and the standard versions it uses

The connection form SHALL record application A and application B, the direction of the data exchange and the standard versions the connection uses. The connection page SHALL show all four.

#### Scenario: An information manager registers a connection

- **GIVEN** an information manager on the Connections page
- **WHEN** they add a connection from application X to application Y, direction A to B, standard version StUF-ZKN 3.10, and save
- **THEN** the connection page shows X, Y, the direction A to B and StUF-ZKN 3.10

#### Scenario: A connection lists in the direction it was given

- **GIVEN** a connection from X to Y with direction A to B
- **WHEN** the owner of X opens the page of X
- **THEN** the connection is listed under Connections from this application
