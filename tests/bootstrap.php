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

// THE OpenRegister CONTRACT INTERFACES, OPTED INTO RATHER THAN AUTOLOADED.
//
// conduction/hydra-gates claims `OCA\OpenRegister\Contract\` as a RUNTIME psr-4
// prefix, so consumers get these interfaces implicitly. That prefix is LONGER
// than openregister's own `OCA\OpenRegister\` -> `lib/`, and PSR-4 is
// longest-prefix-wins, so whichever app's autoloader registers first defines
// OpenRegister's contract for the whole process (ConductionNL/.github#531).
//
// Loaded here, immediately after the autoloader and before the OpenRegister
// stubs below, for the same reason those stubs are loaded early: what is
// declared first wins, and the contract must exist before anything implementing
// it is declared.
//
// interface_exists() is order-independent — it asks whether the interface is
// RESOLVABLE, not who registered first. Appending a fallback autoloader does
// NOT work: spl_autoload_register appends relative to registration order, and
// that order across independently loaded apps is what nobody controls.
foreach (['ObjectEntityInterface', 'ObjectServiceInterface'] as $contract) {
	if (interface_exists('\\OCA\\OpenRegister\\Contract\\' . $contract) === false) {
		$shipped = __DIR__ . '/../vendor/conduction/hydra-gates/hydra-gates/contracts/' . $contract . '.php';
		if (file_exists($shipped) === true) {
			require_once $shipped;
		}
	}
}

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
