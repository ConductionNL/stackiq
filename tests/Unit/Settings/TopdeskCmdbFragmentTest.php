<?php

/**
 * The module schema as the app imports it with every register fragment, and
 * the CMDB import's seed modules.
 *
 * @category  Tests
 * @package   OCA\Stackiq\Tests\Unit\Settings
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/cmdb-export-import/tasks.md#task-2
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
 * Merges every fragment in filename order, exactly as SettingsService::loadSettings() does.
 */
class TopdeskCmdbFragmentTest extends TestCase {
	/**
	 * The properties the fragment adds.
	 *
	 * @var array<int, string>
	 */
	private const PROPERTIES = ['externalId', 'externalNumber', 'externalKey', 'externalCreatedAt', 'externalModifiedAt', 'applicationType'];

	/**
	 * The register after merging every fragment in sorted filename order.
	 *
	 * @return array<string, mixed>
	 */
	private function mergedRegister(): array {
		$dir = __DIR__ . '/../../../lib/Settings';
		$register = json_decode((string)file_get_contents($dir . '/softwarecatalogus_register.json'), true);
		$merge = new ReflectionMethod(SettingsService::class, 'deepMergeConfig');

		$files = glob($dir . '/register.d/*.json');
		sort($files);
		foreach ($files as $file) {
			$register = $merge->invoke(null, $register, json_decode((string)file_get_contents($file), true));
		}

		return $register;
	}//end mergedRegister()

	/**
	 * The merged module is 0.3.7, carries the six optional, titled properties and allows BBN2+.
	 *
	 * @return void
	 */
	public function testTheMergedModuleIsVersion037WithTheExternalIds(): void {
		$module = $this->mergedRegister()['components']['schemas']['module'];

		$this->assertSame('0.3.7', $module['version'], 'a fragment sorting after topdesk-cmdb-import.json overwrote the bump');
		foreach (self::PROPERTIES as $property) {
			$this->assertArrayHasKey($property, $module['properties']);
			$this->assertNotEmpty($module['properties'][$property]['title'] ?? '', $property);
			$this->assertNotEmpty($module['properties'][$property]['description'] ?? '', $property);
			$this->assertSame('string', $module['properties'][$property]['type'], $property);
			$this->assertNotContains($property, $module['required'] ?? [], $property);
			$this->assertNotTrue($module['properties'][$property]['required'] ?? false, $property);
		}

		$this->assertSame(100, $module['properties']['externalId']['maxLength']);
		$this->assertSame(50, $module['properties']['externalNumber']['maxLength']);
		$this->assertSame(200, $module['properties']['externalKey']['maxLength']);
		$this->assertSame(['default' => false], $module['properties']['externalKey']['table']);
		$this->assertSame('date', $module['properties']['externalCreatedAt']['format']);
		$this->assertSame('date', $module['properties']['externalModifiedAt']['format']);
		$this->assertSame(100, $module['properties']['applicationType']['maxLength']);
		$this->assertSame(['BBN1', 'BBN2', 'BBN3', 'BBN2+'], $module['properties']['bbnLevel']['enum'], 'the fragment adds BBN2+ to the BIO levels');
		$this->assertArrayHasKey('roadmapStatement', $module['properties'], 'the 0.3.4 fragment still applies');
		$this->assertSame(['name'], $module['required']);
	}//end testTheMergedModuleIsVersion037WithTheExternalIds()

	/**
	 * The fragment sorts after the fragment that set module 0.3.4.
	 *
	 * @return void
	 */
	public function testTheFragmentSortsAfterTheRoadmapFragment(): void {
		$names = ['maintenance-and-roadmap.json', 'topdesk-cmdb-import.json'];
		$sorted = $names;
		sort($sorted);
		$this->assertSame($names, $sorted);
	}//end testTheFragmentSortsAfterTheRoadmapFragment()

	/**
	 * The three seed modules exist without publicationDate or externalKey, and every seed field is a schema property.
	 *
	 * @return void
	 */
	public function testTheSeedModulesShowTheNewProperties(): void {
		$register = $this->mergedRegister();
		$properties = $register['components']['schemas']['module']['properties'];
		$seeds = [];
		foreach ($register['components']['objects'] as $object) {
			if (($object['@self']['schema'] ?? null) === 'module') {
				$seeds[$object['@self']['slug']] = $object;
			}
		}

		$this->assertSame(['voorbeeld-zaaksysteem', 'voorbeeld-afsprakenplanner', 'voorbeeld-belastingapplicatie'], array_keys($seeds));
		$this->assertSame(['APP-00001', 'APP-00002', 'AIA-00003'], array_column(array_values($seeds), 'externalId'));
		foreach ($seeds as $slug => $seed) {
			$this->assertArrayNotHasKey('publicationDate', $seed, $slug);
			$this->assertArrayNotHasKey('externalKey', $seed, $slug);
			$this->assertSame('stackiq', $seed['@self']['register'], $slug);
			foreach (array_keys($seed) as $field) {
				if ($field !== '@self') {
					$this->assertArrayHasKey($field, $properties, $slug . '.' . $field);
				}
			}

			$this->assertContains($seed['bbnLevel'], $properties['bbnLevel']['enum'], $slug);
			$this->assertContains($seed['type'], $properties['type']['enum'], $slug);
		}

		// The base seeds are still there: the fragment appends, it does not replace.
		$this->assertGreaterThan(3, count($register['components']['objects']));
	}//end testTheSeedModulesShowTheNewProperties()
}//end class
