<?php

/**
 * Tests for the CMDB workbook reader, on the sanitised xlsx fixtures.
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
use OCA\Stackiq\Tests\Unit\Support\RecordingXlsxReader;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Reads the fixtures through PhpSpreadsheet as OpenRegister ships it.
 */
class CmdbWorkbookReaderTest extends TestCase {
	/**
	 * The shipped profile, validated with OpenRegister's validator.
	 *
	 * @param string|null $directory A profile directory other than the shipped one.
	 *
	 * @return CmdbImportProfile
	 */
	private function profile(?string $directory = null): CmdbImportProfile {
		CmdbTestSupport::loadMigrationPack();
		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->willReturn(false);

		$profile = new CmdbImportProfile(container: $container, directory: $directory);
		$profile->load();
		return $profile;
	}//end profile()

	/**
	 * Skip unless PhpSpreadsheet can be loaded from an OpenRegister checkout.
	 *
	 * @return void
	 */
	private function requireSpreadsheet(): void {
		if (CmdbTestSupport::loadPhpSpreadsheet() === false) {
			$this->markTestSkipped('PhpSpreadsheet not found: set OPENREGISTER_DIR to an OpenRegister app with its vendor/ installed.');
		}
	}//end requireSpreadsheet()

	/**
	 * Read a fixture.
	 *
	 * @param string $name The fixture file.
	 *
	 * @return array<string, mixed>
	 */
	private function read(string $name): array {
		$this->requireSpreadsheet();
		$path = CmdbTestSupport::fixtures() . '/' . $name;
		$reader = new CmdbWorkbookReader();
		$reader->assertXlsx(path: $path, fileName: $name);
		return $reader->read(path: $path, profile: $this->profile());
	}//end read()

	/**
	 * One data row per CMDB sheet, read from the cached formula values; the formatted
	 * empty rows and the rows whose formulas cached 0 are dropped; only allowlisted columns.
	 *
	 * @return void
	 */
	public function testTheSanitisedExportYieldsOneRowPerSheet(): void {
		$result = $this->read(name: 'topdesk-export-anonymised.xlsx');
		$rows = $result['rows'];

		$this->assertCount(2, $rows);
		$this->assertSame(['Onbeh Applicaties CMDB', 2], [$rows[0]['sheet'], $rows[0]['row']]);
		$this->assertSame(['Beheerde Applicaties CMDB', 2], [$rows[1]['sheet'], $rows[1]['row']]);
		$this->assertSame(1234, (int)$rows[0]['cells']['APPID']);
		$this->assertSame('AIA-AangetekendMailen', $rows[0]['cells']['Applicatie Code']);
		$this->assertSame('Aangetekend Mailen', $rows[0]['cells']['Applicatie Naam']);
		$this->assertSame('Mailen', $rows[0]['cells']['Roepnaam']);
		$this->assertSame('Webapplicatie', $rows[0]['cells']['Applicatiesoort']);
		$this->assertSame('Achternaam, Voornaam', $rows[0]['cells']['Applicatie Eigenaar (Persoon)']);
		$this->assertArrayNotHasKey('Nickname', $rows[0]['cells'], 'Onbeh has no Nickname column');
		$this->assertSame('naamtest123', $rows[1]['cells']['Applicatie Naam']);
		$this->assertSame(2, (int)$rows[1]['cells']['APPID']);
		$this->assertSame(53359, (int)$rows[1]['cells']['End-of-Life Functioneel']);
		$this->assertSame('Saas', $rows[1]['cells']['Applicatiesoort']);
		$this->assertSame('BBN2', $rows[1]['cells']['BNN Classificatie']);
		$this->assertSame('Tolereren', $rows[1]['cells']['Classificatie']);
		$this->assertSame('NT123', $rows[1]['cells']['Nickname']);
		$this->assertSame('Teamleider Applicatiebeheer', $rows[1]['cells']['Applicatie Eigenaar (Persoon)']);
		$this->assertSame([], $rows[0]['uncached']);
		$this->assertSame([], $rows[1]['uncached']);

		$allowed = $this->profile()->referencedColumns();
		foreach ($rows as $row) {
			foreach (array_keys($row['cells']) as $column) {
				$this->assertContains($column, $allowed);
			}

			foreach (['Beschikbaarheid', 'Behandelgroep', 'Hostingpartij', 'Rappelreden', 'Locatie BIOToets', 'Beheer'] as $never) {
				$this->assertArrayNotHasKey($never, $row['cells']);
			}
		}

		$this->assertFalse($result['date1904']);
		$this->assertSame([], $result['importWarnings'], 'Nickname is listed as absent on Onbeh, so its absence is no warning');
	}//end testTheSanitisedExportYieldsOneRowPerSheet()

	/**
	 * A formula cell yields the value Excel cached, not its result; a formula without
	 * a cached value yields an empty cell and is listed; the connection is never contacted.
	 *
	 * @return void
	 */
	public function testAFormulaYieldsItsCachedValue(): void {
		$rows = $this->read(name: 'topdesk-formula-and-connection.xlsx')['rows'];

		// The formula evaluates to "Evaluated"; the cached value is "Rekenmodel".
		$this->assertSame('Rekenmodel', $rows[1]['cells']['Applicatie Naam']);
		$this->assertSame('APP-test123', $rows[1]['cells']['Applicatie Code']);
		$this->assertNull($rows[1]['cells']['Roepnaam'], 'no cached value: empty, never evaluated');
		$this->assertSame(['Roepnaam'], $rows[1]['uncached']);
		$this->assertSame([], $rows[0]['uncached']);
	}//end testAFormulaYieldsItsCachedValue()

	/**
	 * The reader source never calls the calculation engine nor an HTTP client.
	 *
	 * @return void
	 */
	public function testTheReaderNeverEvaluatesOrFetches(): void {
		$source = (string)file_get_contents(CmdbTestSupport::appRoot() . '/lib/Service/Cmdb/CmdbWorkbookReader.php');
		$code = (string)preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $source);

		$this->assertStringNotContainsString('getCalculatedValue', $code);
		$this->assertStringNotContainsString('toArray', $code);
		$this->assertStringNotContainsString('Calculation', $code);
		$this->assertDoesNotMatchRegularExpression('/Http|Guzzle|curl_|file_get_contents\(\s*\$url/i', $code);
		$this->assertStringContainsString('getOldCalculatedValue', $code);
		$this->assertStringContainsString('setReadDataOnly(true)', $code);
	}//end testTheReaderNeverEvaluatesOrFetches()

	/**
	 * Shuffled columns and decorated headers map to the same rows.
	 *
	 * @return void
	 */
	public function testShuffledColumnsMapTheSame(): void {
		$original = $this->read(name: 'topdesk-export-anonymised.xlsx')['rows'];
		$shuffled = $this->read(name: 'topdesk-shuffled-columns.xlsx')['rows'];

		$this->assertCount(count($original), $shuffled);
		foreach ($original as $index => $row) {
			$expected = $row['cells'];
			$actual = $shuffled[$index]['cells'];
			ksort($expected);
			ksort($actual);
			$this->assertSame($expected, $actual);
		}
	}//end testShuffledColumnsMapTheSame()

	/**
	 * A CMDB sheet without APPID stops the import, naming column and sheet.
	 *
	 * @return void
	 */
	public function testAMissingRequiredColumnIsNamed(): void {
		try {
			$this->read(name: 'topdesk-missing-appid.xlsx');
			$this->fail('MISSING_COLUMN expected');
		} catch (CmdbImportException $e) {
			$this->assertSame('MISSING_COLUMN', $e->getErrorCode());
			$this->assertSame(422, $e->getHttpStatus());
			$this->assertSame(['sheet' => 'Beheerde Applicaties CMDB', 'column' => 'APPID'], $e->getDetails());
		}
	}//end testAMissingRequiredColumnIsNamed()

	/**
	 * A workbook with only "Blad1" names both expected sheets.
	 *
	 * @return void
	 */
	public function testAWorkbookWithoutSourceSheetsIsRefused(): void {
		try {
			$this->read(name: 'topdesk-no-source-sheet.xlsx');
			$this->fail('NO_SOURCE_SHEET expected');
		} catch (CmdbImportException $e) {
			$this->assertSame('NO_SOURCE_SHEET', $e->getErrorCode());
			$this->assertSame(['expected' => ['Onbeh Applicaties CMDB', 'Beheerde Applicaties CMDB']], $e->getDetails());
		}
	}//end testAWorkbookWithoutSourceSheetsIsRefused()

	/**
	 * More non-empty rows than the profile allows stops the import.
	 *
	 * @return void
	 */
	public function testTooManyRowsIsRefused(): void {
		$this->requireSpreadsheet();
		$directory = sys_get_temp_dir() . '/stackiq-cmdb-profile-' . bin2hex(random_bytes(4));
		mkdir($directory);
		$shipped = CmdbTestSupport::appRoot() . '/lib/Settings/cmdb-import';
		foreach (glob($shipped . '/*.json') as $file) {
			copy($file, $directory . '/' . basename($file));
		}

		$profile = json_decode((string)file_get_contents($directory . '/topdesk-profile.json'), true);
		$profile['maxRowsPerSheet'] = 0;
		file_put_contents($directory . '/topdesk-profile.json', json_encode($profile));

		try {
			(new CmdbWorkbookReader())->read(path: CmdbTestSupport::fixtures() . '/topdesk-export-anonymised.xlsx', profile: $this->profile(directory: $directory));
			$this->fail('TOO_MANY_ROWS expected');
		} catch (CmdbImportException $e) {
			$this->assertSame('TOO_MANY_ROWS', $e->getErrorCode());
			$this->assertSame(422, $e->getHttpStatus());
		} finally {
			array_map('unlink', glob($directory . '/*.json'));
			rmdir($directory);
		}
	}//end testTooManyRowsIsRefused()

	/**
	 * A package that unpacks to more than maxUncompressedBytes is refused before PhpSpreadsheet is touched.
	 *
	 * The reader below has no PhpSpreadsheet at all: reaching it would answer READER_UNAVAILABLE.
	 *
	 * @return void
	 */
	public function testAWorkbookThatUnpacksBeyondTheLimitIsRefusedBeforeParsing(): void {
		$rows = [['APPID', 'Applicatie Naam']];
		for ($index = 1; $index <= 3000; $index++) {
			$rows[] = [1, 'Applicatie'];
		}

		$path = CmdbTestSupport::buildWorkbook(sheets: ['Beheerde Applicaties CMDB' => $rows]);
		$directory = CmdbTestSupport::profileDirectory(overrides: ['maxUncompressedBytes' => 100000]);
		$reader = new class extends CmdbWorkbookReader {
			/**
			 * PhpSpreadsheet is absent.
			 *
			 * @return bool
			 */
			public function isAvailable(): bool {
				return false;
			}//end isAvailable()
		};

		try {
			$this->assertLessThan(100000, filesize($path), 'the package itself is under the limit; only its contents are not');
			$reader->read(path: $path, profile: $this->profile(directory: $directory));
			$this->fail('WORKBOOK_TOO_LARGE expected');
		} catch (CmdbImportException $e) {
			$this->assertSame('WORKBOOK_TOO_LARGE', $e->getErrorCode());
			$this->assertSame(413, $e->getHttpStatus());
			$this->assertSame(['maxUncompressedBytes' => 100000], $e->getDetails());
		} finally {
			unlink($path);
			CmdbTestSupport::removeDirectory(directory: $directory);
		}
	}//end testAWorkbookThatUnpacksBeyondTheLimitIsRefusedBeforeParsing()

	/**
	 * A source sheet whose last used row lies beyond twice the row limit stops before any sheet is loaded.
	 *
	 * @return void
	 */
	public function testARowSpanBeyondTheLimitStopsBeforeLoading(): void {
		$this->requireSpreadsheet();
		require_once __DIR__ . '/../../Support/RecordingXlsxReader.php';
		RecordingXlsxReader::$loads = 0;

		// maxRowsPerSheet 1 reads up to row 3; the third data row sits on row 4.
		$path = CmdbTestSupport::buildWorkbook(
			sheets: ['Beheerde Applicaties CMDB' => [['APPID', 'Applicatie Naam'], [1, 'Een'], [2, 'Twee'], [3, 'Drie']]]
		);
		$directory = CmdbTestSupport::profileDirectory(overrides: ['maxRowsPerSheet' => 1]);
		$reader = new class extends CmdbWorkbookReader {
			public const READER_CLASS = RecordingXlsxReader::class;
		};

		try {
			$reader->read(path: $path, profile: $this->profile(directory: $directory));
			$this->fail('TOO_MANY_ROWS expected');
		} catch (CmdbImportException $e) {
			$this->assertSame('TOO_MANY_ROWS', $e->getErrorCode());
			$this->assertSame(['sheet' => 'Beheerde Applicaties CMDB', 'limit' => 1], $e->getDetails());
			$this->assertSame(0, RecordingXlsxReader::$loads, 'no sheet was loaded');
		} finally {
			unlink($path);
			CmdbTestSupport::removeDirectory(directory: $directory);
		}
	}//end testARowSpanBeyondTheLimitStopsBeforeLoading()

	/**
	 * The data pass holds only the resolved columns, and drops an empty row between data rows.
	 *
	 * @return void
	 */
	public function testTheDataPassHoldsOnlyResolvedColumns(): void {
		$this->requireSpreadsheet();
		$path = CmdbTestSupport::buildWorkbook(
			sheets: [
				'Beheerde Applicaties CMDB' => [
					['APPID', 'Personeelsnummer', 'Applicatie Naam'],
					[1, 'P-0001', 'Een'],
					[],
					[2, 'P-0002', 'Twee'],
				],
			]
		);
		$directory = CmdbTestSupport::profileDirectory(overrides: ['maxRowsPerSheet' => 2]);

		try {
			$rows = (new CmdbWorkbookReader())->read(path: $path, profile: $this->profile(directory: $directory))['rows'];
			$this->assertSame([2, 4], array_column($rows, 'row'));
			$this->assertSame(['APPID', 'Applicatie Naam'], array_keys(array_filter($rows[1]['cells'], static fn ($value): bool => $value !== null)));
			$this->assertStringNotContainsString('P-000', (string)json_encode($rows));
		} finally {
			unlink($path);
			CmdbTestSupport::removeDirectory(directory: $directory);
		}
	}//end testTheDataPassHoldsOnlyResolvedColumns()

	/**
	 * A sheet within the row span still stops at the limit on non-empty rows.
	 *
	 * @return void
	 */
	public function testOneRowOverTheLimitWithinTheSpanIsRefused(): void {
		$this->requireSpreadsheet();
		// maxRowsPerSheet 1 reads up to row 3, so both data rows are read, and the second is one too many.
		$path = CmdbTestSupport::buildWorkbook(sheets: ['Beheerde Applicaties CMDB' => [['APPID', 'Applicatie Naam'], [1, 'Een'], [2, 'Twee']]]);
		$directory = CmdbTestSupport::profileDirectory(overrides: ['maxRowsPerSheet' => 1]);

		try {
			(new CmdbWorkbookReader())->read(path: $path, profile: $this->profile(directory: $directory));
			$this->fail('TOO_MANY_ROWS expected');
		} catch (CmdbImportException $e) {
			$this->assertSame('TOO_MANY_ROWS', $e->getErrorCode());
			$this->assertSame(['sheet' => 'Beheerde Applicaties CMDB', 'limit' => 1], $e->getDetails());
		} finally {
			unlink($path);
			CmdbTestSupport::removeDirectory(directory: $directory);
		}
	}//end testOneRowOverTheLimitWithinTheSpanIsRefused()

	/**
	 * A text file named .xlsx, a .xlsm and a CSV are refused before PhpSpreadsheet is touched.
	 *
	 * @return void
	 */
	public function testNonXlsxIsRefusedBeforeParsing(): void {
		$reader = new CmdbWorkbookReader();
		$text = tempnam(sys_get_temp_dir(), 'cmdb');
		file_put_contents($text, "Applicatie Naam;APPID\nVoorbeeld;1\n");
		$cases = [
			[$text, 'export.xlsx'],
			[CmdbTestSupport::fixtures() . '/topdesk-export-anonymised.xlsx', 'export.xlsm'],
			[$text, 'applications.csv'],
			[CmdbTestSupport::fixtures() . '/topdesk-export-anonymised.xlsx', 'export.xls'],
		];

		try {
			foreach ($cases as [$path, $name]) {
				try {
					$reader->assertXlsx(path: $path, fileName: $name);
					$this->fail('NOT_XLSX expected for ' . $name);
				} catch (CmdbImportException $e) {
					$this->assertSame('NOT_XLSX', $e->getErrorCode(), $name);
					$this->assertSame(400, $e->getHttpStatus(), $name);
				}
			}

			// A ZIP without xl/workbook.xml.
			$zipPath = tempnam(sys_get_temp_dir(), 'cmdb') . '.xlsx';
			$zip = new \ZipArchive();
			$zip->open($zipPath, \ZipArchive::CREATE);
			$zip->addFromString('word/document.xml', '<x/>');
			$zip->close();
			try {
				$reader->assertXlsx(path: $zipPath, fileName: 'export.xlsx');
				$this->fail('NOT_XLSX expected for a zip without a workbook');
			} catch (CmdbImportException $e) {
				$this->assertSame('NOT_XLSX', $e->getErrorCode());
			} finally {
				unlink($zipPath);
			}
		} finally {
			unlink($text);
		}

		$this->assertTrue(true);
	}//end testNonXlsxIsRefusedBeforeParsing()

	/**
	 * Without PhpSpreadsheet the reader answers READER_UNAVAILABLE.
	 *
	 * @return void
	 */
	public function testAMissingReaderIsReported(): void {
		$reader = new class extends CmdbWorkbookReader {
			/**
			 * PhpSpreadsheet is absent.
			 *
			 * @return bool
			 */
			public function isAvailable(): bool {
				return false;
			}//end isAvailable()
		};

		try {
			$reader->read(path: CmdbTestSupport::fixtures() . '/topdesk-export-anonymised.xlsx', profile: $this->profile());
			$this->fail('READER_UNAVAILABLE expected');
		} catch (CmdbImportException $e) {
			$this->assertSame('READER_UNAVAILABLE', $e->getErrorCode());
			$this->assertSame(503, $e->getHttpStatus());
		}
	}//end testAMissingReaderIsReported()

	/**
	 * Headers match after trimming, collapsing whitespace, dropping a trailing ":" or "⚡" and lower-casing.
	 *
	 * @return void
	 */
	public function testHeadersAreNormalised(): void {
		$this->assertSame('vendor', CmdbWorkbookReader::normaliseHeader(header: 'Vendor⚡'));
		$this->assertSame('applicatie eigenaar (persoon)', CmdbWorkbookReader::normaliseHeader(header: ' Applicatie  Eigenaar (Persoon): '));
		$this->assertSame('appid', CmdbWorkbookReader::normaliseHeader(header: 'APPID'));
	}//end testHeadersAreNormalised()
}//end class
