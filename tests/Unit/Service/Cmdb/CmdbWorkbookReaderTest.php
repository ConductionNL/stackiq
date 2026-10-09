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
	 * A shared-strings table with more entries than maxSharedStrings is refused before any sheet is loaded.
	 *
	 * The table declares a `uniqueCount` of 1, so only counting its `<si>` elements catches it. A table of
	 * exactly the limit is read normally.
	 *
	 * @return void
	 */
	public function testASharedStringsTableBeyondTheLimitIsRefusedBeforeLoading(): void {
		$this->requireSpreadsheet();
		require_once __DIR__ . '/../../Support/RecordingXlsxReader.php';
		$sheets = ['Beheerde Applicaties CMDB' => [['APPID', 'Applicatie Naam'], [1, 'Een']]];
		$directory = CmdbTestSupport::profileDirectory(overrides: ['maxSharedStrings' => 1000]);
		$reader = new class extends CmdbWorkbookReader {
			public const READER_CLASS = RecordingXlsxReader::class;
		};

		$atLimit = CmdbTestSupport::buildWorkbook(sheets: $sheets, extraParts: ['xl/sharedStrings.xml' => self::sharedStrings(count: 1000)]);
		$overLimit = CmdbTestSupport::buildWorkbook(sheets: $sheets, extraParts: ['xl/sharedStrings.xml' => self::sharedStrings(count: 1001)]);
		try {
			$this->assertCount(1, $reader->read(path: $atLimit, profile: $this->profile(directory: $directory))['rows'], 'a table of exactly the limit is read');

			RecordingXlsxReader::$loads = 0;
			$reader->read(path: $overLimit, profile: $this->profile(directory: $directory));
			$this->fail('WORKBOOK_TOO_LARGE expected');
		} catch (CmdbImportException $e) {
			$this->assertSame('WORKBOOK_TOO_LARGE', $e->getErrorCode());
			$this->assertSame(['maxSharedStrings' => 1000], $e->getDetails());
			$this->assertSame(0, RecordingXlsxReader::$loads, 'no sheet was loaded');
		} finally {
			unlink($atLimit);
			unlink($overLimit);
			CmdbTestSupport::removeDirectory(directory: $directory);
		}
	}//end testASharedStringsTableBeyondTheLimitIsRefusedBeforeLoading()

	/**
	 * A shared-strings table under another part name is counted too.
	 *
	 * The workbook's relationships can point the table at any part, and PhpSpreadsheet follows them, so the
	 * count recognises the table by its `sst` root element rather than by the name `xl/sharedStrings.xml`.
	 *
	 * @return void
	 */
	public function testASharedStringsTableUnderAnotherNameIsCounted(): void {
		$this->requireSpreadsheet();
		require_once __DIR__ . '/../../Support/RecordingXlsxReader.php';
		$sheets = ['Beheerde Applicaties CMDB' => [['APPID', 'Applicatie Naam'], [1, 'Een']]];
		$directory = CmdbTestSupport::profileDirectory(overrides: ['maxSharedStrings' => 1000]);
		$reader = new class extends CmdbWorkbookReader {
			public const READER_CLASS = RecordingXlsxReader::class;
		};

		$renamed = CmdbTestSupport::buildWorkbook(sheets: $sheets, extraParts: ['xl/strs.xml' => self::sharedStrings(count: 1001)]);
		try {
			RecordingXlsxReader::$loads = 0;
			$reader->read(path: $renamed, profile: $this->profile(directory: $directory));
			$this->fail('WORKBOOK_TOO_LARGE expected');
		} catch (CmdbImportException $e) {
			$this->assertSame('WORKBOOK_TOO_LARGE', $e->getErrorCode());
			$this->assertSame(['maxSharedStrings' => 1000], $e->getDetails());
			$this->assertSame(0, RecordingXlsxReader::$loads, 'no sheet was loaded');
		} finally {
			unlink($renamed);
			CmdbTestSupport::removeDirectory(directory: $directory);
		}
	}//end testASharedStringsTableUnderAnotherNameIsCounted()

	/**
	 * A shared-strings table whose root element is not `sst` is counted too.
	 *
	 * PhpSpreadsheet reads the `<si>` children of the part the relationships name without checking the
	 * root element, so the count does not check it either.
	 *
	 * @return void
	 */
	public function testASharedStringsTableUnderAnotherRootIsCounted(): void {
		$this->requireSpreadsheet();
		require_once __DIR__ . '/../../Support/RecordingXlsxReader.php';
		$sheets = ['Beheerde Applicaties CMDB' => [['APPID', 'Applicatie Naam'], [1, 'Een']]];
		$directory = CmdbTestSupport::profileDirectory(overrides: ['maxSharedStrings' => 1000]);
		$reader = new class extends CmdbWorkbookReader {
			public const READER_CLASS = RecordingXlsxReader::class;
		};

		$table = str_replace(['<sst ', '</sst>'], ['<strings ', '</strings>'], self::sharedStrings(count: 1001));
		$renamed = CmdbTestSupport::buildWorkbook(sheets: $sheets, extraParts: ['xl/sharedStrings.xml' => $table]);
		try {
			RecordingXlsxReader::$loads = 0;
			$reader->read(path: $renamed, profile: $this->profile(directory: $directory));
			$this->fail('WORKBOOK_TOO_LARGE expected');
		} catch (CmdbImportException $e) {
			$this->assertSame('WORKBOOK_TOO_LARGE', $e->getErrorCode());
			$this->assertSame(['maxSharedStrings' => 1000], $e->getDetails());
			$this->assertSame(0, RecordingXlsxReader::$loads, 'no sheet was loaded');
		} finally {
			unlink($renamed);
			CmdbTestSupport::removeDirectory(directory: $directory);
		}
	}//end testASharedStringsTableUnderAnotherRootIsCounted()

	/**
	 * The runs of a rich-text entry count against maxSharedStrings, also when no cell references it.
	 *
	 * PhpSpreadsheet keeps every run of every rich-text entry as objects for the whole load, so one entry
	 * of many runs is as costly as as many entries. A table of exactly the limit, runs included, is read.
	 * The runs of a cell's own rich text (an inline string) count the same way, in any part.
	 *
	 * @return void
	 */
	public function testTheRunsOfARichTextEntryCountAsEntries(): void {
		$this->requireSpreadsheet();
		require_once __DIR__ . '/../../Support/RecordingXlsxReader.php';
		$directory = CmdbTestSupport::profileDirectory(overrides: ['maxSharedStrings' => 1000]);
		$reader = new class extends CmdbWorkbookReader {
			public const READER_CLASS = RecordingXlsxReader::class;
		};

		// The table holds the two headers, the name the cell references, and one entry of n runs: 4 + n entries.
		$atLimit = self::sharedStringWorkbook(entry: '<si><t>Een</t></si><si>' . str_repeat('<r><t>a</t></r>', 996) . '</si>', cells: 1);
		$overLimit = self::sharedStringWorkbook(entry: '<si><t>Een</t></si><si>' . str_repeat('<r><t>a</t></r>', 997) . '</si>', cells: 1, part: 'strings.bin');
		$inline = CmdbTestSupport::buildWorkbook(
			sheets: ['Beheerde Applicaties CMDB' => [['APPID', 'Applicatie Naam']]],
			extraParts: [
				'xl/worksheets/notes.bin' => '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
					. '<row r="2"><c r="A2"><v>1</v></c><c r="B2" t="inlineStr"><is>' . str_repeat('<r><rPr/></r>', 1001) . '</is></c></row></sheetData></worksheet>',
			]
		);
		try {
			RecordingXlsxReader::$loads = 0;
			try {
				$reader->read(path: $inline, profile: $this->profile(directory: $directory));
				$this->fail('WORKBOOK_TOO_LARGE expected for the inline runs');
			} catch (CmdbImportException $e) {
				$this->assertSame(['maxSharedStrings' => 1000], $e->getDetails(), 'the inline runs count');
				$this->assertSame(0, RecordingXlsxReader::$loads, 'no sheet was loaded for the inline runs');
			}

			$this->assertCount(1, $reader->read(path: $atLimit, profile: $this->profile(directory: $directory))['rows'], 'a table of exactly the limit is read');

			RecordingXlsxReader::$loads = 0;
			$reader->read(path: $overLimit, profile: $this->profile(directory: $directory));
			$this->fail('WORKBOOK_TOO_LARGE expected');
		} catch (CmdbImportException $e) {
			$this->assertSame('WORKBOOK_TOO_LARGE', $e->getErrorCode());
			$this->assertSame(['maxSharedStrings' => 1000], $e->getDetails());
			$this->assertSame(0, RecordingXlsxReader::$loads, 'no sheet was loaded');
		} finally {
			unlink($atLimit);
			unlink($overLimit);
			unlink($inline);
			CmdbTestSupport::removeDirectory(directory: $directory);
		}
	}//end testTheRunsOfARichTextEntryCountAsEntries()

	/**
	 * One shared string referenced by many cells is refused before loading, as rich text or as plain text.
	 *
	 * PhpSpreadsheet gives every referencing cell its own copy, cloning each run of a rich-text string first,
	 * so a small file holds the string once but would make PhpSpreadsheet build it once per cell. Every
	 * package passes every other limit. The last three are shaped so that a check reading the package
	 * differently from PhpSpreadsheet charges the light entry: an entry of another namespace before the
	 * headers (PhpSpreadsheet numbers only its own), a decoy `<v>` of another namespace before the real one
	 * (PhpSpreadsheet reads only its own), a table whose part name does not end in `.xml`, and a `<v>` whose
	 * own text differs from all the text inside it (PhpSpreadsheet reads its own text).
	 *
	 * @return void
	 */
	public function testOneSharedStringReferencedByManyCellsIsRefusedBeforeLoading(): void {
		$this->requireSpreadsheet();
		require_once __DIR__ . '/../../Support/RecordingXlsxReader.php';
		$rich = '<si>' . str_repeat('<r><rPr><b/></rPr><t>ab</t></r>', 500) . '</si>';
		$packages = [
			'rich text' => self::sharedStringWorkbook(entry: $rich, cells: 50),
			'plain text' => self::sharedStringWorkbook(entry: '<si><t>' . str_repeat('x', 20000) . '</t></si>', cells: 50),
			'an entry of another namespace first' => self::sharedStringWorkbook(entry: $rich, cells: 50, before: '<o:si xmlns:o="urn:x"><o:t>z</o:t></o:si>'),
			'a decoy value of another namespace' => self::sharedStringWorkbook(entry: $rich, cells: 50, value: '<o:v xmlns:o="urn:x">99</o:v><v>2</v>'),
			'a table not named .xml' => self::sharedStringWorkbook(entry: $rich, cells: 50, part: 'sharedStrings.bin'),
			'a value with a child element' => self::sharedStringWorkbook(entry: $rich, cells: 50, value: '<v><o:x xmlns:o="urn:x">9</o:x>2</v>'),
		];
		$directory = CmdbTestSupport::profileDirectory(overrides: ['maxReferencedStringBytes' => 400000]);
		$reader = new class extends CmdbWorkbookReader {
			public const READER_CLASS = RecordingXlsxReader::class;
		};

		try {
			foreach ($packages as $kind => $path) {
				RecordingXlsxReader::$loads = 0;
				try {
					$reader->read(path: $path, profile: $this->profile(directory: $directory));
					$this->fail('WORKBOOK_TOO_LARGE expected for ' . $kind);
				} catch (CmdbImportException $e) {
					$this->assertSame('WORKBOOK_TOO_LARGE', $e->getErrorCode(), $kind);
					$this->assertSame(['maxReferencedStringBytes' => 400000], $e->getDetails(), $kind);
					$this->assertSame(0, RecordingXlsxReader::$loads, 'no sheet was loaded for ' . $kind);
				}
			}
		} finally {
			array_map('unlink', $packages);
			CmdbTestSupport::removeDirectory(directory: $directory);
		}
	}//end testOneSharedStringReferencedByManyCellsIsRefusedBeforeLoading()

	/**
	 * Shared strings referenced within the limit are read as their text, rich text as plain text.
	 *
	 * @return void
	 */
	public function testSharedStringsWithinTheReferenceLimitAreRead(): void {
		$this->requireSpreadsheet();
		$path = self::sharedStringWorkbook(entry: '<si><r><rPr><b/></rPr><t>Ee</t></r><r><t>n</t></r></si>', cells: 1);
		$directory = CmdbTestSupport::profileDirectory(overrides: ['maxReferencedStringBytes' => 400000]);

		try {
			$rows = (new CmdbWorkbookReader())->read(path: $path, profile: $this->profile(directory: $directory))['rows'];
			$this->assertCount(1, $rows);
			$this->assertSame('Een', $rows[0]['cells']['Applicatie Naam']);
		} finally {
			unlink($path);
			CmdbTestSupport::removeDirectory(directory: $directory);
		}
	}//end testSharedStringsWithinTheReferenceLimitAreRead()

	/**
	 * A workbook whose CMDB sheet holds an APPID and an application name per row, the name a reference to one shared string.
	 *
	 * The shared strings are the two headers and the given entry, linked from the workbook's relationships.
	 *
	 * @param string $entry The `<si>` element every name references.
	 * @param int $cells The number of rows that reference it.
	 * @param string $before Elements placed in the table before the two headers.
	 * @param string $value What each name cell holds: the `<v>` that points at the entry.
	 * @param string $part The name of the shared-strings part under `xl/`.
	 *
	 * @return string The path of the workbook.
	 */
	private static function sharedStringWorkbook(string $entry, int $cells, string $before = '', string $value = '<v>2</v>', string $part = 'sharedStrings.xml'): string {
		$main = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
		$rel = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
		$rows = '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c></row>';
		for ($row = 2; $row <= $cells + 1; $row++) {
			$rows .= '<row r="' . $row . '"><c r="A' . $row . '"><v>' . ($row - 1) . '</v></c><c r="B' . $row . '" t="s">' . $value . '</c></row>';
		}

		return CmdbTestSupport::buildWorkbook(
			sheets: ['Beheerde Applicaties CMDB' => []],
			extraParts: [
				'xl/worksheets/sheet1.xml' => '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="' . $main . '"><sheetData>' . $rows . '</sheetData></worksheet>',
				'xl/' . $part => '<?xml version="1.0" encoding="UTF-8"?><sst xmlns="' . $main . '">' . $before . '<si><t>APPID</t></si><si><t>Applicatie Naam</t></si>' . $entry . '</sst>',
				'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
					. '<Relationship Id="rId1" Type="' . $rel . '/worksheet" Target="worksheets/sheet1.xml"/>'
					. '<Relationship Id="rId2" Type="' . $rel . '/sharedStrings" Target="' . $part . '"/></Relationships>',
			]
		);
	}//end sharedStringWorkbook()

	/**
	 * A shared-strings part or a sheet part that unpacks beyond maxPartBytes is refused before any sheet is loaded.
	 *
	 * Both packages stay under maxUncompressedBytes; only the one part is too large.
	 *
	 * @return void
	 */
	public function testAPartBeyondThePartLimitIsRefusedBeforeLoading(): void {
		$this->requireSpreadsheet();
		require_once __DIR__ . '/../../Support/RecordingXlsxReader.php';
		$rows = [['APPID', 'Applicatie Naam']];
		for ($index = 1; $index <= 3000; $index++) {
			$rows[] = [$index, 'Applicatie'];
		}

		$packages = [
			'xl/sharedStrings.xml' => CmdbTestSupport::buildWorkbook(
				sheets: ['Beheerde Applicaties CMDB' => [['APPID', 'Applicatie Naam'], [1, 'Een']]],
				extraParts: ['xl/sharedStrings.xml' => self::sharedStrings(count: 10, length: 10000)]
			),
			'xl/worksheets/sheet1.xml' => CmdbTestSupport::buildWorkbook(sheets: ['Beheerde Applicaties CMDB' => $rows]),
		];
		$directory = CmdbTestSupport::profileDirectory(overrides: ['maxPartBytes' => 50000, 'maxSharedStrings' => 1000000]);
		$reader = new class extends CmdbWorkbookReader {
			public const READER_CLASS = RecordingXlsxReader::class;
		};

		try {
			foreach ($packages as $part => $path) {
				RecordingXlsxReader::$loads = 0;
				try {
					$reader->read(path: $path, profile: $this->profile(directory: $directory));
					$this->fail('WORKBOOK_TOO_LARGE expected for ' . $part);
				} catch (CmdbImportException $e) {
					$this->assertSame('WORKBOOK_TOO_LARGE', $e->getErrorCode(), $part);
					$expected = ['maxPartBytes' => 50000, 'part' => $part, 'size' => self::partSize(path: $path, part: $part)];
					if ($part === 'xl/worksheets/sheet1.xml') {
						$expected['sheet'] = 'Beheerde Applicaties CMDB';
					}

					$this->assertSame($expected, $e->getDetails(), $part);
					$this->assertSame(0, RecordingXlsxReader::$loads, 'no sheet was loaded for ' . $part);
				}
			}
		} finally {
			array_map('unlink', $packages);
			CmdbTestSupport::removeDirectory(directory: $directory);
		}
	}//end testAPartBeyondThePartLimitIsRefusedBeforeLoading()

	/**
	 * A sheet the import does not read may unpack beyond maxPartBytes; the source sheets are still read.
	 *
	 * A TOPdesk export carries such sheets ("Relatie APP oplosgroepen", the
	 * archive, the original data), and PhpSpreadsheet never parses them.
	 *
	 * @return void
	 */
	public function testAnUnreadSheetBeyondThePartLimitDoesNotStopTheImport(): void {
		$this->requireSpreadsheet();
		$path = CmdbTestSupport::buildWorkbook(
			sheets: [
				'Relatie APP oplosgroepen' => self::manyRows(count: 3000),
				'Beheerde Applicaties CMDB' => [['APPID', 'Applicatie Naam'], [1, 'Een']],
			]
		);
		$directory = CmdbTestSupport::profileDirectory(overrides: ['maxPartBytes' => 50000, 'maxSharedStrings' => 1000000]);

		try {
			$this->assertGreaterThan(50000, self::partSize(path: $path, part: 'xl/worksheets/sheet1.xml'));
			$result = (new CmdbWorkbookReader())->read(path: $path, profile: $this->profile(directory: $directory));
			$this->assertCount(1, $result['rows']);
			$this->assertSame('Beheerde Applicaties CMDB', $result['rows'][0]['sheet']);
		} finally {
			unlink($path);
			CmdbTestSupport::removeDirectory(directory: $directory);
		}
	}//end testAnUnreadSheetBeyondThePartLimitDoesNotStopTheImport()

	/**
	 * An unread sheet's part beyond maxPartBytes keeps the limit when anything else may name it.
	 *
	 * Each package lets an unread sheet point at a large part, and then also
	 * names that part another way: as the shared-strings table, as the part of
	 * a source sheet, in another case or without its directory (PhpSpreadsheet
	 * looks parts up case-insensitively and retries without the first
	 * character), or from a worksheet relationship no sheet refers to.
	 *
	 * @return void
	 */
	public function testAnUnreadSheetPartThatMayBeParsedKeepsThePartLimit(): void {
		$this->requireSpreadsheet();
		require_once __DIR__ . '/../../Support/RecordingXlsxReader.php';
		$rel = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
		$relationships = static fn (string $extra): string => '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. '<Relationship Id="rId1" Type="' . $rel . '/worksheet" Target="worksheets/sheet1.xml"/>'
			. '<Relationship Id="rId2" Type="' . $rel . '/worksheet" Target="worksheets/sheet2.xml"/>' . $extra . '</Relationships>';
		$sharedId = '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="' . $rel . '"><sheets>'
			. '<sheet name="Relatie APP oplosgroepen" sheetId="1" r:id="rId1"/><sheet name="Beheerde Applicaties CMDB" sheetId="2" r:id="rId1"/></sheets></workbook>';
		$cases = [
			'shared strings' => ['xl/_rels/workbook.xml.rels' => $relationships('<Relationship Id="rId3" Type="' . $rel . '/sharedStrings" Target="worksheets/sheet1.xml"/>')],
			'shared r:id' => ['xl/workbook.xml' => $sharedId],
			'source sheet' => ['xl/_rels/workbook.xml.rels' => str_replace('worksheets/sheet2.xml', 'worksheets/sheet1.xml', $relationships(''))],
			'other case' => ['xl/_rels/workbook.xml.rels' => $relationships('<Relationship Id="rId3" Type="' . $rel . '/styles" Target="/XL/Worksheets/SHEET1.XML"/>')],
			'no first character' => ['xl/_rels/workbook.xml.rels' => $relationships('<Relationship Id="rId3" Type="' . $rel . '/theme" Target="xsheet1.xml"/>')],
			'unreferenced worksheet' => ['xl/_rels/workbook.xml.rels' => $relationships('<Relationship Id="rId9" Type="' . $rel . '/worksheet" Target="worksheets/sheet1.xml"/>')],
		];
		$directory = CmdbTestSupport::profileDirectory(overrides: ['maxPartBytes' => 50000, 'maxSharedStrings' => 1000000]);
		$reader = new class extends CmdbWorkbookReader {
			public const READER_CLASS = RecordingXlsxReader::class;
		};

		try {
			foreach ($cases as $case => $extraParts) {
				$path = CmdbTestSupport::buildWorkbook(
					sheets: [
						'Relatie APP oplosgroepen' => self::manyRows(count: 3000),
						'Beheerde Applicaties CMDB' => [['APPID', 'Applicatie Naam'], [1, 'Een']],
					],
					extraParts: $extraParts
				);
				RecordingXlsxReader::$loads = 0;
				try {
					$reader->read(path: $path, profile: $this->profile(directory: $directory));
					$this->fail('WORKBOOK_TOO_LARGE expected for ' . $case);
				} catch (CmdbImportException $e) {
					$this->assertSame('WORKBOOK_TOO_LARGE', $e->getErrorCode(), $case);
					$this->assertSame('xl/worksheets/sheet1.xml', $e->getDetails()['part'] ?? null, $case);
					$this->assertSame(['source sheet' => 'Beheerde Applicaties CMDB', 'shared r:id' => 'Beheerde Applicaties CMDB'][$case] ?? null, $e->getDetails()['sheet'] ?? null, 'the refusal names the sheet that keeps the limit, if any: ' . $case);
					$this->assertSame(0, RecordingXlsxReader::$loads, 'no sheet was loaded for ' . $case);
				} finally {
					unlink($path);
				}
			}
		} finally {
			CmdbTestSupport::removeDirectory(directory: $directory);
		}
	}//end testAnUnreadSheetPartThatMayBeParsedKeepsThePartLimit()

	/**
	 * A crafted package never gets PhpSpreadsheet to read a blanked part, however its targets resolve.
	 *
	 * Each package has a part beyond maxPartBytes that only an unread sheet
	 * seems to name, while PhpSpreadsheet would resolve a source sheet (or the
	 * package relationships) to it: a target ending in `/x/..`, a root-relative
	 * target PhpSpreadsheet shortens, a foreign `id` after `r:id`, the workbook
	 * relationships found only through the Apache POI retry, and a padded
	 * `_rels/.rels`. The source sheet then reads the blanked, empty part, or the
	 * package is refused; the text of the large part is never read.
	 *
	 * @return void
	 */
	public function testACraftedPackageNeverReadsABlankedPart(): void {
		$this->requireSpreadsheet();
		$main = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
		$rel = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
		$pkg = 'http://schemas.openxmlformats.org/package/2006/relationships';
		$pad = '<p:pad xmlns:p="urn:p">' . str_repeat('<p:a/>', 12000) . '</p:pad>';
		$small = '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="' . $main . '"><sheetData>'
			. '<row r="1"><c r="A1" t="inlineStr"><is><t>APPID</t></is></c><c r="B1" t="inlineStr"><is><t>Applicatie Naam</t></is></c></row>'
			. '<row r="2"><c r="A2"><v>1</v></c><c r="B2" t="inlineStr"><is><t>Een</t></is></c></row></sheetData>';
		$big = str_replace('<t>Een</t>', '<t>FROM-BIG-PART</t>', $small) . $pad . '</worksheet>';
		$workbookRels = static fn (string $first, string $second): string => '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="' . $pkg . '">'
			. '<Relationship Id="rId1" Type="' . $rel . '/worksheet" Target="' . $first . '"/>'
			. '<Relationship Id="rId2" Type="' . $rel . '/worksheet" Target="' . $second . '"/></Relationships>';
		$workbook = static fn (string $sheets, string $namespaces = ''): string => '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="' . $main . '" xmlns:r="' . $rel . '"' . $namespaces . '><sheets>' . $sheets . '</sheets></workbook>';
		$cases = [
			'dot-dot target' => [['xl/worksheets/sheet1.xml' => $big, 'xl/_rels/workbook.xml.rels' => $workbookRels('worksheets/sheet1.xml', 'worksheets/sheet1.xml/x/..')], false],
			'root-relative target' => [['xl/sheet1.xml' => $big, 'abcsheet1.xml' => $small . '</worksheet>', 'xl/_rels/workbook.xml.rels' => $workbookRels('sheet1.xml', '/abcsheet1.xml')], false],
			'padded package relationships' => [[
				'_rels/.rels' => '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="' . $pkg . '"><Relationship Id="rId1" Type="' . $rel . '/officeDocument" Target="xl/workbook.xml"/>' . $pad . '</Relationships>',
				'xl/_rels/workbook.xml.rels' => $workbookRels('../_rels/.rels', 'worksheets/sheet2.xml'),
			], false],
			'foreign id' => [['xl/worksheets/sheet1.xml' => $big, 'xl/workbook.xml' => $workbook('<sheet name="Relatie APP oplosgroepen" sheetId="1" r:id="rId1"/><sheet name="Beheerde Applicaties CMDB" sheetId="2" r:id="rId1" x:id="rId2"/>', ' xmlns:x="urn:x"')], false],
			'Apache POI workbook relationships' => [[
				'xl/worksheets/sheet1.xml' => $big,
				'xl/workbook.xml' => $workbook('<sheet name="Beheerde Applicaties CMDB" sheetId="1" r:id="rId1"/>'),
				'l/workbook.xml' => $workbook('<sheet name="Relatie APP oplosgroepen" sheetId="1" r:id="rId1"/>'),
				'xl/_rels/workbook.xml.rels' => $workbookRels('worksheets/sheet1.xml', 'worksheets/sheet2.xml'),
			], true],
		];
		$directory = CmdbTestSupport::profileDirectory(overrides: ['maxPartBytes' => 50000, 'maxSharedStrings' => 1000000]);

		try {
			foreach ($cases as $case => [$extraParts, $poiRename]) {
				$path = CmdbTestSupport::buildWorkbook(
					sheets: ['Relatie APP oplosgroepen' => [['x']], 'Beheerde Applicaties CMDB' => [['APPID', 'Applicatie Naam'], [1, 'Een']]],
					extraParts: $extraParts
				);
				if ($poiRename === true) {
					$zip = new \ZipArchive();
					$zip->open($path);
					$zip->renameName('xl/_rels/workbook.xml.rels', 'l/_rels/workbook.xml.rels');
					$zip->close();
				}

				try {
					$result = (new CmdbWorkbookReader())->read(path: $path, profile: $this->profile(directory: $directory));
					$this->assertStringNotContainsString('FROM-BIG-PART', json_encode($result['rows']), $case);
				} catch (CmdbImportException $e) {
					$this->assertContains($e->getErrorCode(), ['MISSING_COLUMN', 'WORKBOOK_TOO_LARGE', 'NOT_XLSX', 'NO_SOURCE_SHEET'], $case);
					if ($case === 'padded package relationships') {
						$this->assertSame('WORKBOOK_TOO_LARGE', $e->getErrorCode(), 'a relationships part is refused, never blanked');
						$this->assertSame('_rels/.rels', $e->getDetails()['part'] ?? null);
					}
				} finally {
					unlink($path);
				}
			}
		} finally {
			CmdbTestSupport::removeDirectory(directory: $directory);
		}
	}//end testACraftedPackageNeverReadsABlankedPart()

	/**
	 * A header row and the given number of data rows.
	 *
	 * @param int $count The number of data rows.
	 *
	 * @return array<int, array<int, mixed>>
	 */
	private static function manyRows(int $count): array {
		$rows = [['APPID', 'Applicatie Naam']];
		for ($index = 1; $index <= $count; $index++) {
			$rows[] = [$index, 'Applicatie'];
		}

		return $rows;
	}//end manyRows()

	/**
	 * The unpacked size of one part of a package.
	 *
	 * @param string $path The xlsx file.
	 * @param string $part The part's name.
	 *
	 * @return int
	 */
	private static function partSize(string $path, string $part): int {
		$zip = new \ZipArchive();
		$zip->open($path);
		$stat = $zip->statName($part);
		$zip->close();
		return (int)($stat['size'] ?? 0);
	}//end partSize()

	/**
	 * A shared-strings part with the given number of entries, each the given number of characters long.
	 *
	 * Its `count` and `uniqueCount` attributes claim a single entry.
	 *
	 * @param int $count The number of `<si>` entries.
	 * @param int $length The length of every string.
	 *
	 * @return string
	 */
	private static function sharedStrings(int $count, int $length = 1): string {
		return '<?xml version="1.0" encoding="UTF-8"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="1" uniqueCount="1">'
			. str_repeat('<si><t>' . str_repeat('x', $length) . '</t></si>', $count) . '</sst>';
	}//end sharedStrings()

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
	 * The archive sheet of the anonymised export yields its APPIDs; the CMDB rows are unchanged.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cmdb-import-archive-reconciliation/specs/cmdb-export-import/spec.md#requirement-an-application-missing-from-the-cmdb-sheets-shall-be-archived-when-the-archive-sheet-lists-it-and-soft-deleted-when-no-sheet-does-req-cmdb-015
	 */
	public function testTheArchiveSheetYieldsItsAppIds(): void {
		$result = $this->read(name: 'topdesk-export-anonymised.xlsx');

		$this->assertSame('Gearchiveerde Applicaties', $result['archive']['sheet']);
		$this->assertTrue($result['archive']['present']);
		$this->assertFalse($result['archive']['keyColumnMissing']);
		$this->assertSame(['1198'], array_map('strval', $result['archive']['appIds']));
		$this->assertSame(['Onbeh Applicaties CMDB', 'Beheerde Applicaties CMDB'], array_column($result['rows'], 'sheet'), 'the archive sheet adds no rows to import');
	}//end testTheArchiveSheetYieldsItsAppIds()

	/**
	 * The later export moves APPID 1234 to the archive sheet and drops APPID 2 from every sheet.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cmdb-import-archive-reconciliation/specs/cmdb-export-import/spec.md#requirement-an-application-missing-from-the-cmdb-sheets-shall-be-archived-when-the-archive-sheet-lists-it-and-soft-deleted-when-no-sheet-does-req-cmdb-015
	 */
	public function testTheArchivedApplicationsVariantListsOnlyTheArchive(): void {
		$result = $this->read(name: 'topdesk-archived-applications.xlsx');

		$this->assertSame([], $result['rows']);
		$this->assertTrue($result['archive']['present']);
		$this->assertSame(['1234'], array_map('strval', $result['archive']['appIds']));
	}//end testTheArchivedApplicationsVariantListsOnlyTheArchive()

	/**
	 * Only the APPID column of the archive sheet is read; without the sheet or its APPID column it is absent.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cmdb-import-archive-reconciliation/specs/cmdb-export-import/spec.md#requirement-an-application-missing-from-the-cmdb-sheets-shall-be-archived-when-the-archive-sheet-lists-it-and-soft-deleted-when-no-sheet-does-req-cmdb-015
	 */
	public function testTheArchiveSheetIsOptionalAndReadForItsKeyOnly(): void {
		$this->requireSpreadsheet();
		$cmdb = [['APPID', 'Applicatie Naam'], [1, 'Een']];
		$cases = [
			'with the sheet' => [
				['Beheerde Applicaties CMDB' => $cmdb, 'Gearchiveerde Applicaties' => [['APPID', 'Applicatie Naam', 'Bron'], [7, 'Zeven', 'P-0007'], [], [8, 'Acht', 'P-0008']]],
				['present' => true, 'keyColumnMissing' => false, 'appIds' => ['7', '8']],
			],
			'without the sheet' => [
				['Beheerde Applicaties CMDB' => $cmdb],
				['present' => false, 'keyColumnMissing' => false, 'appIds' => []],
			],
			'without its APPID column' => [
				['Beheerde Applicaties CMDB' => $cmdb, 'Gearchiveerde Applicaties' => [['Applicatie Code', 'Applicatie Naam'], ['APP-7', 'Zeven']]],
				['present' => false, 'keyColumnMissing' => true, 'appIds' => []],
			],
		];

		foreach ($cases as $case => [$sheets, $expected]) {
			$path = CmdbTestSupport::buildWorkbook(sheets: $sheets);
			try {
				$result = (new CmdbWorkbookReader())->read(path: $path, profile: $this->profile());
			} finally {
				unlink($path);
			}

			$archive = $result['archive'];
			$archive['appIds'] = array_map('strval', $archive['appIds']);
			unset($archive['sheet']);
			$this->assertSame($expected, $archive, $case);
			$this->assertSame([2], array_column($result['rows'], 'row'), $case);
			$this->assertStringNotContainsString('P-000', (string)json_encode($result), $case);
			$this->assertStringNotContainsString('Zeven', (string)json_encode($result), $case . ': only the key column is read');
		}
	}//end testTheArchiveSheetIsOptionalAndReadForItsKeyOnly()

	/**
	 * An archive sheet with more rows than the profile allows is refused and named, like a source sheet.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cmdb-import-archive-reconciliation/specs/cmdb-export-import/spec.md#requirement-an-application-missing-from-the-cmdb-sheets-shall-be-archived-when-the-archive-sheet-lists-it-and-soft-deleted-when-no-sheet-does-req-cmdb-015
	 */
	public function testAnArchiveSheetOverTheRowLimitIsRefused(): void {
		$this->requireSpreadsheet();
		$path = CmdbTestSupport::buildWorkbook(
			sheets: [
				'Beheerde Applicaties CMDB' => [['APPID', 'Applicatie Naam'], [1, 'Een']],
				'Gearchiveerde Applicaties' => [['APPID'], [7], [8]],
			]
		);
		$directory = CmdbTestSupport::profileDirectory(overrides: ['maxRowsPerSheet' => 1]);

		try {
			(new CmdbWorkbookReader())->read(path: $path, profile: $this->profile(directory: $directory));
			$this->fail('TOO_MANY_ROWS expected');
		} catch (CmdbImportException $e) {
			$this->assertSame('TOO_MANY_ROWS', $e->getErrorCode());
			$this->assertSame(['sheet' => 'Gearchiveerde Applicaties', 'limit' => 1], $e->getDetails());
		} finally {
			unlink($path);
			CmdbTestSupport::removeDirectory(directory: $directory);
		}
	}//end testAnArchiveSheetOverTheRowLimitIsRefused()

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

			// The ZIP signature, but no package behind it.
			file_put_contents($text, "PK\x03\x04" . str_repeat('x', 64));
			try {
				$reader->assertXlsx(path: $text, fileName: 'export.xlsx');
				$this->fail('NOT_XLSX expected for a truncated zip');
			} catch (CmdbImportException $e) {
				$this->assertSame('NOT_XLSX', $e->getErrorCode());
				$this->assertSame('The ZIP package cannot be opened', $e->getMessage());
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
