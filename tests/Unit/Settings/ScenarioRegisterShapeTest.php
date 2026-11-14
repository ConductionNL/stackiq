<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Settings;

use OCA\Stackiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * REQ-FSS-004 (architecture-future-state-scenarios, task 3): the scenario
 * schemas are in the stackiq register with magic mapping, their lifecycle
 * moves between status values only, and the demo scenario is seeded.
 *
 * @coversNothing
 */
final class ScenarioRegisterShapeTest extends TestCase {
	/**
	 * The register with every fragment merged the way the loader merges them.
	 * @return array<string,mixed>
	 */
	private function merged(): array {
		$dir = __DIR__ . '/../../../lib/Settings';
		$merged = json_decode((string)file_get_contents($dir . '/softwarecatalogus_register.json'), true);
		$merge = new ReflectionMethod(SettingsService::class, 'deepMergeConfig');
		$keep = new ReflectionMethod(SettingsService::class, 'keepHighestSchemaVersions');

		$files = glob($dir . '/register.d/*.json');
		sort($files);
		foreach ($files as $file) {
			$before = $merged;
			$merged = $merge->invoke(null, $merged, json_decode((string)file_get_contents($file), true));
			$merged = $keep->invoke(null, $before, $merged);
		}

		return $merged;
	}//end merged()

	public function testTheRegisterListsBothSchemasWithMagicMapping(): void {
		$register = $this->merged()['components']['registers']['stackiq'];

		foreach (['scenario', 'scenarioChange'] as $slug) {
			self::assertContains($slug, $register['schemas']);
			self::assertSame(['magicMapping' => true, 'autoCreateTable' => true], $register['configuration']['schemas'][$slug]);
		}

		// The base register's own schemas are still there: the list is appended to, not replaced.
		self::assertContains('usage', $register['schemas']);
		self::assertSame(count($register['schemas']), count(array_unique($register['schemas'])));
	}//end testTheRegisterListsBothSchemasWithMagicMapping()

	public function testEveryLifecycleStateIsAStatusValue(): void {
		$scenario = $this->merged()['components']['schemas']['scenario'];
		$lifecycle = $scenario['configuration']['x-openregister-lifecycle'];
		$statuses = $scenario['properties']['status']['enum'];

		self::assertSame('status', $lifecycle['field']);
		self::assertContains($lifecycle['initial'], $statuses);
		self::assertSame($scenario['properties']['status']['default'], $lifecycle['initial']);
		foreach ($lifecycle['final'] as $final) {
			self::assertContains($final, $statuses);
		}

		self::assertSame(['propose', 'adopt', 'reject', 'rework'], array_keys($lifecycle['transitions']));
		foreach ($lifecycle['transitions'] as $name => $transition) {
			self::assertContains($transition['to'], $statuses, $name);
			foreach ($transition['from'] as $from) {
				self::assertContains($from, $statuses, $name);
			}
		}
	}//end testEveryLifecycleStateIsAStatusValue()

	public function testAChangeNamesItsActionAndPointsAtTheRightSchemas(): void {
		$change = $this->merged()['components']['schemas']['scenarioChange'];

		self::assertSame(['add', 'phase out', 'replace'], $change['properties']['action']['enum']);
		self::assertSame(['scenario', 'action'], $change['required']);
		foreach (['scenario' => 'scenario', 'usage' => 'usage', 'module' => 'module', 'appliedTo' => 'usage'] as $property => $schema) {
			self::assertSame('#/components/schemas/' . $schema, $change['properties'][$property]['$ref'], $property);
		}

		self::assertSame('gemmaType=referentiecomponent', $change['properties']['referenceComponents']['items']['objectConfiguration']['queryParams']);
	}//end testAChangeNamesItsActionAndPointsAtTheRightSchemas()

	public function testTheDemoScenarioAndItsTwoChangesAreSeededOnExistingObjects(): void {
		$objects = $this->merged()['components']['objects'];
		$bySlug = [];
		foreach ($objects as $object) {
			$bySlug[$object['@self']['slug']] = $object;
		}

		$scenario = $bySlug['seed-scenario-delft-2027'];
		self::assertSame(['stackiq', 'scenario'], [$scenario['@self']['register'], $scenario['@self']['schema']]);
		self::assertSame('proposed', $scenario['status']);
		self::assertArrayHasKey('gemeente-delft', $bySlug);

		$out = $bySlug['seed-scenario-change-uitfaseren'];
		$add = $bySlug['seed-scenario-change-toevoegen'];
		self::assertSame(['phase out', 'add'], [$out['action'], $add['action']]);
		self::assertSame('@ref:scenario:seed-scenario-delft-2027', $out['scenario']);
		self::assertSame('@ref:scenario:seed-scenario-delft-2027', $add['scenario']);
		self::assertArrayHasKey('gebruik-suite4-gem-delft-eigenaar', $bySlug);
		self::assertSame('@ref:usage:gebruik-suite4-gem-delft-eigenaar', $out['usage']);
		self::assertSame('topdesk-itsm', $add['module']);
	}//end testTheDemoScenarioAndItsTwoChangesAreSeededOnExistingObjects()
}//end class
