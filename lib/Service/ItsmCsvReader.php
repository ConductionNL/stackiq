<?php

/**
 * Reads the CSV file of an ITSM file import into rows of cells.
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

use RuntimeException;

/**
 * A CSV file as rows of cells: comma or semicolon, whichever the header uses, without the byte order mark.
 *
 * @spec openspec/changes/sharing-itsm-exchange/specs/itsm-exchange/spec.md#requirement-req-itx-006-a-file-feeds-the-same-import
 */
final class ItsmCsvReader {
	/**
	 * Read a CSV file into rows of cells. Comma or semicolon, whichever the header uses.
	 *
	 * @param string $path The file.
	 *
	 * @return \Generator<int, list<string>> The cells, row by row; the file closes when reading stops.
	 *
	 * @throws RuntimeException When the file cannot be opened.
	 *
	 * @spec openspec/changes/sharing-itsm-exchange/specs/itsm-exchange/spec.md#requirement-req-itx-006-a-file-feeds-the-same-import
	 */
	public function rows(string $path): \Generator {
		$handle = fopen($path, 'r');
		if ($handle === false) {
			throw new RuntimeException('cannot open the uploaded file');
		}

		try {
			$delimiter = $this->csvDelimiter(header: (string) fgets($handle));
			rewind($handle);
			yield from $this->csvRows(handle: $handle, delimiter: $delimiter);
		} finally {
			fclose($handle);
		}
	}//end rows()

	/**
	 * The delimiter a CSV header uses: a semicolon when it has more semicolons than commas, else a comma.
	 *
	 * @param string $header The first line of the file.
	 *
	 * @return string
	 */
	private function csvDelimiter(string $header): string {
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
	private function csvRows(mixed $handle, string $delimiter): \Generator {
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
}//end class
