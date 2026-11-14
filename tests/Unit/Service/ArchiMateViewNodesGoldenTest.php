<?php

/**
 * Golden test for the flattening of ArchiMate view nodes.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\Stackiq\Service\ArchiMateImportService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * The view nodes ArchiMateImportService::extractViewNodesRecursively() builds, as recorded.
 *
 * @covers \OCA\Stackiq\Service\ArchiMateImportService
 *
 * @spec openspec/specs/method-decomposition/spec.md
 */
class ArchiMateViewNodesGoldenTest extends TestCase {

	/**
	 * The golden file.
	 *
	 * @return string
	 */
	private static function golden(): string {
		return __DIR__ . '/golden/archiMateViewNodes.json';

	}//end golden()

	/**
	 * The elements the nodes refer to.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function elements(): array {
		return [
			'el-gemma'      => [
				'name'             => 'Zaaksysteem',
				'omschrijving'     => 'Beheert zaken',
				'documentation'    => 'ignored',
				'gemmaType'        => 'Applicatiecomponent',
				'objectId'         => 'obj-1',
				'bivScoreBbn'      => 'hoog',
				'integrity'        => 'midden',
				'section'          => 'elements',
				'model_identifier' => 'model-1',
				'properties'       => ['a' => 'b'],
			],
			'el-gemma-list' => ['name' => 'Lijst', 'gemmaType' => ['_value' => 'Bedrijfsproces'], 'documentation' => 'Doc only'],
			'el-gemma-idx'  => ['gemmaType' => ['Dienst']],
			'el-gemma-none' => ['gemmaType' => ['other' => 'x']],
			'el-xsi'        => ['name' => 'Service', 'xml' => ['_attributes' => ['xsi:type' => 'archimate:BusinessService']]],
			'el-xsi-plain'  => ['xml' => ['_attributes' => ['xsi:type' => 'ApplicationComponent']]],
			'el-bare'       => ['availability' => 'laag', 'confidentiality' => 'hoog'],
			''              => ['name' => 'Keyed by the empty string'],
		];

	}//end elements()

	/**
	 * The cases: node data as the XML parser produces it.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function cases(): array {
		$node = static fn (array $attributes, array $extra = []): array => (['_attributes' => $attributes] + $extra);

		return [
			'single node without attributes' => [['label' => 'x']],
			'single node without identifier' => [$node(['elementRef' => 'el-gemma'])],
			'single bare node'               => [$node(['identifier' => 'n1'])],
			'geometry and string label'      => [[$node(['identifier' => 'n1', 'x' => '10', 'y' => '20', 'w' => '30', 'h' => '40', 'xsi:type' => 'Label'], ['label' => 'Tekst'])]],
			'array label and style'          => [[
				$node(
					['identifier' => 'n1', 'xsi:type' => 'Element', 'elementRef' => 'el-gemma'],
					['label' => ['_value' => 'Eigen label'], 'style' => ['fillColor' => ['_attributes' => ['r' => '1', 'g' => '2', 'b' => '3']]]]
				),
			],
			],
			'label array without value'      => [[$node(['identifier' => 'n1', 'elementRef' => 'el-gemma'], ['label' => ['other' => 'x']])]],
			'every element kind'             => [[
				$node(['identifier' => 'a', 'elementRef' => 'el-gemma']),
				$node(['identifier' => 'b', 'elementRef' => 'el-gemma-list']),
				$node(['identifier' => 'c', 'elementRef' => 'el-gemma-idx']),
				$node(['identifier' => 'd', 'elementRef' => 'el-gemma-none']),
				$node(['identifier' => 'e', 'elementRef' => 'el-xsi']),
				$node(['identifier' => 'f', 'elementRef' => 'el-xsi-plain']),
				$node(['identifier' => 'g', 'elementRef' => 'el-bare']),
				$node(['identifier' => 'h', 'elementRef' => 'unknown']),
				$node(['identifier' => 'i']),
				$node(['identifier' => 'j', 'xsi:type' => 'Container', 'elementRef' => 'el-gemma']),
			],
			],
			'nested children'                => [[
				$node(
					['identifier' => 'root', 'elementRef' => 'el-xsi'],
					[
						'node' => [
							$node(['identifier' => 'child-1'], ['node' => $node(['identifier' => 'grandchild'])]),
							$node(['identifier' => 'child-2', 'elementRef' => 'el-bare']),
						],
					]
				),
				$node(['identifier' => 'sibling'], ['node' => ['_attributes' => []]]),
			],
			],
		];

	}//end cases()

	/**
	 * The view nodes match the recording.
	 *
	 * @param mixed $nodeData The node data.
	 *
	 * @return void
	 *
	 * @dataProvider cases
	 */
	public function testViewNodesMatchTheRecording(mixed $nodeData): void {
		$service = (new ReflectionClass(ArchiMateImportService::class))->newInstanceWithoutConstructor();
		$method  = new ReflectionMethod($service, 'extractViewNodesRecursively');
		$nodes   = $method->invoke($service, $nodeData, self::elements());

		$this->check(key: (string) json_encode(value: $nodeData), value: ['nodes' => $nodes]);

	}//end testViewNodesMatchTheRecording()

	/**
	 * Compares a value with its recording, or records it when RECORD_GOLDEN is set.
	 *
	 * @param string               $key   The case key.
	 * @param array<string, mixed> $value The observed value.
	 *
	 * @return void
	 */
	private function check(string $key, array $value): void {
		$json  = json_decode(json: (string) json_encode(value: $value), associative: true);
		$file  = self::golden();
		$saved = [];
		if (file_exists(filename: $file) === true) {
			$saved = json_decode(json: (string) file_get_contents(filename: $file), associative: true);
		}

		if (getenv(name: 'RECORD_GOLDEN') !== false) {
			$saved[$key] = $json;
			file_put_contents(filename: $file, data: json_encode(value: $saved, flags: (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "\n");
			$this->addToAssertionCount(count: 1);
			return;
		}

		$this->assertArrayHasKey(key: $key, array: $saved, message: 'No golden for ' . $key);
		$this->assertSame(expected: $saved[$key], actual: $json);

	}//end check()
}//end class
