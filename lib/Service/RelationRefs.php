<?php

/**
 * The uuids an OpenRegister relation holds.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/connections-derived-dependencies/specs/derived-connections/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

/**
 * Reads a relation stored as a uuid string or as an object with `id`, `uuid` or `@self.id`.
 *
 * @spec openspec/changes/connections-derived-dependencies/specs/derived-connections/spec.md
 */
final class RelationRefs {
	/**
	 * The uuid a relation holds: a string, or an object with `id` or `uuid`.
	 *
	 * @param mixed $value The relation.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/connections-derived-dependencies/specs/derived-connections/spec.md
	 */
	public static function ref(mixed $value): ?string {
		if (is_array($value) === true) {
			$value = ($value['id'] ?? $value['uuid'] ?? $value['@self']['id'] ?? null);
		}

		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		return trim($value);
	}//end ref()

	/**
	 * The uuids a relation list holds.
	 *
	 * @param mixed $values The relations.
	 *
	 * @return list<string>
	 *
	 * @spec openspec/changes/connections-derived-dependencies/specs/derived-connections/spec.md
	 */
	public static function refs(mixed $values): array {
		if (is_array($values) === false) {
			return [];
		}

		return array_values(array_filter(array_map(static fn (mixed $value): ?string => self::ref(value: $value), $values)));
	}//end refs()
}//end class
