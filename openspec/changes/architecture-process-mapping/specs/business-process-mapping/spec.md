# business-process-mapping specification

**Status**: proposed
**Scope**: stackiq
**OpenSpec changes**:
- architecture-process-mapping

## Purpose

A municipality describes its business processes in stackiq, step by step, and links each step to the applications in use that support it. Steps carry a risk level and a compliance check. A process (schema.org `HowTo`) and its steps (schema.org `HowToStep`) are objects in the `stackiq` register (ADR-001), shown with `CnIndexPage`, `CnDetailPage` and a read-only `CnGraphCanvas` (ADR-012), with the status lifecycle declared on the schema (ADR-031).

## ADDED Requirements

### Requirement: REQ-BPM-001 A municipal information manager SHALL record a business process with ordered steps

Stackiq SHALL offer a Processes page at `/processen` (page `Processen`) over the `process` schema and a process page at `/processen/:id` (page `ProcesDetail`). A process SHALL have a name, a description, an owner picked from the organisation's contact roles, a status, tags and an optional GEMMA reference process. A step SHALL be a `processStep` object with the process, a name, a description, a step type (task, event or decision), a position and an optional list of steps it follows. The process page SHALL list its steps in position order. Processes and steps SHALL be scoped to the organisation that created them.

#### Scenario: An information manager adds a process with three steps
@e2e tests/e2e/workflows/process-mapping.spec.ts

- **GIVEN** a municipal information manager signed in to stackiq
- **WHEN** they open Architecture, then Processes, create the process Behandelen melding and add the steps Registreren, Beoordelen and Afhandelen at positions 1, 2 and 3
- **THEN** the process page SHALL list the three steps in that order
- **AND** the Processes page SHALL list the process with status draft

#### Scenario: Another municipality does not see the process
@e2e exclude The CI instance has one organisation; tests/Unit/Settings/ProcessMappingRegisterShapeTest.php asserts that the read rules of process and processStep match on _organisation, as usage does.

- **GIVEN** a process of municipality A
- **WHEN** a user of municipality B opens the Processes page
- **THEN** the process SHALL NOT be listed

### Requirement: REQ-BPM-002 A step SHALL name the applications in use that support it

A step SHALL hold a list of `usage` objects: the organisation's applications in use that support the step. The usage page `GebruikDetail` SHALL show a list "Process steps this application supports" with every step that names the usage, its process, its risk level and its compliance check, each row opening the step page at `/processtappen/:id` (page `ProcesStapDetail`). When a linked usage is not visible to the reader, the step page SHALL say how many linked applications are hidden.

#### Scenario: An application owner sees which process steps their application supports
@e2e tests/e2e/workflows/process-mapping.spec.ts

- **GIVEN** the seeded step Intake vergunningaanvraag linked to the seeded TOPdesk usage
- **WHEN** an application owner opens that usage at `/gebruik/:id`
- **THEN** the list "Process steps this application supports" SHALL show Intake vergunningaanvraag with the process Behandelen vergunningaanvraag
- **AND** choosing the row SHALL open the step page

#### Scenario: A hidden usage is counted, not dropped
@e2e exclude Needs two organisations with different rights; tests/vitest/processStepUsages.spec.js asserts that two stored usage uuids with one returned object give the notice "1 linked application is not visible to you".

- **GIVEN** a step linked to two usages, one of which the reader may not read
- **WHEN** the reader opens the step page
- **THEN** the page SHALL list the visible usage
- **AND** it SHALL show that one linked application is not visible to them

### Requirement: REQ-BPM-003 A step SHALL carry a risk level and a compliance check

Every `processStep` SHALL have a risk level (not assessed, low, medium or high, default not assessed), a risk note, a compliance check (not checked, compliant, not compliant or not applicable, default not checked), a compliance note and the date of the last check. The risk level and the compliance check SHALL be facetable, and the step list on the process page SHALL show both.

#### Scenario: An information manager marks a step as not compliant
@e2e tests/e2e/workflows/process-mapping.spec.ts

- **GIVEN** the step Besluiten vergunningaanvraag with compliance check not checked
- **WHEN** a municipal information manager edits the step, sets the risk level to high, the compliance check to not compliant and a compliance note, and saves
- **THEN** the step list on the process page SHALL show high and not compliant for that step
- **AND** the step page SHALL show the compliance note

#### Scenario: The step fields have defaults
@e2e exclude A schema default; tests/Unit/Settings/ProcessMappingRegisterShapeTest.php asserts the enums and defaults of riskLevel and complianceCheck.

- **GIVEN** the merged register
- **WHEN** a step is created without a risk level or a compliance check
- **THEN** it SHALL read not assessed and not checked

### Requirement: REQ-BPM-004 The process page SHALL show the steps as a read-only flow

The process page SHALL render the steps on a read-only `CnGraphCanvas`: one node per step with its name, type, risk level and supporting applications, and an edge from each step it follows, or from the step before it by position when it follows none. A step with a high risk or a not compliant check SHALL be marked in the error colour and in text.

#### Scenario: A reader sees the flow of a process
@e2e tests/e2e/workflows/process-mapping.spec.ts

- **GIVEN** the seeded process Behandelen vergunningaanvraag with three steps
- **WHEN** a municipal information manager opens its process page
- **THEN** the flow SHALL show three nodes connected in position order
- **AND** the node Besluiten vergunningaanvraag SHALL read high risk and not compliant

#### Scenario: A branch follows the follows list
@e2e exclude A pure mapping; tests/vitest/processStepFlow.spec.js asserts that a step whose follows list names two steps gets two incoming edges and no edge from the step before it by position.

- **GIVEN** a step that follows two earlier steps
- **WHEN** the flow is built
- **THEN** the step SHALL have one incoming edge from each of the two
- **AND** no edge from the step before it by position

### Requirement: REQ-BPM-005 A process SHALL move through a declared lifecycle and MAY follow a GEMMA reference process

The `process` status SHALL move through transitions declared as `x-openregister-lifecycle` on the schema: activate (draft to active), retire (active to retired) and reopen (retired to draft), with `from` and `to` values that are members of the status enum. A process MAY reference one AMEF `element` of type `BusinessProcess` as its GEMMA reference process, and the process page SHALL show that reference with a link to it.

#### Scenario: An owner activates a process
@e2e tests/e2e/workflows/process-mapping.spec.ts

- **GIVEN** a process in status draft
- **WHEN** its owner applies Activate on the process page
- **THEN** the process SHALL read active
- **AND** the transition SHALL appear in its History tab

#### Scenario: The lifecycle matches the enum
@e2e exclude The transition engine is OpenRegister's; tests/Unit/Settings/ProcessMappingRegisterShapeTest.php asserts every lifecycle from and to value is a member of the status enum.

- **GIVEN** the merged register
- **WHEN** the shape test reads the process lifecycle
- **THEN** every `from` and `to` value SHALL be a status enum value

#### Scenario: A process points at its GEMMA reference process
@e2e exclude The CI instance runs without a GEMMA import; tests/Unit/Settings/ProcessMappingRegisterShapeTest.php asserts that referenceProcess is a related element filtered on type BusinessProcess.

- **GIVEN** an imported GEMMA model with the process Bedrijfsproces Behandelen vergunningaanvraag
- **WHEN** an information manager picks it as the reference process of their process
- **THEN** the process page SHALL show the reference by name with a link to its element page
