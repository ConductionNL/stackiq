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

use OCA\Stackiq\AppInfo\Application;
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
		$config = json_decode($this->appConfig->getValueString(Application::APP_ID, ItsmExchangeService::CONFIG_KEY, '{}'), true);
		$flow   = null;
		if (is_array($config) === true) {
			$flow = ($config['flows']['file'] ?? null);
		}
		if (is_string($flow) === false || $flow === '') {
			return ['started' => false, 'message' => 'Set up the exchange first. The file import uses the flow the set-up creates.'];
		}

		if (is_file($path) === true && filesize($path) > self::MAX_FILE_BYTES) {
			return ['started' => false, 'message' => 'The file is larger than ' . (self::MAX_FILE_BYTES / 1048576) . ' MB; one import takes at most that.'];
		}

		try {
			$rows = $this->readRows(path: $path, name: $name);
		} catch (Throwable $e) {
			return ['started' => false, 'message' => 'The file could not be read: ' . $e->getMessage()];
		}

		$problem = $this->checkRows(rows: $rows);
		if ($problem !== null) {
			return ['started' => false, 'message' => $problem];
		}

		try {
			$run = $this->gateway->run(uuid: $flow, payload: ['rows' => $rows]);
		} catch (Throwable $e) {
			$this->logger->error('[ItsmFileImportService] Starting the file import flow failed', ['exception' => $e]);
			return ['started' => false, 'message' => 'The file import flow could not be started: ' . $e->getMessage()];
		}

		return ['started' => true, 'run' => $run, 'rows' => count($rows)];
	}//end import()

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

			if (count($rows) > self::MAX_ROWS) {
				break;
			}

			$row = [];
			foreach ($header as $index => $column) {
				$cell = trim((string) ($cells[$index] ?? ''));
				if ($column !== '' && $cell !== '') {
					$row[$column] = $cell;
				}
			}

			if ($row !== []) {
				$rows[] = $row;
			}
		}

		return $rows;
	}//end readRows()

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

		foreach ($rows as $index => $row) {
			if (($row[self::KEY_COLUMN] ?? '') === '') {
				return 'Row ' . ($index + 2) . ' has no ' . self::KEY_COLUMN . '. Every row needs one, so a second import updates instead of adding.';
			}
		}

		return null;
	}//end checkRows()

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
			$first     = (string) fgets($handle);
			$delimiter = ',';
			if (substr_count($first, ';') > substr_count($first, ',')) {
				$delimiter = ';';
			}

			rewind($handle);
			$isFirst = true;
			while (($cells = fgetcsv($handle, null, $delimiter, '"', '\\')) !== false) {
				$cells = array_map(static fn ($cell): string => (string) $cell, $cells);
				if ($isFirst === true && isset($cells[0]) === true) {
					$cells[0] = (string) preg_replace('/^\xEF\xBB\xBF/', '', $cells[0]);
				}

				$isFirst = false;
				yield $cells;
			}
		} finally {
			fclose($handle);
		}
	}//end readCsv()

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
