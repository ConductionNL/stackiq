<?php

/**
 * Test stub for OCA\OpenRegister\Service\Object\PermissionAsk.
 *
 * Mirrors the question OpenRegister's PermissionHandler::hasPermission() takes:
 * a schema and an action, optionally who asks, who owns the object and which
 * object. The real class wins whenever OpenRegister is loaded.
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Stubs\Service
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

if (class_exists(PermissionAsk::class) === false) {
	/**
	 * One permission question.
	 */
	final class PermissionAsk {
		/**
		 * @var string|null The user who asks; null is the signed-in user.
		 */
		public ?string $userId = null;

		/**
		 * @var string|null The object's owner.
		 */
		public ?string $objectOwner = null;

		/**
		 * @var object|null The object asked about.
		 */
		public ?object $object = null;

		/**
		 * @var string|null The register the object lives in.
		 */
		public ?string $register = null;

		/**
		 * @param object $schema The schema.
		 * @param string $action The action (create, read, update, delete).
		 */
		public function __construct(
			public readonly object $schema,
			public readonly string $action,
		) {
		}

		/**
		 * Name who asks and who owns the object.
		 *
		 * @param string|null $userId      The user id.
		 * @param string|null $objectOwner The object owner.
		 *
		 * @return self
		 */
		public function askedBy(?string $userId, ?string $objectOwner): self {
			$this->userId      = $userId;
			$this->objectOwner = $objectOwner;

			return $this;
		}

		/**
		 * Name the object and its register.
		 *
		 * @param object|null $object   The object.
		 * @param string|null $register The register.
		 *
		 * @return self
		 */
		public function about(?object $object, ?string $register): self {
			$this->object   = $object;
			$this->register = $register;

			return $this;
		}
	}
}
