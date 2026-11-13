<?php

/**
 * Golden test for the import error report and the AMEF schema id lookup.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\OpenRegister\Service\OrganisationService;
use OCA\Stackiq\Service\ArchiMateImportService;
use OCA\Stackiq\Service\ProgressTracker;
use OCA\Stackiq\Service\Settings\ObjectTypeLocatorInterface;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use ReflectionMethod;

/**
 * What ArchiMateImportService reports for import errors and which AMEF
 * schema id it resolves per type, as recorded.
 *
 * @covers \OCA\Stackiq\Service\ArchiMateImportService
 *
 * @spec openspec/specs/archimate-import/spec.md
 */
class ArchiMateErrorsAndSchemaIdsGoldenTest extends TestCase {

	/**
	 * The app config reads, in order.
	 *
	 * @var array<int, mixed>
	 */
	private array $trace = [];

	/**
	 * The app config values by key.
	 *
	 * @var array<string, string>
	 */
	private array $values = [];

	/**
	 * The golden file.
	 *
	 * @return string
	 */
	private static function golden(): string {
		return __DIR__ . '/golden/archiMateErrorsAndSchemaIds.json';

	}//end golden()

	/**
	 * Import statistics with errors.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function errorCases(): array {
		$errors = [
			'Missing name',
			['message' => 'Invalid type value', 'object_id' => 'o-1'],
			['message' => 'Invalid type value', 'object_id' => 'o-2'],
			['object_id' => 'o-3'],
			['message' => 'Duplicate identifier'],
			'Something odd',
		];
		$many = [];
		for ($i = 1; $i <= 8; $i++) {
			$many[] = ['message' => 'Required field ' . $i, 'object_id' => 'm-' . $i];
		}

		return [
			'no statistics'          => [[]],
			'no errors anywhere'     => [['elements' => ['errors' => []], 'views' => ['created' => 3]]],
			'errors in two sections' => [['elements' => ['errors' => $errors], 'property_definitions' => ['errors' => ['Encoding of character']], 'other' => ['errors' => ['ignored']]]],
			'more than five examples' => [['relationships' => ['errors' => $many], 'organizations' => ['errors' => array_slice($many, 0, 2)]]],
			'more than ten kinds'    => [[
				'elements' => ['errors' => ['validation', 'schema', 'reference x', 'property', 'unique', 'source', 'string', 'utf', 'plain', 'validation again']],
				'views'    => ['errors' => ['schema', 'schema', 'utf', 'plain']],
			],
			],
		];

	}//end errorCases()

	/**
	 * The detailed errors match the recording.
	 *
	 * @param array<string, mixed> $statistics The import statistics.
	 *
	 * @return void
	 *
	 * @dataProvider errorCases
	 */
	public function testDetailedErrorsMatchTheRecording(array $statistics): void {
		$service = $this->service();
		$method  = new ReflectionMethod($service, 'extractDetailedErrors');

		$this->check(key: 'errors ' . (string) json_encode(value: $statistics), value: ['result' => $method->invoke($service, $statistics)]);

	}//end testDetailedErrorsMatchTheRecording()

	/**
	 * Types and the app config they are resolved against.
	 *
	 * @return array<string, array{0: string, 1: array<string, string>}>
	 */
	public static function schemaCases(): array {
		$json = static fn (array $config): array => ['amef_config' => (string) json_encode(value: $config)];

		return [
			'nothing configured'          => ['elements', []],
			'json, plural type'           => ['elements', $json(['element_schema' => '12'])],
			'json, relationships'         => ['relationships', $json(['relation_schema' => 5, 'relationship_schema' => 6])],
			'json, unknown type'          => ['custom', $json(['custom_schema' => '7'])],
			'json, zero and text'         => ['views', $json(['view_schema' => '0'])],
			'json, not numeric'           => ['models', $json(['model_schema' => 'abc'])],
			'json, null'                  => ['organizations', $json(['organization_schema' => null])],
			'legacy key'                  => ['views', ['view_schema' => '21']],
			'legacy amef key wins'        => ['views', ['view_schema' => '21', 'amef_view_schema' => '22']],
			'legacy, not positive'        => ['property_definitions', ['property_definition_schema' => '-3']],
			'json invalid, legacy found'  => ['element', ['element_schema' => '8', 'amef_config' => '{}']],
		];

	}//end schemaCases()

	/**
	 * The schema id and the config reads match the recording.
	 *
	 * @param string                $type   The ArchiMate type.
	 * @param array<string, string> $values The app config values.
	 *
	 * @return void
	 *
	 * @dataProvider schemaCases
	 */
	public function testSchemaIdMatchesTheRecording(string $type, array $values): void {
		$this->values = $values;
		$this->trace  = [];
		$service      = $this->service();
		$method       = new ReflectionMethod($service, 'getAmefSchemaIdForType');
		$schemaId     = $method->invoke($service, $type);

		$this->check(key: 'schema ' . $type . ' ' . (string) json_encode(value: $values), value: ['result' => $schemaId, 'reads' => $this->trace]);

	}//end testSchemaIdMatchesTheRecording()

	/**
	 * The service over a recording app config.
	 *
	 * @return ArchiMateImportService
	 */
	private function service(): ArchiMateImportService {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = '') {
				$this->trace[] = [$app, $key, $default];
				return ($this->values[$key] ?? $default);
			}
		);

		return new ArchiMateImportService(
			config: $config,
			userSession: $this->createMock(IUserSession::class),
			appManager: $this->createMock(IAppManager::class),
			container: $this->createMock(ContainerInterface::class),
			logger: new NullLogger(),
			settingsService: $this->createMock(ObjectTypeLocatorInterface::class),
			organisationService: $this->createMock(OrganisationService::class),
			dbConnection: $this->createMock(IDBConnection::class),
			progressTracker: $this->createMock(ProgressTracker::class)
		);

	}//end service()

	/**
	 * Compares a value with its recording, or records it when RECORD_GOLDEN is set.
	 *
	 * @param string               $key   The case key.
	 * @param array<string, mixed> $value The observed value.
	 *
	 * @return void
	 */
	private function check(string $key, array $value): void {
		$json  = json_decode(json: (string) json_encode(value: $value), associative: true);
		$file  = self::golden();
		$saved = [];
		if (file_exists(filename: $file) === true) {
			$saved = json_decode(json: (string) file_get_contents(filename: $file), associative: true);
		}

		if (getenv(name: 'RECORD_GOLDEN') !== false) {
			$saved[$key] = $json;
			file_put_contents(filename: $file, data: json_encode(value: $saved, flags: (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "\n");
			$this->addToAssertionCount(count: 1);
			return;
		}

		$this->assertArrayHasKey(key: $key, array: $saved, message: 'No golden for ' . $key);
		$this->assertSame(expected: $saved[$key], actual: $json);

	}//end check()
}//end class
