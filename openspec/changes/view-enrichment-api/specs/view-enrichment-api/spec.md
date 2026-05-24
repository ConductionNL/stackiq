---
status: implemented
---

# View Enrichment API Specification

## Purpose
Defines how the frontend obtains enriched view data (base GEMMA view + organization-specific modules and usage data) through the softwarecatalog enrichment API, replacing direct OpenRegister calls. This API acts as the single entry point for all GEMMA view data, aggregating base ArchiMate view data with organization-specific module mappings, gebruik, and deelnames into a unified response.

## Context
Previously, the frontend called the OpenRegister API directly to fetch raw GEMMA view data, then performed client-side enrichment. This spec moves enrichment to the backend (softwarecatalog app), which has access to organization data, module mappings, and gebruik objects. The frontend sends filter toggle state as query parameters, and the backend returns a complete, enriched view ready for rendering.

**Relation to existing specs:**
- `module-overlay-rendering`: Consumes the enriched viewNode data this API returns
- `deelnames-gebruik`: This API orchestrates the two-phase query (owned + deelnames) defined there
- `org-archimate-export`: Uses similar enrichment logic but outputs ArchiMate XML instead of JSON

**Relation to existing OpenCatalogi infrastructure:**
- The softwarecatalog app hosts the enrichment endpoint, not OpenCatalogi directly
- OpenCatalogi's existing public API (`/api/{catalogSlug}`) serves publication data; views are a softwarecatalog concern
- The enrichment API calls OpenRegister's ObjectService internally for view, module, and gebruik data

## Requirements

### Requirement: Frontend MUST call enrichment API for views
The frontend MUST use the softwarecatalog enrichment API (`/softwarecatalog/api/views/{viewId}`) instead of the OpenRegister direct API (`/openregister/api/objects/vng-gemma/view/{id}`) for all view rendering.

#### Scenario: Beheer view loads with enrichment
- GIVEN a user navigates to `/beheer/view/{id}`
- WHEN the view component mounts
- THEN the frontend MUST request `GET /softwarecatalog/api/views/{viewId}`
- AND the request MUST include enrichment parameters based on active filter toggles

#### Scenario: Public view loads with enrichment
- GIVEN a visitor navigates to `/views/{id}`
- WHEN the public view component mounts
- THEN the frontend MUST request `GET /softwarecatalog/api/views/{viewId}`
- AND the request MUST include enrichment parameters based on active filter toggles

#### Scenario: Direct OpenRegister calls are no longer used for views
- GIVEN the frontend codebase
- WHEN searching for view data fetch calls
- THEN no calls to `/openregister/api/objects/vng-gemma/view/{id}` MUST exist for rendering
- AND all view rendering MUST go through the enrichment API

#### Scenario: Enrichment API returns 404 for non-existent view
- GIVEN a view ID that does not exist in the system
- WHEN `GET /softwarecatalog/api/views/{invalidId}` is called
- THEN the response MUST have status 404
- AND the response body MUST contain an error message indicating the view was not found

#### Scenario: Enrichment API handles server errors gracefully
- GIVEN the ObjectService is temporarily unavailable
- WHEN `GET /softwarecatalog/api/views/{viewId}` is called
- THEN the response MUST have status 503 or 500
- AND the response body MUST contain a descriptive error message
- AND the error MUST be logged server-side

### Requirement: Frontend filter toggles MUST map to backend enrichment parameters
The frontend filter toggle state MUST be translated to the correct backend query parameters on each view fetch.

#### Scenario: Gebruik filter is enabled
- GIVEN the user enables the "Gebruik" filter toggle
- WHEN the view is fetched
- THEN the request MUST include `include_gebruik=true`
- AND the request MUST include `include_modules=true`

#### Scenario: Deelnames filter is enabled
- GIVEN the user enables the "Deelnames" filter toggle
- WHEN the view is fetched
- THEN the request MUST include `include_deelnames_gebruik=true`
- AND the request MUST include `include_modules=true`

#### Scenario: Product filter is enabled
- GIVEN the user enables the "Product" filter toggle
- WHEN the view is fetched
- THEN the request MUST include `include_products=true`

#### Scenario: No filters are enabled
- GIVEN all filter toggles are disabled
- WHEN the view is fetched
- THEN the request MUST NOT include any enrichment parameters
- AND the response MUST contain only the base GEMMA view (viewNodes and viewRelationships from the ArchiMate import)

#### Scenario: Multiple filters enabled simultaneously
- GIVEN the user enables both "Gebruik" and "Deelnames" toggles
- WHEN the view is fetched
- THEN the request MUST include `include_gebruik=true`, `include_deelnames_gebruik=true`, and `include_modules=true`
- AND the response MUST contain base GEMMA nodes plus both owned and deelnames module overlay nodes

### Requirement: Enrichment API MUST return standard viewNode format
The enrichment API MUST return module overlay nodes in the same format as base GEMMA viewNodes so the frontend rendering pipeline requires no structural changes.

#### Scenario: Enriched response contains module nodes
- GIVEN a view is requested with `include_modules=true`
- WHEN the active organization has modules linked to referentiecomponenten on this view
- THEN the response `viewNodes` array MUST contain additional entries for each module-referentiecomponent match
- AND each module node MUST have `viewNodeId`, `modelNodeId`, `name`, `type`, `x`, `y`, `width`, `height`, `parent`, `color`, `borderColor` fields
- AND each module node MUST have `parent` set to the matching referentiecomponent's `viewNodeId`
- AND each module node MUST have `_isModuleExpansion` set to `true`

#### Scenario: No matching modules exist
- GIVEN a view is requested with `include_modules=true`
- WHEN the active organization has no modules matching referentiecomponenten on this view
- THEN the response MUST contain only the base viewNodes
- AND no error MUST be returned

#### Scenario: Module node positioning is calculated server-side
- GIVEN referentiecomponent R1 at position (100, 200) with size (300, 150)
- AND 3 modules are mapped to R1
- WHEN the enrichment API generates module overlay nodes
- THEN each module node MUST have `x`, `y`, `width`, `height` values that fit within R1's bounds
- AND the 3 modules MUST be stacked vertically without overlap
- AND module width MUST match R1's width minus padding

#### Scenario: Deelnames module nodes include type marker
- GIVEN a view is requested with `include_deelnames_gebruik=true`
- WHEN deelnames gebruiksobjecten match referentiecomponenten on this view
- THEN each deelnames module node MUST have `_type: "deelnames"`
- AND each MUST have `_sourceOrganization` with the owning organization's name
- AND each MUST have `_sourceOrganizationId` with the owning organization's UUID

#### Scenario: Response includes metadata about enrichment
- GIVEN a view is requested with enrichment parameters
- WHEN the response is generated
- THEN the response MUST include a `_enrichment` metadata object containing:
  - `modules_count`: number of module overlay nodes added
  - `deelnames_count`: number of deelnames overlay nodes added
  - `organization`: the active organization's name and UUID
  - `timestamp`: ISO 8601 timestamp of when the enrichment was computed

### Requirement: Enrichment API MUST support organization context
The enrichment API MUST know which organization to enrich for, either from the active organization setting or from a query parameter.

#### Scenario: Active organization is used by default
- GIVEN the softwarecatalog app has an active organization configured
- WHEN `GET /softwarecatalog/api/views/{viewId}?include_modules=true` is called without an `organization` parameter
- THEN the enrichment MUST use the active organization's UUID
- AND module mappings MUST be fetched for that organization

#### Scenario: Organization parameter overrides active organization
- GIVEN `GET /softwarecatalog/api/views/{viewId}?include_modules=true&organization={uuid}` is called
- WHEN the `organization` parameter is provided
- THEN the enrichment MUST use the specified organization UUID
- AND the active organization setting MUST be ignored for this request

#### Scenario: No organization available returns base view only
- GIVEN no active organization is configured AND no `organization` parameter is provided
- WHEN `GET /softwarecatalog/api/views/{viewId}?include_modules=true` is called
- THEN the response MUST return the base GEMMA view without any module enrichment
- AND a warning header `X-Enrichment-Warning: no-organization` MUST be included

### Requirement: Endpoint constants MUST be updated
The frontend endpoint configuration MUST point to the softwarecatalog enrichment API.

#### Scenario: GEMMA VIEW endpoint is configured
- GIVEN the frontend endpoints constants file
- WHEN the GEMMA.VIEW endpoint is resolved
- THEN it MUST resolve to `/softwarecatalog/api/views/{id}`
- AND the GEMMA.VIEWS endpoint MUST resolve to `/softwarecatalog/api/views`

#### Scenario: Old OpenRegister view endpoint is removed
- GIVEN the frontend endpoint constants
- WHEN searching for view-related endpoint definitions
- THEN no endpoint MUST reference `/openregister/api/objects/vng-gemma/view/`
- AND all view-related fetches MUST use the softwarecatalog enrichment API

#### Scenario: Endpoint supports query string parameters
- GIVEN the enrichment API endpoint
- WHEN the frontend appends filter parameters
- THEN the URL MUST support query parameters like `?include_modules=true&include_gebruik=true&include_deelnames_gebruik=true`
- AND the backend MUST parse all boolean query parameters correctly (accepting `true`, `1`, `yes`)

### Requirement: Enrichment API MUST support caching
The enrichment API MUST implement caching to avoid recomputing enrichment for unchanged data.

#### Scenario: Repeated request returns cached response
- GIVEN a view was fetched with the same parameters 30 seconds ago
- AND no relevant data has changed
- WHEN the same request is made again
- THEN the response MUST be served from cache
- AND the response time MUST be significantly faster than the first request

#### Scenario: Cache is invalidated when module mappings change
- GIVEN a cached enriched view for organization A
- WHEN a module mapping is added or removed for organization A
- THEN the cache for all views of organization A MUST be invalidated
- AND the next request MUST recompute the enrichment

#### Scenario: Cache key includes all relevant parameters
- GIVEN enrichment requests with different parameter combinations
- WHEN caching responses
- THEN the cache key MUST include: viewId, organization UUID, include_modules, include_gebruik, include_deelnames_gebruik, include_products
- AND different parameter combinations MUST NOT share cache entries

#### Scenario: Cache respects TTL
- GIVEN a cached enriched view
- WHEN the cache TTL (default 5 minutes) expires
- THEN the next request MUST recompute the enrichment
- AND the stale cache entry MUST be replaced

### Requirement: Enrichment API MUST return view relationships
The enrichment API MUST include both base GEMMA relationships and any enrichment-specific relationships (e.g., specialization relationships between modules and referentiecomponenten).

#### Scenario: Base relationships are included unchanged
- GIVEN a view with 50 base GEMMA relationships
- WHEN the enrichment API returns the view
- THEN all 50 relationships MUST be present in the `viewRelationships` array
- AND their structure MUST be identical to the raw OpenRegister response

#### Scenario: Module enrichment adds specialization relationships
- GIVEN 5 modules mapped to referentiecomponenten on a view
- WHEN the enrichment API returns the view with `include_modules=true`
- THEN the `viewRelationships` array MUST contain 5 additional specialization relationships
- AND each relationship MUST link a module viewNode to its parent referentiecomponent viewNode

#### Scenario: No enrichment returns only base relationships
- GIVEN a view requested without enrichment parameters
- WHEN the enrichment API returns the view
- THEN the `viewRelationships` array MUST contain only base GEMMA relationships
- AND no synthetic relationships MUST be added

### Requirement: Enrichment API MUST handle concurrent requests
The enrichment API MUST handle multiple simultaneous requests without data corruption or excessive resource usage.

#### Scenario: Five users request the same view simultaneously
- GIVEN 5 users request the same view with the same enrichment parameters at the same time
- WHEN all requests are processed
- THEN all 5 responses MUST be identical
- AND the backend MUST NOT execute 5 separate enrichment computations (cache should serve 4 of 5)

#### Scenario: Different organizations request the same view
- GIVEN user A (organization Zeist) and user B (organization Utrecht) request the same view
- WHEN both requests are processed concurrently
- THEN user A's response MUST contain Zeist's module mappings
- AND user B's response MUST contain Utrecht's module mappings
- AND the responses MUST NOT be mixed

### Requirement: Enrichment API response MUST include pagination metadata for large views
For views with many nodes, the API MUST include metadata to help the frontend manage rendering.

#### Scenario: Large view includes node count metadata
- GIVEN a view with 500 base nodes and 200 module overlay nodes
- WHEN the enrichment API returns the view
- THEN the response MUST include `_meta.totalNodes` with value 700
- AND `_meta.baseNodes` with value 500
- AND `_meta.overlayNodes` with value 200

#### Scenario: View response includes timing metadata
- GIVEN an enrichment request
- WHEN the response is generated
- THEN `_meta.processingTimeMs` MUST indicate the server-side processing time in milliseconds
- AND this value MUST help frontend developers identify slow enrichments

### Requirement: Enrichment API MUST validate input parameters
The API MUST validate all input parameters and return clear error messages for invalid input.

#### Scenario: Invalid view ID format
- GIVEN `GET /softwarecatalog/api/views/not-a-uuid?include_modules=true`
- WHEN the request is processed
- THEN the response MUST have status 400
- AND the error message MUST indicate the view ID format is invalid

#### Scenario: Invalid organization UUID
- GIVEN `GET /softwarecatalog/api/views/{viewId}?organization=invalid`
- WHEN the request is processed
- THEN the response MUST have status 400
- AND the error message MUST indicate the organization UUID is invalid

#### Scenario: Unknown query parameter is ignored
- GIVEN `GET /softwarecatalog/api/views/{viewId}?include_modules=true&unknown_param=true`
- WHEN the request is processed
- THEN the `unknown_param` MUST be silently ignored
- AND the response MUST be generated normally with module enrichment

## MODIFIED Requirements

_None -- this is a new capability._

## REMOVED Requirements

_None._

## Current Implementation Status
- **Not yet implemented**: No view enrichment API exists in softwarecatalog or OpenCatalogi.
- **Building blocks that exist**:
  - OpenRegister ObjectService for fetching view, module, and gebruik objects
  - Softwarecatalog app infrastructure (controllers, services, routes)
  - Frontend endpoint constants configuration
  - GEMMA view data model (viewNodes, viewRelationships, modelNodes)
  - Filter toggle UI components in the softwarecatalog frontend
- **Key gaps**:
  - No ViewEnrichmentService or ViewEnrichmentController in softwarecatalog
  - No enrichment API endpoint registered in routes
  - No cache layer for enriched view data
  - No module-to-referentiecomponent matching logic
  - No overlay node position calculation
  - Frontend still calls OpenRegister directly for view data

## Dependencies
- OpenRegister ObjectService (data queries for views, modules, gebruik)
- Softwarecatalog app (hosts the enrichment endpoint)
- `deelnames-gebruik` spec (deelnames query logic)
- `module-overlay-rendering` spec (consumes the enriched data)
