# architecture-assistant-views specification

**Status**: proposed
**Scope**: stackiq
**OpenSpec changes**:
- architecture-assistant-drafted-views

## Purpose

A user asks Hermiq's assistant to draw a view, and the assistant drafts it through two stackiq MCP tools: one finds the AMEF elements that fit, one writes the draft. The draft is an ordinary `view` in status draft, marked as drafted by an assistant in the same write (ADR-088), and a person reviews it in the view editor from `architecture-views-editor`. The chat, the model, the agent's grants and the approval gate are Hermiq's (ADR-034, ADR-063). Stackiq owns the tools, the input checks and the mark.

## ADDED Requirements

### Requirement: REQ-AAV-001 Stackiq SHALL offer a read tool that finds architecture elements for a draft

The provider `lib/Mcp/StackiqToolProvider.php` SHALL list `stackiq.searchArchitectureElements` with scope read, reach user and `readOnlyHint` true. It SHALL take a search text, an optional list of ArchiMate element types and a limit of at most 50, and SHALL return for each match the uuid, the ArchiMate identifier, the name, the ArchiMate type and the GEMMA type. It SHALL read through OpenRegister with RBAC and multitenancy on, so it returns only elements the caller may read.

#### Scenario: A sibling app finds the GEMMA element for a case system
@e2e exclude The tool is called over MCP, not through a page; tests/Unit/Service/ArchitectureViewDraftServiceTest.php asserts the projection fields and the type filter, and tests/Unit/Mcp/StackiqToolProviderDraftToolsTest.php asserts the descriptor's scope, reach and hints.

- **GIVEN** the imported GEMMA model holds an application component named Zaaksysteem
- **WHEN** Hermiq, the sibling app, calls `stackiq.searchArchitectureElements` with the text zaak and the type ApplicationComponent in a signed-in user's session
- **THEN** the result SHALL list Zaaksysteem with its uuid, identifier, name, ArchiMate type and GEMMA type
- **AND** the result SHALL hold no other element fields

### Requirement: REQ-AAV-002 Stackiq SHALL offer a create tool that writes a draft view from elements and relations

The provider SHALL list `stackiq.draftView` with scope create, reach instance, `readOnlyHint` false, `destructiveHint` false and `idempotentHint` false. It SHALL take a name, a description, a list of elements (an existing element uuid, or a new ArchiMate element type and name, each with a key) and a list of relations (a source key, a target key and an ArchiMate relation type). It SHALL check the whole input before it writes anything: an unknown element or relation type, a key no element declares, more than 60 elements or more than 120 relations SHALL return an error result and SHALL write nothing. On success it SHALL write one `view` in status draft, reuse a `relation` of the same type between the same two elements or create one, reuse an existing element of the same type and exact name in the caller's scope or create one, and SHALL return the view's uuid, its editor link `/apps/stackiq/views/<uuid>` and the number of elements and relations it created.

#### Scenario: An assistant drafts a view and hands back a link
@e2e tests/e2e/workflows/architecture-assistant-views.spec.ts

- **GIVEN** an application owner signed in to stackiq and the seeded elements Zaaksysteem and Documentbeheer
- **WHEN** a tool call to `stackiq.draftView` on OpenRegister's MCP endpoint `POST /apps/openregister/api/mcp` names both elements and a Flow relation between them
- **THEN** the result SHALL carry a view uuid and the link `/apps/stackiq/views/<uuid>`
- **AND** the Views page SHALL list the new view with status draft

#### Scenario: A dangling key writes nothing
@e2e exclude Input checks are a service concern; tests/Unit/Service/ArchitectureViewDraftServiceTest.php asserts that a relation naming an undeclared key, an unknown type and a list over the cap each return an error and call no save.

- **GIVEN** a draft request whose relation names the target key c while only the keys a and b are declared
- **WHEN** Hermiq calls `stackiq.draftView`
- **THEN** the result SHALL be an error that names the key c
- **AND** no `view`, `element` or `relation` object SHALL be written

#### Scenario: A new element with the name of an existing one is reused
@e2e exclude The match rule is a service concern; tests/Unit/Service/ArchitectureViewDraftServiceTest.php asserts that a new ApplicationComponent named Documentbeheer resolves to the existing element of that type and name.

- **GIVEN** an element of type ApplicationComponent named Documentbeheer in the caller's scope
- **WHEN** a draft request asks for a new ApplicationComponent named Documentbeheer
- **THEN** the view SHALL reference the existing element
- **AND** the result SHALL report zero created elements

### Requirement: REQ-AAV-003 Every object the draft tool writes SHALL carry the assistant mark in the same write

Every `view`, `element` and `relation` that `stackiq.draftView` creates SHALL carry `origin` set to `assistant` in the save that creates it. The view SHALL also carry `draftedFor`, the Nextcloud user id of the session the tool ran in, and `draftedAt`. A write that fails SHALL return an error result, and no object SHALL be saved first and marked later (ADR-088). A person who edits a drafted view SHALL NOT remove the mark.

#### Scenario: A reviewer sees who the draft was made for
@e2e tests/e2e/workflows/architecture-assistant-views.spec.ts

- **GIVEN** a view drafted through `stackiq.draftView` in the session of an application owner
- **WHEN** the application owner opens it in the view editor at `/views/<uuid>`
- **THEN** the editor SHALL show the notice "Drafted by an assistant for" with their name and the date
- **AND** the notice SHALL still show after they move a node and save

#### Scenario: New elements and relations carry the mark
@e2e exclude The mark sits in the saved objects; tests/Unit/Service/ArchitectureViewDraftServiceTest.php asserts every saveObject call for a new view, element and relation carries origin assistant in the same payload.

- **GIVEN** a draft request with one new element and one new relation
- **WHEN** `stackiq.draftView` writes it
- **THEN** the new element and the new relation SHALL each carry `origin` assistant
- **AND** the reused elements SHALL keep their own `origin`

### Requirement: REQ-AAV-004 A drafted view SHALL be laid out when it is first opened

The draft tool SHALL write nodes without positions. When the view editor opens a view whose nodes need a full layout, it SHALL place them with the shared library's layered layout, and the first save by a person SHALL store the positions. The Views page SHALL offer `assistant` in its origin facet.

#### Scenario: An application owner opens a fresh draft
@e2e tests/e2e/workflows/architecture-assistant-views.spec.ts

- **GIVEN** a view drafted with three elements and two relations and no positions
- **WHEN** the application owner opens it in the view editor
- **THEN** the three nodes SHALL render at distinct positions with both connections drawn
- **AND** choosing the origin assistant in the Views sidebar SHALL list the draft

### Requirement: REQ-AAV-005 The draft tools SHALL run with the caller's rights and SHALL keep drafts out of shared readers

Both tools SHALL run in the caller's Nextcloud session with no substitute account (ADR-034 Decision 7). A caller without create rights on `view` SHALL get a forbidden result and nothing SHALL be written. A drafted view SHALL belong to the caller's active organisation. `GET /api/views` and the full ArchiMate export SHALL leave out views with `origin` assistant, as they leave out drawn views.

#### Scenario: A caller without create rights gets a forbidden result
@e2e exclude The CI instance runs as admin; tests/Unit/Service/ArchitectureViewDraftServiceTest.php asserts that a forbidden save from OpenRegister's ObjectService returns a forbidden result and that no later save runs.

- **GIVEN** a signed-in user whose groups may read but not create `view` objects
- **WHEN** Hermiq calls `stackiq.draftView` in that user's session
- **THEN** the result SHALL be forbidden
- **AND** no object SHALL be written

#### Scenario: A draft stays out of the shared views list
@e2e exclude The shared readers are PHP; tests/Unit/Service/ViewServiceDrawnViewTest.php and tests/Unit/Service/ArchiMateExportServiceDrawnFilterTest.php gain a case with origin assistant and assert it is left out.

- **GIVEN** a view drafted by an assistant
- **WHEN** another user calls `GET /api/views` or a Nextcloud admin runs the full ArchiMate export
- **THEN** the drafted view SHALL NOT be in the response or in the exported file
