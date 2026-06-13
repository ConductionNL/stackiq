# Retrofit — settings-admin-controller

Describes observed behavior of 70 methods as 5 REQ(s) under the `settings-admin-controller` capability. Code already exists — this change retroactively specifies it (strict coverage policy: every method gets a spec).

## Affected code units

- lib/Controller/SettingsController.php::getObjectService
- lib/Controller/SettingsController.php::getConfigurationService
- lib/Controller/SettingsController.php::index
- lib/Controller/SettingsController.php::create
- lib/Controller/SettingsController.php::getGeneralConfig
- lib/Controller/SettingsController.php::updateGeneralConfig
- lib/Controller/SettingsController.php::getSyncConfig
- lib/Controller/SettingsController.php::updateSyncConfig
- lib/Controller/SettingsController.php::getVoorzieningenConfig
- lib/Controller/SettingsController.php::updateVoorzieningenConfig
- lib/Controller/SettingsController.php::load
- lib/Controller/SettingsController.php::status
- lib/Controller/SettingsController.php::initialize
- lib/Controller/SettingsController.php::autoConfigure
- lib/Controller/SettingsController.php::consolidatedAutoConfigure
- lib/Controller/SettingsController.php::manualImport
- lib/Controller/SettingsController.php::forceUpdate
- lib/Controller/SettingsController.php::resetAutoConfig
- lib/Controller/SettingsController.php::clearCache
- lib/Controller/SettingsController.php::getProgress
- lib/Controller/SettingsController.php::streamProgress
- lib/Controller/SettingsController.php::getVersionInfo
- lib/Controller/SettingsController.php::getSyncStatus
- lib/Controller/SettingsController.php::performSync
- lib/Controller/SettingsController.php::syncOrganisations
- lib/Controller/SettingsController.php::bulkSyncStandards
- lib/Controller/SettingsController.php::heartbeat
- lib/Controller/SettingsController.php::stats
- lib/Controller/SettingsController.php::debug
- lib/Controller/SettingsController.php::getObjectCounts
- lib/Controller/SettingsController.php::getObjectsCounts
- lib/Controller/SettingsController.php::getObjectsStatistics
- lib/Controller/SettingsController.php::getEmailSettings
- lib/Controller/SettingsController.php::updateEmailSettings
- lib/Controller/SettingsController.php::getEmailConfig
- lib/Controller/SettingsController.php::updateEmailConfig
- lib/Controller/SettingsController.php::getAmefConfig
- lib/Controller/SettingsController.php::updateAmefConfig
- lib/Controller/SettingsController.php::sendTestEmail
- lib/Controller/SettingsController.php::testEmailConnection
- lib/Controller/SettingsController.php::getEmailTemplates
- lib/Controller/SettingsController.php::getEmailTemplate
- lib/Controller/SettingsController.php::updateEmailTemplate
- lib/Controller/SettingsController.php::getEmailTemplateDefault
- lib/Controller/SettingsController.php::getEmailTemplateVariables
- lib/Controller/SettingsController.php::getGenericUserGroups
- lib/Controller/SettingsController.php::setGenericUserGroups
- lib/Controller/SettingsController.php::getOrganizationAdminGroups
- lib/Controller/SettingsController.php::setOrganizationAdminGroups
- lib/Controller/SettingsController.php::getSuperUserGroups
- lib/Controller/SettingsController.php::setSuperUserGroups
- lib/Controller/SettingsController.php::getAllGroups
- lib/Controller/SettingsController.php::getUserGroupsConfig
- lib/Controller/SettingsController.php::updateUserGroupsConfig
- lib/Controller/SettingsController.php::importArchiMate
- lib/Controller/SettingsController.php::exportArchiMate
- lib/Controller/SettingsController.php::exportOrgArchiMate
- lib/Controller/SettingsController.php::downloadArchiMate
- lib/Controller/SettingsController.php::getArchiMateSettings
- lib/Controller/SettingsController.php::getArchiMateConfig
- lib/Controller/SettingsController.php::updateArchiMateConfig
- lib/Controller/SettingsController.php::clearArchiMateImportStatus
- lib/Controller/SettingsController.php::killArchiMateImport
- lib/Controller/SettingsController.php::cancelArchiMateImport
- lib/Controller/SettingsController.php::clearArchiMateExportStatus
- lib/Controller/SettingsController.php::testArchiMateRoundTrip
- lib/Controller/SettingsController.php::getCronjobConfig
- lib/Controller/SettingsController.php::updateCronjobConfig
- lib/Controller/SettingsController.php::getCronjobUsers
- lib/Controller/SettingsController.php::getCronjobOrganisations

## Approach

- Describe observed inputs/outputs/side-effects of each method.
- Group methods implementing the same observable behavior under one REQ.

Source: openspec/coverage-report (csc.py --mode report) generated 2026-05-26. Umbrella: ConductionNL/softwarecatalog#294.
