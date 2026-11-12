<?php

/**
 * The old flat argument list of ObjectService::saveObject(), rebuilt from the new call.
 *
 * openregister #9123 moved saveObject()'s register, schema, uuid, extend, files,
 * save policy and acting user into one ObjectSaveOptions value. The recording
 * (golden) tests and several doubles captured the flat list of the contract
 * (twelve parameters); flattening the new call back to it keeps those
 * assertions and recordings unchanged, so a changed one still means changed
 * behaviour.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Unit\Support
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Support;

use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;

/**
 * Flattens a saveObject(object, options, _rbac, _multitenancy) call to the old parameter list.
 */
final class SaveCallShape {

	/**
	 * The old parameter names, in order, with their defaults.
	 *
	 * @var array<string, mixed>
	 */
	private const OLD = [
		'object'        => null,
		'extend'        => [],
		'register'      => null,
		'schema'        => null,
		'uuid'          => null,
		'_rbac'         => true,
		'_multitenancy' => true,
		'silent'        => false,
		'_validation'   => true,
		'uploadedFiles' => null,
		'currentUser'   => null,
		'failIfExists'  => false,
	];

	/**
	 * The call as the old twelve positional arguments.
	 *
	 * @param array<int|string, mixed> $args The saveObject() arguments, positional or by name.
	 *
	 * @return list<mixed>
	 */
	public static function flat(array $args): array {
		return array_values(self::byName(args: $args));
	}//end flat()

	/**
	 * The call as the old named arguments, defaults left out (the object always kept).
	 *
	 * @param array<int|string, mixed> $args The saveObject() arguments, positional or by name.
	 *
	 * @return array<string, mixed>
	 */
	public static function named(array $args): array {
		$named = [];
		foreach (self::byName(args: $args) as $name => $value) {
			if ($name !== 'object' && $value === self::OLD[$name]) {
				continue;
			}

			$named[$name] = $value;
		}

		return $named;
	}//end named()

	/**
	 * The call by old parameter name.
	 *
	 * @param array<int|string, mixed> $args The saveObject() arguments, positional or by name.
	 *
	 * @return array<string, mixed>
	 */
	public static function byName(array $args): array {
		$object  = ($args['object'] ?? $args[0] ?? null);
		$options = ($args['options'] ?? $args[1] ?? null);
		if (($options instanceof ObjectSaveOptions) === false) {
			$options = new ObjectSaveOptions();
		}

		return [
			'object'        => $object,
			'extend'        => $options->extend,
			'register'      => $options->target->register,
			'schema'        => $options->target->schema,
			'uuid'          => $options->uuid,
			'_rbac'         => ($args['_rbac'] ?? $args[2] ?? true),
			'_multitenancy' => ($args['_multitenancy'] ?? $args[3] ?? true),
			'silent'        => $options->policy->silent,
			'_validation'   => $options->policy->validation,
			'uploadedFiles' => $options->uploadedFiles,
			'currentUser'   => $options->actor->currentUser,
			'failIfExists'  => $options->policy->failIfExists,
		];
	}//end byName()

}//end class
