<?php

/**
 * Bootstrap file for PHPUnit unit tests (minimal — no Nextcloud bootstrap required).
 *
 * @category Test
 * @package  OCA\SoftwareCatalog\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conductio.nl
 */

declare(strict_types=1);

// Define that we're running PHPUnit.
define('PHPUNIT_RUN', 1);

// Include Composer's autoloader.
require_once __DIR__ . '/../vendor/autoload.php';

// Register OCP/NCU classes from nextcloud/ocp package.
// nextcloud/ocp has no autoload section in its composer.json, so we register it manually.
spl_autoload_register(function (string $class): void {
	$prefixMap = [
		'OCP\\' => __DIR__ . '/../vendor/nextcloud/ocp/OCP/',
		'NCU\\' => __DIR__ . '/../vendor/nextcloud/ocp/NCU/',
		// OpenRegister stubs — Db entities and Services used by tests.
		'OCA\\OpenRegister\\Db\\' => __DIR__ . '/Stubs/Db/',
		'OCA\\OpenRegister\\Service\\' => __DIR__ . '/Stubs/Service/',
	];

	foreach ($prefixMap as $prefix => $dir) {
		if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
			continue;
		}

		$relative = str_replace(search: '\\', replace: '/', subject: substr($class, strlen($prefix)));
		$file = $dir . $relative . '.php';
		if (file_exists($file) === true) {
			require_once $file;
		}

		break;
	}//end foreach
});
