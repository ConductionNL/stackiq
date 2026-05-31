---
status: draft
retrofit: true
---

# Settings Admin Controller Specification

## Purpose

Captures observed behavior of the SettingsController — the admin-facing HTTP surface for configuration, status, synchronisation, email, user-groups, ArchiMate, and statistics. Each endpoint validates admin context and delegates to SettingsService / domain services, returning JSONResponse (or a streaming/file Response for progress and exports).

## ADDED Requirements

### Requirement: The system SHALL expose read/write endpoints for app configuration (REQ-001)

The controller MUST expose paired get/update endpoints for each configuration domain — general, sync, voorzieningen, AMEF, email, ArchiMate, user-groups, cronjob — plus the OpenRegister object-service/configuration-service accessors, `index`/`create`/`load`, and the catalog autoconfigure flows. Each MUST delegate to SettingsService and return the current or updated config as JSON.

#### Scenario: REQ-001 case 1
- WHEN `getGeneralConfig()` is called by an admin
- THEN it MUST return the current general configuration as JSON

#### Scenario: REQ-001 case 2
- WHEN `updateVoorzieningenConfig()` is called with a body
- THEN SettingsService MUST persist it and the endpoint MUST return the updated config

### Requirement: The system SHALL expose configuration status, auto-configuration and import endpoints (REQ-002)

`status`, `initialize`, `autoConfigure`, `consolidatedAutoConfigure`, `manualImport`, `forceUpdate`, `resetAutoConfig`, `clearCache`, `getProgress`, `streamProgress`, and `getVersionInfo` MUST run the corresponding SettingsService configuration/diagnostic operation and report the result (JSON, or an SSE stream for `streamProgress`).

#### Scenario: REQ-002 case 1
- WHEN `autoConfigure()` is called
- THEN the consolidated auto-configuration MUST run and its result MUST be returned

#### Scenario: REQ-002 case 2
- WHEN `streamProgress(operationId)` is called
- THEN it MUST stream progress events for the operation

### Requirement: The system SHALL expose synchronisation, heartbeat and statistics endpoints (REQ-003)

`getSyncStatus`, `getSyncConfig`/`updateSyncConfig`, `performSync`, `syncOrganisations`, `bulkSyncStandards`, `heartbeat`, `stats`, `debug`, `getObjectCounts`, `getObjectsCounts`, and `getObjectsStatistics` MUST run the matching sync/diagnostic/statistics routine and return the result as JSON.

#### Scenario: REQ-003 case 1
- WHEN `performSync(minutesBack)` is called
- THEN the synchronisation MUST run for that window and stats MUST be returned

#### Scenario: REQ-003 case 2
- WHEN `heartbeat()` is called
- THEN it MUST return the current liveness/heartbeat payload

### Requirement: The system SHALL expose email-configuration and template endpoints (REQ-004)

`getEmailSettings`/`updateEmailSettings`, `getEmailConfig`/`updateEmailConfig`, `getAmefConfig`/`updateAmefConfig`, `sendTestEmail`, `testEmailConnection`, `getEmailTemplates`, `getEmailTemplate`, `updateEmailTemplate`, `getEmailTemplateDefault`, and `getEmailTemplateVariables` MUST manage the email transport configuration and templates, returning the settings/template/test result as JSON.

#### Scenario: REQ-004 case 1
- WHEN `sendTestEmail()` is called with a recipient
- THEN a test email MUST be dispatched and the outcome returned

#### Scenario: REQ-004 case 2
- WHEN `updateEmailTemplate(name)` is called
- THEN the named template MUST be persisted

### Requirement: The system SHALL expose user-group and ArchiMate management endpoints (REQ-005)

The user-group endpoints (`getGenericUserGroups`/`setGenericUserGroups`, `getOrganizationAdminGroups`/`setOrganizationAdminGroups`, `getSuperUserGroups`/`setSuperUserGroups`, `getAllGroups`, `getUserGroupsConfig`/`updateUserGroupsConfig`) and the ArchiMate endpoints (`importArchiMate`, `exportArchiMate`, `exportOrgArchiMate`, `downloadArchiMate`, `getArchiMateSettings`, `getArchiMateConfig`/`updateArchiMateConfig`, `clearArchiMateImportStatus`, `killArchiMateImport`, `cancelArchiMateImport`, `clearArchiMateExportStatus`, `testArchiMateRoundTrip`) plus cronjob endpoints (`getCronjobConfig`/`updateCronjobConfig`, `getCronjobUsers`, `getCronjobOrganisations`) MUST apply the requested mutation or return the requested data — exports returning a downloadable Response.

#### Scenario: REQ-005 case 1
- WHEN `exportArchiMate()` is called
- THEN it MUST return the ArchiMate model as a downloadable Response

#### Scenario: REQ-005 case 2
- WHEN `setSuperUserGroups()` is called with a body
- THEN the super-user group list MUST be persisted
