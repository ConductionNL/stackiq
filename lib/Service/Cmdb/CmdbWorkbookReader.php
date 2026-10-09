<?php

/**
 * CMDB workbook reader.
 *
 * Reads the CMDB sheets of a TOPdesk CMDB export (design D3):
 *
 * 1. Before PhpSpreadsheet is touched: the name ends in `.xlsx`, the file
 *    starts with the ZIP signature and the package holds `xl/workbook.xml`.
 *    Anything else is `NOT_XLSX` (400). Macro-enabled `.xlsm`, legacy `.xls`
 *    and CSV never reach the parser.
 * 2. PhpSpreadsheet's Xlsx reader, shipped by OpenRegister, in read-data-only
 *    mode, loading only the profile's sheets. It is checked with
 *    `class_exists`; without it the import answers `READER_UNAVAILABLE` (503).
 * 3. Row 1 holds the headers. Headers are matched by normalised name (trim,
 *    collapse whitespace, drop a trailing `:` or `⚡`, lower case) to the
 *    columns the profile and the packs reference. Every other column is never
 *    copied out of the reader, so personnel numbers, phone numbers and group
 *    mailboxes stay out of memory.
 * 4. A cell yields its stored value; a formula cell yields the value Excel
 *    cached (`getOldCalculatedValue()`). Formulas are never evaluated, and no
 *    HTTP client is involved, so external connections, Power Query packages
 *    and hyperlinks stay inert. A formula without a cached value yields an
 *    empty cell and its column is listed in the row's `uncached`, so the
 *    import can warn; a cached number 0 is what Excel stores for a reference
 *    to an empty cell, so it yields an empty cell too.
 * 5. Rows whose kept cells are all empty are dropped; more non-empty rows than
 *    the profile allows stops the import with `TOO_MANY_ROWS` (422).
 * 6. What PhpSpreadsheet can be made to hold is bounded before it parses
 *    anything. PhpSpreadsheet builds the whole shared-strings table, and the
 *    whole XML tree of every sheet it loads, before a read filter runs, so
 *    the filter alone does not bound memory. The reader therefore refuses
 *    with `WORKBOOK_TOO_LARGE` (413) a package that unpacks to more than the
 *    profile's `maxUncompressedBytes`, a part it may parse that unpacks to
 *    more than `maxPartBytes` (a larger sheet it does not load is blanked in
 *    a copy the reader reads instead, CmdbPartReferences), a shared-strings
 *    table with more entries than
 *    `maxSharedStrings` (each rich-text run counted as an entry, also in a
 *    cell's inline string), and cells that reference more shared-string
 *    text than `maxReferencedStringBytes` (CmdbWorkbookBounds, streamed
 *    without building the table). A source sheet whose last used row lies
 *    beyond twice the row limit is `TOO_MANY_ROWS`. A read filter then materialises only the
 *    header row and the resolved columns of the rows up to that bound
 *    (CmdbReadFilter), which bounds the cell objects, not the parse.
 * 7. The archive sheet ("Gearchiveerde Applicaties"), when the workbook has
 *    it, counts as a read sheet for every bound above, and only its key
 *    column is resolved and read (openspec/changes/cmdb-import-archive-reconciliation).
 *
 * @category  Service
 * @package   OCA\Stackiq\Service\Cmdb
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/cmdb-export-import/tasks.md#task-4
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service\Cmdb;

use OCA\Stackiq\Exception\CmdbImportException;
use Throwable;
use ZipArchive;

/**
 * Reads the allowlisted columns of the profile's source sheets.
 *
 * @spec openspec/changes/cmdb-export-import/tasks.md#task-4
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The file checks before parsing and the
 * header resolution are each a chain of small guards; together they pass the threshold.
 */
class CmdbWorkbookReader {
	/**
	 * PhpSpreadsheet's Xlsx reader, shipped in OpenRegister's vendor directory.
	 */
	public const READER_CLASS = 'PhpOffice\PhpSpreadsheet\Reader\Xlsx';

	/**
	 * What an oversized unread sheet becomes in the copy the reader reads.
	 */
	private const EMPTY_WORKSHEET = '<?xml version="1.0" encoding="UTF-8"?>'
		. '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData/></worksheet>';

	/**
	 * The ZIP local-file-header signature every xlsx package starts with.
	 */
	private const ZIP_SIGNATURE = "PK\x03\x04";

	/**
	 * Check that an upload is an xlsx workbook, without parsing it.
	 *
	 * @param string $path The uploaded temporary file.
	 * @param string $fileName The original file name.
	 *
	 * @return void
	 *
	 * @throws CmdbImportException NOT_XLSX when the name, signature or package is wrong.
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-4
	 */
	public function assertXlsx(string $path, string $fileName): void {
		if (strtolower((string)pathinfo($fileName, PATHINFO_EXTENSION)) !== 'xlsx') {
			throw new CmdbImportException(errorCode: CmdbImportException::NOT_XLSX, message: 'The file name does not end in .xlsx');
		}

		$head = false;
		if (is_file($path) === true && is_readable($path) === true) {
			$head = file_get_contents($path, false, null, 0, 4);
		}

		if ($head !== self::ZIP_SIGNATURE) {
			throw new CmdbImportException(errorCode: CmdbImportException::NOT_XLSX, message: 'The file is not a ZIP package');
		}

		$zip = new ZipArchive();
		if ($zip->open($path, ZipArchive::RDONLY) !== true) {
			throw new CmdbImportException(errorCode: CmdbImportException::NOT_XLSX, message: 'The ZIP package cannot be opened');
		}

		$hasWorkbook = ($zip->locateName('xl/workbook.xml') !== false);
		$zip->close();

		if ($hasWorkbook === false) {
			throw new CmdbImportException(errorCode: CmdbImportException::NOT_XLSX, message: 'The package holds no xl/workbook.xml');
		}
	}//end assertXlsx()

	/**
	 * Whether PhpSpreadsheet's Xlsx reader can be loaded.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-4
	 */
	public function isAvailable(): bool {
		return class_exists(static::READER_CLASS) === true;
	}//end isAvailable()

	/**
	 * Read the source sheets of an xlsx workbook.
	 *
	 * What the workbook can make PhpSpreadsheet hold is bounded before any
	 * part is parsed: the unpacked size of the package (the profile's
	 * `maxUncompressedBytes`) and of each part (`maxPartBytes`), the number of
	 * shared strings (`maxSharedStrings`), the shared-string text the cells
	 * reference (`maxReferencedStringBytes`), then the last used row of every
	 * source sheet.
	 * The sheets are then read twice through a read filter: once for the
	 * header row, once for the resolved columns of the data rows, so no other
	 * cell is ever materialised.
	 * The archive sheet, when the workbook has it, is read under the same
	 * bounds as a source sheet, for its key column only.
	 *
	 * @param string $path The xlsx file, already checked by assertXlsx().
	 * @param CmdbImportProfile $profile The import profile.
	 *
	 * @return array<string, mixed> `rows` (list of {sheet, row, cells, uncached}), `importWarnings`
	 *                              (list of {sheet, message}), `date1904` (bool) and `archive`
	 *                              ({sheet, present, keyColumnMissing, appIds}).
	 *
	 * @throws CmdbImportException WORKBOOK_TOO_LARGE, READER_UNAVAILABLE, NOT_XLSX, NO_SOURCE_SHEET,
	 *                             MISSING_COLUMN or TOO_MANY_ROWS.
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-4
	 * @spec openspec/changes/cmdb-import-archive-reconciliation/specs/cmdb-export-import/spec.md#requirement-an-application-missing-from-the-cmdb-sheets-shall-be-archived-when-the-archive-sheet-lists-it-and-soft-deleted-when-no-sheet-does-req-cmdb-015
	 */
	public function read(string $path, CmdbImportProfile $profile): array {
		$oversized = $this->assertUncompressedSize(path: $path, limit: $profile->maxUncompressedBytes(), partLimit: $profile->maxPartBytes());

		if ($this->isAvailable() === false) {
			throw new CmdbImportException(
				errorCode: CmdbImportException::READER_UNAVAILABLE,
				message: 'PhpSpreadsheet Xlsx reader is not available'
			);
		}

		$scanner = $this->newReader(sheetNames: null)->getSecurityScannerOrThrow();
		$blank = (new CmdbPartReferences())->assertPartSizes(
			path: $path,
			oversized: $oversized,
			sourceSheets: $profile->readSheetNames(),
			limit: $profile->maxPartBytes(),
			scanner: $scanner
		);
		if ($blank === []) {
			return $this->readBounded(path: $path, profile: $profile, scanner: $scanner);
		}

		$copy = self::blankedCopy(path: $path, parts: $blank);
		try {
			return $this->readBounded(path: $copy, profile: $profile, scanner: $scanner);
		} finally {
			self::removeCopy(path: $copy);
		}
	}//end read()

	/**
	 * A copy of the package in which each named part is an empty worksheet; the caller removes it.
	 *
	 * Every entry under one of the names is replaced, so PhpSpreadsheet can
	 * reach at most an empty sheet through it, whichever way it resolves a
	 * target to that name.
	 *
	 * @param string $path The xlsx file.
	 * @param array<int, string> $parts The names of the parts to blank.
	 *
	 * @return string The copy's path, ending in .xlsx.
	 *
	 * @throws CmdbImportException NOT_XLSX when the copy cannot be written.
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-4
	 */
	private static function blankedCopy(string $path, array $parts): string {
		$copy = self::temporaryCopy(path: $path);
		$zip = new ZipArchive();
		if ($zip->open($copy) !== true) {
			unlink($copy);
			throw new CmdbImportException(errorCode: CmdbImportException::NOT_XLSX, message: 'The ZIP package cannot be opened');
		}

		for ($index = ($zip->numFiles - 1); $index >= 0; $index--) {
			if (in_array((string)$zip->getNameIndex($index), $parts, true) === true) {
				$zip->deleteIndex($index);
			}
		}

		foreach (array_unique($parts) as $part) {
			$zip->addFromString($part, self::EMPTY_WORKSHEET);
		}

		if ($zip->close() === false) {
			unlink($copy);
			throw new CmdbImportException(errorCode: CmdbImportException::NOT_XLSX, message: 'The workbook cannot be copied for reading');
		}

		return $copy;
	}//end blankedCopy()

	/**
	 * A copy of the file in the temporary directory, removed at the end of the request at the latest.
	 *
	 * The copy holds the upload, so it is also removed when the request dies
	 * (memory or time limit) before read() removes it.
	 *
	 * @param string $path The xlsx file.
	 *
	 * @return string The copy's path, ending in .xlsx.
	 *
	 * @throws CmdbImportException NOT_XLSX when the copy cannot be written.
	 */
	private static function temporaryCopy(string $path): string {
		$base = tempnam(sys_get_temp_dir(), 'cmdb-read-');
		if ($base === false) {
			throw new CmdbImportException(errorCode: CmdbImportException::NOT_XLSX, message: 'The workbook cannot be copied for reading');
		}

		$copy = $base . '.xlsx';
		if (rename($base, $copy) === false) {
			unlink($base);
			throw new CmdbImportException(errorCode: CmdbImportException::NOT_XLSX, message: 'The workbook cannot be copied for reading');
		}

		register_shutdown_function(static function () use ($copy): void {
			self::removeCopy(path: $copy);
		});
		if (copy($path, $copy) === false) {
			unlink($copy);
			throw new CmdbImportException(errorCode: CmdbImportException::NOT_XLSX, message: 'The workbook cannot be copied for reading');
		}

		return $copy;
	}//end temporaryCopy()

	/**
	 * Remove a copy made by temporaryCopy(), if it is still there.
	 *
	 * Called by read() once the copy is read, and by the shutdown function
	 * when the request dies first; after read(), the latter finds nothing.
	 *
	 * @param string $path The copy's path.
	 *
	 * @return void
	 */
	private static function removeCopy(string $path): void {
		if (is_file($path) === true) {
			unlink($path);
		}
	}//end removeCopy()

	/**
	 * Read the source sheets of a package whose part sizes have been checked.
	 *
	 * @param string $path The xlsx file, or its copy with the oversized unread sheets blanked.
	 * @param CmdbImportProfile $profile The import profile.
	 * @param object $scanner PhpSpreadsheet's XmlScanner.
	 *
	 * @return array<string, mixed> See read().
	 *
	 * @throws CmdbImportException WORKBOOK_TOO_LARGE, NOT_XLSX, NO_SOURCE_SHEET, MISSING_COLUMN or TOO_MANY_ROWS.
	 */
	private function readBounded(string $path, CmdbImportProfile $profile, object $scanner): array {
		$bounds = new CmdbWorkbookBounds();
		$bounds->assertSharedStringCount(path: $path, limit: $profile->maxSharedStrings(), scanner: $scanner);
		$bounds->assertReferencedStringBytes(
			path: $path,
			limit: $profile->maxReferencedStringBytes(),
			sheetCount: count($profile->readSheetNames()),
			scanner: $scanner
		);

		$reader = $this->newReader(sheetNames: null);
		try {
			$available = $reader->listWorksheetNames($path);
		} catch (Throwable $e) {
			throw new CmdbImportException(
				errorCode: CmdbImportException::NOT_XLSX,
				message: 'The workbook cannot be read: ' . get_class($e),
				previous: $e
			);
		}

		$expected = $profile->sheetNames();
		$present = array_values(array_intersect($expected, $available));
		if ($present === []) {
			throw new CmdbImportException(
				errorCode: CmdbImportException::NO_SOURCE_SHEET,
				message: 'The workbook holds none of the source sheets',
				details: ['expected' => $expected]
			);
		}

		$archiveSheet = $profile->archiveSheetName();
		$loaded = $present;
		if ($archiveSheet !== null && in_array($archiveSheet, $available, true) === true) {
			$loaded[] = $archiveSheet;
		}

		$limit = $profile->maxRowsPerSheet();
		$lastRow = self::lastReadableRow(limit: $limit);
		$this->assertRowSpan(path: $path, sheetNames: $loaded, lastRow: $lastRow, limit: $limit);

		$headers = $this->load(path: $path, sheetNames: $loaded, filter: new CmdbReadFilter(lastRow: 1));
		try {
			$resolved = $this->resolveSheets(spreadsheet: $headers, sheetNames: $present, profile: $profile);
			$archiveColumns = [];
			if ($loaded !== $present) {
				// Only the key column of the archive sheet is read; the allowlist does not grow.
				$archiveColumns = $this->resolveColumns(worksheet: $headers->getSheetByName($archiveSheet), referenced: [$profile->keyColumn()]);
			}
		} finally {
			$headers->disconnectWorksheets();
		}

		$archive = ['sheet' => (string)$archiveSheet, 'present' => false, 'keyColumnMissing' => ($loaded !== $present), 'appIds' => []];
		$letters = array_map(static fn (array $columns): array => array_keys($columns), $resolved['columns']);
		if ($archiveColumns !== []) {
			$letters[$archiveSheet] = array_keys($archiveColumns);
			$archive['keyColumnMissing'] = false;
		} else {
			$loaded = $present;
		}

		$spreadsheet = $this->load(path: $path, sheetNames: $loaded, filter: new CmdbReadFilter(lastRow: $lastRow, columns: $letters));
		try {
			$rows = [];
			foreach ($present as $sheetName) {
				$sheetRows = $this->readRows(
					worksheet: $spreadsheet->getSheetByName($sheetName),
					columns: $resolved['columns'][$sheetName],
					sheetName: $sheetName,
					limit: $limit
				);
				array_push($rows, ...$sheetRows);
			}

			if ($archiveColumns !== []) {
				$archiveRows = $this->readRows(
					worksheet: $spreadsheet->getSheetByName($archiveSheet),
					columns: $archiveColumns,
					sheetName: (string)$archiveSheet,
					limit: $limit
				);
				$archive['present'] = true;
				$archive['appIds'] = array_column(array_column($archiveRows, 'cells'), $profile->keyColumn());
			}

			$date1904 = false;
			if (method_exists($spreadsheet, 'getExcelCalendar') === true) {
				$date1904 = ((int)$spreadsheet->getExcelCalendar() === 1904);
			}
		} finally {
			$spreadsheet->disconnectWorksheets();
		}

		return ['rows' => $rows, 'importWarnings' => $resolved['warnings'], 'date1904' => $date1904, 'archive' => $archive];
	}//end readBounded()

	/**
	 * The last row number the data pass reads.
	 *
	 * Twice the row limit plus the header row: a sheet may carry empty rows
	 * between its data rows (formatted rows, formulas whose cached value is 0),
	 * but not more of them than it has room for data rows.
	 *
	 * @param int $limit The profile's maximum number of non-empty rows.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-4
	 */
	public static function lastReadableRow(int $limit): int {
		return ((2 * max(0, $limit)) + 1);
	}//end lastReadableRow()

	/**
	 * Refuse a package whose parts together unpack to more than the limit, and list the parts beyond the part limit.
	 *
	 * The sizes are the uncompressed sizes the ZIP directory declares; libzip
	 * never inflates a part beyond its declared size. A part beyond the part
	 * limit is refused later, by CmdbPartReferences, unless only sheets the
	 * import does not read refer to it.
	 *
	 * @param string $path The xlsx file.
	 * @param int $limit The maximum number of unpacked bytes of all parts together.
	 * @param int $partLimit The maximum number of unpacked bytes of one part the import parses.
	 *
	 * @return array<string, int> Part name => unpacked size, for every part beyond the part limit.
	 *
	 * @throws CmdbImportException NOT_XLSX when the package cannot be opened, WORKBOOK_TOO_LARGE above the limit.
	 */
	private function assertUncompressedSize(string $path, int $limit, int $partLimit): array {
		$zip = new ZipArchive();
		if ($zip->open($path, ZipArchive::RDONLY) !== true) {
			throw new CmdbImportException(errorCode: CmdbImportException::NOT_XLSX, message: 'The ZIP package cannot be opened');
		}

		$total = 0;
		$readable = true;
		$oversized = [];
		for ($index = 0; $index < $zip->numFiles && $total <= $limit; $index++) {
			$stat = $zip->statIndex($index);
			if ($stat === false) {
				$readable = false;
				break;
			}

			if ((int)$stat['size'] > $partLimit) {
				$oversized[(string)$stat['name']] = (int)$stat['size'];
			}

			$total += (int)$stat['size'];
		}

		$zip->close();

		if ($readable === false) {
			throw new CmdbImportException(errorCode: CmdbImportException::NOT_XLSX, message: 'A part of the ZIP package cannot be read');
		}

		if ($total > $limit) {
			throw new CmdbImportException(
				errorCode: CmdbImportException::WORKBOOK_TOO_LARGE,
				message: 'The workbook unpacks to more bytes than the profile allows',
				details: ['maxUncompressedBytes' => $limit]
			);
		}

		return $oversized;
	}//end assertUncompressedSize()

	/**
	 * Refuse a source sheet whose last used row lies beyond the rows that are read.
	 *
	 * PhpSpreadsheet's worksheet info streams the sheet and counts no cell
	 * objects, so this runs before a sheet is loaded.
	 *
	 * @param string $path The xlsx file.
	 * @param array<int, string> $sheetNames The present source sheets.
	 * @param int $lastRow The last row number that is read.
	 * @param int $limit The profile's maximum number of non-empty rows, for the details.
	 *
	 * @return void
	 *
	 * @throws CmdbImportException NOT_XLSX when the sheets cannot be listed, TOO_MANY_ROWS beyond the last row.
	 */
	private function assertRowSpan(string $path, array $sheetNames, int $lastRow, int $limit): void {
		try {
			$info = $this->newReader(sheetNames: null)->listWorksheetInfo($path);
		} catch (Throwable $e) {
			throw new CmdbImportException(
				errorCode: CmdbImportException::NOT_XLSX,
				message: 'The workbook cannot be read: ' . get_class($e),
				previous: $e
			);
		}

		foreach ($info as $sheet) {
			$name = (string)($sheet['worksheetName'] ?? '');
			if (in_array($name, $sheetNames, true) === true && (int)($sheet['totalRows'] ?? 0) > $lastRow) {
				throw new CmdbImportException(
					errorCode: CmdbImportException::TOO_MANY_ROWS,
					message: 'A source sheet has more rows than the profile allows',
					details: ['sheet' => $name, 'limit' => $limit]
				);
			}
		}
	}//end assertRowSpan()

	/**
	 * A PhpSpreadsheet Xlsx reader in read-data-only mode.
	 *
	 * @param array<int, string>|null $sheetNames The sheets to load, or null for none set.
	 *
	 * @return object
	 */
	private function newReader(?array $sheetNames): object {
		$readerClass = static::READER_CLASS;
		$reader = new $readerClass();
		$reader->setReadDataOnly(true);
		$reader->setReadEmptyCells(false);
		if ($sheetNames !== null) {
			$reader->setLoadSheetsOnly($sheetNames);
		}

		return $reader;
	}//end newReader()

	/**
	 * Load the source sheets through a read filter.
	 *
	 * @param string $path The xlsx file.
	 * @param array<int, string> $sheetNames The present source sheets.
	 * @param CmdbReadFilter $filter Which cells are read.
	 *
	 * @return object The PhpSpreadsheet workbook.
	 *
	 * @throws CmdbImportException NOT_XLSX when the workbook cannot be loaded.
	 */
	private function load(string $path, array $sheetNames, CmdbReadFilter $filter): object {
		$reader = $this->newReader(sheetNames: $sheetNames);
		$reader->setReadFilter($filter);

		try {
			return $reader->load($path);
		} catch (Throwable $e) {
			throw new CmdbImportException(
				errorCode: CmdbImportException::NOT_XLSX,
				message: 'The workbook cannot be loaded: ' . get_class($e),
				previous: $e
			);
		}
	}//end load()

	/**
	 * Normalise a header or column name for matching.
	 *
	 * @param string $header The raw header.
	 *
	 * @return string The normalised name.
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-4
	 */
	public static function normaliseHeader(string $header): string {
		$header = (string)preg_replace('/\s+/u', ' ', trim($header));
		$header = (string)preg_replace('/\s*(?::|⚡)+$/u', '', $header);

		return mb_strtolower(trim($header));
	}//end normaliseHeader()

	/**
	 * Resolve the columns of every present source sheet from its header row.
	 *
	 * Every sheet is resolved before a single data row is read, so a missing
	 * required column stops the import first.
	 *
	 * @param object $spreadsheet The workbook, loaded with the header row only.
	 * @param array<int, string> $sheetNames The present source sheets, in profile order.
	 * @param CmdbImportProfile $profile The import profile.
	 *
	 * @return array{columns: array<string, array<string, string>>, warnings: array<int, array{sheet: string, column: string, message: string}>}
	 *         Per sheet, column letter => referenced column name; and the import warnings.
	 *
	 * @throws CmdbImportException MISSING_COLUMN.
	 */
	private function resolveSheets(object $spreadsheet, array $sheetNames, CmdbImportProfile $profile): array {
		$referenced = $profile->referencedColumns();
		$mapped = $this->packSources(profile: $profile);
		$required = $profile->requiredColumns();

		$columnsPerSheet = [];
		$warnings = [];
		foreach ($sheetNames as $sheetName) {
			$worksheet = $spreadsheet->getSheetByName($sheetName);
			$columns = $this->resolveColumns(worksheet: $worksheet, referenced: $referenced);

			foreach ($required as $column) {
				if (in_array($column, $columns, true) === false) {
					throw new CmdbImportException(
						errorCode: CmdbImportException::MISSING_COLUMN,
						message: 'A source sheet lacks a required column',
						details: ['sheet' => $sheetName, 'column' => $column]
					);
				}
			}

			$skip = array_merge($required, $profile->absentColumns(sheetName: $sheetName));
			array_push($warnings, ...self::missingOptionalColumns(sheetName: $sheetName, mapped: $mapped, columns: $columns, skip: $skip));
			$columnsPerSheet[$sheetName] = $columns;
		}

		return ['columns' => $columnsPerSheet, 'warnings' => $warnings];
	}//end resolveSheets()

	/**
	 * Map the header row to the referenced column names.
	 *
	 * @param object $worksheet The worksheet.
	 * @param array<int, string> $referenced The allowlisted column names.
	 *
	 * @return array<string, string> Column letter => referenced column name.
	 */
	private function resolveColumns(object $worksheet, array $referenced): array {
		$wanted = [];
		foreach ($referenced as $column) {
			$wanted[self::normaliseHeader(header: $column)] = $column;
		}

		$columns = [];
		$lastColumn = self::columnIndex(letters: (string)$worksheet->getHighestDataColumn(1));
		for ($index = 1; $index <= $lastColumn; $index++) {
			$letters = self::columnLetters(index: $index);
			$coordinate = $letters . '1';
			if ($worksheet->cellExists($coordinate) === false) {
				continue;
			}

			$header = $this->cellValue(cell: $worksheet->getCell($coordinate));
			if (is_scalar($header) === false) {
				continue;
			}

			$name = $wanted[self::normaliseHeader(header: (string)$header)] ?? null;
			// The first column with a matching header wins.
			if ($name !== null && in_array($name, $columns, true) === false) {
				$columns[$letters] = $name;
			}
		}

		return $columns;
	}//end resolveColumns()

	/**
	 * Read the non-empty data rows of one sheet.
	 *
	 * @param object $worksheet The worksheet.
	 * @param array<string, string> $columns Column letter => column name.
	 * @param string $sheetName The sheet name.
	 * @param int $limit The maximum number of non-empty rows.
	 *
	 * @return array<int, array{sheet: string, row: int, cells: array<string, mixed>, uncached: array<int, string>}>
	 *
	 * @throws CmdbImportException TOO_MANY_ROWS.
	 */
	private function readRows(object $worksheet, array $columns, string $sheetName, int $limit): array {
		$rows = [];
		$lastRow = (int)$worksheet->getHighestDataRow();
		for ($rowNumber = 2; $rowNumber <= $lastRow; $rowNumber++) {
			['cells' => $cells, 'uncached' => $uncached, 'empty' => $empty] = $this->readRow(
				worksheet: $worksheet,
				columns: $columns,
				rowNumber: $rowNumber
			);
			if ($empty === true) {
				continue;
			}

			if (count($rows) >= $limit) {
				throw new CmdbImportException(
					errorCode: CmdbImportException::TOO_MANY_ROWS,
					message: 'A source sheet has more rows than the profile allows',
					details: ['sheet' => $sheetName, 'limit' => $limit]
				);
			}

			$rows[] = ['sheet' => $sheetName, 'row' => $rowNumber, 'cells' => $cells, 'uncached' => $uncached];
		}//end for

		return $rows;
	}//end readRows()

	/**
	 * The kept cells of one row, the columns whose formula has no cached value,
	 * and whether every kept cell is empty.
	 *
	 * @param object $worksheet The worksheet.
	 * @param array<string, string> $columns Column letter => column name.
	 * @param int $rowNumber The 1-based row number.
	 *
	 * @return array{cells: array<string, mixed>, uncached: array<int, string>, empty: bool}
	 */
	private function readRow(object $worksheet, array $columns, int $rowNumber): array {
		$cells = [];
		$uncached = [];
		$empty = true;
		foreach ($columns as $letters => $name) {
			$value = null;
			$coordinate = $letters . $rowNumber;
			if ($worksheet->cellExists($coordinate) === true) {
				$cell = $worksheet->getCell($coordinate);
				$value = $this->cellValue(cell: $cell);
				if (self::isUncachedFormula(cell: $cell) === true) {
					$uncached[] = $name;
				}
			}

			$cells[$name] = $value;
			if ($value !== null && (is_string($value) === false || trim($value) !== '')) {
				$empty = false;
			}
		}

		return ['cells' => $cells, 'uncached' => $uncached, 'empty' => $empty];
	}//end readRow()

	/**
	 * One import warning per pack column a sheet lacks, except the ones to skip.
	 *
	 * @param string $sheetName The sheet name.
	 * @param array<int, string> $mapped The sheet-mapped pack sources.
	 * @param array<string, string> $columns The sheet's resolved columns.
	 * @param array<int, string> $skip Required columns and the columns the sheet is known to lack.
	 *
	 * @return array<int, array{sheet: string, column: string, message: string}>
	 */
	private static function missingOptionalColumns(string $sheetName, array $mapped, array $columns, array $skip): array {
		$warnings = [];
		foreach ($mapped as $column) {
			if (in_array($column, $columns, true) === false && in_array($column, $skip, true) === false) {
				$warnings[] = ['sheet' => $sheetName, 'column' => $column, 'message' => sprintf('Optional column "%s" not found', $column)];
			}
		}

		return $warnings;
	}//end missingOptionalColumns()

	/**
	 * Whether a cell holds a formula without a cached value.
	 *
	 * @param object $cell The PhpSpreadsheet cell.
	 *
	 * @return bool
	 */
	private static function isUncachedFormula(object $cell): bool {
		return $cell->getDataType() === 'f' && $cell->getOldCalculatedValue() === null;
	}//end isUncachedFormula()

	/**
	 * The stored value of a cell; for a formula, the value Excel cached.
	 *
	 * A formula without a cached value, and a formula whose cached value is
	 * the number 0 (Excel's result for a reference to an empty cell), yield
	 * null.
	 *
	 * @param object $cell The PhpSpreadsheet cell.
	 *
	 * @return mixed A scalar or null.
	 */
	private function cellValue(object $cell): mixed {
		$value = $cell->getValue();
		if ($cell->getDataType() === 'f') {
			// The value Excel cached; the formula itself is never evaluated.
			$value = $cell->getOldCalculatedValue();
			if ((is_int($value) === true || is_float($value) === true) && (float)$value === 0.0) {
				return null;
			}
		}

		if (is_object($value) === true && method_exists($value, 'getPlainText') === true) {
			return (string)$value->getPlainText();
		}

		if (is_scalar($value) === false) {
			return null;
		}

		return $value;
	}//end cellValue()

	/**
	 * The sources of every sheet-mapped pack field.
	 *
	 * @param CmdbImportProfile $profile The import profile.
	 *
	 * @return array<int, string>
	 */
	private function packSources(CmdbImportProfile $profile): array {
		$constants = $profile->constantColumns();
		$sources = [];
		foreach (CmdbImportProfile::TARGETS as $target) {
			if ($target === 'municipality') {
				continue;
			}

			foreach (($profile->pack(target: $target)['fieldMappings'] ?? []) as $mapping) {
				$sources[] = (string)($mapping['source'] ?? '');
			}
		}

		return array_values(
			array_unique(
				array_filter(
					$sources,
					fn (string $source): bool => $source !== '' && in_array($source, $constants, true) === false
				)
			)
		);
	}//end packSources()

	/**
	 * Column letters to a 1-based index ("A" = 1, "AA" = 27).
	 *
	 * @param string $letters The column letters.
	 *
	 * @return int
	 */
	private static function columnIndex(string $letters): int {
		$index = 0;
		foreach (str_split(strtoupper($letters)) as $char) {
			$index = (($index * 26) + (ord($char) - 64));
		}

		return $index;
	}//end columnIndex()

	/**
	 * A 1-based column index to its letters.
	 *
	 * @param int $index The column index.
	 *
	 * @return string
	 */
	private static function columnLetters(int $index): string {
		$letters = '';
		while ($index > 0) {
			$remainder = (($index - 1) % 26);
			$letters = chr(65 + $remainder) . $letters;
			$index = intdiv(($index - 1), 26);
		}

		return $letters;
	}//end columnLetters()
}//end class
