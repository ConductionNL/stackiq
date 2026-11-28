<?php

/**
 * Golden test for finding the items of an ArchiMate section.
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
 * The items ArchiMateImportService::findItemsInSection() finds, as recorded.
 *
 * @covers \OCA\Stackiq\Service\ArchiMateImportService
 *
 * @spec openspec/specs/method-decomposition/spec.md
 */
class ArchiMateSectionItemsGoldenTest extends TestCase {

	/**
	 * The golden file.
	 *
	 * @return string
	 */
	private static function golden(): string {
		return __DIR__ . '/golden/archiMateSectionItems.json';

	}//end golden()

	/**
	 * The cases: section name and section data as the XML parser produces it.
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>}>
	 */
	public static function cases(): array {
		$one = ['_attributes' => ['identifier' => 'id-1']];
		$two = ['_attributes' => ['identifier' => 'id-2']];

		return [
			'views: single diagram view'        => ['views', ['diagrams' => ['view' => $one]]],
			'views: listed diagram views'       => ['views', ['diagrams' => ['view' => [$one, $two]]]],
			'views: diagram view without attrs' => ['views', ['diagrams' => ['view' => ['name' => 'x']]]],
			'views: diagrams without views'     => ['views', ['diagrams' => ['other' => $one]]],
			'views: direct views'               => ['views', ['view' => [$one, $two]]],
			'views: direct single view'         => ['views', ['view' => $one]],
			'views: nothing'                    => ['views', ['other' => 'x']],
			'elements: model path list'         => ['elements', ['model' => ['elements' => ['element' => [$one, $two]]]]],
			'elements: model path single'       => ['elements', ['model' => ['elements' => ['element' => $one]]]],
			'elements: path to a scalar'        => ['elements', ['elements' => ['element' => 'text'], 'element' => [$two]]],
			'elements: direct tag'              => ['elements', ['element' => [$one, $two]]],
			'elements: direct scalar tag'       => ['elements', ['element' => 'text']],
			'elements: numeric keyed map'       => ['elements', ['elements' => [3 => $one, 7 => $two]]],
			'elements: empty list on path'      => ['elements', ['elements' => ['element' => []], 'elements2' => 1]],
			'relationships: section itself'     => ['relationships', ['_attributes' => ['identifier' => 'self']]],
			'organizations: items'              => ['organizations', ['organizations' => ['item' => [$one]]]],
			'property_definitions: nested'      => ['property_definitions', ['propertyDefinitions' => ['propertyDefinition' => $one]]],
			'unknown section: own tag'          => ['custom', ['custom' => [$one, $two]]],
			'unknown section: nothing'          => ['custom', ['other' => [$one]]],
			'empty section'                     => ['elements', []],
		];

	}//end cases()

	/**
	 * The items match the recording.
	 *
	 * @param string               $sectionName The section name.
	 * @param array<string, mixed> $sectionData The section data.
	 *
	 * @return void
	 *
	 * @dataProvider cases
	 */
	public function testItemsMatchTheRecording(string $sectionName, array $sectionData): void {
		$service = (new ReflectionClass(ArchiMateImportService::class))->newInstanceWithoutConstructor();
		$method  = new ReflectionMethod($service, 'findItemsInSection');
		$items   = $method->invoke($service, $sectionData, $sectionName);

		$this->check(key: $sectionName . ' ' . (string) json_encode(value: $sectionData), value: ['items' => $items]);

	}//end testItemsMatchTheRecording()

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
