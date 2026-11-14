<?php

/**
 * Entry shape
 *
 * Pure readings of a catalogue entry and its schemas for a type change: which
 * type an entry is, which service types a schema allows, which values count.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service\EntryType
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/landscape-change-entry-type/specs/entry-type-change/spec.md#requirement-req-etc-002-the-user-sees-what-carries-over-before-confirming
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service\EntryType;

use OCA\Stackiq\Service\EntryTypeService;

/**
 * Reads the type and the fields of an entry.
 *
 * @spec openspec/changes/landscape-change-entry-type/specs/entry-type-change/spec.md#requirement-req-etc-002-the-user-sees-what-carries-over-before-confirming
 */
final class EntryShape {

	/**
	 * The type of an entry, from its schema and, for a module, its type field.
	 *
	 * @param string $objectType The schema slug.
	 * @param array<string, mixed> $data The entry's data.
	 *
	 * @spec openspec/changes/landscape-change-entry-type/specs/entry-type-change/spec.md#requirement-req-etc-002-the-user-sees-what-carries-over-before-confirming
	 *
	 * @return string
	 */
	public function typeOf(string $objectType, array $data): string {
		if ($objectType === 'catalogService') {
			return EntryTypeService::SERVICE;
		}

		if (($data['type'] ?? '') === EntryTypeService::MODULE_TYPE[EntryTypeService::SYSTEM_SOFTWARE]) {
			return EntryTypeService::SYSTEM_SOFTWARE;
		}

		return EntryTypeService::APPLICATION;

	}//end typeOf()

	/**
	 * The service types a catalogService may hold, or none for another schema.
	 *
	 * @param array<string, mixed> $properties The target schema's properties.
	 *
	 * @spec openspec/changes/landscape-change-entry-type/specs/entry-type-change/spec.md#requirement-req-etc-002-the-user-sees-what-carries-over-before-confirming
	 *
	 * @return array<int, string>
	 */
	public function serviceTypesOf(array $properties): array {
		$type = $properties['type'] ?? [];
		if (is_array($type) === false || ($type['type'] ?? '') !== 'array') {
			return [];
		}

		return array_values(array_map('strval', (array)($type['items']['enum'] ?? [])));

	}//end serviceTypesOf()

	/**
	 * Whether a value holds something a user would miss.
	 *
	 * @param mixed $value The value.
	 *
	 * @spec openspec/changes/landscape-change-entry-type/specs/entry-type-change/spec.md#requirement-req-etc-002-the-user-sees-what-carries-over-before-confirming
	 *
	 * @return bool
	 */
	public function isFilled(mixed $value): bool {
		return $value !== null && $value !== '' && $value !== [];

	}//end isFilled()
}//end class
