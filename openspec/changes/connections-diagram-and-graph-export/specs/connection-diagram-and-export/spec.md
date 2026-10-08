# connection-diagram-and-export specification

**Status**: proposed
**Scope**: stackiq
**OpenSpec changes**:
- connections-diagram-and-graph-export

## Purpose

Users see how applications connect as a picture, and take the link graph to other tools. Matrix rows `stackiq:conn-diagram` and `stackiq:conn-export-graph`.

## ADDED Requirements

### Requirement: REQ-CDX-001 The application page draws the application's connections as a map

The application page `ModuleDetail` SHALL show a map with the application in the centre and every application or national provision it has a readable connection with around it. Each line SHALL carry the transport type and point in the connection's data exchange direction. Clicking a node SHALL open that application, and clicking a line SHALL open the connection.

#### Scenario: An application owner reads the map
@e2e tests/e2e/workflows/connections.spec.ts

- **GIVEN** application X has an `api` connection to application Y and a `file transfer` connection to a national provision
- **WHEN** the application owner opens the page of application X
- **THEN** the connection map shows X in the centre with Y and the national provision around it
- **AND** the line to Y reads `api`

### Requirement: REQ-CDX-002 The connections list can be shown as a diagram of the filtered rows

The connections list `Koppelingen` SHALL offer a diagram view that draws exactly the rows the current filters return, with a stable layout: the same rows SHALL give the same positions.

#### Scenario: An information manager draws only the API connections
@e2e tests/e2e/workflows/connections.spec.ts

- **GIVEN** the connections list filtered on transport type `api`
- **WHEN** the information manager switches to Diagram
- **THEN** the diagram shows only the `api` connections and their applications

### Requirement: REQ-CDX-003 A user can download an application's map and its links

The connection map SHALL let the user download the drawing as SVG and as PNG, and the links as a CSV with one line per connection: application A, direction, application B or national provision, transport type and status. The download SHALL hold only connections the user may read.

#### Scenario: An architect takes the links to a spreadsheet
@e2e exclude The download is built client-side; tests/vitest/connectionExport.spec.js asserts the CSV columns, one line per connection and quoting.

- **GIVEN** the map of application X with two connections
- **WHEN** the architect picks Download links as CSV
- **THEN** a CSV downloads with a header line and two connection lines

### Requirement: REQ-CDX-004 The organisation ArchiMate export can carry the organisation's connections

`GET /api/archimate/export/organization/{organizationUuid}` SHALL accept `connections=true`. The export SHALL then write every connection between applications in the export as an ArchiMate flow relationship, and a connection to a national provision as a flow to that element, with the transport type, direction and status as properties. A connection whose other end is not in the exported model SHALL be skipped and counted in the result.

#### Scenario: An organisation admin exports applications and connections in one file
@e2e tests/e2e/org-archimate-export.spec.ts

- **GIVEN** an organisation whose two applications share one `api` connection
- **WHEN** the organisation admin runs the organisation export with Applications and Connections ticked
- **THEN** the downloaded file holds a flow relationship between the two application components
- **AND** the relationship carries the property transport type `api`
