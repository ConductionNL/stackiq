## ADDED Requirements

### Requirement: Standards are browsable on their own page with their compliance claims

Stackiq SHALL list the GEMMA standards imported with the ArchiMate model on a Standards page. Each standard SHALL open a page that lists the compliance claims made against it.

#### Scenario: A buyer browses the standards

- **GIVEN** an imported GEMMA model with the standard StUF-ZKN
- **WHEN** a municipal buyer opens Standards
- **THEN** the list shows StUF-ZKN with its theme and status

#### Scenario: A standard lists its claims

- **GIVEN** two applications with a compliance claim on StUF-ZKN
- **WHEN** the buyer opens StUF-ZKN
- **THEN** the page lists both claims with their application

### Requirement: An admin updates the standards of every application in one action

Stackiq SHALL offer a Nextcloud admin one action that sets each application's standards from its compliance records. Only a Nextcloud admin SHALL run it.

#### Scenario: An admin syncs the standards

- **GIVEN** three applications whose compliance records name standards their `standaarden` list lacks
- **WHEN** a Nextcloud admin runs Sync standards in the settings
- **THEN** each application's standards list holds the standards of its compliance records
- **AND** the dialog reports how many applications changed

#### Scenario: A non-admin is refused

- **GIVEN** a signed-in user who is not a Nextcloud admin
- **WHEN** they call the bulk sync endpoint
- **THEN** stackiq answers 403 and changes nothing
