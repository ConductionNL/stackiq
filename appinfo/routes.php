<?php

return [
	'routes' => [
		['name' => 'dashboard#page', 'url' => '/', 'verb' => 'GET'],
	],
	// Global Configuration
	['name' => 'settings#index', 'url' => '/api/settings', 'verb' => 'GET'],
	['name' => 'settings#create', 'url' => '/api/settings', 'verb' => 'POST'],
	['name' => 'settings#load', 'url' => '/api/settings/load', 'verb' => 'GET'],
];
