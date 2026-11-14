<?php

/**
 * Converts ArchiMate property labels to camelCase property names.
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
 * CamelCase property names, cached per instance to avoid redundant conversions.
 *
 * @spec openspec/specs/archimate-import/spec.md
 */
final class CamelCaseNames {
	/**
	 * Cache for camelCase property name conversions to avoid redundant processing
	 *
	 * @var array<string, string>
	 */
	private array $cache = [];

	/**
	 * Convert property name with spaces to camelCase
	 *
	 * Examples:
	 * - "Object ID" -> "objectId"
	 * - "Business Unit" -> "businessUnit"
	 * - "System Name" -> "systemName"
	 *
	 * @param string $propertyName Property name that may contain spaces
	 *
	 * @return string CamelCase version of the property name
	 *
	 * @spec openspec/specs/archimate-import/spec.md
	 */
	public function convertName(string $propertyName): string {
		// OPTIMIZATION: Check cache first to avoid redundant conversions.
		if (isset($this->cache[$propertyName]) === true) {
			return $this->cache[$propertyName];
		}

		// Remove any leading/trailing whitespace.
		$propertyName = trim($propertyName);
		$result = self::convert(words: explode(' ', $propertyName));

		// OPTIMIZATION: Cache the result for future use.
		$this->cache[$propertyName] = $result;

		return $result;
	}//end convertName()

	/**
	 * Join the words of a label as camelCase.
	 *
	 * @param array<int, string> $words The words of the label, split by spaces.
	 *
	 * @return string The first word lowercased, every later word lowercased and capitalised.
	 */
	private static function convert(array $words): string {
		if (count($words) === 1) {
			// Single word, just lowercase it.
			return strtolower($words[0]);
		}

		// First word is lowercase, subsequent words are capitalized.
		$camelCase = strtolower($words[0]);
		foreach (array_slice($words, 1) as $word) {
			$camelCase .= ucfirst(strtolower($word));
		}

		return $camelCase;
	}//end convert()
}//end class
