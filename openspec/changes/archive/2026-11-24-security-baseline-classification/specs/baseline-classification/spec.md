# baseline-classification specification

**Status**: proposed
**Scope**: stackiq
**OpenSpec changes**:
- security-baseline-classification

## Purpose

A CISO or functional administrator of a municipality classifies each application their organisation uses on availability, integrity and confidentiality, on the BIO baseline levels, with a reason for each. Stackiq suggests the levels from GEMMA, derives the overall BBN level, and shows other municipalities how organisations classify an application without naming any of them.

## ADDED Requirements

### Requirement: REQ-BCL-001 An organisation SHALL classify each application it uses on availability, integrity and confidentiality

`usage` SHALL carry `availabilityLevel`, `integrityLevel` and `confidentialityLevel` (BBN1, BBN2, BBN3), a reason for each, a derived `bbnLevel`, `classifiedAt` and `classifiedBy`. Only users of the consuming organisation SHALL read or change these properties; a supplier that may read the usage SHALL NOT see them.

#### Scenario: A CISO classifies an application in use
@e2e tests/e2e/spec-coverage/baseline-classification.spec.ts

- **GIVEN** a functional administrator of Gemeente Voorbeeld on `/gebruik/:id`
- **WHEN** they set availability BBN1, integrity BBN2 and confidentiality BBN2 with a reason each, and save
- **THEN** the Security classification panel SHALL show the three levels and BBN2 overall
- **AND** it SHALL show today and their name as classified at and by

#### Scenario: The supplier does not see the classification
@e2e exclude A property rule held by OpenRegister; tests/Unit/Settings/BaselineClassificationDeclarationTest.php asserts every new property carries the owning-organisation read and update rule of usage.interneAnnotation.

- **GIVEN** a classified usage of a product
- **WHEN** a user of the product's supplier reads that usage
- **THEN** the classification properties SHALL NOT be in the response

### Requirement: REQ-BCL-002 The overall BBN level SHALL be the highest of the three aspects, on every save

On every create and update of a `usage`, stackiq SHALL set `bbnLevel` to the highest of the three aspect levels, empty when none is set, and SHALL stamp `classifiedAt` and `classifiedBy` when an aspect changed. It SHALL NOT write when nothing it derives has changed.

#### Scenario: An API write keeps the overall level right
@e2e exclude A subscriber; tests/Unit/EventListener/UsageClassificationSubscriberTest.php asserts BBN1, BBN2, BBN3 gives BBN3, that an unchanged save writes nothing, and that the stamp follows an aspect change.

- **GIVEN** a usage with BBN2 overall
- **WHEN** an integration raises confidentiality to BBN3 through the OpenRegister API
- **THEN** the usage's `bbnLevel` SHALL be BBN3

### Requirement: REQ-BCL-003 Stackiq SHALL suggest the levels from GEMMA's reference components

Suggest from GEMMA on the usage page SHALL fill the three levels with the highest GEMMA score per aspect among the usage's reference components, or the application's when the usage names none, mapping 1 to 3 onto BBN1 to BBN3. It SHALL NOT save, and SHALL say how many components had a score.

#### Scenario: The suggestion follows the reference components
@e2e tests/e2e/spec-coverage/baseline-classification.spec.ts

- **GIVEN** a usage of an application whose reference component has GEMMA availability 1, integrity 2 and confidentiality 2
- **WHEN** the functional administrator chooses Suggest from GEMMA
- **THEN** the form SHALL show BBN1, BBN2 and BBN2, unsaved

### Requirement: REQ-BCL-004 Municipalities SHALL see how organisations classify an application, without names and not below three

`ModuleDetail` SHALL show How organisations classify this application: per aspect and overall, the number of organisations per level. The data SHALL come from `GET /api/modules/{moduleId}/classification-summary`, which SHALL answer Nextcloud admins, members of `ambtenaar` and users whose active organisation is a municipality or a collaboration, SHALL refuse others with 403, SHALL return levels only when at least three organisations classified the application, and SHALL never return an organisation name or id.

#### Scenario: An information manager learns how others classify an application
@e2e tests/e2e/spec-coverage/baseline-classification.spec.ts

- **GIVEN** three organisations that classified Voorbeeld Name 1, two at BBN2 and one at BBN3 overall
- **WHEN** a municipal information manager of another municipality opens its `/modules/:id`
- **THEN** the panel SHALL show 3 organisations, BBN2 two times and BBN3 once
- **AND** it SHALL name none of them

#### Scenario: Two organisations are not enough
@e2e exclude A threshold; tests/Unit/Service/UsageClassificationServiceTest.php asserts the summary returns only the organisation count for fewer than three classifying organisations.

- **GIVEN** an application classified by two organisations
- **WHEN** the summary is requested
- **THEN** it SHALL return that two organisations classified it and no levels

#### Scenario: A supplier cannot read the summary
@e2e exclude An authorisation rule; tests/Unit/Controller/UsageClassificationControllerTest.php asserts 403 for a user whose active organisation is a supplier.

- **GIVEN** a user whose active organisation has type Supplier
- **WHEN** they call `GET /api/modules/{moduleId}/classification-summary`
- **THEN** stackiq SHALL answer 403
