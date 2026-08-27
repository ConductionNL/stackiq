<?php

/**
 * Test stub for OCA\OpenRegister\Service\ConfigurationService.
 *
 * The real ConfigurationService lives in the OpenRegister app which is not
 * available as a Composer dependency in the test environment. This stub
 * declares the methods used by Stackiq unit tests so PHPUnit can
 * create mocks (SettingsService::resolveImportForce()'s
 * force-when-stale-version workaround for
 * https://github.com/ConductionNL/openregister/issues/2075).
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Stubs\Service
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * Stub for ConfigurationService with the surface used by Stackiq tests.
 */
abstract class ConfigurationService {

	/**
	 * Import configuration from an app's JSON data.
	 *
	 * @param string $appId The application ID.
	 * @param array $data The configuration data.
	 * @param string $version The configuration version.
	 * @param bool $force Force import regardless of version.
	 *
	 * @return array
	 */
	abstract public function importFromApp(string $appId, array $data, string $version, bool $force = false): array;

	/**
	 * Get the configured app version from appconfig.
	 *
	 * @param string $appId The app ID to get the version for.
	 *
	 * @return null|string The configured version or null if not set.
	 */
	abstract public function getConfiguredAppVersion(string $appId): ?string;

}//end class
