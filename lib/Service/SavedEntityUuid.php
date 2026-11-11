<?php

/**
 * Reads the uuid of a saveObject result.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/catalog-ratings/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

use Psr\Log\LoggerInterface;

/**
 * The uuid of a saved entity or array, or null.
 *
 * @spec openspec/specs/catalog-ratings/spec.md
 */
final class SavedEntityUuid {
	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger Logs an unreadable uuid.
	 * @param string          $owner  The service named in that log line.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly string $owner,
	) {
	}//end __construct()

	/**
	 * The uuid of a saved entity (handles entity or array result shapes).
	 *
	 * `ObjectService::saveObject()` returns an `ObjectEntity`, whose
	 * `getUuid()` is an `@method` docblock served by `Entity::__call()` over
	 * `protected ?string $uuid`. A bare `method_exists()` probe is therefore
	 * FALSE, and because an object is not an array the array arm below cannot
	 * rescue it — so this method used to return `null` for EVERY real save,
	 * putting `uuid: null` in the submit response and the audit log
	 * (stackiq#490). `property_exists()` is the instrument
	 * `Entity::getter()` itself decides on; `method_exists()` is kept as the
	 * second arm for genuinely concrete accessors, and the call is wrapped
	 * because neither probe guarantees the other object's shape.
	 *
	 * @param mixed $entity The saveObject result.
	 *
	 * @return string|null The uuid, or null.
	 *
	 * @spec openspec/specs/catalog-ratings/spec.md
	 */
	public function of(mixed $entity): ?string {
		if (is_object($entity) === true
			&& (property_exists($entity, 'uuid') === true || method_exists($entity, 'getUuid') === true)
		) {
			return $this->fromObject(entity: $entity);
		}

		if (is_array($entity) === true) {
			return $this->fromArray(entity: $entity);
		}

		return null;
	}//end of()

	/**
	 * The uuid of a saved entity, read through its getUuid().
	 *
	 * @param object $entity The saved entity.
	 *
	 * @return string|null The uuid, or null when it is empty, not a string or unreadable.
	 */
	private function fromObject(object $entity): ?string {
		try {
			$uuid = $entity->getUuid();
		} catch (\Throwable $e) {
			$this->logger->warning($this->owner . ': could not read uuid from saved entity', ['exception' => $e->getMessage()]);
			return null;
		}

		if (is_string($uuid) === true && $uuid !== '') {
			return $uuid;
		}

		return null;
	}//end fromObject()

	/**
	 * The id, or else the uuid, of a saved array.
	 *
	 * @param array<string, mixed> $entity The saved array.
	 *
	 * @return string|null The id or uuid, or null when it is not a string.
	 */
	private function fromArray(array $entity): ?string {
		$uuid = $entity['id'] ?? $entity['uuid'] ?? null;
		if (is_string($uuid) === true) {
			return $uuid;
		}

		return null;
	}//end fromArray()
}//end class
