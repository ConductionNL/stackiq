<?php
/**
 * ChangeEntryTypeFragmentTest
 *
 * Landscape-change-entry-type: the merged register shows a module's type on
 * the form, with a module version above every other source's.
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
 * @spec openspec/changes/landscape-change-entry-type
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
	 * is this fragment's, above every other source's.
	 *
	 * @return void
	 */
	public function testTheModuleTypeIsVisibleAtTheHighestVersion(): void {
		$module = self::mergedRegister()['components']['schemas']['module'];
		$this->assertTrue(condition: $module['properties']['type']['visible']);
		$this->assertSame(expected: 'string', actual: $module['properties']['type']['type']);
		$this->assertSame(expected: '0.3.15', actual: $module['version']);

		$root = dirname(__DIR__, 3);
		$sources = array_merge([$root.'/lib/Settings/softwarecatalogus_register.json'], glob($root.'/lib/Settings/register.d/*.json'));
		foreach ($sources as $source) {
			if (str_ends_with($source, 'landscape-change-entry-type.json') === true) {
				continue;
			}

			$other = (string)(json_decode((string)file_get_contents($source), true)['components']['schemas']['module']['version'] ?? '0');
			$this->assertTrue(condition: version_compare('0.3.15', $other, '>'), message: 'module 0.3.15 must be above '.$other.' in '.basename($source));
		}
	}//end testTheModuleTypeIsVisibleAtTheHighestVersion()
}//end class
