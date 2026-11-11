<?php

/**
 * Normalises an OpenRegister ObjectService result item to a data bag.
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

/**
 * The data bag of an ObjectService result item (an ObjectEntity or an array), with its uuid.
 *
 * @spec openspec/specs/catalog-ratings/spec.md
 */
final class ObjectDataBag {
	/**
	 * Normalise an ObjectService result item to a data bag (with its uuid).
	 *
	 * @param mixed $object The result item (ObjectEntity or array).
	 *
	 * @return array<string,mixed> The data bag.
	 *
	 * @spec openspec/specs/catalog-ratings/spec.md
	 */
	public static function of(mixed $object): array {
		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true && method_exists($object, 'getObject') === true) {
			$data = $object->getObject();
			if (method_exists($object, 'getUuid') === true && empty($data['id']) === true) {
				$data['id'] = $object->getUuid();
			}

			if (is_array($data) === true) {
				return $data;
			}

			return [];
		}

		return [];
	}//end of()
}//end class
