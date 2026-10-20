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
	public const ARCHIVED = 'archived';
	public const UNARCHIVED = 'unarchived';
	public const DELETED = 'deleted';
	public const RESTORED = 'restored';

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
	 * Modules this run created without a publication date.
	 *
	 * @var int
	 */
	private int $unpublished = 0;

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
	 * @param int $row The 1-based sheet row number, or 0 for an application the reconciliation archived or deleted.
	 * @param string $appId The APPID ('' when missing).
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
		string $appId,
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
			'appId' => $appId,
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
	 * Count a module this run created without publishing it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-7
	 */
	public function countUnpublished(): void {
		$this->unpublished++;
	}//end countUnpublished()

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
	 * Whether the run stopped on a cancel.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/cmdb-import-archive-reconciliation/specs/cmdb-export-import/spec.md#requirement-an-application-missing-from-the-cmdb-sheets-shall-be-archived-when-the-archive-sheet-lists-it-and-soft-deleted-when-no-sheet-does-req-cmdb-015
	 */
	public function isCancelled(): bool {
		return $this->cancelled;
	}//end isCancelled()

	/**
	 * The number of sheet rows processed so far; reconciliation entries (row 0) are not sheet rows.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-7
	 */
	public function processed(): int {
		return count(array_filter($this->rows, static fn (array $row): bool => $row['row'] > 0));
	}//end processed()

	/**
	 * The summary counts.
	 *
	 * `unpublished` counts the modules created without a publication date;
	 * a row whose module was created but whose usage then failed counts too.
	 * `processed` counts sheet rows only; every outcome, also of a
	 * reconciliation entry, is counted under its own key.
	 *
	 * @return array{rowsRead: int, processed: int, created: int, updated: int, unchanged: int, skipped: int, failed: int,
	 *     archived: int, unarchived: int, deleted: int, restored: int, warnings: int, unpublished: int}
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-7
	 * @spec openspec/changes/cmdb-import-archive-reconciliation/specs/cmdb-export-import/spec.md#requirement-an-application-missing-from-the-cmdb-sheets-shall-be-archived-when-the-archive-sheet-lists-it-and-soft-deleted-when-no-sheet-does-req-cmdb-015
	 */
	public function summary(): array {
		$summary = [
			'rowsRead' => $this->rowsRead,
			'processed' => $this->processed(),
			self::CREATED => 0,
			self::UPDATED => 0,
			self::UNCHANGED => 0,
			self::SKIPPED => 0,
			self::FAILED => 0,
			self::ARCHIVED => 0,
			self::UNARCHIVED => 0,
			self::DELETED => 0,
			self::RESTORED => 0,
			'warnings' => 0,
			'unpublished' => $this->unpublished,
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

	/**
	 * The report as it is stored with the operation: the counts, and at most
	 * $maxRows rows.
	 *
	 * The progress entry lives in the distributed cache, where a value of a
	 * few megabytes is refused without an error (memcached's default item
	 * limit is 1 MB). The stored copy keeps every count. Within the limit it
	 * keeps every row; over it, the rows that need attention: failed, then
	 * skipped, then rows with a warning, then the rest, each in processing
	 * order. `rowsStored` and `rowsTruncated` say how much it holds.
	 *
	 * @param int $maxRows The most rows to keep.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-7
	 */
	public function toStoredArray(int $maxRows): array {
		$stored = $this->toArray();
		$stored['rowsStored'] = count($this->rows);
		$stored['rowsTruncated'] = false;
		if (count($this->rows) <= $maxRows) {
			return $stored;
		}

		$rank = static function (array $row): int {
			if ($row['outcome'] === self::FAILED) {
				return 0;
			}

			if ($row['outcome'] === self::SKIPPED) {
				return 1;
			}

			if ($row['warnings'] !== []) {
				return 2;
			}

			return 3;
		};

		$ordered = [];
		foreach ($this->rows as $index => $row) {
			$ordered[] = [$rank($row), $index, $row];
		}

		usort($ordered, static fn (array $left, array $right): int => [$left[0], $left[1]] <=> [$right[0], $right[1]]);
		$kept = array_column(array_slice($ordered, 0, max(0, $maxRows)), 2);

		$stored['rows'] = $kept;
		$stored['rowsStored'] = count($kept);
		$stored['rowsTruncated'] = true;

		return $stored;
	}//end toStoredArray()
}//end class
