<?php

/**
 * Tests for the CMDB workbook reader's sheet, column and row logic, on an in-memory reader.
 *
 * CmdbWorkbookReaderTest reads real xlsx files and needs PhpSpreadsheet,
 * so it skips where OpenRegister's vendor/ is absent. These tests point
 * READER_CLASS at FakeXlsxReader instead, so the header resolution, row
 * reading, formula handling, archive sheet and every refusal after the ZIP
 * bounds run on every machine.
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
require_once __DIR__ . '/../../Support/FakeXlsxReader.php';

use OCA\Stackiq\Exception\CmdbImportException;
use OCA\Stackiq\Service\Cmdb\CmdbImportProfile;
use OCA\Stackiq\Service\Cmdb\CmdbWorkbookReader;
use OCA\Stackiq\Tests\Unit\Support\CmdbTestSupport;
use OCA\Stackiq\Tests\Unit\Support\FakeXlsxReader;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The reader with READER_CLASS pointed at the in-memory reader.
 */
class FakeBackedWorkbookReader extends CmdbWorkbookReader {
	public const READER_CLASS = FakeXlsxReader::class;
}//end class

/**
 * Drives CmdbWorkbookReader::read() through FakeXlsxReader.
 *
 * @covers \OCA\Stackiq\Service\Cmdb\CmdbWorkbookReader
 * @uses   \OCA\Stackiq\Exception\CmdbImportException
 * @uses   \OCA\Stackiq\Service\Cmdb\CmdbImportPackRules
 * @uses   \OCA\Stackiq\Service\Cmdb\CmdbImportPackStore
 * @uses   \OCA\Stackiq\Service\Cmdb\CmdbImportPackStoreProbe
 * @uses   \OCA\Stackiq\Service\Cmdb\CmdbImportProfile
 * @uses   \OCA\Stackiq\Service\Cmdb\CmdbPackLoadRun
 * @uses   \OCA\Stackiq\Service\Cmdb\CmdbPackOrigin
 * @uses   \OCA\Stackiq\Service\Cmdb\CmdbPartReferences
 * @uses   \OCA\Stackiq\Service\Cmdb\CmdbReadFilter
 * @uses   \OCA\Stackiq\Service\Cmdb\CmdbRegisterSchemaFields
 * @uses   \OCA\Stackiq\Service\Cmdb\CmdbWorkbookBounds
 * @uses   \OCA\Stackiq\Service\Cmdb\OpenRegisterInstance
 */
class CmdbWorkbookReaderLogicTest extends TestCase {
	private const MANAGED = 'Beheerde Applicaties CMDB';
	private const UNMANAGED = 'Onbeh Applicaties CMDB';
	private const ARCHIVE = 'Gearchiveerde Applicaties';

	/**
	 * Files and directories to remove after the test.
	 *
	 * @var array<int, string>
	 */
	private array $cleanup = [];

	/**
	 * Reset the in-memory reader.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		FakeXlsxReader::reset();
	}//end setUp()

	/**
	 * Remove the packages and profile directories.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ($this->cleanup as $path) {
			if (is_dir($path) === true) {
				CmdbTestSupport::removeDirectory(directory: $path);
			} else if (is_file($path) === true) {
				unlink($path);
			}
		}

		FakeXlsxReader::reset();
		parent::tearDown();
	}//end tearDown()

	/**
	 * The shipped profile, or one with overridden keys.
	 *
	 * @param array<string, mixed> $overrides Profile key => value.
	 *
	 * @return CmdbImportProfile
	 */
	private function profile(array $overrides = []): CmdbImportProfile {
		CmdbTestSupport::loadMigrationPack();
		$directory = null;
		if ($overrides !== []) {
			$directory = CmdbTestSupport::profileDirectory(overrides: $overrides);
			$this->cleanup[] = $directory;
		}

		$profile = new CmdbImportProfile(container: CmdbTestSupport::emptyContainer(test: $this), directory: $directory);
		$profile->load();
		return $profile;
	}//end profile()

	/**
	 * A small real package, so the ZIP bounds pass; its cells are never read by the fake reader.
	 *
	 * @param array<string, array<int, array<int, mixed>>> $sheets Sheet name => rows.
	 *
	 * @return string
	 */
	private function package(array $sheets = [self::MANAGED => [['APPID', 'Applicatie Naam']]]): string {
		$path = CmdbTestSupport::buildWorkbook(sheets: $sheets);
		$this->cleanup[] = $path;
		return $path;
	}//end package()

	/**
	 * Read the package through the in-memory reader.
	 *
	 * @param CmdbImportProfile|null $profile The profile, the shipped one when null.
	 * @param string|null $path The package, a small one when null.
	 *
	 * @return array<string, mixed>
	 */
	private function read(?CmdbImportProfile $profile = null, ?string $path = null): array {
		return (new FakeBackedWorkbookReader())->read(path: ($path ?? $this->package()), profile: ($profile ?? $this->profile()));
	}//end read()

	/**
	 * Read, expecting a refusal.
	 *
	 * @param CmdbImportProfile|null $profile The profile, the shipped one when null.
	 *
	 * @return CmdbImportException
	 */
	private function refusal(?CmdbImportProfile $profile = null): CmdbImportException {
		try {
			$this->read(profile: $profile);
		} catch (CmdbImportException $e) {
			return $e;
		}

		$this->fail('CmdbImportException expected');
	}//end refusal()

	/**
	 * A header row and data rows, columns from A.
	 *
	 * @param array<int, mixed> $header The header cells.
	 * @param array<int, array<int, mixed>> $rows The data rows, row 2 first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function sheet(array $header, array $rows = []): array {
		$sheet = [];
		foreach (array_merge([$header], $rows) as $index => $cells) {
			foreach (array_values($cells) as $column => $value) {
				if ($value !== null) {
					$sheet[$index + 1][chr(65 + $column)] = $value;
				}
			}
		}

		return $sheet;
	}//end sheet()

	/**
	 * The fake reader counts as available.
	 *
	 * @return void
	 */
	public function testTheConfiguredReaderClassIsAvailable(): void {
		$this->assertTrue((new FakeBackedWorkbookReader())->isAvailable());
	}//end testTheConfiguredReaderClassIsAvailable()

	/**
	 * Rows of every present sheet come back in sheet order, keyed by column name, with empty rows dropped.
	 *
	 * @return void
	 */
	public function testRowsAreKeyedByColumnNameInSheetOrder(): void {
		FakeXlsxReader::$sheets = [
			self::MANAGED => self::sheet(['APPID', 'Applicatie Naam', 'Vendor'], [['A-2', 'Twee', 'Acme'], [null, '   ', null], [7, 'Zeven', null]]),
			self::UNMANAGED => self::sheet(['APPID', 'Applicatie Naam'], [['U-1', 'Een']]),
		];

		$result = $this->read();

		$this->assertSame(
			[
				['sheet' => self::UNMANAGED, 'row' => 2, 'cells' => ['APPID' => 'U-1', 'Applicatie Naam' => 'Een'], 'uncached' => []],
				['sheet' => self::MANAGED, 'row' => 2, 'cells' => ['APPID' => 'A-2', 'Applicatie Naam' => 'Twee', 'Vendor' => 'Acme'], 'uncached' => []],
				['sheet' => self::MANAGED, 'row' => 4, 'cells' => ['APPID' => 7, 'Applicatie Naam' => 'Zeven', 'Vendor' => null], 'uncached' => []],
			],
			$result['rows']
		);
		$this->assertSame([], $result['absentSheets']);
		$this->assertSame([], $result['uncachedKeyRows']);
		$this->assertFalse($result['date1904']);
		$this->assertSame(
			['sheet' => self::ARCHIVE, 'present' => false, 'keyColumnMissing' => false, 'appIds' => [], 'uncachedRows' => []],
			$result['archive']
		);
	}//end testRowsAreKeyedByColumnNameInSheetOrder()

	/**
	 * Headers match after normalisation, the first matching column wins, and unknown or non-scalar headers are ignored.
	 *
	 * Column AA proves the letters round-trip beyond Z.
	 *
	 * @return void
	 */
	public function testHeadersResolveByNormalisedNameAndFirstMatchWins(): void {
		FakeXlsxReader::$sheets = [
			self::MANAGED => [
				1 => ['A' => ' appid: ', 'B' => 'Onbekend', 'C' => 'APPID', 'D' => new \stdClass(), 'AA' => 'applicatie  naam⚡'],
				2 => ['A' => 'K-1', 'B' => 'niet gelezen', 'C' => 'tweede', 'D' => 'x', 'AA' => 'Naam'],
			],
		];

		$result = $this->read();

		$this->assertSame([['sheet' => self::MANAGED, 'row' => 2, 'cells' => ['APPID' => 'K-1', 'Applicatie Naam' => 'Naam'], 'uncached' => []]], $result['rows']);
	}//end testHeadersResolveByNormalisedNameAndFirstMatchWins()

	/**
	 * A pack column the sheet lacks is a warning, except required columns and the sheet's known-absent columns.
	 *
	 * @return void
	 */
	public function testMissingOptionalColumnsAreWarnedPerSheet(): void {
		FakeXlsxReader::$sheets = [self::MANAGED => self::sheet(['APPID', 'Applicatie Naam'], [['A-1', 'Een']])];

		$warnings = $this->read()['importWarnings'];

		$this->assertNotSame([], $warnings);
		$columns = array_column($warnings, 'column');
		$this->assertContains('Vendor', $columns);
		$this->assertNotContains('APPID', $columns);
		$this->assertNotContains('Applicatie Naam', $columns);
		$this->assertNotContains('Applicatie Eigenaar (E-mail)', $columns);
		$this->assertNotContains('Beheer', $columns);
		$this->assertSame(count($columns), count(array_unique($columns)));
		foreach ($warnings as $warning) {
			$this->assertSame(self::MANAGED, $warning['sheet']);
			$this->assertSame(sprintf('Optional column "%s" not found', $warning['column']), $warning['message']);
		}
	}//end testMissingOptionalColumnsAreWarnedPerSheet()

	/**
	 * A formula yields its cached value; a cached 0 and a missing cache yield null, the latter listed as uncached.
	 *
	 * @return void
	 */
	public function testFormulasYieldTheirCachedValue(): void {
		FakeXlsxReader::$sheets = [
			self::MANAGED => self::sheet(
				['APPID', 'Applicatie Naam', 'Vendor', 'Cluster'],
				[[['f' => 'F-1'], ['f' => 0], ['f' => null], ['f' => 2.5]]]
			),
		];

		$rows = $this->read()['rows'];

		$this->assertSame(
			[['sheet' => self::MANAGED, 'row' => 2, 'cells' => ['APPID' => 'F-1', 'Applicatie Naam' => null, 'Vendor' => null, 'Cluster' => 2.5], 'uncached' => ['Vendor']]],
			$rows
		);
	}//end testFormulasYieldTheirCachedValue()

	/**
	 * A rich-text value reads as its plain text; any other object reads as null.
	 *
	 * @return void
	 */
	public function testRichTextReadsAsPlainTextAndOtherObjectsAsNull(): void {
		$rich = new class {
			/**
			 * The text.
			 *
			 * @return string
			 */
			public function getPlainText(): string {
				return 'Rijke tekst';
			}//end getPlainText()
		};
		FakeXlsxReader::$sheets = [self::MANAGED => self::sheet(['APPID', 'Applicatie Naam', 'Vendor'], [['R-1', $rich, new \stdClass()]])];

		$this->assertSame(['APPID' => 'R-1', 'Applicatie Naam' => 'Rijke tekst', 'Vendor' => null], $this->read()['rows'][0]['cells']);
	}//end testRichTextReadsAsPlainTextAndOtherObjectsAsNull()

	/**
	 * A row empty only because its key formula has no cached value is listed per sheet; one with another uncached formula is not.
	 *
	 * @return void
	 */
	public function testAnEmptyRowWithAnUncachedKeyIsListed(): void {
		FakeXlsxReader::$sheets = [
			self::MANAGED => self::sheet(
				['APPID', 'Applicatie Naam'],
				[[['f' => null], null], ['M-3', 'Drie'], [null, ['f' => null]], [['f' => null], ['f' => 0]]]
			),
		];

		$result = $this->read();

		$this->assertSame([self::MANAGED => [2, 5]], $result['uncachedKeyRows']);
		$this->assertCount(1, $result['rows']);
		$this->assertSame(3, $result['rows'][0]['row']);
	}//end testAnEmptyRowWithAnUncachedKeyIsListed()

	/**
	 * The archive sheet yields its raw key values in order and lists rows whose key is an uncached formula.
	 *
	 * @return void
	 */
	public function testTheArchiveSheetYieldsRawAppIdsForItsKeyOnly(): void {
		FakeXlsxReader::$sheets = [
			self::MANAGED => self::sheet(['APPID', 'Applicatie Naam'], [['M-1', 'Een']]),
			self::ARCHIVE => self::sheet(['Vendor', 'APPID', 'Applicatie Naam'], [['X', ' A-9 ', 'Negen'], ['Y', ['f' => null], 'Tien'], ['Z', 'A-1', 'Elf']]),
		];

		$result = $this->read();

		$this->assertSame(
			['sheet' => self::ARCHIVE, 'present' => true, 'keyColumnMissing' => false, 'appIds' => [' A-9 ', 'A-1'], 'uncachedRows' => [3]],
			$result['archive']
		);
		$this->assertCount(1, $result['rows']);
		$this->assertSame([self::MANAGED, self::ARCHIVE], FakeXlsxReader::$loads[0]['sheets']);
		$this->assertSame([self::MANAGED, self::ARCHIVE], FakeXlsxReader::$loads[1]['sheets']);
	}//end testTheArchiveSheetYieldsRawAppIdsForItsKeyOnly()

	/**
	 * An archive sheet without the key column is reported and left out of the data pass.
	 *
	 * @return void
	 */
	public function testAnArchiveSheetWithoutKeyColumnIsNotRead(): void {
		FakeXlsxReader::$sheets = [
			self::MANAGED => self::sheet(['APPID', 'Applicatie Naam'], [['M-1', 'Een']]),
			self::ARCHIVE => self::sheet(['Applicatie Naam'], [['Weg']]),
		];

		$result = $this->read();

		$this->assertSame(
			['sheet' => self::ARCHIVE, 'present' => false, 'keyColumnMissing' => true, 'appIds' => [], 'uncachedRows' => []],
			$result['archive']
		);
		$this->assertSame([self::MANAGED, self::ARCHIVE], FakeXlsxReader::$loads[0]['sheets']);
		$this->assertSame([self::MANAGED], FakeXlsxReader::$loads[1]['sheets']);
	}//end testAnArchiveSheetWithoutKeyColumnIsNotRead()

	/**
	 * Both loads are data-only without empty cells; a 1904 workbook is reported as such.
	 *
	 * @return void
	 */
	public function testLoadsAreDataOnlyAndThe1904CalendarIsReported(): void {
		FakeXlsxReader::$sheets = [self::MANAGED => self::sheet(['APPID', 'Applicatie Naam'], [['M-1', 'Een']])];
		FakeXlsxReader::$calendar = 1904;

		$result = $this->read();

		$this->assertTrue($result['date1904']);
		$this->assertCount(2, FakeXlsxReader::$loads);
		foreach (FakeXlsxReader::$loads as $load) {
			$this->assertTrue($load['readDataOnly']);
			$this->assertFalse($load['readEmptyCells']);
			$this->assertSame([self::MANAGED], $load['sheets']);
		}
	}//end testLoadsAreDataOnlyAndThe1904CalendarIsReported()

	/**
	 * A workbook with some source sheets names the absent ones.
	 *
	 * @return void
	 */
	public function testAbsentSourceSheetsAreNamed(): void {
		FakeXlsxReader::$sheets = ['Overig' => self::sheet(['x']), self::MANAGED => self::sheet(['APPID', 'Applicatie Naam'], [['M-1', 'Een']])];

		$this->assertSame([self::UNMANAGED], $this->read()['absentSheets']);
	}//end testAbsentSourceSheetsAreNamed()

	/**
	 * A workbook without any source sheet is refused with the expected names, before anything is loaded.
	 *
	 * @return void
	 */
	public function testAWorkbookWithoutSourceSheetsIsRefused(): void {
		FakeXlsxReader::$sheets = ['Overig' => self::sheet(['APPID', 'Applicatie Naam'])];

		$e = $this->refusal();

		$this->assertSame(CmdbImportException::NO_SOURCE_SHEET, $e->getErrorCode());
		$this->assertSame(['expected' => [self::UNMANAGED, self::MANAGED]], $e->getDetails());
		$this->assertSame([], FakeXlsxReader::$loads);
	}//end testAWorkbookWithoutSourceSheetsIsRefused()

	/**
	 * A sheet lacking a required column is refused by name after the header pass only.
	 *
	 * @return void
	 */
	public function testAMissingRequiredColumnIsRefusedAfterTheHeaderPass(): void {
		FakeXlsxReader::$sheets = [
			self::UNMANAGED => self::sheet(['APPID', 'Applicatie Naam'], [['U-1', 'Een']]),
			self::MANAGED => self::sheet(['APPID', 'Vendor'], [['M-1', 'Acme']]),
		];

		$e = $this->refusal();

		$this->assertSame(CmdbImportException::MISSING_COLUMN, $e->getErrorCode());
		$this->assertSame(['sheet' => self::MANAGED, 'column' => 'Applicatie Naam'], $e->getDetails());
		$this->assertCount(1, FakeXlsxReader::$loads);
	}//end testAMissingRequiredColumnIsRefusedAfterTheHeaderPass()

	/**
	 * A sheet list that cannot be read is NOT_XLSX, naming the failure's class and keeping it.
	 *
	 * @return void
	 */
	public function testAnUnreadableSheetListIsNotXlsx(): void {
		FakeXlsxReader::$listFailure = new RuntimeException('broken');

		$e = $this->refusal();

		$this->assertSame(CmdbImportException::NOT_XLSX, $e->getErrorCode());
		$this->assertSame('The workbook cannot be read: RuntimeException', $e->getMessage());
		$this->assertSame(FakeXlsxReader::$listFailure, $e->getPrevious());
	}//end testAnUnreadableSheetListIsNotXlsx()

	/**
	 * Worksheet info that cannot be read is NOT_XLSX.
	 *
	 * @return void
	 */
	public function testUnreadableWorksheetInfoIsNotXlsx(): void {
		FakeXlsxReader::$sheets = [self::MANAGED => self::sheet(['APPID', 'Applicatie Naam'])];
		FakeXlsxReader::$infoFailure = new RuntimeException('broken');

		$e = $this->refusal();

		$this->assertSame(CmdbImportException::NOT_XLSX, $e->getErrorCode());
		$this->assertSame('The workbook cannot be read: RuntimeException', $e->getMessage());
		$this->assertSame([], FakeXlsxReader::$loads);
	}//end testUnreadableWorksheetInfoIsNotXlsx()

	/**
	 * A workbook that fails to load is NOT_XLSX.
	 *
	 * @return void
	 */
	public function testAWorkbookThatFailsToLoadIsNotXlsx(): void {
		FakeXlsxReader::$sheets = [self::MANAGED => self::sheet(['APPID', 'Applicatie Naam'])];
		FakeXlsxReader::$loadFailure = new RuntimeException('broken');

		$e = $this->refusal();

		$this->assertSame(CmdbImportException::NOT_XLSX, $e->getErrorCode());
		$this->assertSame('The workbook cannot be loaded: RuntimeException', $e->getMessage());
		$this->assertSame(FakeXlsxReader::$loadFailure, $e->getPrevious());
	}//end testAWorkbookThatFailsToLoadIsNotXlsx()

	/**
	 * A source sheet whose used range ends beyond twice the limit plus one is refused before loading;
	 * an unrelated sheet that long is not.
	 *
	 * @return void
	 */
	public function testARowSpanBeyondTheReadableRowsIsRefusedBeforeLoading(): void {
		FakeXlsxReader::$sheets = ['Overig' => self::sheet(['x']), self::MANAGED => self::sheet(['APPID', 'Applicatie Naam'])];
		FakeXlsxReader::$totalRows = ['Overig' => 100, self::MANAGED => 6];

		$e = $this->refusal(profile: $this->profile(overrides: ['maxRowsPerSheet' => 2]));

		$this->assertSame(CmdbImportException::TOO_MANY_ROWS, $e->getErrorCode());
		$this->assertSame(['sheet' => self::MANAGED, 'limit' => 2], $e->getDetails());
		$this->assertSame([], FakeXlsxReader::$loads);
	}//end testARowSpanBeyondTheReadableRowsIsRefusedBeforeLoading()

	/**
	 * Within the readable span, one non-empty row over the limit is refused; empty rows do not count.
	 *
	 * @return void
	 */
	public function testOneRowOverTheLimitIsRefusedButEmptyRowsDoNotCount(): void {
		FakeXlsxReader::$sheets = [self::MANAGED => self::sheet(['APPID', 'Applicatie Naam'], [['M-1', 'Een'], [null, null], ['M-2', 'Twee']])];
		$this->assertCount(2, $this->read(profile: $this->profile(overrides: ['maxRowsPerSheet' => 2]))['rows']);

		FakeXlsxReader::$sheets = [self::MANAGED => self::sheet(['APPID', 'Applicatie Naam'], [['M-1', 'Een'], ['M-2', 'Twee']])];
		$e = $this->refusal(profile: $this->profile(overrides: ['maxRowsPerSheet' => 1]));

		$this->assertSame(CmdbImportException::TOO_MANY_ROWS, $e->getErrorCode());
		$this->assertSame(['sheet' => self::MANAGED, 'limit' => 1], $e->getDetails());
	}//end testOneRowOverTheLimitIsRefusedButEmptyRowsDoNotCount()

	/**
	 * An oversized part only an unread sheet uses is blanked in a temporary copy, which is read and then removed.
	 *
	 * @return void
	 */
	public function testAnOversizedUnreadSheetIsReadFromABlankedCopy(): void {
		$rows = [['x']];
		for ($index = 0; $index < 3000; $index++) {
			$rows[] = ['regel ' . $index];
		}

		$path = $this->package(sheets: ['Relatie APP oplosgroepen' => $rows, self::MANAGED => [['APPID', 'Applicatie Naam'], ['M-1', 'Een']]]);
		FakeXlsxReader::$sheets = [self::MANAGED => self::sheet(['APPID', 'Applicatie Naam'], [['M-1', 'Een']])];

		$result = $this->read(profile: $this->profile(overrides: ['maxPartBytes' => 50000, 'maxSharedStrings' => 1000000]), path: $path);

		$this->assertCount(1, $result['rows']);
		$this->assertCount(2, FakeXlsxReader::$loads);
		$copy = FakeXlsxReader::$loads[0]['path'];
		$this->assertNotSame($path, $copy);
		$this->assertStringEndsWith('.xlsx', $copy);
		$this->assertTrue(FakeXlsxReader::$loads[0]['exists']);
		$this->assertSame($copy, FakeXlsxReader::$loads[1]['path']);
		$this->assertFileDoesNotExist($copy);
		$this->assertFileExists($path);
	}//end testAnOversizedUnreadSheetIsReadFromABlankedCopy()

	/**
	 * Without an oversized part the original file is read directly.
	 *
	 * @return void
	 */
	public function testAPackageWithinThePartLimitIsReadInPlace(): void {
		$path = $this->package();
		FakeXlsxReader::$sheets = [self::MANAGED => self::sheet(['APPID', 'Applicatie Naam'], [['M-1', 'Een']])];

		$this->read(path: $path);

		$this->assertSame($path, FakeXlsxReader::$loads[0]['path']);
		$this->assertSame($path, FakeXlsxReader::$loads[1]['path']);
	}//end testAPackageWithinThePartLimitIsReadInPlace()

	/**
	 * A package that unpacks beyond maxUncompressedBytes is refused before the reader lists a sheet.
	 *
	 * @return void
	 */
	public function testAPackageBeyondTheUnpackedLimitIsRefusedFirst(): void {
		FakeXlsxReader::$sheets = [self::MANAGED => self::sheet(['APPID', 'Applicatie Naam'])];
		FakeXlsxReader::$listFailure = new RuntimeException('must not be reached');

		$e = $this->refusal(profile: $this->profile(overrides: ['maxUncompressedBytes' => 100]));

		$this->assertSame(CmdbImportException::WORKBOOK_TOO_LARGE, $e->getErrorCode());
		$this->assertSame(['maxUncompressedBytes' => 100], $e->getDetails());
		$this->assertSame([], FakeXlsxReader::$loads);
	}//end testAPackageBeyondTheUnpackedLimitIsRefusedFirst()

	/**
	 * The last readable row is twice the limit plus the header row, never below the header.
	 *
	 * @return void
	 */
	public function testTheLastReadableRow(): void {
		$this->assertSame(21, CmdbWorkbookReader::lastReadableRow(limit: 10));
		$this->assertSame(1, CmdbWorkbookReader::lastReadableRow(limit: 0));
		$this->assertSame(1, CmdbWorkbookReader::lastReadableRow(limit: -5));
	}//end testTheLastReadableRow()
}//end class
