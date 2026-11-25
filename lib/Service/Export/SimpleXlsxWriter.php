<?php

/**
 * Stackiq simple XLSX writer
 *
 * Writes rows of text as a one-sheet Office Open XML workbook, with every cell
 * an inline string. Enough for a list export, with no spreadsheet library.
 *
 * @category Service
 * @package  OCA\Stackiq\Service\Export
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-11-25-insight-exports-and-custom-reports/specs/catalogue-exports-and-reports/spec.md#requirement-req-cer-002-the-applications-and-services-pages-shall-export-the-rows-the-facets-the-quick-filter-and-the-search-leave
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service\Export;

use RuntimeException;
use ZipArchive;

/**
 * Writes a list of text rows as an XLSX workbook with one sheet.
 *
 * @spec openspec/changes/archive/2026-11-25-insight-exports-and-custom-reports/specs/catalogue-exports-and-reports/spec.md#requirement-req-cer-002-the-applications-and-services-pages-shall-export-the-rows-the-facets-the-quick-filter-and-the-search-leave
 */
class SimpleXlsxWriter {

	/**
	 * The media type of an XLSX file.
	 */
	public const CONTENT_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

	private const CONTENT_TYPES = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
		. '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
		. '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
		. '<Default Extension="xml" ContentType="application/xml"/>'
		. '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
		. '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
		. '</Types>';

	private const ROOT_RELS = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
		. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
		. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
		. '</Relationships>';

	private const WORKBOOK_RELS = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
		. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
		. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
		. '</Relationships>';

	/**
	 * The workbook as bytes.
	 *
	 * @param string                  $sheetName The sheet's name (at most 31 characters are kept).
	 * @param array<int, array<int, string>> $rows      The rows, the first one the header.
	 *
	 * @return string The XLSX file.
	 *
	 * @throws RuntimeException When the zip cannot be written.
	 *
	 * @spec openspec/changes/archive/2026-11-25-insight-exports-and-custom-reports/specs/catalogue-exports-and-reports/spec.md#requirement-req-cer-002-the-applications-and-services-pages-shall-export-the-rows-the-facets-the-quick-filter-and-the-search-leave
	 */
	public function write(string $sheetName, array $rows): string {
		$path = tempnam(sys_get_temp_dir(), 'stackiq-xlsx-');
		if ($path === false) {
			throw new RuntimeException('No temporary file for the workbook');
		}

		try {
			$zip = new ZipArchive();
			if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
				throw new RuntimeException('The workbook could not be opened for writing');
			}

			$zip->addFromString('[Content_Types].xml', self::CONTENT_TYPES);
			$zip->addFromString('_rels/.rels', self::ROOT_RELS);
			$zip->addFromString('xl/_rels/workbook.xml.rels', self::WORKBOOK_RELS);
			$zip->addFromString('xl/workbook.xml', $this->workbook(sheetName: $sheetName));
			$zip->addFromString('xl/worksheets/sheet1.xml', $this->sheet(rows: $rows));
			$zip->close();

			return (string)file_get_contents($path);
		} finally {
			@unlink($path);
		}
	}//end write()

	/**
	 * The workbook part naming the one sheet.
	 *
	 * @param string $sheetName The sheet's name.
	 *
	 * @return string
	 */
	private function workbook(string $sheetName): string {
		$name = self::escape(value: mb_substr(str_replace(['\\', '/', '?', '*', '[', ']', ':'], ' ', $sheetName), 0, 31));

		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
			. ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
			. '<sheets><sheet name="' . $name . '" sheetId="1" r:id="rId1"/></sheets></workbook>';
	}//end workbook()

	/**
	 * The sheet part: every cell an inline string.
	 *
	 * @param array<int, array<int, string>> $rows The rows.
	 *
	 * @return string
	 */
	private function sheet(array $rows): string {
		$xml = '';
		foreach (array_values($rows) as $index => $row) {
			$number = ($index + 1);
			$cells  = '';
			foreach (array_values($row) as $column => $value) {
				$cells .= '<c r="' . self::columnName(index: $column) . $number . '" t="inlineStr"><is><t xml:space="preserve">'
					. self::escape(value: (string)$value) . '</t></is></c>';
			}

			$xml .= '<row r="' . $number . '">' . $cells . '</row>';
		}

		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
			. $xml . '</sheetData></worksheet>';
	}//end sheet()

	/**
	 * The column letters of a zero-based column index: 0 is A, 26 is AA.
	 *
	 * @param int $index The zero-based column index.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/archive/2026-11-25-insight-exports-and-custom-reports/specs/catalogue-exports-and-reports/spec.md#requirement-req-cer-002-the-applications-and-services-pages-shall-export-the-rows-the-facets-the-quick-filter-and-the-search-leave
	 */
	public static function columnName(int $index): string {
		$name = '';
		for ($n = ($index + 1); $n > 0; $n = intdiv(($n - 1), 26)) {
			$name = chr(65 + (($n - 1) % 26)) . $name;
		}

		return $name;
	}//end columnName()

	/**
	 * Text made safe for XML, without the control characters XML forbids.
	 *
	 * @param string $value The text.
	 *
	 * @return string
	 */
	private static function escape(string $value): string {
		$clean = (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value);

		return htmlspecialchars($clean, (ENT_XML1 | ENT_QUOTES), 'UTF-8');
	}//end escape()
}//end class
