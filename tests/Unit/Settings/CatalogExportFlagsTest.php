<?php

/**
 * Unit tests for the export flags of insight-exports-and-custom-reports: the
 * four list schemas are exportable in the merged register, and only the list
 * pages whose filter the library already forwards offer the Export menu.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Settings
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/archive/2026-11-25-insight-exports-and-custom-reports/tasks.md
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Settings;

use OCA\Stackiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The export flags on the list schemas and pages.
 *
 * @spec openspec/changes/archive/2026-11-25-insight-exports-and-custom-reports/tasks.md
 */
class CatalogExportFlagsTest extends TestCase {

	/**
	 * The register with every fragment merged, as the import sees it.
	 *
	 * @return array<string, mixed>
	 */
	private static function mergedRegister(): array {
		$root  = dirname(__DIR__, 3);
		$base  = json_decode((string)file_get_contents($root . '/lib/Settings/softwarecatalogus_register.json'), true);
		$merge = new ReflectionMethod(SettingsService::class, 'deepMergeConfig');

		$fragments = glob($root . '/lib/Settings/register.d/*.json');
		sort($fragments);
		foreach ($fragments as $fragment) {
			$base = $merge->invoke(null, $base, json_decode((string)file_get_contents($fragment), true));
		}

		return $base;
	}//end mergedRegister()

	/**
	 * The index pages of the manifest by id.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function pages(): array {
		$manifest = json_decode((string)file_get_contents(dirname(__DIR__, 3) . '/src/manifest.json'), true);

		return array_column($manifest['pages'], null, 'id');
	}//end pages()

	/**
	 * The four list schemas are exportable, and the merge keeps the rest of each schema.
	 *
	 * @return void
	 */
	public function testTheFourListSchemasAreExportable(): void {
		$schemas = self::mergedRegister()['components']['schemas'];

		foreach (['catalogContract', 'organization', 'compliancy', 'moduleVersion'] as $slug) {
			self::assertTrue($schemas[$slug]['exportable'], $slug);
			self::assertNotEmpty($schemas[$slug]['properties'], $slug . ' keeps its properties');
		}

		self::assertArrayNotHasKey('exportable', $schemas['module'], 'the faceted lists export through stackiq');
	}//end testTheFourListSchemasAreExportable()

	/**
	 * Compliance and module versions offer the Export menu; contracts and organisations wait for the
	 * library release that forwards their page filter and quick filter.
	 *
	 * @return void
	 */
	public function testOnlyThePagesWhoseFilterReachesTheFileOfferExport(): void {
		$pages = self::pages();

		self::assertTrue($pages['Komplianties']['config']['allowExport']);
		self::assertTrue($pages['Moduleversies']['config']['allowExport']);
		self::assertArrayNotHasKey('allowExport', $pages['Contracten']['config']);
		self::assertArrayNotHasKey('allowExport', $pages['Organisaties']['config']);
	}//end testOnlyThePagesWhoseFilterReachesTheFileOfferExport()
}//end class
