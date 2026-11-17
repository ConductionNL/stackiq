<?php

/**
 * The applicationInterface schema reaches the merged register (connections-api-catalogue).
 *
 * The first fragment in this app that adds a brand-new schema rather than
 * changing one, so this proves the merge carries the schema, the register's
 * list entry and its table configuration, the lifecycle on the status enum,
 * and the connection's new interface field at a version that deploys.
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/connections-api-catalogue/specs/application-interfaces/spec.md#requirement-req-aif-001-a-user-can-record-an-api-that-an-application-offers
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Settings;

use OCA\Stackiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The applicationInterface fragment, merged as SettingsService::loadSettings() merges it.
 */
final class ApplicationInterfaceFragmentTest extends TestCase {

	/**
	 * The monolith with every fragment merged in, the way the loader does it.
	 *
	 * @return array<string, mixed>
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

	/**
	 * The stackiq register lists the schema once and creates its table.
	 *
	 * @return void
	 */
	public function testTheRegisterListsTheSchemaAndItsTable(): void {
		$stackiq = $this->merged()['components']['registers']['stackiq'];

		self::assertSame(1, count(array_keys($stackiq['schemas'], 'applicationInterface', true)));
		self::assertContains('maintenanceWindow', $stackiq['schemas'], 'The list is appended to, not replaced.');
		self::assertSame(
			['magicMapping' => true, 'autoCreateTable' => true],
			$stackiq['configuration']['schemas']['applicationInterface']
		);
		self::assertArrayHasKey('module', $stackiq['configuration']['schemas'], 'The other schemas keep their table configuration.');
	}//end testTheRegisterListsTheSchemaAndItsTable()

	/**
	 * The schema carries the design's fields, names its application, and is read like a module.
	 *
	 * @return void
	 */
	public function testTheSchemaDescribesAnApiOfAnApplication(): void {
		$schemas = $this->merged()['components']['schemas'];
		$api = $schemas['applicationInterface'];

		self::assertSame('API', $api['title']);
		self::assertSame(['name', 'module'], $api['required']);
		self::assertSame(
			['name', 'shortDescription', 'longDescription', 'module', 'style', 'version', 'specificationUrl', 'documentationUrl', 'standardVersions', 'status', 'publicationDate', 'depublicationDate'],
			array_keys($api['properties'])
		);
		self::assertSame('#/components/schemas/module', $api['properties']['module']['$ref']);
		self::assertSame('interfaces', $api['properties']['module']['inversedBy']);
		self::assertSame('gemmaType=standaardversie', $api['properties']['standardVersions']['items']['objectConfiguration']['queryParams']);
		self::assertSame(['REST', 'SOAP', 'GraphQL', 'event or message', 'file', 'other'], $api['properties']['style']['enum']);
		self::assertTrue($api['properties']['style']['facetable']);
		self::assertSame($schemas['module']['authorization'], $api['authorization']);
		foreach ($api['properties'] as $name => $property) {
			self::assertNotEmpty($property['title'] ?? '', $name . ' has no title');
		}
	}//end testTheSchemaDescribesAnApiOfAnApplication()

	/**
	 * Every lifecycle state is a value of the status enum, the connection's own states.
	 *
	 * @return void
	 */
	public function testTheLifecycleUsesTheStatusValues(): void {
		$schemas = $this->merged()['components']['schemas'];
		$api = $schemas['applicationInterface'];
		$lifecycle = $api['configuration']['x-openregister-lifecycle'];
		$states = [$lifecycle['initial'], ...$lifecycle['final']];
		foreach ($lifecycle['transitions'] as $transition) {
			$states = [...$states, ...$transition['from'], $transition['to']];
		}

		self::assertSame([], array_values(array_diff(array_unique($states), $api['properties']['status']['enum'])));
		self::assertSame($schemas['connection']['properties']['status']['enum'], $api['properties']['status']['enum']);
	}//end testTheLifecycleUsesTheStatusValues()

	/**
	 * A connection names the API of its application B, at a version above every other.
	 *
	 * @return void
	 */
	public function testAConnectionCanNameTheApiItCalls(): void {
		$connection = $this->merged()['components']['schemas']['connection'];
		$interface = $connection['properties']['interface'];

		self::assertSame('#/components/schemas/applicationInterface', $interface['$ref']);
		self::assertFalse($interface['required']);
		self::assertSame(['module' => '@object.moduleB'], $interface['x-relation-filter']);
		self::assertSame('0.3.6', $connection['version']);
		self::assertArrayHasKey('moduleB', $connection['properties'], 'The merge keeps the connection\'s own fields.');
	}//end testAConnectionCanNameTheApiItCalls()

	/**
	 * The demo data has two APIs whose fields all exist on the schema.
	 *
	 * @return void
	 */
	public function testTheDemoApisFitTheSchema(): void {
		$api = $this->merged()['components']['schemas']['applicationInterface'];
		$demo = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/stackiq_mock_register.json'), true);
		$apis = array_values(array_filter($demo['components']['objects'], static fn (array $o): bool => ($o['@self']['schema'] ?? '') === 'applicationInterface'));

		self::assertCount(2, $apis);
		foreach ($apis as $object) {
			unset($object['@self']);
			self::assertSame([], array_values(array_diff(array_keys($object), array_keys($api['properties']))));
			self::assertContains($object['style'], $api['properties']['style']['enum']);
			self::assertContains($object['status'], $api['properties']['status']['enum']);
		}
	}//end testTheDemoApisFitTheSchema()
}//end class
