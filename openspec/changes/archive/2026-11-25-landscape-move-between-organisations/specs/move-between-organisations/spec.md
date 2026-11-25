# move-between-organisations specification

**Status**: proposed
**Scope**: stackiq
**OpenSpec changes**:
- landscape-move-between-organisations

## Purpose

Entries registered under the wrong organisation move to the right one, one or many at a time, keeping their identity. Matrix row `stackiq:land-move-between-workspaces`.

## ADDED Requirements

### Requirement: REQ-MBO-001 An administrator moves chosen entries to another organisation and they keep their identity

Stackiq SHALL move a chosen set of applications, services, usages, connections, contracts, compliance claims and contact persons from one organisation to another by re-pointing `@self.organisation` and the type's owning field, keeping each entry's uuid, history, files and relations.

#### Scenario: A functional administrator moves two applications
@e2e tests/e2e/workflows/move-to-organisation.spec.ts

- **GIVEN** a functional administrator who administers supplier A and supplier B, and two applications registered under A that belong to B
- **WHEN** they select both on the Applications list, pick Move to organisation, choose B and confirm
- **THEN** both applications show supplier B
- **AND** their pages open at the same addresses with their history

### Requirement: REQ-MBO-002 Only an administrator of both organisations moves entries

`POST /api/ownership-transfers/plan` and `/api/ownership-transfers/execute` SHALL answer 403 unless the caller is a Nextcloud admin, or an organisation admin who is a member of both the source and the target organisation. A refused call SHALL change nothing.

#### Scenario: An administrator of one side is refused
@e2e exclude API guard; tests/Unit/Controller/OwnershipTransferControllerTest.php asserts the 403 and that no object was saved.

- **GIVEN** a user who administers organisation A only
- **WHEN** they post a transfer of an A entry to organisation B
- **THEN** the answer is 403

### Requirement: REQ-MBO-003 The dry run shows what moves and what stays behind

Before a transfer, stackiq SHALL list each chosen entry as moving or skipped with the reason, and SHALL list linked entries of the source organisation that stay behind.

#### Scenario: Connections that stay behind are named
@e2e tests/e2e/workflows/move-to-organisation.spec.ts

- **GIVEN** a usage of organisation A with one connection owned by A
- **WHEN** the administrator opens Move to organisation for that usage and picks organisation B
- **THEN** the dialog lists the usage under moving
- **AND** lists the connection under staying with organisation A
