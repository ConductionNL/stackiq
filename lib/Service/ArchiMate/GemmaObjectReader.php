<?php

/**
 * Reads the GEMMA facts off one ArchiMate object in import shape.
 *
 * @category Service
 * @package  OCA\Stackiq\Service\ArchiMate
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service\ArchiMate;

/**
 * The section, GEMMA type, reference, relationship ends and type of an object.
 *
 * @spec openspec/specs/archimate-import/spec.md
 */
class GemmaObjectReader {
	/**
	 * Property names a GEMMA type may be flattened to, in lookup order.
	 */
	private const GEMMA_TYPE_PROPERTIES = [
		'gemmaType',
		'gemmatype',
		'GemmaType',
		'GEMMA_Type',
		'gemma_type',
		'GEMMAType',
		'type',
		'elementType',
		'componentType',
	];

	/**
	 * Matches a uuid.
	 */
	private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

	/**
	 * The reference an object is linked by: its uuid id, else its slug.
	 *
	 * @param array<string, mixed> $object The object.
	 *
	 * @return string The reference.
	 *
	 * @spec openspec/specs/archimate-import/spec.md
	 */
	public function reference(array $object): string {
		$id = $object['@self']['id'] ?? null;
		if (is_string($id) === true && preg_match(self::UUID_PATTERN, $id) === 1) {
			return $id;
		}

		$identifier = (string) $object['identifier'];
		if (str_starts_with($identifier, 'id-') === true) {
			return substr($identifier, 3);
		}

		return $identifier;
	}//end reference()

	/**
	 * The GEMMA type of an element, or null when it has none.
	 *
	 * @param array<string, mixed> $object The element.
	 *
	 * @return string|null The GEMMA type.
	 *
	 * @spec openspec/specs/archimate-import/spec.md
	 */
	public function gemmaType(array $object): ?string {
		$property = (self::directTypeProperty(object: $object) ?? self::mappedTypeProperty(object: $object));
		if ($property === null) {
			return null;
		}

		return $this->scalar(value: $object[$property]);
	}//end gemmaType()

	/**
	 * The first of the known GEMMA type properties the element fills.
	 *
	 * @param array<string, mixed> $object The element.
	 *
	 * @return string|null The property name, or null when none is filled.
	 */
	private static function directTypeProperty(array $object): ?string {
		foreach (self::GEMMA_TYPE_PROPERTIES as $property) {
			if (empty($object[$property]) === false) {
				return $property;
			}
		}

		return null;
	}//end directTypeProperty()

	/**
	 * The first filled property whose original label names both GEMMA and type.
	 *
	 * @param array<string, mixed> $object The element.
	 *
	 * @return int|string|null The property key, or null when none matches.
	 */
	private static function mappedTypeProperty(array $object): int|string|null {
		foreach ($object['_propertyMapping'] ?? [] as $camelCase => $original) {
			if (self::isGemmaTypeLabel(label: (string) $original) === true && empty($object[$camelCase]) === false) {
				return $camelCase;
			}
		}

		return null;
	}//end mappedTypeProperty()

	/**
	 * Whether a property label names both GEMMA and type, in any case.
	 *
	 * @param string $label The original property label.
	 *
	 * @return bool
	 */
	private static function isGemmaTypeLabel(string $label): bool {
		return stripos($label, 'gemma') !== false && stripos($label, 'type') !== false;
	}//end isGemmaTypeLabel()

	/**
	 * A flattened property value as a string, unwrapping an XML value array.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string The string value.
	 */
	private function scalar(mixed $value): string {
		if (is_array($value) === true) {
			$value = $value['_value'] ?? $value[0] ?? '';
		}

		if (is_scalar($value) === false) {
			return '';
		}

		return (string) $value;
	}//end scalar()

	/**
	 * A Verbindingsrol as text, or null when it is not a string.
	 *
	 * @param mixed $role The Verbindingsrol.
	 *
	 * @return string|null The text.
	 *
	 * @spec openspec/specs/archimate-import/spec.md
	 */
	public function roleText(mixed $role): ?string {
		if (is_string($role) === true) {
			return $role;
		}

		return null;
	}//end roleText()

	/**
	 * The section of an object, singular.
	 *
	 * @param array<string, mixed> $object The object.
	 *
	 * @return string The section.
	 *
	 * @spec openspec/specs/archimate-import/spec.md
	 */
	public function section(array $object): string {
		return rtrim((string) ($object['section'] ?? ''), 's');
	}//end section()

	/**
	 * The ArchiMate type of a relationship.
	 *
	 * @param array<string, mixed> $relationship The relationship.
	 *
	 * @return mixed The type.
	 *
	 * @spec openspec/specs/archimate-import/spec.md
	 */
	public function relationType(array $relationship): mixed {
		return $relationship['type'] ?? $relationship['_xsi__type'] ?? $relationship['_attributes']['xsi:type'] ?? null;
	}//end relationType()

	/**
	 * One end of a relationship: the flattened property, else the XML.
	 *
	 * @param array<string, mixed> $relationship The relationship.
	 * @param string               $end          "source" or "target".
	 *
	 * @return string|null The identifier at that end, or null when there is none.
	 *
	 * @spec openspec/specs/archimate-import/spec.md
	 */
	public function endpoint(array $relationship, string $end): ?string {
		$candidates = [$relationship[$end] ?? null, $relationship['xml'][$end] ?? null, $relationship['xml']['_attributes'][$end] ?? null];
		foreach ($candidates as $value) {
			if (is_array($value) === true) {
				$value = $value['_attributes']['href'] ?? $value['_value'] ?? null;
			}

			if (is_string($value) === true && $value !== '') {
				return $value;
			}
		}

		return null;
	}//end endpoint()
}//end class
