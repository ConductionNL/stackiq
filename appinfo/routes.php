<?php

declare(strict_types=1);

/**
 * SoftwareCatalog Routes Configuration
 *
 * This file defines the API routes for the SoftwareCatalog application.
 *
 * @category Configuration
 * @package  OCA\SoftwareCatalog
 * @version  1.0.0
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link     https://github.com/ConductionNL/SoftwareCatalog
 */

return [
    'routes' => [
        // Dashboard route
        ['name' => 'dashboard#page', 'url' => '/', 'verb' => 'GET'],
        
        // Core Settings API routes (minimal, for basic app functionality)
        ['name' => 'settings#index', 'url' => '/api/settings', 'verb' => 'GET'],
        ['name' => 'settings#create', 'url' => '/api/settings', 'verb' => 'POST'],
        ['name' => 'settings#load', 'url' => '/api/settings/load', 'verb' => 'GET'],
        ['name' => 'settings#initialize', 'url' => '/api/settings/initialize', 'verb' => 'POST'],
        ['name' => 'settings#status', 'url' => '/api/settings/status', 'verb' => 'GET'],
        ['name' => 'settings#auto_configure', 'url' => '/api/settings/auto-configure', 'verb' => 'POST'],
        ['name' => 'settings#debug', 'url' => '/api/settings/debug', 'verb' => 'GET'],
        
        // Separate endpoints for performance optimization
        ['name' => 'settings#getArchiMateSettings', 'url' => '/api/settings/archimate', 'verb' => 'GET'],
        ['name' => 'settings#getObjectCounts', 'url' => '/api/settings/objects', 'verb' => 'GET'],
        
        // Organization synchronization routes
        ['name' => 'settings#getSyncStatus', 'url' => '/api/settings/sync-status', 'verb' => 'GET'],
        ['name' => 'settings#performSync', 'url' => '/api/settings/sync', 'verb' => 'POST'],
        
        // Heartbeat route for keeping connections alive during long operations
        ['name' => 'settings#heartbeat', 'url' => '/api/heartbeat', 'verb' => 'POST'],

        // Version and import management routes
        ['name' => 'settings#getVersionInfo', 'url' => '/api/settings/version', 'verb' => 'GET'],
        ['name' => 'settings#manualImport', 'url' => '/api/settings/import', 'verb' => 'POST'],
        ['name' => 'settings#forceUpdate', 'url' => '/api/settings/force-update', 'verb' => 'POST'],
        ['name' => 'settings#resetAutoConfig', 'url' => '/api/settings/reset-auto-config', 'verb' => 'POST'],

        // Email management routes
        ['name' => 'settings#send_test_email', 'url' => '/api/email/test', 'verb' => 'POST'],
        ['name' => 'settings#test_email_connection', 'url' => '/api/email/test-connection', 'verb' => 'POST'],
        ['name' => 'settings#get_email_settings', 'url' => '/api/settings/email', 'verb' => 'GET'],
        ['name' => 'settings#update_email_settings', 'url' => '/api/settings/email', 'verb' => 'POST'],
        
        // Email template management routes
        ['name' => 'settings#get_email_templates', 'url' => '/api/email/templates', 'verb' => 'GET'],
        ['name' => 'settings#get_email_template', 'url' => '/api/email/templates/{templateName}', 'verb' => 'GET'],
        ['name' => 'settings#update_email_template', 'url' => '/api/email/templates/{templateName}', 'verb' => 'POST'],
        ['name' => 'settings#get_email_template_default', 'url' => '/api/email/templates/{templateName}/default', 'verb' => 'GET'],
        ['name' => 'settings#get_email_template_variables', 'url' => '/api/email/templates/{templateName}/variables', 'verb' => 'GET'],
        
        // Health check endpoint
        ['name' => 'settings#health_check', 'url' => '/api/health', 'verb' => 'GET'],
        
        // Configuration cache management
        ['name' => 'settings#clear_cache', 'url' => '/api/settings/clear-cache', 'verb' => 'POST'],
        
        // Force re-initialization endpoint
        ['name' => 'settings#force_reinit', 'url' => '/api/settings/force-reinit', 'verb' => 'POST'],

        // ArchiMate import/export routes
        ['name' => 'settings#importArchiMate', 'url' => '/api/archimate/import', 'verb' => 'POST'],
        ['name' => 'settings#exportArchiMate', 'url' => '/api/archimate/export', 'verb' => 'POST'],
        ['name' => 'settings#downloadArchiMate', 'url' => '/api/archimate/download/{fileName}', 'verb' => 'GET'],
        
        // ArchiMate status management routes (status reading is via main settings endpoint)
        ['name' => 'settings#clearArchiMateImportStatus', 'url' => '/api/archimate/status/import/clear', 'verb' => 'POST'],
        ['name' => 'settings#cancelArchiMateImport', 'url' => '/api/archimate/import/cancel', 'verb' => 'POST'],
        ['name' => 'settings#killArchiMateImport', 'url' => '/api/archimate/import/kill', 'verb' => 'POST'], // deprecated
        ['name' => 'settings#clearArchiMateExportStatus', 'url' => '/api/archimate/status/export/clear', 'verb' => 'POST'],
        
        ['name' => 'settings#test_archimate_round_trip', 'url' => '/api/archimate/test-round-trip', 'verb' => 'POST'],

        // User Groups management routes
        ['name' => 'settings#get_generic_user_groups', 'url' => '/api/settings/user-groups/generic', 'verb' => 'GET'],
        ['name' => 'settings#set_generic_user_groups', 'url' => '/api/settings/user-groups/generic', 'verb' => 'POST'],
        ['name' => 'settings#get_organization_admin_groups', 'url' => '/api/settings/user-groups/organization-admin', 'verb' => 'GET'],
        ['name' => 'settings#set_organization_admin_groups', 'url' => '/api/settings/user-groups/organization-admin', 'verb' => 'POST'],
        ['name' => 'settings#get_super_user_groups', 'url' => '/api/settings/user-groups/super-user', 'verb' => 'GET'],
        ['name' => 'settings#set_super_user_groups', 'url' => '/api/settings/user-groups/super-user', 'verb' => 'POST'],
        ['name' => 'settings#get_all_groups', 'url' => '/api/settings/user-groups/all', 'verb' => 'GET'],

        // Progress streaming routes
        ['name' => 'settings#getProgress', 'url' => '/api/progress/{operationId}', 'verb' => 'GET'],
        ['name' => 'settings#streamProgress', 'url' => '/api/progress/{operationId}/stream', 'verb' => 'GET'],
        
        // ========================================================================
        // FOCUSED ENDPOINTS FOR PERFORMANCE OPTIMIZATION
        // ========================================================================
        
        // ArchiMate focused endpoints
        ['name' => 'settings#getArchiMateConfig', 'url' => '/api/archimate/config', 'verb' => 'GET'],
        ['name' => 'settings#updateArchiMateConfig', 'url' => '/api/archimate/config', 'verb' => 'POST'],
        ['name' => 'settings#getArchiMateConfig', 'url' => '/api/archimate/status', 'verb' => 'GET'],
        
        // Email focused endpoints
        ['name' => 'settings#getEmailConfig', 'url' => '/api/email/config', 'verb' => 'GET'],
        ['name' => 'settings#updateEmailConfig', 'url' => '/api/email/config', 'verb' => 'POST'],
        
        // AMEF focused endpoints
        ['name' => 'settings#getAmefConfig', 'url' => '/api/amef/config', 'verb' => 'GET'],
        ['name' => 'settings#updateAmefConfig', 'url' => '/api/amef/config', 'verb' => 'POST'],
        
        // Voorzieningen focused endpoints
        ['name' => 'settings#getVoorzieningenConfig', 'url' => '/api/voorzieningen/config', 'verb' => 'GET'],
        ['name' => 'settings#updateVoorzieningenConfig', 'url' => '/api/voorzieningen/config', 'verb' => 'POST'],
        
        // Objects focused endpoints (for object counts)
        ['name' => 'settings#getObjectsCounts', 'url' => '/api/objects/counts', 'verb' => 'GET'],
        ['name' => 'settings#getObjectsStatistics', 'url' => '/api/objects/statistics', 'verb' => 'GET'],
        
        // User Groups focused endpoints
        ['name' => 'settings#getUserGroupsConfig', 'url' => '/api/user-groups/config', 'verb' => 'GET'],
        ['name' => 'settings#updateUserGroupsConfig', 'url' => '/api/user-groups/config', 'verb' => 'POST'],
        
        // General Settings focused endpoints
        ['name' => 'settings#getGeneralConfig', 'url' => '/api/settings/general/config', 'verb' => 'GET'],
        ['name' => 'settings#updateGeneralConfig', 'url' => '/api/settings/general/config', 'verb' => 'POST'],
        
        // Organization Synchronization focused endpoints
        ['name' => 'settings#getSyncConfig', 'url' => '/api/settings/sync/config', 'verb' => 'GET'],
        ['name' => 'settings#updateSyncConfig', 'url' => '/api/settings/sync/config', 'verb' => 'POST'],
        ['name' => 'settings#syncOrganisations', 'url' => '/api/settings/sync/organisations', 'verb' => 'POST'],
        
        // Contactpersonen Management endpoints
        ['name' => 'contactpersonen#getContactpersonen', 'url' => '/api/contactpersonen/organisation/{organisationId}', 'verb' => 'GET'],
        ['name' => 'contactpersonen#convertToUser', 'url' => '/api/contactpersonen/{contactpersoonId}/convert-to-user', 'verb' => 'POST'],
        ['name' => 'contactpersonen#changePassword', 'url' => '/api/contactpersonen/change-password', 'verb' => 'POST'],
        ['name' => 'contactpersonen#updateUserGroups', 'url' => '/api/contactpersonen/update-groups', 'verb' => 'POST'],
        ['name' => 'contactpersonen#getUserInfo', 'url' => '/api/contactpersonen/{contactpersoonId}/user-info', 'verb' => 'GET'],
        ['name' => 'contactpersonen#getAvailableGroups', 'url' => '/api/contactpersonen/available-groups', 'verb' => 'GET'],
        
        // ========================================================================
        // VIEW API ENDPOINTS - ArchiMate Views with Enrichment Support
        // ========================================================================
        
        // View API endpoints for querying and enriching ArchiMate views
        ['name' => 'view#getAllViews', 'url' => '/api/views', 'verb' => 'GET'],
        ['name' => 'view#getApiDocumentation', 'url' => '/api/views/docs', 'verb' => 'GET'],
        ['name' => 'view#getView', 'url' => '/api/views/{viewId}', 'verb' => 'GET'],

        // ========================================================================
        // AANGEBODEN GEBRUIK API ENDPOINTS - Custom Objects API for Gebruiks
        // ========================================================================
        
        // AangebodenGebruik API endpoints for filtering gebruiks by organization involvement
        ['name' => 'aangebodenGebruik#getGebruiksWhereAfnemer', 'url' => '/api/aangeboden-gebruik/afnemer', 'verb' => 'GET'],
        ['name' => 'aangebodenGebruik#getGebruiksWhereDeelnemers', 'url' => '/api/aangeboden-gebruik/deelnemers', 'verb' => 'GET'],
        ['name' => 'aangebodenGebruik#getAllGebruiksForAmbtenaar', 'url' => '/api/aangeboden-gebruik/ambtenaar', 'verb' => 'GET'],
        ['name' => 'aangebodenGebruik#getSingleGebruikForAmbtenaar', 'url' => '/api/aangeboden-gebruik/ambtenaar/{gebruikId}', 'verb' => 'GET'],
        ['name' => 'aangebodenGebruik#setGebruikSelfToActiveOrg', 'url' => '/api/aangeboden-gebruik/{gebruikId}/set-self', 'verb' => 'PUT'],
        ['name' => 'aangebodenGebruik#deleteGebruikAsAfnemer', 'url' => '/api/aangeboden-gebruik/{gebruikId}/deny', 'verb' => 'DELETE'],
        ['name' => 'aangebodenGebruik#getApiDocumentation', 'url' => '/api/aangeboden-gebruik/docs', 'verb' => 'GET'],

    ],
];
