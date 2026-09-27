# architecture-views-editor specification

**Status**: proposed
**Scope**: stackiq
**OpenSpec changes**:
- architecture-views-editor

## Purpose

An application owner draws ArchiMate views in stackiq instead of only importing them from Archi. Views carry tags, a status and an owner, so a municipality finds its views again, and saved versions can be compared to see what changed. Imported GEMMA views stay as VNG publishes them. Data lives in OpenRegister's AMEF register (ADR-001), the canvas is `CnGraphCanvas` (ADR-012), and the status lifecycle is declared on the schema (ADR-031).

## ADDED Requirements

### Requirement: REQ-AVE-001 An application owner SHALL draw and save an architecture view

Stackiq SHALL offer a view editor at `/views/:id` (page `ViewEditor`) on `CnGraphCanvas`. An application owner SHALL be able to create a view, place existing AMEF elements and new elements of an ArchiMate element type, connect two elements with an ArchiMate relation type, move and resize nodes, and save. The saved view SHALL be a `view` object in the `vng-gemma` register with `origin` set to `drawn`, and its diagram SHALL be stored in `xml.viewNodes` and `xml.viewRelationships` in the shape the ArchiMate import writes, so `ViewService` reads it unchanged. A new element SHALL be saved as an `element` object with `origin` set to `drawn`.

#### Scenario: An application owner draws a view and opens it again
@e2e tests/e2e/workflows/architecture-views.spec.ts

- **GIVEN** an application owner signed in to stackiq
- **WHEN** they open the Views page, choose New view, place two application components, connect them with a flow relation and save
- **THEN** the Views page SHALL list the view with status draft and origin drawn
- **AND** opening it again SHALL show both nodes at the saved positions and the connection between them

#### Scenario: A saved drawn view reads like an imported one
@e2e exclude The shape is not visible in the browser; tests/vitest/viewGraph.spec.js round-trips a drawn canvas and an imported GEMMA view through the store's save shape, and tests/Unit/Service/ViewServiceDrawnViewTest.php reads a drawn view through ViewService::transformView.

- **GIVEN** a drawn view saved by the editor
- **WHEN** `ViewService::transformView` reads it
- **THEN** every node SHALL carry `identifier`, `position` and `elementRef` as it does for an imported view
- **AND** every child node SHALL keep its `parent`

### Requirement: REQ-AVE-002 Imported GEMMA views SHALL open read-only and SHALL be copied before editing

A view with `origin` set to `imported` SHALL open in the editor without edit controls. Its Copy to edit action SHALL create a new `view` with a fresh identifier, `origin` set to `drawn`, `status` set to `draft` and `basedOn` set to the source view's uuid, and SHALL open the copy in edit mode. A later ArchiMate import SHALL NOT change a drawn view.

#### Scenario: A municipal information manager copies a GEMMA view to adapt it
@e2e tests/e2e/workflows/architecture-views.spec.ts

- **GIVEN** an imported GEMMA view on the Views page
- **WHEN** a municipal information manager opens it
- **THEN** the canvas SHALL show no edit controls and SHALL offer Copy to edit
- **AND** choosing Copy to edit SHALL open a new drawn view whose Based on field names the GEMMA view

#### Scenario: A re-import leaves a drawn copy alone
@e2e exclude An import needs a GEMMA model file and minutes of runtime; tests/Unit/Service/ArchitectureViewsImportIsolationTest.php imports a view whose identifier differs from the copy's and asserts the copy's nodes are unchanged.

- **GIVEN** a drawn copy of a GEMMA view
- **WHEN** a Nextcloud admin imports the GEMMA model again
- **THEN** the imported view SHALL be updated
- **AND** the drawn copy SHALL keep its nodes, connections and status

### Requirement: REQ-AVE-003 A drawn connection SHALL reference a relation object

When the user connects two elements, the editor SHALL reuse a `relation` object of the chosen type between the same source and target, or SHALL create one with `origin` set to `drawn`. The connection SHALL store that relation's id as `modelRelationshipId`, so the ArchiMate export writes a valid relationship reference.

#### Scenario: Connecting the same two elements twice reuses one relation
@e2e exclude The relation lookup is a store concern; tests/vitest/architectureViewStore.spec.js asserts one relation is created for the first connection and reused for the second.

- **GIVEN** a drawn view with a flow connection from Zaaksysteem to Documentbeheer
- **WHEN** the application owner draws a second flow connection between the same two elements on another view
- **THEN** no second `relation` object SHALL be created
- **AND** both connections SHALL carry the same `modelRelationshipId`

### Requirement: REQ-AVE-004 The views list SHALL filter on tag, status and owner

The `Views` page at `/views` SHALL be a `CnIndexPage` over the `view` schema with columns name, viewpoint, status, tags and origin. `tags` SHALL be a facetable list of strings, `status` a facetable enum of draft, in review, published and retired, and `origin` a facetable enum of imported and drawn. The page SHALL offer the quick filters All, Mine, Drawn and Imported, where Mine filters on the signed-in user as owner. The status transitions SHALL be declared as `x-openregister-lifecycle` on the `view` schema.

#### Scenario: A municipal information manager finds views by tag
@e2e tests/e2e/workflows/architecture-views.spec.ts

- **GIVEN** two drawn views, one tagged zaakgericht and one tagged financien
- **WHEN** a municipal information manager selects the tag zaakgericht in the Views sidebar
- **THEN** the list SHALL show only the view tagged zaakgericht

#### Scenario: Mine shows only the signed-in user's views
@e2e tests/e2e/workflows/architecture-views.spec.ts

- **GIVEN** a drawn view owned by the signed-in application owner and one owned by a colleague
- **WHEN** the application owner chooses the quick filter Mine
- **THEN** the list SHALL show only their own view

#### Scenario: A published view moves through the declared lifecycle
@e2e exclude The transition engine is OpenRegister's; tests/Unit/Service/ArchitectureViewsRegisterShapeTest.php asserts every lifecycle from and to value is a member of the status enum and the view schema version is bumped.

- **GIVEN** a drawn view in status in review
- **WHEN** the owner applies the publish transition
- **THEN** the view SHALL read published
- **AND** the transition SHALL appear in the view's History tab

### Requirement: REQ-AVE-005 An owner SHALL save named versions and compare two of them

The editor SHALL offer Save version, which SHALL write a `view-version` object with the view's uuid, the next version number, a label, the saving user, the time and a copy of the view's nodes and connections. Compare versions SHALL let the user pick two versions, or one version and the current view, and SHALL show both on read-only canvases with added items marked in the success colour, removed items in the error colour and changed items in the warning colour, next to a text list of the same changes. A node SHALL count as changed when its name, element, parent, position, size or style differs.

#### Scenario: An application owner sees what changed since the last version
@e2e tests/e2e/workflows/architecture-views.spec.ts

- **GIVEN** a drawn view with a saved version 1 holding two nodes
- **WHEN** the application owner adds a third node, saves, and compares version 1 with the current view
- **THEN** the third node SHALL be marked as added on the current side
- **AND** the text list SHALL read one added node, no removed nodes and no changed nodes

#### Scenario: A reordered node list is not a change
@e2e exclude A pure function; tests/vitest/viewDiff.spec.js asserts that two snapshots with the same nodes in a different order give no added, removed or changed entries.

- **GIVEN** two snapshots with the same nodes in a different order
- **WHEN** `viewDiff` compares them
- **THEN** it SHALL report no added, removed or changed nodes

### Requirement: REQ-AVE-006 Drawn views SHALL stay inside the organisation that drew them

Drawn views, elements and relations SHALL be scoped to the organisation that created them. `GET /api/views` SHALL NOT return a view with `origin` set to `drawn`, because its list is cached for all callers. The full ArchiMate export (`POST /api/archimate/export`) SHALL skip objects with `origin` set to `drawn`. The editor SHALL take OpenRegister's object lock before edit mode and SHALL show who holds the lock when another user has it.

#### Scenario: Another municipality does not see a drawn view
@e2e exclude The CI instance has one organisation; tests/Unit/Service/ViewServiceDrawnViewTest.php asserts the views query excludes origin drawn, and tests/Unit/Service/ArchiMateExportServiceDrawnFilterTest.php asserts the full export skips drawn objects.

- **GIVEN** a drawn view of municipality A
- **WHEN** a user of municipality B calls `GET /api/views` or a Nextcloud admin runs the full ArchiMate export
- **THEN** the drawn view SHALL NOT be in the response or in the exported file

#### Scenario: A second editor sees the lock
@e2e tests/e2e/workflows/architecture-views.spec.ts

- **GIVEN** an application owner editing a drawn view
- **WHEN** a colleague opens the same view
- **THEN** the colleague SHALL see the view read-only with a notice naming who is editing it
