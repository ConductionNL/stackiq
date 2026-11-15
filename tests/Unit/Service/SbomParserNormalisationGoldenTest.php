<?php

/**
 * Golden tests for the SbomParserService component normalisation.
 *
 * Pins the normalised CycloneDX components (licenses by id, name or
 * expression, hashes, skipped entries) and SPDX packages (first purl
 * reference, concluded-then-declared licence, NOASSERTION), plus the
 * rejection messages, so splitting the parser into named steps keeps every
 * outcome.
 *
 * @category Tests
 * @package  OCA\Stackiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\Stackiq\Service\SbomParserService;
use PHPUnit\Framework\TestCase;

/**
 * Golden tests for SbomParserService::parse() and parseSpdx().
 *
 * @category Tests
 * @package  OCA\Stackiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */
class SbomParserNormalisationGoldenTest extends TestCase {

	/**
	 * The CycloneDX and SPDX documents with every entry shape.
	 *
	 * @return array<string, string>
	 */
	private function documents(): array {
		return [
		'cyclonedx' => json_encode([
			'bomFormat' => 'CycloneDX',
			'specVersion' => '1.6',
			'components' => [
				'not-a-component',
				[
					'name' => 'lib-a',
					'version' => 2,
					'purl' => 'pkg:npm/lib-a@2',
					'type' => 'library',
					'bom-ref' => 'ref-a',
					'licenses' => [
						'skip',
						['license' => ['id' => 'MIT', 'name' => 'ignored']],
						['license' => ['name' => 'Custom']],
						['expression' => 'Apache-2.0 OR MIT'],
						['license' => ['id' => null, 'name' => 'Null id']],
						['license' => 'string-license'],
						[],
					],
					'hashes' => [['alg' => 'SHA-256', 'content' => 'abc'], 'skip', ['alg' => 'MD5'], []],
				],
				['licenses' => [], 'hashes' => []],
				[],
			],
			'vulnerabilities' => [['id' => 'CVE-1', 'affects' => [['ref' => 'ref-a']]]],
		]),
		'spdx' => json_encode([
			'spdxVersion' => 'SPDX-2.3',
			'packages' => [
				'skip',
				[
					'name' => 'pkg-a',
					'versionInfo' => '1.0',
					'SPDXID' => 'SPDXRef-a',
					'licenseConcluded' => 'MIT',
					'licenseDeclared' => 'GPL',
					'externalRefs' => ['skip', ['referenceType' => 'cpe23', 'referenceLocator' => 'cpe'], ['referenceType' => 'purl', 'referenceLocator' => 'pkg:a'], ['referenceType' => 'purl', 'referenceLocator' => 'pkg:b']],
				],
				['name' => 'pkg-b', 'licenseDeclared' => 'noassertion', 'externalRefs' => [['referenceType' => 'purl']]],
				['name' => 'pkg-c', 'licenseConcluded' => null, 'licenseDeclared' => 'BSD-3-Clause'],
				['name' => 'pkg-d', 'licenseConcluded' => '', 'licenseDeclared' => 'BSD'],
				['name' => 'pkg-e', 'licenseConcluded' => ['x']],
				[],
			],
		]),
	];
	}//end documents()

	/**
	 * CycloneDX components normalise to the recorded DTOs.
	 *
	 * @return void
	 */
	public function testCycloneDxComponentsNormalise(): void {
		$parsed = (new SbomParserService())->parse($this->documents()['cyclonedx']);

		$expected = [
			'components' => [
				[
					'name' => 'lib-a',
					'version' => '2',
					'purl' => 'pkg:npm/lib-a@2',
					'licenses' => [
						'MIT',
						'Custom',
						'Apache-2.0 OR MIT',
						'Null id',
					],
					'type' => 'library',
					'hashes' => [
						[
							'alg' => 'SHA-256',
							'value' => 'abc',
						],
						[
							'alg' => 'MD5',
							'value' => '',
						],
						[
							'alg' => '',
							'value' => '',
						],
					],
					'bomRef' => 'ref-a',
				],
				[
					'name' => '',
					'version' => '',
					'purl' => '',
					'licenses' => [],
					'type' => '',
					'hashes' => [],
					'bomRef' => '',
				],
				[
					'name' => '',
					'version' => '',
					'purl' => '',
					'licenses' => [],
					'type' => '',
					'hashes' => [],
					'bomRef' => '',
				],
			],
			'vulnerabilities' => [
				[
					'cveId' => 'CVE-1',
					'componentBomRef' => 'ref-a',
				],
			],
		];
		$this->assertSame($expected, $parsed);
	}//end testCycloneDxComponentsNormalise()

	/**
	 * SPDX packages normalise to the recorded DTOs.
	 *
	 * @return void
	 */
	public function testSpdxPackagesNormalise(): void {
		$parsed = (new SbomParserService())->parseSpdx($this->documents()['spdx']);

		$expected = [
			'components' => [
				[
					'name' => 'pkg-a',
					'version' => '1.0',
					'purl' => 'pkg:a',
					'licenses' => [
						'MIT',
					],
					'type' => 'library',
					'hashes' => [],
					'bomRef' => 'SPDXRef-a',
				],
				[
					'name' => 'pkg-b',
					'version' => '',
					'purl' => '',
					'licenses' => [],
					'type' => 'library',
					'hashes' => [],
					'bomRef' => '',
				],
				[
					'name' => 'pkg-c',
					'version' => '',
					'purl' => '',
					'licenses' => [
						'BSD-3-Clause',
					],
					'type' => 'library',
					'hashes' => [],
					'bomRef' => '',
				],
				[
					'name' => 'pkg-d',
					'version' => '',
					'purl' => '',
					'licenses' => [],
					'type' => 'library',
					'hashes' => [],
					'bomRef' => '',
				],
				[
					'name' => 'pkg-e',
					'version' => '',
					'purl' => '',
					'licenses' => [],
					'type' => 'library',
					'hashes' => [],
					'bomRef' => '',
				],
				[
					'name' => '',
					'version' => '',
					'purl' => '',
					'licenses' => [],
					'type' => 'library',
					'hashes' => [],
					'bomRef' => '',
				],
			],
			'vulnerabilities' => [],
		];
		$this->assertSame($expected, $parsed);
	}//end testSpdxPackagesNormalise()

	/**
	 * Unsupported documents are refused with the recorded messages.
	 *
	 * @return void
	 */
	public function testUnsupportedDocumentsAreRefused(): void {
		$parser = new SbomParserService();
		$messages = [];
		$cycloneDx = [
			'{"bomFormat":"CycloneDX","specVersion":"1.4"}',
			'{"bomFormat":"SPDX","specVersion":"1.6"}',
			'{"bomFormat":"CycloneDX","specVersion":1.6}',
			'{"bomFormat":["x"]}',
			'nope',
			'"str"',
		];
		foreach ($cycloneDx as $json) {
			$messages[] = $this->refusal(parse: fn () => $parser->parse($json));
		}

		foreach (['{"spdxVersion":"SPDX-3.0"}', '{"spdxVersion":2}', '{}'] as $json) {
			$messages[] = $this->refusal(parse: fn () => $parser->parseSpdx($json));
		}

		$expected = [
			'OCA\\Stackiq\\Exception\\UnsupportedSbomFormatException: Unsupported SBOM format/version: bomFormat=CycloneDX, specVersion=1.4 (expected CycloneDX 1.5 or 1.6)',
			'OCA\\Stackiq\\Exception\\UnsupportedSbomFormatException: Unsupported SBOM format/version: bomFormat=SPDX, specVersion=1.6 (expected CycloneDX 1.5 or 1.6)',
			'OCA\\Stackiq\\Exception\\UnsupportedSbomFormatException: Unsupported SBOM format/version: bomFormat=CycloneDX, specVersion=1.6 (expected CycloneDX 1.5 or 1.6)',
			'OCA\\Stackiq\\Exception\\UnsupportedSbomFormatException: Unsupported SBOM format/version: bomFormat=["x"], specVersion=(none) (expected CycloneDX 1.5 or 1.6)',
			'OCA\\Stackiq\\Exception\\UnsupportedSbomFormatException: Uploaded content is not a valid JSON document: Syntax error',
			'OCA\\Stackiq\\Exception\\UnsupportedSbomFormatException: Uploaded content is not a valid JSON document: No error',
			'OCA\\Stackiq\\Exception\\UnsupportedSbomFormatException: Unsupported SPDX document version: SPDX-3.0 (expected an SPDX-2.x document)',
			'OCA\\Stackiq\\Exception\\UnsupportedSbomFormatException: Unsupported SPDX document version: 2 (expected an SPDX-2.x document)',
			'OCA\\Stackiq\\Exception\\UnsupportedSbomFormatException: Unsupported SPDX document version: (none) (expected an SPDX-2.x document)',
		];
		$this->assertSame($expected, $messages);
	}//end testUnsupportedDocumentsAreRefused()

	/**
	 * The class and message of the refusal a parse raises.
	 *
	 * @param callable $parse The parse call.
	 *
	 * @return string
	 */
	private function refusal(callable $parse): string {
		try {
			$parse();
		} catch (\Throwable $e) {
			return get_class($e) . ': ' . $e->getMessage();
		}

		return 'no error';
	}//end refusal()

}//end class
