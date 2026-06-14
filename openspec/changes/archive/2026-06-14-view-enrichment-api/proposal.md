# Proposal: view-enrichment-api

## Summary
Create a backend API that enriches GEMMA view data with module, gebruik, and organization information from OpenRegister, providing the data layer needed by the frontend view rendering.

## Motivation
The frontend currently has no API to fetch enriched view data. Module overlays, deelnames, and usage information require a backend endpoint that combines data from multiple OpenRegister schemas.

## Scope
- REST API endpoint for enriched view data
- Data aggregation from view, module, and gebruik objects
- Caching strategy for performance
- Integration with existing ObjectService
