<?php

/**
 * ObjectService double that also takes OpenRegister main's saveObject() options.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Support
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Support;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;

/**
 * An ObjectServiceInterface double base on OpenRegister main's saveObject()
 * signature, with a helper that finds the ObjectSaveOptions among a mocked
 * call's arguments.
 *
 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
 */
abstract class MainApiObjectService implements ObjectServiceInterface {

	/**
	 * Save an object (main's options form).
	 *
	 * @param array             $object        The object to store.
	 * @param ObjectSaveOptions $options       Where and how it is saved.
	 * @param bool              $_rbac         Apply register RBAC.
	 * @param bool              $_multitenancy Apply organisation scoping.
	 *
	 * @return ObjectEntityInterface The stored object.
	 */
	abstract public function saveObject(
		array $object,
		ObjectSaveOptions $options=new ObjectSaveOptions(),
		bool $_rbac=true,
		bool $_multitenancy=true
	): ObjectEntityInterface;

	/**
	 * The ObjectSaveOptions among a mocked saveObject() call's arguments.
	 *
	 * @param array<int,mixed> $args The call's arguments.
	 *
	 * @return ObjectSaveOptions|null The options, or null when the call passed none.
	 */
	public static function optionsOf(array $args): ?ObjectSaveOptions {
		foreach ($args as $arg) {
			if ($arg instanceof ObjectSaveOptions) {
				return $arg;
			}
		}

		return null;
	}//end optionsOf()
}//end class
