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
	 * @param string $path The xlsx file, already checked by assertXlsx().
	 * @param CmdbImportProfile $profile The import profile.
	 *
	 * @return array<string, mixed> `rows` (list of {sheet, row, cells, uncached}), `importWarnings`
	 *                              (list of {sheet, message}) and `date1904` (bool).
	 *
	 * @throws CmdbImportException READER_UNAVAILABLE, NOT_XLSX, NO_SOURCE_SHEET, MISSING_COLUMN or TOO_MANY_ROWS.
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-4
	 */
	public function read(string $path, CmdbImportProfile $profile): array {
		if ($this->isAvailable() === false) {
			throw new CmdbImportException(
				errorCode: CmdbImportException::READER_UNAVAILABLE,
				message: 'PhpSpreadsheet Xlsx reader is not available'
			);
		}

		$readerClass = static::READER_CLASS;
		$reader = new $readerClass();

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

		$reader->setReadDataOnly(true);
		$reader->setReadEmptyCells(false);
		$reader->setLoadSheetsOnly($present);

		try {
			$spreadsheet = $reader->load($path);
		} catch (Throwable $e) {
			throw new CmdbImportException(
				errorCode: CmdbImportException::NOT_XLSX,
				message: 'The workbook cannot be loaded: ' . get_class($e),
				previous: $e
			);
		}

		try {
			$result = $this->readSheets(spreadsheet: $spreadsheet, sheetNames: $present, profile: $profile);
		} finally {
			$spreadsheet->disconnectWorksheets();
		}

		return $result;
	}//end read()

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
	 * Read every present source sheet.
	 *
	 * @param object $spreadsheet The loaded PhpSpreadsheet workbook.
	 * @param array<int, string> $sheetNames The present source sheets, in profile order.
	 * @param CmdbImportProfile $profile The import profile.
	 *
	 * @return array<string, mixed> `rows` (list of {sheet, row, cells, uncached}), `importWarnings`
	 *                              (list of {sheet, message}) and `date1904` (bool).
	 *
	 * @throws CmdbImportException MISSING_COLUMN or TOO_MANY_ROWS.
	 */
	private function readSheets(object $spreadsheet, array $sheetNames, CmdbImportProfile $profile): array {
		$referenced = $profile->referencedColumns();
		$mapped = $this->packSources(profile: $profile);
		$required = $profile->requiredColumns();

		// Resolve every sheet's columns first, so a missing required column
		// stops the import before a single row is read.
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

		$rows = [];
		$limit = $profile->maxRowsPerSheet();
		foreach ($sheetNames as $sheetName) {
			$worksheet = $spreadsheet->getSheetByName($sheetName);
			$sheetRows = $this->readRows(worksheet: $worksheet, columns: $columnsPerSheet[$sheetName], sheetName: $sheetName, limit: $limit);
			array_push($rows, ...$sheetRows);
		}

		$date1904 = false;
		if (method_exists($spreadsheet, 'getExcelCalendar') === true) {
			$date1904 = ((int)$spreadsheet->getExcelCalendar() === 1904);
		}

		return ['rows' => $rows, 'importWarnings' => $warnings, 'date1904' => $date1904];
	}//end readSheets()

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
