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
        
        // Unified Settings API routes
        ['name' => 'settings#index', 'url' => '/api/settings', 'verb' => 'GET'],
        ['name' => 'settings#create', 'url' => '/api/settings', 'verb' => 'POST'],
        ['name' => 'settings#load', 'url' => '/api/settings/load', 'verb' => 'GET'],
        ['name' => 'settings#initialize', 'url' => '/api/settings/initialize', 'verb' => 'POST'],
        ['name' => 'settings#status', 'url' => '/api/settings/status', 'verb' => 'GET'],
        ['name' => 'settings#auto_configure', 'url' => '/api/settings/auto-configure', 'verb' => 'POST'],
        ['name' => 'settings#debug', 'url' => '/api/settings/debug', 'verb' => 'GET'],
        
        // Organization synchronization routes
        ['name' => 'settings#getSyncStatus', 'url' => '/api/settings/sync-status', 'verb' => 'GET'],
        ['name' => 'settings#performSync', 'url' => '/api/settings/sync', 'verb' => 'POST'],

        // Version and import management routes
        ['name' => 'settings#getVersionInfo', 'url' => '/api/settings/version', 'verb' => 'GET'],
        ['name' => 'settings#manualImport', 'url' => '/api/settings/import', 'verb' => 'POST'],
        		        ['name' => 'settings#forceUpdate', 'url' => '/api/settings/force-update', 'verb' => 'POST'],
        ['name' => 'settings#resetAutoConfig', 'url' => '/api/settings/reset-auto-config', 'verb' => 'POST'],

        // Legacy email routes (for backward compatibility)
        ['name' => 'settings#send_test_email', 'url' => '/api/email/test', 'verb' => 'POST'],
        
        // Health check endpoint
        ['name' => 'settings#health_check', 'url' => '/api/health', 'verb' => 'GET'],
        
        // Force re-initialization endpoint
        ['name' => 'settings#force_reinit', 'url' => '/api/settings/force-reinit', 'verb' => 'POST'],

        // ArchiMate import/export routes
        ['name' => 'settings#importArchiMate', 'url' => '/api/archimate/import', 'verb' => 'POST'],
        ['name' => 'settings#exportArchiMate', 'url' => '/api/archimate/export', 'verb' => 'POST'],
        ['name' => 'settings#downloadArchiMate', 'url' => '/api/archimate/download/{fileName}', 'verb' => 'GET'],
        
        // ArchiMate status management routes
        ['name' => 'settings#getArchiMateStatus', 'url' => '/api/archimate/status', 'verb' => 'GET'],
        ['name' => 'settings#clearArchiMateImportStatus', 'url' => '/api/archimate/status/import/clear', 'verb' => 'POST'],
        ['name' => 'settings#clearArchiMateExportStatus', 'url' => '/api/archimate/status/export/clear', 'verb' => 'POST'],



        // AMEF register configuration routes
        ['name' => 'settings#getAmefSettings', 'url' => '/api/settings/amef', 'verb' => 'GET'],
        ['name' => 'settings#saveAmefSettings', 'url' => '/api/settings/amef', 'verb' => 'POST'],
        ['name' => 'settings#autoConfigureAmef', 'url' => '/api/settings/amef/auto-configure', 'verb' => 'POST'],

        // Progress streaming routes
        ['name' => 'settings#getProgress', 'url' => '/api/progress/{operationId}', 'verb' => 'GET'],
        ['name' => 'settings#streamProgress', 'url' => '/api/progress/{operationId}/stream', 'verb' => 'GET'],
    ],
];
