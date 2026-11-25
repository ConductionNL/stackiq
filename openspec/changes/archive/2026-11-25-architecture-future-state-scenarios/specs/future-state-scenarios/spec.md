# future-state-scenarios specification

**Status**: proposed
**Scope**: stackiq
**OpenSpec changes**:
- architecture-future-state-scenarios

## Purpose

A municipality compares its landscape of today with its landscape on a future date: first as the data already plans it, then with a named scenario of its own on top. The comparison shows which applications come, go or are replaced, and which reference component gaps and overlaps that changes. An adopted scenario can be applied to the usages as planned dates and replacements. Scenarios are objects in the `stackiq` register (ADR-001) with a declared status lifecycle (ADR-031), shown with `CnIndexPage` and `CnDetailPage` (ADR-012).

## ADDED Requirements

### Requirement: REQ-FSS-001 Stackiq SHALL derive an organisation's landscape on any date from its usage dates

The landscape on a date SHALL hold every usage of the organisation whose phase on that date, derived from its phase start dates, is In production or To be phased out. A usage whose planned replacement date is on or before that date SHALL leave the landscape, and its planned replacement module SHALL enter it as a planned successor with the same reference components. A usage with no phase date but a status of In production or To be phased out SHALL be in today's landscape and in every later one until a date or a scenario change removes it. A usage with neither SHALL be counted as not dated and SHALL be in neither landscape. Today's landscape SHALL be the same rule with today's date.

#### Scenario: A planned replacement shows on its date
@e2e exclude A pure derivation; tests/Unit/Service/LandscapeAtDateDerivationTest.php asserts that a usage with plannedReplacementDate 2027-03-01 is in the landscape on 2027-02-28 and replaced by its successor on 2027-03-01.

- **GIVEN** a usage in production with a planned replacement by another module on 2027-03-01
- **WHEN** the landscape is derived for 2027-02-28 and for 2027-03-01
- **THEN** the first SHALL hold the usage
- **AND** the second SHALL hold the successor module instead, with the usage's reference components

#### Scenario: A usage with only a status counts, one with nothing is counted apart
@e2e exclude A pure derivation; tests/Unit/Service/LandscapeAtDateDerivationTest.php asserts that a usage with status In production and no dates is in both landscapes, and that a usage with neither a date nor a current status is in neither and raises the not dated count by one.

- **GIVEN** a usage with status In production and no dates, and a usage with no phase date and the status in-gebruik
- **WHEN** today's landscape and a future landscape are derived
- **THEN** the first SHALL be in both landscapes
- **AND** the second SHALL be in neither, and the not dated count SHALL be one

### Requirement: REQ-FSS-002 An information manager SHALL compare today's landscape with the plan on a date

`GET /api/landscape-comparison?organisation=<uuid>&date=<date>` SHALL return the application differences between today and the date (added, removed, replaced by, unchanged, each with its date and the source plan), the reference components whose coverage state differs, and the not dated count. It SHALL refuse a user not authorised for the organisation before it reads anything. The page `LandscapeComparison` at `/landscape-comparison`, opened from a Compare with the plan button on the Portfolio roadmap, SHALL show both landscapes side by side with these differences in text.

#### Scenario: An information manager sees what the plan changes by next summer
@e2e tests/e2e/workflows/future-state-scenarios.spec.ts

- **GIVEN** a usage of the test organisation with a planned replacement on 2027-03-01, created by the test fixture
- **WHEN** a municipal information manager opens Portfolio roadmap, chooses Compare with the plan and picks 2027-06-30
- **THEN** the comparison SHALL list the usage as replaced by its successor with source plan
- **AND** today's column SHALL still hold the usage

#### Scenario: Another organisation's comparison is refused
@e2e exclude Needs a second organisation; tests/Unit/Controller/LandscapeComparisonControllerTest.php asserts a 403 before any service call through OrganisationReportAccess.

- **GIVEN** a user of municipality A
- **WHEN** they call `GET /api/landscape-comparison` for municipality B
- **THEN** the response SHALL be 403

### Requirement: REQ-FSS-003 An information manager SHALL write a scenario of additions, phase-outs and replacements

Stackiq SHALL offer a Scenarios page at `/scenarios` (page `Scenarios`) and a scenario page at `/scenarios/:id` (page `ScenarioDetail`) under the Architecture menu group. A scenario SHALL have a name, a description, an organisation, a target date and a status. A scenario change SHALL be one of add (a module with the reference components it will be used for), phase out (a usage) or replace (a usage by a module), with an optional effective date that defaults to the target date. The scenario page SHALL list its changes and SHALL show the comparison between today and the scenario landscape, where the scenario landscape is the plan on the target date with the changes applied, and every difference SHALL name its source, plan or scenario.

#### Scenario: An information manager tries a replacement
@e2e tests/e2e/workflows/future-state-scenarios.spec.ts

- **GIVEN** a usage in production of the test organisation and a second module, created by the test fixture
- **WHEN** a municipal information manager opens Architecture, then Scenarios, creates a scenario for the test organisation with a target date next year, and adds a change that replaces the usage by the second module
- **THEN** the scenario page SHALL list the change
- **AND** the comparison SHALL show the usage as replaced by the second module with source scenario

#### Scenario: A scenario change wins over the plan
@e2e exclude A pure derivation; tests/Unit/Service/LandscapeAtDateDerivationTest.php asserts that a scenario phase out of a usage that the plan replaces reads as removed with both sources named.

- **GIVEN** a usage the plan replaces on the target date and a scenario that phases it out
- **WHEN** the scenario landscape is derived
- **THEN** the usage SHALL be removed with the source scenario
- **AND** the difference SHALL also name the plan

### Requirement: REQ-FSS-004 A scenario SHALL move through a declared lifecycle and an adopted scenario SHALL be applied to the landscape

The `scenario` status SHALL move through transitions declared as `x-openregister-lifecycle`: propose (draft to proposed), adopt (proposed to adopted), reject (proposed to rejected) and rework (proposed or rejected to draft), with values that are members of the status enum. An adopted scenario without an applied date SHALL offer Apply to landscape, which SHALL write each change as the signed-in user: add creates a usage with status Planned and the effective date as its production start, phase out sets the usage's phased out date, and replace sets its planned replacement and date. Each applied change SHALL record the usage it wrote, and a retry SHALL skip it. The scenario SHALL record when it was applied.

#### Scenario: An information manager applies an adopted scenario
@e2e tests/e2e/workflows/future-state-scenarios.spec.ts

- **GIVEN** a proposed scenario of the test organisation, created by the test fixture, that adds a module on 2027-03-01 and phases out a usage on 2027-06-30
- **WHEN** a municipal information manager applies Adopt and then Apply to landscape
- **THEN** a new usage of that module SHALL exist with status Planned and production start 2027-03-01
- **AND** the phased out usage SHALL carry the phased out date 2027-06-30
- **AND** Apply to landscape SHALL no longer be offered

#### Scenario: A retried apply writes nothing twice
@e2e exclude A store rule; tests/vitest/scenarioApply.spec.js asserts that a change with appliedTo is skipped and that appliedAt is set only after the last write.

- **GIVEN** an apply that failed after its first change was written
- **WHEN** the information manager applies again
- **THEN** the first change SHALL NOT be written again
- **AND** the scenario SHALL get its applied date once every change is written

#### Scenario: The lifecycle matches the enum
@e2e exclude The transition engine is OpenRegister's; tests/Unit/Settings/ScenarioRegisterShapeTest.php asserts every lifecycle from and to value is a member of the status enum.

- **GIVEN** the merged register
- **WHEN** the shape test reads the scenario lifecycle
- **THEN** every `from` and `to` value SHALL be a status enum value
