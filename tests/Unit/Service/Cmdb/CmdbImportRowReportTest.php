<?php

/**
 * A row's outcome lands in the run's report with everything the row knows.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service\Cmdb;

use OCA\Stackiq\Service\Cmdb\CmdbImportReport;
use OCA\Stackiq\Service\Cmdb\CmdbImportRow;
use OCA\Stackiq\Service\Cmdb\CmdbImportRun;
use PHPUnit\Framework\TestCase;

/**
 * Pins the report row CmdbImportRow::report() adds.
 *
 * @spec openspec/changes/cmdb-export-import/tasks.md#task-7
 */
class CmdbImportRowReportTest extends TestCase {
	/**
	 * Rows with and without warnings, objects and reasons; and a row outside a run.
	 *
	 * @return void
	 */
	public function testTheRowOutcomeIsReported(): void {
		$report = new CmdbImportReport(operationId: 'op-1', rowsRead: 3);
		$run    = new CmdbImportRun(
			$report,
			[
				'municipality' => [],
				'archive' => [],
				'operationId' => 'op-1',
				'missingRecords' => 'keep',
				'updateExisting' => true,
				'publicationDate' => null,
				'date1904' => false,
			]
		);

		$plain = new CmdbImportRow(run: $run, entry: ['sheet' => 'S', 'row' => 2, 'appId' => '1', 'name' => 'Een']);
		$plain->report(outcome: CmdbImportReport::CREATED);

		$full = new CmdbImportRow(run: $run, entry: ['sheet' => 'S', 'row' => 3, 'appId' => '2', 'name' => 'Twee']);
		$full->warnings   = [3 => 'let op', 7 => 'nog een'];
		$full->moduleUuid = 'module-2';
		$full->usageUuid  = 'usage-2';
		$full->report(outcome: CmdbImportReport::FAILED, reasons: [5 => 'step "usage" failed (RuntimeException)']);

		$archived = new CmdbImportRow(run: $run, entry: ['sheet' => '', 'row' => 0, 'appId' => '8', 'name' => 'Acht']);
		$archived->report(outcome: CmdbImportReport::DELETED, reasons: []);

		(new CmdbImportRow(run: null, entry: ['sheet' => 'S', 'row' => 9, 'appId' => '9', 'name' => 'Negen']))->report(outcome: CmdbImportReport::CREATED);

		$this->assertSame(
			[
				['sheet' => 'S', 'row' => 2, 'appId' => '1', 'name' => 'Een', 'outcome' => CmdbImportReport::CREATED, 'reasons' => [], 'warnings' => [], 'moduleUuid' => null, 'usageUuid' => null],
				['sheet' => 'S', 'row' => 3, 'appId' => '2', 'name' => 'Twee', 'outcome' => CmdbImportReport::FAILED, 'reasons' => ['step "usage" failed (RuntimeException)'], 'warnings' => ['let op', 'nog een'], 'moduleUuid' => 'module-2', 'usageUuid' => 'usage-2'],
				['sheet' => '', 'row' => 0, 'appId' => '8', 'name' => 'Acht', 'outcome' => CmdbImportReport::DELETED, 'reasons' => [], 'warnings' => [], 'moduleUuid' => null, 'usageUuid' => null],
			],
			$report->toArray()['rows']
		);
	}
}
