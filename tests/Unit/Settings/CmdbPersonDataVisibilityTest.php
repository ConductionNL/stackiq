<?php

/**
 * The owner data the CMDB import writes is never publicly readable.
 *
 * The import links the application owner as a `contactPerson` through
 * `usage.businessOwner`. Anonymous visitors (OpenCatalogi search, Portaliq,
 * the OpenRegister objects API) must not see that person. This test pins the
 * register configuration that guarantees it, in the merged register (base plus
 * every register.d fragment): `contactPerson` has no public read rule, a
 * `usage` is public only once its publicationDate has passed and its person
 * properties never are, and a published `module` only refers to them by
 * relation, so a public search hit carries at most ids. The rig check of
 * the running stack is the API test in
 * tests/e2e/spec-coverage/cmdb-import.spec.ts.
 *
 * @category  Tests
 * @package   OCA\Stackiq\Tests\Unit\Settings
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/cmdb-export-import/tasks.md#task-6
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
 * Pins the register rules that keep imported owners out of public reads.
 */
class CmdbPersonDataVisibilityTest extends TestCase {
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
	 * Whether a read rule lets the public group in.
	 *
	 * @param mixed $rule A string group or a {group, match} rule.
	 *
	 * @return bool
	 */
	private static function isPublic(mixed $rule): bool {
		if (is_string($rule) === true) {
			return $rule === 'public';
		}

		return is_array($rule) === true && ($rule['group'] ?? null) === 'public';
	}//end isPublic()

	/**
	 * A contactPerson can never be read anonymously.
	 *
	 * @return void
	 */
	public function testContactPersonHasNoPublicReadRule(): void {
		$read = ($this->mergedRegister()['components']['schemas']['contactPerson']['authorization']['read'] ?? null);
		$this->assertIsArray($read, 'contactPerson must have an explicit read rule; without one OpenRegister does not restrict reads');
		$this->assertNotEmpty($read, 'contactPerson');
		foreach ($read as $rule) {
			$this->assertFalse(self::isPublic(rule: $rule), 'contactPerson has a public read rule: imported owners would be readable anonymously');
		}
	}//end testContactPersonHasNoPublicReadRule()

	/**
	 * A usage is public only once published, and never with its owners.
	 *
	 * Publication-field-rules (stackiq #1206) makes a usage readable to the public
	 * group once its publicationDate has passed, so OpenCatalogi can show which
	 * applications an organisation uses. The import never sets a usage's
	 * publicationDate, and the properties that name a person carry their own read
	 * rule without the public group, which OpenRegister enforces on the body,
	 * relations, `@self` and facets alike.
	 *
	 * @return void
	 */
	public function testAUsageIsPublicOnlyWhenPublishedAndNeverWithItsOwners(): void {
		$usage = $this->mergedRegister()['components']['schemas']['usage'];
		$read = ($usage['authorization']['read'] ?? null);
		$this->assertIsArray($read, 'usage must have an explicit read rule; without one OpenRegister does not restrict reads');
		$this->assertNotEmpty($read, 'usage');
		foreach ($read as $rule) {
			if (self::isPublic(rule: $rule) === true) {
				$this->assertIsArray($rule, 'a bare public read rule on usage would publish every usage');
				$this->assertArrayHasKey('publicationDate', ($rule['match'] ?? []), 'the public read rule on usage must be conditional on publicationDate');
			}
		}

		foreach (['businessOwner', 'technicalOwner', 'contactPerson'] as $property) {
			$propertyRead = ($usage['properties'][$property]['authorization']['read'] ?? null);
			$this->assertIsArray($propertyRead, 'usage.' . $property . ' names a person and needs its own read rule');
			$this->assertNotEmpty($propertyRead, 'usage.' . $property);
			foreach ($propertyRead as $rule) {
				$this->assertFalse(self::isPublic(rule: $rule), 'usage.' . $property . ' is publicly readable: imported owners would be visible anonymously');
			}
		}
	}//end testAUsageIsPublicOnlyWhenPublishedAndNeverWithItsOwners()

	/**
	 * A published module refers to its contact person and usages by relation only, and holds no person field.
	 *
	 * @return void
	 */
	public function testAModuleOnlyRefersToPeopleByRelation(): void {
		$module = $this->mergedRegister()['components']['schemas']['module'];

		$this->assertTrue(
			array_filter($module['authorization']['read'], fn (mixed $rule): bool => self::isPublic(rule: $rule)) !== [],
			'modules are public once published; that is why the person data must stay on other schemas'
		);
		$this->assertSame('#/components/schemas/contactPerson', $module['properties']['contactPerson']['$ref']);
		$this->assertSame('#/components/schemas/usage', $module['properties']['usages']['$ref']);
		foreach (['businessOwner', 'technicalOwner', 'email', 'owner', 'eigenaar'] as $field) {
			$this->assertArrayNotHasKey($field, $module['properties'], 'module.' . $field . ' would be public');
		}
	}//end testAModuleOnlyRefersToPeopleByRelation()

	/**
	 * The import writes no person data onto a module; only the owner pack reads a person column.
	 *
	 * @return void
	 */
	public function testTheImportWritesNoPersonDataOntoAModule(): void {
		$dir = __DIR__ . '/../../../lib/Settings/cmdb-import';
		$person = ['Applicatie Eigenaar (Persoon)', 'Applicatie Eigenaar (Functie)'];

		foreach (glob($dir . '/topdesk-*.json') as $file) {
			$pack = json_decode((string)file_get_contents($file), true);
			foreach (($pack['fieldMappings'] ?? []) as $mapping) {
				if (basename($file) === 'topdesk-module.json') {
					$this->assertNotContains($mapping['target'], ['contactPerson', 'usages'], 'the module pack writes ' . $mapping['target']);
				}

				if (in_array($mapping['source'], $person, true) === true) {
					$this->assertSame('topdesk-business-owner.json', basename($file), $mapping['source'] . ' is read outside the owner pack');
				}
			}
		}
	}//end testTheImportWritesNoPersonDataOntoAModule()
}//end class
