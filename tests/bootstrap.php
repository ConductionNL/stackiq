<?php

/**
 * Bootstrap file for PHPUnit tests
 *
 * @category Test
 * @package  OCA\SoftwareCatalog\Tests
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://SoftwareCatalog.app
 */

declare(strict_types=1);

// Define that we're running PHPUnit
define('PHPUNIT_RUN', 1);

// Include Composer's autoloader
require_once __DIR__ . '/../vendor/autoload.php';

// OpenRegister test stubs. The real OCA\OpenRegister\Db\ObjectEntity has
// __call magic getters that PHPUnit cannot configure on a mock, so the unit
// tests use the explicit stub in tests/Stubs/. It is loaded HERE, BEFORE
// Nextcloud's app bootstrap, so the stub class wins over the real OR class
// when PHPUnit later resolves `OCA\OpenRegister\Db\ObjectEntity` for mock
// generation. We do NOT use a composer `autoload-dev` PSR-4 mapping for the
// foreign `OCA\OpenRegister\` namespace — that would shadow the real
// OpenRegister classes in any deployment whose vendor/ retains dev autoload
// entries (breaking every OR-backed app, see PR #232 / issue #230).
foreach (glob(__DIR__ . '/Stubs/{,**/}*.php', GLOB_BRACE) ?: [] as $stub) {
	require_once $stub;
}

// Bootstrap Nextcloud if not already done
if (!defined('OC_CONSOLE')) {
	// Try to include the main Nextcloud bootstrap
	if (file_exists(__DIR__ . '/../../../lib/base.php')) {
		require_once __DIR__ . '/../../../lib/base.php';
	}

	// Load Test\TestCase and other NC test classes (NC convention).
	if (file_exists(__DIR__ . '/../../../tests/autoload.php')) {
		require_once __DIR__ . '/../../../tests/autoload.php';
	}

	// Load all enabled apps
	\OC_App::loadApps();

	// Load our specific app
	\OC_App::loadApp('softwarecatalog');

	// Clear hooks for testing
	OC_Hook::clear();
}
