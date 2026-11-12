<?php

/**
 * Characterisation tests for SettingsService::configureVoorzieningen(): which
 * register it picks, how it resolves schema ids, and the config it persists.
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
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Pins the register selection and schema mapping of configureVoorzieningen().
 */
final class SettingsServiceConfigureVoorzieningenTest extends TestCase {

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
	 * Run configureVoorzieningen() against the given registers and schema rows.
	 *
	 * @param array<int, mixed>|\Throwable $registers What findAll() returns or throws.
	 * @param array<int, array<string, mixed>>|null $schemasById Rows the schema mapper finds, null for no mapper.
	 *
	 * @return array<string, mixed> The result of configureVoorzieningen().
	 */
	private function configure($registers, ?array $schemasById = []): array {
		$mapper = new class($schemasById ?? []) {
			/**
			 * @param array<int, array<string, mixed>> $rows Schema rows by id.
			 */
			public function __construct(private array $rows) {
			}

			/**
			 * @param int $id The schema id.
			 *
			 * @return object|null The schema entity.
			 */
			public function find(int $id): ?object {
				if (array_key_exists($id, $this->rows) === false) {
					throw new \RuntimeException('Schema not found');
				}

				$row = $this->rows[$id];
				if ($row === null) {
					return null;
				}

				return new class($row) {
					/**
					 * @param array<string, mixed> $row The schema row.
					 */
					public function __construct(private array $row) {
					}

					/**
					 * @return array<string, mixed> The schema row.
					 */
					public function jsonSerialize(): array {
						return $this->row;
					}
				};
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($mapper, $schemasById) {
				if ($id === SchemaMapper::class && $schemasById !== null) {
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

		// The OpenRegister services and the stored config live in CatalogueLocator.
		$catalogue = $this->createMock(CatalogueLocator::class);
		$catalogue->method('getObjectService')->willReturn($objectService);
		$catalogue->method('getRegisterService')->willReturn($registerService);
		$catalogue->method('setVoorzieningenConfig')->willReturnCallback(
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

		$method = new ReflectionMethod($service, 'configureVoorzieningen');
		$method->setAccessible(true);
		return $method->invoke($service);
	}//end configure()

	/**
	 * Without OpenRegister nothing is configured.
	 *
	 * @return void
	 */
	public function testWithoutObjectServiceReportsUnavailable(): void {
		$this->objectServiceAvailable = false;
		$result = $this->configure([], []);

		$this->assertSame(['success' => false, 'message' => 'OpenRegister service not available'], $result);
		$this->assertNull($this->persisted);
	}//end testWithoutObjectServiceReportsUnavailable()

	/**
	 * A failing findAll() is reported with its message.
	 *
	 * @return void
	 */
	public function testFailingRegisterLookupIsReported(): void {
		$result = $this->configure(new \RuntimeException('db down'));

		$this->assertSame(['success' => false, 'message' => 'Failed to retrieve registers: db down'], $result);
	}//end testFailingRegisterLookupIsReported()

	/**
	 * No registers means nothing to configure.
	 *
	 * @return void
	 */
	public function testNoRegistersIsReported(): void {
		$this->assertSame(['success' => false, 'message' => 'No registers available'], $this->configure([]));
	}//end testNoRegistersIsReported()

	/**
	 * A register without the slug and with too few known schemas is not picked.
	 *
	 * @return void
	 */
	public function testUnrecognisedRegisterIsNotFound(): void {
		$register = [
			'id' => 3,
			'slug' => 'other',
			'schemas' => [['slug' => 'sector'], ['slug' => 'suite'], 7, 99],
		];

		$result = $this->configure([$register], [7 => ['id' => 7, 'slug' => 'module']]);

		$this->assertSame(['success' => false, 'message' => 'Voorzieningen register not found'], $result);
		$this->assertNull($this->persisted);
	}//end testUnrecognisedRegisterIsNotFound()

	/**
	 * The stackiq register wins, ids resolve through the mapper, unknown ids
	 * are skipped and slugs map in their original case before lowercase.
	 *
	 * @return void
	 */
	public function testStackiqRegisterIsConfiguredFromItsSchemas(): void {
		$stackiq = [
			'id' => 12,
			'slug' => 'StackIQ',
			'schemas' => [
				['id' => 1, 'slug' => 'organization'],
				'2',
				3,
				404,
				['id' => 5],
				['id' => 6, 'slug' => 'unknownThing'],
				['id' => 7, 'slug' => 'SECTOR'],
			],
		];
		$heuristic = [
			'id' => 13,
			'slug' => 'later',
			'schemas' => [
				['slug' => 'sector'],
				['slug' => 'suite'],
				['slug' => 'module'],
				['slug' => 'usage'],
				['slug' => 'connection'],
				['slug' => 'compliancy'],
			],
		];
		$rows = [
			2 => ['id' => 2, 'slug' => 'moduleVersion'],
			3 => null,
		];

		$result = $this->configure([$stackiq, $heuristic], $rows);

		$expected = [
			'register' => '12',
			'organisatie_schema' => '1',
			'moduleVersie_schema' => '2',
			'sector_schema' => '7',
		];
		$this->assertSame(
			['success' => true, 'message' => 'Voorzieningen configured successfully', 'configured' => $expected],
			$result
		);
		$this->assertSame($expected, $this->persisted);
	}//end testStackiqRegisterIsConfiguredFromItsSchemas()

	/**
	 * Without the slug, a register with six or more known schema slugs is
	 * picked, and a later match replaces an earlier one.
	 *
	 * @return void
	 */
	public function testHeuristicPicksTheLastRegisterWithSixKnownSchemas(): void {
		$first = [
			'id' => 20,
			'slug' => 'voorzieningen',
			'schemas' => [21, 22, 23, 24, 25, 26],
		];
		$second = [
			'id' => 30,
			'slug' => '',
			'schemas' => [
				['id' => 31, 'slug' => 'sector'],
				['id' => 32, 'slug' => 'suite'],
				['id' => 33, 'slug' => 'module'],
				['id' => 34, 'slug' => 'usage'],
				['id' => 35, 'slug' => 'connection'],
				36,
				99,
			],
		];
		$rows = [
			21 => ['id' => 21, 'slug' => 'sector'],
			22 => ['id' => 22, 'slug' => 'suite'],
			23 => ['id' => 23, 'slug' => 'module'],
			24 => ['id' => 24, 'slug' => 'usage'],
			25 => ['id' => 25, 'slug' => 'connection'],
			26 => ['id' => 26, 'slug' => 'compliancy'],
			36 => ['id' => 36, 'slug' => 'vulnerability'],
		];

		$result = $this->configure([$first, $second], $rows);

		$this->assertTrue($result['success']);
		$this->assertSame(
			[
				'register' => '30',
				'sector_schema' => '31',
				'suite_schema' => '32',
				'module_schema' => '33',
				'gebruik_schema' => '34',
				'koppeling_schema' => '35',
				'kwetsbaarheid_schema' => '36',
			],
			$this->persisted
		);
	}//end testHeuristicPicksTheLastRegisterWithSixKnownSchemas()

	/**
	 * Without a schema mapper only inline schema objects count.
	 *
	 * @return void
	 */
	public function testWithoutSchemaMapperOnlyInlineSchemasCount(): void {
		$register = [
			'id' => 40,
			'slug' => 'stackiq',
			'schemas' => [['id' => 41, 'slug' => 'suite'], 42],
		];

		$result = $this->configure([$register], null);

		$this->assertTrue($result['success']);
		$this->assertSame(['register' => '40', 'suite_schema' => '41'], $this->persisted);
	}//end testWithoutSchemaMapperOnlyInlineSchemasCount()

}//end class
