---
status: implemented
---

# Deelnames Gebruik Specification

## Purpose
Defines how usage objects (gebruiksobjecten) with participant organizations (deelnemers) are queried, enriched, and displayed alongside regular organization-owned modules on GEMMA views. This enables organizations to see not only the software they directly use, but also shared applications where they participate as a deelnemer (participant) through inter-organizational cooperation agreements.

## Context
In the Dutch municipal landscape, organizations frequently share software applications. For example, a shared service center (SSC) may own a software product while multiple municipalities participate in its use. The `deelnemers` field on gebruiksobjecten captures this relationship: the owning organization has RBAC access to the object, while participating organizations are listed in the `deelnemers` array. This spec ensures both types of usage are visible on GEMMA ArchiMate views.

**Relation to existing specs:**
- `view-enrichment-api`: Provides the backend API that returns both owned and deelnames gebruik data
- `module-overlay-rendering`: Handles the visual rendering of deelnames module nodes with distinct styling
- `org-archimate-export`: Includes deelnames data in ArchiMate XML exports when the deelnames parameter is enabled

**Relation to existing OpenCatalogi entities:**
- Uses OpenRegister's ObjectService for querying gebruiksobjecten across organizations
- Leverages the softwarecatalog enrichment API for view data retrieval
- Builds on the existing GEMMA view and referentiecomponent data model

## Requirements

### Requirement: ViewService MUST retrieve deelnames gebruik separately from regular gebruik
The ViewService MUST perform a two-phase retrieval: regular gebruik filtered by RBAC, then deelnames gebruik with RBAC disabled filtering by the `deelnemers` field.

#### Scenario: Organization has both owned and shared gebruik
- GIVEN organization A owns 5 gebruiksobjecten
- AND organization A appears in the `deelnemers` field of 3 gebruiksobjecten owned by organization B
- WHEN the view is requested with `include_gebruik=true` and `include_deelnames_gebruik=true`
- THEN the response MUST include module overlay nodes for all 5 owned gebruiksobjecten
- AND the response MUST include module overlay nodes for the 3 shared gebruiksobjecten
- AND shared module nodes MUST be marked with `_type: "deelnames"`

#### Scenario: Organization has only deelnames gebruik
- GIVEN organization A owns 0 gebruiksobjecten
- AND organization A appears in `deelnemers` of 2 gebruiksobjecten
- WHEN the view is requested with `include_deelnames_gebruik=true`
- THEN the response MUST include module overlay nodes for the 2 shared gebruiksobjecten
- AND no error MUST be returned for the empty regular gebruik result

#### Scenario: Deelnames flag is not set
- GIVEN organization A appears in `deelnemers` of 3 gebruiksobjecten
- WHEN the view is requested with `include_gebruik=true` but WITHOUT `include_deelnames_gebruik`
- THEN the response MUST NOT include the 3 shared gebruiksobjecten
- AND only directly owned gebruik MUST be included

#### Scenario: Both flags disabled returns base view only
- GIVEN organization A has both owned and deelnames gebruik
- WHEN the view is requested WITHOUT `include_gebruik` and WITHOUT `include_deelnames_gebruik`
- THEN the response MUST contain only the base GEMMA view nodes
- AND no gebruik or deelnames queries MUST be executed

#### Scenario: Deelnames without gebruik flag still returns deelnames
- GIVEN organization A has deelnames gebruik but the `include_gebruik` flag is false
- WHEN the view is requested with `include_deelnames_gebruik=true` only
- THEN the response MUST include deelnames module overlay nodes
- AND owned gebruik MUST NOT be included in the response

### Requirement: Deelnames gebruik MUST be queried with RBAC disabled
Deelnames gebruiksobjecten are owned by other organizations, so the query MUST bypass RBAC to find records where the current organization appears in the `deelnemers` array.

#### Scenario: Deelnames query bypasses RBAC
- GIVEN organization A is searching for deelnames gebruik
- WHEN the ObjectService search is executed for deelnames
- THEN the search MUST be called with `_rbac: false`
- AND the query MUST filter on `deelnemers` containing organization A's UUID

#### Scenario: Deelnames query also disables multitenancy
- GIVEN organization A is searching for deelnames gebruik in a multi-tenant environment
- WHEN the ObjectService search is executed for deelnames
- THEN the search MUST be called with `_rbac: false` AND `_multitenancy: false`
- AND results MUST include gebruiksobjecten from all tenants where organization A is listed as deelnemer

#### Scenario: Deelnames query targets correct register and schema
- GIVEN the voorzieningen register and gebruik schema are configured
- WHEN the deelnames query is executed
- THEN the ObjectService search MUST target the voorzieningen register
- AND the query MUST use the gebruik schema identifier
- AND no other schemas or registers MUST be queried

#### Scenario: Deelnames query handles large result sets
- GIVEN organization A appears as deelnemer in 50 gebruiksobjecten across 10 organizations
- WHEN the deelnames query is executed
- THEN all 50 results MUST be returned
- AND the query MUST NOT be limited by default pagination
- AND results MUST include the owning organization's name for display purposes

#### Scenario: RBAC-enabled query for regular gebruik runs separately
- GIVEN organization A has both owned and deelnames gebruik
- WHEN both queries execute
- THEN the regular gebruik query MUST use standard RBAC (organization-filtered)
- AND the deelnames query MUST use `_rbac: false`
- AND the two result sets MUST be merged without duplicates

### Requirement: Gebruiksobjecten MUST support the deelnemers field
Gebruiksobjecten MUST have a `deelnemers` field that contains an array of participating organization identifiers.

#### Scenario: Gebruiksobject with deelnemers array of UUIDs
- GIVEN a gebruiksobject with `deelnemers: ["uuid-org-a", "uuid-org-b"]`
- WHEN organization A queries for deelnames gebruik
- THEN this gebruiksobject MUST be returned in the results

#### Scenario: Gebruiksobject with deelnemers array of objects
- GIVEN a gebruiksobject with `deelnemers: [{"id": "uuid-org-a", "name": "Org A"}]`
- WHEN organization A queries for deelnames gebruik
- THEN this gebruiksobject MUST be returned in the results

#### Scenario: Gebruiksobject without deelnemers field
- GIVEN a gebruiksobject without a `deelnemers` field
- WHEN any organization queries for deelnames gebruik
- THEN this gebruiksobject MUST NOT be returned in deelnames results
- AND it MAY still appear in regular gebruik results if the querying organization owns it

#### Scenario: Gebruiksobject with empty deelnemers array
- GIVEN a gebruiksobject with `deelnemers: []`
- WHEN any organization queries for deelnames gebruik
- THEN this gebruiksobject MUST NOT be returned in deelnames results

#### Scenario: Organization appears in deelnemers of its own gebruiksobject
- GIVEN organization A owns a gebruiksobject AND also appears in its own `deelnemers` array
- WHEN organization A queries with both `include_gebruik=true` and `include_deelnames_gebruik=true`
- THEN the gebruiksobject MUST appear only once in the results (deduplicated)
- AND it MUST be classified as owned, not deelnames

### Requirement: Deelnames module nodes MUST carry source organization metadata
When deelnames gebruiksobjecten are converted to module overlay nodes, they MUST include metadata about the owning organization to enable attribution in the UI.

#### Scenario: Deelnames node includes owning organization name
- GIVEN a deelnames gebruiksobject owned by organization B
- WHEN it is converted to a module overlay node
- THEN the node MUST include `_sourceOrganization` with the owning organization's name
- AND the node MUST include `_sourceOrganizationId` with the owning organization's UUID

#### Scenario: Tooltip shows source organization for deelnames nodes
- GIVEN a rendered deelnames module node on a GEMMA view
- WHEN the user hovers over the node
- THEN the tooltip MUST display the source organization name
- AND the tooltip MUST indicate this is a shared/deelnames application

#### Scenario: Deelnames nodes are distinguishable in node lists
- GIVEN a view with both owned and deelnames module nodes
- WHEN the node list or legend is displayed
- THEN deelnames nodes MUST be listed separately from owned nodes
- AND each deelnames entry MUST show the source organization

### Requirement: Deelnames gebruik MUST be filterable in the frontend
The frontend MUST provide a dedicated toggle for deelnames gebruik, separate from the regular gebruik toggle.

#### Scenario: Deelnames toggle is independent from gebruik toggle
- GIVEN the view filter panel
- WHEN the user sees the filter toggles
- THEN there MUST be a separate "Deelnames" toggle
- AND it MUST be independent of the "Gebruik" toggle
- AND enabling/disabling one MUST NOT affect the other

#### Scenario: Deelnames toggle triggers re-fetch with correct parameters
- GIVEN the deelnames toggle is currently disabled
- WHEN the user enables the deelnames toggle
- THEN a new API request MUST be made with `include_deelnames_gebruik=true`
- AND the view MUST re-render with the additional deelnames nodes

#### Scenario: Deelnames toggle disabled by default
- GIVEN a user navigates to a GEMMA view for the first time
- THEN the deelnames toggle MUST be disabled by default
- AND no deelnames query MUST be executed on initial load

### Requirement: Test data MUST include gebruiksobjecten with deelnemers
The development environment MUST have test data demonstrating the deelnames flow.

#### Scenario: Test data exists for deelnames verification
- GIVEN the development environment
- WHEN a developer wants to test the deelnames feature
- THEN there MUST be at least one gebruiksobject owned by organization X with `deelnemers` containing organization Y
- AND organization Y MUST be a different organization than X
- AND the gebruiksobject MUST reference a module linked to referentiecomponenten on at least one view

#### Scenario: Test data covers multiple deelnemers per gebruiksobject
- GIVEN the development environment
- THEN there MUST be at least one gebruiksobject with 2 or more organizations in the `deelnemers` array
- AND each listed organization MUST be a valid, existing organization in the system

#### Scenario: Test data includes organizations with only deelnames
- GIVEN the development environment
- THEN there MUST be at least one organization that has zero owned gebruiksobjecten but appears as deelnemer in at least one gebruiksobject
- AND this organization MUST be usable for testing the "deelnames only" scenario

### Requirement: Performance testing MUST use the organization with most applications
After implementation, the system MUST be tested with the organization that has the highest number of applications and gebruiksobjecten set as active.

#### Scenario: Performance test with largest organization
- GIVEN the organization with the most applications is set as active
- WHEN the BBN poster view (388+ base nodes) is loaded with all enrichment flags enabled
- THEN the total render time (including overlay nodes) MUST be under 3 seconds
- AND the page MUST remain interactive (no browser freeze)

#### Scenario: Performance test with large deelnames result set
- GIVEN an organization that appears as deelnemer in 100+ gebruiksobjecten
- WHEN the view is loaded with deelnames enabled
- THEN the deelnames query MUST complete within 2 seconds
- AND the combined render time (base + owned + deelnames) MUST be under 5 seconds

#### Scenario: Performance test with concurrent owned and deelnames queries
- GIVEN both gebruik and deelnames toggles are enabled
- WHEN the view data is fetched
- THEN the owned gebruik query and deelnames query SHOULD execute in parallel where possible
- AND the total data retrieval time MUST NOT be the sum of both queries

### Requirement: Deduplication MUST prevent duplicate module nodes
When an organization both owns a gebruiksobject AND appears in another organization's gebruiksobject for the same module, the deduplication logic MUST prevent rendering the same module twice on a referentiecomponent.

#### Scenario: Same module from owned and deelnames sources
- GIVEN organization A owns a gebruiksobject for module "Topdesk" linked to referentiecomponent R1
- AND organization A also appears as deelnemer on another gebruiksobject for "Topdesk" linked to R1
- WHEN both owned and deelnames results are merged
- THEN only one module overlay node for "Topdesk" on R1 MUST be rendered
- AND the node MUST be marked as owned (not deelnames)

#### Scenario: Different modules from same referentiecomponent are not deduplicated
- GIVEN organization A owns "Topdesk" linked to R1
- AND organization A is deelnemer on "ServiceNow" also linked to R1
- WHEN results are merged
- THEN both "Topdesk" and "ServiceNow" MUST appear as separate overlay nodes on R1

#### Scenario: Same module on different referentiecomponenten is not deduplicated
- GIVEN organization A owns "Topdesk" linked to R1
- AND organization A is deelnemer on "Topdesk" linked to R2 (different referentiecomponent)
- WHEN results are merged
- THEN "Topdesk" MUST appear on both R1 (as owned) and R2 (as deelnames)

### Requirement: Error handling MUST gracefully handle deelnames query failures
If the deelnames query fails (e.g., network timeout, schema not found), the view MUST still render with available data.

#### Scenario: Deelnames query fails with timeout
- GIVEN the deelnames query takes longer than the configured timeout
- WHEN the view is being loaded
- THEN the view MUST render with owned gebruik data (if available) and base GEMMA nodes
- AND a warning MUST be logged indicating the deelnames query timed out
- AND the frontend MUST display a non-blocking notification that deelnames data could not be loaded

#### Scenario: Deelnames schema not configured
- GIVEN the gebruik schema is not configured in the voorzieningen register
- WHEN a deelnames query is attempted
- THEN the query MUST fail gracefully with a logged warning
- AND the view MUST render without deelnames data
- AND no HTTP error MUST be returned to the frontend

#### Scenario: Regular gebruik query fails but deelnames succeeds
- GIVEN the regular RBAC-enabled gebruik query fails
- AND the deelnames query succeeds with 3 results
- WHEN the view is rendered
- THEN the 3 deelnames nodes MUST be displayed
- AND a warning MUST be shown for the failed owned gebruik query

## MODIFIED Requirements

_None -- this is a new capability._

## REMOVED Requirements

_None._

## Current Implementation Status
- **Not yet implemented**: No deelnames-specific logic exists in the OpenCatalogi or softwarecatalog codebase.
- **Building blocks that exist**:
  - ObjectService search with RBAC disable capability (via `_rbac` and `_multitenancy` parameters)
  - GEMMA view rendering pipeline with JointJS in the softwarecatalog frontend
  - Module overlay node infrastructure (see `module-overlay-rendering` spec)
  - View enrichment API (see `view-enrichment-api` spec)
- **Key gaps**:
  - No two-phase query logic (owned + deelnames) in any ViewService
  - No deelnames-specific frontend toggle
  - No deduplication logic for overlapping owned/deelnames results
  - No source organization metadata on module overlay nodes
  - No test data with deelnemers field populated

## Dependencies
- `view-enrichment-api` spec (backend API contract)
- `module-overlay-rendering` spec (visual rendering of deelnames nodes)
- OpenRegister ObjectService (data queries with RBAC control)
- Softwarecatalog ViewService (view data aggregation)
