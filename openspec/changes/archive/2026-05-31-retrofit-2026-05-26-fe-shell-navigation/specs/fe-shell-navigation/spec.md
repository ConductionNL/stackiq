---
status: draft
retrofit: true
---

# Frontend Shell & Navigation Specification

## Purpose

Captures the observed behavior of the dashboard view, the directory and search sidebars, the pagination control, and the reusable presentational components (collapsible/always-visible sections, published-status icon).

## ADDED Requirements

### Requirement: Dashboard view (REQ-FE-401)

The dashboard SHALL load and display the catalog's overview data (counts, recent items, widgets) and allow the user to navigate into detail views.

`Dashboard.vue` fetches dashboard data from the store, formats it for display, and routes the user into object/organisation detail.

#### Scenario: Open the dashboard
- WHEN the dashboard loads
- THEN it MUST display the overview data and navigation entries

### Requirement: Directory & search sidebars (REQ-FE-402)

The directory and search sidebars SHALL let the user browse/filter the catalog and apply search criteria that update the active object list.

`DirectorySideBar.vue` renders the directory tree/filters and applies selection; `SearchSideBar.vue` collects search criteria and dispatches the search to update results.

#### Scenario: Apply a directory filter
- WHEN the user selects a directory entry or applies a search
- THEN the sidebar MUST update the active object list accordingly

### Requirement: Pagination control (REQ-FE-403)

The pagination control SHALL expose page navigation and emit page-change events that update the displayed result set.

`PaginationComponent.vue` computes page state and emits navigation events to the parent.

#### Scenario: Change page
- WHEN the user navigates to another page
- THEN the control MUST emit the page change so the parent reloads that page

### Requirement: Reusable presentational components (REQ-FE-404)

The reusable section and icon components SHALL render their content/state consistently: collapsible/always-visible sections SHALL toggle and render their slot content, and the published-status icon SHALL render the correct icon and tooltip for an object's publication state.

`CollapsibleSection.vue` toggles open/closed and renders its content; `AlwaysVisibleSection.vue` renders a permanently-visible section; `PublishedIcon.vue` derives the icon/tooltip from the object's publication status.

#### Scenario: Toggle a collapsible section
- WHEN the user toggles a collapsible section
- THEN it MUST show or hide its slot content

#### Scenario: Render publication status
- WHEN the published icon renders for an object
- THEN it MUST reflect that object's publication state and tooltip
