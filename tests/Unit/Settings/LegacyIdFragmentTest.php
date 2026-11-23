<?php
/**
 * LegacyIdFragmentTest
 *
 * Landscape-migrate-from-previous-catalogue: the usage, module and
 * organization schemas carry an optional `legacyId`, and each schema's merged
 * version is this fragment's, so an installed register takes the field.
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
 * @spec openspec/changes/landscape-migrate-from-previous-catalogue/tasks.md#task-3
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
 * The previous catalogue's id is an optional, admin-guarded field on the three schemas an import writes.
 */
class LegacyIdFragmentTest extends TestCase {

	/**
	 * The schemas this fragment extends, with the version it sets for each.
	 */
	private const VERSIONS = [
		'usage'        => '1.6.5',
		'module'       => '0.3.16',
		'organization' => '0.5.7',
	];

	/**
	 * The register with every fragment merged over it, the way the import
	 * reads it, keeping the highest version of each schema.
	 *
	 * @return array<string, mixed>
	 */
	private static function mergedRegister(): array {
		$root     = dirname(__DIR__, 3);
		$register = json_decode((string) file_get_contents($root.'/lib/Settings/softwarecatalogus_register.json'), true);
		$merge    = new ReflectionMethod(SettingsService::class, 'deepMergeConfig');
		$merge->setAccessible(true);
		$highest = new ReflectionMethod(SettingsService::class, 'keepHighestSchemaVersions');
		$highest->setAccessible(true);
		$fragments = glob($root.'/lib/Settings/register.d/*.json');
		sort($fragments);
		foreach ($fragments as $fragment) {
			$overlay  = json_decode((string) file_get_contents($fragment), true);
			$register = $highest->invoke(null, $register, $merge->invoke(null, $register, $overlay));
		}

		return $register;
	}//end mergedRegister()

	/**
	 * Each schema has `legacyId` as an optional string only an admin can change.
	 *
	 * @return void
	 */
	public function testEachSchemaHasAnOptionalAdminGuardedLegacyId(): void {
		$schemas = self::mergedRegister()['components']['schemas'];
		foreach (array_keys(self::VERSIONS) as $key) {
			$field = $schemas[$key]['properties']['legacyId'] ?? null;
			$this->assertIsArray(actual: $field, message: $key.' has legacyId');
			$this->assertSame(expected: 'string', actual: $field['type']);
			$this->assertSame(expected: ['admin'], actual: $field['authorization']['update']);
			$this->assertNotContains(needle: 'legacyId', haystack: ($schemas[$key]['required'] ?? []), message: $key.' legacyId is optional');
			$this->assertNotTrue(condition: ($field['required'] ?? false), message: $key.' legacyId is optional');
		}
	}//end testEachSchemaHasAnOptionalAdminGuardedLegacyId()

	/**
	 * The merged version of each schema is no lower than this fragment's, which is above the base register's.
	 *
	 * @return void
	 */
	public function testTheVersionsDeployTheField(): void {
		$root    = dirname(__DIR__, 3);
		$base    = json_decode((string) file_get_contents($root.'/lib/Settings/softwarecatalogus_register.json'), true)['components']['schemas'];
		$schemas = self::mergedRegister()['components']['schemas'];
		foreach (self::VERSIONS as $key => $version) {
			$merged = (string) $schemas[$key]['version'];
			$this->assertTrue(condition: version_compare($merged, $version, '>='), message: $key.' '.$merged.' below '.$version);
			$baseVersion = (string) ($base[$key]['version'] ?? '0');
			$this->assertTrue(condition: version_compare($version, $baseVersion, '>'), message: $key.' must be above the base register');
		}
	}//end testTheVersionsDeployTheField()
}//end class
