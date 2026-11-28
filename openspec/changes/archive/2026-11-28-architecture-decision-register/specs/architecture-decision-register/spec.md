# architecture-decision-register specification

**Status**: proposed
**Scope**: stackiq
**OpenSpec changes**:
- architecture-decision-register

## Purpose

A municipality records its architecture decisions in stackiq with a review by a second person, and links each decision to the applications in use and the GEMMA elements it affects. Decisions are `architectureDecision` objects in the `stackiq` register (ADR-001) with a lifecycle and notifications declared on the schema (ADR-031), shown with `CnIndexPage` and `CnDetailPage` (ADR-012). A formal board decision stays in decidiq, and stackiq links to it.

## ADDED Requirements

### Requirement: REQ-ADREG-001 An information manager SHALL record an architecture decision with its context, options and consequences

Stackiq SHALL offer an Architecture decisions page at `/architectuurbesluiten` (page `Architectuurbesluiten`) and a decision page at `/architectuurbesluiten/:id` (page `ArchitectuurbesluitDetail`) under the Architecture menu group. A decision SHALL have a title, a context, the decision, the rejected alternatives each with a reason, the consequences, a category (application, data, integration, infrastructure, security or standards), an impact (low, medium or high), a status and a reviewer. Category, impact and status SHALL be facetable. Decisions SHALL be scoped to the organisation that recorded them.

#### Scenario: An information manager records a decision
@e2e tests/e2e/workflows/architecture-decisions.spec.ts

- **GIVEN** a municipal information manager signed in to stackiq
- **WHEN** they open Architecture, then Architecture decisions, create the decision Nieuwe koppelingen alleen via API's with category integration, impact medium, one rejected alternative and a reviewer, and save
- **THEN** the page SHALL list the decision with status draft
- **AND** its page SHALL show the rejected alternative with its reason

#### Scenario: Another municipality does not see the decision
@e2e exclude The CI instance has one organisation; tests/Unit/Settings/ArchitectureDecisionRegisterShapeTest.php asserts the read rules match on _organisation, as usage does.

- **GIVEN** a decision of municipality A
- **WHEN** a user of municipality B opens the Architecture decisions page
- **THEN** the decision SHALL NOT be listed

### Requirement: REQ-ADREG-002 A decision SHALL be reviewed by a second person through a declared lifecycle

The status SHALL move through transitions declared as `x-openregister-lifecycle`: submit (draft to in review), accept and reject (in review to accepted or rejected), rework (in review or rejected to draft), supersede (accepted to superseded) and deprecate (accepted to deprecated), with values that are members of the status enum. Submit SHALL be allowed only when the reviewer is a Nextcloud user other than the caller. Accept and reject SHALL be allowed only for the reviewer. Supersede SHALL be allowed only when the decision names an accepted decision that supersedes it. A denied transition SHALL answer 403 with the reason, whether it was applied as an action or as a direct edit of the status.

#### Scenario: A reviewer accepts a decision
@e2e tests/e2e/workflows/architecture-decisions.spec.ts

- **GIVEN** a decision in review whose reviewer is a second test user, created by the test fixture
- **WHEN** that reviewer opens the decision and applies Accept
- **THEN** the decision SHALL read accepted
- **AND** the transition SHALL appear in its History tab

#### Scenario: The author cannot accept their own decision
@e2e exclude A guard rule; tests/Unit/Lifecycle/ArchitectureDecisionReviewGuardTest.php asserts that submit is denied when the reviewer is the caller and that accept and reject are denied for anyone but the reviewer.

- **GIVEN** a decision in review whose reviewer is a colleague
- **WHEN** the author applies Accept
- **THEN** the transition SHALL be denied with a 403 naming the reviewer rule

#### Scenario: A decision is superseded only by an accepted one
@e2e exclude A guard rule; tests/Unit/Lifecycle/ArchitectureDecisionReviewGuardTest.php asserts supersede is allowed when supersededBy points at an accepted decision and denied otherwise.

- **GIVEN** an accepted decision whose supersededBy names a decision still in draft
- **WHEN** the owner applies Supersede
- **THEN** the transition SHALL be denied

### Requirement: REQ-ADREG-003 The reviewer and the owner SHALL be notified through declared notifications

The schema SHALL declare in `x-openregister-notifications` a notification to the reviewer when a decision enters in review, and a notification to the owners of the decision when it is accepted or rejected, on the channels Nextcloud notification and email, with Dutch and English subjects.

#### Scenario: A reviewer is told a decision waits for them
@e2e exclude Delivery runs in OpenRegister's engine; tests/Unit/Settings/ArchitectureDecisionRegisterShapeTest.php asserts the review-requested rule uses the field recipient reviewer and the review-concluded rule the object-acl manage recipient, and that both subjects have nl and en.

- **GIVEN** a decision with a colleague as reviewer
- **WHEN** the author submits it
- **THEN** the colleague SHALL receive a Nextcloud notification that links to the decision

### Requirement: REQ-ADREG-004 A decision SHALL link to the applications and elements it affects and to board decisions

A decision SHALL hold a list of the usages it affects, a list of the AMEF elements it affects, the decision it supersedes, the decision that supersedes it, and a list of decidiq decision ids with `x-external-register` decidesk. The usage page `GebruikDetail` and the standard page `StandaardDetail` SHALL each list the architecture decisions that name them.

#### Scenario: An application owner sees the decisions about their application
@e2e tests/e2e/workflows/architecture-decisions.spec.ts

- **GIVEN** the seeded accepted decision Eén zaaksysteem voor alle domeinen linked to the seeded Suite4 usage
- **WHEN** an application owner opens that usage at `/gebruik/:id`
- **THEN** the list Architecture decisions SHALL show the decision with status accepted
- **AND** choosing it SHALL open the decision page

#### Scenario: A decision links a board decision without copying it
@e2e exclude The CI instance runs without decidiq; tests/Unit/Settings/ArchitectureDecisionRegisterShapeTest.php asserts boardDecisions is a uuid list with x-external-register decidesk and referenceType decision, as catalogContract.decisions is.

- **GIVEN** a decision adopted by a board in decidiq
- **WHEN** the owner adds the decidiq decision to the architecture decision
- **THEN** the architecture decision SHALL store only the decidiq decision id
