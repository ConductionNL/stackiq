---
status: draft
retrofit: true
---

# Aangeboden Gebruik Api Specification

## Purpose

Captures observed behavior of the 'aangeboden gebruik' (offered usage) read/write API — the controller endpoints and their backing service that expose usage records from the perspective of afnemer (consumer), ambtenaar (civil servant), and deelnemer (participant).

## ADDED Requirements

### REQ-001: The system SHALL list offered-usage records scoped to the caller's role

The controller exposes role-scoped listings: `getGebruiksWhereAfnemer`, `getAllGebruiksForAmbtenaar`, `getGebruiksWhereDeelnemers`, and `getSingleGebruikForAmbtenaar`/`getKoppelingenGebruikByUuid` for single records. Each delegates to the matching `AangebodenGebruikService` method, which queries OpenRegister with the caller's active-organisation context and returns the usage records (and their koppelingen/links) as a JSON response.

#### Scenario: REQ-001 case 1
- WHEN `getGebruiksWhereAfnemer()` is called by an authenticated user
- THEN it MUST return the usage records where the active organisation is the afnemer

#### Scenario: REQ-001 case 2
- WHEN `getKoppelingenGebruikByUuid(uuid)` is called
- THEN it MUST return the koppelingen for that usage record

### REQ-002: The system SHALL allow assigning and removing offered-usage for the caller's active organisation

`setGebruikSelfToActiveOrg(gebruikId)` MUST attach the identified usage to the caller's active organisation; `deleteGebruikAsAfnemer(gebruikId)` MUST remove the caller's consumer link to that usage. Both delegate to the matching service method which performs the OpenRegister mutation and returns the result.

#### Scenario: REQ-002 case 1
- WHEN `setGebruikSelfToActiveOrg(id)` is called
- THEN the usage MUST be linked to the caller's active organisation

#### Scenario: REQ-002 case 2
- WHEN `deleteGebruikAsAfnemer(id)` is called
- THEN the caller's afnemer link to the usage MUST be removed

### REQ-003: The system SHALL publish API documentation for the offered-usage endpoints

`getApiDocumentation()` MUST return a machine-readable description of the offered-usage API surface.

#### Scenario: REQ-003 case 1
- WHEN `getApiDocumentation()` is called
- THEN it MUST return the offered-usage API documentation as a JSON response
