# fe-stores Specification

## Purpose
TBD - created by archiving change retrofit-2026-05-26-fe-stores. Update Purpose after archive.

@e2e exclude Pinia stores and FE services (settings/organisatie/navigation/catalog stores, object-operations plugin, theme service, heartbeat client) — every scenario asserts store-action / service behaviour (load/save/fetch/reset/saveObject/massOp/setModal/isDarkTheme/start-stop heartbeat). These are pure JS state/logic units tested by vitest with mocked fetch; they have no navigable UI surface of their own. The UI that consumes these stores is covered by the manifest dashboard/settings/index render tests.

## Requirements
### Requirement: Settings store (REQ-FE-501)

The settings store SHALL load, hold and persist the app's configuration (general, OpenRegister, email, user-groups, sync, ArchiMate, statistics, version) and SHALL expose actions to validate, auto-configure, test (email/round-trip) and poll status, surfacing errors to consumers.

`settings.js` exposes load*/save*/test*/validate*/poll actions and clears/holds error and status state for the settings UI.

#### Scenario: Load configuration
- WHEN a load action runs
- THEN the store MUST populate the corresponding configuration state

#### Scenario: Save configuration
- WHEN a save action runs with valid input
- THEN the store MUST persist it and reflect success or error state

### Requirement: Organisation/contact-person store (REQ-FE-502)

The organisation store SHALL fetch contact persons (with linked-user details) for an organisation and SHALL expose user-management actions (convert to user, change password, enable/disable, update groups, fetch groups/user info).

`organisatie.js` fetches contact persons and bulk/single user info and dispatches user-management mutations, holding error state.

#### Scenario: Fetch contact persons with user details
- WHEN the fetch action runs for an organisation
- THEN the store MUST populate the contact persons with their linked-user details

#### Scenario: Run a user-management action
- WHEN a user-management action runs
- THEN the store MUST perform it and reflect the updated state

### Requirement: Navigation store (REQ-FE-503)

The navigation store SHALL hold the active UI navigation state — the open modal, open dialog, current selection and selected organisation — and SHALL expose setters that carry transfer data between views.

`navigation.js` exposes setModal/setDialog/setSelected/setSelectedOrganisatie and a transfer-data accessor.

#### Scenario: Open a modal
- WHEN `setModal` is called with a modal identifier
- THEN the store MUST record the active modal so the shell renders it

### Requirement: Catalog store (REQ-FE-504)

The catalog store SHALL hold catalog state and SHALL expose actions to clear errors and reset its state.

`catalog.js` exposes clearError and reset.

#### Scenario: Reset the catalog store
- WHEN `reset` is called
- THEN the store MUST return to its initial state

### Requirement: Object operations store plugin (REQ-FE-505)

The object-operations store plugin SHALL provide the shared CRUD and lifecycle actions for register objects — fetch/save/patch/copy/delete, publish/depublish, lock/unlock, validate, download, merge, the bulk-operation runner, selection management, and the supporting initialization/refresh helpers — and SHALL surface per-object error state.

`softwarecatalogPlugin.js` augments stores with these object actions (e.g. `saveObject`, `deleteObject`, `publishObject`, `lockObject`, `mergeObjects`, `massPublishObjects`, `refreshObjectList`, `setActiveObject`, `toggleSelectAllObjects`, `updateColumnFilter`). The plugin installer and the generic `$patch` passthrough are framework plumbing and are excluded from coverage.

#### Scenario: Save an object
- WHEN `saveObject` is dispatched
- THEN the plugin MUST create or update the object and refresh the relevant state

#### Scenario: Run a bulk operation
- WHEN a mass-operation action (e.g. `massPublishObjects`) is dispatched over the selection
- THEN the plugin MUST apply the operation to each selected object and report progress

### Requirement: Theme service (REQ-FE-506)

The theme service SHALL detect the active Nextcloud theme (light/dark) and expose the corresponding theme variables to consumers.

`getTheme.js` exposes `getTheme`, `getThemeVariables` and `isDarkTheme`.

#### Scenario: Detect dark theme
- WHEN the active theme is dark
- THEN `isDarkTheme()` MUST return true and `getThemeVariables()` MUST return the dark variables

### Requirement: Heartbeat client (REQ-FE-507)

The heartbeat client SHALL periodically send a heartbeat to the backend while active and SHALL allow starting, stopping and querying the heartbeat, plus wrapping an async operation with an active heartbeat.

`heartbeat.js` exposes start/stop/startHeartbeat/stopHeartbeat/sendHeartbeat/isHeartbeatRunning/withHeartbeat. The class constructor is excluded as initialization-only.

#### Scenario: Start and stop the heartbeat
- WHEN `start()` is called and later `stop()`
- THEN the client MUST begin sending heartbeats and then cease, with `isHeartbeatRunning()` reflecting the state

