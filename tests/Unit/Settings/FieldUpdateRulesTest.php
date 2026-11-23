<?php

/**
 * The field-level update rules, merged the way the app merges its register.
 *
 * @category  Tests
 * @package   OCA\Stackiq\Tests\Unit\Settings
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/capability-rows-the-code-does-not-yet-meet/specs/publication-field-rules/spec.md#requirement-a-field-can-carry-its-own-rule-for-who-may-change-it-req-clr-017
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
 * A schema property declares who may update it; the rest of the record keeps its own rules.
 *
 * @spec openspec/changes/capability-rows-the-code-does-not-yet-meet/specs/publication-field-rules/spec.md#requirement-a-field-can-carry-its-own-rule-for-who-may-change-it-req-clr-017
 */
class FieldUpdateRulesTest extends TestCase {

	/**
	 * The base register with every fragment merged in file name order, as SettingsService::loadSettings does.
	 *
	 * @return array<string, mixed>
	 */
	private function register(): array {
		$deep   = new ReflectionMethod(SettingsService::class, 'deepMergeConfig');
		$keep   = new ReflectionMethod(SettingsService::class, 'keepHighestSchemaVersions');
		$merged = json_decode((string) file_get_contents(__DIR__ . '/../../../lib/Settings/softwarecatalogus_register.json'), true);
		$files  = glob(__DIR__ . '/../../../lib/Settings/register.d/*.json');
		sort($files);
		foreach ($files as $file) {
			$fragment = json_decode((string) file_get_contents($file), true);
			$merged   = $keep->invoke(null, $merged, $deep->invoke(null, $merged, $fragment));
		}

		return $merged;
	}//end register()

	/**
	 * Every property-level update rule in the merged register, keyed "schema.property".
	 *
	 * @param array<string, mixed> $register The merged register.
	 *
	 * @return array<string, mixed>
	 */
	private static function updateRules(array $register): array {
		$rules = [];
		foreach ($register['components']['schemas'] as $slug => $schema) {
			foreach (($schema['properties'] ?? []) as $name => $property) {
				if (is_array($property) === true
					&& is_array($property['authorization'] ?? null) === true
					&& array_key_exists('update', $property['authorization']) === true
				) {
					$rules[$slug . '.' . $name] = $property['authorization']['update'];
				}
			}
		}

		return $rules;
	}//end updateRules()

	/**
	 * Only a Nextcloud admin changes an application's import key, and every signed-in user still reads it.
	 *
	 * @return void
	 */
	public function testTheImportKeyIsUpdatedByAdminsOnly(): void {
		$key = $this->register()['components']['schemas']['module']['properties']['externalKey'];

		$this->assertSame(expected: 'string', actual: $key['type'], message: 'externalKey is a real property, not a rule on nothing');
		$this->assertSame(expected: ['admin'], actual: $key['authorization']['update']);
		$this->assertSame(expected: ['authenticated'], actual: $key['authorization']['read'], message: 'the publication fragment\'s read rule survives the merge');
	}//end testTheImportKeyIsUpdatedByAdminsOnly()

	/**
	 * The maintenance window's notification fields declare their update group.
	 *
	 * @return void
	 */
	public function testTheMaintenanceRecipientFieldsDeclareTheirUpdateGroup(): void {
		$window = $this->register()['components']['schemas']['maintenanceWindow'];

		foreach (['notifyUserIds', 'recipientsResolvedAt'] as $field) {
			$this->assertSame(expected: ['software-catalog-admins'], actual: $window['properties'][$field]['authorization']['update'], message: $field);
		}
	}//end testTheMaintenanceRecipientFieldsDeclareTheirUpdateGroup()

	/**
	 * A field rule does not lock the record: the application schema keeps no admin-only update rule of its own.
	 *
	 * @return void
	 */
	public function testTheRestOfTheApplicationStaysEditable(): void {
		$module = $this->register()['components']['schemas']['module'];
		$update = ($module['authorization']['update'] ?? null);

		$this->assertNotSame(expected: ['admin'], actual: $update, message: 'the schema-level update rule is not narrowed to the field\'s rule');
		$this->assertArrayNotHasKey(key: 'authorization', array: ($module['properties']['name'] ?? []), message: 'an ordinary field carries no rule of its own');
	}//end testTheRestOfTheApplicationStaysEditable()

	/**
	 * Every field-level update rule is well formed: a non-empty list of group names or group-and-match entries,
	 * on a property that has a type.
	 *
	 * @return void
	 */
	public function testEveryFieldUpdateRuleIsWellFormed(): void {
		$register = $this->register();
		$rules    = self::updateRules(register: $register);

		$this->assertArrayHasKey(key: 'module.externalKey', array: $rules);
		foreach ($rules as $path => $rule) {
			[$slug, $name] = explode('.', $path, 2);
			$this->assertArrayHasKey(key: 'type', array: $register['components']['schemas'][$slug]['properties'][$name], message: $path . ' is a real property');
			$this->assertIsArray(actual: $rule, message: $path);
			$this->assertNotEmpty(actual: $rule, message: $path . ' names at least one group');
			$this->assertTrue(condition: array_is_list($rule), message: $path . ' is a list');
			foreach ($rule as $entry) {
				if (is_string($entry) === true) {
					$this->assertNotSame(expected: '', actual: $entry, message: $path);
					continue;
				}

				$this->assertIsArray(actual: $entry, message: $path);
				$this->assertIsString(actual: ($entry['group'] ?? null), message: $path . ' entry names its group');
			}
		}
	}//end testEveryFieldUpdateRuleIsWellFormed()
}//end class
