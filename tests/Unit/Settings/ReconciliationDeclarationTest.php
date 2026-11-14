<?php

/**
 * ReconciliationDeclarationTest
 *
 * operations-record-reconciliation task 1: the merged register declares the
 * duplicate and merge rules OpenRegister reads, and the record status, on
 * applications and services, with versions above every earlier one.
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
 * @spec openspec/changes/operations-record-reconciliation/specs/record-reconciliation/spec.md#requirement-req-rrc-001-applications-services-and-organisations-shall-declare-duplicate-rules-and-applications-and-services-shall-declare-how-they-merge
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
 * The reconciliation declarations survive the fragment merge.
 */
class ReconciliationDeclarationTest extends TestCase {
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
	 * Applications and services merge through OpenRegister with a record status.
	 *
	 * @return void
	 */
	public function testApplicationsAndServicesDeclareHowTheyMerge(): void {
		$schemas = self::mergedRegister()['components']['schemas'];
		foreach (['module', 'catalogService'] as $slug) {
			$this->assertSame(
				['statusField' => 'recordStatus', 'survivorStatus' => 'Active', 'mergedStatus' => 'Merged', 'reversalWindowDays' => 30],
				$schemas[$slug]['configuration']['x-openregister-merge'] ?? null,
				$slug
			);
			$status = $schemas[$slug]['properties']['recordStatus'] ?? [];
			$this->assertSame(['Active', 'Merged'], $status['enum'] ?? null, $slug);
			$this->assertSame('Active', $status['default'] ?? null, $slug);
			$this->assertSame('uuid', $schemas[$slug]['properties']['mergedInto']['format'] ?? null, $slug);
		}
	}//end testApplicationsAndServicesDeclareHowTheyMerge()

	/**
	 * Applications, services and organisations carry duplicate rules; the
	 * services' rules have the applications' shape.
	 *
	 * @return void
	 */
	public function testApplicationsServicesAndOrganisationsDeclareDuplicateRules(): void {
		$schemas = self::mergedRegister()['components']['schemas'];
		foreach (['module', 'catalogService', 'organization'] as $slug) {
			$this->assertNotEmpty($schemas[$slug]['configuration']['x-openregister-dedup']['matchRules'] ?? [], $slug);
		}

		$this->assertSame(
			$schemas['module']['configuration']['x-openregister-dedup'],
			$schemas['catalogService']['configuration']['x-openregister-dedup']
		);
		$this->assertSame(0.7, $schemas['catalogService']['configuration']['x-openregister-dedup']['threshold']);
	}//end testApplicationsServicesAndOrganisationsDeclareDuplicateRules()

	/**
	 * Both schema versions are above the monolith's and every fragment's, so
	 * the import does not skip the new properties.
	 *
	 * @return void
	 */
	public function testBothSchemaVersionsGoUp(): void {
		$root = dirname(__DIR__, 3);
		$fragment = json_decode((string)file_get_contents($root.'/lib/Settings/register.d/operations-record-reconciliation.json'), true);
		$sources = array_merge([$root.'/lib/Settings/softwarecatalogus_register.json'], glob($root.'/lib/Settings/register.d/*.json'));
		foreach (['module', 'catalogService'] as $slug) {
			$ours = $fragment['components']['schemas'][$slug]['version'];
			foreach ($sources as $source) {
				if (str_ends_with($source, 'operations-record-reconciliation.json') === true) {
					continue;
				}

				$other = json_decode((string)file_get_contents($source), true)['components']['schemas'][$slug]['version'] ?? '0';
				$this->assertTrue(version_compare($ours, (string)$other, '>'), $slug.' '.$ours.' must be above '.$other.' in '.basename($source));
			}

			$this->assertSame($ours, self::mergedRegister()['components']['schemas'][$slug]['version']);
		}
	}//end testBothSchemaVersionsGoUp()
}//end class
