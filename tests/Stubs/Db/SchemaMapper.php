<?php

/**
 * Test stub for OCA\OpenRegister\Db\SchemaMapper.
 *
 * The real mapper lives in the OpenRegister app, which is not available as a
 * Composer dependency in the Stackiq test environment. This stub
 * declares the narrow surface Stackiq unit tests mock (findBySlug),
 * used by SettingsService::verifyRegisterAgainstEffectiveConfig()
 * (register-import-reliability) to confirm a shipped schema slug actually
 * resolves in OpenRegister after import.
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Stubs\Db
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * Stub for the OpenRegister SchemaMapper with the surface used by
 * Stackiq tests.
 */
abstract class SchemaMapper {

	/**
	 * Find schemas matching a slug.
	 *
	 * @param string $slug The slug to search for.
	 * @param int $limit Maximum number of results.
	 * @param int $offset Offset for pagination.
	 *
	 * @return array<int, object> Array of matching schema entities.
	 */
	abstract public function findBySlug(string $slug, int $limit = 10, int $offset = 0): array;

}//end class
