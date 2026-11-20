# features-roadmap Specification (delta)

## Purpose

The page where a user sees what stackiq can do, what is in beta, what is coming and which extensions can be added. Covers rows ins-feature-roadmap and the visible half of ops-plugins.

## ADDED Requirements

### Requirement: The Features and roadmap page groups the app's features by status (REQ-FRM-001)

The Features and roadmap page SHALL list the features from `openspec/features.overlay.json` in three groups, Available (stable), Beta and Coming soon, each with its count, title and summary in the user's language. It SHALL be reachable from the footer menu and SHALL link to the documentation.

#### Scenario: A user sees what is coming
- GIVEN the overlay lists three features with status soon
- WHEN a user opens Features and roadmap
- THEN the Coming soon group shows those three with their titles and a count of 3

#### Scenario: Dutch titles
- GIVEN the user's language is Dutch
- WHEN the page loads
- THEN each feature shows its `title_nl` and `summary_nl`

### Requirement: The Uitbreidingen tab lists the extensions from the store (REQ-FRM-002)

The page SHALL have a second tab, Uitbreidingen, with a count, that lists the store items for stackiq (configuration sets and flows) with name, publisher, what it does and status (installed or install). An administrator SHALL be able to install an item from the tab; other users SHALL see the list without the install action. The footer Store entry SHALL open this tab.

#### Scenario: An administrator installs an extension
- GIVEN the store offers a stackiq flow that is not installed
- WHEN an administrator opens Uitbreidingen and chooses Install on it
- THEN the item shows as installed and the flow appears on the Flows page

#### Scenario: A user without admin rights
- GIVEN a signed-in user who is not an administrator
- WHEN they open Uitbreidingen
- THEN the items are listed and no install action is offered

#### Scenario: The footer Store entry
- GIVEN the footer menu
- WHEN a user chooses Store
- THEN Features and roadmap opens on the Uitbreidingen tab
