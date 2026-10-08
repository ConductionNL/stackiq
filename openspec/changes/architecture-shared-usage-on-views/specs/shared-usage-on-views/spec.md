# shared-usage-on-views specification

**Status**: proposed
**Scope**: stackiq
**OpenSpec changes**:
- architecture-shared-usage-on-views

## Purpose

An information manager sees, on a GEMMA view, which applications fill each reference component, and can tell at a glance which ones the organisation runs itself and which it uses through a partner. The same difference shows when the organisation's export is opened in Archi.

## ADDED Requirements

### Requirement: REQ-SUV-001 The GEMMA view page MUST let a user show own and shared applications on the view

The read-only GEMMA view page SHALL offer a "Show applications" control with two switches, "Our applications" and "Shared with partners", both off when the page opens. With a switch on, the page SHALL request `GET /api/views/{viewId}` with `include_gebruik` or `include_deelnames_gebruik` set, and SHALL draw each usage as a node inside the reference component it belongs to.

#### Scenario: An information manager shows the shared applications
@e2e tests/e2e/spec-coverage/shared-usage-on-views.spec.ts

- **GIVEN** a GEMMA view with reference component "Zaakregistratiecomponent", and the organisation uses application "Zaaksysteem X" there through partner "Gemeente Utrecht"
- **WHEN** the information manager opens the view and switches on "Shared with partners"
- **THEN** a node "Zaaksysteem X" SHALL appear inside "Zaakregistratiecomponent"
- **AND** the node SHALL show the text "Shared"

#### Scenario: The view opens without overlays
@e2e tests/e2e/spec-coverage/shared-usage-on-views.spec.ts

- **GIVEN** a GEMMA view with own and shared usage
- **WHEN** a user opens the view
- **THEN** both switches SHALL be off
- **AND** no application node SHALL be drawn

### Requirement: REQ-SUV-002 A shared application MUST be drawn apart from an own application without relying on colour

A shared node SHALL have a dashed border, the visible text "Shared", and an accessible name "<application>, shared by <partner>". An own node SHALL have a solid border and no "Shared" text. A legend under the canvas SHALL explain both. Colours SHALL come from Nextcloud CSS variables.

#### Scenario: Own and shared look different on the same component
@e2e tests/e2e/spec-coverage/shared-usage-on-views.spec.ts

- **GIVEN** a reference component that holds own application "Suite A" and shared application "Suite B" from partner "Gemeente Utrecht"
- **WHEN** both switches are on
- **THEN** "Suite A" SHALL have a solid border and no "Shared" text
- **AND** "Suite B" SHALL have a dashed border and the text "Shared"
- **AND** the legend SHALL list "Our application" and "Shared with a partner"

#### Scenario: A screen reader user hears who shares the application
@e2e tests/e2e/spec-coverage/shared-usage-on-views.spec.ts

- **GIVEN** shared application "Suite B" from partner "Gemeente Utrecht" is drawn
- **WHEN** keyboard focus reaches its node
- **THEN** its accessible name SHALL be "Suite B, shared by Gemeente Utrecht"

#### Scenario: An application both owned and shared is drawn once as own
@e2e exclude The API already drops the shared copy (lib/Service/ViewService.php:467-469); tests/vitest/usageOverlay.spec.js asserts the mapping draws one own node.

- **GIVEN** the organisation runs "Suite A" itself and also uses it through a partner on the same component
- **WHEN** both switches are on
- **THEN** exactly one "Suite A" node SHALL be drawn on that component, as own

### Requirement: REQ-SUV-003 The organisation ArchiMate export MUST draw shared applications apart from own ones

When the organisation export includes deelnames, every shared application nested in a view copy SHALL get a fill and line colour different from own applications, and its element SHALL carry the property `Gedeeld door` with the partner's name. An application both owned and shared for the same reference component SHALL be nested once, with the own style.

#### Scenario: Archi shows shared applications in their own colour
@e2e exclude The export is an XML download; tests/Unit/Service/ArchiMateExportSharedUsageStyleTest.php checks the generated nodes and properties.

- **GIVEN** an organisation with own application "Suite A" and shared application "Suite B" on the same reference component
- **WHEN** an admin runs the organisation export with Deelnames ticked
- **THEN** the nested node for "Suite A" SHALL have fillColor 200,255,200
- **AND** the nested node for "Suite B" SHALL have fillColor 210,225,255
- **AND** the element "Suite B" SHALL have the property `Gedeeld door` with value "Gemeente Utrecht"

#### Scenario: The export without deelnames is unchanged
@e2e exclude Covered by tests/Unit/Service/ArchiMateExportSharedUsageStyleTest.php.

- **GIVEN** the same organisation
- **WHEN** the export runs with Deelnames not ticked
- **THEN** only own applications SHALL be nested, with the own style, as before this change
