<?php

/**
 * The licence seat fields of the catalogue contract, as the import sees them.
 *
 * @category Tests
 * @package  OCA\Stackiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/contracts-licence-seats/specs/licence-seats/spec.md#requirement-req-lsc-001-a-contract-shall-record-its-licence-metric-and-the-number-of-licences-bought-and-in-use
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Settings;

use OCA\Stackiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Merges the register.d fragment into the monolith with SettingsService's own
 * merge, the one loadSettings() runs before the import.
 *
 * @coversNothing
 */
class LicenceSeatsDeclarationTest extends TestCase {

	/**
	 * The catalogContract schema after the fragment is merged in.
	 *
	 * @return array<string, mixed> The schema.
	 */
	private function contractSchema(): array {
		$dir      = __DIR__ . '/../../../lib/Settings';
		$base     = json_decode((string) file_get_contents($dir . '/softwarecatalogus_register.json'), true);
		$fragment = json_decode((string) file_get_contents($dir . '/register.d/contracts-licence-seats.json'), true);

		$merge  = new ReflectionMethod(SettingsService::class, 'deepMergeConfig');
		$merged = $merge->invoke(null, $base, $fragment);

		return $merged['components']['schemas']['catalogContract'];
	}//end contractSchema()

	/**
	 * The metric carries the six values of the spec.
	 *
	 * @return void
	 */
	public function testTheMetricCarriesSixValues(): void {
		$metric = $this->contractSchema()['properties']['licenceMetric'];

		$this->assertSame('string', $metric['type']);
		$this->assertSame(
			['Per named user', 'Per concurrent user', 'Per device', 'Per inhabitant', 'Per organisation', 'Other'],
			$metric['enum']
		);
		$this->assertSame($metric['enum'], array_keys($metric['x-enum-labels']));
	}//end testTheMetricCarriesSixValues()

	/**
	 * Both counts are whole numbers of at least 0, and none of the three is required.
	 *
	 * @return void
	 */
	public function testBothCountsAreWholeNumbersOfAtLeastZero(): void {
		$schema = $this->contractSchema();
		foreach (['licencesBought', 'licencesInUse'] as $field) {
			$this->assertSame('integer', $schema['properties'][$field]['type'], $field);
			$this->assertSame(0, $schema['properties'][$field]['minimum'], $field);
		}

		$required = ($schema['required'] ?? []);
		$this->assertSame([], array_values(array_intersect(['licenceMetric', 'licencesBought', 'licencesInUse'], $required)));
	}//end testBothCountsAreWholeNumbersOfAtLeastZero()

	/**
	 * The merge keeps every existing contract property.
	 *
	 * @return void
	 */
	public function testTheMergeKeepsTheExistingProperties(): void {
		$schema = $this->contractSchema();
		foreach (['contractType', 'cost', 'usage', 'status'] as $field) {
			$this->assertArrayHasKey($field, $schema['properties'], $field);
		}
	}//end testTheMergeKeepsTheExistingProperties()

	/**
	 * The schema version moves above the version before this change.
	 *
	 * @return void
	 */
	public function testTheSchemaVersionMovesUp(): void {
		$this->assertTrue(version_compare($this->contractSchema()['version'], '0.1.2', '>'));
	}//end testTheSchemaVersionMovesUp()
}//end class
