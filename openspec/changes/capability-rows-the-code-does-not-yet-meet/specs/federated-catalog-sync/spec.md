# federated-catalog-sync specification

## Purpose

An admin removes a peer catalogue. Matrix row `stackiq:share-federation-peers`.

## ADDED Requirements

### Requirement: An admin removes a peer catalogue (REQ-CLR-015)

The exchange settings SHALL offer a remove action per peer, restricted to admins. After removal the next pull SHALL NOT read that peer.

#### Scenario: An admin removes a peer
@e2e tests/e2e/workflows/federation-settings.spec.ts

- **GIVEN** the peer catalogue of Gemeente Buurdorp in the exchange settings
- **WHEN** an admin chooses Remove for that peer
- **THEN** the peer SHALL be gone from the list
- **AND** the next pull SHALL NOT read it
