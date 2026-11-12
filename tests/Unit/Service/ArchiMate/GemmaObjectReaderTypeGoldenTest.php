<?php

/**
 * Golden test for GemmaObjectReader::gemmaType().
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service\ArchiMate
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service\ArchiMate;

use OCA\Stackiq\Service\ArchiMate\GemmaObjectReader;
use PHPUnit\Framework\TestCase;

/**
 * Pins how the GEMMA type of an element is found.
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Unit\Service\ArchiMate
 */
final class GemmaObjectReaderTypeGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/../../../fixtures/archimate/gemma-type.json';

	/**
	 * Every element yields the recorded type.
	 *
	 * @return void
	 */
	public function testGemmaTypeMatchesGolden(): void {
		$elements = [
			'nothing'              => [],
			'direct'               => ['gemmaType' => 'Referentiecomponent'],
			'direct later name'    => ['gemma_type' => 'Bedrijfsfunctie'],
			'direct order'         => ['GEMMAType' => 'second', 'gemmatype' => 'first'],
			'direct empty'         => ['gemmaType' => '', 'GemmaType' => '0', 'gemma_type' => 'kept'],
			'direct array value'   => ['gemmaType' => ['_value' => 'wrapped']],
			'direct list value'    => ['gemmaType' => ['listed']],
			'direct number'        => ['gemmaType' => 7],
			'mapped'               => ['_propertyMapping' => ['gemmaKind' => 'GEMMA type'], 'gemmaKind' => 'Applicatiedienst'],
			'mapped case'          => ['_propertyMapping' => ['x' => 'gEmMa object TYPE'], 'x' => 'Mixed'],
			'mapped only gemma'    => ['_propertyMapping' => ['x' => 'GEMMA naam'], 'x' => 'No'],
			'mapped only type'     => ['_propertyMapping' => ['x' => 'Object type'], 'x' => 'No'],
			'mapped empty value'   => ['_propertyMapping' => ['x' => 'GEMMA type', 'y' => 'gemma-type'], 'x' => '', 'y' => 'Second'],
			'mapped missing value' => ['_propertyMapping' => ['x' => 'GEMMA type']],
			'mapped numeric label' => ['_propertyMapping' => ['x' => 42], 'x' => 'No'],
			'mapped numeric key'   => ['_propertyMapping' => [3 => 'GEMMA type'], 3 => 'Indexed'],
			'direct beats mapped'  => ['gemmaType' => 'direct', '_propertyMapping' => ['x' => 'GEMMA type'], 'x' => 'mapped'],
			'mapped non-scalar'    => ['_propertyMapping' => ['x' => 'GEMMA type'], 'x' => [['deep']]],
		];
		$reader = (new \ReflectionClass(GemmaObjectReader::class))->newInstanceWithoutConstructor();

		$actual = [];
		foreach ($elements as $name => $element) {
			$actual[$name] = $reader->gemmaType($element);
		}

		if (getenv('GOLDEN_WRITE') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT) . "\n");
		}

		$this->assertSame(json_decode((string) file_get_contents(self::FIXTURE), true), $actual);

	}//end testGemmaTypeMatchesGolden()
}//end class
