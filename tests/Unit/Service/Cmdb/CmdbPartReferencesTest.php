<?php

/**
 * Tests for the CMDB oversized-part check.
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
use OCA\Stackiq\Service\Cmdb\CmdbPartReferences;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * Which oversized workbook parts refuse the import and which are left unread.
 *
 * @covers \OCA\Stackiq\Service\Cmdb\CmdbPartReferences
 * @uses   \OCA\Stackiq\Service\Cmdb\CmdbWorkbookBounds
 * @uses   \OCA\Stackiq\Exception\CmdbImportException
 */
class CmdbPartReferencesTest extends TestCase {
	/**
	 * The workbook written for the test.
	 *
	 * @var string
	 */
	private string $path = '';

	/**
	 * Write a workbook with two sheets, Apps and Notes, and a media part.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->path = (string)tempnam(sys_get_temp_dir(), 'cmdb');
		$zip        = new ZipArchive();
		$zip->open($this->path, ZipArchive::OVERWRITE);
		$zip->addFromString(
			'xl/workbook.xml',
			'<workbook xmlns:r="urn:r"><sheets><sheet name="Apps" sheetId="1" r:id="rId1"/>'
			.'<sheet name="Notes" sheetId="2" r:id="rId2"/><sheet sheetId="3" r:id="rId3"/></sheets></workbook>'
		);
		$zip->addFromString(
			'xl/_rels/workbook.xml.rels',
			'<Relationships>'
			.'<Relationship Id="rId1" Type="http://x/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
			.'<Relationship Id="rId2" Type="http://x/officeDocument/2006/relationships/Worksheet" Target="/xl/worksheets/sheet%202.xml"/>'
			.'<Relationship Id="rId9" Type="http://x/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
			.'</Relationships>'
		);
		$zip->addFromString('xl/worksheets/sheet1.xml', '<worksheet/>');
		$zip->close();
	}

	/**
	 * Remove the workbook.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		@unlink($this->path);
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
	 * Run the check and return the refusal it threw.
	 *
	 * @param array<string, int> $oversized    Part to unpacked size.
	 * @param array<int, string> $sourceSheets Sheets the import reads.
	 *
	 * @return CmdbImportException The refusal.
	 */
	private function refusal(array $oversized, array $sourceSheets): CmdbImportException {
		try {
			(new CmdbPartReferences())->assertPartSizes($this->path, $oversized, $sourceSheets, 100, $this->scanner());
		} catch (CmdbImportException $e) {
			return $e;
		}

		self::fail('Expected the oversized part to refuse the import');
	}

	/**
	 * Nothing oversized means nothing to check, not even the file.
	 *
	 * @return void
	 */
	public function testNoOversizedPartReturnsNothing(): void {
		self::assertSame([], (new CmdbPartReferences())->assertPartSizes('/does/not/exist.xlsx', [], ['Apps'], 100, $this->scanner()));
	}

	/**
	 * An oversized sheet the import does not read is returned to be left blank.
	 *
	 * @return void
	 */
	public function testAnOversizedUnreadSheetIsReturned(): void {
		$blank = (new CmdbPartReferences())->assertPartSizes(
			$this->path,
			['xl/worksheets/sheet 2.xml' => 500],
			['Apps'],
			100,
			$this->scanner()
		);

		self::assertSame(['xl/worksheets/sheet 2.xml'], $blank);
	}

	/**
	 * An oversized sheet the import reads refuses, naming the sheet, matched case- and space-insensitively.
	 *
	 * @return void
	 */
	public function testAnOversizedReadSheetRefusesNamingIt(): void {
		$refusal = $this->refusal(['xl/worksheets/SHEET1.xml' => 500], ['  apps ']);

		self::assertSame(CmdbImportException::WORKBOOK_TOO_LARGE, $refusal->getErrorCode());
		self::assertSame(
			['maxPartBytes' => 100, 'part' => 'xl/worksheets/SHEET1.xml', 'size' => 500, 'sheet' => 'Apps'],
			$refusal->getDetails()
		);
		self::assertSame('A part of the workbook the import reads unpacks to more bytes than the profile allows', $refusal->getMessage());
	}

	/**
	 * A url-encoded target matches its decoded part, and reading that sheet refuses.
	 *
	 * @return void
	 */
	public function testAnEncodedTargetMatchesTheDecodedPart(): void {
		self::assertSame('Notes', $this->refusal(['xl/worksheets/sheet 2.xml' => 500], ['Notes'])->getDetails()['sheet']);
	}

	/**
	 * Relationship and content-type parts, parts no sheet points at, and non-sheet targets always refuse without a sheet.
	 *
	 * @return void
	 */
	public function testPartsOutsideTheSheetsAlwaysRefuse(): void {
		foreach (['xl/_rels/workbook.xml.rels', '[Content_Types].xml', 'xl/media/image1.png', 'xl/styles.xml'] as $part) {
			$details = $this->refusal([$part => 500], [])->getDetails();
			self::assertSame(['maxPartBytes' => 100, 'part' => $part, 'size' => 500], $details, $part);
		}
	}

	/**
	 * The first oversized read part refuses even when an unread one comes before it.
	 *
	 * @return void
	 */
	public function testAnUnreadPartDoesNotHideALaterReadOne(): void {
		$refusal = $this->refusal(['xl/worksheets/sheet 2.xml' => 300, 'xl/worksheets/sheet1.xml' => 400], ['Apps']);

		self::assertSame('xl/worksheets/sheet1.xml', $refusal->getDetails()['part']);
	}
}
