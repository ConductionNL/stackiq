# catalogue-connection-pages Specification

## Purpose
A connection records that one application exchanges data with another, or with a national provision. Users browse connections, filter them by transport, open one, and see an application's connections on its own page. Matrix rows `stackiq:conn-list-page`, `stackiq:conn-type-filter`, `stackiq:conn-per-application` and `stackiq:conn-external-provision`.

## Requirements

### Requirement: REQ-CCP-001 A user can browse every connection they may read in one list

Stackiq SHALL offer an index page `Koppelingen` at `/koppelingen` over the `connection` schema, reached from the Applications menu entry. It SHALL list every connection the user may read under the schema's authorization, with its name, transport type, status, both applications and the national provision, and each row SHALL open the connection's detail page.

#### Scenario: A municipal information manager opens the connections list
@e2e tests/e2e/workflows/connections.spec.ts

- **GIVEN** an information manager of a municipality whose organisation registered two connections
- **WHEN** they open Applications, then Connections
- **THEN** the page `/koppelingen` lists both connections with type, status and the two applications
- **AND** clicking a row opens `/koppelingen/<id>`

#### Scenario: A connection of another municipality stays hidden
@e2e exclude The read rule is OpenRegister's schema RBAC; tests/Unit/Settings/SchemaRbacTest.php asserts the connection read rule.

- **GIVEN** a connection owned by another organisation with no publication date
- **WHEN** the information manager opens the connections list
- **THEN** that connection is not listed

### Requirement: REQ-CCP-002 A user can filter connections by transport type

The connections list SHALL let the user narrow the rows to one or more transport types (`api`, `file transfer`, `digikoppeling`, `message que`, `upload to portal`, `webservices`, `n/a`) and to one status, and SHALL show how many rows each value holds.

#### Scenario: Only API connections remain
@e2e tests/e2e/workflows/connections.spec.ts

- **GIVEN** the connections list shows one `api` and one `file transfer` connection
- **WHEN** the information manager filters the list on type `api` (the table's filter menu, or `/koppelingen?type=api`)
- **THEN** only the `api` connection remains in the list

### Requirement: REQ-CCP-003 The application page lists the connections that start and end there

The application page `ModuleDetail` SHALL show the connections in which the application is application A, and separately the connections in which it is application B. Each row SHALL open the connection's detail page, and each list SHALL link to the connections list filtered on that application.

#### Scenario: An application owner sees both directions
@e2e tests/e2e/workflows/connections.spec.ts

- **GIVEN** application X is application A in connection 1 and application B in connection 2
- **WHEN** the application owner opens the page of application X
- **THEN** "Connections from this application" lists connection 1
- **AND** "Connections to this application" lists connection 2
- **AND** clicking connection 2 opens its detail page

### Requirement: REQ-CCP-004 The connection schema offers transitions and a picker that match its data

The `connection` schema SHALL declare its lifecycle on the status values its rows hold (`in development`, `in use`, `end of support`, `withdrawn`), SHALL filter the national provision picker on the GEMMA type `Buitengemeentelijke voorziening`, and SHALL build a connection's display name from `moduleA`, `dataExchangeDirection` and `moduleB` or `nonMunicipalProvision`.

#### Scenario: A supplier releases a connection from its detail page
@e2e tests/e2e/workflows/connections.spec.ts

- **GIVEN** a connection with status `in development`
- **WHEN** a supplier with update rights opens its detail page
- **THEN** the page offers the release action
- **AND** after release the status reads `in use`

#### Scenario: The national provision picker lists GEMMA provisions
@e2e exclude The picker is the library's related-object field; tests/Unit/Settings/ConnectionSchemaTest.php asserts the queryParams value and that GEMMA_release.xml uses the same spelling.

- **GIVEN** the GEMMA model is imported
- **WHEN** a user edits a connection and opens the national provision field
- **THEN** it offers the GEMMA elements of type Buitengemeentelijke voorziening, such as a basisregistratie
