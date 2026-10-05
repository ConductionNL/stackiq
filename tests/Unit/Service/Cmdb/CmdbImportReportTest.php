<?php

/**
 * Tests for the CMDB import report.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service\Cmdb
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/cmdb-export-import/tasks.md#task-7
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service\Cmdb;

use OCA\Stackiq\Service\Cmdb\CmdbImportReport;
use PHPUnit\Framework\TestCase;

/**
 * The stored copy of a report is bounded.
 */
class CmdbImportReportTest extends TestCase {
	/**
	 * A report of five rows, one of each kind that matters.
	 *
	 * @return CmdbImportReport
	 */
	private function report(): CmdbImportReport {
		$report = new CmdbImportReport(operationId: 'cmdb-report-01', rowsRead: 5);
		$report->addRow(sheet: 'S', row: 2, appId: '1', name: 'Een', outcome: CmdbImportReport::CREATED);
		$report->addRow(sheet: 'S', row: 3, appId: '2', name: 'Twee', outcome: CmdbImportReport::UPDATED, warnings: ['let op']);
		$report->addRow(sheet: 'S', row: 4, appId: '3', name: 'Drie', outcome: CmdbImportReport::SKIPPED, reasons: ['exists']);
		$report->addRow(sheet: 'S', row: 5, appId: '4', name: 'Vier', outcome: CmdbImportReport::FAILED, reasons: ['step "module" failed (RuntimeException)']);
		$report->addRow(sheet: 'S', row: 6, appId: '5', name: 'Vijf', outcome: CmdbImportReport::FAILED, reasons: ['step "usage" failed (RuntimeException)']);
		return $report;
	}//end report()

	/**
	 * Over the limit, the stored copy keeps every count and the failed, skipped and warned rows first.
	 *
	 * @return void
	 */
	public function testTheStoredCopyKeepsTheCountsAndTheRowsThatNeedAttention(): void {
		$report = $this->report();
		$stored = $report->toStoredArray(maxRows: 3);

		$this->assertSame($report->summary(), $stored['summary']);
		$this->assertSame(['4', '5', '3'], array_column($stored['rows'], 'appId'));
		$this->assertSame(3, $stored['rowsStored']);
		$this->assertTrue($stored['rowsTruncated']);
		$this->assertCount(5, $report->toArray()['rows'], 'the report itself is whole');
	}//end testTheStoredCopyKeepsTheCountsAndTheRowsThatNeedAttention()

	/**
	 * Within the limit, the stored copy holds every row in processing order and says so.
	 *
	 * @return void
	 */
	public function testWithinTheLimitEveryRowIsStored(): void {
		$report = $this->report();
		$stored = $report->toStoredArray(maxRows: 500);

		$this->assertSame(array_merge($report->toArray(), ['rowsStored' => 5, 'rowsTruncated' => false]), $stored);
	}//end testWithinTheLimitEveryRowIsStored()
}//end class
