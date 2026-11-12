# ai-system-inventory specification

## Purpose

An AI system names the business processes it supports. Matrix row `stackiq:land-ai-agent-inventory`.

## ADDED Requirements

### Requirement: An AI system names the business processes it supports (REQ-CLR-006)

The AI system schema SHALL hold a list of business processes it supports. The AI system page SHALL list those processes, and a business process page SHALL list the AI systems that support it.

#### Scenario: An information manager links an AI agent to a process
@e2e tests/e2e/workflows/ai-systems.spec.ts

- **GIVEN** an AI agent Dossierassistent and a business process Bezwaar behandelen
- **WHEN** an information manager adds Bezwaar behandelen to the agent's processes and saves
- **THEN** the page of Bezwaar behandelen SHALL list Dossierassistent
