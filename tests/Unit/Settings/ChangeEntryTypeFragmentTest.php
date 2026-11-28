<?php
/**
 * ChangeEntryTypeFragmentTest
 *
 * Landscape-change-entry-type: the merged register shows a module's type on
 * the form, at a module version no lower than this change's.
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
 * @spec openspec/changes/archive/2026-11-25-landscape-change-entry-type
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
 * The module type stays visible through the fragment merge.
 */
class ChangeEntryTypeFragmentTest extends TestCase {

	/**
	 * The register with every fragment merged over it, the way the import
	 * reads it, keeping the highest version of each schema.
	 *
	 * @return array<string, mixed>
	 */
	private static function mergedRegister(): array {
		$root = dirname(__DIR__, 3);
		$register = json_decode((string)file_get_contents($root.'/lib/Settings/softwarecatalogus_register.json'), true);
		$merge = new ReflectionMethod(SettingsService::class, 'deepMergeConfig');
		$merge->setAccessible(true);
		$highest = new ReflectionMethod(SettingsService::class, 'keepHighestSchemaVersions');
		$highest->setAccessible(true);
		$fragments = glob($root.'/lib/Settings/register.d/*.json');
		sort($fragments);
		foreach ($fragments as $fragment) {
			$overlay = json_decode((string)file_get_contents($fragment), true);
			$register = $highest->invoke(null, $register, $merge->invoke(null, $register, $overlay));
		}

		return $register;
	}//end mergedRegister()

	/**
	 * The module's type is on the form, and the version the import compares
	 * is no lower than this fragment's.
	 *
	 * @return void
	 */
	public function testTheModuleTypeIsVisibleAtTheHighestVersion(): void {
		$module = self::mergedRegister()['components']['schemas']['module'];
		$this->assertTrue(condition: $module['properties']['type']['visible']);
		$this->assertSame(expected: 'string', actual: $module['properties']['type']['type']);
		// A later fragment may bump module above 0.3.15; the import must still compare a version no lower than this change's.
		$version = (string) $module['version'];
		$this->assertTrue(condition: version_compare($version, '0.3.15', '>='), message: 'module '.$version.' must not be below 0.3.15');
	}//end testTheModuleTypeIsVisibleAtTheHighestVersion()
}//end class
