<?php

/**
 * The usage schema as the app imports it: the register with the usage-owners
 * fragment merged in through SettingsService::deepMergeConfig, the same merge
 * the import runs.
 *
 * @category  Tests
 * @package   OCA\Stackiq\Tests\Unit\Settings
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/landscape-usage-registration/specs/application-usage-pages/spec.md
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Settings;

use OCA\Stackiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Asserts the owner fields, the lifecycle, the name template and the seeds of
 * the usage schema.
 */
class UsageSchemaTest extends TestCase {

	/**
	 * The merged register.
	 *
	 * @return array<string, mixed>
	 */
	private function register(): array {
		$dir      = __DIR__ . '/../../../lib/Settings';
		$base     = json_decode((string) file_get_contents($dir . '/softwarecatalogus_register.json'), true);
		$fragment = json_decode((string) file_get_contents($dir . '/register.d/usage-owners.json'), true);

		$merge = new ReflectionMethod(SettingsService::class, 'deepMergeConfig');
		return $merge->invoke(null, $base, $fragment);
	}//end register()

	/**
	 * The merged usage schema.
	 *
	 * @return array<string, mixed>
	 */
	private function usage(): array {
		return $this->register()['components']['schemas']['usage'];
	}//end usage()

	/**
	 * Both owners point at a contact person of the organisation that uses the application.
	 *
	 * @return void
	 */
	public function testBothOwnersAreContactPersonsOfTheConsumer(): void {
		$usage = $this->usage();
		foreach (['businessOwner', 'technicalOwner'] as $field) {
			$prop = $usage['properties'][$field];
			$this->assertSame('#/components/schemas/contactPerson', $prop['$ref'], $field);
			$this->assertSame('related-object', $prop['objectConfiguration']['handling'], $field);
			$this->assertSame(['organization' => '@object.consumer'], $prop['x-relation-filter'], $field);
		}

		$this->assertArrayHasKey('organization', $this->register()['components']['schemas']['contactPerson']['properties']);
		$this->assertSame([], array_values(array_intersect(['businessOwner', 'technicalOwner'], ($usage['required'] ?? []))));
	}//end testBothOwnersAreContactPersonsOfTheConsumer()

	/**
	 * The merge adds the owners and keeps every property the usage had.
	 *
	 * @return void
	 */
	public function testTheMergeKeepsTheExistingProperties(): void {
		$props = $this->usage()['properties'];
		foreach (['consumer', 'module', 'moduleVersion', 'status', 'contactPerson', 'timeClassification'] as $field) {
			$this->assertArrayHasKey($field, $props, $field);
		}

		$this->assertSame(['Acquisition', 'Planned', 'In production', 'To be phased out', 'Phased out'], $props['status']['enum']);
		$this->assertTrue($props['status']['facetable']);
	}//end testTheMergeKeepsTheExistingProperties()

	/**
	 * Plan, Go live, Phase out and Retire name only states the status enum holds.
	 *
	 * @return void
	 */
	public function testTheLifecycleNamesTheEnumValues(): void {
		$usage     = $this->usage();
		$lifecycle = $usage['configuration']['x-openregister-lifecycle'];
		$enum      = $usage['properties']['status']['enum'];

		$this->assertSame('status', $lifecycle['field']);
		$this->assertContains($lifecycle['initial'], $enum);
		$this->assertSame(['plan', 'goLive', 'phaseOut', 'retire'], array_keys($lifecycle['transitions']));
		foreach ($lifecycle['transitions'] as $name => $transition) {
			$this->assertContains($transition['to'], $enum, $name);
			foreach ($transition['from'] as $from) {
				$this->assertContains($from, $enum, $name);
			}
		}

		$this->assertSame(['Planned'], $lifecycle['transitions']['goLive']['from']);
		$this->assertSame('In production', $lifecycle['transitions']['goLive']['to']);
	}//end testTheLifecycleNamesTheEnumValues()

	/**
	 * A usage is named after its application and organisation, from keys the schema has.
	 *
	 * @return void
	 */
	public function testTheNameTemplateReadsExistingKeys(): void {
		$usage    = $this->usage();
		$template = $usage['configuration']['objectNameField'];

		$this->assertSame('{{ module }} ({{ consumer }})', $template);
		preg_match_all('/{{\s*([A-Za-z]+)/', $template, $keys);
		foreach ($keys[1] as $key) {
			$this->assertArrayHasKey($key, $usage['properties'], $key);
		}
	}//end testTheNameTemplateReadsExistingKeys()

	/**
	 * The schema version moves up, or the import skips the fragment.
	 *
	 * @return void
	 */
	public function testTheSchemaVersionMovesUp(): void {
		$this->assertTrue(version_compare($this->usage()['version'], '1.5.1', '>'));
	}//end testTheSchemaVersionMovesUp()

	/**
	 * A supplier reads contact persons of its own organisation only, so it cannot open the owners of a customer.
	 *
	 * @return void
	 */
	public function testASupplierCannotOpenTheOwnersOfACustomer(): void {
		$read     = $this->register()['components']['schemas']['contactPerson']['authorization']['read'];
		$supplier = array_values(
			array_filter(
				$read,
				static fn ($rule): bool => $rule === 'aanbod-beheerder' || (is_array($rule) === true && ($rule['group'] ?? '') === 'aanbod-beheerder')
			)
		);

		$this->assertSame([['group' => 'aanbod-beheerder', 'match' => ['_organisation' => '$organisation']]], $supplier);
	}//end testASupplierCannotOpenTheOwnersOfACustomer()

	/**
	 * The seeded usages carry a status the enum holds, one of them planned.
	 *
	 * @return void
	 */
	public function testTheSeededUsagesCarryEnumStatuses(): void {
		$register = $this->register();
		$enum     = $register['components']['schemas']['usage']['properties']['status']['enum'];
		$statuses = [];
		foreach ($register['components']['objects'] as $object) {
			if (($object['@self']['schema'] ?? '') !== 'usage') {
				continue;
			}

			$this->assertContains($object['status'], $enum, $object['@self']['slug']);
			$statuses[] = $object['status'];
		}

		$this->assertContains('In production', $statuses);
		$this->assertContains('Planned', $statuses);
	}//end testTheSeededUsagesCarryEnumStatuses()
}//end class
