<?php

/**
 * Test stub for OCA\OpenRegister\Service\ConfigLookup.
 *
 * Mirrors the constructor of OpenRegister's value object so tests can assert
 * what SettingsService asks RegisterResolverService::lookupId() for.
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Stubs\Service
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * Stub of the app id, config key, fallback and tenant of one resolver lookup.
 */
final class ConfigLookup {

	/**
	 * Build a lookup.
	 *
	 * @param string      $appId            Consumer app id.
	 * @param string      $configKey        Config key to read.
	 * @param string|null $default          Fallback when unset.
	 * @param string|null $organisationUuid Optional tenant override.
	 */
	public function __construct(
		public readonly string $appId,
		public readonly string $configKey,
		public readonly ?string $default=null,
		public readonly ?string $organisationUuid=null,
	) {
	}//end __construct()

}//end class
