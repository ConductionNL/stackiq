# record-reconciliation Specification

## Purpose
A functional administrator finds applications, services and organisations that were recorded twice, from different sources or by different people, and merges them through OpenRegister's duplicate candidates page. Stackiq declares how to recognise a duplicate and how to merge it, keeps every catalogue reference pointing at the record that survives, and stops showing the merged one in its lists.

## Requirements

### Requirement: REQ-RRC-001 Applications, services and organisations SHALL declare duplicate rules, and applications and services SHALL declare how they merge

`module`, `catalogService` and `organization` SHALL carry `x-openregister-dedup` in their configuration. `module` and `catalogService` SHALL carry `x-openregister-merge` with `statusField` `recordStatus`, survivor status Active, merged status Merged and a reversal window of 30 days, and SHALL have the properties `recordStatus` and `mergedInto`.

#### Scenario: A duplicate application appears as a candidate
@e2e tests/e2e/spec-coverage/record-reconciliation.spec.ts

- **GIVEN** the demo applications Voorbeeld Name 1 and Voorbeeld name 1 from the same supplier with the same website
- **WHEN** a functional administrator opens OpenRegister's Duplicate candidates page for the catalogue register and the application schema
- **THEN** the two applications SHALL be listed as a pair above the threshold

#### Scenario: The declarations survive import
@e2e exclude A configuration shape; tests/Unit/Settings/ReconciliationDeclarationTest.php asserts the merged register holds both annotations with the values above and a higher version on both schemas.

- **GIVEN** the merged register
- **WHEN** `module` and `catalogService` are read
- **THEN** both SHALL carry `x-openregister-dedup` and `x-openregister-merge` in their configuration

### Requirement: REQ-RRC-002 The catalogue pages SHALL lead an administrator to OpenRegister's duplicate candidates

`/modules` and `/diensten` SHALL offer Find duplicates to Nextcloud admins and functional administrators, and the merge panel on an organisation's page SHALL offer it to Nextcloud admins (the only users who merge organisations), opening OpenRegister's Duplicate candidates page. Other users SHALL NOT see the action.

#### Scenario: A functional administrator goes to the candidates
@e2e tests/e2e/spec-coverage/record-reconciliation.spec.ts

- **GIVEN** a functional administrator on `/modules`
- **WHEN** they choose Find duplicates
- **THEN** OpenRegister's Duplicate candidates page SHALL open

#### Scenario: A regular user does not see the action
@e2e tests/e2e/spec-coverage/record-reconciliation.spec.ts

- **GIVEN** a municipal information manager who is not an admin and not a functional administrator
- **WHEN** they open `/modules`
- **THEN** the page SHALL NOT offer Find duplicates

### Requirement: REQ-RRC-003 After OpenRegister merges two applications or services, every catalogue reference SHALL point at the survivor

On `ObjectsMergedEvent` for `module` or `catalogService`, stackiq SHALL replace the merged uuid by the survivor's uuid in every reference the catalogue reference map lists for that schema, in scalar and array fields, without leaving the survivor twice in one array. It SHALL set `mergedInto` on the merged record and SHALL write one audit entry per moved reference with the merge operation id.

#### Scenario: Usages and connections follow the survivor
@e2e tests/e2e/spec-coverage/record-reconciliation.spec.ts

- **GIVEN** an application in use by Gemeente Voorbeeld and a connection, both on the duplicate application
- **WHEN** a functional administrator merges the duplicate into the original on OpenRegister's page
- **THEN** the usage and the connection SHALL point at the original
- **AND** the original's detail page SHALL list them

#### Scenario: An array does not get the survivor twice
@e2e exclude A data edge; tests/Unit/EventListener/CatalogueMergeListenerTest.php asserts a service that listed both applications lists the survivor once after the merge.

- **GIVEN** a service whose `modules` lists both the original and the duplicate
- **WHEN** the duplicate is merged into the original
- **THEN** the service's `modules` SHALL list the original once

### Requirement: REQ-RRC-004 Merged applications and services SHALL leave the lists and point readers to the survivor

`/modules` and `/diensten`, and their facet counts, SHALL leave out records whose `recordStatus` is Merged. The detail page of a merged record SHALL stay reachable and SHALL show Merged into with a link to the survivor.

#### Scenario: A reader opens an old link to a merged application
@e2e tests/e2e/spec-coverage/record-reconciliation.spec.ts

- **GIVEN** a merged duplicate application
- **WHEN** a municipal information manager opens its old `/modules/:id` link
- **THEN** the page SHALL show Merged into with a link to the original
- **AND** `/modules` SHALL NOT list the duplicate

### Requirement: REQ-RRC-005 The organisation merge MUST re-point every reference to the merged organisation

The organisation merge SHALL re-point every register reference to an organisation, including `module.provider`, `catalogService.provider`, `usage.provider`, `organization.deelnames`, `organization.participants` and `model.organizations`. The reference map SHALL be checked against the register so a reference the map misses fails a test.

#### Scenario: A merged supplier's applications follow it
@e2e exclude The merge walks every organisation reference; tests/Unit/Service/MergeOrganisatieServiceTest.php asserts module.provider and catalogService.provider are re-pointed in dry run and execute, and tests/Unit/Service/CatalogueReferenceMapTest.php asserts the map covers every $ref in the register.

- **GIVEN** two supplier organisations that are the same company, each with one application
- **WHEN** a Nextcloud admin merges one into the other
- **THEN** both applications SHALL have the survivor as `provider`
