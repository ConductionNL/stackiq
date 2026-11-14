<?php

/**
 * The partOf relation on module, as the app imports it.
 *
 * @category  Tests
 * @package   OCA\Stackiq\Tests\Unit\Settings
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/landscape-application-components/specs/application-components/spec.md#requirement-req-acm-001-an-application-can-name-the-application-it-is-a-component-of
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
 * The fragment merged onto the shipped register, and the pages that read it.
 *
 * @covers \OCA\Stackiq\Service\SettingsService
 */
class ApplicationComponentsFragmentTest extends TestCase {

	/**
	 * The merged register.
	 *
	 * @return array<string, mixed>
	 */
	private function register(): array {
		$dir      = __DIR__ . '/../../../lib/Settings';
		$base     = json_decode((string) file_get_contents($dir . '/softwarecatalogus_register.json'), true);
		$fragment = json_decode((string) file_get_contents($dir . '/register.d/application-components.json'), true);

		$merge = new ReflectionMethod(SettingsService::class, 'deepMergeConfig');
		return $merge->invoke(null, $base, $fragment);
	}//end register()

	/**
	 * A module names the module it is part of, of the same supplier, and lists its components.
	 *
	 * @return void
	 */
	public function testAModuleNamesItsParentAndListsItsComponents(): void {
		$module = $this->register()['components']['schemas']['module'];
		$props  = $module['properties'];

		$this->assertSame('#/components/schemas/module', $props['partOf']['$ref']);
		$this->assertSame('related-object', $props['partOf']['objectConfiguration']['handling']);
		$this->assertSame(['provider' => '@object.provider'], $props['partOf']['x-relation-filter']);
		$this->assertSame('components', $props['partOf']['inversedBy']);

		$this->assertSame('array', $props['components']['type']);
		$this->assertTrue($props['components']['hideOnForm']);
		$this->assertSame(['partOf' => '@objectId'], $props['components']['x-relation-filter']);
		$this->assertSame('#/components/schemas/module', $props['components']['items']['$ref']);

		// The fragment adds; everything the shipped module had is still there.
		foreach (['name', 'provider', 'moduleVersions', 'usages', 'standards'] as $kept) {
			$this->assertArrayHasKey($kept, $props);
		}

		$this->assertSame('0.3.13', $module['version']);
	}//end testAModuleNamesItsParentAndListsItsComponents()

	/**
	 * The application page lists the components and shows the parent; the list hides components by default.
	 *
	 * @return void
	 */
	public function testThePagesShowComponents(): void {
		$manifest = json_decode((string) file_get_contents(__DIR__ . '/../../../src/manifest.json'), true);
		$pages    = array_column($manifest['pages'], null, 'id');

		$detail  = $pages['ModuleDetail']['config'];
		$widgets = array_column($detail['widgets'], null, 'id');
		$list    = $widgets['md-components']['content'];
		$this->assertSame('module', $list['schema']);
		$this->assertSame(['partOf' => '@objectId'], $list['filter']);
		$this->assertSame('ModuleDetail', $list['rowRoute']);
		$this->assertTrue($list['allowCreate']);
		$this->assertContains('partOf', $widgets['md-data']['content']['include']);
		$this->assertContains('md-components', array_column($detail['layout'], 'widgetId'));

		$filters = $pages['Modules']['config']['quickFilters'];
		$this->assertSame(['label' => 'Whole applications', 'filter' => ['partOf' => 'IS NULL'], 'default' => true], $filters[0]);
		$this->assertSame(['label' => 'All, including components', 'filter' => []], $filters[1]);
		$this->assertCount(1, array_filter($filters, static fn (array $tab): bool => ($tab['default'] ?? false) === true));
	}//end testThePagesShowComponents()
}//end class
