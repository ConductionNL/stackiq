# gemma-faceted-search Specification

## Purpose
TBD - created by archiving change gemma-faceted-search. Update Purpose after archive.
## Requirements
### Requirement: Facet aggregation endpoint returns GEMMA dimension counts

The system SHALL expose a facet aggregation endpoint (`GET /apps/stackiq/api/facets/{schema}`, `schema` in `module`, `dienst`) that returns, for each supported GEMMA dimension (`referentiecomponent`, `standaard`, `applicatieservice`, `domein`), the list of distinct facet values present in the currently-filtered result set together with the count of matching objects for each value.

#### Scenario: Facet counts returned for the module listing

- GIVEN the `module` register contains 40 modules, 12 of which link to referentiecomponent "Zaakregistratiecomponent"
- WHEN `GET /apps/stackiq/api/facets/module` is called with no filters applied
- THEN the response MUST include a `referentiecomponent` facet
- AND that facet MUST contain an entry `{ "value": "Zaakregistratiecomponent", "count": 12 }`

#### Scenario: Facet response covers all four GEMMA dimensions

- GIVEN a request to the facet aggregation endpoint for the `module` schema
- WHEN the response is generated
- THEN the response MUST contain top-level keys for `referentiecomponent`, `standaard`, `applicatieservice`, and `domein`
- AND a dimension with no matching objects MUST be present as an empty array, not omitted

#### Scenario: Unsupported schema is rejected

- GIVEN `GET /apps/stackiq/api/facets/contract` is called
- WHEN `contract` is not one of the supported facet schemas (`module`, `dienst`)
- THEN the response MUST have status 400
- AND the response body MUST contain an error message naming the supported schemas

### Requirement: Facet counts reflect the currently filtered set, not the unfiltered universe

Selecting a facet value SHALL narrow both the object list and the counts shown for every other facet dimension, so counts always describe "how many more results if I also select this value" rather than the totals across the whole register.

#### Scenario: Selecting one facet value narrows counts for other dimensions

- GIVEN the module listing has 40 modules total, of which 12 link to referentiecomponent "Zaakregistratiecomponent" and 5 of those 12 also link to standaard "StUF-ZKN"
- WHEN the facet endpoint is called with `referentiecomponent=Zaakregistratiecomponent` already selected
- THEN the `standaard` facet's count for "StUF-ZKN" MUST be 5, not the unfiltered total
- AND the `referentiecomponent` facet's own count for "Zaakregistratiecomponent" MUST reflect the same 12-object filtered set (self-count is not narrowed by its own selection)

#### Scenario: Multiple values within one dimension combine with OR semantics

- GIVEN a user selects both "Zaakregistratiecomponent" and "Klantcontactcomponent" under the `referentiecomponent` facet
- WHEN the object list and facet counts are requested
- THEN the result set MUST include modules linking to either referentiecomponent (union, not intersection)

#### Scenario: Selections across different dimensions combine with AND semantics

- GIVEN a user selects "Zaakregistratiecomponent" under `referentiecomponent` and "StUF-ZKN" under `standaard`
- WHEN the object list and facet counts are requested
- THEN the result set MUST include only modules that link to that referentiecomponent AND that standaard

### Requirement: Facets combine with free-text search

The facet aggregation and the existing free-text search on the module/dienst index pages SHALL be combinable: text search narrows the candidate set before facet counts are computed.

#### Scenario: Text query narrows facet counts

- GIVEN a free-text search for "zaak" is active on the module index
- WHEN the facet endpoint is called with the same search query parameter
- THEN facet counts MUST only reflect modules matching "zaak"
- AND the returned facet values MUST NOT include values that only occur on non-matching modules

#### Scenario: No text query returns facets over the full (RBAC-scoped) set

- GIVEN no free-text search is active
- WHEN the facet endpoint is called
- THEN facet counts MUST be computed over all objects the caller can see, unfiltered by text

### Requirement: Facet aggregation queries MUST be bounded

Every OpenRegister `searchObjects()` (or equivalent aggregate) call issued by the facet aggregation service MUST set an explicit `_limit`, or use `searchObjectsPaginated()`/an explicit documented ceiling, consistent with the `bound-unbounded-searchobjects-scans` change. Facet aggregation MUST NOT introduce a new unbounded full-table scan.

#### Scenario: Facet aggregation query sets an explicit limit

- GIVEN `FacetService` builds a query to aggregate `referentiecomponent` values across the `module` schema
- WHEN the query array is constructed
- THEN it MUST include an explicit `_limit` value
- AND the value MUST NOT be silently omitted or left to default

#### Scenario: A register too large for one bounded page pages instead of scanning unbounded

- GIVEN the `module` register has more objects than fit in one bounded facet aggregation page
- WHEN facet counts are computed
- THEN the service MUST page through results via `searchObjectsPaginated()` (or a documented `_limit` ceiling) to reach a complete count
- AND MUST NOT issue a single unbounded `searchObjects()` call to cover the whole table

### Requirement: Facet counts MUST respect the caller's RBAC/tenant context

Facet aggregation SHALL count only objects the requesting user is authorized to read. The facet endpoint MUST NOT expose the existence of, or count, objects a restricted user cannot see via the equivalent object list query.

#### Scenario: Restricted user sees only their own scope reflected in counts

- GIVEN a tenant-restricted user who can see 8 of the register's 40 modules
- WHEN that user requests facet counts for the module listing
- THEN every facet value's count MUST be computed only from that user's visible 8 modules
- AND no facet value that exists only among the other 32 (invisible) modules MUST appear

#### Scenario: Facet aggregation uses the same authorization path as the object list

- GIVEN the module index page's own object list query is scoped by RBAC/organisation context
- WHEN the facet aggregation query is built
- THEN it MUST apply the identical RBAC/tenant scoping as the object list query
- AND MUST NOT use a separate, unscoped counting code path

### Requirement: Filter state is URL-encoded and deep-linkable

The selected facet values and active free-text query on the module/dienst index pages SHALL be reflected in the browser URL as query parameters, so a filtered view can be shared, bookmarked, or reloaded without losing the selection.

#### Scenario: Applying a facet updates the URL

- GIVEN a user on the module index page selects "Zaakregistratiecomponent" under the `referentiecomponent` facet
- WHEN the selection is applied
- THEN the browser URL MUST include a query parameter encoding that selection (e.g. `?referentiecomponent=Zaakregistratiecomponent`)

#### Scenario: Loading a filtered URL restores the facet selection

- GIVEN a URL `.../modules?referentiecomponent=Zaakregistratiecomponent&standaard=StUF-ZKN`
- WHEN the module index page loads
- THEN the `referentiecomponent` and `standaard` facets MUST show those values as pre-selected
- AND the object list and facet counts MUST reflect that filter state on first render, without requiring an additional user action

#### Scenario: Clearing all facets removes filter parameters from the URL

- GIVEN a filtered URL is active
- WHEN the user clears all facet selections
- THEN the facet-related query parameters MUST be removed from the URL
- AND the object list MUST return to the unfiltered (RBAC-scoped) view

### Requirement: Facet sidebar UI on the module and dienst index pages

The module (`Applications`) and dienst (`Services`) `CnIndexPage`-based index pages SHALL present a facet filter panel listing the four GEMMA dimensions (referentiecomponent, standaard, applicatieservice, domein), each showing its available values with counts, using `@conduction/nextcloud-vue` components per ADR-012.

#### Scenario: Facet panel renders alongside the existing index page toolbar

- GIVEN a user navigates to the module index page
- WHEN the page renders
- THEN a facet filter panel MUST be visible showing all four GEMMA dimensions
- AND the existing free-text search box and any `quickFilters` MUST continue to render and function unchanged

#### Scenario: Selecting a facet value updates the object list without a full page reload

- GIVEN the facet panel is visible on the module index page
- WHEN the user selects a facet value
- THEN the object list MUST re-fetch and display only matching modules
- AND the facet panel's counts for the other dimensions MUST update to reflect the new filter state
- AND no full browser page reload MUST occur

#### Scenario: A facet dimension with zero available values is visibly disabled

- GIVEN the currently filtered set has no objects linking to any `applicatieservice` value
- WHEN the facet panel renders
- THEN the `applicatieservice` facet section MUST indicate it has no available values (e.g. empty state or disabled state)
- AND MUST NOT be selectable

### Requirement: A facet selection can be saved as a view

A user SHALL be able to save the currently active facet selection (and free-text query, if any) as a saved view via the existing dashboard-views-api `ViewService`, so it can be recalled later without re-selecting each facet.

#### Scenario: Saving the current filter state as a view

- GIVEN a user has selected `referentiecomponent=Zaakregistratiecomponent` and `standaard=StUF-ZKN` on the module index page
- WHEN they choose "Save as view" and provide a name
- THEN a saved view MUST be created via the existing `ViewService` save-view call
- AND the saved view's stored filter state MUST reproduce the same facet selection when loaded

#### Scenario: Loading a saved view restores its facet selection

- GIVEN a saved view exists with a stored facet selection
- WHEN a user opens that saved view from the module index page
- THEN the facet panel MUST pre-select the stored values
- AND the object list and URL MUST reflect that filter state

### Requirement: Facet aggregation results are cached

The facet aggregation endpoint SHALL cache computed facet results per unique combination of schema, filter state, free-text query, and caller RBAC/tenant context, and SHALL invalidate the cache when underlying module/dienst/element data changes.

#### Scenario: Repeated identical facet request is served from cache

- GIVEN a facet request was made 10 seconds ago with a given filter combination
- AND no relevant module, dienst, or element data has changed
- WHEN the same request is made again by the same user
- THEN the response MUST be served from cache
- AND the response time MUST be significantly faster than the first request

#### Scenario: Cache is invalidated when a module's GEMMA links change

- GIVEN a cached facet result exists for the module listing
- WHEN a module's `referentieComponenten`, `standaarden`, `standaardenGemma`, or related GEMMA link field is created, updated, or removed
- THEN the cache for affected facet queries MUST be invalidated
- AND the next facet request MUST recompute the aggregation

#### Scenario: Cache key includes RBAC/tenant context

- GIVEN two users with different RBAC scopes request facets for the same filter combination
- WHEN both requests are served
- THEN each user's response MUST come from (or populate) a cache entry keyed to include their own RBAC/tenant context
- AND the two users MUST NOT receive each other's cached counts

### Requirement: Facet labels and UI strings are translated

All facet dimension labels, facet value display strings sourced from the UI layer (not raw data values), empty-state text, and the "Save as view" action SHALL be available in Dutch and English, with translation keys written in English per ADR-005.

#### Scenario: Facet panel renders in the user's selected language

- GIVEN a user's Nextcloud locale is set to `nl_NL`
- WHEN the facet panel renders
- THEN the dimension labels (e.g. "Referentiecomponent", "Standaard", "Applicatieservice", "Domein") and the "Save as view" action MUST render in Dutch

#### Scenario: Translation keys are in English

- GIVEN the stackiq `l10n` translation files
- WHEN the facet panel's translation keys are inspected
- THEN each key MUST be an English identifier (e.g. `facetSaveAsView`), not a Dutch string, with the Dutch translation supplied as the `nl` value

