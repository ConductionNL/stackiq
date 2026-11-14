# reference-component-coverage specification

**Status**: proposed
**Scope**: stackiq
**OpenSpec changes**:
- architecture-reference-component-coverage

## Purpose

A municipality sees, per GEMMA reference component, which of its applications in use fulfil it: none (a gap), one, or several (an overlap). It reads the result as a table, on a GEMMA view as a map, and as overlap in the portfolio rationalization report. The data stays in OpenRegister (ADR-001); the join between the organisation's usages and the GEMMA reference components runs in one bounded, organisation-scoped endpoint, because no declarative aggregation returns empty buckets (ADR-031).

## ADDED Requirements

### Requirement: REQ-RCC-001 Stackiq SHALL give each reference component a coverage state for one organisation

`GET /api/reference-component-coverage?organisation=<uuid>` SHALL return every reference component with the organisation's usages that name it in `usedForReferenceComponents` and a state: gap when no counting usage names it, covered when one module does, overlap when two or more different modules do. A usage SHALL count unless its status is Acquisition, Planned or Phased out. A gap SHALL be marked fillable when a module the organisation uses declares the component in `module.referenceComponents`. The response SHALL say how many usages name no component. The endpoint SHALL refuse a user who is not authorised for the organisation before it reads anything, with the same check the portfolio report uses, and SHALL offer the same rows as CSV with `format=csv`.

#### Scenario: Two applications for one component read as overlap
@e2e exclude The rule is a pure derivation; tests/Unit/Service/ReferenceComponentCoverageDerivationTest.php asserts gap, covered and overlap, that two usages of one module count once, and that Planned and Phased out usages do not count.

- **GIVEN** an organisation with two usages in production of different modules that both name the reference component Zaakregistratiecomponent
- **WHEN** the coverage is derived
- **THEN** Zaakregistratiecomponent SHALL read overlap with both applications
- **AND** a component no usage names SHALL read gap

#### Scenario: A gap the organisation could fill is marked
@e2e exclude A pure derivation; tests/Unit/Service/ReferenceComponentCoverageDerivationTest.php asserts that a gap is fillable when a used module lists the component in referenceComponents.

- **GIVEN** a usage of a module whose `referenceComponents` include Documentbeheercomponent, while no usage names Documentbeheercomponent
- **WHEN** the coverage is derived
- **THEN** Documentbeheercomponent SHALL read gap and fillable

#### Scenario: Another organisation's coverage is refused
@e2e exclude Needs a second organisation; tests/Unit/Controller/ReferenceComponentCoverageControllerTest.php asserts a 403 before any service call for a user of another organisation, and tests/Unit/Service/OrganisationReportAccessTest.php covers the shared rule.

- **GIVEN** a signed-in user whose organisation is municipality A
- **WHEN** they call `GET /api/reference-component-coverage?organisation=<uuid of municipality B>`
- **THEN** the response SHALL be 403
- **AND** no usage SHALL be read

### Requirement: REQ-RCC-002 A GEMMA plate SHALL show the components with their applications, with filters for gaps and overlaps

Stackiq SHALL offer a GEMMA plate page at `/gemma-plaat` (page `GemmaPlate`, board SkGemmaPlaat), in the menu under Reports & Compliance. After an organisation is picked it SHALL show the counts of reference components, covered components, gaps (with how many are fillable) and overlaps, the number of usages that name no component, and every reference component grouped by its GEMMA grouping, each with the applications that cover it and its state in words, with the filters All, Gaps, Fillable gaps and Overlap. Choosing a component SHALL show its description, state, applications and, for a fillable gap, the applications in use that offer it. A Download button SHALL give the same rows as CSV. Without any reference component it SHALL say that the GEMMA model must be imported.

#### Scenario: An information manager filters on overlap
@e2e tests/e2e/workflows/reference-component-coverage.spec.ts

- **GIVEN** two reference component elements and two usages of different modules that both name the first, created by the test fixture through OpenRegister's objects API
- **WHEN** a municipal information manager opens the GEMMA plate, picks the organisation and chooses the filter Overlap
- **THEN** the first component SHALL read overlap with both applications
- **AND** the second component, a gap, SHALL no longer show

#### Scenario: The page explains an instance without GEMMA
@e2e exclude Needs an instance without the GEMMA model, which the shared e2e instance has; tests/Unit/Service/ReferenceComponentCoverageServiceTest.php asserts that without an element schema no component is read, which the page renders as "Import the GEMMA model to see coverage".

- **GIVEN** an instance with no reference component elements
- **WHEN** a municipal information manager opens the GEMMA plate and picks an organisation
- **THEN** the page SHALL say that the GEMMA model must be imported to see coverage

### Requirement: REQ-RCC-003 The coverage SHALL be drawn on a GEMMA view as a map

The coverage page SHALL let the user pick an imported GEMMA view and SHALL draw it read-only on `CnGraphCanvas`. Every node of a reference component SHALL carry its state as a border colour (the error colour for a gap, the success colour for covered, the warning colour for an overlap) and as a text badge with the number and names of its applications.

#### Scenario: An information manager sees their applications on a GEMMA view
@e2e tests/e2e/workflows/reference-component-coverage.spec.ts

- **GIVEN** the fixture from REQ-RCC-002 and a view created by the fixture that holds both reference components
- **WHEN** the information manager picks that view on the coverage page
- **THEN** the node of the first component SHALL read overlap with two application names
- **AND** the node of the second SHALL read gap

#### Scenario: Colour is never the only signal
@e2e exclude A rendering rule; tests/vitest/coverageViewMap.spec.js asserts that every reference component node carries a text badge with its state and that colours are CSS variables.

- **GIVEN** a view with a gap, a covered and an overlap node
- **WHEN** the map renders
- **THEN** each of the three nodes SHALL carry its state in text

### Requirement: REQ-RCC-004 The portfolio rationalization report SHALL show overlapping applications

Each row of `GET /api/portfolio-report` SHALL carry the reference components its usage names and, per shared component, the other modules that cover it. The report SHALL count the rows with an overlap, the CSV SHALL have an `overlapsWith` column, and the Portfolio rationalization page SHALL list each overlapping component with its applications and mark overlapping rows.

#### Scenario: The portfolio report shows overlap next to ageing
@e2e tests/e2e/workflows/reference-component-coverage.spec.ts

- **GIVEN** the fixture from REQ-RCC-002
- **WHEN** a municipal information manager opens Portfolio rationalization and picks the organisation
- **THEN** an Overlap section SHALL list the first component with both applications
- **AND** both rows SHALL be marked as overlapping in the row list

#### Scenario: The CSV carries the overlap
@e2e exclude A file body; tests/Unit/Service/PortfolioReportServiceOverlapTest.php asserts the overlapsWith column holds "component: module" for an overlapping row and is empty for a row without overlap.

- **GIVEN** two overlapping rows and one row without overlap
- **WHEN** the CSV is built
- **THEN** the overlapping rows SHALL name the component and the other module in `overlapsWith`
- **AND** the third row SHALL leave it empty
