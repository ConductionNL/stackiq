<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction b.v. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\Stackiq\Service\ArchiMateExportService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Pins the array to XML conversion of the AMEF export: attributes from
 * underscored keys and the legacy `_attributes` bag, text content, skipped
 * keys, filtered nested structures and repeated child elements.
 *
 * @spec openspec/specs/archimate-export/spec.md
 */
class ArchiMateExportArrayToXmlTest extends TestCase {
	/**
	 * Build the service without its logger; the conversion does not log.
	 *
	 * @return ArchiMateExportService
	 */
	private function makeService(): ArchiMateExportService {
		$reflection = new ReflectionClass(ArchiMateExportService::class);
		return $reflection->newInstanceWithoutConstructor();
	}

	/**
	 * Convert data onto a fresh root element and return the XML string.
	 *
	 * @param array  $data The data to convert.
	 * @param string $root The root element markup.
	 *
	 * @return string
	 */
	private function convert(array $data, string $root='<root/>'): string {
		$xml    = new \SimpleXMLElement($root);
		$result = $this->makeService()->arrayToXml(data: $data, xml: $xml);
		$this->assertSame($xml, $result);
		return (string)$xml->asXML();
	}

	/**
	 * Underscored keys become attributes; malformed and duplicate ones are skipped.
	 *
	 * @return void
	 */
	public function testUnderscoredKeysBecomeAttributes(): void {
		$out = $this->convert(
			data: [
				'_identifier'  => 'id-1',
				'___bad'       => 'skipped',
				'_'            => 'empty',
				'_xml__lang'   => 'nl',
				'_xml:lang'    => 'en',
				'_xsi__type'   => 'Element',
				'_foo__bar'    => 'unknown-ns',
				'_identifier2' => 2,
			]
		);
		$this->assertSame(
			"<?xml version=\"1.0\"?>\n<root xmlns:xsi=\"http://www.w3.org/2001/XMLSchema-instance\" identifier=\"id-1\" xml:lang=\"nl\" xsi:type=\"Element\" bar=\"unknown-ns\" identifier2=\"2\"/>\n",
			$out
		);
	}

	/**
	 * The `_value` and `_text` keys set the node text, last one wins.
	 *
	 * @return void
	 */
	public function testValueAndTextKeysSetTheNodeText(): void {
		$this->assertSame("<?xml version=\"1.0\"?>\n<root>hello</root>\n", $this->convert(data: ['_value' => 'hello']));
		$this->assertSame("<?xml version=\"1.0\"?>\n<root>second</root>\n", $this->convert(data: ['_value' => 'first', '_text' => 'second']));
		$this->assertSame("<?xml version=\"1.0\"?>\n<root>7</root>\n", $this->convert(data: ['_text' => 7]));
	}

	/**
	 * The legacy `_attributes` bag adds attributes after the underscored keys,
	 * skipping colon-prefixed keys and attributes that are already present.
	 *
	 * @return void
	 */
	public function testLegacyAttributesBag(): void {
		$out = $this->convert(
			data: [
				'_identifier' => 'id-1',
				'_attributes' => [
					'identifier' => 'dup',
					':shadow'    => 'skipped',
					'xml__lang'  => 'nl',
					'xml:lang'   => 'dup-clean',
					'xsi:type'   => 'Element',
					'foo__bar'   => 'unknown-ns',
					'name'       => 'Plain',
				],
			]
		);
		$this->assertSame(
			"<?xml version=\"1.0\"?>\n<root xmlns:xsi=\"http://www.w3.org/2001/XMLSchema-instance\" identifier=\"id-1\" xml:lang=\"nl\" xsi:type=\"Element\" bar=\"unknown-ns\" name=\"Plain\"/>\n",
			$out
		);
	}

	/**
	 * A non-array `_attributes` value is neither an attribute nor a child.
	 *
	 * @return void
	 */
	public function testScalarAttributesBagIsIgnored(): void {
		$this->assertSame("<?xml version=\"1.0\"?>\n<root/>\n", $this->convert(data: ['_attributes' => 'x']));
	}

	/**
	 * Children: skipped keys, scalar, nested, lists of arrays and of scalars.
	 *
	 * @return void
	 */
	public function testChildElements(): void {
		$out = $this->convert(
			data: [
				':shadow'      => 'skipped',
				0              => 'numeric',
				'availability' => 'skipped',
				'gemmaType'    => 'skipped',
				'name'         => 'Plain',
				'count'        => 3,
				'empty'        => [],
				'nested'       => ['_id' => 'n1', 'label' => 'Inner'],
				'item'         => [['_id' => 'a'], 'scalar', ['label' => 'b']],
			]
		);
		$this->assertSame(
			"<?xml version=\"1.0\"?>\n<root><name>Plain</name><count>3</count><nested id=\"n1\"><label>Inner</label></nested><item id=\"a\"/><item>scalar</item><item><label>b</label></item></root>\n",
			$out
		);
	}

	/**
	 * Nested property containers drop property-like and invalid-tag fields;
	 * a container left empty writes no element.
	 *
	 * @return void
	 */
	public function testNestedContainersAreFiltered(): void {
		$out = $this->convert(
			data: [
				'properties'        => [
					'availabilityPrimaryReason' => 'skipped',
					'objectId'                  => 'skipped',
					'bad(name)'                 => 'skipped',
					'kept'                      => 'yes',
					'deep'                      => ['integrity' => 'skipped', 'ok' => 'fine'],
					5                           => 'numeric',
				],
				'viewNodes'         => 'scalar-is-not-filtered',
				'elementProperties' => [['confidentiality' => 'x']],
			]
		);
		$this->assertSame(
			"<?xml version=\"1.0\"?>\n<root><properties><kept>yes</kept><deep><ok>fine</ok></deep></properties><viewNodes>scalar-is-not-filtered</viewNodes></root>\n",
			$out
		);
	}

	/**
	 * Call a private helper of the service.
	 *
	 * @param string $method The helper name.
	 * @param array  $args   The positional arguments.
	 *
	 * @return mixed
	 */
	private function callHelper(string $method, array $args): mixed {
		$ref = new ReflectionMethod(ArchiMateExportService::class, $method);
		$ref->setAccessible(true);
		return $ref->invokeArgs($this->makeService(), $args);
	}

	/**
	 * Namespaced keys split on the first `__`, then on the first `:`.
	 *
	 * @return void
	 */
	public function testSplitNamespacedKey(): void {
		$cases = [
			'xsi__type'  => ['xsi', 'type'],
			'xml:lang'   => ['xml', 'lang'],
			'a__b__c'    => ['a', 'b__c'],
			'a:b:c'      => ['a', 'b:c'],
			'a__b:c'     => ['a', 'b:c'],
			'__type'     => [null, '__type'],
			'xsi__'      => [null, 'xsi__'],
			':lang'      => [null, ':lang'],
			'xml:'       => [null, 'xml:'],
			'__a:b'      => ['__a', 'b'],
			'plain'      => [null, 'plain'],
			''           => [null, ''],
		];
		foreach ($cases as $key => $expected) {
			$this->assertSame($expected, $this->callHelper('splitNamespacedKey', [(string)$key]), (string)$key);
		}
	}

	/**
	 * Problematic fields are dropped by exact name, by prefix and by invalid
	 * tag characters, recursively; numeric keys are dropped too.
	 *
	 * @return void
	 */
	public function testFilterProblematicFields(): void {
		$data   = [
			'availability'        => 'exact',
			'availabilityReason'  => 'prefix',
			'myavailability'      => 'kept',
			'a(b'                 => 'paren',
			'a,b'                 => 'comma',
			'a<b'                 => 'lt',
			'a>b'                 => 'gt',
			'a/b'                 => 'slash',
			'a\b'                => 'backslash',
			'a)b'                 => 'close',
			'a.b'                 => 'kept-dot',
			3                     => 'numeric',
			'nested'              => ['integrityLevel' => 'x', 'ok' => ['deep' => 1, 'objectId' => 2], 7 => 'n'],
			'scalar'              => null,
		];
		$result = $this->callHelper('filterProblematicFields', [$data, ['availability', 'integrity', 'objectId']]);
		$this->assertSame(
			[
				'myavailability' => 'kept',
				'a.b'            => 'kept-dot',
				'nested'         => ['ok' => ['deep' => 1]],
				'scalar'         => null,
			],
			$result
		);
		// An empty pattern is a prefix of every name.
		$this->assertSame([], $this->callHelper('filterProblematicFields', [['x' => 1, 'y' => 2], ['']]));
	}
}
