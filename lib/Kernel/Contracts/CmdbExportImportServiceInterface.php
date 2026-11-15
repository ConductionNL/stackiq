<?php

/**
 * Contract of CmdbExportImportService
 *
 * The public surface of CmdbExportImportService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Stackiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/method-decomposition/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Kernel\Contracts;

use OCA\Stackiq\Exception\CmdbImportException;

/**
 * Contract of CmdbExportImportService.
 *
 * @category Kernel
 * @package  OCA\Stackiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/stackiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CmdbExportImportServiceInterface {

	/**
	 * What happens to records missing from the export when the request does not say.
	 */
	public const DEFAULT_MISSING_RECORDS = 'archive';

	/**
	 * Check that an upload is an xlsx workbook, without parsing it.
	 *
	 * @param string $path The uploaded temporary file.
	 * @param string $fileName The original file name.
	 *
	 * @return void
	 *
	 * @throws CmdbImportException NOT_XLSX.
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-8
	 */
	public function assertXlsx(string $path, string $fileName): void;

	/**
	 * Import an export for one municipality.
	 *
	 * Validation that can fail the whole import runs before any object is
	 * written: the packs and the engine, the configuration, the workbook and
	 * the municipality uuid. After that every row is processed in its own
	 * error boundary. The import holds an exclusive lock on its register from
	 * before the file is read until it returns or throws.
	 *
	 * @param string $path The xlsx file, already checked by assertXlsx().
	 * @param array<string, mixed> $options municipalityUuid, municipalityName, updateExisting, publish,
	 *                                      missingRecords (keep or archive, default archive), operationId,
	 *                                      and fileName (the upload's name, for the audit log line).
	 *
	 * @return array<string, mixed> The report (contract.md).
	 *
	 * @throws CmdbImportException MAPPING_UNAVAILABLE, NOT_CONFIGURED, SCHEMA_OUTDATED, IMPORT_IN_PROGRESS,
	 *                             WORKBOOK_TOO_LARGE, READER_UNAVAILABLE, NOT_XLSX, NO_SOURCE_SHEET,
	 *                             MISSING_COLUMN, TOO_MANY_ROWS, MUNICIPALITY_REQUIRED,
	 *                             MUNICIPALITY_INVALID, MUNICIPALITY_AMBIGUOUS or ARCHIVE_UNAVAILABLE.
	 * @throws \Exception         An unexpected OpenRegister error outside a row, such as
	 *                             creating the municipality; rows catch their own.
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-5
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-7
	 * @spec openspec/changes/cmdb-import-archive-reconciliation/specs/cmdb-export-import/spec.md#requirement-an-application-missing-from-the-cmdb-sheets-shall-be-archived-when-the-archive-sheet-lists-it-and-soft-deleted-when-no-sheet-does-req-cmdb-015
	 */
	public function import(string $path, array $options): array;

	/**
	 * The upload limit in bytes (the profile's `maxFileBytes`).
	 *
	 * @return int
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-8
	 */
	public function maxFileBytes(): int;

	/**
	 * The accepted `missingRecords` values: the profile's modes (`keep`, `archive`); any other is refused.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-8
	 * @spec openspec/changes/cmdb-import-archive-reconciliation/specs/cmdb-export-import/spec.md#requirement-records-missing-from-a-newer-export-shall-be-archived-by-default-or-kept-on-request-req-cmdb-012
	 */
	public function missingRecordsModes(): array;

	/**
	 * Ask a running import to stop between rows.
	 *
	 * @param string $operationId The operation id.
	 *
	 * @return bool False when no running `cmdb_import` operation has this id.
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-7
	 */
	public function requestCancel(string $operationId): bool;
}//end interface
