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
use OCA\Stackiq\Service\Cmdb\CmdbImportRow;
use OCA\Stackiq\Service\Cmdb\CmdbRowOutcome;
use PHPUnit\Framework\TestCase;

/**
 * The stored copy of a report is bounded.
 */
class CmdbImportReportTest extends TestCase {
	/**
	 * A row outside a run, at the given place, with the given warnings.
	 *
	 * @param array{sheet: string, row: int, appId: string, name: string} $entry    Where the row is.
	 * @param array<int, string>                                          $warnings The row's warnings.
	 *
	 * @return CmdbImportRow
	 */
	private function row(array $entry, array $warnings): CmdbImportRow {
		$row           = new CmdbImportRow(run: null, entry: $entry);
		$row->warnings = $warnings;
		return $row;
	}//end row()

	/**
	 * A report of five rows, one of each kind that matters.
	 *
	 * @return CmdbImportReport
	 */
	private function report(): CmdbImportReport {
		$report = new CmdbImportReport(operationId: 'cmdb-report-01', rowsRead: 5);
		$report->addRow(row: $this->row(entry: ['sheet' => 'S', 'row' => 2, 'appId' => '1', 'name' => 'Een'], warnings: []), outcome: new CmdbRowOutcome(outcome: CmdbImportReport::CREATED, reasons: []));
		$report->addRow(row: $this->row(entry: ['sheet' => 'S', 'row' => 3, 'appId' => '2', 'name' => 'Twee'], warnings: ['let op']), outcome: new CmdbRowOutcome(outcome: CmdbImportReport::UPDATED, reasons: []));
		$report->addRow(row: $this->row(entry: ['sheet' => 'S', 'row' => 4, 'appId' => '3', 'name' => 'Drie'], warnings: []), outcome: new CmdbRowOutcome(outcome: CmdbImportReport::SKIPPED, reasons: ['exists']));
		$report->addRow(row: $this->row(entry: ['sheet' => 'S', 'row' => 5, 'appId' => '4', 'name' => 'Vier'], warnings: []), outcome: new CmdbRowOutcome(outcome: CmdbImportReport::FAILED, reasons: ['step "module" failed (RuntimeException)']));
		$report->addRow(row: $this->row(entry: ['sheet' => 'S', 'row' => 6, 'appId' => '5', 'name' => 'Vijf'], warnings: []), outcome: new CmdbRowOutcome(outcome: CmdbImportReport::FAILED, reasons: ['step "usage" failed (RuntimeException)']));
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

	/**
	 * The reconciliation outcomes are counted, and their entries (row 0) are not counted as processed sheet rows.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cmdb-import-archive-reconciliation/specs/cmdb-export-import/spec.md#requirement-an-application-missing-from-the-cmdb-sheets-shall-be-archived-when-the-archive-sheet-lists-it-and-soft-deleted-when-no-sheet-does-req-cmdb-015
	 */
	public function testTheReconciliationOutcomesAreCounted(): void {
		$report = new CmdbImportReport(operationId: 'cmdb-report-02', rowsRead: 2);
		$report->addRow(row: $this->row(entry: ['sheet' => 'S', 'row' => 2, 'appId' => '1', 'name' => 'Een'], warnings: []), outcome: new CmdbRowOutcome(outcome: CmdbImportReport::UNARCHIVED, reasons: []));
		$report->addRow(row: $this->row(entry: ['sheet' => 'S', 'row' => 3, 'appId' => '2', 'name' => 'Twee'], warnings: []), outcome: new CmdbRowOutcome(outcome: CmdbImportReport::RESTORED, reasons: []));
		$report->addRow(row: $this->row(entry: ['sheet' => 'Gearchiveerde Applicaties', 'row' => 0, 'appId' => '7', 'name' => 'Zeven'], warnings: []), outcome: new CmdbRowOutcome(outcome: CmdbImportReport::ARCHIVED, reasons: []));
		$report->addRow(row: $this->row(entry: ['sheet' => '', 'row' => 0, 'appId' => '8', 'name' => 'Acht'], warnings: []), outcome: new CmdbRowOutcome(outcome: CmdbImportReport::DELETED, reasons: []));

		$summary = $report->summary();
		$this->assertSame(2, $summary['processed']);
		$this->assertSame(2, $report->processed());
		$this->assertSame([1, 1, 1, 1], [$summary['archived'], $summary['unarchived'], $summary['deleted'], $summary['restored']]);
		$this->assertFalse($report->isCancelled());
		$report->markCancelled();
		$this->assertTrue($report->isCancelled());
	}//end testTheReconciliationOutcomesAreCounted()
}//end class
