<?php

/**
 * Unit tests for CmdbWorkbookBounds: every XML part of a workbook is handed over in order, the shared-string count stops above its limit, and the shared-string text the cells reference is held to the per-sheet budget.
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
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service\Cmdb;

use OCA\Stackiq\Exception\CmdbImportException;
use OCA\Stackiq\Service\Cmdb\CmdbWorkbookBounds;
use PHPUnit\Framework\TestCase;
use XMLReader;
use ZipArchive;

/**
 * Tests for CmdbWorkbookBounds.
 *
 * @covers \OCA\Stackiq\Service\Cmdb\CmdbWorkbookBounds
 * @uses   \OCA\Stackiq\Exception\CmdbImportException
 */
final class CmdbWorkbookBoundsScenariosTest extends TestCase {

	/**
	 * Temporary workbooks to remove.
	 *
	 * @var array<int,string>
	 */
	private array $files = [];

	/**
	 * Remove the temporary workbooks.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ($this->files as $file) {
			@unlink($file);
		}

		parent::tearDown();
	}//end tearDown()

	/**
	 * A zip with the given parts.
	 *
	 * @param array<string,string> $parts Part name to content.
	 *
	 * @return string The path.
	 */
	private function workbook(array $parts): string {
		$path = tempnam(sys_get_temp_dir(), 'cmdb').'.xlsx';
		$zip  = new ZipArchive();
		$zip->open($path, ZipArchive::CREATE);
		foreach ($parts as $name => $content) {
			$zip->addFromString($name, $content);
		}

		$zip->close();
		$this->files[] = $path;

		return $path;
	}//end workbook()

	/**
	 * A scanner that passes every part, or refuses the ones containing a marker.
	 *
	 * @param string|null $refuse The marker of parts to refuse.
	 *
	 * @return object The scanner.
	 */
	private function scanner(?string $refuse = null): object {
		return new class ($refuse) {
			public function __construct(private readonly ?string $refuse) {
			}

			public function scan(string $content): string {
				if ($this->refuse !== null && str_contains($content, $this->refuse) === true) {
					throw new \RuntimeException('refused');
				}

				return $content;
			}
		};
	}//end scanner()

	/**
	 * Every non-empty, scanned, well-formed part reaches the consumer with its name, in zip order.
	 *
	 * @return void
	 */
	public function testEachPartHandsOverTheReadableParts(): void {
		$path = $this->workbook(
			[
				'xl/workbook.xml'  => '<workbook/>',
				'xl/empty.xml'     => '',
				'xl/refused.xml'   => '<x>DOCTYPE</x>',
				'xl/sheet1.xml'    => '<worksheet><row/></worksheet>',
			]
		);
		$seen = [];

		(new CmdbWorkbookBounds())->eachPart(
			path: $path,
			scanner: $this->scanner(refuse: 'DOCTYPE'),
			consume: static function (XMLReader $xml, string $name) use (&$seen): void {
				$xml->read();
				$seen[] = [$name, $xml->localName];
			}
		);

		self::assertSame([['xl/workbook.xml', 'workbook'], ['xl/sheet1.xml', 'worksheet']], $seen);
	}//end testEachPartHandsOverTheReadableParts()

	/**
	 * A file that is not a zip hands over nothing.
	 *
	 * @return void
	 */
	public function testANonZipHandsOverNothing(): void {
		$path = tempnam(sys_get_temp_dir(), 'cmdb');
		file_put_contents($path, 'not a zip');
		$this->files[] = $path;
		$called = false;

		(new CmdbWorkbookBounds())->eachPart(
			path: $path,
			scanner: $this->scanner(),
			consume: static function () use (&$called): void {
				$called = true;
			}
		);

		self::assertFalse($called);
	}//end testANonZipHandsOverNothing()

	/**
	 * Shared strings and runs inside them or inline strings count; the limit itself is allowed.
	 *
	 * @return void
	 */
	public function testTheSharedStringCountIncludesRuns(): void {
		$path = $this->workbook(
			[
				'xl/sharedStrings.xml'     => '<sst><si><t>a</t></si><si><r><t>b</t></r><r><t>c</t></r></si><si/></sst>',
				'xl/worksheets/sheet1.xml' => '<worksheet><c t="inlineStr"><is><r><t>d</t></r></is></c></worksheet>',
			]
		);
		$bounds = new CmdbWorkbookBounds();

		// Three <si>, two runs inside one, one run inside an inline string: 6.
		$bounds->assertSharedStringCount(path: $path, limit: 6, scanner: $this->scanner());

		try {
			$bounds->assertSharedStringCount(path: $path, limit: 5, scanner: $this->scanner());
			self::fail('Expected the count to be refused');
		} catch (CmdbImportException $e) {
			self::assertSame(CmdbImportException::WORKBOOK_TOO_LARGE, $e->getErrorCode());
			self::assertSame(['maxSharedStrings' => 5], $e->getDetails());
		}
	}//end testTheSharedStringCountIncludesRuns()

	/**
	 * The referenced shared-string text is held to the limit divided over the sheets.
	 *
	 * Each `<si><t>hello</t></si>` weighs 16 + 16 + 5 = 37; two shared-string
	 * cells reference it, a plain string cell does not count: 74 in total.
	 *
	 * @return void
	 */
	public function testTheReferencedTextIsHeldToThePerSheetBudget(): void {
		$path   = $this->workbook(
			[
				'xl/sharedStrings.xml'     => '<sst><si><t>hello</t></si></sst>',
				'xl/worksheets/sheet1.xml' => '<worksheet><c t="s"><v>0</v></c><c t="s"><v>0</v></c><c t="str"><v>0</v></c></worksheet>',
			]
		);
		$bounds = new CmdbWorkbookBounds();

		$bounds->assertReferencedStringBytes(path: $path, limit: 74, sheetCount: 1, scanner: $this->scanner());
		$bounds->assertReferencedStringBytes(path: $path, limit: 148, sheetCount: 2, scanner: $this->scanner());

		foreach ([[73, 1], [147, 2]] as [$limit, $sheets]) {
			try {
				$bounds->assertReferencedStringBytes(path: $path, limit: $limit, sheetCount: $sheets, scanner: $this->scanner());
				self::fail('Expected the referenced text to be refused');
			} catch (CmdbImportException $e) {
				self::assertSame(['maxReferencedStringBytes' => $limit], $e->getDetails());
			}
		}
	}//end testTheReferencedTextIsHeldToThePerSheetBudget()

	/**
	 * A workbook without shared strings is never refused on referenced text.
	 *
	 * @return void
	 */
	public function testWithoutSharedStringsNothingIsReferenced(): void {
		$path = $this->workbook(['xl/worksheets/sheet1.xml' => '<worksheet><c t="s"><v>0</v></c></worksheet>']);

		(new CmdbWorkbookBounds())->assertReferencedStringBytes(path: $path, limit: 0, sheetCount: 1, scanner: $this->scanner());

		$this->addToAssertionCount(1);
	}//end testWithoutSharedStringsNothingIsReferenced()
}//end class
