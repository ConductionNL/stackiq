<?php

/**
 * Unit tests for ListingPagination: the underscored parameter wins over the plain one, and only numeric values become integer options.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Controller;

use OCA\Stackiq\Controller\ListingPagination;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ListingPagination.
 *
 * @covers \OCA\Stackiq\Controller\ListingPagination
 */
final class ListingPaginationTest extends TestCase {

	/**
	 * Pagination over a request with the given params.
	 *
	 * @param array<string,mixed> $params The request params.
	 *
	 * @return ListingPagination The pagination.
	 */
	private function pagination(array $params): ListingPagination {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key): mixed => ($params[$key] ?? null)
		);

		return new ListingPagination($request);
	}//end pagination()

	/**
	 * The underscored parameter wins; the plain one is the fallback; absent reads as null.
	 *
	 * @return void
	 */
	public function testTheUnderscoredParameterWins(): void {
		self::assertSame(
			['limit' => '5', 'offset' => '20', 'page' => null],
			$this->pagination(['_limit' => '5', 'limit' => '50', 'offset' => '20'])->rawParams()
		);
	}//end testTheUnderscoredParameterWins()

	/**
	 * Numeric values become integers: limit and offset in both spellings, the page only underscored.
	 *
	 * @return void
	 */
	public function testNumericValuesBecomeOptions(): void {
		self::assertSame(
			['_limit' => 5, 'limit' => 5, '_offset' => 20, 'offset' => 20, '_page' => 3],
			$this->pagination([])->options(['limit' => '5', 'offset' => 20, 'page' => '3'])
		);
	}//end testNumericValuesBecomeOptions()

	/**
	 * Null and non-numeric values are left out.
	 *
	 * @return void
	 */
	public function testNonNumericValuesAreLeftOut(): void {
		self::assertSame(
			['_page' => 0],
			$this->pagination([])->options(['limit' => 'ten', 'offset' => null, 'page' => '0'])
		);
		self::assertSame([], $this->pagination([])->options(['limit' => null, 'offset' => '', 'page' => 'x']));
	}//end testNonNumericValuesAreLeftOut()
}//end class
