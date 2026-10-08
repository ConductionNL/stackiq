# application-knowledge-base specification

**Status**: proposed
**Scope**: stackiq
**OpenSpec changes**:
- insight-knowledge-base

## Purpose

An application owner keeps knowledge articles about an application in Nextcloud Collectives and links them to the application in stackiq. A municipal information manager finds those articles on the application page, or searches for them from the Knowledge base page under Applications.

## ADDED Requirements

### Requirement: REQ-AKB-001 The application detail page SHALL show the knowledge articles linked to the application

`ModuleDetail` SHALL carry a Knowledge articles widget on OpenRegister's `collectives` leaf. It SHALL list the Collectives pages linked to the application with title, collective, last change and a link that opens the page in Collectives. The existing Documentation files panel SHALL stay.

#### Scenario: An information manager reads the articles of an application
@e2e tests/e2e/spec-coverage/application-knowledge-base.spec.ts

- **GIVEN** an application with one linked Collectives page titled Restore procedure
- **WHEN** a municipal information manager opens `/modules/:id`
- **THEN** the Knowledge articles panel SHALL list Restore procedure with its collective
- **AND** the Documentation panel SHALL still be on the page

### Requirement: REQ-AKB-002 An application owner SHALL link an existing article or create a new one from the application page

From the Knowledge articles widget an application owner SHALL link a Collectives page they can read, or create a page in one of their collectives and link it in one step. Unlinking SHALL remove the link and keep the page in Collectives.

#### Scenario: An application owner writes a new article for an application
@e2e tests/e2e/spec-coverage/application-knowledge-base.spec.ts

- **GIVEN** an application owner on `/modules/:id` who is a member of the collective Application knowledge
- **WHEN** they choose to create an article in that collective titled Onboarding new users
- **THEN** a page Onboarding new users SHALL exist in Collectives
- **AND** the Knowledge articles panel SHALL list it

#### Scenario: Unlinking keeps the article
@e2e tests/e2e/spec-coverage/application-knowledge-base.spec.ts

- **GIVEN** an application with a linked article
- **WHEN** the application owner unlinks it
- **THEN** the panel SHALL no longer list it
- **AND** the page SHALL still open in Collectives

### Requirement: REQ-AKB-003 The Knowledge base page SHALL search the articles the user can read and open them in Collectives

A page `KnowledgeBase` at `/knowledge`, reached from a menu entry that is a child of Applications, SHALL show a search box. Typing SHALL list the Collectives pages whose title matches, across the collectives the user is a member of, each with a link that opens the page in Collectives. A hint SHALL say the search matches titles.

#### Scenario: An information manager finds an article by title
@e2e tests/e2e/spec-coverage/application-knowledge-base.spec.ts

- **GIVEN** a municipal information manager who is a member of a collective with a page Restore procedure
- **WHEN** they open `/knowledge` and type restore
- **THEN** the results SHALL list Restore procedure
- **AND** choosing it SHALL open the page in Collectives

#### Scenario: The entry sits under Applications
@e2e tests/e2e/spec-coverage/application-knowledge-base.spec.ts

- **GIVEN** an instance with Collectives installed
- **WHEN** a municipal information manager opens stackiq
- **THEN** Knowledge base SHALL be a child of the Applications menu entry and not a top-level entry

### Requirement: REQ-AKB-004 Without the Collectives app the knowledge base MUST be absent, not broken

When the Collectives app is not installed the Knowledge base menu entry SHALL be hidden, `/knowledge` SHALL say that Collectives is needed, and the Knowledge articles widget on `ModuleDetail` SHALL show the library's set-up state instead of an error.

#### Scenario: A Nextcloud admin without Collectives sees a set-up state
@e2e tests/e2e/spec-coverage/application-knowledge-base.spec.ts

- **GIVEN** an instance without the Collectives app
- **WHEN** a Nextcloud admin opens `/modules/:id`
- **THEN** the Knowledge articles panel SHALL show a set-up state that names Collectives
- **AND** the menu SHALL have no Knowledge base entry
