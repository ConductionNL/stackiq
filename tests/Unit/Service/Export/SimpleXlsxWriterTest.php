<?php

/**
 * Unit tests for SimpleXlsxWriter.
 *
 * @category Tests
 * @package  OCA\Stackiq\Tests\Unit\Service\Export
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service\Export;

use OCA\Stackiq\Service\Export\SimpleXlsxWriter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * The single-sheet xlsx workbook the catalogue export writes.
 */
#[CoversClass(SimpleXlsxWriter::class)]
class SimpleXlsxWriterTest extends TestCase {

	/**
	 * Open a written workbook and answer its parts by name.
	 *
	 * @param string $bytes The workbook.
	 *
	 * @return array<string, string>
	 */
	private function parts(string $bytes): array {
		$path = tempnam(sys_get_temp_dir(), 'xlsx-test-');
		file_put_contents($path, $bytes);
		$zip = new ZipArchive();
		$this->assertTrue($zip->open($path));
		$parts = [];
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$name         = (string)$zip->getNameIndex($i);
			$parts[$name] = (string)$zip->getFromName($name);
		}

		$zip->close();
		unlink($path);
		return $parts;
	}//end parts()

	/**
	 * Column letters as a spreadsheet names them.
	 *
	 * @return array<string, array{int, string}>
	 */
	public static function columns(): array {
		return [
			'first' => [0, 'A'],
			'last single' => [25, 'Z'],
			'first double' => [26, 'AA'],
			'AZ' => [51, 'AZ'],
			'BA' => [52, 'BA'],
			'last double' => [701, 'ZZ'],
			'first triple' => [702, 'AAA'],
		];
	}//end columns()

	/**
	 * A zero-based column index becomes its letter name.
	 *
	 * @param int    $index    The index.
	 * @param string $expected The name.
	 *
	 * @return void
	 */
	#[DataProvider('columns')]
	public function testColumnName(int $index, string $expected): void {
		$this->assertSame($expected, SimpleXlsxWriter::columnName(index: $index));
	}//end testColumnName()

	/**
	 * The workbook holds exactly the five package parts.
	 *
	 * @return void
	 */
	public function testWorkbookHoldsThePackageParts(): void {
		$parts = $this->parts((new SimpleXlsxWriter())->write(sheetName: 'Gebruik', rows: []));

		$names = array_keys($parts);
		sort($names);
		$this->assertSame(
			['[Content_Types].xml', '_rels/.rels', 'xl/_rels/workbook.xml.rels', 'xl/workbook.xml', 'xl/worksheets/sheet1.xml'],
			$names
		);
		$this->assertStringContainsString('<sheet name="Gebruik" sheetId="1" r:id="rId1"/>', $parts['xl/workbook.xml']);
		$this->assertStringContainsString('<sheetData></sheetData>', $parts['xl/worksheets/sheet1.xml']);
		$this->assertSame(
			'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
			SimpleXlsxWriter::CONTENT_TYPE
		);
	}//end testWorkbookHoldsThePackageParts()

	/**
	 * Rows and cells are numbered from A1, keyed arrays in their order, every value an inline string.
	 *
	 * @return void
	 */
	public function testRowsBecomeInlineStringCells(): void {
		$parts = $this->parts((new SimpleXlsxWriter())->write(
			sheetName: 'Export',
			rows: [
				'header' => ['naam' => 'Naam', 'versie' => 'Versie'],
				'first' => ['naam' => 'Zaaksysteem', 'versie' => 3],
			]
		));

		$sheet = $parts['xl/worksheets/sheet1.xml'];
		$this->assertStringContainsString(
			'<row r="1"><c r="A1" t="inlineStr"><is><t xml:space="preserve">Naam</t></is></c>'
			. '<c r="B1" t="inlineStr"><is><t xml:space="preserve">Versie</t></is></c></row>',
			$sheet
		);
		$this->assertStringContainsString(
			'<row r="2"><c r="A2" t="inlineStr"><is><t xml:space="preserve">Zaaksysteem</t></is></c>'
			. '<c r="B2" t="inlineStr"><is><t xml:space="preserve">3</t></is></c></row>',
			$sheet
		);
	}//end testRowsBecomeInlineStringCells()

	/**
	 * Cell text is XML-escaped and control characters are dropped, so the sheet stays well-formed.
	 *
	 * @return void
	 */
	public function testCellTextIsEscapedAndControlCharactersDropped(): void {
		$parts = $this->parts((new SimpleXlsxWriter())->write(
			sheetName: 'Export',
			rows: [["A & B <c> \"d\" 'e'\x01\x0B", "regel 1\nregel 2\ttab"]]
		));

		$sheet = $parts['xl/worksheets/sheet1.xml'];
		$this->assertStringContainsString('A &amp; B &lt;c&gt; &quot;d&quot; &apos;e&apos;</t>', $sheet);
		$this->assertStringContainsString("regel 1\nregel 2\ttab</t>", $sheet);
		$this->assertNotFalse(simplexml_load_string($sheet));
	}//end testCellTextIsEscapedAndControlCharactersDropped()

	/**
	 * The sheet name loses the characters Excel forbids, is cut to 31 characters and escaped.
	 *
	 * @return void
	 */
	public function testSheetNameIsSanitisedTruncatedAndEscaped(): void {
		$parts = $this->parts((new SimpleXlsxWriter())->write(sheetName: 'A/B\\C?D*E[F]G:H & een hele lange naam erachter', rows: []));

		$this->assertStringContainsString('<sheet name="A B C D E F G H &amp; een hele lang" sheetId="1"', $parts['xl/workbook.xml']);
		$this->assertNotFalse(simplexml_load_string($parts['xl/workbook.xml']));
	}//end testSheetNameIsSanitisedTruncatedAndEscaped()

	/**
	 * The temporary file the workbook is built in is removed afterwards.
	 *
	 * @return void
	 */
	public function testNoTemporaryFileIsLeftBehind(): void {
		$before = glob(sys_get_temp_dir() . '/stackiq-xlsx-*') ?: [];
		(new SimpleXlsxWriter())->write(sheetName: 'Export', rows: [['x']]);
		$after = glob(sys_get_temp_dir() . '/stackiq-xlsx-*') ?: [];

		$this->assertSame($before, $after);
	}//end testNoTemporaryFileIsLeftBehind()
}//end class
