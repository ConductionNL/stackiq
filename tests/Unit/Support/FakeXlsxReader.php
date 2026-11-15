<?php

/**
 * An in-memory stand-in for PhpSpreadsheet's Xlsx reader, its workbook, worksheets and cells.
 *
 * Lets a reader test drive CmdbWorkbookReader's sheet, column and row logic
 * without PhpSpreadsheet installed: a test subclass of the reader points
 * READER_CLASS at FakeXlsxReader, and the sheets it "loads" are the arrays in
 * FakeXlsxReader::$sheets. The xlsx file itself is still opened by the
 * reader's own ZIP bounds, so a test passes a real (small) package.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Support
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

namespace OCA\Stackiq\Tests\Unit\Support;

use RuntimeException;

/**
 * The reader: lists and "loads" the sheets in self::$sheets through the read filter it is given.
 *
 * A sheet is `row number => column letters => cell`, where a cell is a scalar
 * (a stored value), an object (a rich-text-like value) or `['f' => cached]`
 * (a formula whose cached value is `cached`, null for none).
 */
class FakeXlsxReader {
	/**
	 * Sheet name => rows, in workbook order.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	public static array $sheets = [];

	/**
	 * Sheet name => totalRows reported by listWorksheetInfo(); a sheet not listed reports its highest row.
	 *
	 * @var array<string, int>
	 */
	public static array $totalRows = [];

	/**
	 * When set, listWorksheetNames() throws it.
	 *
	 * @var RuntimeException|null
	 */
	public static ?RuntimeException $listFailure = null;

	/**
	 * When set, listWorksheetInfo() throws it.
	 *
	 * @var RuntimeException|null
	 */
	public static ?RuntimeException $infoFailure = null;

	/**
	 * When set, load() throws it.
	 *
	 * @var RuntimeException|null
	 */
	public static ?RuntimeException $loadFailure = null;

	/**
	 * The calendar the loaded workbook reports, or null for a workbook without getExcelCalendar().
	 *
	 * @var int|null
	 */
	public static ?int $calendar = 1900;

	/**
	 * Every load since the last reset: {path, exists, sheets, readDataOnly, readEmptyCells}.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public static array $loads = [];

	/**
	 * The sheets to load, or null for all.
	 *
	 * @var array<int, string>|null
	 */
	private ?array $loadSheetsOnly = null;

	/**
	 * The read filter of the next load.
	 *
	 * @var object|null
	 */
	private ?object $readFilter = null;

	/**
	 * Read data only.
	 *
	 * @var bool|null
	 */
	private ?bool $readDataOnly = null;

	/**
	 * Read empty cells.
	 *
	 * @var bool|null
	 */
	private ?bool $readEmptyCells = null;

	/**
	 * Restore the defaults.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$sheets = [];
		self::$totalRows = [];
		self::$listFailure = null;
		self::$infoFailure = null;
		self::$loadFailure = null;
		self::$calendar = 1900;
		self::$loads = [];
	}//end reset()

	/**
	 * Read data only.
	 *
	 * @param bool $value The flag.
	 *
	 * @return void
	 */
	public function setReadDataOnly(bool $value): void {
		$this->readDataOnly = $value;
	}//end setReadDataOnly()

	/**
	 * Read empty cells.
	 *
	 * @param bool $value The flag.
	 *
	 * @return void
	 */
	public function setReadEmptyCells(bool $value): void {
		$this->readEmptyCells = $value;
	}//end setReadEmptyCells()

	/**
	 * Load only these sheets.
	 *
	 * @param array<int, string> $sheetNames The sheets.
	 *
	 * @return void
	 */
	public function setLoadSheetsOnly(array $sheetNames): void {
		$this->loadSheetsOnly = $sheetNames;
	}//end setLoadSheetsOnly()

	/**
	 * The read filter.
	 *
	 * @param object $filter The filter.
	 *
	 * @return void
	 */
	public function setReadFilter(object $filter): void {
		$this->readFilter = $filter;
	}//end setReadFilter()

	/**
	 * A scanner that passes every part through unchanged.
	 *
	 * @return object
	 */
	public function getSecurityScannerOrThrow(): object {
		return new class {
			/**
			 * The part, unchanged.
			 *
			 * @param string $xml The part.
			 *
			 * @return string
			 */
			public function scan(string $xml): string {
				return $xml;
			}//end scan()
		};
	}//end getSecurityScannerOrThrow()

	/**
	 * The sheet names.
	 *
	 * @param string $path The file.
	 *
	 * @return array<int, string>
	 */
	public function listWorksheetNames(string $path): array {
		if (self::$listFailure !== null) {
			throw self::$listFailure;
		}

		return array_keys(self::$sheets);
	}//end listWorksheetNames()

	/**
	 * The worksheet info: name and total rows.
	 *
	 * @param string $path The file.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function listWorksheetInfo(string $path): array {
		if (self::$infoFailure !== null) {
			throw self::$infoFailure;
		}

		$info = [];
		foreach (self::$sheets as $name => $rows) {
			$highest = 0;
			if ($rows !== []) {
				$highest = max(array_keys($rows));
			}

			$info[] = ['worksheetName' => $name, 'totalRows' => (self::$totalRows[$name] ?? $highest)];
		}

		return $info;
	}//end listWorksheetInfo()

	/**
	 * Load the selected sheets, keeping only the cells the read filter admits.
	 *
	 * @param string $path The file.
	 *
	 * @return FakeSpreadsheet
	 */
	public function load(string $path): FakeSpreadsheet {
		self::$loads[] = [
			'path' => $path,
			'exists' => is_file($path),
			'sheets' => $this->loadSheetsOnly,
			'readDataOnly' => $this->readDataOnly,
			'readEmptyCells' => $this->readEmptyCells,
		];
		if (self::$loadFailure !== null) {
			throw self::$loadFailure;
		}

		$worksheets = [];
		foreach (self::$sheets as $name => $rows) {
			if ($this->loadSheetsOnly !== null && in_array($name, $this->loadSheetsOnly, true) === false) {
				continue;
			}

			$kept = [];
			foreach ($rows as $rowNumber => $cells) {
				foreach ($cells as $letters => $cell) {
					if ($this->readFilter === null || $this->readFilter->readCell($letters, $rowNumber, $name) === true) {
						$kept[$rowNumber][$letters] = $cell;
					}
				}
			}

			$worksheets[$name] = new FakeWorksheet(rows: $kept);
		}

		return new FakeSpreadsheet(worksheets: $worksheets, calendar: self::$calendar);
	}//end load()
}//end class

/**
 * A loaded workbook.
 */
class FakeSpreadsheet {
	/**
	 * Whether disconnectWorksheets() was called.
	 *
	 * @var bool
	 */
	public bool $disconnected = false;

	/**
	 * Constructor.
	 *
	 * @param array<string, FakeWorksheet> $worksheets Sheet name => worksheet.
	 * @param int|null $calendar The calendar it reports.
	 */
	public function __construct(
		private readonly array $worksheets,
		private readonly ?int $calendar,
	) {
	}//end __construct()

	/**
	 * A worksheet by name.
	 *
	 * @param string $name The sheet name.
	 *
	 * @return FakeWorksheet|null
	 */
	public function getSheetByName(string $name): ?FakeWorksheet {
		return $this->worksheets[$name] ?? null;
	}//end getSheetByName()

	/**
	 * Release the worksheets.
	 *
	 * @return void
	 */
	public function disconnectWorksheets(): void {
		$this->disconnected = true;
	}//end disconnectWorksheets()

	/**
	 * The date system.
	 *
	 * @return int
	 */
	public function getExcelCalendar(): int {
		return (int)$this->calendar;
	}//end getExcelCalendar()
}//end class

/**
 * A loaded worksheet.
 */
class FakeWorksheet {
	/**
	 * Constructor.
	 *
	 * @param array<int, array<string, mixed>> $rows Row number => column letters => cell.
	 */
	public function __construct(private readonly array $rows) {
	}//end __construct()

	/**
	 * The highest column letters holding data in a row.
	 *
	 * @param int $row The row.
	 *
	 * @return string
	 */
	public function getHighestDataColumn(int $row): string {
		$highest = 'A';
		foreach (array_keys($this->rows[$row] ?? []) as $letters) {
			if (strlen($letters) > strlen($highest) || (strlen($letters) === strlen($highest) && strcmp($letters, $highest) > 0)) {
				$highest = $letters;
			}
		}

		return $highest;
	}//end getHighestDataColumn()

	/**
	 * The highest row holding data.
	 *
	 * @return int
	 */
	public function getHighestDataRow(): int {
		if ($this->rows === []) {
			return 1;
		}

		return max(array_keys($this->rows));
	}//end getHighestDataRow()

	/**
	 * Whether a cell exists.
	 *
	 * @param string $coordinate Like "B2".
	 *
	 * @return bool
	 */
	public function cellExists(string $coordinate): bool {
		[$letters, $row] = self::split(coordinate: $coordinate);
		return array_key_exists($letters, ($this->rows[$row] ?? []));
	}//end cellExists()

	/**
	 * A cell.
	 *
	 * @param string $coordinate Like "B2".
	 *
	 * @return FakeCell
	 */
	public function getCell(string $coordinate): FakeCell {
		[$letters, $row] = self::split(coordinate: $coordinate);
		return new FakeCell(spec: ($this->rows[$row][$letters] ?? null));
	}//end getCell()

	/**
	 * Column letters and row number of a coordinate.
	 *
	 * @param string $coordinate Like "B2".
	 *
	 * @return array{0: string, 1: int}
	 */
	private static function split(string $coordinate): array {
		preg_match('/^([A-Z]+)(\d+)$/', $coordinate, $match);
		return [$match[1], (int)$match[2]];
	}//end split()
}//end class

/**
 * A cell: a stored value, or a formula with its cached value.
 */
class FakeCell {
	/**
	 * Constructor.
	 *
	 * @param mixed $spec A value, or `['f' => cached]` for a formula.
	 */
	public function __construct(private readonly mixed $spec) {
	}//end __construct()

	/**
	 * Whether the cell is a formula.
	 *
	 * @return bool
	 */
	private function isFormula(): bool {
		return is_array($this->spec) === true && array_key_exists('f', $this->spec) === true;
	}//end isFormula()

	/**
	 * "f" for a formula, "s" otherwise.
	 *
	 * @return string
	 */
	public function getDataType(): string {
		if ($this->isFormula() === true) {
			return 'f';
		}

		return 's';
	}//end getDataType()

	/**
	 * The stored value; for a formula, its text.
	 *
	 * @return mixed
	 */
	public function getValue(): mixed {
		if ($this->isFormula() === true) {
			return '=FORMULA()';
		}

		return $this->spec;
	}//end getValue()

	/**
	 * The cached value of a formula.
	 *
	 * @return mixed
	 */
	public function getOldCalculatedValue(): mixed {
		if ($this->isFormula() === true) {
			return $this->spec['f'];
		}

		return null;
	}//end getOldCalculatedValue()
}//end class
