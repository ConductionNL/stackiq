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
use Throwable;

/**
 * Reads a spreadsheet and starts the file import flow with its rows.
 */
class ItsmFileImportService {

	/**
	 * The most rows one import takes.
	 *
	 * @var integer
	 */
	public const MAX_ROWS = 5000;

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
	 */
	public function __construct(
		private readonly ItsmFlowGateway $gateway,
		private readonly IAppConfig $appConfig,
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
		$flow   = (is_array($config) === true) ? ($config['flows']['file'] ?? null) : null;
		if (is_string($flow) === false || $flow === '') {
			return ['started' => false, 'message' => 'Set up the exchange first. The file import uses the flow the set-up creates.'];
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

		$run = $this->gateway->run(uuid: $flow, payload: ['rows' => $rows]);

		return ['started' => true, 'run' => $run, 'rows' => count($rows)];
	}//end import()

	/**
	 * Read the rows of a CSV or XLSX file, keyed by the header row.
	 *
	 * @param string $path The file.
	 * @param string $name Its name.
	 *
	 * @return list<array<string, string>> The rows, empty cells left out.
	 *
	 * @throws \RuntimeException When the type is not CSV or XLSX, or XLSX cannot be read here.
	 *
	 * @spec openspec/changes/sharing-itsm-exchange/specs/itsm-exchange/spec.md#requirement-req-itx-006-a-file-feeds-the-same-import
	 */
	public function readRows(string $path, string $name): array {
		$extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
		if ($extension === 'csv') {
			$table = $this->readCsv(path: $path);
		} else if ($extension === 'xlsx') {
			$table = $this->readXlsx(path: $path);
		} else {
			throw new \RuntimeException('only .csv and .xlsx files can be imported, not .' . $extension);
		}

		$header = array_map(static fn ($cell): string => trim((string) $cell), (array) array_shift($table));
		$rows   = [];
		foreach ($table as $cells) {
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
			return 'The file holds ' . count($rows) . ' rows; one import takes at most ' . self::MAX_ROWS . '.';
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
	 * @return list<list<string>> The cells.
	 */
	private function readCsv(string $path): array {
		$handle = fopen($path, 'r');
		if ($handle === false) {
			throw new \RuntimeException('cannot open the uploaded file');
		}

		$first     = (string) fgets($handle);
		$delimiter = ',';
		if (substr_count($first, ';') > substr_count($first, ',')) {
			$delimiter = ';';
		}

		rewind($handle);
		$table = [];
		while (($cells = fgetcsv($handle, null, $delimiter, '"', '\\')) !== false) {
			$table[] = array_map(static fn ($cell): string => (string) $cell, $cells);
		}

		fclose($handle);
		if (isset($table[0][0]) === true) {
			$table[0][0] = preg_replace('/^\xEF\xBB\xBF/', '', $table[0][0]);
		}

		return $table;
	}//end readCsv()

	/**
	 * Read the first sheet of an XLSX file into rows of cells, with PhpSpreadsheet as OpenRegister ships it.
	 *
	 * @param string $path The file.
	 *
	 * @return list<list<string>> The cells.
	 */
	private function readXlsx(string $path): array {
		$factory = '\PhpOffice\PhpSpreadsheet\IOFactory';
		if (class_exists($factory) === false) {
			throw new \RuntimeException('reading .xlsx needs PhpSpreadsheet, which OpenRegister provides; save the sheet as .csv instead');
		}

		$sheet = $factory::load($path)->getActiveSheet()->toArray(null, true, false, false);

		return array_map(static fn ($cells): array => array_map(static fn ($cell): string => (string) $cell, (array) $cells), (array) $sheet);
	}//end readXlsx()
}//end class
