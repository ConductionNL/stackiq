<?php

/**
 * Tests for the CSV reader of a profile-driven import.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service\Cmdb
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/landscape-migrate-from-previous-catalogue/tasks.md#task-1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service\Cmdb;

require_once __DIR__ . '/../../Support/CmdbTestSupport.php';

use OCA\Stackiq\Exception\CmdbImportException;
use OCA\Stackiq\Service\Cmdb\CmdbCsvReader;
use OCA\Stackiq\Service\Cmdb\CmdbImportProfile;
use OCA\Stackiq\Tests\Unit\Support\CmdbTestSupport;
use PHPUnit\Framework\TestCase;

/**
 * Delimiters, encodings, the allowlist, the row limit and the file checks.
 */
class CmdbCsvReaderTest extends TestCase {
	/**
	 * Files written by a test.
	 *
	 * @var array<int, string>
	 */
	private array $files = [];

	/**
	 * Remove the files written by the test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ($this->files as $file) {
			if (is_file($file) === true) {
				unlink($file);
			}
		}
	}//end tearDown()

	/**
	 * A CSV file with the given content.
	 *
	 * @param string $content The bytes.
	 *
	 * @return string The path.
	 */
	private function file(string $content): string {
		$path = sys_get_temp_dir() . '/stackiq-csv-' . bin2hex(random_bytes(4)) . '.csv';
		file_put_contents($path, $content);
		$this->files[] = $path;

		return $path;
	}//end file()

	/**
	 * The previous catalogue's profile.
	 *
	 * @return CmdbImportProfile
	 */
	private function profile(): CmdbImportProfile {
		CmdbTestSupport::loadMigrationPack();
		return new CmdbImportProfile(container: CmdbTestSupport::emptyContainer(test: $this), profileFile: 'vng-swc-profile.json');
	}//end profile()

	/**
	 * A comma-separated file with a byte order mark reads like a semicolon one, with quoted cells kept whole.
	 *
	 * @return void
	 */
	public function testACommaFileWithAByteOrderMarkIsRead(): void {
		$path = $this->file("\xEF\xBB\xBFGebruik ID,Leverancier,Pakket,Referentiecomponenten,Geheim\r\n7,\"Acme, B.V.\",Pakket X,\"A; B\",niet lezen\r\n,,,,\r\n");

		$read = (new CmdbCsvReader())->read(path: $path, profile: $this->profile());

		$this->assertSame(
			[['sheet' => 'Applicaties', 'row' => 2, 'cells' => ['Gebruik ID' => '7', 'Leverancier' => 'Acme, B.V.', 'Pakket' => 'Pakket X', 'Referentiecomponenten' => 'A; B'], 'uncached' => []]],
			$read['rows']
		);
		$this->assertFalse($read['date1904']);
		$this->assertFalse($read['archive']['present']);
		$this->assertSame([], $read['absentSheets']);
	}//end testACommaFileWithAByteOrderMarkIsRead()

	/**
	 * Text that is not UTF-8 is read as Windows-1252, and headers match case- and space-insensitively.
	 *
	 * @return void
	 */
	public function testWindows1252TextAndLooseHeadersAreRead(): void {
		$path = $this->file("gebruik  id;LEVERANCIER;Pakket:\n9;Gemeente\xEBn B.V.;Financi\xEBn\n");

		$rows = (new CmdbCsvReader())->read(path: $path, profile: $this->profile())['rows'];

		$this->assertSame(['Gebruik ID' => '9', 'Leverancier' => 'Gemeenteën B.V.', 'Pakket' => 'Financiën'], $rows[0]['cells']);
	}//end testWindows1252TextAndLooseHeadersAreRead()

	/**
	 * More non-empty rows than the profile allows stops the read.
	 *
	 * @return void
	 */
	public function testMoreRowsThanTheLimitAreRefused(): void {
		$path = $this->file("Gebruik ID;Leverancier;Pakket\n" . str_repeat("1;A;B\n", 5001));

		try {
			(new CmdbCsvReader())->read(path: $path, profile: $this->profile());
			$this->fail('TOO_MANY_ROWS expected');
		} catch (CmdbImportException $e) {
			$this->assertSame(CmdbImportException::TOO_MANY_ROWS, $e->getErrorCode());
			$this->assertSame(['sheet' => 'Applicaties', 'limit' => 5000], $e->getDetails());
		}
	}//end testMoreRowsThanTheLimitAreRefused()

	/**
	 * A file that is not named .csv, or holds binary content, is refused before it is parsed.
	 *
	 * @return void
	 */
	public function testANonCsvFileIsRefused(): void {
		$reader = new CmdbCsvReader();
		$text = $this->file("a;b\n");
		$binary = $this->file("PK\x03\x04\0\0binary");

		$reader->assertCsv(path: $text, fileName: 'export.CSV');

		foreach ([[$text, 'export.xlsx'], [$binary, 'export.csv'], ['/no/such/file', 'export.csv']] as [$path, $name]) {
			try {
				$reader->assertCsv(path: $path, fileName: $name);
				$this->fail('NOT_CSV expected for ' . $name);
			} catch (CmdbImportException $e) {
				$this->assertSame(CmdbImportException::NOT_CSV, $e->getErrorCode());
				$this->assertSame(400, $e->getHttpStatus());
			}
		}
	}//end testANonCsvFileIsRefused()

	/**
	 * A profile that names no CSV sheet reads no CSV file.
	 *
	 * @return void
	 */
	public function testAProfileWithoutACsvSheetReadsNoCsv(): void {
		CmdbTestSupport::loadMigrationPack();
		$profile = new CmdbImportProfile(container: CmdbTestSupport::emptyContainer(test: $this));

		$this->expectException(CmdbImportException::class);
		$this->expectExceptionMessage('The import profile reads no CSV file');
		(new CmdbCsvReader())->read(path: $this->file("APPID\n1\n"), profile: $profile);
	}//end testAProfileWithoutACsvSheetReadsNoCsv()

	/**
	 * The delimiter is the most frequent one outside quotes; a semicolon wins a tie.
	 *
	 * @return void
	 */
	public function testTheDelimiterIsTheMostFrequentOutsideQuotes(): void {
		$this->assertSame(',', CmdbCsvReader::delimiter(line: 'a,b,"c;d;e"'));
		$this->assertSame(';', CmdbCsvReader::delimiter(line: 'a;b,c'));
		$this->assertSame("\t", CmdbCsvReader::delimiter(line: "a\tb\tc;d"));
		$this->assertSame(';', CmdbCsvReader::delimiter(line: 'single'));
		$this->assertSame('Financiën', CmdbCsvReader::utf8(text: "Financi\xEBn"));
		$this->assertSame('Financiën', CmdbCsvReader::utf8(text: "\xEF\xBB\xBFFinanciën"));
	}//end testTheDelimiterIsTheMostFrequentOutsideQuotes()
}//end class
