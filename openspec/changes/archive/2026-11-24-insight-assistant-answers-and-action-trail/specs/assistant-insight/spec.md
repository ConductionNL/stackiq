# assistant-insight specification

## Purpose

A user asks the catalogue a question and sees which entries the answer rests on, and an administrator sees what AI assistants did to catalogue records. Matrix rows `stackiq:ins-natural-language-query` and `stackiq:ins-ai-action-audit`.

## ADDED Requirements

### Requirement: REQ-ASK-001 An answer lists the catalogue entries it used

Stackiq SHALL answer a plain-language question by searching the catalogue with the asking user's own rights through OpenRegister, sending the matching entries to hermiq's converse endpoint as grounding, and returning the reply with the entries it cites, each linked to its stackiq page. Stackiq MUST NOT send hermiq an entry the user cannot read, and the Ask path MUST NOT change any object.

#### Scenario: A user asks which applications a supplier offers
@e2e tests/e2e/workflows/ask-the-catalogue.spec.ts

- **GIVEN** hermiq is installed and the catalogue holds three published applications of supplier "Centric"
- **WHEN** a user opens Ask and asks "Which applications does Centric offer?"
- **THEN** the reply names the three applications
- **AND** the Sources list shows each of them with a link to its application page

#### Scenario: An entry the user cannot read is never sent
@e2e exclude RBAC case; tests/Unit/Service/AskServiceTest.php asserts the search runs as the caller and contextData holds only what it returned.

- **GIVEN** a usage of another organisation that the user may not read
- **WHEN** the user asks about that organisation's landscape
- **THEN** the usage is not in the grounding sent to hermiq
- **AND** it is not in the Sources list

#### Scenario: Nothing matched
@e2e tests/e2e/workflows/ask-the-catalogue.spec.ts

- **WHEN** a user asks a question no entry matches
- **THEN** the panel answers "No catalogue entries matched this question."
- **AND** hermiq is not called

### Requirement: REQ-ASK-002 The Ask panel is only offered when hermiq is installed

Stackiq SHALL show the Ask menu entry and page only when hermiq is enabled for the user, and `POST /api/ask` SHALL answer 503 with a clear message when it is not.

#### Scenario: Hermiq is missing
@e2e exclude needs hermiq disabled on the instance; tests/Unit/Controller/AskControllerTest.php covers the 503.

- **GIVEN** hermiq is not installed
- **WHEN** a user opens stackiq
- **THEN** no Ask entry is shown
- **AND** a direct call to `POST /api/ask` answers 503

### Requirement: REQ-ASK-003 Assistant actions on catalogue records are listed

Stackiq SHALL offer an Assistant actions page listing every OpenRegister audit record whose action starts with `mcp.` and whose tool id starts with `stackiq.`, with when, user, tool, linked record and outcome, filterable by tool, user and period. The page SHALL read the audit trail through OpenRegister and MUST NOT keep a copy.

#### Scenario: An administrator reviews what an assistant changed
@e2e tests/e2e/workflows/assistant-actions.spec.ts

- **GIVEN** a user's assistant submitted contract "2025-0042" for renewal through `stackiq.submitContractApproval` on 6 Oct 2026
- **WHEN** a stackiq administrator opens Assistant actions and filters on that tool
- **THEN** one row shows 6 Oct 2026, the user, the tool and contract "2025-0042"
- **AND** the record link opens the contract page

#### Scenario: A user sees only their own assistant's actions
@e2e exclude RBAC case enforced by OpenRegister's audit trail rules; tests/Unit covers that stackiq adds no wider read.

- **GIVEN** two users whose assistants both acted on catalogue records
- **WHEN** one of them, without admin rights, opens Assistant actions
- **THEN** only that user's rows are listed

### Requirement: REQ-ASK-004 The History tab marks assistant actions

On a record's History tab, an entry with an `mcp.` action SHALL read as an assistant action naming its tool, when the library audit widget supports a label per action.

#### Scenario: An assistant action in a record's history
@e2e exclude depends on the nextcloud-vue audit widget; covered in that library once the label map exists.

- **GIVEN** an assistant read application "Zaaksysteem X" through `stackiq.module.get`
- **WHEN** a user opens the History tab of "Zaaksysteem X"
- **THEN** the entry reads "Assistant: stackiq.module.get"
