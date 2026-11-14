<?php

/**
 * Unit tests for SearchBounds and the bounded-search invariant over lib/.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/bound-unbounded-searchobjects-scans/specs/query-performance/spec.md#requirement-every-searchobjects-call-must-set-an-explicit-_limit
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\Stackiq\Service\SearchBounds;
use PHPUnit\Framework\TestCase;

/**
 * The ceiling is added only when a query names no limit, and no search in lib/ goes without one.
 *
 * @covers \OCA\Stackiq\Service\SearchBounds
 */
class SearchBoundsTest extends TestCase {

	/**
	 * A query without a limit gets the ceiling, and keeps everything else.
	 *
	 * @return void
	 */
	public function testAQueryWithoutALimitGetsTheCeiling(): void {
		$query = ['@self' => ['register' => 1, 'schema' => 2], 'name' => 'x'];

		$this->assertSame(expected: $query + ['_limit' => 10000], actual: SearchBounds::bounded(query: $query));
		$this->assertSame(expected: 10000, actual: SearchBounds::CEILING);
	}//end testAQueryWithoutALimitGetsTheCeiling()

	/**
	 * A limit the caller set is kept, however large or small.
	 *
	 * @return void
	 */
	public function testACallersLimitIsKept(): void {
		$this->assertSame(expected: ['_limit' => 5, '_offset' => 10], actual: SearchBounds::bounded(query: ['_limit' => 5, '_offset' => 10]));
		$this->assertSame(expected: 50000, actual: SearchBounds::bounded(query: ['_limit' => 50000])['_limit']);
	}//end testACallersLimitIsKept()

	/**
	 * Every searchObjects() call in lib/ sits in a function that names `_limit` or SearchBounds.
	 *
	 * The automated form of the proposal's `_limit`-proximity grep: a new
	 * unbounded call fails here instead of fetching a whole register in
	 * production.
	 *
	 * @return void
	 */
	public function testNoSearchInLibIsUnbounded(): void {
		$unbounded = [];
		$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 3) . '/lib'));
		foreach ($files as $file) {
			if ($file->getExtension() !== 'php') {
				continue;
			}

			$unbounded = array_merge($unbounded, self::unboundedCalls(path: $file->getPathname()));
		}

		$this->assertSame(
			expected: [],
			actual: $unbounded,
			message: 'searchObjects() calls with no _limit in reach: ' . implode(separator: ', ', array: $unbounded)
		);
	}//end testNoSearchInLibIsUnbounded()

	/**
	 * The searchObjects() calls of one file whose function names neither `_limit` nor SearchBounds.
	 *
	 * @param string $path The file.
	 *
	 * @return list<string> `file:line` for each.
	 */
	private static function unboundedCalls(string $path): array {
		$lines = file($path);
		$found = [];
		foreach ($lines as $index => $line) {
			if (preg_match('/->searchObjects\(/', $line) !== 1 || preg_match('/^\s*(\*|\/\/)/', $line) === 1) {
				continue;
			}

			$start = $index;
			while ($start > 0 && preg_match('/\bfunction\s+\w+\s*\(/', $lines[$start]) !== 1) {
				$start--;
			}

			$scope = implode('', array_slice($lines, $start, ($index - $start + 12)));
			if (str_contains($scope, '_limit') === false && str_contains($scope, 'SearchBounds') === false) {
				$found[] = basename($path) . ':' . ($index + 1);
			}
		}

		return $found;
	}//end unboundedCalls()
}//end class
