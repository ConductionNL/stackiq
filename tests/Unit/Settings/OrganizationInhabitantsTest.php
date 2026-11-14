<?php

/**
 * The organisation's inhabitants, for comparing it with similar organisations.
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Unit\Settings
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * @version GIT: <git_id>
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Settings;

use OCA\Stackiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Pins that the merged organization schema has an optional integer
 * `inhabitants` (minimum 0) with a version above every earlier one, and that
 * the organisation page shows it.
 *
 * @covers \OCA\Stackiq\Service\SettingsService
 */
final class OrganizationInhabitantsTest extends TestCase {
	/**
	 * The merged organisation schema, merged the way SettingsService does.
	 *
	 * @return array<string, mixed>
	 */
	private function organisation(): array {
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

		return $merged['components']['schemas']['organization'];
	}//end organisation()

	/**
	 * inhabitants is an optional, non-negative integer, and the schema version moved.
	 *
	 * @return void
	 */
	public function testInhabitantsIsAnOptionalNonNegativeInteger(): void {
		$organisation = $this->organisation();
		$inhabitants = $organisation['properties']['inhabitants'];

		self::assertSame('integer', $inhabitants['type']);
		self::assertSame(0, $inhabitants['minimum']);
		self::assertTrue($inhabitants['visible']);
		self::assertNotContains('inhabitants', (array)($organisation['required'] ?? []));
		self::assertTrue(version_compare($organisation['version'], '0.5.6', '>='));
	}//end testInhabitantsIsAnOptionalNonNegativeInteger()

	/**
	 * The organisation page shows inhabitants in its data card.
	 *
	 * @return void
	 */
	public function testTheOrganisationPageShowsInhabitants(): void {
		$manifest = json_decode((string)file_get_contents(__DIR__ . '/../../../src/manifest.json'), true);
		$include = [];
		foreach ($manifest['pages'] as $page) {
			if (($page['route'] ?? '') !== '/organisaties/:id') {
				continue;
			}

			foreach ($page['config']['widgets'] as $widget) {
				if ($widget['id'] === 'org-data') {
					$include = $widget['content']['include'];
				}
			}
		}

		self::assertContains('inhabitants', $include);
	}//end testTheOrganisationPageShowsInhabitants()
}//end class
