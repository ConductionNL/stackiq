<?php

/**
 * Resolves the register/schema an object type lives in.
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

use OCA\Stackiq\Service\Settings\ObjectTypeLocatorInterface;

/**
 * The register/schema target of an object type, as ints.
 *
 * @spec openspec/specs/catalog-ratings/spec.md
 */
final class ObjectTypeTarget {
	/**
	 * Resolve the register/schema for a given object type.
	 *
	 * @param ObjectTypeLocatorInterface $locator Resolves register/schema ids.
	 * @param string                     $type    The object type.
	 *
	 * @return array{register:int, schema:int}|null The target, or null when either id is not configured.
	 *
	 * @spec openspec/specs/catalog-ratings/spec.md
	 */
	public static function of(ObjectTypeLocatorInterface $locator, string $type): ?array {
		$register = $locator->getRegisterIdForObjectType($type);
		$schema = $locator->getSchemaIdForObjectType($type);
		if ($register === null || $schema === null) {
			return null;
		}

		return ['register' => (int)$register, 'schema' => (int)$schema];
	}//end of()
}//end class
