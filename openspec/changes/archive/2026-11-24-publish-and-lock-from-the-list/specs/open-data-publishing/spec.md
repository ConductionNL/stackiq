# open-data-publishing Specification (delta)

## ADDED Requirements

### Requirement: Selected entries are published, withdrawn or locked together from the list (REQ-ODP-010)

On the Applications and Services lists, a user who selects entries SHALL get a selection bar with the count and the actions Publish, Withdraw, Lock and Delete. Publish and Withdraw SHALL call the publication endpoint once per entry, so its ownership guard runs for each one. The bar SHALL report how many entries succeeded and list the ones refused with the reason. Lock SHALL lock the selected objects through OpenRegister's lock for the chosen duration.

#### Scenario: A supplier publishes three entries
- GIVEN a supplier selects three of its own applications
- WHEN it chooses Publish in the selection bar and confirms
- THEN all three get a publication date of now and show the Published badge

#### Scenario: One entry belongs to someone else
- GIVEN a selection of two own applications and one of another supplier
- WHEN the user chooses Publish
- THEN two are published, and the bar says one was refused because the user may not publish it

#### Scenario: Locking for editing
- GIVEN three selected applications
- WHEN the user chooses Lock for 1 hour
- THEN the three objects are locked and others see them as locked until it expires

### Requirement: An entry's page publishes or withdraws that entry (REQ-ODP-011)

An application's or service's detail page SHALL offer Publish when the entry is not published and Withdraw when it is, in its actions menu, for users the publication endpoint allows. Publish SHALL accept "now" or a date. The page SHALL show the publication state as a badge.

#### Scenario: Withdrawing a published application
- GIVEN a published application of the user's organisation
- WHEN the user chooses Withdraw in the actions menu and confirms
- THEN the endpoint sets the depublication date, the badge changes to Not published and the entry leaves the public catalogue

#### Scenario: A reader without rights
- GIVEN a user who may not publish the entry
- WHEN they open its actions menu
- THEN Publish and Withdraw are not offered
