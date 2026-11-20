<?php

/**
 * Characterisation tests for SettingsService::configureAmef(): how it finds
 * the AMEF register among the registers OpenRegister holds, how it resolves
 * schema ids, and the config it persists.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/settings-service/spec.md#requirement-the-system-shall-run-auto-configuration-import-and-configuration-maintenance-req-003
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\RegisterService;
use OCA\Stackiq\Service\SettingsService;
use OCA\Stackiq\Service\Settings\CatalogueLocator;
use OCA\Stackiq\Service\Settings\EmailSettings;
use OCA\Stackiq\Service\Settings\RoleGroupSettings;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Pins the register detection and schema mapping of configureAmef().
 */
#[CoversClass(SettingsService::class)]
final class SettingsServiceConfigureAmefTest extends TestCase {

	/**
	 * The config the service persisted, or null when it persisted nothing.
	 *
	 * @var array<string, string>|null
	 */
	private ?array $persisted = null;

	/**
	 * Whether OpenRegister's object service is available.
	 *
	 * @var boolean
	 */
	private bool $objectServiceAvailable = true;

	/**
	 * The ids the schema mapper was asked for, per call.
	 *
	 * @var array<int, array<int, int>>
	 */
	private array $requestedIds = [];

	/**
	 * Build a schema entity double.
	 *
	 * @param array<string, mixed> $row The schema row.
	 *
	 * @return object The entity.
	 */
	private function schema(array $row): object {
		return new class($row) {
			/**
			 * @param array<string, mixed> $row The schema row.
			 */
			public function __construct(private array $row) {
			}

			/**
			 * @return int The schema id.
			 */
			public function getId(): int {
				return (int) $this->row['id'];
			}

			/**
			 * @return array<string, mixed> The schema row.
			 */
			public function jsonSerialize(): array {
				return $this->row;
			}
		};
	}//end schema()

	/**
	 * Run configureAmef() against the given registers and schema rows.
	 *
	 * @param array<int, mixed>|\Throwable          $registers   What findAll() returns or throws.
	 * @param array<int, array<string, mixed>>|null $schemaRows  Rows the schema mapper holds, null for no mapper.
	 *
	 * @return array<string, mixed> The result of configureAmef().
	 */
	private function configure($registers, ?array $schemaRows = []): array {
		$rows = $schemaRows ?? [];
		$test = $this;
		$mapper = new class($rows, $test) {
			/**
			 * @param array<int, array<string, mixed>> $rows Schema rows by id.
			 * @param SettingsServiceConfigureAmefTest $test The test, to build entities and record calls.
			 */
			public function __construct(private array $rows, private SettingsServiceConfigureAmefTest $test) {
			}

			/**
			 * @param array<int, int> $ids The schema ids.
			 *
			 * @return array<int, object> The schema entities found.
			 */
			public function findMultipleOptimized(array $ids): array {
				$this->test->recordRequestedIds(ids: $ids);
				$found = [];
				foreach ($ids as $id) {
					if (isset($this->rows[$id]) === true) {
						$found[] = $this->test->buildSchema(row: $this->rows[$id]);
					}
				}

				return $found;
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($mapper, $schemaRows) {
				if ($id === SchemaMapper::class && $schemaRows !== null) {
					return $mapper;
				}

				throw new \RuntimeException("No {$id}");
			}
		);

		$registerService = $this->createMock(RegisterService::class);
		if ($registers instanceof \Throwable) {
			$registerService->method('findAll')->willThrowException($registers);
		} else {
			$registerService->method('findAll')->willReturn($registers);
		}

		$objectService = null;
		if ($this->objectServiceAvailable === true) {
			$objectService = $this->createMock(ObjectServiceInterface::class);
		}

		$catalogue = $this->createMock(CatalogueLocator::class);
		$catalogue->method('getObjectService')->willReturn($objectService);
		$catalogue->method('getRegisterService')->willReturn($registerService);
		$catalogue->method('setAmefConfig')->willReturnCallback(
			function (array $config): void {
				$this->persisted = $config;
			}
		);

		$service = new SettingsService(
			$catalogue,
			$this->createMock(IAppConfig::class),
			$this->createMock(IRequest::class),
			$this->createMock(RoleGroupSettings::class),
			$container,
			$this->createMock(IAppManager::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(IL10N::class),
			$this->createMock(EmailSettings::class),
		);

		$method = new ReflectionMethod($service, 'configureAmef');
		$method->setAccessible(true);
		return $method->invoke($service);
	}//end configure()

	/**
	 * Record the ids a schema-mapper call asked for.
	 *
	 * @param array<int, int> $ids The ids.
	 *
	 * @return void
	 */
	public function recordRequestedIds(array $ids): void {
		$this->requestedIds[] = array_values($ids);
	}//end recordRequestedIds()

	/**
	 * Expose the entity builder to the mapper double.
	 *
	 * @param array<string, mixed> $row The schema row.
	 *
	 * @return object The entity.
	 */
	public function buildSchema(array $row): object {
		return $this->schema(row: $row);
	}//end buildSchema()

	/**
	 * Without OpenRegister nothing is configured.
	 *
	 * @return void
	 */
	public function testWithoutObjectServiceReportsUnavailable(): void {
		$this->objectServiceAvailable = false;

		$result = $this->configure(registers: []);

		$this->assertSame(['success' => false, 'message' => 'OpenRegister service not available'], $result);
		$this->assertNull($this->persisted);
	}//end testWithoutObjectServiceReportsUnavailable()

	/**
	 * A failing register lookup is reported with its message, not thrown.
	 *
	 * @return void
	 */
	public function testFailingRegisterLookupIsReported(): void {
		$result = $this->configure(registers: new \RuntimeException('database gone'));

		$this->assertSame(
			['success' => false, 'message' => 'Failed to retrieve registers: database gone'],
			$result
		);
		$this->assertNull($this->persisted);
	}//end testFailingRegisterLookupIsReported()

	/**
	 * A TypeError from the register service is caught the same way.
	 *
	 * @return void
	 */
	public function testTypeErrorFromRegisterLookupIsReported(): void {
		$result = $this->configure(registers: new \TypeError('bad return'));

		$this->assertFalse($result['success']);
		$this->assertSame('Failed to retrieve registers: bad return', $result['message']);
	}//end testTypeErrorFromRegisterLookupIsReported()

	/**
	 * No registers at all is reported as such.
	 *
	 * @return void
	 */
	public function testNoRegistersIsReported(): void {
		$result = $this->configure(registers: []);

		$this->assertSame(['success' => false, 'message' => 'No registers available'], $result);
	}//end testNoRegistersIsReported()

	/**
	 * A register with fewer than three AMEF core schemas is not taken.
	 *
	 * @return void
	 */
	public function testTwoCoreSchemasAreNotEnough(): void {
		$registers = [
			[
				'id' => 4,
				'schemas' => [
					['id' => 10, 'slug' => 'model'],
					['id' => 11, 'slug' => 'element'],
					['id' => 12, 'slug' => 'sector'],
				],
			],
		];

		$result = $this->configure(registers: $registers);

		$this->assertSame(['success' => false, 'message' => 'AMEF register not found'], $result);
		$this->assertNull($this->persisted);
	}//end testTwoCoreSchemasAreNotEnough()

	/**
	 * Inline schema objects are mapped to their config keys, slugs compared
	 * case-insensitively, and property-definition gets its underscore key.
	 *
	 * @return void
	 */
	public function testInlineSchemasAreMappedToTheirConfigKeys(): void {
		$registers = [
			[
				'id' => 7,
				'schemas' => [
					['id' => 20, 'slug' => 'Model'],
					['id' => 21, 'slug' => 'element'],
					['id' => 22, 'slug' => 'relation'],
					['id' => 23, 'slug' => 'property-definition'],
					['id' => 24, 'slug' => 'property'],
				],
			],
		];

		$result = $this->configure(registers: $registers);

		$expected = [
			'register' => '7',
			'organization_schema' => '',
			'element_schema' => '21',
			'relation_schema' => '22',
			'view_schema' => '',
			'model_schema' => '20',
			'property_definition_schema' => '23',
		];
		$this->assertSame(
			[
				'success' => true,
				'message' => 'AMEF configuration completed successfully',
				'configured' => $expected,
				'errors' => [],
			],
			$result
		);
		$this->assertSame($expected, $this->persisted);
		$this->assertSame([], $this->requestedIds, 'Inline schemas need no mapper lookup.');
	}//end testInlineSchemasAreMappedToTheirConfigKeys()

	/**
	 * Schema ids (ints and numeric strings) of every register are fetched in one
	 * de-duplicated batch and resolved from it; ids the mapper does not know are
	 * dropped.
	 *
	 * @return void
	 */
	public function testSchemaIdsAreResolvedInOneBatch(): void {
		$registers = [
			['id' => 1, 'schemas' => [30, '31']],
			['id' => 2, 'schemas' => [30, 32, 33, 99]],
		];
		$rows = [
			30 => ['id' => 30, 'slug' => 'organization'],
			31 => ['id' => 31, 'slug' => 'sector'],
			32 => ['id' => 32, 'slug' => 'view'],
			33 => ['id' => 33, 'slug' => 'element'],
		];

		$result = $this->configure(registers: $registers, schemaRows: $rows);

		$this->assertSame([[30, 31, 32, 33, 99]], $this->requestedIds);
		$this->assertTrue($result['success']);
		$this->assertSame(
			[
				'register' => '2',
				'organization_schema' => '30',
				'element_schema' => '33',
				'relation_schema' => '',
				'view_schema' => '32',
				'model_schema' => '',
				'property_definition_schema' => '',
			],
			$this->persisted
		);
	}//end testSchemaIdsAreResolvedInOneBatch()

	/**
	 * Of several qualifying registers the one with the most core schemas wins,
	 * even when it comes first.
	 *
	 * @return void
	 */
	public function testTheRegisterWithMostCoreSchemasWins(): void {
		$registers = [
			[
				'id' => 1,
				'schemas' => [
					['id' => 1, 'slug' => 'model'],
					['id' => 2, 'slug' => 'element'],
					['id' => 3, 'slug' => 'relation'],
					['id' => 4, 'slug' => 'view'],
				],
			],
			[
				'id' => 2,
				'schemas' => [
					['id' => 5, 'slug' => 'model'],
					['id' => 6, 'slug' => 'element'],
					['id' => 7, 'slug' => 'relation'],
				],
			],
		];

		$result = $this->configure(registers: $registers);

		$this->assertTrue($result['success']);
		$this->assertSame('1', $this->persisted['register']);
		$this->assertSame('4', $this->persisted['view_schema']);
	}//end testTheRegisterWithMostCoreSchemasWins()

	/**
	 * Without a schema mapper only inline schemas count, so id-only registers
	 * are never detected.
	 *
	 * @return void
	 */
	public function testWithoutSchemaMapperIdOnlyRegistersAreNotDetected(): void {
		$registers = [
			['id' => 3, 'schemas' => [1, 2, 3]],
		];

		$result = $this->configure(registers: $registers, schemaRows: null);

		$this->assertSame(['success' => false, 'message' => 'AMEF register not found'], $result);
	}//end testWithoutSchemaMapperIdOnlyRegistersAreNotDetected()

	/**
	 * Register objects are read through their array cast.
	 *
	 * @return void
	 */
	public function testRegisterObjectsAreReadAsArrays(): void {
		$register = new \stdClass();
		$register->id = 9;
		$register->schemas = [
			['id' => 40, 'slug' => 'model'],
			['id' => 41, 'slug' => 'view'],
			['id' => 42, 'slug' => 'organization'],
		];

		$result = $this->configure(registers: [$register]);

		$this->assertTrue($result['success']);
		$this->assertSame('9', $this->persisted['register']);
		$this->assertSame('42', $this->persisted['organization_schema']);
	}//end testRegisterObjectsAreReadAsArrays()

	/**
	 * A failure while persisting is caught and reported.
	 *
	 * @return void
	 */
	public function testPersistFailureIsReported(): void {
		$registers = [
			[
				'id' => 5,
				'schemas' => [
					['id' => 1, 'slug' => 'model'],
					['id' => 2, 'slug' => 'element'],
					['id' => 3, 'slug' => 'view'],
				],
			],
		];
		$catalogueFails = new \RuntimeException('write refused');

		$catalogue = $this->createMock(CatalogueLocator::class);
		$catalogue->method('getObjectService')->willReturn($this->createMock(ObjectServiceInterface::class));
		$registerService = $this->createMock(RegisterService::class);
		$registerService->method('findAll')->willReturn($registers);
		$catalogue->method('getRegisterService')->willReturn($registerService);
		$catalogue->method('setAmefConfig')->willThrowException($catalogueFails);

		$service = new SettingsService(
			$catalogue,
			$this->createMock(IAppConfig::class),
			$this->createMock(IRequest::class),
			$this->createMock(RoleGroupSettings::class),
			$this->createMock(ContainerInterface::class),
			$this->createMock(IAppManager::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(IL10N::class),
			$this->createMock(EmailSettings::class),
		);
		$method = new ReflectionMethod($service, 'configureAmef');
		$method->setAccessible(true);

		$result = $method->invoke($service);

		$this->assertSame(
			[
				'success' => false,
				'message' => 'AMEF configuration failed: write refused',
				'error' => 'write refused',
			],
			$result
		);
	}//end testPersistFailureIsReported()
}//end class
