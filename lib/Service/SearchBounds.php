<?php

/**
 * StackIQ SearchBounds
 *
 * OpenRegister's `searchObjects()` reads `_limit` straight into
 * `setMaxResults()`, and a missing `_limit` means no LIMIT clause at all: the
 * whole register table is fetched and hydrated. This puts an explicit,
 * documented ceiling on a query that does not name its own limit, so no call
 * site can silently fall back to an unbounded scan (bound-unbounded-
 * searchobjects-scans). A limit the caller set is kept as it is.
 *
 * The ceiling is the one the full-catalogue ArchiMate export already uses:
 * far above any realistic register, so no existing result is cut short.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Stackiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bound-unbounded-searchobjects-scans/specs/query-performance/spec.md#requirement-every-searchobjects-call-must-set-an-explicit-_limit
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

/**
 * The explicit ceiling on an otherwise unbounded object search.
 *
 * @spec openspec/changes/bound-unbounded-searchobjects-scans/specs/query-performance/spec.md#requirement-every-searchobjects-call-must-set-an-explicit-_limit
 */
final class SearchBounds {

	/**
	 * The most objects one unpaged search returns.
	 *
	 * @var int
	 */
	public const CEILING = 10000;

	/**
	 * The query with `_limit` set to the ceiling when it names no limit of its own.
	 *
	 * @param array<string, mixed> $query The search query.
	 *
	 * @return array<string, mixed> The bounded query.
	 *
	 * @spec openspec/changes/bound-unbounded-searchobjects-scans/specs/query-performance/spec.md#requirement-every-searchobjects-call-must-set-an-explicit-_limit
	 */
	public static function bounded(array $query): array {
		if (isset($query['_limit']) === false) {
			$query['_limit'] = self::CEILING;
		}

		return $query;
	}//end bounded()
}//end class
