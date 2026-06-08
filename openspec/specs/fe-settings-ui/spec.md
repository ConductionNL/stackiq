# fe-settings-ui Specification

## Purpose
TBD - created by archiving change retrofit-2026-05-26-fe-settings-ui. Update Purpose after archive.
## Requirements
### Requirement: Settings shell & navigation (REQ-FE-301)

The settings shell SHALL present the available configuration sections, switch between them, and surface the overall configuration status.

`Settings.vue` and `SoftwareCatalogSettings.vue` load the consolidated configuration, render section navigation, and report whether the app is fully configured.

#### Scenario: Open settings
- WHEN the settings view loads
- THEN it MUST display the configuration sections and the current configuration status

### Requirement: OpenRegister integration configuration (REQ-FE-302)

The OpenRegister integration section SHALL let the admin select registers/schemas for the app's object types, validate the selection, run auto-configuration, and persist it.

`OpenRegisterIntegration.vue` loads OpenRegister essentials, binds register/schema selections per object type, validates and saves the configuration, and supports auto-configure.

@e2e exclude Vue OpenRegister-integration settings section — select/validate/auto-configure/save is an admin interaction that dispatches a settings-store save persisted via REST; the component logic is tested by vitest (mocked store) and the persistence contract by Newman (settings-admin-controller). The settings shell render is covered by the manifest settings-page test.

#### Scenario: Save OpenRegister mapping
- WHEN the admin selects registers/schemas and saves
- THEN the section MUST persist the configuration and report the result

### Requirement: User groups configuration (REQ-FE-303)

The user-groups section SHALL let the admin configure which Nextcloud groups map to the app's roles and persist that mapping.

`UserGroupsConfiguration.vue` loads available groups and the current mapping, edits it, and saves.

@e2e exclude Vue user-groups settings section — edit/save group mapping is an admin interaction dispatching a settings-store save persisted via REST; component logic tested by vitest (mocked store), persistence by Newman (settings-admin-controller).

#### Scenario: Save group mapping
- WHEN the admin changes the group mapping and saves
- THEN the section MUST persist the mapping and report the result

### Requirement: Email configuration (REQ-FE-304)

The email section SHALL let the admin configure email delivery settings, test the connection, send a test email, and persist the settings.

`EmailConfiguration.vue` loads email config, validates input, tests the connection / sends a test message, and saves.

@e2e exclude Vue email settings section — test-connection/send-test/save is an admin interaction dispatching settings-store test+save actions hitting live SMTP/REST; component logic tested by vitest (mocked store), the test/send/persist contract by Newman + PHPUnit (email-delivery, settings-admin-controller).

#### Scenario: Test and save email settings
- WHEN the admin tests the email connection and saves
- THEN the section MUST report the test result and persist the settings

### Requirement: Cronjob configuration (REQ-FE-305)

The cronjob section SHALL let the admin view and configure the app's scheduled jobs and persist the configuration.

`CronjobConfiguration.vue` loads cronjob config/status, edits schedule settings, and saves.

@e2e exclude Vue cronjob settings section — edit/save schedule is an admin interaction dispatching a settings-store save persisted via REST; component logic tested by vitest (mocked store), persistence by Newman (settings-admin-controller).

#### Scenario: Save cronjob configuration
- WHEN the admin changes the cronjob settings and saves
- THEN the section MUST persist the configuration and report the result

### Requirement: Organization synchronization configuration (REQ-FE-306)

The organization-sync section SHALL let the admin configure the external organisation source, the sync time window, run/trigger a sync, and view sync status.

`OrganizationSynchronization.vue` loads sync config and status, updates the time window, triggers synchronisation, and reports status.

@e2e exclude Vue organisation-sync settings section — set-window/trigger-sync is an admin interaction dispatching a settings-store action that runs the backend sync; component logic tested by vitest (mocked store), the sync run by Newman + PHPUnit (organization-sync, progress-tracking).

#### Scenario: Configure and run organisation sync
- WHEN the admin sets the sync window and triggers a sync
- THEN the section MUST dispatch the sync and report its status

### Requirement: ArchiMate import/export (REQ-FE-307)

The ArchiMate section SHALL let the admin export the catalog to ArchiMate and import an ArchiMate file, validating the round-trip and reporting import/export status.

`ArchiMateImportExport.vue` loads ArchiMate status, exports to ArchiMate, imports an uploaded file, validates the round-trip, and surfaces progress/result.

@e2e exclude Vue ArchiMate import/export settings section — trigger-export and upload-import are admin interactions dispatching settings-store actions that produce/consume XML via the backend; component logic tested by vitest (mocked store), the XML round-trip by Newman + PHPUnit (archimate-export, archimate-import). The export-from-detail UI is additionally covered by org-archimate-export.

#### Scenario: Export to ArchiMate
- WHEN the admin triggers an export
- THEN the section MUST produce the ArchiMate export and report status

#### Scenario: Import an ArchiMate file
- WHEN the admin uploads and confirms an ArchiMate file
- THEN the section MUST dispatch the import and report the result

### Requirement: Statistics overview (REQ-FE-308)

The statistics overview SHALL load and display aggregate object counts and statistics for the catalog.

`StatisticsOverview.vue` loads object counts and statistics from the store and renders them.

#### Scenario: View statistics
- WHEN the statistics section loads
- THEN it MUST display the current object counts and statistics

### Requirement: Version information (REQ-FE-309)

The version section SHALL load and display the app's version information.

`VersionInformation.vue` loads version info from the store and renders it.

#### Scenario: View version information
- WHEN the version section loads
- THEN it MUST display the current version information

### Requirement: Navigation configuration panel (REQ-FE-310)

The navigation configuration panel SHALL load the current configuration and persist edits made by the user.

`Configuration.vue` fetches the configuration data and saves changes.

@e2e exclude Vue navigation-configuration panel — edit/save is an interaction dispatching a settings-store save persisted via REST; component logic tested by vitest (mocked store), persistence by Newman (settings-admin-controller).

#### Scenario: Save navigation configuration
- WHEN the user edits and saves the configuration
- THEN the panel MUST persist it and report the result

