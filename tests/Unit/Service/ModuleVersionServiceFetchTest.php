<?php

/**
 * Characterisation tests for ModuleVersionService::fetchVersionData.
 *
 * Pins the returned context, the logs and the version search (including its
 * named _rbac and _multitenancy flags) for every branch.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-9-5
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Stackiq\Service\ModuleVersionService;
use OCA\Stackiq\Service\SettingsService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;
use ReflectionMethod;
use RuntimeException;

/**
 * Test class pinning fetchVersionData.
 */
class ModuleVersionServiceFetchTest extends TestCase {
	/**
	 * @var ContainerInterface|MockObject
	 */
	private ContainerInterface|MockObject $container;

	/**
	 * @var SettingsService|MockObject
	 */
	private SettingsService|MockObject $settingsService;

	/**
	 * Logger that records every call.
	 *
	 * @var AbstractLogger
	 */
	private AbstractLogger $logger;

	/**
	 * The searches the object service received.
	 *
	 * @var array<int, array>
	 */
	private array $searches = [];

	/**
	 * Set up the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->container = $this->createMock(ContainerInterface::class);
		$this->settingsService = $this->createMock(SettingsService::class);
		$this->logger = new class extends AbstractLogger {
			/**
			 * @var array<int, array{0: mixed, 1: string, 2: array}>
			 */
			public array $records = [];

			/**
			 * @param mixed $level   The level.
			 * @param mixed $message The message.
			 * @param array $context The context.
			 *
			 * @return void
			 */
			public function log($level, $message, array $context = []): void {
				$this->records[] = [$level, (string)$message, $context];
			}
		};
	}//end setUp()

	/**
	 * An object service mock that records its searches.
	 *
	 * @param array|int $result What searchObjects returns.
	 *
	 * @return ObjectServiceInterface|MockObject
	 */
	private function objectService(array|int $result): ObjectServiceInterface|MockObject {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('searchObjects')->willReturnCallback(
			function (array $query = [], bool $_rbac = true, bool $_multitenancy = true) use ($result): array|int {
				$this->searches[] = ['query' => $query, '_rbac' => $_rbac, '_multitenancy' => $_multitenancy];
				return $result;
			}
		);
		return $objectService;
	}//end objectService()

	/**
	 * A module object.
	 *
	 * @return object
	 */
	private function module(): object {
		return new class {
			/**
			 * @return string
			 */
			public function getUuid(): string {
				return 'mod-1';
			}

			/**
			 * @return array
			 */
			public function getObject(): array {
				return ['name' => 'Module'];
			}
		};
	}//end module()

	/**
	 * Run the private method.
	 *
	 * @return array|null
	 */
	private function fetch(): ?array {
		$service = new ModuleVersionService($this->container, $this->settingsService, $this->logger);
		$reflection = new ReflectionMethod(ModuleVersionService::class, 'fetchVersionData');
		$reflection->setAccessible(true);
		return $reflection->invoke($service, $this->module());
	}//end fetch()

	/**
	 * Without the object service nothing is configured or searched.
	 *
	 * @return void
	 */
	public function testNoObjectService(): void {
		$this->container->method('get')->willThrowException(new RuntimeException('no'));
		$this->settingsService->expects($this->never())->method('getSchemaIdForObjectType');

		$this->assertNull($this->fetch());
		$this->assertSame(
			[
				['error', 'ModuleVersionService: Failed to get ObjectService', ['exception' => 'no']],
				['error', 'ModuleVersionService: ObjectService not available', []],
			],
			$this->logger->records
		);
	}//end testNoObjectService()

	/**
	 * A missing schema or register warns with both values and searches nothing.
	 *
	 * @return void
	 */
	public function testMissingConfiguration(): void {
		$this->container->method('get')->willReturn($this->objectService([]));
		$this->settingsService->method('getSchemaIdForObjectType')->willReturn(null);
		$this->settingsService->method('getVoorzieningenConfig')->willReturn(['register' => '5']);

		$this->assertNull($this->fetch());
		$this->assertSame(
			[['warning', 'ModuleVersionService: moduleVersie schema or register not configured', ['moduleVersieSchemaId' => null, 'registerId' => '5']]],
			$this->logger->records
		);
		$this->assertSame([], $this->searches);

		$this->logger->records = [];
		$this->settingsService = $this->createMock(SettingsService::class);
		$this->settingsService->method('getSchemaIdForObjectType')->willReturn(9);
		$this->settingsService->method('getVoorzieningenConfig')->willReturn([]);
		$this->assertNull($this->fetch());
		$this->assertSame(['moduleVersieSchemaId' => 9, 'registerId' => null], $this->logger->records[0][2]);
	}//end testMissingConfiguration()

	/**
	 * The versions are searched system-wide and returned with their count.
	 *
	 * @return void
	 */
	public function testFoundVersions(): void {
		$objectService = $this->objectService(['v1', 'v2']);
		$this->container->method('get')->willReturn($objectService);
		$this->settingsService->method('getSchemaIdForObjectType')->willReturnCallback(fn (string $type): ?int => ['moduleVersion' => 9][$type] ?? null);
		$this->settingsService->method('getVoorzieningenConfig')->willReturn(['register' => '5']);

		$result = $this->fetch();

		$this->assertSame(
			[
				'moduleUuid' => 'mod-1',
				'moduleData' => ['name' => 'Module'],
				'registerId' => 5,
				'schemaId' => 9,
				'existingVersions' => ['v1', 'v2'],
				'versionCount' => 2,
				'objectService' => $objectService,
			],
			$result
		);
		$this->assertSame(
			[['query' => ['@self' => ['schema' => 9, 'register' => 5], 'module' => 'mod-1', '_limit' => 200], '_rbac' => false, '_multitenancy' => false]],
			$this->searches
		);
		$this->assertSame([], $this->logger->records);
	}//end testFoundVersions()

	/**
	 * A non-array search result counts as no versions.
	 *
	 * @return void
	 */
	public function testNonArrayResultIsNoVersions(): void {
		$this->container->method('get')->willReturn($this->objectService(3));
		$this->settingsService->method('getSchemaIdForObjectType')->willReturn(9);
		$this->settingsService->method('getVoorzieningenConfig')->willReturn(['register' => 5]);

		$result = $this->fetch();

		$this->assertSame([], $result['existingVersions']);
		$this->assertSame(0, $result['versionCount']);
	}//end testNonArrayResultIsNoVersions()
}//end class
