<?php

/**
 * CMDB import report.
 *
 * Collects the outcome of every counted row and renders the report shape of
 * the contract (openspec/changes/cmdb-export-import/contract.md): a summary,
 * import-level warnings and one entry per row. Reasons and warnings name
 * sheets, columns and values, never owner names or e-mail addresses.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service\Cmdb
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

namespace OCA\Stackiq\Service\Cmdb;

/**
 * The per-row report of one CMDB import run.
 *
 * @spec openspec/changes/cmdb-export-import/tasks.md#task-7
 */
class CmdbImportReport {
	public const CREATED = 'created';
	public const UPDATED = 'updated';
	public const UNCHANGED = 'unchanged';
	public const SKIPPED = 'skipped';
	public const FAILED = 'failed';

	/**
	 * The row entries, in processing order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $rows = [];

	/**
	 * Import-level warnings.
	 *
	 * @var array<int, array{sheet: string, message: string}>
	 */
	private array $importWarnings = [];

	/**
	 * Whether the run stopped on a cancel.
	 *
	 * @var bool
	 */
	private bool $cancelled = false;

	/**
	 * The consuming municipality.
	 *
	 * @var array{uuid: string, name: string, created: bool}|null
	 */
	private ?array $municipality = null;

	/**
	 * Constructor.
	 *
	 * @param string $operationId The progress operation id.
	 * @param int $rowsRead Non-empty rows read from the workbook.
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-7
	 */
	public function __construct(
		private readonly string $operationId,
		private readonly int $rowsRead,
	) {
	}//end __construct()

	/**
	 * Add one row outcome.
	 *
	 * @param string $sheet The sheet name.
	 * @param int $row The 1-based sheet row number.
	 * @param string $middelId The Middel-ID ('' when missing).
	 * @param string $name The application name ('' when missing).
	 * @param string $outcome One of the outcome constants.
	 * @param array<int, string> $reasons Why the row was skipped or failed.
	 * @param array<int, string> $warnings Row warnings.
	 * @param string|null $moduleUuid The module, when there is one.
	 * @param string|null $usageUuid The usage, when there is one.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-7
	 */
	public function addRow(
		string $sheet,
		int $row,
		string $middelId,
		string $name,
		string $outcome,
		array $reasons = [],
		array $warnings = [],
		?string $moduleUuid = null,
		?string $usageUuid = null,
	): void {
		$this->rows[] = [
			'sheet' => $sheet,
			'row' => $row,
			'middelId' => $middelId,
			'name' => $name,
			'outcome' => $outcome,
			'reasons' => array_values($reasons),
			'warnings' => array_values($warnings),
			'moduleUuid' => $moduleUuid,
			'usageUuid' => $usageUuid,
		];
	}//end addRow()

	/**
	 * Add import-level warnings, such as a missing optional column.
	 *
	 * @param array<int, array{sheet: string, message: string}> $warnings The warnings.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-7
	 */
	public function addImportWarnings(array $warnings): void {
		foreach ($warnings as $warning) {
			$this->importWarnings[] = ['sheet' => (string)$warning['sheet'], 'message' => (string)$warning['message']];
		}
	}//end addImportWarnings()

	/**
	 * Record the consuming municipality.
	 *
	 * @param string $uuid The organisation uuid.
	 * @param string $name Its name.
	 * @param bool $created Whether this run created it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-7
	 */
	public function setMunicipality(string $uuid, string $name, bool $created): void {
		$this->municipality = ['uuid' => $uuid, 'name' => $name, 'created' => $created];
	}//end setMunicipality()

	/**
	 * Mark the run as stopped on a cancel.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-7
	 */
	public function markCancelled(): void {
		$this->cancelled = true;
	}//end markCancelled()

	/**
	 * The number of rows processed so far.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-7
	 */
	public function processed(): int {
		return count($this->rows);
	}//end processed()

	/**
	 * The summary counts.
	 *
	 * @return array{rowsRead: int, processed: int, created: int, updated: int, unchanged: int, skipped: int, failed: int, warnings: int}
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-7
	 */
	public function summary(): array {
		$summary = [
			'rowsRead' => $this->rowsRead,
			'processed' => count($this->rows),
			self::CREATED => 0,
			self::UPDATED => 0,
			self::UNCHANGED => 0,
			self::SKIPPED => 0,
			self::FAILED => 0,
			'warnings' => 0,
		];

		foreach ($this->rows as $row) {
			$summary[$row['outcome']]++;
			$summary['warnings'] += count($row['warnings']);
		}

		return $summary;
	}//end summary()

	/**
	 * The report in the contract shape.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-7
	 */
	public function toArray(): array {
		return [
			'success' => true,
			'operationId' => $this->operationId,
			'cancelled' => $this->cancelled,
			'municipality' => $this->municipality,
			'summary' => $this->summary(),
			'importWarnings' => $this->importWarnings,
			'rows' => $this->rows,
		];
	}//end toArray()
}//end class
