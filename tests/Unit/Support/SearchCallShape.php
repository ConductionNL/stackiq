<?php

/**
 * The old flat argument lists of ObjectService's search methods, rebuilt from the new calls.
 *
 * openregister #9103 moved searchObjects()'s and searchObjectsPaginated()'s ids,
 * uses, views and deleted settings into one SearchOptions value. The recording
 * (golden) tests captured the flat lists of the contract; flattening the new
 * call back to them keeps every recording unchanged, so a changed recording
 * still means changed behaviour.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Unit\Support
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Support;

use OCA\OpenRegister\Kernel\Contracts\SearchOptions;

/**
 * Flattens a search(query, _rbac, _multitenancy, options) call to the old parameter list.
 */
final class SearchCallShape {

	/**
	 * searchObjects() as the old list: query, _rbac, _multitenancy, ids, uses, views.
	 *
	 * @param array<int|string, mixed> $args The searchObjects() arguments, positional or by name.
	 *
	 * @return list<mixed>
	 */
	public static function search(array $args): array {
		[$query, $rbac, $multitenancy, $options] = self::parts(args: $args);

		return [$query, $rbac, $multitenancy, $options->selection->ids, $options->selection->uses, $options->views];
	}//end search()

	/**
	 * searchObjectsPaginated() as the old list: query, _rbac, _multitenancy, deleted, ids, uses, views.
	 *
	 * @param array<int|string, mixed> $args The searchObjectsPaginated() arguments, positional or by name.
	 *
	 * @return list<mixed>
	 */
	public static function paginated(array $args): array {
		[$query, $rbac, $multitenancy, $options] = self::parts(args: $args);

		return [$query, $rbac, $multitenancy, $options->deleted, $options->selection->ids, $options->selection->uses, $options->views];
	}//end paginated()

	/**
	 * The query, the two flags and the options of one call.
	 *
	 * @param array<int|string, mixed> $args The call's arguments, positional or by name.
	 *
	 * @return array{0: array, 1: bool, 2: bool, 3: SearchOptions}
	 */
	private static function parts(array $args): array {
		$options = ($args['options'] ?? $args[3] ?? null);
		if (($options instanceof SearchOptions) === false) {
			$options = new SearchOptions();
		}

		return [
			($args['query'] ?? $args[0] ?? []),
			($args['_rbac'] ?? $args[1] ?? true),
			($args['_multitenancy'] ?? $args[2] ?? true),
			$options,
		];
	}//end parts()

}//end class
