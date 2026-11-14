## ADDED Requirements

### Requirement: The Organisations page lists organisations with search, a type facet and a status filter

The Organisations page SHALL list organisations and SHALL let a user search them by name, narrow them by type and filter them by status.

#### Scenario: A buyer finds the suppliers

- **GIVEN** the catalogue holds municipalities and suppliers
- **WHEN** a municipal buyer opens Organisations and picks the type Supplier
- **THEN** only suppliers remain in the list

#### Scenario: A search narrows the list

- **GIVEN** the Organisations page
- **WHEN** the user types part of a supplier's name
- **THEN** the list shows only organisations whose name matches

### Requirement: An organisation is registered with its type

The organisation form SHALL require a type: municipality, supplier, collaboration or community. The type SHALL show on the organisation's card and page.

#### Scenario: A functional administrator registers a cooperation

- **GIVEN** a functional administrator on the Organisations page
- **WHEN** they add an organisation with type Collaboration and save
- **THEN** the new organisation shows type Collaboration

#### Scenario: The type is required

- **GIVEN** the organisation form without a type
- **WHEN** the user saves
- **THEN** the save is refused with a message on the type field

### Requirement: An organisation's page lists its contact persons with their function and roles

The organisation page SHALL list the organisation's contact persons with their function and catalogue roles. Each row SHALL open the contact person's page.

#### Scenario: A beheerder sees the contacts of the organisation

- **GIVEN** an organisation with contact persons Anna, function information manager, role gebruik-beheerder, and Bram
- **WHEN** a beheerder of the organisation opens its page
- **THEN** the contact persons list shows Anna with her function and role, and Bram
- **AND** clicking Anna opens her contact person page
