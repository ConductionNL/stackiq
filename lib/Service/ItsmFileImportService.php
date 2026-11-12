<?php

/**
 * Imports applications from a CSV or XLSX file through the exchange's file flow.
 *
 * The file is read here and nowhere else: its rows become the payload of one
 * run of the file import flow, which maps, matches and writes them exactly as
 * the service desk import does. A row without a record id is refused before
 * anything runs, because the record id is what makes a second import of the
 * same file an update.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/sharing-itsm-exchange/specs/itsm-exchange/spec.md#requirement-req-itx-006-a-file-feeds-the-same-import
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

use OCA\Stackiq\AppInfo\AppIdentity;
use OCA\Stackiq\Service\Itsm\ItsmFlowGateway;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Reads a spreadsheet and starts the file import flow with its rows.
 *
 * @spec openspec/changes/sharing-itsm-exchange/specs/itsm-exchange/spec.md#requirement-req-itx-001-an-administrator-sets-up-the-exchange-without-stackiq-holding-a-credential
 */
class ItsmFileImportService {

	/**
	 * The most rows one import takes.
	 *
	 * @var integer
	 */
	public const MAX_ROWS = 5000;

	/**
	 * The largest file one import reads, in bytes (10 MiB, as the CMDB import).
	 *
	 * @var integer
	 */
	public const MAX_FILE_BYTES = 10485760;

	/**
	 * PhpSpreadsheet's XLSX reader, as OpenRegister ships it.
	 *
	 * @var string
	 */
	public const XLSX_READER = '\PhpOffice\PhpSpreadsheet\Reader\Xlsx';

	/**
	 * The column every row must fill.
	 *
	 * @var string
	 */
	public const KEY_COLUMN = 'recordId';

	/**
	 * Constructor.
	 *
	 * @param ItsmFlowGateway $gateway   OpenRegister's flow store.
	 * @param IAppConfig      $appConfig The app settings.
	 * @param LoggerInterface $logger    Logs a flow that could not be started.
	 */
	public function __construct(
		private readonly ItsmFlowGateway $gateway,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Import one file.
	 *
	 * @param string $path The uploaded file on disk.
	 * @param string $name The name it was uploaded with, which tells CSV from XLSX.
	 *
	 * @return array<string, mixed> `started` with the run and the row count, or `started: false` with the reason.
	 *
	 * @spec openspec/changes/sharing-itsm-exchange/specs/itsm-exchange/spec.md#requirement-req-itx-006-a-file-feeds-the-same-import
	 */
	public function import(string $path, string $name): array {
		$flow = $this->fileFlow();
		if ($flow === null) {
			return self::refused(message: 'Set up the exchange first. The file import uses the flow the set-up creates.');
		}

		$rows = $this->rowsOrProblem(path: $path, name: $name);
		if (is_string($rows) === true) {
			return self::refused(message: $rows);
		}

		return $this->startImport(flow: $flow, rows: $rows);
	}//end import()

	/**
	 * The uuid of the file import flow the exchange set-up created.
	 *
	 * @return string|null The flow uuid, or null when the exchange is not set up.
	 */
	private function fileFlow(): ?string {
		$config = json_decode($this->appConfig->getValueString(AppIdentity::APP_ID, ItsmExchangeService::CONFIG_KEY, '{}'), true);
		if (is_array($config) === false) {
			return null;
		}

		$flow = ($config['flows']['file'] ?? null);
		if (is_string($flow) === false || $flow === '') {
			return null;
		}

		return $flow;
	}//end fileFlow()

	/**
	 * The checked rows of a file, or the reason it cannot be imported.
	 *
	 * @param string $path The uploaded file on disk.
	 * @param string $name The name it was uploaded with.
	 *
	 * @return array<int, array<string, string>>|string The rows, or the problem.
	 */
	private function rowsOrProblem(string $path, string $name): array|string {
		if (is_file($path) === true && filesize($path) > self::MAX_FILE_BYTES) {
			return 'The file is larger than ' . (self::MAX_FILE_BYTES / 1048576) . ' MB; one import takes at most that.';
		}

		try {
			$rows = $this->readRows(path: $path, name: $name);
		} catch (Throwable $e) {
			return 'The file could not be read: ' . $e->getMessage();
		}

		return ($this->checkRows(rows: $rows) ?? $rows);
	}//end rowsOrProblem()

	/**
	 * Start the file import flow with the rows.
	 *
	 * @param string                             $flow The flow uuid.
	 * @param array<int, array<string, string>> $rows The checked rows.
	 *
	 * @return array<string, mixed> `started` with the run and the row count, or `started: false` with the reason.
	 */
	private function startImport(string $flow, array $rows): array {
		try {
			$run = $this->gateway->run(uuid: $flow, payload: ['rows' => $rows]);
		} catch (Throwable $e) {
			$this->logger->error('[ItsmFileImportService] Starting the file import flow failed', ['exception' => $e]);
			return self::refused(message: 'The file import flow could not be started: ' . $e->getMessage());
		}

		return ['started' => true, 'run' => $run, 'rows' => count($rows)];
	}//end startImport()

	/**
	 * The result of an import that did not start.
	 *
	 * @param string $message Why it did not start.
	 *
	 * @return array{started: false, message: string}
	 */
	private static function refused(string $message): array {
		return ['started' => false, 'message' => $message];
	}//end refused()

	/**
	 * Read the rows of a CSV or XLSX file, keyed by the header row.
	 *
	 * @param string $path The file.
	 * @param string $name Its name.
	 *
	 * Reading stops one row past MAX_ROWS, so an oversized file is refused
	 * without reading the rest of it.
	 *
	 * @return list<array<string, string>> The rows, empty cells left out.
	 *
	 * @throws RuntimeException When the type is not CSV or XLSX, or XLSX cannot be read here.
	 *
	 * @spec openspec/changes/sharing-itsm-exchange/specs/itsm-exchange/spec.md#requirement-req-itx-006-a-file-feeds-the-same-import
	 */
	public function readRows(string $path, string $name): array {
		$header = null;
		$rows   = [];
		foreach ($this->readTable(path: $path, name: $name) as $cells) {
			if ($header === null) {
				$header = array_map(static fn ($cell): string => trim((string) $cell), $cells);
				continue;
			}

			if (self::addRow(rows: $rows, row: self::rowOf(header: $header, cells: $cells)) === false) {
				break;
			}
		}

		return $rows;
	}//end readRows()

	/**
	 * Add a row unless it is empty; refuse it once the rows are past MAX_ROWS.
	 *
	 * @param list<array<string, string>> $rows The rows read so far; the row is appended to them.
	 * @param array<string, string>       $row  The row.
	 *
	 * @return bool False when the rows already hold more than MAX_ROWS, so reading stops.
	 */
	private static function addRow(array &$rows, array $row): bool {
		if (count($rows) > self::MAX_ROWS) {
			return false;
		}

		if ($row !== []) {
			$rows[] = $row;
		}

		return true;
	}//end addRow()

	/**
	 * One row keyed by the header, with the cells under an empty header and the empty cells left out.
	 *
	 * @param list<string> $header The trimmed header cells.
	 * @param list<string> $cells  The row's cells.
	 *
	 * @return array<string, string>
	 */
	private static function rowOf(array $header, array $cells): array {
		$row = [];
		foreach ($header as $index => $column) {
			$cell = trim((string) ($cells[$index] ?? ''));
			if ($column !== '' && $cell !== '') {
				$row[$column] = $cell;
			}
		}

		return $row;
	}//end rowOf()

	/**
	 * Read a file into rows of cells, by its extension.
	 *
	 * @param string $path The file.
	 * @param string $name Its name.
	 *
	 * @return iterable<list<string>> The cells, row by row.
	 *
	 * @throws RuntimeException When the type is not CSV or XLSX.
	 */
	private function readTable(string $path, string $name): iterable {
		$extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
		if ($extension === 'csv') {
			return $this->readCsv(path: $path);
		}

		if ($extension === 'xlsx') {
			return $this->readXlsx(path: $path);
		}

		throw new RuntimeException('only .csv and .xlsx files can be imported, not .' . $extension);
	}//end readTable()

	/**
	 * Why the rows cannot be imported, or null when they can.
	 *
	 * @param list<array<string, string>> $rows The rows.
	 *
	 * @return string|null The reason, naming the first bad row as the spreadsheet numbers it.
	 *
	 * @spec openspec/changes/sharing-itsm-exchange/specs/itsm-exchange/spec.md#requirement-req-itx-006-a-file-feeds-the-same-import
	 */
	public function checkRows(array $rows): ?string {
		if ($rows === []) {
			return 'The file holds no rows under its header.';
		}

		if (count($rows) > self::MAX_ROWS) {
			return 'The file holds more than ' . self::MAX_ROWS . ' rows; one import takes at most ' . self::MAX_ROWS . '.';
		}

		return self::missingKeyReason(rows: $rows);
	}//end checkRows()

	/**
	 * Why a row cannot be imported for lack of a key, or null when every row has one.
	 *
	 * @param list<array<string, string>> $rows The rows.
	 *
	 * @return string|null The reason, naming the first row without a key as the spreadsheet numbers it.
	 */
	private static function missingKeyReason(array $rows): ?string {
		foreach ($rows as $index => $row) {
			if (($row[self::KEY_COLUMN] ?? '') === '') {
				return 'Row ' . ($index + 2) . ' has no ' . self::KEY_COLUMN . '. Every row needs one, so a second import updates instead of adding.';
			}
		}

		return null;
	}//end missingKeyReason()

	/**
	 * Read a CSV file into rows of cells. Comma or semicolon, whichever the header uses.
	 *
	 * @param string $path The file.
	 *
	 * @return \Generator<int, list<string>> The cells, row by row; the file closes when reading stops.
	 */
	private function readCsv(string $path): \Generator {
		$handle = fopen($path, 'r');
		if ($handle === false) {
			throw new RuntimeException('cannot open the uploaded file');
		}

		try {
			$delimiter = self::csvDelimiter(header: (string) fgets($handle));
			rewind($handle);
			yield from self::csvRows(handle: $handle, delimiter: $delimiter);
		} finally {
			fclose($handle);
		}
	}//end readCsv()

	/**
	 * The delimiter a CSV header uses: a semicolon when it has more semicolons than commas, else a comma.
	 *
	 * @param string $header The first line of the file.
	 *
	 * @return string
	 */
	private static function csvDelimiter(string $header): string {
		if (substr_count($header, ';') > substr_count($header, ',')) {
			return ';';
		}

		return ',';
	}//end csvDelimiter()

	/**
	 * Read the rows of cells of an open CSV file, without the byte order mark before the first cell.
	 *
	 * @param resource $handle    The open file, at its start.
	 * @param string   $delimiter The delimiter.
	 *
	 * @return \Generator<int, list<string>> The cells, row by row.
	 */
	private static function csvRows(mixed $handle, string $delimiter): \Generator {
		$isFirst = true;
		while (($cells = fgetcsv($handle, null, $delimiter, '"', '\\')) !== false) {
			$cells = array_map(static fn ($cell): string => (string) $cell, $cells);
			if ($isFirst === true && isset($cells[0]) === true) {
				$cells[0] = (string) preg_replace('/^\xEF\xBB\xBF/', '', $cells[0]);
			}

			$isFirst = false;
			yield $cells;
		}
	}//end csvRows()

	/**
	 * Read the first sheet of an XLSX file into rows of cells, with PhpSpreadsheet as OpenRegister ships it.
	 *
	 * Always the XLSX reader, whatever the content looks like, with data only.
	 * A formula gives the value Excel cached; it is never calculated here.
	 *
	 * @param string $path The file.
	 *
	 * @return \Generator<int, list<string>> The cells, row by row; the workbook is released when reading stops.
	 */
	private function readXlsx(string $path): \Generator {
		$readerClass = self::XLSX_READER;
		if (class_exists($readerClass) === false) {
			throw new RuntimeException('reading .xlsx needs PhpSpreadsheet, which OpenRegister provides; save the sheet as .csv instead');
		}

		$reader = new $readerClass();
		$reader->setReadDataOnly(true);
		$spreadsheet = $reader->load($path);
		try {
			foreach ($spreadsheet->getActiveSheet()->getRowIterator() as $row) {
				$iterator = $row->getCellIterator();
				$iterator->setIterateOnlyExistingCells(false);
				$cells = [];
				foreach ($iterator as $cell) {
					$cells[] = self::cellText(cell: $cell);
				}

				yield $cells;
			}
		} finally {
			$spreadsheet->disconnectWorksheets();
		}
	}//end readXlsx()

	/**
	 * The text of one XLSX cell; for a formula, the value Excel cached.
	 *
	 * @param object $cell The PhpSpreadsheet cell.
	 *
	 * @return string The text, or an empty string for a value that is not text or a number.
	 */
	private static function cellText(object $cell): string {
		$value = $cell->getValue();
		if ($cell->getDataType() === 'f') {
			$value = $cell->getOldCalculatedValue();
		}

		if (is_object($value) === true && method_exists($value, 'getPlainText') === true) {
			return (string) $value->getPlainText();
		}

		if (is_scalar($value) === false) {
			return '';
		}

		return (string) $value;
	}//end cellText()
}//end class
