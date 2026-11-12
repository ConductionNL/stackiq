<?php

/**
 * Runtime doubles for the OpenRegister events the debug listener projects
 * that tests/Stubs does not ship (lock, unlock, revert, register, schema and
 * organisation events), plus a concrete object entity.
 *
 * Required explicitly by OpenRegisterEventsDebugListenerGoldenTest, NOT placed
 * in `tests/Stubs/`, so nothing else in the suite sees them. Each declaration
 * is guarded: where the real class is autoloadable it wins.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\EventListener {
	/**
	 * A concrete object entity with fixed values.
	 */
	class DebugListenerObjectEntity extends \OCA\OpenRegister\Db\ObjectEntity {
		/**
		 * Constructor.
		 *
		 * @param array $values The entity values.
		 */
		public function __construct(public array $values) {
			$this->owner = ($values['owner'] ?? null);
		}

		/**
		 * The values by getter name.
		 *
		 * @param string $method The getter.
		 * @param array  $args   Unused.
		 *
		 * @return mixed
		 */
		public function __call(string $method, array $args) {
			return ($this->values[lcfirst(substr($method, 3))] ?? null);
		}

		/** @return mixed */
		public function getId() {
			return $this->values['id'];
		}

		/** @return string|null */
		public function getUuid(): ?string {
			return $this->values['uuid'];
		}

		/** @return array */
		public function getObject(): array {
			return $this->values['object'];
		}

		/** @return string|null */
		public function getRegister(): ?string {
			return $this->values['register'];
		}

		/** @return string|null */
		public function getSchema(): ?string {
			return $this->values['schema'];
		}

		/**
		 * @param mixed $object Unused.
		 *
		 * @return void
		 */
		public function setObject($object=null) {
		}

		/** @return array */
		public function jsonSerialize(): array {
			return $this->values;
		}
	}

	/**
	 * A register, schema or organisation double with fixed getters.
	 */
	class DebugListenerEntity {
		/**
		 * Constructor.
		 *
		 * @param array $values The values by getter name.
		 */
		public function __construct(private array $values) {
		}

		/**
		 * The values by getter name.
		 *
		 * @param string $method The getter.
		 * @param array  $args   Unused.
		 *
		 * @return mixed
		 */
		public function __call(string $method, array $args) {
			return ($this->values[lcfirst(substr($method, 3))] ?? null);
		}
	}
}

namespace OCA\OpenRegister\Event {
	if (class_exists(ObjectLockedEvent::class) === false) {
		/**
		 * Double: answers each getter from its payload.
		 */
		class ObjectLockedEvent extends \OCP\EventDispatcher\Event {
			/**
			 * Constructor.
			 *
			 * @param array $payload Getter results by getter name.
			 */
			public function __construct(private array $payload) {
			}

			/**
			 * The payload entry of a getter.
			 *
			 * @param string $method The getter.
			 * @param array  $args   Unused.
			 *
			 * @return mixed
			 */
			public function __call(string $method, array $args) {
				return ($this->payload[$method] ?? null);
			}
		}
	}

	if (class_exists(ObjectUnlockedEvent::class) === false) {
		/**
		 * Double: answers each getter from its payload.
		 */
		class ObjectUnlockedEvent extends \OCP\EventDispatcher\Event {
			/**
			 * Constructor.
			 *
			 * @param array $payload Getter results by getter name.
			 */
			public function __construct(private array $payload) {
			}

			/**
			 * The payload entry of a getter.
			 *
			 * @param string $method The getter.
			 * @param array  $args   Unused.
			 *
			 * @return mixed
			 */
			public function __call(string $method, array $args) {
				return ($this->payload[$method] ?? null);
			}
		}
	}

	if (class_exists(ObjectRevertedEvent::class) === false) {
		/**
		 * Double: answers each getter from its payload.
		 */
		class ObjectRevertedEvent extends \OCP\EventDispatcher\Event {
			/**
			 * Constructor.
			 *
			 * @param array $payload Getter results by getter name.
			 */
			public function __construct(private array $payload) {
			}

			/**
			 * The payload entry of a getter.
			 *
			 * @param string $method The getter.
			 * @param array  $args   Unused.
			 *
			 * @return mixed
			 */
			public function __call(string $method, array $args) {
				return ($this->payload[$method] ?? null);
			}
		}
	}

	if (class_exists(RegisterCreatedEvent::class) === false) {
		/**
		 * Double: answers each getter from its payload.
		 */
		class RegisterCreatedEvent extends \OCP\EventDispatcher\Event {
			/**
			 * Constructor.
			 *
			 * @param array $payload Getter results by getter name.
			 */
			public function __construct(private array $payload) {
			}

			/**
			 * The payload entry of a getter.
			 *
			 * @param string $method The getter.
			 * @param array  $args   Unused.
			 *
			 * @return mixed
			 */
			public function __call(string $method, array $args) {
				return ($this->payload[$method] ?? null);
			}
		}
	}

	if (class_exists(RegisterUpdatedEvent::class) === false) {
		/**
		 * Double: answers each getter from its payload.
		 */
		class RegisterUpdatedEvent extends \OCP\EventDispatcher\Event {
			/**
			 * Constructor.
			 *
			 * @param array $payload Getter results by getter name.
			 */
			public function __construct(private array $payload) {
			}

			/**
			 * The payload entry of a getter.
			 *
			 * @param string $method The getter.
			 * @param array  $args   Unused.
			 *
			 * @return mixed
			 */
			public function __call(string $method, array $args) {
				return ($this->payload[$method] ?? null);
			}
		}
	}

	if (class_exists(RegisterDeletedEvent::class) === false) {
		/**
		 * Double: answers each getter from its payload.
		 */
		class RegisterDeletedEvent extends \OCP\EventDispatcher\Event {
			/**
			 * Constructor.
			 *
			 * @param array $payload Getter results by getter name.
			 */
			public function __construct(private array $payload) {
			}

			/**
			 * The payload entry of a getter.
			 *
			 * @param string $method The getter.
			 * @param array  $args   Unused.
			 *
			 * @return mixed
			 */
			public function __call(string $method, array $args) {
				return ($this->payload[$method] ?? null);
			}
		}
	}

	if (class_exists(SchemaCreatedEvent::class) === false) {
		/**
		 * Double: answers each getter from its payload.
		 */
		class SchemaCreatedEvent extends \OCP\EventDispatcher\Event {
			/**
			 * Constructor.
			 *
			 * @param array $payload Getter results by getter name.
			 */
			public function __construct(private array $payload) {
			}

			/**
			 * The payload entry of a getter.
			 *
			 * @param string $method The getter.
			 * @param array  $args   Unused.
			 *
			 * @return mixed
			 */
			public function __call(string $method, array $args) {
				return ($this->payload[$method] ?? null);
			}
		}
	}

	if (class_exists(SchemaUpdatedEvent::class) === false) {
		/**
		 * Double: answers each getter from its payload.
		 */
		class SchemaUpdatedEvent extends \OCP\EventDispatcher\Event {
			/**
			 * Constructor.
			 *
			 * @param array $payload Getter results by getter name.
			 */
			public function __construct(private array $payload) {
			}

			/**
			 * The payload entry of a getter.
			 *
			 * @param string $method The getter.
			 * @param array  $args   Unused.
			 *
			 * @return mixed
			 */
			public function __call(string $method, array $args) {
				return ($this->payload[$method] ?? null);
			}
		}
	}

	if (class_exists(SchemaDeletedEvent::class) === false) {
		/**
		 * Double: answers each getter from its payload.
		 */
		class SchemaDeletedEvent extends \OCP\EventDispatcher\Event {
			/**
			 * Constructor.
			 *
			 * @param array $payload Getter results by getter name.
			 */
			public function __construct(private array $payload) {
			}

			/**
			 * The payload entry of a getter.
			 *
			 * @param string $method The getter.
			 * @param array  $args   Unused.
			 *
			 * @return mixed
			 */
			public function __call(string $method, array $args) {
				return ($this->payload[$method] ?? null);
			}
		}
	}

	if (class_exists(OrganisationCreatedEvent::class) === false) {
		/**
		 * Double: answers each getter from its payload.
		 */
		class OrganisationCreatedEvent extends \OCP\EventDispatcher\Event {
			/**
			 * Constructor.
			 *
			 * @param array $payload Getter results by getter name.
			 */
			public function __construct(private array $payload) {
			}

			/**
			 * The payload entry of a getter.
			 *
			 * @param string $method The getter.
			 * @param array  $args   Unused.
			 *
			 * @return mixed
			 */
			public function __call(string $method, array $args) {
				return ($this->payload[$method] ?? null);
			}
		}
	}
}
