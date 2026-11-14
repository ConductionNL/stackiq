<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/landscape-dependent-field-options/specs/dependent-field-options/spec.md#requirement-req-dfo-001-a-value-outside-its-dependent-list-is-refused-on-save
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Settings;

use OCA\Stackiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * REQ-DFO-001: the dependent value tables OpenRegister enforces, read from the register as it ships.
 *
 * @coversNothing
 */
final class DependentFieldOptionsTest extends TestCase {
	/**
	 * The merged schemas: the monolith with every register.d fragment, merged as the import merges them.
	 *
	 * @return array<string, mixed>
	 */
	private function schemas(): array {
		$dir = __DIR__ . '/../../../lib/Settings';
		$merged = json_decode((string)file_get_contents($dir . '/softwarecatalogus_register.json'), true);
		$merge = new ReflectionMethod(SettingsService::class, 'deepMergeConfig');
		$keep = new ReflectionMethod(SettingsService::class, 'keepHighestSchemaVersions');
		$files = glob($dir . '/register.d/*.json');
		sort($files);
		foreach ($files as $file) {
			$before = $merged;
			$merged = $keep->invoke(null, $before, $merge->invoke(null, $merged, json_decode((string)file_get_contents($file), true)));
		}

		return $merged['components']['schemas'];
	}//end schemas()

	public function testAModulesLicenceIsAllowedOnlyForOpenSource(): void {
		$module = $this->schemas()['module'];
		$licence = $module['properties']['licence'];
		$table = $licence['x-openregister-dependent-values'];

		self::assertSame('licentietype', $table['controlledBy']);
		self::assertSame([], $table['allowed']['Closed source']);
		// Every licence the enum offers is allowed for open source, and nothing else.
		self::assertSame($licence['enum'], $table['allowed']['Open source']);
		$controllers = array_keys($table['allowed']);
		sort($controllers);
		$types = $module['properties']['licentietype']['enum'];
		sort($types);
		self::assertSame($types, $controllers, 'every licence type has a row');
		self::assertTrue(version_compare($module['version'], '0.3.13', '>='), $module['version']);
	}//end testAModulesLicenceIsAllowedOnlyForOpenSource()

	public function testACollaborationTypeIsAllowedOnlyForACollaboration(): void {
		$organisation = $this->schemas()['organization'];
		$samenwerkingtype = $organisation['properties']['samenwerkingtype'];
		$table = $samenwerkingtype['x-openregister-dependent-values'];

		self::assertSame('type', $table['controlledBy']);
		self::assertSame($samenwerkingtype['enum'], $table['allowed']['Collaboration']);
		foreach (array_diff($organisation['properties']['type']['enum'], ['Collaboration']) as $type) {
			self::assertSame([], $table['allowed'][$type], $type);
		}
		self::assertTrue(version_compare($organisation['version'], '0.5.6', '>='), $organisation['version']);
	}//end testACollaborationTypeIsAllowedOnlyForACollaboration()

	public function testTheCollaborationTypeEnumNoLongerHoldsItsOwnName(): void {
		$enum = $this->schemas()['organization']['properties']['samenwerkingtype']['enum'];

		self::assertNotContains('samenwerkingtype', $enum);
		self::assertSame(array_values(array_unique($enum)), $enum, 'the enum has no duplicates after the merge');
	}//end testTheCollaborationTypeEnumNoLongerHoldsItsOwnName()
}//end class
