<?php

/**
 * Test stub for OCA\OpenRegister\Service\Object\MoveAddress.
 *
 * Mirrors where an OpenRegister move goes from or to: a register and a
 * schema. The real class wins whenever OpenRegister is loaded.
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Stubs\Service
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

if (class_exists(MoveAddress::class) === false) {
	/**
	 * A register and a schema.
	 */
	final class MoveAddress {
		/**
		 * @param object $register The register.
		 * @param object $schema   The schema.
		 */
		public function __construct(
			public readonly object $register,
			public readonly object $schema,
		) {
		}
	}
}
