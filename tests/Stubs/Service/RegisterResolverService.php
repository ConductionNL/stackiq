<?php

/**
 * Test stub for OCA\OpenRegister\Service\RegisterResolverService.
 *
 * The real service lives in the OpenRegister app, which is not available as a
 * Composer dependency in the Stackiq test environment. This stub
 * declares the public surface that stackiq consumes (lookupId) so PHPUnit can create mocks against it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Stubs\Service
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * Stub for RegisterResolverService with the surface used by Stackiq tests.
 */
abstract class RegisterResolverService {

	/**
	 * Read the configured slug/UUID/name of a lookup from app config.
	 *
	 * @param ConfigLookup $lookup The app, config key and fallback to read.
	 *
	 * @return string The configured value, else the lookup's fallback.
	 */
	abstract public function lookupId(ConfigLookup $lookup): string;

}//end class
