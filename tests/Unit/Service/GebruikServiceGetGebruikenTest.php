<?php

/**
 * Unit tests for GebruikService::getGebruiken.
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

use Exception;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\Stackiq\Service\GebruikService;
use OCA\Stackiq\Service\Settings\CatalogueLocator;
use OCP\App\IAppManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the gebruik search: scoping, _extend normalisation and redaction.
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Unit\Service
 *
 * @covers \OCA\Stackiq\Service\GebruikService
 */
class GebruikServiceGetGebruikenTest extends TestCase {

	private CatalogueLocator&MockObject $catalogue;

	private IAppManager&MockObject $appManager;

	private ContainerInterface&MockObject $container;

	private ObjectServiceInterface&MockObject $objectService;

	private GebruikService $service;

	/**
	 * Set up mocks and the service under test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->catalogue = $this->createMock(CatalogueLocator::class);
		$this->appManager = $this->createMock(IAppManager::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->objectService = $this->createMock(ObjectServiceInterface::class);

		$this->service = new GebruikService(
			$this->catalogue,
			$this->appManager,
			$this->container,
			$this->createMock(LoggerInterface::class)
		);

	}//end setUp()

	/**
	 * Wire an installed OpenRegister and a complete configuration.
	 *
	 * @return void
	 */
	private function configured(): void {
		$this->appManager->method('getInstalledApps')->willReturn(['files', 'openregister']);
		$this->container->method('get')
			->with('OCA\OpenRegister\Service\ObjectService')
			->willReturn($this->objectService);
		$this->catalogue->method('getVoorzieningenConfig')->willReturn(
			['register' => 'reg-1', 'gebruik_schema' => 'gebruik-schema', 'module_schema' => 'module-schema']
		);

	}//end configured()

	/**
	 * The search is scoped to the gebruik schema, a comma-separated extend
	 * becomes a trimmed _extend array, and internal annotations are removed.
	 *
	 * @return void
	 */
	public function testSearchIsScopedAndAnnotationsAreRemoved(): void {
		$this->configured();

		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getObject')->willReturn(['id' => 'b', 'interneAnnotation' => 'secret']);

		$this->objectService->expects($this->once())->method('searchObjectsPaginated')
			->with(
				[
					'_limit' => 10,
					'@self' => ['register' => 'reg-1', 'schema' => 'gebruik-schema'],
					'_extend' => ['module', 'organisatie'],
				],
				false,
				false
			)
			->willReturn(
				[
					'results' => [['id' => 'a', 'interneAnnotation' => 'x', 'naam' => 'A'], $entity],
					'total' => 2,
				]
			);

		$result = $this->service->getGebruiken(['_limit' => 10, 'extend' => 'module, organisatie']);

		$this->assertSame(
			['results' => [['id' => 'a', 'naam' => 'A'], ['id' => 'b']], 'total' => 2],
			$result
		);

	}//end testSearchIsScopedAndAnnotationsAreRemoved()

	/**
	 * An array _extend is kept and a scalar one is wrapped.
	 *
	 * @return void
	 */
	public function testExtendNormalisation(): void {
		$this->configured();

		$seen = [];
		$this->objectService->method('searchObjectsPaginated')->willReturnCallback(
			function (array $query) use (&$seen): array {
				$seen[] = $query['_extend'];
				$this->assertArrayNotHasKey('extend', $query);
				return ['results' => []];
			}
		);

		$this->service->getGebruiken(['_extend' => ['module']]);
		$this->service->getGebruiken(['extend' => 5]);
		$this->service->getGebruiken([]);

		$this->assertSame([['module'], [5], []], $seen);

	}//end testExtendNormalisation()

	/**
	 * Without OpenRegister the search refuses.
	 *
	 * @return void
	 */
	public function testMissingOpenRegisterThrows(): void {
		$this->appManager->method('getInstalledApps')->willReturn(['files']);

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('OpenRegister app is not installed');

		$this->service->getGebruiken([]);

	}//end testMissingOpenRegisterThrows()

	/**
	 * A container failure is wrapped with its message.
	 *
	 * @return void
	 */
	public function testContainerFailureIsWrapped(): void {
		$this->appManager->method('getInstalledApps')->willReturn(['openregister']);
		$this->container->method('get')->willThrowException(new RuntimeException('boom'));

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Failed to get OpenRegister service: boom');

		$this->service->getGebruiken([]);

	}//end testContainerFailureIsWrapped()

	/**
	 * A configuration without a gebruik schema refuses.
	 *
	 * @return void
	 */
	public function testMissingGebruikSchemaThrows(): void {
		$this->appManager->method('getInstalledApps')->willReturn(['openregister']);
		$this->container->method('get')->willReturn($this->objectService);
		$this->catalogue->method('getVoorzieningenConfig')->willReturn(['register' => 'reg-1']);
		$this->objectService->expects($this->never())->method('searchObjectsPaginated');

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Voorzieningen configuration not found. Please configure the schemas in the admin panel.');

		$this->service->getGebruiken([]);

	}//end testMissingGebruikSchemaThrows()

	/**
	 * A failing configuration read also refuses with the same message.
	 *
	 * @return void
	 */
	public function testFailingConfigurationReadThrows(): void {
		$this->appManager->method('getInstalledApps')->willReturn(['openregister']);
		$this->container->method('get')->willReturn($this->objectService);
		$this->catalogue->method('getVoorzieningenConfig')->willThrowException(new Exception('db'));

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Voorzieningen configuration not found.');

		$this->service->getGebruiken([]);

	}//end testFailingConfigurationReadThrows()

}//end class
