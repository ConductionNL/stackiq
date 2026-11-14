<?php

/**
 * Where an architecture object comes from.
 *
 * A `view`, `element` or `relation` is either imported from GEMMA or drawn in
 * the view editor. The readers that serve GEMMA content to everyone (the views
 * list, the single view read and the full ArchiMate export) keep only what was
 * imported, so a drawn view never leaves the organisation that drew it. An
 * object saved before `origin` existed counts as imported, and any later origin
 * value is left out by default.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/architecture-views-editor/specs/architecture-views-editor/spec.md#requirement-req-ave-006-drawn-views-shall-stay-inside-the-organisation-that-drew-them
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

/**
 * The imported-or-not test the shared architecture readers apply.
 */
final class ViewOrigin {

	/**
	 * The origin the GEMMA import writes.
	 *
	 * @var string
	 */
	public const IMPORTED = 'imported';

	/**
	 * Whether an object read from the register is GEMMA content: origin empty or `imported`.
	 *
	 * @param mixed $object A serialised object, or an entity that serialises to one.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/architecture-views-editor/specs/architecture-views-editor/spec.md#requirement-req-ave-006-drawn-views-shall-stay-inside-the-organisation-that-drew-them
	 */
	public static function isImported(mixed $object): bool {
		if (is_object($object) === true && $object instanceof \JsonSerializable) {
			$object = $object->jsonSerialize();
		}

		if (is_array($object) === false) {
			return false;
		}

		$origin = ($object['origin'] ?? null);

		return ($origin === null || $origin === '' || $origin === self::IMPORTED);
	}//end isImported()

	/**
	 * The imported objects of a list, in their order.
	 *
	 * @param array<int, mixed> $objects Serialised objects or entities.
	 *
	 * @return array<int, mixed>
	 *
	 * @spec openspec/changes/architecture-views-editor/specs/architecture-views-editor/spec.md#requirement-req-ave-006-drawn-views-shall-stay-inside-the-organisation-that-drew-them
	 */
	public static function importedOnly(array $objects): array {
		return array_values(array_filter($objects, static fn (mixed $object): bool => self::isImported(object: $object)));
	}//end importedOnly()
}//end class
