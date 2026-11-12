<?php

/**
 * Test stub for OCA\OpenRegister\Kernel\Contracts\Value\BulkBatchDefaults.
 *
 * Mirrors OpenRegister's value object (openregister #9108): the register and
 * schema a bulk batch falls back to. OpenRegister types them as Register/Schema
 * entity, id, UUID or slug; the stub widens the entities to object.
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Stubs\Service
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Value;

/**
 * Stub of the default register and schema of one bulk save.
 */
final class BulkBatchDefaults {

	/**
	 * Build the defaults.
	 *
	 * @param object|string|int|null $register Register entity, id, UUID or slug.
	 * @param object|string|int|null $schema   Schema entity, id, UUID or slug.
	 */
	public function __construct(
		public readonly object|string|int|null $register=null,
		public readonly object|string|int|null $schema=null,
	) {
	}//end __construct()

}//end class
