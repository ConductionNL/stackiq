<?php

/**
 * The duplicate and merge declarations on applications and services.
 *
 * @category  Tests
 * @package   OCA\Stackiq\Tests\Unit\Settings
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/record-reconciliation/spec.md#requirement-req-rrc-001-applications-services-and-organisations-shall-declare-duplicate-rules-and-applications-and-services-shall-declare-how-they-merge
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Settings;

use OCA\Stackiq\Service\SettingsService;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Merges every register.d fragment into the monolith the way SettingsService
 * loads it (sorted, deepMergeConfig) and reads module and catalogService.
 */
class ReconciliationDeclarationTest extends TestCase {

	/**
	 * The register as OpenRegister imports it.
	 *
	 * @return array<string, mixed> The merged register.
	 */
	public static function mergedRegister(): array {
		$dir    = __DIR__ . '/../../../lib/Settings';
		$merged = json_decode((string) file_get_contents($dir . '/softwarecatalogus_register.json'), true);
		$files  = glob($dir . '/register.d/*.json');
		sort($files);
		$merge = new ReflectionMethod(SettingsService::class, 'deepMergeConfig');
		foreach ($files as $file) {
			$merged = $merge->invoke(null, $merged, json_decode((string) file_get_contents($file), true));
		}

		return $merged;
	}//end mergedRegister()

	/**
	 * One schema of the merged register.
	 *
	 * @param string $slug The schema key.
	 *
	 * @return array<string, mixed> The schema.
	 */
	private function schema(string $slug): array {
		return self::mergedRegister()['components']['schemas'][$slug];
	}//end schema()

	/**
	 * Applications and services declare how OpenRegister merges them.
	 *
	 * @return void
	 */
	public function testApplicationsAndServicesDeclareTheMerge(): void {
		foreach (['module', 'catalogService'] as $slug) {
			$merge = $this->schema($slug)['configuration']['x-openregister-merge'] ?? null;
			$this->assertSame(
				[
					'statusField' => 'recordStatus',
					'survivorStatus' => 'Active',
					'mergedStatus' => 'Merged',
					'reversalWindowDays' => 30,
				],
				$merge,
				$slug . ' must declare x-openregister-merge'
			);
		}
	}//end testApplicationsAndServicesDeclareTheMerge()

	/**
	 * Applications, services and organisations declare their duplicate rules.
	 *
	 * @return void
	 */
	public function testApplicationsServicesAndOrganisationsDeclareDuplicateRules(): void {
		foreach (['module', 'catalogService', 'organization'] as $slug) {
			$dedup = $this->schema($slug)['configuration']['x-openregister-dedup'] ?? null;
			$this->assertIsArray($dedup, $slug . ' must declare x-openregister-dedup');
			$this->assertNotEmpty($dedup['matchRules'] ?? []);
			$this->assertIsFloat($dedup['threshold'] ?? null);
		}

		$service = $this->schema('catalogService')['configuration']['x-openregister-dedup'];
		$this->assertSame(0.7, $service['threshold']);
		$this->assertEqualsCanonicalizing(
			['name', 'provider', 'website'],
			array_values(array_unique(array_column($service['matchRules'], 'field')))
		);
		foreach ($service['matchRules'] as $rule) {
			$this->assertArrayHasKey($rule['field'], $this->schema('catalogService')['properties'], 'a match rule reads a property the service has');
		}

		$this->assertEqualsWithDelta(1.0, array_sum(array_column($service['matchRules'], 'weight')), 0.0001);
	}//end testApplicationsServicesAndOrganisationsDeclareDuplicateRules()

	/**
	 * The status the merge writes, and where it points.
	 *
	 * @return void
	 */
	public function testTheRecordStatusAndMergedIntoProperties(): void {
		foreach (['module', 'catalogService'] as $slug) {
			$properties = $this->schema($slug)['properties'];
			$status     = $properties['recordStatus'] ?? [];
			$this->assertSame(['Active', 'Merged'], $status['enum'] ?? null, $slug . '.recordStatus');
			$this->assertSame('Active', $status['default'] ?? null, 'a new record starts Active');
			$this->assertTrue($status['hideOnForm'] ?? false, 'only the merge sets the status');
			$into = $properties['mergedInto'] ?? [];
			$this->assertSame('string', $into['type'] ?? null);
			$this->assertSame('uuid', $into['format'] ?? null);
			$this->assertTrue($into['hideOnForm'] ?? false);
		}
	}//end testTheRecordStatusAndMergedIntoProperties()

	/**
	 * The schema versions move up so the import applies the change.
	 *
	 * @return void
	 */
	public function testTheSchemaVersionsMoveUp(): void {
		$this->assertTrue(version_compare($this->schema('module')['version'], '0.3.4', '>'), 'module past 0.3.4');
		$this->assertTrue(version_compare($this->schema('catalogService')['version'], '0.2.1', '>'), 'catalogService past 0.2.1');
	}//end testTheSchemaVersionsMoveUp()

	/**
	 * Every demo application and service is Active, and the seeded duplicate
	 * matches its original on every field the rules read.
	 *
	 * @return void
	 */
	public function testTheSeedsAreActiveAndCarryOneDuplicatePair(): void {
		$mock      = json_decode((string) file_get_contents(__DIR__ . '/../../../lib/Settings/stackiq_mock_register.json'), true);
		$validator = new Validator();
		$status    = json_decode((string) json_encode($this->schema('module')['properties']['recordStatus']));
		$byName    = [];
		$count     = 0;
		foreach (($mock['components']['objects'] ?? []) as $object) {
			$schema = ($object['@self']['schema'] ?? '');
			if (in_array($schema, ['module', 'catalogService'], true) === false) {
				continue;
			}

			$count++;
			$this->assertSame('Active', $object['recordStatus'] ?? null, ($object['@self']['slug'] ?? '?') . ' must be Active');
			$this->assertTrue($validator->validate('Active', $status)->isValid());
			if ($schema === 'module') {
				$byName[($object['@self']['register'] ?? '') . ':' . mb_strtolower(trim((string) ($object['name'] ?? '')))][] = $object;
			}
		}

		$this->assertGreaterThan(0, $count);
		$pairs = array_filter($byName, static fn (array $group): bool => count($group) > 1);
		$this->assertNotEmpty($pairs, 'one application is seeded twice');
		foreach ($pairs as $group) {
			$this->assertSame($group[0]['provider'] ?? null, $group[1]['provider'] ?? null, 'same supplier');
			$this->assertSame($group[0]['website'] ?? null, $group[1]['website'] ?? null, 'same website');
			$this->assertNotSame($group[0]['name'], $group[1]['name'], 'spelled differently');
		}
	}//end testTheSeedsAreActiveAndCarryOneDuplicatePair()
}//end class
