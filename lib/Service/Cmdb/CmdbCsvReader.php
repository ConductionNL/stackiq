<?php

/**
 * CSV reader for a profile-driven import.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Reads a CSV file as the one sheet a profile names in `csvSheet`, and answers
 * in the shape CmdbWorkbookReader::read() does, so a consumer reads an xlsx
 * workbook and a CSV file alike:
 *
 * 1. Before anything is parsed: the name ends in `.csv` and the first bytes
 *    hold no NUL byte (a binary file renamed). Anything else is `NOT_CSV`.
 * 2. A UTF-8 byte order mark is dropped; text that is not UTF-8 is read as
 *    Windows-1252, the encoding spreadsheet programs write on Windows.
 * 3. The delimiter is the one of `;`, `,` and tab that occurs most in the
 *    header line outside quotes; `;` wins a tie (the Dutch Excel default).
 * 4. Row 1 holds the headers, matched like the workbook reader matches them
 *    (CmdbWorkbookReader::normaliseHeader). Only the columns the profile and
 *    its packs reference are kept; every required column must be there, or
 *    the read stops with `MISSING_COLUMN` naming every missing one.
 * 5. Rows whose kept cells are all empty are dropped; more non-empty rows than
 *    the profile allows stops the read with `TOO_MANY_ROWS`.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service\Cmdb
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/landscape-migrate-from-previous-catalogue/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service\Cmdb;

use OCA\Stackiq\Exception\CmdbImportException;

/**
 * Reads the allowlisted columns of a CSV file as one sheet of a profile.
 *
 * @spec openspec/changes/landscape-migrate-from-previous-catalogue/tasks.md#task-1
 */
class CmdbCsvReader {
	/**
	 * The delimiters the reader recognises, the preferred one first.
	 *
	 * @var array<int, string>
	 */
	private const DELIMITERS = [';', ',', "\t"];

	/**
	 * How many leading bytes are checked for a NUL byte.
	 */
	private const SNIFF_BYTES = 4096;

	/**
	 * Check that an upload is a CSV text file, without parsing it.
	 *
	 * @param string $path The uploaded temporary file.
	 * @param string $fileName The original file name.
	 *
	 * @return void
	 *
	 * @throws CmdbImportException NOT_CSV when the name or the content is not that of a CSV file.
	 *
	 * @spec openspec/changes/landscape-migrate-from-previous-catalogue/tasks.md#task-1
	 */
	public function assertCsv(string $path, string $fileName): void {
		if (strtolower((string)pathinfo($fileName, PATHINFO_EXTENSION)) !== 'csv') {
			throw new CmdbImportException(errorCode: CmdbImportException::NOT_CSV, message: 'The file name does not end in .csv');
		}

		$head = false;
		if (is_file($path) === true && is_readable($path) === true) {
			$head = file_get_contents($path, false, null, 0, self::SNIFF_BYTES);
		}

		if ($head === false || str_contains($head, "\0") === true) {
			throw new CmdbImportException(errorCode: CmdbImportException::NOT_CSV, message: 'The file is not a text file');
		}
	}//end assertCsv()

	/**
	 * Read a CSV file as the profile's CSV sheet.
	 *
	 * @param string $path The CSV file, already checked by assertCsv().
	 * @param CmdbImportProfile $profile The import profile.
	 *
	 * @return array<string, mixed> As CmdbWorkbookReader::read(): `rows` (list of {sheet, row, cells, uncached}),
	 *                              `importWarnings`, `date1904` (always false), `archive` (never present),
	 *                              `uncachedKeyRows` (empty) and `absentSheets` (empty).
	 *
	 * @throws CmdbImportException MAPPING_UNAVAILABLE when the profile reads no CSV, NOT_CSV when the
	 *                             file cannot be read, MISSING_COLUMN or TOO_MANY_ROWS.
	 *
	 * @spec openspec/changes/landscape-migrate-from-previous-catalogue/tasks.md#task-1
	 */
	public function read(string $path, CmdbImportProfile $profile): array {
		$sheet = $profile->csvSheetName();
		if ($sheet === null) {
			throw new CmdbImportException(errorCode: CmdbImportException::MAPPING_UNAVAILABLE, message: 'The import profile reads no CSV file');
		}

		$lines = $this->records(text: self::utf8(text: $this->content(path: $path)));
		// The header is record 1; the data rows keep their record numbers.
		$header = ($lines[1] ?? []);
		unset($lines[1]);
		$columns = self::resolveColumns(header: $header, referenced: $profile->referencedColumns());

		$missing = array_values(array_diff($profile->requiredColumns(), $columns));
		if ($missing !== []) {
			throw new CmdbImportException(
				errorCode: CmdbImportException::MISSING_COLUMN,
				message: 'The CSV file lacks a required column',
				details: ['sheet' => $sheet, 'column' => $missing[0], 'columns' => $missing]
			);
		}

		return [
			'rows' => self::rows(lines: $lines, columns: $columns, sheet: $sheet, limit: $profile->maxRowsPerSheet()),
			'importWarnings' => [],
			'date1904' => false,
			'archive' => ['sheet' => '', 'present' => false, 'keyColumnMissing' => false, 'appIds' => [], 'uncachedRows' => []],
			'uncachedKeyRows' => [],
			'absentSheets' => [],
		];
	}//end read()

	/**
	 * The file's content.
	 *
	 * @param string $path The file.
	 *
	 * @return string
	 *
	 * @throws CmdbImportException NOT_CSV when it cannot be read.
	 */
	private function content(string $path): string {
		$content = false;
		if (is_file($path) === true && is_readable($path) === true) {
			$content = file_get_contents($path);
		}

		if ($content === false) {
			throw new CmdbImportException(errorCode: CmdbImportException::NOT_CSV, message: 'The CSV file cannot be read');
		}

		return $content;
	}//end content()

	/**
	 * The text as UTF-8 without a byte order mark; text that is not UTF-8 is read as Windows-1252.
	 *
	 * @param string $text The raw text.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/landscape-migrate-from-previous-catalogue/tasks.md#task-1
	 */
	public static function utf8(string $text): string {
		if (str_starts_with($text, "\xEF\xBB\xBF") === true) {
			$text = substr($text, 3);
		}

		if (mb_check_encoding($text, 'UTF-8') === true) {
			return $text;
		}

		return (string)mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
	}//end utf8()

	/**
	 * The delimiter of a header line: the one of `;`, `,` and tab that occurs most outside quotes.
	 *
	 * @param string $line The header line.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/landscape-migrate-from-previous-catalogue/tasks.md#task-1
	 */
	public static function delimiter(string $line): string {
		$unquoted = (string)preg_replace('/"[^"]*"/u', '', $line);
		$best = self::DELIMITERS[0];
		$bestCount = 0;
		foreach (self::DELIMITERS as $delimiter) {
			$count = substr_count($unquoted, $delimiter);
			if ($count > $bestCount) {
				$best = $delimiter;
				$bestCount = $count;
			}
		}

		return $best;
	}//end delimiter()

	/**
	 * The records of the text, the header first, each a list of cells.
	 *
	 * @param string $text The UTF-8 text.
	 *
	 * @return array<int, array<int, string>> Line number (1 is the header) => cells.
	 */
	private function records(string $text): array {
		$firstLine = strtok($text, "\r\n");
		$delimiter = self::delimiter(line: (string)$firstLine);

		$stream = fopen('php://temp', 'r+');
		if ($stream === false) {
			throw new CmdbImportException(errorCode: CmdbImportException::NOT_CSV, message: 'The CSV file cannot be buffered');
		}

		fwrite($stream, $text);
		rewind($stream);

		$records = [];
		$number = 0;
		while (($cells = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
			$number++;
			$records[$number] = array_map(static fn (?string $cell): string => (string)$cell, $cells);
		}

		fclose($stream);

		return $records;
	}//end records()

	/**
	 * Map the header cells to the referenced column names.
	 *
	 * @param array<int, string> $header The header cells.
	 * @param array<int, string> $referenced The allowlisted column names.
	 *
	 * @return array<int, string> Cell index => referenced column name; the first matching header wins.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) normaliseHeader() is the workbook reader's pure header rule, shared so
	 * both readers match a header alike.
	 */
	private static function resolveColumns(array $header, array $referenced): array {
		$wanted = [];
		foreach ($referenced as $column) {
			$wanted[CmdbWorkbookReader::normaliseHeader(header: $column)] = $column;
		}

		$columns = [];
		foreach ($header as $index => $cell) {
			$name = ($wanted[CmdbWorkbookReader::normaliseHeader(header: (string)$cell)] ?? null);
			if ($name !== null && in_array($name, $columns, true) === false) {
				$columns[(int)$index] = $name;
			}
		}

		return $columns;
	}//end resolveColumns()

	/**
	 * The non-empty data rows, with the kept cells only.
	 *
	 * @param array<int, array<int, string>> $lines Line number => cells, without the header.
	 * @param array<int, string> $columns Cell index => column name.
	 * @param string $sheet The sheet the rows stand for.
	 * @param int $limit The maximum number of non-empty rows.
	 *
	 * @return array<int, array{sheet: string, row: int, cells: array<string, string>, uncached: array<int, string>}>
	 *
	 * @throws CmdbImportException TOO_MANY_ROWS.
	 */
	private static function rows(array $lines, array $columns, string $sheet, int $limit): array {
		$rows = [];
		foreach ($lines as $number => $line) {
			$cells = [];
			foreach ($columns as $index => $name) {
				$cells[$name] = trim((string)($line[$index] ?? ''));
			}

			if (implode('', $cells) === '') {
				continue;
			}

			if (count($rows) >= $limit) {
				throw new CmdbImportException(
					errorCode: CmdbImportException::TOO_MANY_ROWS,
					message: 'The CSV file has more rows than the profile allows',
					details: ['sheet' => $sheet, 'limit' => $limit]
				);
			}

			$rows[] = ['sheet' => $sheet, 'row' => (int)$number, 'cells' => $cells, 'uncached' => []];
		}

		return $rows;
	}//end rows()
}//end class
