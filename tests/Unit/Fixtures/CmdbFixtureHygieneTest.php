<?php

/**
 * Hygiene of the CMDB import fixtures.
 *
 * The fixtures are derived from an anonymised TOPdesk export. This test fails
 * when one carries a package part outside the list a plain workbook needs
 * (printer settings, embeddings, comments, customXml, custom properties, ...),
 * document metadata (author, company, title and the other document properties,
 * a creation or modification date other than the fixed one, the absolute path
 * of the last save, revision GUIDs, the filter ranges of the original data) or
 * any person data that is not one of the known placeholders. Plain zip and XML
 * parsing only, so it needs no spreadsheet library and always runs.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Fixtures
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/cmdb-export-import/tasks.md#task-1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Fixtures;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * Scans every fixture package.
 */
class CmdbFixtureHygieneTest extends TestCase {
	/**
	 * The only e-mail addresses a fixture may hold.
	 *
	 * @var array<int, string>
	 */
	private const PLACEHOLDER_EMAILS = ['letter.achternaam@gemeente.nl', 'groepsmail.test@gemeente.nl'];

	/**
	 * The only hosts a fixture's content may link to.
	 *
	 * @var array<int, string>
	 */
	private const PLACEHOLDER_HOSTS = ['wiki.gemeente.nl', 'example.invalid'];

	/**
	 * The only runs of six or more digits (personnel numbers, phone numbers, ids) a fixture may hold.
	 *
	 * @var array<int, string>
	 */
	private const PLACEHOLDER_NUMBERS = ['123456', '123457', '612345678', '0612345678', '143211234', '10000000001'];

	/**
	 * The only values a person-name column may hold, besides a placeholder e-mail address
	 * (TOPdesk puts the address of the configuration coordinator in that column), and
	 * the placeholder function a CMDB sheet shows as owner when no person is set.
	 *
	 * @var array<int, string>
	 */
	private const PLACEHOLDER_NAMES = ['', 'Achternaam, Voornaam', 'Achternaam, voornaam', 'Teamleider Applicatiebeheer'];

	/**
	 * Columns that hold a person's name.
	 *
	 * @var array<int, string>
	 */
	private const NAME_COLUMNS = [
		'Eigenaar',
		'FB contactpersoon 1',
		'FB contactpersoon 2',
		'Groepseigenaar naam⚡',
		'Configuratie coördinator⚡',
		'Applicatie Eigenaar (Persoon)',
		'|Asset eigenaar',
	];

	/**
	 * The only parts a fixture package may hold (xl/connections.xml only in the
	 * fixture that tests the synthetic connection). Anything else, such as
	 * xl/printerSettings/*.bin, embeddings, comments or customXml, fails.
	 *
	 * @var array<int, string>
	 */
	private const ALLOWED_PARTS = [
		'#^\[Content_Types\]\.xml$#',
		'#^_rels/\.rels$#',
		'#^docProps/(core|app)\.xml$#',
		'#^xl/workbook\.xml$#',
		'#^xl/_rels/workbook\.xml\.rels$#',
		'#^xl/worksheets/sheet\d+\.xml$#',
		'#^xl/worksheets/_rels/sheet\d+\.xml\.rels$#',
		'#^xl/theme/theme\d+\.xml$#',
		'#^xl/(styles|sharedStrings|calcChain|metadata)\.xml$#',
	];

	/**
	 * The creation and modification date of every fixture (build-fixtures.py FIXED_DATE).
	 *
	 * @var string
	 */
	private const FIXED_DATE = '2026-01-01T00:00:00Z';

	/**
	 * Document properties that must be empty, per part.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const EMPTY_PROPERTIES = [
		'docProps/core.xml' => ['dc:title', 'dc:subject', 'dc:creator', 'cp:keywords', 'dc:description', 'cp:lastModifiedBy', 'cp:revision', 'cp:category', 'cp:contentStatus'],
		'docProps/app.xml'  => ['Manager', 'Company', 'HyperlinkBase'],
	];

	/**
	 * The placeholder person names; a column that holds one is a name column.
	 *
	 * @var array<int, string>
	 */
	private const PLACEHOLDER_PERSONS = ['Achternaam, Voornaam', 'Achternaam, voornaam'];

	/**
	 * Hosts of XML namespaces and schemas, which are not content.
	 *
	 * @var array<int, string>
	 */
	private const SCHEMA_HOSTS = ['schemas.openxmlformats.org', 'schemas.microsoft.com', 'purl.org', 'www.w3.org'];

	/**
	 * Every fixture.
	 *
	 * @return array<string, array{string}>
	 */
	public static function fixtures(): array {
		$cases = [];
		foreach (glob(__DIR__ . '/../../fixtures/cmdb/*.xlsx') as $path) {
			$cases[basename($path)] = [$path];
		}

		return $cases;
	}//end fixtures()

	/**
	 * Every part of a package, by name.
	 *
	 * @param string $path The package.
	 *
	 * @return array<string, string>
	 */
	private function parts(string $path): array {
		$zip = new ZipArchive();
		$this->assertTrue($zip->open($path, ZipArchive::RDONLY), basename($path));
		$parts = [];
		for ($index = 0; $index < $zip->numFiles; $index++) {
			$name = (string)$zip->getNameIndex($index);
			$parts[$name] = (string)$zip->getFromIndex($index);
		}

		$zip->close();
		return $parts;
	}//end parts()

	/**
	 * There are fixtures to scan, including the four the reader tests use.
	 *
	 * @return void
	 */
	public function testTheFixturesExist(): void {
		$names = array_keys(self::fixtures());
		foreach (['topdesk-export-anonymised.xlsx', 'topdesk-missing-appid.xlsx', 'topdesk-shuffled-columns.xlsx', 'topdesk-formula-and-connection.xlsx'] as $expected) {
			$this->assertContains($expected, $names);
		}
	}//end testTheFixturesExist()

	/**
	 * Only the parts a plain workbook needs, no document metadata, no revision ids;
	 * connections only the synthetic one.
	 *
	 * @param string $path The fixture.
	 *
	 * @return void
	 */
	#[DataProvider('fixtures')]
	public function testNoDocumentMetadata(string $path): void {
		$parts = $this->parts(path: $path);
		$name = basename($path);

		foreach (array_keys($parts) as $part) {
			if ($part === 'xl/connections.xml' && $name === 'topdesk-formula-and-connection.xlsx') {
				continue;
			}

			$this->assertTrue($this->isAllowedPart(part: $part), $name . ': unexpected package part ' . $part);
		}

		$this->assertDocumentProperties(parts: $parts, name: $name);

		$this->assertStringNotContainsString('absPath', ($parts['xl/workbook.xml'] ?? ''), $name);
		$this->assertStringNotContainsString('revisionPtr', ($parts['xl/workbook.xml'] ?? ''), $name);
		$this->assertStringNotContainsString('_xlnm._FilterDatabase', ($parts['xl/workbook.xml'] ?? ''), $name);
		foreach ($parts as $part => $content) {
			$this->assertDoesNotMatchRegularExpression('/ xr\d*:uid(LastSave)?="/', $content, $name . ' ' . $part . ': revision GUID');
			$this->assertStringNotContainsString('<sortState', $content, $name . ' ' . $part . ': saved sort range');
			if ($part === '[Content_Types].xml' || str_ends_with($part, '.rels') === true) {
				$this->assertStringNotContainsString('printerSettings', $content, $name . ' ' . $part);
				$this->assertStringNotContainsString('custom.xml', $content, $name . ' ' . $part);
				$this->assertStringNotContainsString('customXml', $content, $name . ' ' . $part);
			}
		}

		if ($name === 'topdesk-formula-and-connection.xlsx') {
			$this->assertStringContainsString('https://example.invalid/', $parts['xl/connections.xml'], 'the synthetic connection');
			return;
		}

		$this->assertArrayNotHasKey('xl/connections.xml', $parts, $name);
		$this->assertStringNotContainsString('connections.xml', $parts['[Content_Types].xml'], $name);
		$this->assertStringNotContainsString('connections.xml', ($parts['xl/_rels/workbook.xml.rels'] ?? ''), $name);
	}//end testNoDocumentMetadata()

	/**
	 * Whether a part is on the allow-list.
	 *
	 * @param string $part The part name.
	 *
	 * @return bool
	 */
	private function isAllowedPart(string $part): bool {
		foreach (self::ALLOWED_PARTS as $pattern) {
			if (preg_match($pattern, $part) === 1) {
				return true;
			}
		}

		return false;
	}//end isAllowedPart()

	/**
	 * The document properties are empty and both dates are the fixed date.
	 *
	 * @param array<string, string> $parts The package parts.
	 * @param string                $name  The fixture name.
	 *
	 * @return void
	 */
	private function assertDocumentProperties(array $parts, string $name): void {
		foreach (self::EMPTY_PROPERTIES as $part => $elements) {
			if (isset($parts[$part]) === false) {
				continue;
			}

			foreach ($elements as $element) {
				$this->assertDoesNotMatchRegularExpression('#<' . preg_quote($element, '#') . '(?: [^>]*)?>[^<]+</#', $parts[$part], $name . ' ' . $part . ' ' . $element);
			}
		}

		if (isset($parts['docProps/core.xml']) === true) {
			preg_match_all('#<dcterms:(created|modified)[^>]*>([^<]*)</#', $parts['docProps/core.xml'], $dates, PREG_SET_ORDER);
			foreach ($dates as $date) {
				$this->assertSame(self::FIXED_DATE, $date[2], $name . ' dcterms:' . $date[1]);
			}
		}
	}//end assertDocumentProperties()

	/**
	 * Every e-mail address, linked host and long digit run is a known placeholder.
	 *
	 * @param string $path The fixture.
	 *
	 * @return void
	 */
	#[DataProvider('fixtures')]
	public function testOnlyPlaceholderContactData(string $path): void {
		$name = basename($path);
		// Every part: the allow-list of testNoDocumentMetadata keeps binary parts out.
		foreach ($this->parts(path: $path) as $part => $content) {
			preg_match_all('/[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)+/', $content, $emails);
			foreach (array_unique($emails[0]) as $email) {
				$this->assertContains(strtolower($email), self::PLACEHOLDER_EMAILS, $name . ' ' . $part);
			}

			preg_match_all('#https?://([^/"<\s]+)#', $content, $urls);
			foreach (array_unique($urls[1]) as $host) {
				if (in_array($host, self::SCHEMA_HOSTS, true) === false) {
					$this->assertContains($host, self::PLACEHOLDER_HOSTS, $name . ' ' . $part);
				}
			}

			// Digit runs in cell values and shared strings; attributes such as
			// widths, ids and dates are not content.
			preg_match_all('#>(\+?\d[\d\s-]{5,}\d)<#', $content, $numbers);
			foreach (array_unique($numbers[1]) as $number) {
				$digits = (string)preg_replace('/\D/', '', $number);
				if (strlen($digits) >= 6) {
					$this->assertContains($digits, self::PLACEHOLDER_NUMBERS, $name . ' ' . $part);
				}
			}
		}//end foreach
	}//end testOnlyPlaceholderContactData()

	/**
	 * Every person-name cell of the raw TOPdesk sheets and the CMDB sheets holds a placeholder.
	 *
	 * @param string $path The fixture.
	 *
	 * @return void
	 */
	#[DataProvider('fixtures')]
	public function testPersonNameColumnsHoldPlaceholders(string $path): void {
		$parts = $this->parts(path: $path);
		$strings = $this->sharedStrings(xml: ($parts['xl/sharedStrings.xml'] ?? ''));

		foreach ($parts as $part => $content) {
			if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', $part) !== 1) {
				continue;
			}

			$sheet = simplexml_load_string($content);
			$this->assertNotFalse($sheet, $part);
			$headers = [];
			foreach ($sheet->sheetData->row as $row) {
				foreach ($row->c as $cell) {
					preg_match('/^([A-Z]+)(\d+)$/', (string)$cell['r'], $ref);
					$value = $this->cellText(cell: $cell, strings: $strings);
					if ($ref[2] === '1') {
						$headers[$ref[1]] = $value;
						continue;
					}

					// The raw TOPdesk sheets (Middel-ID) and the CMDB sheets (APPID)
					// have their headers in row 1; the other sheets are covered by
					// the e-mail and number scan.
					if (in_array('Middel-ID', $headers, true) === false && in_array('APPID', $headers, true) === false) {
						break 2;
					}

					$header = ($headers[$ref[1]] ?? '');
					if (in_array($header, self::NAME_COLUMNS, true) === true) {
						$this->assertContains(trim($value), array_merge(self::PLACEHOLDER_NAMES, self::PLACEHOLDER_EMAILS), basename($path) . ' ' . $part . ' ' . $cell['r']);
					}
				}
			}
		}//end foreach
	}//end testPersonNameColumnsHoldPlaceholders()

	/**
	 * The shared strings table as a list.
	 *
	 * @param string $xml The sharedStrings part.
	 *
	 * @return array<int, string>
	 */
	private function sharedStrings(string $xml): array {
		if ($xml === '') {
			return [];
		}

		$table = simplexml_load_string($xml);
		$strings = [];
		foreach ($table->si as $item) {
			$text = (string)$item->t;
			foreach ($item->r as $run) {
				$text .= (string)$run->t;
			}

			$strings[] = $text;
		}

		return $strings;
	}//end sharedStrings()

	/**
	 * The text of a cell.
	 *
	 * @param \SimpleXMLElement $cell The cell.
	 * @param array<int, string> $strings The shared strings.
	 *
	 * @return string
	 */
	private function cellText(\SimpleXMLElement $cell, array $strings): string {
		$type = (string)$cell['t'];
		if ($type === 's') {
			return ($strings[(int)$cell->v] ?? '');
		}

		if ($type === 'inlineStr') {
			return (string)$cell->is->t;
		}

		return (string)$cell->v;
	}//end cellText()
}//end class
