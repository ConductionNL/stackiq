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
use OCP\IUser;

/**
 * The contract interface tests load still declares the older saveObject()
 * signature. Code on OpenRegister main's API calls saveObject(object:, options:);
 * this abstract widens that signature with a trailing `$options` so such a call
 * can be mocked, and leaves the shared contract untouched.
 *
 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
 * @SuppressWarnings(PHPMD.ExcessiveParameterList)
 */
abstract class MainApiObjectService implements ObjectServiceInterface {

	/**
	 * Save an object, in either the older named-argument form or main's options form.
	 *
	 * @param array                  $object        The object to store.
	 * @param ?array                 $extend        Relations to expand on the result.
	 * @param string|int|null        $register      Register id, UUID or slug.
	 * @param string|int|null        $schema        Schema id, UUID or slug.
	 * @param ?string                $uuid          The object UUID.
	 * @param bool                   $_rbac         Apply register RBAC.
	 * @param bool                   $_multitenancy Apply organisation scoping.
	 * @param bool                   $silent        Suppress events for this save.
	 * @param bool                   $_validation   Validate against the schema.
	 * @param ?array                 $uploadedFiles Files uploaded alongside the object.
	 * @param ?IUser                 $currentUser   Explicit acting user.
	 * @param bool                   $failIfExists  Fail when the object already exists.
	 * @param ObjectSaveOptions|null $options       Main's options form.
	 *
	 * @return ObjectEntityInterface The stored object.
	 */
	abstract public function saveObject(
		array $object,
		?array $extend=[],
		string|int|null $register=null,
		string|int|null $schema=null,
		?string $uuid=null,
		bool $_rbac=true,
		bool $_multitenancy=true,
		bool $silent=false,
		bool $_validation=true,
		?array $uploadedFiles=null,
		?IUser $currentUser=null,
		bool $failIfExists=false,
		?ObjectSaveOptions $options=null
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
