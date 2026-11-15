# view-enrichment-api specification

## Purpose

A client reads the imported GEMMA architecture through the views API. Matrix row `stackiq:arch-gemma-api`.

## ADDED Requirements

### Requirement: The views API lists the imported GEMMA views and returns each as imported (REQ-CLR-021)

`GET /api/views` SHALL list the imported GEMMA views with their id and name to any signed-in user. `GET /api/views/{viewId}` without enrichment parameters SHALL return that view's nodes and relationships as imported.

#### Scenario: A client reads the GEMMA architecture through the API
@e2e exclude JSON API without a page; covered by tests/Unit/Controller/ViewControllerTest.php

- **GIVEN** the GEMMA model is imported
- **WHEN** a signed-in client requests `GET /api/views`
- **THEN** the response lists the imported views with their id and name
- **AND** `GET /api/views/{viewId}` returns that view's nodes and relationships as imported
