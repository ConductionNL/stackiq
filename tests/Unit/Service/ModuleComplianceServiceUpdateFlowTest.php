<?php

/**
 * A module's standard versions derived from its compliance objects, end to end.
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

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\Stackiq\Service\ModuleComplianceService;
use OCA\Stackiq\Service\Settings\RegisterConfigReaderInterface;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;
use RuntimeException;

/**
 * handleModuleComplianceUpdate(): reads the module's compliance objects, and saves
 * their standard versions on the module only when the set changed.
 *
 * @covers \OCA\Stackiq\Service\ModuleComplianceService
 */
final class ModuleComplianceServiceUpdateFlowTest extends TestCase {

	/**
	 * The compliance queries sent.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $queries = [];

	/**
	 * The saves, as [object, register, schema, uuid].
	 *
	 * @var list<array{0: array<string, mixed>, 1: mixed, 2: mixed, 3: mixed}>
	 */
	private array $saved = [];

	/**
	 * The log records, as [level, message].
	 *
	 * @var list<array{0: mixed, 1: string}>
	 */
	private array $records = [];

	/**
	 * A service whose object service answers those compliance objects.
	 *
	 * @param list<object>              $compliance  What the compliance query answers.
	 * @param int|null                  $schemaId    The compliance schema id, or null when unconfigured.
	 * @param array<string, mixed>      $config      The voorzieningen config.
	 * @param bool                      $noService   Whether the container cannot give the object service.
	 * @param RuntimeException|null     $saveFailure What the save throws.
	 *
	 * @return ModuleComplianceService
	 */
	private function service(array $compliance = [], ?int $schemaId = 21, array $config = ['register' => '4'], bool $noService = false, ?RuntimeException $saveFailure = null): ModuleComplianceService {
		$objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objects->method('searchObjects')->willReturnCallback(
			function (array $query) use ($compliance): array {
				$this->queries[] = $query;
				return $compliance;
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], string|int|null $register = null, string|int|null $schema = null, ?string $uuid = null) use ($saveFailure) {
				if ($saveFailure !== null) {
					throw $saveFailure;
				}

				$this->saved[] = [$object, $register, $schema, $uuid];
				return $this->createMock(originalClassName: ObjectEntity::class);
			}
		);

		$container = $this->createMock(originalClassName: ContainerInterface::class);
		if ($noService === true) {
			$container->method('get')->willThrowException(new RuntimeException('not registered'));
		} else {
			$container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($objects);
		}

		$settings = $this->createMock(originalClassName: RegisterConfigReaderInterface::class);
		$settings->method('getSchemaIdForObjectType')->with('compliancy')->willReturn($schemaId);
		$settings->method('getVoorzieningenConfig')->willReturn($config);

		$records = &$this->records;
		$logger  = new class($records) extends AbstractLogger {
			/**
			 * @param array $records The shared record list.
			 */
			public function __construct(private array &$records) {
			}

			/**
			 * @param mixed $level   The level.
			 * @param mixed $message The message.
			 * @param array $context The context.
			 *
			 * @return void
			 */
			public function log($level, $message, array $context = []): void {
				$this->records[] = [$level, (string) $message];
			}
		};

		return new ModuleComplianceService($container, $settings, $logger);
	}//end service()

	/**
	 * A module entity with that uuid and data, in register 4, schema 6.
	 *
	 * @param string|null          $uuid The uuid.
	 * @param array<string, mixed> $data The data.
	 *
	 * @return object
	 */
	private static function module(?string $uuid, array $data): object {
		return new class($uuid, $data) {
			/**
			 * @param string|null $uuid The uuid.
			 * @param array       $data The data.
			 */
			public function __construct(private ?string $uuid, private array $data) {
			}

			/** @return int */
			public function getId(): int {
				return 42;
			}

			/** @return string|null */
			public function getUuid(): ?string {
				return $this->uuid;
			}

			/** @return array */
			public function getObject(): array {
				return $this->data;
			}

			/** @return string */
			public function getRegister(): string {
				return '4';
			}

			/** @return string */
			public function getSchema(): string {
				return '6';
			}
		};
	}//end module()

	/**
	 * A compliance object with that standard version.
	 *
	 * @param mixed $standardVersion The standard version, in any stored shape; omitted when null.
	 *
	 * @return object
	 */
	private static function compliance(mixed $standardVersion): object {
		$data = ['uuid' => 'c'];
		if ($standardVersion !== null) {
			$data['standardVersion'] = $standardVersion;
		}

		return new class($data) {
			/**
			 * @param array $data The data.
			 */
			public function __construct(private array $data) {
			}

			/** @return array */
			public function getObject(): array {
				return $this->data;
			}

			/** @return int */
			public function getId(): int {
				return 7;
			}
		};
	}//end compliance()

	/**
	 * Changed standard versions, in every stored shape, are saved on the module in its own register and schema.
	 *
	 * @return void
	 */
	public function testChangedStandardVersionsAreSavedOnTheModule(): void {
		$uuidObject       = new \stdClass();
		$uuidObject->uuid = 'sv-3';
		$service          = $this->service(compliance: [self::compliance('sv-1'), self::compliance(['uuid' => 'sv-2']), self::compliance($uuidObject)]);

		$service->handleModuleComplianceUpdate(moduleObject: self::module(uuid: 'mod-1', data: ['uuid' => 'mod-1', 'name' => 'Zaak', 'standardVersions' => ['old']]));

		$this->assertSame(expected: [['@self' => ['schema' => 21, 'register' => 4], 'module' => 'mod-1', '_limit' => 500]], actual: $this->queries);
		$this->assertSame(
			expected: [[['uuid' => 'mod-1', 'name' => 'Zaak', 'standardVersions' => ['sv-1', 'sv-2', 'sv-3']], '4', '6', 'mod-1']],
			actual: $this->saved
		);
	}//end testChangedStandardVersionsAreSavedOnTheModule()

	/**
	 * The same set in another order, with duplicates and unusable entries, is not saved again.
	 *
	 * @return void
	 */
	public function testAnUnchangedSetIsNotSaved(): void {
		$service = $this->service(compliance: [self::compliance('sv-2'), self::compliance('sv-1'), self::compliance('sv-2'), self::compliance(null), self::compliance(5)]);

		$service->handleModuleComplianceUpdate(moduleObject: self::module(uuid: 'mod-1', data: ['standardVersions' => ['sv-1', 'sv-2']]));

		$this->assertSame(expected: [], actual: $this->saved);
		$this->assertContains(needle: ['warning', 'ModuleComplianceService: Invalid standaardversie type'], haystack: $this->records);
	}//end testAnUnchangedSetIsNotSaved()

	/**
	 * A module without a uuid is skipped with a warning and nothing is queried.
	 *
	 * @return void
	 */
	public function testAModuleWithoutUuidIsSkipped(): void {
		$this->service()->handleModuleComplianceUpdate(moduleObject: self::module(uuid: null, data: []));

		$this->assertSame(expected: [], actual: $this->queries);
		$this->assertContains(needle: ['warning', 'ModuleComplianceService: Module object has no UUID'], haystack: $this->records);
	}//end testAModuleWithoutUuidIsSkipped()

	/**
	 * Without a compliance schema or register nothing is queried, and stored standards are cleared.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredSourceClearsTheStandards(): void {
		$this->service(schemaId: null)->handleModuleComplianceUpdate(moduleObject: self::module(uuid: 'mod-1', data: ['standardVersions' => 'not a list']));
		$this->assertSame(expected: [], actual: $this->saved);

		$this->service(config: [])->handleModuleComplianceUpdate(moduleObject: self::module(uuid: 'mod-1', data: ['standardVersions' => ['sv-1']]));

		$this->assertSame(expected: [], actual: $this->queries);
		$this->assertSame(expected: [[['standardVersions' => []], '4', '6', 'mod-1']], actual: $this->saved);
		$this->assertContains(needle: ['warning', 'ModuleComplianceService: Compliance schema not configured'], haystack: $this->records);
		$this->assertContains(needle: ['warning', 'ModuleComplianceService: Voorzieningen register not configured'], haystack: $this->records);
	}//end testAnUnconfiguredSourceClearsTheStandards()

	/**
	 * Without an object service the update fails loudly.
	 *
	 * @return void
	 */
	public function testAMissingObjectServiceFails(): void {
		$service = $this->service(noService: true);

		try {
			$service->handleModuleComplianceUpdate(moduleObject: self::module(uuid: 'mod-1', data: []));
			$this->fail('The update should fail');
		} catch (RuntimeException $e) {
			$this->assertSame(expected: 'ObjectService not available', actual: $e->getMessage());
		}

		$this->assertContains(needle: ['error', 'ModuleComplianceService: Failed to get ObjectService'], haystack: $this->records);
		$this->assertContains(needle: ['error', 'ModuleComplianceService: Failed to handle module compliance update'], haystack: $this->records);
	}//end testAMissingObjectServiceFails()

	/**
	 * A failing save is logged and rethrown.
	 *
	 * @return void
	 */
	public function testAFailingSaveIsRethrown(): void {
		$service = $this->service(compliance: [self::compliance('sv-1')], saveFailure: new RuntimeException('locked'));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('locked');
		try {
			$service->handleModuleComplianceUpdate(moduleObject: self::module(uuid: 'mod-1', data: []));
		} finally {
			$this->assertContains(needle: ['error', 'ModuleComplianceService: Failed to update module standaarden'], haystack: $this->records);
		}
	}//end testAFailingSaveIsRethrown()
}//end class
