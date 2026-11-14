# platform-properties Specification (delta)

## Purpose

What stackiq gets from the platform it runs on, independent of any one page: where it runs, how its data is reached, where its look comes from, how entries reach outside systems, how history is kept and how the store extends it. Covers rows share-self-hosted, share-rest-api, ops-own-house-style, share-lifecycle-conditional-sync, share-webhooks, ins-audit-trail and ops-plugins.

## ADDED Requirements

### Requirement: The catalogue runs on the customer's own Nextcloud (REQ-PLAT-001)

stackiq SHALL install and run as a Nextcloud app on a server the customer operates, with OpenRegister as its only required app. It SHALL NOT need a service run by Conduction or another vendor to start, store data or serve its pages. Provider: Nextcloud; stackiq inherits hosting by being a Nextcloud app (`appinfo/info.xml`, licence EUPL-1.2).

#### Scenario: An installation without access to vendor services
- GIVEN a Nextcloud server with OpenRegister and no route to any Conduction service
- WHEN an administrator enables stackiq and loads the catalogue register
- THEN the dashboard, the Applications page and an application's detail page load and an application can be created

#### Scenario: The licence is declared
- GIVEN the repository at any release tag
- WHEN `LICENSE` and `appinfo/info.xml` are read
- THEN both name the EUPL-1.2

### Requirement: Catalogue data is read and written through a REST API (REQ-PLAT-002)

Every catalogue entity SHALL be an OpenRegister object, so OpenRegister's objects API (`/apps/openregister/api/objects/{register}/{schema}`) reads and writes it under the schema's authorization rules. stackiq's own routes under `/api/*` SHALL apply the same rules, and the stackiq pages SHALL use the same API as an outside client. Provider: OpenRegister.

#### Scenario: An outside client reads applications
- GIVEN a signed-in user with read access to the module schema
- WHEN the client calls GET on the objects API for the catalogue register and the module schema
- THEN it receives the same applications the Applications page lists for that user

#### Scenario: A write without rights is refused
- GIVEN a user without write access to the usage schema
- WHEN the client posts a usage to the objects API
- THEN the call is refused and no usage is stored

### Requirement: The look comes from the theme, not the code (REQ-PLAT-003)

stackiq's styles SHALL use the Nextcloud theme variables (and through them the NL Design System tokens thematiq supplies) for colours, fonts and radii, and SHALL NOT hard-code colour values in components except as the fallback of a `var()`. Provider: Nextcloud theming; stackiq inherits it through the CSS variables.

#### Scenario: An organisation applies its house style
- GIVEN an administrator sets a primary colour in Nextcloud theming
- WHEN a user opens the stackiq dashboard and the Applications page
- THEN buttons, links and active menu items use that colour

#### Scenario: A hard-coded colour is added
- GIVEN a component under `src/` with a hex colour outside a `var()` fallback
- WHEN stylelint runs
- THEN it fails on that line

### Requirement: Entries go to outside systems only from a chosen lifecycle state (REQ-PLAT-004)

An administrator SHALL be able to choose, per outside system, the lifecycle state from which stackiq sends an application ("Pas versturen vanaf levenscyclusstatus" on board SkInstellingenUitwisseling). Applications in an earlier state SHALL stay in stackiq. Provider: OpenRegister flows (an `object.updated` trigger and a filter node on the lifecycle status) and integriq's source call; stackiq ships the outbound flow template and writes the chosen state into its filter.

#### Scenario: An application in planning is not sent
- GIVEN the service desk exchange is set up with threshold "In gebruik"
- WHEN a user saves an application in use with lifecycle state "Gepland"
- THEN no message is sent to the service desk

#### Scenario: The application reaches the threshold
- GIVEN the same exchange
- WHEN the lifecycle state of that application changes to "In gebruik"
- THEN the outbound flow sends the application to the service desk once

#### Scenario: The threshold is changed
- GIVEN an administrator on the exchange settings
- WHEN they choose "Concept" and save
- THEN the filter of the published outbound flow uses "Concept" from the next change on

### Requirement: Another system is notified when a catalogue entry changes (REQ-PLAT-005)

The exchange settings SHALL show a Webhooks section that lists the webhooks on catalogue schemas with address, events and last delivery, and lets an administrator add, edit and remove one. A webhook SHALL fire on the chosen events (for example "Applicatie gewijzigd", "Contract gewijzigd", "Gebruik aangemaakt"). Provider: OpenRegister webhooks (delivery, retries, signing); stackiq lists and creates them for its own register and schemas only.

#### Scenario: An administrator adds a webhook
- GIVEN an administrator on the exchange settings
- WHEN they add a webhook with an address and the event "Applicatie gewijzigd"
- THEN the webhook is stored in OpenRegister for the module schema and appears in the list

#### Scenario: A change is delivered
- GIVEN that webhook
- WHEN a user changes an application
- THEN the address receives a message, and the list shows the status code and time of the last delivery

#### Scenario: A webhook of another app stays out
- GIVEN OpenRegister holds a webhook on another app's register
- WHEN the Webhooks section loads
- THEN that webhook is not listed

### Requirement: Every record keeps its history (REQ-PLAT-006)

Each catalogue detail page SHALL have a History tab that shows who changed the record, what changed and when, read from OpenRegister's audit trail for that object. Provider: OpenRegister audit trail; stackiq declares the tab (`widget type audit`) on its detail pages.

#### Scenario: A user reads an application's history
- GIVEN an application that a colleague changed yesterday
- WHEN the user opens the application and the History tab
- THEN the change is listed with the colleague's name, the changed fields and the time

#### Scenario: Every detail page has the tab
- GIVEN the merged manifest
- WHEN its detail pages are read
- THEN each one declares a History tab with an audit widget

### Requirement: The store adds configuration published by others (REQ-PLAT-007)

The Store page SHALL list the configuration sets and flows for stackiq that the configured registry offers, and let an administrator install one. Without a registry it SHALL show only the app's built-in items and make no network call. Provider: the OpenRegister store plane; stackiq declares the page and its store types (`openregister.configset`, `openregister.flows`) and writes no store controller.

#### Scenario: No registry is configured
- GIVEN a default install
- WHEN an administrator opens the Store page
- THEN the built-in items are listed and no outside request is made

#### Scenario: An administrator installs a flow
- GIVEN a registry that offers a stackiq flow
- WHEN the administrator installs it
- THEN the flow appears on the Flows page
