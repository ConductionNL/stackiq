<?php

/**
 * The service desk exchange fields, as the app imports the merged register.
 *
 * @category  Tests
 * @package   OCA\Stackiq\Tests\Unit\Settings
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/sharing-itsm-exchange/specs/itsm-exchange/spec.md#requirement-req-itx-005-licences-and-contracts-carry-what-a-cmdb-needs
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Settings;

use OCA\Stackiq\Service\SettingsService;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Asserts the reference fields, the licence fields, the versions and a real licence payload.
 */
class ItsmExchangeFragmentTest extends TestCase {

	/**
	 * The schema versions on development before this change.
	 *
	 * @var array<string, string>
	 */
	private const VERSIONS_BEFORE = [
		'usage'           => '1.5.3',
		'connection'      => '0.3.3',
		'catalogContract' => '0.1.3',
	];

	/**
	 * The register merged the way SettingsService::loadSettings merges it: every fragment, in file name order.
	 *
	 * @return array<string, mixed>
	 */
	private function register(): array {
		$dir    = __DIR__ . '/../../../lib/Settings';
		$merged = json_decode((string) file_get_contents($dir . '/softwarecatalogus_register.json'), true);
		$merge  = new ReflectionMethod(SettingsService::class, 'deepMergeConfig');
		$files  = glob($dir . '/register.d/*.json');
		sort($files);
		foreach ($files as $file) {
			$merged = $merge->invoke(null, $merged, json_decode((string) file_get_contents($file), true));
		}

		return $merged;
	}//end register()

	/**
	 * Usage, connection and contract carry the service desk reference, written by the import, not typed.
	 *
	 * @return void
	 */
	public function testThreeSchemasCarryTheServiceDeskReference(): void {
		$schemas = $this->register()['components']['schemas'];
		foreach (array_keys(self::VERSIONS_BEFORE) as $key) {
			$props = $schemas[$key]['properties'];
			foreach (['serviceDeskSystem', 'serviceDeskRecordId', 'serviceDeskUrl', 'serviceDeskSyncedAt'] as $field) {
				$this->assertArrayHasKey($field, $props, $key . '.' . $field);
				$this->assertTrue($props[$field]['hideOnForm'], $key . '.' . $field . ' is written by the import');
				$this->assertNotEmpty($props[$field]['title']);
				$this->assertNotEmpty($props[$field]['description']);
			}

			$this->assertSame(['topdesk', 'servicenow', 'file'], $props['serviceDeskSystem']['enum']);
			$this->assertSame('uri', $props['serviceDeskUrl']['format']);
			$this->assertSame('date-time', $props['serviceDeskSyncedAt']['format']);
		}

		$usage = $schemas['usage']['properties'];
		$this->assertSame('string', $usage['installedVersion']['type']);
		$this->assertSame('date-time', $usage['publicationDate']['format']);
	}//end testThreeSchemasCarryTheServiceDeskReference()

	/**
	 * Every changed schema moved its version up, so the import applies it.
	 *
	 * @return void
	 */
	public function testEveryChangedSchemaMovedItsVersionUp(): void {
		$schemas = $this->register()['components']['schemas'];
		foreach (self::VERSIONS_BEFORE as $key => $before) {
			$this->assertTrue(version_compare($schemas[$key]['version'], $before, '>'), $key . ' is ' . $schemas[$key]['version']);
		}
	}//end testEveryChangedSchemaMovedItsVersionUp()

	/**
	 * A licence for an application in use, with no catalogue service, validates against the merged contract schema.
	 *
	 * @return void
	 */
	public function testALicenceWithoutAServiceValidates(): void {
		$schema = $this->register()['components']['schemas']['catalogContract'];
		$this->assertNotContains('service', $schema['required']);
		$this->assertArrayNotHasKey('required', $schema['properties']['service']);

		$payload = [
			'usage'           => '5b2c0f4e-1111-4a9b-8c1d-9f0e1a2b3c4d',
			'supplier'        => '5b2c0f4e-2222-4a9b-8c1d-9f0e1a2b3c4d',
			'contractNumber'  => 'LIC-2026-014',
			'vendorReference' => 'TD-AGR-88213',
			'contractType'    => 'Licence',
			'status'          => 'Active',
			'startDate'       => '2026-01-01T00:00:00+01:00',
			'endDate'         => '2028-12-31T00:00:00+01:00',
			'cost'            => 12000.0,
			'costPeriod'      => 'Annually',
			'currency'        => 'EUR',
			'licenceMetric'   => 'Per named user',
			'licencesBought'  => 50,
			'serviceDeskSystem'   => 'topdesk',
			'serviceDeskRecordId' => 'a8c2d1f0-0001',
			'serviceDeskUrl'      => 'https://desk.example.nl/tas/secure/contract?unid=a8c2d1f0-0001',
			'serviceDeskSyncedAt' => '2026-10-01T02:00:00+02:00',
		];

		$result = (new Validator())->validate(json_decode((string) json_encode($payload)), json_decode((string) json_encode($this->validatable(schema: $schema))));
		$this->assertTrue($result->isValid(), (string) json_encode($result->error()?->args()));

		$bad             = $payload;
		$bad['currency'] = 'euro';
		$result          = (new Validator())->validate(json_decode((string) json_encode($bad)), json_decode((string) json_encode($this->validatable(schema: $schema))));
		$this->assertFalse($result->isValid(), 'a currency that is not an ISO 4217 code is refused');

		unset($payload['usage']);
		$result = (new Validator())->validate(json_decode((string) json_encode($payload)), json_decode((string) json_encode($this->validatable(schema: $schema))));
		$this->assertFalse($result->isValid(), 'a contract still needs its application in use');
	}//end testALicenceWithoutAServiceValidates()

	/**
	 * The schema in the shape a JSON Schema validator reads: relations are uuids, OpenRegister's own keys dropped.
	 *
	 * @param array<string, mixed> $schema The register schema.
	 *
	 * @return array<string, mixed> The validatable schema.
	 */
	private function validatable(array $schema): array {
		$props = [];
		foreach ($schema['properties'] as $name => $prop) {
			if (isset($prop['$ref']) === true || isset($prop['items']['$ref']) === true) {
				$props[$name] = ['type' => ['string', 'object', 'array', 'null']];
				continue;
			}

			$keep = [];
			foreach (['type', 'enum', 'format', 'pattern', 'minimum', 'maximum', 'maxLength'] as $key) {
				if (isset($prop[$key]) === true) {
					$keep[$key] = $prop[$key];
				}
			}

			if (($keep['format'] ?? '') === 'date-time' || ($keep['format'] ?? '') === 'date') {
				unset($keep['format']);
			}

			$props[$name] = $keep;
		}

		return ['type' => 'object', 'required' => $schema['required'], 'properties' => $props];
	}//end validatable()
}//end class
