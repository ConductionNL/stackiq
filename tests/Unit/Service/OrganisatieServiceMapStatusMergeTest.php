<?php

/**
 * Unit tests for OrganisatieService::mapStatus() — organisation-merge tombstone status.
 *
 * Covers the organisatie-service spec delta: `mapStatus('merged')`
 * MUST return `false` (a merged-away organisation is never reported as
 * active on the OR core Organisation entity), while existing
 * actief/inactief/unknown behaviour is unchanged.
 *
 * @category  Tests
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/organisatie-service/spec.md#requirement-the-system-shall-update-the-active-flag-of-an-openregister-organisation-from-a-softwarecatalog-status-req-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\Stackiq\Service\OrganisatieService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionClass;
use ReflectionMethod;

/**
 * Tests for OrganisatieService::mapStatus() and updateOrganizationStatus().
 *
 * @spec openspec/specs/organisatie-service/spec.md#requirement-the-system-shall-update-the-active-flag-of-an-openregister-organisation-from-a-softwarecatalog-status-req-002
 */
class OrganisatieServiceMapStatusMergeTest extends TestCase {
	/**
	 * Build an OrganisatieService without invoking the constructor, wiring
	 * only the properties the methods under test read.
	 *
	 * @param ContainerInterface $container The DI container.
	 * @param LoggerInterface $logger The logger.
	 *
	 * @return OrganisatieService
	 */
	private function makeService(ContainerInterface $container, LoggerInterface $logger): OrganisatieService {
		$reflection = new ReflectionClass(OrganisatieService::class);
		$service = $reflection->newInstanceWithoutConstructor();

		$containerProp = $reflection->getProperty('container');
		$containerProp->setAccessible(true);
		$containerProp->setValue($service, $container);

		$loggerProp = $reflection->getProperty('logger');
		$loggerProp->setAccessible(true);
		$loggerProp->setValue($service, $logger);

		return $service;
	}//end makeService()

	/**
	 * `mapStatus('merged')` MUST return false — a merged-away
	 * organisation is never reported as active.
	 *
	 * @return void
	 */
	public function testMapStatusMergedReturnsFalse(): void {
		$service = $this->makeService($this->createMock(ContainerInterface::class), new NullLogger());

		$method = new ReflectionMethod($service, 'mapStatus');
		$method->setAccessible(true);

		$this->assertFalse($method->invoke($service, 'merged'));
	}//end testMapStatusMergedReturnsFalse()

	/**
	 * Existing actief/inactief/unknown mapping is unchanged by the merge status addition.
	 *
	 * @return void
	 */
	public function testMapStatusExistingValuesUnchanged(): void {
		$service = $this->makeService($this->createMock(ContainerInterface::class), new NullLogger());

		$method = new ReflectionMethod($service, 'mapStatus');
		$method->setAccessible(true);

		$this->assertTrue($method->invoke($service, 'Active'));
		$this->assertFalse($method->invoke($service, ' inactief '));
		$this->assertFalse($method->invoke($service, 'deactief'));
		$this->assertTrue($method->invoke($service, 'pending'));
	}//end testMapStatusExistingValuesUnchanged()

	/**
	 * Tombstoning via merge (`updateOrganizationStatus(..., ['beoordeling' =>
	 * 'merged'])`) also deactivates the OR core Organisation entity.
	 *
	 * @return void
	 */
	public function testUpdateOrganizationStatusSamengevoegdDeactivatesOrEntity(): void {
		$entity = new Organisation();
		$entity->setUuid('uuid-1');
		$entity->setActive(true);

		$mapper = $this->createMock(OrganisationMapper::class);
		$mapper->method('findByUuid')->with('uuid-1')->willReturn($entity);
		$mapper->expects($this->once())->method('save')->willReturnCallback(
			static function (Organisation $org): Organisation {
				return $org;
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($mapper);

		$service = $this->makeService($container, new NullLogger());

		$result = $service->updateOrganizationStatus('uuid-1', ['beoordeling' => 'merged']);

		$this->assertTrue($result);
		$this->assertFalse($entity->isActive());
	}//end testUpdateOrganizationStatusSamengevoegdDeactivatesOrEntity()
}//end class
