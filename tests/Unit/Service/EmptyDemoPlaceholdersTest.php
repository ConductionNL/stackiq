<?php

/**
 * The demo import empties placeholder lists, and the shipped demo set seeds the
 * suppliers the Supplier facet shows (insight-supplier-facet task 4).
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Unit\Service
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\Stackiq\Service\EmptyDemoPlaceholders;
use PHPUnit\Framework\TestCase;

/**
 * A list of only empty objects is emptied; every other value is kept.
 *
 * @covers \OCA\Stackiq\Service\EmptyDemoPlaceholders
 */
class EmptyDemoPlaceholdersTest extends TestCase {

	/**
	 * `[{}]` becomes `[]`, the key stays, and filled values, scalars and `@self` are untouched.
	 *
	 * @return void
	 */
	public function testAListOfOnlyEmptyObjectsIsEmptied(): void {
		$data = ['components' => ['objects' => [[
			'@self'      => ['register' => 'stackiq', 'schema' => 'module', 'slug' => 'm-1'],
			'name'       => 'One',
			'modules'    => [[]],
			'diensten'   => [[], []],
			'properties' => [[]],
			'provider'   => '@ref:organization-1',
			'tags'       => ['a', 'b'],
			'filled'     => [['id' => 'x']],
			'mixed'      => [[], ['id' => 'y']],
			'empty'      => [],
			'zero'       => 0,
			'none'       => null,
		]]]];

		$object = (new EmptyDemoPlaceholders())->strip(data: $data)['components']['objects'][0];

		$this->assertSame(expected: [], actual: $object['modules']);
		$this->assertSame(expected: [], actual: $object['diensten']);
		$this->assertSame(expected: [], actual: $object['properties']);
		$this->assertSame(expected: $data['components']['objects'][0]['@self'], actual: $object['@self']);
		foreach (['name', 'provider', 'tags', 'filled', 'mixed', 'empty', 'zero', 'none'] as $kept) {
			$this->assertSame(expected: $data['components']['objects'][0][$kept], actual: $object[$kept], message: $kept);
		}
	}//end testAListOfOnlyEmptyObjectsIsEmptied()

	/**
	 * A descriptor without objects, and an object that is not an array, pass through unchanged.
	 *
	 * @return void
	 */
	public function testADescriptorWithoutObjectsIsUnchanged(): void {
		$stripper = new EmptyDemoPlaceholders();

		$this->assertSame(expected: ['info' => []], actual: $stripper->strip(data: ['info' => []]));
		$this->assertSame(
			expected: ['components' => ['objects' => ['not-an-object']]],
			actual: $stripper->strip(data: ['components' => ['objects' => ['not-an-object']]])
		);
	}//end testADescriptorWithoutObjectsIsUnchanged()

	/**
	 * The shipped demo modules name two suppliers that are themselves seeded, and their values pass the
	 * dependent-value rules the importer applies: a licence only with an open source licence type, and no
	 * collaboration type on a municipality or a supplier.
	 *
	 * @return void
	 */
	public function testTheShippedDemoSeedsTwoSuppliersThatImport(): void {
		$file = __DIR__.'/../../../lib/Settings/stackiq_mock_register.json';
		$data = json_decode((string) file_get_contents($file), true);
		$this->assertIsArray(actual: $data);

		$organisations = [];
		$providers = [];
		foreach ($data['components']['objects'] as $object) {
			if (($object['@self']['register'] ?? '') !== 'stackiq') {
				continue;
			}

			$schema = ($object['@self']['schema'] ?? '');
			if ($schema === 'organization') {
				$organisations[$object['@self']['slug']] = $object;
				$this->assertFalse(
					condition: in_array(($object['type'] ?? ''), ['Municipality', 'Supplier'], true) === true && isset($object['samenwerkingtype']) === true,
					message: $object['@self']['slug'].' sets a collaboration type the importer refuses for its type'
				);
			}

			if ($schema === 'module') {
				$this->assertFalse(
					condition: isset($object['licence']) === true && ($object['licentietype'] ?? 'Closed source') !== 'Open source',
					message: $object['@self']['slug'].' sets a licence without an open source licence type'
				);
				if (is_string($object['provider'] ?? null) === true) {
					$providers[] = substr($object['provider'], strlen('@ref:'));
				}
			}
		}//end foreach

		$counts = array_count_values($providers);
		$this->assertSame(expected: ['organization-voorbeeld-name-2-2', 'organization-voorbeeld-name-3-3'], actual: array_keys($counts));
		$this->assertSame(expected: 1, actual: $counts['organization-voorbeeld-name-3-3']);
		foreach (array_keys($counts) as $slug) {
			$this->assertArrayHasKey(key: $slug, array: $organisations);
		}
	}//end testTheShippedDemoSeedsTwoSuppliersThatImport()
}//end class
