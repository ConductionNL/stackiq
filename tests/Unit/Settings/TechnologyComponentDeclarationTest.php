<?php

/**
 * The technologyComponent schema and usage.runsOn, as the import sees them.
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
 * @spec openspec/changes/operations-technology-components/specs/technology-components/spec.md#requirement-req-tco-001-an-organisation-shall-record-technology-components-with-a-class-a-status-and-an-owner
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
 * Merges every register.d fragment into the monolith with SettingsService's own
 * merge and version rule, the ones loadSettings() runs before the import.
 *
 * @coversNothing
 */
class TechnologyComponentDeclarationTest extends TestCase {

	/**
	 * The merged register, and the usage schema before this change's fragment.
	 *
	 * @param bool $withFragment Whether to merge this change's fragment.
	 *
	 * @return array<string, mixed>
	 */
	private function merged(bool $withFragment = true): array {
		$dir    = __DIR__ . '/../../../lib/Settings';
		$merged = json_decode((string) file_get_contents($dir . '/softwarecatalogus_register.json'), true);
		$merge  = new ReflectionMethod(SettingsService::class, 'deepMergeConfig');
		$keep   = new ReflectionMethod(SettingsService::class, 'keepHighestSchemaVersions');

		$files = glob($dir . '/register.d/*.json');
		sort($files);
		foreach ($files as $file) {
			if ($withFragment === false && basename($file) === 'operations-technology-components.json') {
				continue;
			}

			$fragment = json_decode((string) file_get_contents($file), true);
			$merged   = $keep->invoke(null, $merged, $merge->invoke(null, $merged, $fragment));
		}

		return $merged;
	}//end merged()

	/**
	 * The component has the design's properties and required fields, and points at its own kind.
	 *
	 * @return void
	 */
	public function testTheComponentHasTheDesignedProperties(): void {
		$schema = $this->merged()['components']['schemas']['technologyComponent'];

		$this->assertSame(
			['name', 'class', 'archimateType', 'organization', 'status', 'manufacturer', 'model', 'serialNumber', 'assetTag', 'location', 'software', 'endOfSupport', 'eolSource', 'eolUpdatedOn', 'runsOn'],
			array_keys($schema['properties'])
		);
		$this->assertSame(['name', 'class', 'organization'], $schema['required']);
		$this->assertSame('#/components/schemas/organization', $schema['properties']['organization']['$ref']);
		$this->assertSame('#/components/schemas/moduleVersion', $schema['properties']['software']['$ref']);
		$this->assertSame('#/components/schemas/technologyComponent', $schema['properties']['runsOn']['items']['$ref']);
		$this->assertSame('related-object', $schema['properties']['runsOn']['items']['objectConfiguration']['handling']);
		$this->assertSame('date', $schema['properties']['endOfSupport']['format']);
		$this->assertCount(11, $schema['properties']['class']['enum']);
		$this->assertSame('In use', $schema['properties']['status']['default']);
		foreach (['class', 'archimateType', 'status'] as $enumField) {
			$this->assertSame($schema['properties'][$enumField]['enum'], array_keys($schema['properties'][$enumField]['x-enum-labels']), $enumField);
		}
	}//end testTheComponentHasTheDesignedProperties()

	/**
	 * The component is in the stackiq register with its own table, and nowhere else.
	 *
	 * @return void
	 */
	public function testTheComponentIsInTheStackiqRegister(): void {
		$registers = $this->merged()['components']['registers'];

		$this->assertContains('technologyComponent', $registers['stackiq']['schemas']);
		$this->assertSame(1, count(array_keys($registers['stackiq']['schemas'], 'technologyComponent', true)));
		$this->assertSame(['magicMapping' => true, 'autoCreateTable' => true], $registers['stackiq']['configuration']['schemas']['technologyComponent']);
		$this->assertNotContains('technologyComponent', $registers['vng-gemma']['schemas']);
	}//end testTheComponentIsInTheStackiqRegister()

	/**
	 * Every lifecycle state is a status value, and every status value is reached.
	 *
	 * @return void
	 */
	public function testTheLifecycleStatesAreTheStatusValues(): void {
		$schema    = $this->merged()['components']['schemas']['technologyComponent'];
		$lifecycle = $schema['configuration']['x-openregister-lifecycle'];
		$statuses  = $schema['properties']['status']['enum'];

		$this->assertSame('status', $lifecycle['field']);
		$states = [$lifecycle['initial'], ...$lifecycle['final']];
		foreach ($lifecycle['transitions'] as $transition) {
			$states = [...$states, ...$transition['from'], $transition['to']];
		}

		$states = array_values(array_unique($states));
		sort($states);
		$sortedStatuses = $statuses;
		sort($sortedStatuses);
		$this->assertSame($sortedStatuses, $states);
		$this->assertSame('To be phased out', $lifecycle['transitions']['phase-out']['to']);
		$this->assertSame(['In use'], $lifecycle['transitions']['phase-out']['from']);
	}//end testTheLifecycleStatesAreTheStatusValues()

	/**
	 * Only the owning organisation and catalogue admins read a component; no supplier or public group.
	 *
	 * @return void
	 */
	public function testTheReadRuleIsOrganisationScoped(): void {
		$authorization = $this->merged()['components']['schemas']['technologyComponent']['authorization'];

		foreach ($authorization['read'] as $rule) {
			$this->assertIsArray($rule, 'a bare group name would read across organisations');
			if ($rule['group'] === 'software-catalog-admins') {
				$this->assertTrue($rule['crossOrganisation']);
				continue;
			}

			$this->assertSame(['_organisation' => '$organisation'], $rule['match'], $rule['group']);
			$this->assertArrayNotHasKey('crossOrganisation', $rule, $rule['group']);
		}

		$groups = [];
		foreach (['create', 'read', 'update', 'delete'] as $action) {
			foreach ($authorization[$action] as $rule) {
				$groups[] = is_array($rule) ? $rule['group'] : $rule;
			}
		}

		foreach (['public', 'aanbod-beheerder', 'authenticated'] as $outsider) {
			$this->assertNotContains($outsider, $groups, $outsider);
		}
	}//end testTheReadRuleIsOrganisationScoped()

	/**
	 * Usage gains runsOn and a version above the one it had, so the import applies it.
	 *
	 * @return void
	 */
	public function testUsageGainsRunsOnAndAHigherVersion(): void {
		$before = $this->merged(withFragment: false)['components']['schemas']['usage'];
		$after  = $this->merged()['components']['schemas']['usage'];

		$this->assertArrayNotHasKey('runsOn', $before['properties']);
		$this->assertSame('#/components/schemas/technologyComponent', $after['properties']['runsOn']['items']['$ref']);
		$this->assertTrue(version_compare($after['version'], $before['version'], '>'));
		$this->assertSame([], array_values(array_diff(array_keys($before['properties']), array_keys($after['properties']))), 'usage lost a property');
		$this->assertSame([], array_values(array_diff(array_keys($after['properties']), [...array_keys($before['properties']), 'runsOn'])), 'usage gained more than runsOn');
	}//end testUsageGainsRunsOnAndAHigherVersion()
}//end class
