<?php

/**
 * Unit tests for ModuleVersionService::ensureDefaultVersion.
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
use OCA\Stackiq\Service\ModuleVersionService;
use OCA\Stackiq\Service\Settings\RegisterConfigReaderInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests that a module always ends up with a default 1.0.0 version.
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Unit\Service
 *
 * @covers \OCA\Stackiq\Service\ModuleVersionService
 */
class ModuleVersionServiceEnsureDefaultTest extends TestCase {

	private ContainerInterface&MockObject $container;

	private RegisterConfigReaderInterface&MockObject $settings;

	private LoggerInterface&MockObject $logger;

	private ObjectServiceInterface&MockObject $objectService;

	private ModuleVersionService $service;

	/**
	 * Set up mocks and the service under test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->container = $this->createMock(ContainerInterface::class);
		$this->settings = $this->createMock(RegisterConfigReaderInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->objectService = $this->createMock(ObjectServiceInterface::class);

		$this->service = new ModuleVersionService($this->container, $this->settings, $this->logger);

	}//end setUp()

	/**
	 * Build a module entity mock.
	 *
	 * @param array $data The module object data
	 *
	 * @return ObjectEntity&MockObject
	 */
	private function module(array $data): ObjectEntity&MockObject {
		$module = $this->createMock(ObjectEntity::class);
		$module->method('getUuid')->willReturn('module-uuid');
		$module->method('getObject')->willReturn($data);
		return $module;

	}//end module()

	/**
	 * Wire a configured register and moduleVersion schema.
	 *
	 * @return void
	 */
	private function configured(): void {
		$this->container->method('get')
			->with('OCA\OpenRegister\Service\ObjectService')
			->willReturn($this->objectService);
		$this->settings->method('getSchemaIdForObjectType')->with('moduleVersion')->willReturn(11);
		$this->settings->method('getVoorzieningenConfig')->willReturn(['register' => '4']);

	}//end configured()

	/**
	 * A module without versions gets a 1.0.0 version from its short description.
	 *
	 * @return void
	 */
	public function testModuleWithoutVersionsGetsDefaultVersion(): void {
		$this->configured();
		$this->objectService->expects($this->once())->method('searchObjects')
			->with(
				[
					'@self' => ['schema' => 11, 'register' => 4],
					'module' => 'module-uuid',
					'_limit' => 200,
				],
				false,
				false
			)
			->willReturn([]);

		$saved = $this->createMock(ObjectEntity::class);
		$saved->method('getUuid')->willReturn('version-uuid');
		$this->objectService->expects($this->once())->method('saveObject')
			->with(
				[
					'module' => 'module-uuid',
					'version' => '1.0.0',
					'shortDescription' => 'Kort',
					'longDescription' => '',
					'status' => 'in use',
				],
				[],
				4,
				11,
				null,
				false,
				false
			)
			->willReturn($saved);

		$contexts = [];
		$this->logger->method('info')->willReturnCallback(
			function (string $message, array $context) use (&$contexts): void {
				$contexts[$message] = $context;
			}
		);

		$this->service->ensureDefaultVersion($this->module(['voorkeurnaam' => 'App', 'shortDescription' => 'Kort']));

		$this->assertSame(
			['moduleUuid' => 'module-uuid', 'moduleName' => 'App', 'versionUuid' => 'version-uuid'],
			$contexts['ModuleVersionService: Created default version 1.0.0']
		);

	}//end testModuleWithoutVersionsGetsDefaultVersion()

	/**
	 * The module name falls back to name and then a placeholder; a missing
	 * short description becomes an empty string.
	 *
	 * @return void
	 */
	public function testNameFallsBackToPlaceholder(): void {
		$this->configured();
		$this->objectService->method('searchObjects')->willReturn(42);

		$saved = $this->createMock(ObjectEntity::class);
		$saved->method('getUuid')->willReturn('v');
		$this->objectService->expects($this->once())->method('saveObject')
			->with($this->callback(fn (array $data): bool => $data['shortDescription'] === ''))
			->willReturn($saved);

		$names = [];
		$this->logger->method('info')->willReturnCallback(
			function (string $message, array $context) use (&$names): void {
				if (isset($context['moduleName']) === true) {
					$names[] = $context['moduleName'];
				}
			}
		);

		$this->service->ensureDefaultVersion($this->module([]));

		$this->assertSame(['Onbekende applicatie'], $names);

	}//end testNameFallsBackToPlaceholder()

	/**
	 * A module that already has versions is left alone.
	 *
	 * @return void
	 */
	public function testModuleWithVersionsIsSkipped(): void {
		$this->configured();
		$this->objectService->method('searchObjects')->willReturn([['version' => '2.0'], ['version' => '3.0']]);
		$this->objectService->expects($this->never())->method('saveObject');

		$contexts = [];
		$this->logger->method('info')->willReturnCallback(
			function (string $message, array $context) use (&$contexts): void {
				$contexts[$message] = $context;
			}
		);

		$this->service->ensureDefaultVersion($this->module(['name' => 'App']));

		$this->assertSame(
			['moduleUuid' => 'module-uuid', 'versionCount' => 2],
			$contexts['ModuleVersionService: Module already has versions, skipping']
		);

	}//end testModuleWithVersionsIsSkipped()

	/**
	 * A missing schema stops with a warning and no search.
	 *
	 * @return void
	 */
	public function testUnconfiguredSchemaStops(): void {
		$this->container->method('get')->willReturn($this->objectService);
		$this->settings->method('getSchemaIdForObjectType')->willReturn(null);
		$this->settings->method('getVoorzieningenConfig')->willReturn(['register' => 4]);
		$this->objectService->expects($this->never())->method('searchObjects');
		$this->logger->expects($this->once())->method('warning')
			->with(
				'ModuleVersionService: moduleVersie schema or register not configured',
				['moduleVersieSchemaId' => null, 'registerId' => 4]
			);

		$this->service->ensureDefaultVersion($this->module([]));

	}//end testUnconfiguredSchemaStops()

	/**
	 * An unavailable ObjectService is logged and nothing else happens.
	 *
	 * @return void
	 */
	public function testUnavailableObjectServiceStops(): void {
		$this->container->method('get')->willThrowException(new RuntimeException('missing'));
		$this->settings->expects($this->never())->method('getSchemaIdForObjectType');
		$messages = [];
		$this->logger->method('error')->willReturnCallback(
			function (string $message) use (&$messages): void {
				$messages[] = $message;
			}
		);

		$this->service->ensureDefaultVersion($this->module([]));

		$this->assertSame(
			['ModuleVersionService: Failed to get ObjectService', 'ModuleVersionService: ObjectService not available'],
			$messages
		);

	}//end testUnavailableObjectServiceStops()

	/**
	 * A failing save is caught and logged with the module UUID.
	 *
	 * @return void
	 */
	public function testFailingSaveIsLogged(): void {
		$this->configured();
		$this->objectService->method('searchObjects')->willReturn([]);
		$this->objectService->method('saveObject')->willThrowException(new RuntimeException('db down'));
		$this->logger->expects($this->once())->method('error')
			->with(
				'ModuleVersionService: Failed to ensure default version',
				$this->callback(fn (array $ctx): bool => $ctx['moduleUuid'] === 'module-uuid' && $ctx['exception'] === 'db down')
			);

		$this->service->ensureDefaultVersion($this->module([]));

	}//end testFailingSaveIsLogged()

}//end class
