<?php

/**
 * Test stub for OCA\OpenRegister\Service\RegisterResolverService.
 *
 * The real service lives in the OpenRegister app, which is not available as a
 * Composer dependency in the Stackiq test environment. This stub
 * declares the public surface that stackiq consumes (resolveSchemaId,
 * resolveRegisterId) so PHPUnit can create mocks against it.
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
	 * Resolve a `<context>_register` config key to a slug or UUID string.
	 *
	 * @param string $appId Consumer app id.
	 * @param string $configKey Config key to read.
	 * @param string|null $default Fallback when unset.
	 * @param string|null $organisationUuid Optional org override.
	 *
	 * @return string
	 */
	abstract public function resolveRegisterId(
		string $appId,
		string $configKey,
		?string $default = null,
		?string $organisationUuid = null,
	): string;

	/**
	 * Resolve a `<context>_schema` config key to a slug or UUID string.
	 *
	 * @param string $appId Consumer app id.
	 * @param string $configKey Config key to read.
	 * @param string|null $default Fallback when unset.
	 * @param string|null $organisationUuid Optional org override.
	 *
	 * @return string
	 */
	abstract public function resolveSchemaId(
		string $appId,
		string $configKey,
		?string $default = null,
		?string $organisationUuid = null,
	): string;

}//end class
