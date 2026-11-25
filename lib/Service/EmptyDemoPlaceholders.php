<?php

/**
 * Stackiq EmptyDemoPlaceholders.
 *
 * Empties the placeholder lists a generated demo descriptor writes for a
 * relation it has no value for: `[{}]`. They carry no data, but OpenRegister's importer treats each as a related object to
 * create, so one demo row became a nameless module (refused: name is required)
 * or wrote that new object's uuid into a json column (refused by PostgreSQL),
 * and the row it belonged to was not imported at all.
 *
 * @category Service
 * @package  OCA\Stackiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-11-25-insight-supplier-facet/tasks.md#task-4-seed-suppliers-and-document-the-facet
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

/**
 * Empties top-level properties of demo objects whose value is a list of only empty objects.
 *
 * @spec openspec/changes/archive/2026-11-25-insight-supplier-facet/tasks.md#task-4-seed-suppliers-and-document-the-facet
 */
class EmptyDemoPlaceholders {
	/**
	 * The descriptor with every list of only empty objects of its objects emptied.
	 *
	 * Only `components.objects` is changed, and only top-level properties of
	 * each object; `@self` is kept as it is. The property stays, as `[]`, so a
	 * required list is still present. Any other value is kept as it is: decoded
	 * to arrays, a single `{}` reads the same as an empty list.
	 *
	 * @param array<string, mixed> $data The decoded descriptor.
	 *
	 * @return array<string, mixed> The descriptor without empty placeholders.
	 *
	 * @spec openspec/changes/archive/2026-11-25-insight-supplier-facet/tasks.md#task-4-seed-suppliers-and-document-the-facet
	 */
	public function strip(array $data): array {
		$objects = ($data['components']['objects'] ?? null);
		if (is_array($objects) === false) {
			return $data;
		}

		foreach ($objects as $index => $object) {
			if (is_array($object) === false) {
				continue;
			}

			foreach ($object as $key => $value) {
				if ($key !== '@self' && self::isPlaceholderList(value: $value) === true) {
					$object[$key] = [];
				}
			}

			$objects[$index] = $object;
		}

		$data['components']['objects'] = $objects;
		return $data;
	}//end strip()

	/**
	 * Whether a value is a non-empty list holding only empty objects.
	 *
	 * @param mixed $value The property value as decoded (objects are arrays).
	 *
	 * @return bool True for `[{}]` and `[{}, {}]`.
	 *
	 * @spec openspec/changes/archive/2026-11-25-insight-supplier-facet/tasks.md#task-4-seed-suppliers-and-document-the-facet
	 */
	private static function isPlaceholderList(mixed $value): bool {
		if (is_array($value) === false || $value === [] || array_is_list($value) === false) {
			return false;
		}

		foreach ($value as $item) {
			if ($item !== []) {
				return false;
			}
		}

		return true;
	}//end isPlaceholderList()
}//end class
