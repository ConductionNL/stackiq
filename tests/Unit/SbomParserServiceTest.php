<?php

/**
 * Unit tests for SbomParserService.
 *
 * Covers the CycloneDX 1.5/1.6 happy path, the unsupported-format/version
 * rejection (no partial list), empty-components handling, VEX
 * cveId/bom-ref extraction, the optional SPDX 2.3 path, and the structural
 * "no OR/HTTP dependency" guarantee — all against REAL small CycloneDX/SPDX
 * fixtures, not mocked JSON shapes.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/sbom-import/spec.md#requirement-cyclonedx-sbom-files-are-parsed-into-a-normalized-component-list
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit;

use OCA\Stackiq\Exception\UnsupportedSbomFormatException;
use OCA\Stackiq\Service\SbomParserService;
use PHPUnit\Framework\TestCase;

/**
 * Test class for SbomParserService.
 */
class SbomParserServiceTest extends TestCase {
	/**
	 * @var string
	 */
	private string $fixturesDir;

	/**
	 * @var SbomParserService
	 */
	private SbomParserService $parser;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->fixturesDir = __DIR__ . '/../fixtures/sbom';
		$this->parser = new SbomParserService();
	}//end setUp()

	/**
	 * Read a fixture file's raw contents.
	 *
	 * @param string $name The fixture file name.
	 *
	 * @return string The raw contents.
	 */
	private function fixture(string $name): string {
		return (string)file_get_contents($this->fixturesDir . '/' . $name);
	}//end fixture()

	/**
	 * A valid CycloneDX 1.6 document parses into components with their
	 * name/version/purl/licenses populated.
	 *
	 * @return void
	 */
	public function testValidCycloneDx16ParsesIntoComponents(): void {
		$result = $this->parser->parse($this->fixture('cyclonedx-1.6-valid.json'));

		$this->assertCount(3, $result['components']);

		$lodash = $result['components'][0];
		$this->assertSame('lodash', $lodash['name']);
		$this->assertSame('4.17.21', $lodash['version']);
		$this->assertSame('pkg:npm/lodash@4.17.21', $lodash['purl']);
		$this->assertSame(['MIT'], $lodash['licenses']);
		$this->assertSame('library', $lodash['type']);
		$this->assertNotEmpty($lodash['hashes']);

		$log4j = $result['components'][1];
		$this->assertSame('log4j-core', $log4j['name']);
		$this->assertSame(['Apache-2.0'], $log4j['licenses']);

		// License expression form (no `license.id`) is also read.
		$openssl = $result['components'][2];
		$this->assertSame(['Apache-2.0'], $openssl['licenses']);

		$this->assertSame([], $result['vulnerabilities']);
	}//end testValidCycloneDx16ParsesIntoComponents()

	/**
	 * A valid CycloneDX 1.5 document parses too (both supported versions).
	 *
	 * @return void
	 */
	public function testValidCycloneDx15Parses(): void {
		$result = $this->parser->parse($this->fixture('cyclonedx-1.5-valid.json'));

		$this->assertCount(2, $result['components']);
		$this->assertSame('express', $result['components'][0]['name']);
		// `license.name` (no SPDX id) form is also read.
		$this->assertSame(['MIT License'], $result['components'][0]['licenses']);
	}//end testValidCycloneDx15Parses()

	/**
	 * An unsupported specVersion (1.4) is rejected with no partial list.
	 *
	 * @return void
	 */
	public function testUnsupportedSpecVersionThrows(): void {
		$this->expectException(UnsupportedSbomFormatException::class);
		$this->expectExceptionMessageMatches('/1\.4/');

		$this->parser->parse($this->fixture('cyclonedx-invalid-format.json'));
	}//end testUnsupportedSpecVersionThrows()

	/**
	 * A non-CycloneDX bomFormat is rejected.
	 *
	 * @return void
	 */
	public function testNonCycloneDxBomFormatThrows(): void {
		$this->expectException(UnsupportedSbomFormatException::class);

		$this->parser->parse(json_encode(['bomFormat' => 'SPDX', 'specVersion' => '1.6', 'components' => []]));
	}//end testNonCycloneDxBomFormatThrows()

	/**
	 * Malformed JSON is rejected via the same exception type — no partial
	 * list, no PHP notice/warning escapes as output.
	 *
	 * @return void
	 */
	public function testMalformedJsonThrows(): void {
		$this->expectException(UnsupportedSbomFormatException::class);

		$this->parser->parse('{ this is not json');
	}//end testMalformedJsonThrows()

	/**
	 * An empty components[] array parses into zero components without error.
	 *
	 * @return void
	 */
	public function testEmptyComponentsParsesToEmptyList(): void {
		$result = $this->parser->parse($this->fixture('cyclonedx-empty-components.json'));

		$this->assertSame([], $result['components']);
	}//end testEmptyComponentsParsesToEmptyList()

	/**
	 * A top-level vulnerabilities[] (VEX) block yields {cveId,
	 * componentBomRef} pairs alongside the component list.
	 *
	 * @return void
	 */
	public function testVexBlockExtractsCveComponentPairs(): void {
		$result = $this->parser->parse($this->fixture('cyclonedx-with-vex.json'));

		$this->assertCount(1, $result['components']);
		$this->assertCount(1, $result['vulnerabilities']);
		$this->assertSame('CVE-2021-44228', $result['vulnerabilities'][0]['cveId']);
		$this->assertSame(
			'pkg:maven/org.apache.logging.log4j/log4j-core@2.14.1',
			$result['vulnerabilities'][0]['componentBomRef']
		);
	}//end testVexBlockExtractsCveComponentPairs()

	/**
	 * A valid SPDX 2.3 document parses into the same DTO shape via
	 * parseSpdx().
	 *
	 * @return void
	 */
	public function testValidSpdx23Parses(): void {
		$result = $this->parser->parseSpdx($this->fixture('spdx-2.3-valid.json'));

		$this->assertCount(2, $result['components']);

		$lodash = $result['components'][0];
		$this->assertSame('lodash', $lodash['name']);
		$this->assertSame('4.17.21', $lodash['version']);
		$this->assertSame('pkg:npm/lodash@4.17.21', $lodash['purl']);
		$this->assertSame(['MIT'], $lodash['licenses']);

		$this->assertSame([], $result['vulnerabilities']);
	}//end testValidSpdx23Parses()

	/**
	 * An SPDX document with an unsupported spdxVersion is rejected.
	 *
	 * @return void
	 */
	public function testUnsupportedSpdxVersionThrows(): void {
		$this->expectException(UnsupportedSbomFormatException::class);

		$this->parser->parseSpdx(json_encode(['spdxVersion' => 'SPDX-3.0', 'packages' => []]));
	}//end testUnsupportedSpdxVersionThrows()

	/**
	 * Structural guarantee (design Decision 7): the parser's constructor
	 * takes zero arguments — no ObjectService, no HTTP client can be
	 * injected, so it cannot make an OR or network call from any code path.
	 *
	 * @return void
	 */
	public function testConstructorHasNoNetworkCapableDependency(): void {
		$reflection = new \ReflectionClass(SbomParserService::class);
		$constructor = $reflection->getConstructor();

		$this->assertTrue(
			$constructor === null || count($constructor->getParameters()) === 0,
			'SbomParserService must have no constructor dependencies (pure, OR/HTTP-free service)'
		);
	}//end testConstructorHasNoNetworkCapableDependency()
}//end class
