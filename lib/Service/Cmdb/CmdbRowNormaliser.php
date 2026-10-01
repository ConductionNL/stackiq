<?php

/**
 * CMDB row normaliser.
 *
 * Turns a row from {@see CmdbWorkbookReader} into the flat
 * `column => string` row OpenRegister's `MappingEngine` expects (design D4):
 *
 * - Date columns: an Excel serial number becomes `Y-m-d` (1900 date system,
 *   or 1904 when the workbook says so). A non-numeric value stays as it is, so
 *   the pack's `date` transform accepts it or reports a warning.
 * - Id columns: a whole number becomes a string without a decimal part
 *   (`1234.0` becomes `"1234"`).
 * - Every value is trimmed; an empty value becomes the empty string.
 * - A value the profile lists as "empty" for its column (such as the "NB" a
 *   CMDB sheet writes for an unknown BNN classification) becomes the empty
 *   string, compared case-insensitively before any conversion.
 *
 * The conversion lives here and not in the packs, so every pack stays a plain
 * OpenRegister migration pack.
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

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Normalises reader rows into string rows for the mapping engine.
 *
 * @spec openspec/changes/cmdb-export-import/tasks.md#task-4
 *
 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The date system (1900 or 1904) is a property
 * of the workbook that the reader reports; it is data, not a mode switch.
 */
class CmdbRowNormaliser {
	/**
	 * Highest serial Excel accepts (9999-12-31).
	 */
	private const MAX_SERIAL = 2958465;

	/**
	 * Normalise one row.
	 *
	 * @param array<string, mixed> $cells Column name => raw cell value.
	 * @param array<int, string> $dateColumns Columns holding Excel serial dates.
	 * @param array<int, string> $idColumns Columns holding identifiers.
	 * @param bool $date1904 Whether the workbook uses the 1904 date system.
	 * @param array<string, array<int, string>> $emptyValues Values that mean empty, per column.
	 *
	 * @return array<string, string> Column name => normalised value.
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-4
	 */
	public function normalise(array $cells, array $dateColumns, array $idColumns, bool $date1904 = false, array $emptyValues = []): array {
		$row = [];
		foreach ($cells as $column => $value) {
			$column = (string)$column;
			if (self::meansEmpty(text: $this->toText(value: $value), empty: ($emptyValues[$column] ?? [])) === true) {
				$row[$column] = '';
				continue;
			}

			if (in_array($column, $dateColumns, true) === true) {
				$row[$column] = $this->normaliseDate(value: $value, date1904: $date1904);
				continue;
			}

			if (in_array($column, $idColumns, true) === true) {
				$row[$column] = $this->normaliseId(value: $value);
				continue;
			}

			$row[$column] = $this->toText(value: $value);
		}

		return $row;
	}//end normalise()

	/**
	 * An Excel serial date to `Y-m-d`; any other value trimmed as text.
	 *
	 * @param mixed $value The raw value.
	 * @param bool $date1904 Whether the workbook uses the 1904 date system.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-4
	 */
	public function normaliseDate(mixed $value, bool $date1904 = false): string {
		$text = $this->toText(value: $value);
		if (is_numeric($text) === false) {
			return $text;
		}

		$serial = (float)$text;
		if ($serial < 1 || $serial > self::MAX_SERIAL) {
			return $text;
		}

		$days = (int)floor($serial);
		// 1900 system: 1899-12-30 plus the serial, which absorbs Excel's
		// phantom 1900-02-29 for every serial after it. Before it (serial < 61)
		// the base is one day later. 1904 system: serial 0 is 1904-01-01.
		$base = '1899-12-30';
		if ($days < 61) {
			$base = '1899-12-31';
		}

		if ($date1904 === true) {
			$base = '1904-01-01';
		}

		$base = new DateTimeImmutable($base, new DateTimeZone('UTC'));

		return $base->add(new DateInterval('P' . $days . 'D'))->format('Y-m-d');
	}//end normaliseDate()

	/**
	 * A numeric identifier without a decimal part, as a string.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/cmdb-export-import/tasks.md#task-4
	 */
	public function normaliseId(mixed $value): string {
		if (is_float($value) === true && floor($value) === $value && abs($value) < PHP_INT_MAX) {
			return (string)(int)$value;
		}

		$text = $this->toText(value: $value);
		if (preg_match('/^(\d+)\.0+$/', $text, $matches) === 1) {
			return $matches[1];
		}

		return $text;
	}//end normaliseId()

	/**
	 * Whether a value is one of the column's "empty" values.
	 *
	 * @param string $text The trimmed value.
	 * @param array<int, string> $empty The column's empty values.
	 *
	 * @return bool
	 */
	private static function meansEmpty(string $text, array $empty): bool {
		if ($text === '' || $empty === []) {
			return false;
		}

		$needle = mb_strtolower($text);
		foreach ($empty as $candidate) {
			if (mb_strtolower(trim($candidate)) === $needle) {
				return true;
			}
		}

		return false;
	}//end meansEmpty()

	/**
	 * Any scalar as trimmed text; null as the empty string.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return string
	 */
	private function toText(mixed $value): string {
		if ($value === null) {
			return '';
		}

		if (is_bool($value) === true) {
			if ($value === true) {
				return 'TRUE';
			}

			return 'FALSE';
		}

		if (is_float($value) === true && floor($value) === $value && abs($value) < PHP_INT_MAX) {
			return (string)(int)$value;
		}

		if (is_scalar($value) === false) {
			return '';
		}

		return trim((string)$value);
	}//end toText()
}//end class
