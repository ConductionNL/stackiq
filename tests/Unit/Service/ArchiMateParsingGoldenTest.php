<?php

/**
 * Golden test for parsing ArchiMate XML.
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
 * What ArchiMateImportService's XML conversion, property definition map and
 * view relationship extraction produce, as recorded.
 *
 * @covers \OCA\Stackiq\Service\ArchiMateImportService
 *
 * @spec openspec/specs/archimate-import/spec.md
 */
class ArchiMateParsingGoldenTest extends TestCase {

	/**
	 * The golden file.
	 *
	 * @return string
	 */
	private static function golden(): string {
		return __DIR__ . '/golden/archiMateParsing.json';

	}//end golden()

	/**
	 * XML documents to convert.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function xmlCases(): array {
		return [
			'leaf with text'            => ['<name xml:lang="nl">  Zaak  </name>'],
			'empty leaf'                => ['<name/>'],
			'attributes and children'   => ['<model identifier="m1" version="3.1"><name>Model</name><documentation>Doc</documentation></model>'],
			'repeated children'         => ['<elements><element identifier="a"/><element identifier="b"/><element identifier="c"><name>C</name></element></elements>'],
			'namespaced attributes'     => ['<model xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><element identifier="e1" xsi:type="BusinessActor"><name>Actor</name></element></model>'],
			'text beside children'      => ['<p>Before<b>bold</b>after</p>'],
			'repeated leaf text'        => ['<list><item>one</item><item>two</item><item/></list>'],
			'nested namespace prefixes' => ['<a xmlns:x="urn:x" x:one="1"><b x:two="2">t</b></a>'],
		];

	}//end xmlCases()

	/**
	 * The XML conversion matches the recording.
	 *
	 * @param string $xml The XML document.
	 *
	 * @return void
	 *
	 * @dataProvider xmlCases
	 */
	public function testXmlToArrayMatchesTheRecording(string $xml): void {
		$result = $this->service()->xmlToArray(new \SimpleXMLElement($xml));

		$this->check(key: 'xml ' . $xml, value: ['result' => $result]);

	}//end testXmlToArrayMatchesTheRecording()

	/**
	 * Parsed documents with property definitions.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function propertyDefinitionCases(): array {
		$def = static fn (string $id, mixed $name): array => ['_attributes' => ['identifier' => $id], 'name' => $name];

		return [
			'none'                     => [[]],
			'listed, camel case'       => [['propertyDefinitions' => ['propertyDefinition' => [$def('p1', 'Eigenaar'), $def('p2', ['_value' => 'Status']), $def('p3', ['other' => 'x']), ['name' => 'no id'], ['_attributes' => ['identifier' => 'p5']]]]]],
			'single, snake case'       => [['property_definitions' => ['propertyDefinition' => $def('p1', ['_value' => 'Eigenaar'])]]],
			'single with plain name'   => [['propertyDefinitions' => ['propertyDefinition' => $def('p1', 'Naam')]]],
			'single without a name'    => [['propertyDefinitions' => ['propertyDefinition' => ['_attributes' => ['identifier' => 'p1']]]]],
			'both names, camel wins'   => [['propertyDefinitions' => ['propertyDefinition' => [$def('c', 'Camel')]], 'property_definitions' => ['propertyDefinition' => [$def('s', 'Snake')]]]],
			'section without entries'  => [['propertyDefinitions' => ['other' => []]]],
		];

	}//end propertyDefinitionCases()

	/**
	 * The property definition map matches the recording, and the second call answers from the cache.
	 *
	 * @param array<string, mixed> $data The parsed document.
	 *
	 * @return void
	 *
	 * @dataProvider propertyDefinitionCases
	 */
	public function testPropertyDefinitionMapMatchesTheRecording(array $data): void {
		$service = $this->service();
		$method  = new ReflectionMethod($service, 'extractPropertyDefinitionMap');
		$first   = $method->invoke($service, $data);
		$cached  = $method->invoke($service, ['propertyDefinitions' => ['propertyDefinition' => [['_attributes' => ['identifier' => 'ignored'], 'name' => 'Ignored']]]]);

		$this->check(key: 'properties ' . (string) json_encode(value: $data), value: ['first' => $first, 'cached' => $cached]);

	}//end testPropertyDefinitionMapMatchesTheRecording()

	/**
	 * Connection data of a view.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function connectionCases(): array {
		$attributes = static fn (array $extra = []): array => ['_attributes' => (['identifier' => 'c1', 'relationshipRef' => 'r1', 'source' => 'n1', 'target' => 'n2', 'xsi:type' => 'Relationship'] + $extra)];

		return [
			'single without attributes' => [['label' => 'x']],
			'single, missing source'    => [['_attributes' => ['identifier' => 'c1', 'target' => 'n2']]],
			'single, missing id'        => [['_attributes' => ['source' => 'n1', 'target' => 'n2']]],
			'single, missing target'    => [['_attributes' => ['identifier' => 'c1', 'source' => 'n1']]],
			'single plain'              => [$attributes()],
			'listed with bendpoints'    => [[
				$attributes() + ['bendpoint' => [['_attributes' => ['x' => '5', 'y' => '6']], ['_attributes' => ['x' => '7']], ['no' => 'attributes']]],
				$attributes(['identifier' => 'c2', 'xsi:type' => 'Line']) + ['bendpoint' => ['_attributes' => ['x' => '1', 'y' => '2']]],
			],
			],
			'labels and style'          => [[
				$attributes() + ['label' => 'Tekst'],
				$attributes(['identifier' => 'c2']) + ['label' => ['_value' => 'Waarde'], 'style' => ['font' => ['_attributes' => ['name' => 'Arial', 'size' => '9']]]],
				$attributes(['identifier' => 'c3']) + ['label' => ['other' => 'x'], 'style' => []],
				$attributes(['identifier' => 'c4']) + ['style' => ['lineColor' => ['_attributes' => ['r' => '1', 'g' => '2', 'b' => '3']]]],
			],
			],
		];

	}//end connectionCases()

	/**
	 * The view relationships match the recording.
	 *
	 * @param mixed $connectionData The connection data.
	 *
	 * @return void
	 *
	 * @dataProvider connectionCases
	 */
	public function testViewRelationshipsMatchTheRecording(mixed $connectionData): void {
		$service = $this->service();
		$method  = new ReflectionMethod($service, 'extractViewRelationshipsRecursively');

		$this->check(key: 'connections ' . (string) json_encode(value: $connectionData), value: ['result' => $method->invoke($service, $connectionData)]);

	}//end testViewRelationshipsMatchTheRecording()

	/**
	 * A service without collaborators; the parsing does not use them.
	 *
	 * @return ArchiMateImportService
	 */
	private function service(): ArchiMateImportService {
		return (new ReflectionClass(ArchiMateImportService::class))->newInstanceWithoutConstructor();

	}//end service()

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
