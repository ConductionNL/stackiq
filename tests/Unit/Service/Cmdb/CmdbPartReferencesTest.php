<?php

/**
 * Unit tests for CmdbPartReferences: an oversized part the import reads is refused, naming the sheet when a read worksheet points at it, and an oversized part only unread worksheets point at is handed back to be blanked.
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
use OCA\Stackiq\Service\Cmdb\CmdbPartReferences;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * Tests for CmdbPartReferences.
 *
 * @covers \OCA\Stackiq\Service\Cmdb\CmdbPartReferences
 * @uses   \OCA\Stackiq\Service\Cmdb\CmdbWorkbookBounds
 * @uses   \OCA\Stackiq\Exception\CmdbImportException
 */
final class CmdbPartReferencesTest extends TestCase {

	/**
	 * The workbook under test.
	 *
	 * @var string
	 */
	private string $path;

	/**
	 * Build a workbook with two worksheets, Applicaties and Archief.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->path = tempnam(sys_get_temp_dir(), 'cmdb').'.xlsx';
		$zip        = new ZipArchive();
		$zip->open($this->path, ZipArchive::CREATE);
		$zip->addFromString(
			'xl/workbook.xml',
			'<workbook xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'
			.'<sheet name="Applicaties" sheetId="1" r:id="rId1"/><sheet name="Archief" sheetId="2" r:id="rId2"/>'
			.'</sheets></workbook>'
		);
		$zip->addFromString(
			'xl/_rels/workbook.xml.rels',
			'<Relationships>'
			.'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
			.'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>'
			.'<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>'
			.'</Relationships>'
		);
		$zip->addFromString('xl/worksheets/sheet1.xml', '<worksheet/>');
		$zip->addFromString('xl/worksheets/sheet2.xml', '<worksheet/>');
		$zip->close();
	}//end setUp()

	/**
	 * Remove the workbook.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		@unlink($this->path);
		parent::tearDown();
	}//end tearDown()

	/**
	 * A pass-through scanner.
	 *
	 * @return object The scanner.
	 */
	private function scanner(): object {
		return new class {
			public function scan(string $content): string {
				return $content;
			}
		};
	}//end scanner()

	/**
	 * The size assertion, returning the refusal when there is one.
	 *
	 * @param array<string,int> $oversized    The oversized parts.
	 * @param array<int,string> $sourceSheets The sheets the import reads.
	 *
	 * @return array<int,string>|CmdbImportException The parts to blank, or the refusal.
	 */
	private function assess(array $oversized, array $sourceSheets = ['Applicaties']): array|CmdbImportException {
		try {
			return (new CmdbPartReferences())->assertPartSizes(
				path: $this->path,
				oversized: $oversized,
				sourceSheets: $sourceSheets,
				limit: 1000,
				scanner: $this->scanner()
			);
		} catch (CmdbImportException $e) {
			return $e;
		}
	}//end assess()

	/**
	 * Without oversized parts nothing is read and nothing is blanked.
	 *
	 * @return void
	 */
	public function testNoOversizedPartsReadsNothing(): void {
		$scanner = new class {
			public function scan(string $content): string {
				throw new \LogicException('must not scan');
			}
		};

		self::assertSame(
			[],
			(new CmdbPartReferences())->assertPartSizes(
				path: $this->path,
				oversized: [],
				sourceSheets: [],
				limit: 1,
				scanner: $scanner
			)
		);
	}//end testNoOversizedPartsReadsNothing()

	/**
	 * A part only an unread worksheet points at is handed back to be blanked.
	 *
	 * @return void
	 */
	public function testAPartOfAnUnreadSheetIsBlanked(): void {
		self::assertSame(['xl/worksheets/sheet2.xml'], $this->assess(['xl/worksheets/sheet2.xml' => 5000]));
	}//end testAPartOfAnUnreadSheetIsBlanked()

	/**
	 * A part a read worksheet points at is refused, naming the sheet; the source sheet matches in any case.
	 *
	 * @return void
	 */
	public function testAPartOfAReadSheetIsRefusedNamingIt(): void {
		$refusal = $this->assess(['xl/worksheets/sheet1.xml' => 5000], [' applicaties ']);

		self::assertInstanceOf(CmdbImportException::class, $refusal);
		self::assertSame(CmdbImportException::WORKBOOK_TOO_LARGE, $refusal->getErrorCode());
		self::assertSame(
			['maxPartBytes' => 1000, 'part' => 'xl/worksheets/sheet1.xml', 'size' => 5000, 'sheet' => 'Applicaties'],
			$refusal->getDetails()
		);
	}//end testAPartOfAReadSheetIsRefusedNamingIt()

	/**
	 * Relationship parts and parts no worksheet owns are always refused, without a sheet.
	 *
	 * @return void
	 */
	public function testPartsOutsideTheWorksheetsAreRefused(): void {
		foreach (['xl/_rels/workbook.xml.rels', '[Content_Types].xml', 'xl/sharedStrings.xml'] as $part) {
			$refusal = $this->assess([$part => 2000]);

			self::assertInstanceOf(CmdbImportException::class, $refusal, $part);
			self::assertSame(['maxPartBytes' => 1000, 'part' => $part, 'size' => 2000], $refusal->getDetails());
		}
	}//end testPartsOutsideTheWorksheetsAreRefused()

	/**
	 * The unread parts are collected until the first part that must be refused.
	 *
	 * @return void
	 */
	public function testTheFirstReadPartStopsTheCheck(): void {
		$refusal = $this->assess(['xl/worksheets/sheet2.xml' => 5000, 'xl/worksheets/sheet1.xml' => 5000]);

		self::assertInstanceOf(CmdbImportException::class, $refusal);
		self::assertSame('Applicaties', $refusal->getDetails()['sheet']);
	}//end testTheFirstReadPartStopsTheCheck()
}//end class
