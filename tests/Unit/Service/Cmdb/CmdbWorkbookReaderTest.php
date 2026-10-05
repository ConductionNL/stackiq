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
		$this->assertSame('letter.achternaam@gemeente.nl', $rows[0]['cells']['Eigenaar e-mail'], 'looked up on "Invoer AIA data" by Middel-ID');
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
	 * A formula that would fetch a URL yields its cached value, and nothing connects to that URL.
	 *
	 * A local listener stands in for the remote host: had the reader evaluated
	 * WEBSERVICE(), the listener would hold a pending connection.
	 *
	 * @return void
	 */
	public function testTheReaderNeverEvaluatesOrFetches(): void {
		$this->requireSpreadsheet();
		$server = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
		$this->assertNotFalse($server, 'a local listener: ' . $errorMessage);
		$address = (string)stream_socket_get_name($server, false);
		$path = CmdbTestSupport::buildWorkbook(
			sheets: [
				'Beheerde Applicaties CMDB' => [
					['APPID', 'Applicatie Naam', 'Roepnaam'],
					[1, ['f' => 'WEBSERVICE("http://' . $address . '/naam")', 'v' => 'Gecachte naam'], ['f' => '1+1', 'v' => 'Niet berekend']],
				],
			]
		);

		try {
			$rows = (new CmdbWorkbookReader())->read(path: $path, profile: $this->profile())['rows'];
			$this->assertSame('Gecachte naam', $rows[0]['cells']['Applicatie Naam']);
			$this->assertSame('Niet berekend', $rows[0]['cells']['Roepnaam'], 'the cached value, not 2');

			stream_set_blocking($server, false);
			$this->assertFalse(@stream_socket_accept($server, 0), 'nothing connected to the formula\'s URL');
		} finally {
			fclose($server);
			unlink($path);
		}
	}//end testTheReaderNeverEvaluatesOrFetches()

	/**
	 * A sheet with a DOCTYPE (the shape of an entity-expansion or XXE attack) is refused as NOT_XLSX.
	 *
	 * @return void
	 */
	public function testADoctypeIsRefused(): void {
		$this->requireSpreadsheet();
		$path = CmdbTestSupport::buildWorkbook(
			sheets: ['Beheerde Applicaties CMDB' => [['APPID', 'Applicatie Naam'], [1, 'Een']]],
			prologue: '<!DOCTYPE worksheet [<!ENTITY lol "lol"><!ENTITY lol2 "&lol;&lol;&lol;&lol;">]>'
		);

		try {
			(new CmdbWorkbookReader())->read(path: $path, profile: $this->profile());
			$this->fail('NOT_XLSX expected');
		} catch (CmdbImportException $e) {
			$this->assertSame('NOT_XLSX', $e->getErrorCode());
			$this->assertSame(400, $e->getHttpStatus());
		} finally {
			unlink($path);
		}
	}//end testADoctypeIsRefused()

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
	 * A lookup adds only its listed columns, from the Invoer row with the same Middel-ID, to the rows that have one.
	 *
	 * @return void
	 */
	public function testALookupAddsOnlyItsColumnsByKey(): void {
		$this->requireSpreadsheet();
		$path = CmdbTestSupport::buildWorkbook(
			sheets: [
				'Beheerde Applicaties CMDB' => [
					['APPID', 'Applicatie Code', 'Applicatie Naam'],
					[1, 'APP-een', 'Een'],
					[2, 'APP-twee', 'Twee'],
				],
				'Invoer APP data' => [
					['Personeelsnummer', 'Middel-ID', 'Eigenaar e-mail', 'Eigenaar mobiel nummer'],
					['P-0002', ' app-TWEE ', 'twee@example.org', '0612345678'],
					['P-0003', 'APP-drie', 'drie@example.org', ''],
				],
			]
		);

		try {
			$result = (new CmdbWorkbookReader())->read(path: $path, profile: $this->profile());
			$rows = array_column($result['rows'], 'cells', 'row');
			$this->assertArrayNotHasKey('Eigenaar e-mail', array_filter($rows[2], static fn ($value): bool => $value !== null), 'APP-een has no Invoer row');
			$this->assertSame('twee@example.org', $rows[3]['Eigenaar e-mail'], 'keys match whatever their case or surrounding space');
			$this->assertSame('0612345678', (string)$rows[3]['Eigenaar mobiel nummer']);
			$this->assertStringNotContainsString('P-000', (string)json_encode($result['rows']));
			$this->assertCount(2, $result['rows'], 'a lookup sheet adds no rows of its own');
			$this->assertSame([], array_filter($result['importWarnings'], static fn (array $warning): bool => isset($warning['lookupSheet']) || isset($warning['lookupKey'])));
		} finally {
			unlink($path);
		}
	}//end testALookupAddsOnlyItsColumnsByKey()

	/**
	 * A missing lookup sheet is an import warning, and the source rows are read without its columns.
	 *
	 * @return void
	 */
	public function testAMissingLookupSheetIsAWarning(): void {
		$this->requireSpreadsheet();
		$path = CmdbTestSupport::buildWorkbook(
			sheets: ['Beheerde Applicaties CMDB' => [['APPID', 'Applicatie Code', 'Applicatie Naam'], [1, 'APP-een', 'Een']]]
		);

		try {
			$result = (new CmdbWorkbookReader())->read(path: $path, profile: $this->profile());
			$this->assertCount(1, $result['rows']);
			$lookupWarnings = array_values(array_filter($result['importWarnings'], static fn (array $warning): bool => isset($warning['lookupSheet'])));
			$this->assertSame(
				[['sheet' => 'Beheerde Applicaties CMDB', 'lookupSheet' => 'Invoer APP data', 'lookupColumns' => ['Eigenaar e-mail', 'Eigenaar mobiel nummer']]],
				array_map(static fn (array $warning): array => array_diff_key($warning, ['message' => true]), $lookupWarnings)
			);
		} finally {
			unlink($path);
		}
	}//end testAMissingLookupSheetIsAWarning()

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
