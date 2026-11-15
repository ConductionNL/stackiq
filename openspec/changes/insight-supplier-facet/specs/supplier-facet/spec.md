# supplier-facet specification

**Status**: proposed
**Scope**: stackiq
**OpenSpec changes**:
- insight-supplier-facet

## Purpose

A municipal information manager narrows the Applications and Services lists by supplier, next to the four GEMMA facets, and sees how many applications or services each supplier has in the current selection.

## ADDED Requirements

### Requirement: REQ-SFC-001 The facet endpoint SHALL return a supplier facet with a count per organisation

`GET /api/facets/{schema}` SHALL return a `supplier` bucket next to `referenceComponent`, `standard`, `applicationService` and `domain`, always present and empty when no object has a supplier. For `module` a supplier SHALL be the organisation in `provider`; for `catalogService` it SHALL be the service's own `provider`. Each entry SHALL carry the organisation id as `value`, the organisation name as `label` (the id when the organisation has no name) and the count. The count SHALL cover the objects the caller may read under the other selected facets and the search, and SHALL NOT be narrowed by the supplier selection itself.

#### Scenario: Counts per supplier on the applications facet
@e2e exclude The endpoint shape is asserted in tests/Unit/Service/FacetServiceTest.php, which feeds three modules with two suppliers and checks the bucket values, labels and counts.

- **GIVEN** three applications, two supplied by Voorbeeld Name 2 and one by Voorbeeld Name 3
- **WHEN** a municipal information manager's page calls `GET /api/facets/module`
- **THEN** the `supplier` bucket SHALL hold Voorbeeld Name 2 with 2 and Voorbeeld Name 3 with 1
- **AND** each entry's `value` SHALL be the organisation id

#### Scenario: Two suppliers with the same name stay apart
@e2e exclude A data edge; tests/Unit/Service/FacetServiceTest.php asserts two organisations named alike give two entries with different values.

- **GIVEN** two organisations both named Acme, each supplying one application
- **WHEN** the facet endpoint runs for `module`
- **THEN** the `supplier` bucket SHALL hold two Acme entries with count 1 each

#### Scenario: A service is counted under its own provider
@e2e exclude Covered by tests/Unit/Service/FacetServiceTest.php, which gives a service a provider different from its linked module's and asserts the service's provider is counted.

- **GIVEN** a service provided by Voorbeeld Name 2 that links an application supplied by Voorbeeld Name 3
- **WHEN** the facet endpoint runs for `catalogService`
- **THEN** the service SHALL count under Voorbeeld Name 2 only

### Requirement: REQ-SFC-002 The Applications and Services pages SHALL offer Supplier as a facet that narrows the list

`FacetedCatalogIndexView` on `/modules` and `/diensten` SHALL show a Supplier facet in its sidebar with the labels and counts from REQ-SFC-001. Choosing one or more suppliers SHALL narrow the list to objects of those suppliers, combined with the other facets and the search. The choice SHALL be kept in the URL under `_gf_supplier` and in a saved facet view.

#### Scenario: An information manager narrows applications to one supplier
@e2e tests/e2e/spec-coverage/supplier-facet.spec.ts

- **GIVEN** a municipal information manager on `/modules` on a demo instance
- **WHEN** they choose Voorbeeld Name 2 in the Supplier facet
- **THEN** the list SHALL show only applications supplied by Voorbeeld Name 2
- **AND** the URL SHALL carry `_gf_supplier`

#### Scenario: Supplier combines with a GEMMA facet
@e2e tests/e2e/spec-coverage/supplier-facet.spec.ts

- **GIVEN** a municipal information manager on `/modules` with one reference component chosen
- **WHEN** they open the Supplier facet
- **THEN** its counts SHALL cover only the applications of that reference component

#### Scenario: A saved view keeps the supplier
@e2e tests/e2e/spec-coverage/supplier-facet.spec.ts

- **GIVEN** a municipal information manager who chose a supplier on `/diensten` and saved the selection as a view
- **WHEN** they open the page fresh and apply that view
- **THEN** the Supplier facet SHALL show the same supplier chosen and the list SHALL be narrowed to it

### Requirement: REQ-SFC-003 The facet dimension set MUST be the same in the service, the controller and the client

`FacetService::DIMENSIONS`, `FacetController::parseFilters()` and `FACET_DIMENSIONS` in `src/services/facets.js` MUST hold the same five names. A cached facet answer MUST NOT be served after the set changes.

#### Scenario: The backend reads every dimension the client sends
@e2e exclude A contract between two files; tests/Unit/Controller/FacetControllerTest.php asserts parseFilters() reads a supplier[] parameter, and src/services/facets.spec.js asserts FACET_DIMENSIONS holds the same five names.

- **GIVEN** the client sends `supplier[]` with one organisation id
- **WHEN** `FacetController` parses the request
- **THEN** `FacetService` SHALL receive that supplier filter and narrow `matchedObjectIds` by it

#### Scenario: An answer cached before the deploy is not reused
@e2e exclude Cache behaviour; tests/Unit/Service/FacetServiceTest.php asserts the cache key changes when the dimension list changes.

- **GIVEN** a facet answer cached with four dimensions
- **WHEN** the same request arrives after the deploy
- **THEN** `FacetService` SHALL compute a new answer that holds `supplier`
