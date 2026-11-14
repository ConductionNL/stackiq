<?php

/**
 * Tests for the CMDB workbook read filter.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service\Cmdb
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service\Cmdb;

use OCA\Stackiq\Service\Cmdb\CmdbReadFilter;
use PHPUnit\Framework\TestCase;

// PhpSpreadsheet ships with OpenRegister, not with this app; load the same interface stub static analysis uses.
if (interface_exists('PhpOffice\\PhpSpreadsheet\\Reader\\IReadFilter') === false) {
	require_once __DIR__.'/../../../analysis-stubs/phpspreadsheet-read-filter.stub.php';
}

/**
 * Which cells the CMDB import asks PhpSpreadsheet to load.
 *
 * @covers \OCA\Stackiq\Service\Cmdb\CmdbReadFilter
 */
class CmdbReadFilterTest extends TestCase {
	/**
	 * The header row is read only when it is the last row asked for.
	 *
	 * @return void
	 */
	public function testTheHeaderRowIsReadOnlyForAHeaderOnlyPass(): void {
		self::assertTrue((new CmdbReadFilter(1))->readCell('A', 1, 'Apps'));
		self::assertFalse((new CmdbReadFilter(10, ['Apps' => ['A']]))->readCell('A', 1, 'Apps'));
	}

	/**
	 * Data rows are read for the listed columns of their sheet, case-insensitively.
	 *
	 * @return void
	 */
	public function testDataRowsAreReadForTheListedColumnsOfTheirSheet(): void {
		$filter = new CmdbReadFilter(10, ['Apps' => ['A', 'C']]);

		self::assertTrue($filter->readCell('a', 2, 'Apps'));
		self::assertTrue($filter->readCell('C', 10, 'Apps'));
		self::assertFalse($filter->readCell('B', 2, 'Apps'));
		self::assertFalse($filter->readCell('A', 2, 'Notes'));
		self::assertFalse($filter->readCell('A', 2));
	}

	/**
	 * Rows outside 1..lastRow are never read.
	 *
	 * @return void
	 */
	public function testRowsOutsideTheWindowAreSkipped(): void {
		$filter = new CmdbReadFilter(10, ['Apps' => ['A']]);

		self::assertFalse($filter->readCell('A', 11, 'Apps'));
		self::assertFalse($filter->readCell('A', 0, 'Apps'));
		self::assertFalse($filter->readCell('A', -3, 'Apps'));
	}
}
