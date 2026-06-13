# Retrofit — settings-service

Describes observed behavior of 83 methods as 5 REQ(s) under the `settings-service` capability. Code already exists — this change retroactively specifies it (strict coverage policy: every method gets a spec).

## Affected code units

- lib/Service/SettingsService.php::isOpenRegisterInstalled
- lib/Service/SettingsService.php::isOpenRegisterEnabled
- lib/Service/SettingsService.php::getObjectService
- lib/Service/SettingsService.php::getRegisterService
- lib/Service/SettingsService.php::getConfigurationService
- lib/Service/SettingsService.php::getSchemaIdForObjectType
- lib/Service/SettingsService.php::getRegisterIdForObjectType
- lib/Service/SettingsService.php::getVoorzieningenRegisterId
- lib/Service/SettingsService.php::getSettings
- lib/Service/SettingsService.php::updateSettings
- lib/Service/SettingsService.php::isFullyConfigured
- lib/Service/SettingsService.php::getConfigurationStatus
- lib/Service/SettingsService.php::getConsolidatedConfiguration
- lib/Service/SettingsService.php::getAllSettings
- lib/Service/SettingsService.php::getVoorzieningenConfig
- lib/Service/SettingsService.php::setVoorzieningenConfig
- lib/Service/SettingsService.php::getAmefConfig
- lib/Service/SettingsService.php::setAmefConfig
- lib/Service/SettingsService.php::getVoorzieningenConfigFocused
- lib/Service/SettingsService.php::updateVoorzieningenConfig
- lib/Service/SettingsService.php::getAmefConfigFocused
- lib/Service/SettingsService.php::updateAmefConfig
- lib/Service/SettingsService.php::getCatalogLocation
- lib/Service/SettingsService.php::setCatalogLocation
- lib/Service/SettingsService.php::getArchiMateConfig
- lib/Service/SettingsService.php::updateArchiMateConfig
- lib/Service/SettingsService.php::autoConfigure
- lib/Service/SettingsService.php::autoConfigureAfterImport
- lib/Service/SettingsService.php::configureOpenCatalogi
- lib/Service/SettingsService.php::initialize
- lib/Service/SettingsService.php::loadSettings
- lib/Service/SettingsService.php::performConsolidatedAutoConfiguration
- lib/Service/SettingsService.php::manualImport
- lib/Service/SettingsService.php::forceUpdate
- lib/Service/SettingsService.php::resetAutoConfiguration
- lib/Service/SettingsService.php::compactToJsonConfiguration
- lib/Service/SettingsService.php::cleanupOldConfiguration
- lib/Service/SettingsService.php::clearConfigurationCache
- lib/Service/SettingsService.php::getEmailSettings
- lib/Service/SettingsService.php::updateEmailSettings
- lib/Service/SettingsService.php::getEmailConfig
- lib/Service/SettingsService.php::setEmailConfig
- lib/Service/SettingsService.php::getEmailConfigFocused
- lib/Service/SettingsService.php::updateEmailConfig
- lib/Service/SettingsService.php::getEmailTemplate
- lib/Service/SettingsService.php::updateEmailTemplate
- lib/Service/SettingsService.php::getDefaultEmailTemplate
- lib/Service/SettingsService.php::getAllEmailTemplates
- lib/Service/SettingsService.php::getEmailTemplateVariables
- lib/Service/SettingsService.php::sendTestEmail
- lib/Service/SettingsService.php::testEmailConnection
- lib/Service/SettingsService.php::getGenericUserGroups
- lib/Service/SettingsService.php::setGenericUserGroups
- lib/Service/SettingsService.php::getOrganizationAdminGroups
- lib/Service/SettingsService.php::setOrganizationAdminGroups
- lib/Service/SettingsService.php::getSuperUserGroups
- lib/Service/SettingsService.php::setSuperUserGroups
- lib/Service/SettingsService.php::validateGroups
- lib/Service/SettingsService.php::createAndConfigureUserGroups
- lib/Service/SettingsService.php::getAllGroups
- lib/Service/SettingsService.php::updateGenericUserGroups
- lib/Service/SettingsService.php::updateOrganizationAdminGroups
- lib/Service/SettingsService.php::updateSuperUserGroups
- lib/Service/SettingsService.php::getUserGroupsConfig
- lib/Service/SettingsService.php::updateUserGroupsConfig
- lib/Service/SettingsService.php::getArchiMateStatus
- lib/Service/SettingsService.php::setArchiMateImportStatus
- lib/Service/SettingsService.php::setArchiMateExportStatus
- lib/Service/SettingsService.php::clearArchiMateImportStatus
- lib/Service/SettingsService.php::killArchiMateImport
- lib/Service/SettingsService.php::cancelArchiMateImport
- lib/Service/SettingsService.php::clearArchiMateExportStatus
- lib/Service/SettingsService.php::getObjectCountsStatistics
- lib/Service/SettingsService.php::getObjectsCounts
- lib/Service/SettingsService.php::getObjectsStatistics
- lib/Service/SettingsService.php::getDebugInfo
- lib/Service/SettingsService.php::getVersionInfo
- lib/Service/SettingsService.php::getCronjobConfig
- lib/Service/SettingsService.php::updateCronjobConfig
- lib/Service/SettingsService.php::getCronjobContext
- lib/Service/SettingsService.php::getAvailableUsersForCronjobs
- lib/Service/SettingsService.php::getAvailableOrganisationsForCronjobs
- lib/Service/SettingsService.php::syncOrganisationsToVoorzieningenOptimized

## Approach

- Describe observed inputs/outputs/side-effects of each method.
- Group methods implementing the same observable behavior under one REQ.

Source: openspec/coverage-report (csc.py --mode report) generated 2026-05-26. Umbrella: ConductionNL/softwarecatalog#294.
