# deelnames-gebruik Specification (delta)

## ADDED Requirements

### Requirement: A participant reads the usages shared with it, and their connections (REQ-DLG-010)

A user of an organisation listed in a usage's `participants` SHALL be able to read that usage and the connections it lists in `koppelingen`, through the same pages and API as the owner. The participant SHALL NOT be able to update or delete the usage or those connections. Organisations not listed SHALL see neither.

#### Scenario: A participant opens Applications in use
- GIVEN Gemeente Zuiddrecht owns the usage of Mozard and lists Alblasserdam and Papendrecht as participants
- WHEN a usage manager of Alblasserdam opens Applications in use
- THEN Mozard is listed with Gedeeld "Drechtsteden-Zuid, 3", and the header counts it among the shared usages

#### Scenario: A participant cannot change the shared usage
- GIVEN the same usage
- WHEN the Alblasserdam user opens it
- THEN the Deelnemers card shows the owner and the participants with the date they joined, and the edit and delete actions are not offered
- AND a direct PUT on the usage from that user is refused

#### Scenario: An outsider sees nothing
- GIVEN Gemeente Oostmaas is not a participant
- WHEN a usage manager of Oostmaas lists usages or reads the usage by id
- THEN the usage is not returned

### Requirement: The connections list says with whom a connection is shared (REQ-DLG-011)

The Connections page SHALL show a column "Shared with" listing the participant organisations of the usages that hold the connection, or "nobody". A participant SHALL see the shared connections in its own list.

#### Scenario: A shared connection
- GIVEN the Mozard usage lists the connection "Mozard naar iBurgerzaken" and has participants Alblasserdam and Papendrecht
- WHEN the owner opens Connections
- THEN that connection shows "Alblasserdam, Papendrecht" under Shared with

#### Scenario: The participant's list
- GIVEN the same connection
- WHEN a user of Papendrecht opens Connections
- THEN the connection is listed and opens read only

### Requirement: A cooperation shows its participants (REQ-DLG-012)

An organisation of type cooperation SHALL show its number of participants on the Organisations page and list them on its own page, each linked to its organisation.

#### Scenario: The cooperation row
- GIVEN Samenwerking Drechtsteden-Zuid with four participant organisations
- WHEN a user opens Organisations
- THEN its row reads "Samenwerking · 4 deelnemers"
