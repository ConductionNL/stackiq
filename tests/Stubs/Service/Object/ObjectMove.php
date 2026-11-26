<?php

/**
 * Test stub for OCA\OpenRegister\Service\Object\ObjectMove.
 *
 * Mirrors the move OpenRegister's MoveObjectInterface::perform() takes: the
 * object, where it is, where it goes and who asked. The real class wins
 * whenever OpenRegister is loaded.
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Stubs\Service
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

if (class_exists(ObjectMove::class) === false) {
	/**
	 * One move.
	 */
	final class ObjectMove {
		/**
		 * @param object      $object The object.
		 * @param MoveAddress $source Where it is.
		 * @param MoveAddress $target Where it goes.
		 * @param string      $actor  Who asked.
		 */
		public function __construct(
			public readonly object $object,
			public readonly MoveAddress $source,
			public readonly MoveAddress $target,
			public readonly string $actor,
		) {
		}
	}
}
