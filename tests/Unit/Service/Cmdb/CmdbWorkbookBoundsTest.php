<?php

/**
 * Tests for the CMDB workbook size bounds.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service\Cmdb
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service\Cmdb;


use OCA\Stackiq\Exception\CmdbImportException;
use OCA\Stackiq\Service\Cmdb\CmdbWorkbookBounds;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZipArchive;

/**
 * The shared-string count and referenced-text limits, measured by streaming the workbook parts.
 *
 * @covers \OCA\Stackiq\Service\Cmdb\CmdbWorkbookBounds
 * @uses   \OCA\Stackiq\Exception\CmdbImportException
 */
class CmdbWorkbookBoundsTest extends TestCase {
	/**
	 * Workbook files written by a test, removed afterwards.
	 *
	 * @var array<int, string>
	 */
	private array $files = [];

	/**
	 * Remove the workbooks the test wrote.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ($this->files as $file) {
			@unlink($file);
		}
	}

	/**
	 * Write a zip with the given parts.
	 *
	 * @param array<string, string> $parts Part name to content.
	 *
	 * @return string The path.
	 */
	private function workbook(array $parts): string {
		$path = tempnam(sys_get_temp_dir(), 'cmdb');
		$zip  = new ZipArchive();
		$zip->open($path, ZipArchive::OVERWRITE);
		foreach ($parts as $name => $content) {
			$zip->addFromString($name, $content);
		}
		$zip->close();
		$this->files[] = $path;
		return $path;
	}

	/**
	 * A scanner that passes content through.
	 *
	 * @return object The scanner.
	 */
	private function scanner(): object {
		return new class {
			/**
			 * @param string $content Part content.
			 *
			 * @return string The same content.
			 */
			public function scan(string $content): string {
				return $content;
			}
		};
	}

	/**
	 * Run a bound and return the refusal it threw, or null.
	 *
	 * @param callable $bound The bound to run.
	 *
	 * @return CmdbImportException|null The refusal.
	 */
	private function refusal(callable $bound): ?CmdbImportException {
		try {
			$bound();
		} catch (CmdbImportException $e) {
			return $e;
		}

		return null;
	}

	/**
	 * Plain and rich entries count one per string plus one per rich run.
	 *
	 * @return void
	 */
	public function testSharedStringsCountEntriesAndRichRuns(): void {
		$path = $this->workbook([
			'xl/sharedStrings.xml' => '<sst xmlns="urn:x"><si><t>a</t></si><si><r><t>b</t></r><r><t>c</t></r></si><si/></sst>',
		]);
		$bounds = new CmdbWorkbookBounds();

		self::assertNull($this->refusal(fn () => $bounds->assertSharedStringCount($path, 5, $this->scanner())));

		$refusal = $this->refusal(fn () => $bounds->assertSharedStringCount($path, 4, $this->scanner()));
		self::assertNotNull($refusal);
		self::assertSame(CmdbImportException::WORKBOOK_TOO_LARGE, $refusal->getErrorCode());
		self::assertSame(['maxSharedStrings' => 4], $refusal->getDetails());
		self::assertSame('The shared-strings table holds more entries than the profile allows', $refusal->getMessage());
	}

	/**
	 * Inline strings in a sheet count their rich runs, and the count spans every part.
	 *
	 * @return void
	 */
	public function testInlineRunsAcrossPartsAreCounted(): void {
		$path = $this->workbook([
			'xl/sharedStrings.xml'      => '<sst><si><t>a</t></si></sst>',
			'xl/worksheets/sheet1.xml' => '<worksheet><sheetData><row><c t="inlineStr">'
				.'<is><r><t>x</t></r><r><t>y</t></r></is></c></row></sheetData></worksheet>',
		]);
		$bounds = new CmdbWorkbookBounds();

		self::assertNull($this->refusal(fn () => $bounds->assertSharedStringCount($path, 3, $this->scanner())));
		self::assertNotNull($this->refusal(fn () => $bounds->assertSharedStringCount($path, 2, $this->scanner())));
	}

	/**
	 * A path that is no zip and a part the scanner rejects are not measured.
	 *
	 * @return void
	 */
	public function testUnreadableInputIsNotMeasured(): void {
		$bounds = new CmdbWorkbookBounds();
		$notZip = tempnam(sys_get_temp_dir(), 'cmdb');
		file_put_contents($notZip, 'plain text');
		$this->files[] = $notZip;
		$rejecting = new class {
			/**
			 * @param string $content Part content.
			 *
			 * @return string Never.
			 */
			public function scan(string $content): string {
				throw new RuntimeException('entity found');
			}
		};
		$path = $this->workbook(['xl/sharedStrings.xml' => '<sst><si/><si/><si/></sst>', 'empty.xml' => '']);

		self::assertNull($this->refusal(fn () => $bounds->assertSharedStringCount($notZip, 0, $this->scanner())));
		self::assertNull($this->refusal(fn () => $bounds->assertSharedStringCount($path, 0, $rejecting)));
		self::assertNotNull($this->refusal(fn () => $bounds->assertSharedStringCount($path, 0, $this->scanner())));
	}

	/**
	 * A shared string weighs 16 per element plus its text bytes; a cell referencing it spends that weight.
	 *
	 * @return void
	 */
	public function testReferencedTextIsWeighedAgainstThePerSheetBudget(): void {
		// The string "hello" weighs 16 (si) + 16 (t) + 5 = 37.
		$path = $this->workbook([
			'xl/sharedStrings.xml'      => '<sst><si><t>hello</t></si></sst>',
			'xl/worksheets/sheet1.xml' => '<worksheet><sheetData><row><c t="s"><v>0</v></c><c><v>0</v></c></row></sheetData></worksheet>',
		]);
		$bounds = new CmdbWorkbookBounds();

		self::assertNull($this->refusal(fn () => $bounds->assertReferencedStringBytes($path, 37, 1, $this->scanner())));
		self::assertNull($this->refusal(fn () => $bounds->assertReferencedStringBytes($path, 74, 2, $this->scanner())));

		$refusal = $this->refusal(fn () => $bounds->assertReferencedStringBytes($path, 73, 2, $this->scanner()));
		self::assertNotNull($refusal);
		self::assertSame(['maxReferencedStringBytes' => 73], $refusal->getDetails());
		self::assertSame('The cells of the workbook reference more shared-string text than the profile allows', $refusal->getMessage());
	}

	/**
	 * Every shared-string cell spends its weight, and a workbook without shared strings is not measured.
	 *
	 * @return void
	 */
	public function testEveryReferenceCountsAndNoTableMeansNoBound(): void {
		$sheet = '<worksheet><sheetData><row><c t="s"><v>0</v></c><c t="s"><v>0</v></c><c t="s"><v/></c></row></sheetData></worksheet>';
		$path  = $this->workbook([
			'xl/sharedStrings.xml'      => '<sst><si><t>hello</t></si></sst>',
			'xl/worksheets/sheet1.xml' => $sheet,
		]);
		$bounds = new CmdbWorkbookBounds();

		// Two references to "hello" and one empty value, which reads as index 0 too.
		self::assertNull($this->refusal(fn () => $bounds->assertReferencedStringBytes($path, 111, 1, $this->scanner())));
		self::assertNotNull($this->refusal(fn () => $bounds->assertReferencedStringBytes($path, 110, 1, $this->scanner())));

		$noTable = $this->workbook(['xl/worksheets/sheet1.xml' => $sheet]);
		self::assertNull($this->refusal(fn () => $bounds->assertReferencedStringBytes($noTable, 0, 1, $this->scanner())));
	}

	/**
	 * The heaviest table wins per index when several parts hold shared strings.
	 *
	 * @return void
	 */
	public function testTheHeaviestTableWinsPerIndex(): void {
		$path = $this->workbook([
			'a/sharedStrings.xml'       => '<sst><si><t>hi</t></si></sst>',
			'b/sharedStrings.xml'       => '<sst><si><t>much longer text</t></si></sst>',
			'xl/worksheets/sheet1.xml' => '<worksheet><sheetData><row><c t="s"><v>0</v></c></row></sheetData></worksheet>',
		]);
		$bounds = new CmdbWorkbookBounds();

		// 16 + 16 + 16 bytes of "much longer text".
		self::assertNull($this->refusal(fn () => $bounds->assertReferencedStringBytes($path, 48, 1, $this->scanner())));
		self::assertNotNull($this->refusal(fn () => $bounds->assertReferencedStringBytes($path, 47, 1, $this->scanner())));
	}

	/**
	 * eachPart hands every readable part to the consumer with its name.
	 *
	 * @return void
	 */
	public function testEachPartVisitsReadablePartsInOrder(): void {
		$path  = $this->workbook(['one.xml' => '<a/>', 'broken.xml' => '', 'two.xml' => '<b/>']);
		$names = [];

		(new CmdbWorkbookBounds())->eachPart($path, $this->scanner(), static function (\XMLReader $xml, string $name) use (&$names): void {
			$names[] = $name;
		});

		self::assertSame(['one.xml', 'two.xml'], $names);
	}
}
