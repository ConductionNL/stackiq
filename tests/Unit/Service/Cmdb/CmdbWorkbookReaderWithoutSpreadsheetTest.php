<?php

/**
 * Tests for the parts of the CMDB workbook reader that run without
 * PhpSpreadsheet: the reader probe, the package checks that run before the
 * reader is needed, and the readable-row bound.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service\Cmdb
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

namespace OCA\Stackiq\Tests\Unit\Service\Cmdb;

require_once __DIR__ . '/../../Support/CmdbTestSupport.php';

use OCA\Stackiq\Exception\CmdbImportException;
use OCA\Stackiq\Service\Cmdb\CmdbImportProfile;
use OCA\Stackiq\Service\Cmdb\CmdbWorkbookReader;
use OCA\Stackiq\Tests\Unit\Support\CmdbTestSupport;
use PHPUnit\Framework\TestCase;

/**
 * The reader's checks that need no spreadsheet library.
 */
class CmdbWorkbookReaderWithoutSpreadsheetTest extends TestCase {

	/**
	 * Files a test wrote, removed afterwards.
	 *
	 * @var array<int, string>
	 */
	private array $files = [];

	/**
	 * Remove the files a test wrote.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ($this->files as $file) {
			@unlink($file);
		}

		$this->files = [];
		parent::tearDown();
	}//end tearDown()

	/**
	 * The shipped import profile, loaded.
	 *
	 * @return CmdbImportProfile
	 */
	private function profile(): CmdbImportProfile {
		CmdbTestSupport::loadMigrationPack();
		$profile = new CmdbImportProfile(container: CmdbTestSupport::emptyContainer(test: $this));
		$profile->load();
		return $profile;
	}//end profile()

	/**
	 * A reader whose PhpSpreadsheet reader class does not exist.
	 *
	 * @return CmdbWorkbookReader
	 */
	private function readerWithoutLibrary(): CmdbWorkbookReader {
		return new class extends CmdbWorkbookReader {
			public const READER_CLASS = 'OCA\Stackiq\Tests\Unit\Service\Cmdb\NoSuchXlsxReader';
		};
	}//end readerWithoutLibrary()

	/**
	 * isAvailable answers whether the configured reader class can be loaded.
	 *
	 * @return void
	 */
	public function testIsAvailableFollowsTheReaderClass(): void {
		$present = new class extends CmdbWorkbookReader {
			public const READER_CLASS = \ArrayObject::class;
		};

		$this->assertTrue($present->isAvailable());
		$this->assertFalse($this->readerWithoutLibrary()->isAvailable());
	}//end testIsAvailableFollowsTheReaderClass()

	/**
	 * A workbook within the size limits reaches the reader probe, and without
	 * PhpSpreadsheet the read is READER_UNAVAILABLE (503).
	 *
	 * @return void
	 */
	public function testAWorkbookWithinTheLimitsIsReaderUnavailableWithoutTheLibrary(): void {
		$path          = CmdbTestSupport::buildWorkbook(sheets: ['Beheerde Applicaties CMDB' => [['APPID', 'Applicatie Naam'], [1, 'App']]]);
		$this->files[] = $path;
		$reader        = $this->readerWithoutLibrary();
		$reader->assertXlsx(path: $path, fileName: 'export.xlsx');

		try {
			$reader->read(path: $path, profile: $this->profile());
			$this->fail('READER_UNAVAILABLE expected');
		} catch (CmdbImportException $e) {
			$this->assertSame(CmdbImportException::READER_UNAVAILABLE, $e->getErrorCode());
			$this->assertSame(503, $e->getHttpStatus());
			$this->assertSame('PhpSpreadsheet Xlsx reader is not available', $e->getMessage());
		}
	}//end testAWorkbookWithinTheLimitsIsReaderUnavailableWithoutTheLibrary()

	/**
	 * A file that is no ZIP package is NOT_XLSX before the reader is probed.
	 *
	 * @return void
	 */
	public function testAFileThatIsNoPackageIsNotXlsx(): void {
		$path          = tempnam(sys_get_temp_dir(), 'stackiq-not-zip-');
		$this->files[] = $path;
		file_put_contents($path, 'APPID;Applicatie Naam');

		try {
			$this->readerWithoutLibrary()->read(path: $path, profile: $this->profile());
			$this->fail('NOT_XLSX expected');
		} catch (CmdbImportException $e) {
			$this->assertSame(CmdbImportException::NOT_XLSX, $e->getErrorCode());
			$this->assertSame(400, $e->getHttpStatus());
			$this->assertSame('The ZIP package cannot be opened', $e->getMessage());
		}
	}//end testAFileThatIsNoPackageIsNotXlsx()

	/**
	 * assertXlsx refuses a wrong extension, a non-ZIP file and a ZIP without a workbook.
	 *
	 * @return void
	 */
	public function testAssertXlsxNamesWhatIsWrong(): void {
		$text          = tempnam(sys_get_temp_dir(), 'stackiq-text-');
		$this->files[] = $text;
		file_put_contents($text, 'plain text');

		$zip           = tempnam(sys_get_temp_dir(), 'stackiq-zip-') . '.zip';
		$this->files[] = $zip;
		$archive       = new \ZipArchive();
		$archive->open($zip, \ZipArchive::CREATE);
		$archive->addFromString('readme.txt', 'no workbook here');
		$archive->close();

		$cases = [
			[$text, 'export.csv', 'The file name does not end in .xlsx'],
			[$text, 'export.XLSX', 'The file is not a ZIP package'],
			[$zip, 'export.xlsx', 'The package holds no xl/workbook.xml'],
		];
		foreach ($cases as [$path, $name, $message]) {
			try {
				(new CmdbWorkbookReader())->assertXlsx(path: $path, fileName: $name);
				$this->fail('NOT_XLSX expected for ' . $name);
			} catch (CmdbImportException $e) {
				$this->assertSame(CmdbImportException::NOT_XLSX, $e->getErrorCode());
				$this->assertSame($message, $e->getMessage());
			}
		}
	}//end testAssertXlsxNamesWhatIsWrong()

	/**
	 * The last readable row leaves room for one empty row per data row, after the header.
	 *
	 * @return void
	 */
	public function testLastReadableRowIsTwiceTheLimitPlusTheHeader(): void {
		$this->assertSame(1, CmdbWorkbookReader::lastReadableRow(limit: 0));
		$this->assertSame(1, CmdbWorkbookReader::lastReadableRow(limit: -5));
		$this->assertSame(3, CmdbWorkbookReader::lastReadableRow(limit: 1));
		$this->assertSame(10001, CmdbWorkbookReader::lastReadableRow(limit: 5000));
	}//end testLastReadableRowIsTwiceTheLimitPlusTheHeader()
}//end class
