<?php

/**
 * Test stub for OCA\OpenRegister\Db\ListQuery.
 *
 * Mirrors the constructor and the unbounded() factory of OpenRegister's list
 * query value object, so tests can assert what a mapper is asked for.
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Stubs\Db
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * Stub of one page (limit, offset, filters, sort, search) of a list query.
 */
final class ListQuery {

	public const DEFAULT_LIMIT = 20;

	public const UNBOUNDED = PHP_INT_MAX;

	/**
	 * Build a list query.
	 *
	 * @param int                  $limit   Maximum rows.
	 * @param int|null             $offset  Rows to skip.
	 * @param array<string,mixed>  $filters Column => value filters.
	 * @param array<string,string> $sort    Column => direction.
	 * @param string|null          $search  Free-text search.
	 */
	public function __construct(
		public readonly int $limit=self::DEFAULT_LIMIT,
		public readonly ?int $offset=null,
		public readonly array $filters=[],
		public readonly array $sort=[],
		public readonly ?string $search=null,
	) {
	}//end __construct()

	/**
	 * A query for every row.
	 *
	 * @param array<string,mixed>  $filters Column => value filters.
	 * @param array<string,string> $sort    Column => direction.
	 *
	 * @return self
	 */
	public static function unbounded(array $filters=[], array $sort=[]): self {
		return new self(limit: self::UNBOUNDED, filters: $filters, sort: $sort);
	}//end unbounded()

	/**
	 * Whether this query asks for every row.
	 *
	 * @return bool
	 */
	public function isUnbounded(): bool {
		return $this->limit === self::UNBOUNDED;
	}//end isUnbounded()

}//end class
