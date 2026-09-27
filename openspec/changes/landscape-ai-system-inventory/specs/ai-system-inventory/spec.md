# ai-system-inventory specification

**Status**: proposed
**Scope**: stackiq
**OpenSpec changes**:
- landscape-ai-system-inventory

## Purpose

The organisation keeps its AI agents, models and features next to the applications they run in, with their EU AI Act classification and evidence. Matrix rows `stackiq:land-ai-agent-inventory` and `stackiq:comp-ai-act-classification`.

## ADDED Requirements

### Requirement: REQ-AIS-001 An organisation registers the AI systems it uses next to their applications

Stackiq SHALL store an AI system with its name, kind (AI agent, AI model or AI feature), the application it runs in or supports, the supplier, its purpose and a status, and the application page SHALL list the AI systems linked to it.

#### Scenario: An information manager registers a chat assistant
@e2e tests/e2e/workflows/ai-systems.spec.ts

- **GIVEN** the municipality uses application X, which has a built-in chat assistant
- **WHEN** the information manager opens AI systems, clicks Add and saves "Chat assistant" of kind AI feature linked to X
- **THEN** the page of X lists "Chat assistant" in its AI systems section

### Requirement: REQ-AIS-002 An AI system carries its AI Act classification and evidence

An AI system SHALL record its EU AI Act risk category (prohibited, high risk, limited risk, minimal risk or not yet assessed), the organisation's role under the act, the date of the last assessment and a link to its algorithm register entry, and SHALL hold evidence files tagged FRIA, Technical documentation, Human oversight and Logging. The AI systems list SHALL filter on risk category.

#### Scenario: A privacy officer lists the high-risk systems
@e2e tests/e2e/workflows/ai-systems.spec.ts

- **GIVEN** two AI systems, one high risk and one minimal risk
- **WHEN** the privacy officer filters the AI systems list on high risk
- **THEN** only the high-risk system remains

### Requirement: REQ-AIS-003 A high-risk AI system without a fundamental rights impact assessment is flagged

The detail page of an AI system SHALL show which of the four evidence tags have a file, and a high-risk AI system without a FRIA file SHALL show a warning on its page and in the list.

#### Scenario: The missing assessment shows
@e2e tests/e2e/workflows/ai-systems.spec.ts

- **GIVEN** a high-risk AI system with technical documentation but no FRIA file
- **WHEN** the privacy officer opens its page
- **THEN** the evidence checklist marks FRIA as missing
- **AND** the AI systems list shows a warning on that row
