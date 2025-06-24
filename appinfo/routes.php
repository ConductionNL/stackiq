<?php

declare(strict_types=1);

/**
 * SoftwareCatalog Routes Configuration
 *
 * This file defines the API routes for the SoftwareCatalog application.
 *
 * @category Configuration
 * @package  OCA\SoftwareCatalog
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link     https://github.com/ConductionNL/SoftwareCatalog
 * @version  1.0.0
 */

return [
	'routes' => [
		['name' => 'dashboard#page', 'url' => '/', 'verb' => 'GET'],
	],
	// Global Configuration
	['name' => 'settings#index', 'url' => '/api/settings', 'verb' => 'GET'],
	['name' => 'settings#create', 'url' => '/api/settings', 'verb' => 'POST'],
	['name' => 'settings#initialize', 'url' => '/api/settings/initialize', 'verb' => 'POST'],
	['name' => 'settings#status', 'url' => '/api/settings/status', 'verb' => 'GET'],
	['name' => 'settings#auto_configure', 'url' => '/api/settings/auto-configure', 'verb' => 'POST'],

	// Email Settings API routes
	['name' => 'email_settings#index', 'url' => '/api/email-settings', 'verb' => 'GET'],
	['name' => 'email_settings#update', 'url' => '/api/email-settings', 'verb' => 'POST'],
	['name' => 'email_settings#test', 'url' => '/api/email-settings/test', 'verb' => 'POST'],
	['name' => 'email_settings#status', 'url' => '/api/email-settings/status', 'verb' => 'GET'],
];
