# shared-compliance-documents specification

**Status**: proposed
**Scope**: stackiq
**OpenSpec changes**:
- sharing-compliance-documents

## Purpose

Organisations publish compliance documents about a product and choose who reads them, so others reuse them. Matrix row `stackiq:share-compliance-documents`.

## ADDED Requirements

### Requirement: REQ-SCD-001 An organisation publishes a compliance document about a product

A supplier, or an organisation that uses the product, SHALL publish a compliance document about a product with its type (DPIA, processing agreement, pentest report, assurance report, certificate, ENSIA statement or other), title, summary, issue date, validity and file. The product page SHALL list the documents the user may read, and a documents list SHALL filter on type and on documents that expire within 90 days.

#### Scenario: A supplier publishes a processing agreement for all government readers
@e2e tests/e2e/workflows/compliance-documents.spec.ts

- **GIVEN** a supplier of product X
- **WHEN** the supplier opens the page of X, adds a processing agreement valid until next year with audience government, and saves
- **THEN** a municipal information manager opening the page of X sees the processing agreement with its validity and publisher

### Requirement: REQ-SCD-002 The audience decides who reads a document

A document SHALL be readable by anyone when its audience is public, by users of government organisations in the catalogue when it is government, and only by the organisations listed in `sharedWithOrganisations` when it is named. The publisher's organisation SHALL always read and edit its own documents, and a new document SHALL start with audience named.

#### Scenario: A pentest report stays with the municipalities it was shared with
@e2e tests/e2e/workflows/compliance-documents.spec.ts

- **GIVEN** a supplier shared a pentest report on product X with municipality A only
- **WHEN** an information manager of municipality B opens the page of X
- **THEN** the pentest report is not listed
- **AND** an information manager of municipality A sees it
