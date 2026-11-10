<?php

/**
 * Test stub for OCA\OpenRegister\Service\Object\ObjectFindTarget.
 *
 * Mirrors OpenRegister's register/schema target value object. OpenRegister
 * types the two as Register|Schema entity, id, UUID or slug; the stub widens
 * the entities to object.
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Stubs\Service
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

/**
 * Stub of the register and schema one object call is scoped to.
 */
final class ObjectFindTarget {

	/**
	 * Build the target.
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
