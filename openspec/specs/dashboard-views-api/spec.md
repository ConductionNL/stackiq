---
status: done
---

# dashboard-views-api Specification

## Purpose
Serves the app shell page and the dashboard's aggregate data feed, exposes saved views (list and single) over the API, and stores and retrieves generic per-user preferences by key. Provides the backend data behind the catalog dashboard and saved-view features.

@e2e exclude PHP dashboard-views REST endpoints (aggregate counts/recent-items data feed) — HTTP contract; covered by Newman REST collections and PHPUnit controller tests. The dashboard render that consumes this feed is covered by the manifest dashboard-page test.

## Requirements
### Requirement: The system SHALL render the app page and expose dashboard data (REQ-001)

`DashboardController::page(getParameter)` MUST return the app's main TemplateResponse; `DashboardController::index()` MUST return the dashboard's aggregate data as a JSON response.

#### Scenario: REQ-001 case 1
- WHEN `page()` is requested
- THEN it MUST return the app shell TemplateResponse

#### Scenario: REQ-001 case 2
- WHEN `index()` is called
- THEN it MUST return dashboard data as JSON

### Requirement: The system SHALL expose saved views over the API (REQ-002)

`ViewController::getAllViews()` / `ViewService::getAllViews(options)` MUST list saved views; `ViewController::getView(viewId)` / `ViewService::getView(viewId,options)` MUST return a single view; `ViewController::getApiDocumentation()` MUST return the views API documentation.

#### Scenario: REQ-002 case 1
- WHEN `getAllViews()` is called
- THEN it MUST return the list of saved views

#### Scenario: REQ-002 case 2
- WHEN `getView(viewId)` is called with an existing id
- THEN it MUST return that view

### Requirement: The system SHALL store and retrieve generic per-user preferences by key (REQ-003)

`PreferencesController::getPreference(key)` MUST return the current user's stored value for the key; `setPreference(key,value)` MUST persist a value for the current user. Values are scoped per user.

#### Scenario: REQ-003 case 1
- WHEN `setPreference('listColumns', json)` then `getPreference('listColumns')` is called
- THEN the stored value MUST be returned for the same user

