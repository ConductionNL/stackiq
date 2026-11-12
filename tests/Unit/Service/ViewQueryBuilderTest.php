<?php

/**
 * Unit tests for ViewQueryBuilder.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec exclude Query-array builder; the filters it maps are pinned here.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\Stackiq\Service\ViewQueryBuilder;
use PHPUnit\Framework\TestCase;

/**
 * How view filter options become OpenRegister query keys.
 *
 * @covers \OCA\Stackiq\Service\ViewQueryBuilder
 *
 * @spec exclude Query-array builder; the filters it maps are pinned here.
 */
class ViewQueryBuilderTest extends TestCase {

	/**
	 * With no filter options the base query is returned unchanged.
	 *
	 * @return void
	 */
	public function testNoOptionsLeaveTheBaseQueryAlone(): void {
		$base = ['register' => 3, 'schema' => 4];
		$this->assertSame(expected: $base, actual: (new ViewQueryBuilder())->buildQuery($base, []));
	}//end testNoOptionsLeaveTheBaseQueryAlone()

	/**
	 * Empty strings count as "no filter".
	 *
	 * @return void
	 */
	public function testEmptyStringsAreNotFilters(): void {
		$base = ['register' => 3];
		$options = ['dateFrom' => '', 'dateTo' => '', 'status' => '', 'search' => '', 'sortField' => ''];
		$this->assertSame(expected: $base, actual: (new ViewQueryBuilder())->buildQuery($base, $options));
	}//end testEmptyStringsAreNotFilters()

	/**
	 * Every option maps to its query key, and a descending order is recognised case-insensitively.
	 *
	 * @return void
	 */
	public function testEveryOptionMapsToItsQueryKey(): void {
		$query = (new ViewQueryBuilder())->buildQuery(
			['register' => 3],
			[
				'dateFrom' => '2026-01-01',
				'dateTo' => '2026-12-31',
				'status' => 'Actief',
				'search' => 'zaak',
				'sortField' => 'naam',
				'sortOrder' => 'DeSc',
			]
		);

		$this->assertSame(
			expected: [
				'register' => 3,
				'_modified_after' => '2026-01-01',
				'_modified_before' => '2026-12-31',
				'status' => 'Actief',
				'_search' => 'zaak',
				'_order' => ['naam' => 'DESC'],
			],
			actual: $query
		);
	}//end testEveryOptionMapsToItsQueryKey()

	/**
	 * Any order other than desc sorts ascending, including the default.
	 *
	 * @return void
	 */
	public function testAnyOtherOrderSortsAscending(): void {
		$builder = new ViewQueryBuilder();
		$this->assertSame(expected: ['_order' => ['naam' => 'ASC']], actual: $builder->applySorting([], 'naam'));
		$this->assertSame(expected: ['_order' => ['naam' => 'ASC']], actual: $builder->applySorting([], 'naam', 'sideways'));
		$this->assertSame(expected: [], actual: $builder->applySorting([], null, 'desc'));
	}//end testAnyOtherOrderSortsAscending()

	/**
	 * A one-sided date range sets only that side.
	 *
	 * @return void
	 */
	public function testOneSidedDateRange(): void {
		$builder = new ViewQueryBuilder();
		$this->assertSame(expected: ['_modified_before' => '2026-03-01'], actual: $builder->applyDateFilter([], null, '2026-03-01'));
		$this->assertSame(expected: ['_modified_after' => '2026-03-01'], actual: $builder->applyDateFilter([], '2026-03-01', null));
	}//end testOneSidedDateRange()
}//end class
