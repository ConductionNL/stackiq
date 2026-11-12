<?php

/**
 * The old flat argument list of ObjectService::find(), rebuilt from the new call.
 *
 * openregister #9116 moved find()'s register, schema, extend, files, render and
 * audit settings into one ObjectFindOptions value. The recording (golden) tests
 * captured the flat list; flattening the new call back to it keeps every
 * recording byte-for-byte, so a changed recording still means changed behaviour.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Unit\Support
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Support;

use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;

/**
 * Flattens a find(id, options, _rbac, _multitenancy) call to the old parameter list.
 */
final class FindCallShape {

	/**
	 * The old parameter names, in order, with their defaults.
	 *
	 * @var array<string, mixed>
	 */
	private const OLD = [
		'id'            => null,
		'_extend'       => [],
		'files'         => false,
		'register'      => null,
		'schema'        => null,
		'_rbac'         => true,
		'_multitenancy' => true,
		'_render'       => true,
		'_audit'        => true,
	];

	/**
	 * The call as the old nine positional arguments.
	 *
	 * @param array<int|string, mixed> $args The find() arguments, positional or by name.
	 *
	 * @return list<mixed>
	 */
	public static function find(array $args): array {
		return array_values(self::flat(args: $args));
	}//end find()

	/**
	 * The call as the old named arguments, defaults left out.
	 *
	 * @param array<int|string, mixed> $args The find() arguments, positional or by name.
	 *
	 * @return array<string, mixed>
	 */
	public static function named(array $args): array {
		$named = [];
		foreach (self::flat(args: $args) as $name => $value) {
			if ($name !== 'id' && $value === self::OLD[$name]) {
				continue;
			}

			$named[$name] = $value;
		}

		return $named;
	}//end named()

	/**
	 * The call by old parameter name.
	 *
	 * @param array<int|string, mixed> $args The find() arguments, positional or by name.
	 *
	 * @return array<string, mixed>
	 */
	private static function flat(array $args): array {
		$id           = ($args['id'] ?? $args[0] ?? null);
		$options      = ($args['options'] ?? $args[1] ?? null);
		$rbac         = ($args['_rbac'] ?? $args[2] ?? true);
		$multitenancy = ($args['_multitenancy'] ?? $args[3] ?? true);
		if (($options instanceof ObjectFindOptions) === false) {
			$options = new ObjectFindOptions();
		}

		return [
			'id'            => $id,
			'_extend'       => $options->extend,
			'files'         => $options->files,
			'register'      => $options->register,
			'schema'        => $options->schema,
			'_rbac'         => $rbac,
			'_multitenancy' => $multitenancy,
			'_render'       => $options->render,
			'_audit'        => $options->audit,
		];
	}//end flat()

}//end class
