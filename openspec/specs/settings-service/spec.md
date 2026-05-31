# settings-service Specification

## Purpose
TBD - created by archiving change retrofit-2026-05-26-settings-service. Update Purpose after archive.
## Requirements
### Requirement: The system SHALL detect and resolve OpenRegister availability and services (REQ-001)

`isOpenRegisterInstalled(minVersion)`, `isOpenRegisterEnabled()`, `getObjectService()`, `getRegisterService()`, `getConfigurationService()`, `getSchemaIdForObjectType(type)`, `getRegisterIdForObjectType(type)`, and `getVoorzieningenRegisterId()` MUST resolve OpenRegister availability and its service handles / register-schema ids, returning null when OpenRegister is absent.

#### Scenario: REQ-001 case 1
- WHEN `isOpenRegisterEnabled()` is called with OpenRegister disabled
- THEN it MUST return false

#### Scenario: REQ-001 case 2
- WHEN `getObjectService()` is called with OpenRegister enabled
- THEN it MUST return the OpenRegister ObjectService

### Requirement: The system SHALL read and persist every configuration domain (REQ-002)

The service MUST expose get/set (and focused get/update) pairs for voorzieningen, AMEF, email, ArchiMate, user-groups (generic/org-admin/super), cronjob, and catalog location, plus aggregate readers (`getSettings`, `getAllSettings`, `getConsolidatedConfiguration`, `getConfigurationStatus`, `isFullyConfigured`) and writers (`updateSettings`). Each MUST read from / write to app config and return the current or updated values.

#### Scenario: REQ-002 case 1
- WHEN `setVoorzieningenConfig(config)` then `getVoorzieningenConfig()` is called
- THEN the persisted config MUST be returned

#### Scenario: REQ-002 case 2
- WHEN `isFullyConfigured()` is called with all required config present
- THEN it MUST return true

### Requirement: The system SHALL run auto-configuration, import and configuration maintenance (REQ-003)

`autoConfigure`, `autoConfigureAfterImport`, `configureOpenCatalogi`, `initialize`, `loadSettings`, `performConsolidatedAutoConfiguration`, `manualImport`, `forceUpdate`, `resetAutoConfiguration`, `compactToJsonConfiguration`, `cleanupOldConfiguration`, and `clearConfigurationCache` MUST create/repair the register-schema configuration in OpenRegister, import seed data, and maintain the cached configuration, returning a result summary.

#### Scenario: REQ-003 case 1
- WHEN `autoConfigure(force=true)` is called
- THEN the registers/schemas MUST be (re)configured and a result summary returned

#### Scenario: REQ-003 case 2
- WHEN `clearConfigurationCache()` is called
- THEN the cached configuration MUST be invalidated

### Requirement: The system SHALL manage email settings, templates and connectivity tests (REQ-004)

`getEmailSettings`/`updateEmailSettings`, `getEmailConfig`/`setEmailConfig`/`getEmailConfigFocused`/`updateEmailConfig`, `getEmailTemplate`/`updateEmailTemplate`/`getDefaultEmailTemplate`/`getAllEmailTemplates`/`getEmailTemplateVariables`, `sendTestEmail`, and `testEmailConnection` MUST manage the email transport configuration + templates and run connectivity/test-send diagnostics.

#### Scenario: REQ-004 case 1
- WHEN `sendTestEmail(email)` is called
- THEN a test message MUST be dispatched and the result returned

#### Scenario: REQ-004 case 2
- WHEN `getDefaultEmailTemplate(name)` is called
- THEN the built-in default template body MUST be returned

### Requirement: The system SHALL manage user groups, ArchiMate operation status, statistics and organisation sync (REQ-005)

User-group helpers (`getGenericUserGroups`/`set...`/`update...`, org-admin, super-user, `getAllGroups`, `validateGroups`, `createAndConfigureUserGroups`, `getUserGroupsConfig`/`updateUserGroupsConfig`), ArchiMate-status helpers (`getArchiMateStatus`, `set/clear ArchiMateImportStatus`, `set/clear ArchiMateExportStatus`, `killArchiMateImport`, `cancelArchiMateImport`, `getArchiMateConfig`/`updateArchiMateConfig`), statistics (`getObjectCountsStatistics`, `getObjectsCounts`, `getObjectsStatistics`, `getDebugInfo`, `getVersionInfo`), cronjob config + context (`getCronjobConfig`/`updateCronjobConfig`, `getCronjobContext`, `getAvailableUsersForCronjobs`, `getAvailableOrganisationsForCronjobs`), and `syncOrganisationsToVoorzieningenOptimized` MUST each apply or report the requested state.

#### Scenario: REQ-005 case 1
- WHEN `validateGroups(groups)` is called
- THEN it MUST return which groups exist/are valid

#### Scenario: REQ-005 case 2
- WHEN `getArchiMateStatus()` is called during an import
- THEN it MUST report the in-progress import status

