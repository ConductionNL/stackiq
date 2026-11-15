<?php

/**
 * Unit tests for ModuleRegistrationService::handleModuleRegistration.
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
use OCA\Stackiq\Service\ModuleRegistrationService;
use OCA\Stackiq\Service\Settings\RegisterConfigReaderInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the registeredBy derivation on module create/update.
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Unit\Service
 *
 * @covers \OCA\Stackiq\Service\ModuleRegistrationService
 */
class ModuleRegistrationServiceTest extends TestCase {

	private ContainerInterface&MockObject $container;

	private RegisterConfigReaderInterface&MockObject $settings;

	private LoggerInterface&MockObject $logger;

	private ObjectServiceInterface&MockObject $objectService;

	private ModuleRegistrationService $service;

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

		$this->service = new ModuleRegistrationService($this->container, $this->settings, $this->logger);

	}//end setUp()

	/**
	 * Build a module entity mock.
	 *
	 * @param string|null $organisation The owning organisation UUID
	 * @param array       $data         The module object data
	 *
	 * @return ObjectEntity&MockObject
	 */
	private function module(?string $organisation, array $data=[]): ObjectEntity&MockObject {
		$module = $this->createMock(ObjectEntity::class);
		$module->method('getId')->willReturn(42);
		$module->method('getUuid')->willReturn('module-uuid');
		$module->method('getOrganisation')->willReturn($organisation);
		$module->method('getObject')->willReturn($data);
		$module->method('getRegister')->willReturn('7');
		$module->method('getSchema')->willReturn('9');
		return $module;

	}//end module()

	/**
	 * Wire the container and register configuration to resolve an organisation.
	 *
	 * @param array|null $organisationData The organisation data, null for not found
	 *
	 * @return void
	 */
	private function configured(?array $organisationData): void {
		$this->container->method('get')
			->with('OCA\OpenRegister\Service\ObjectService')
			->willReturn($this->objectService);
		$this->settings->method('getSchemaIdForObjectType')->with('organization')->willReturn(3);
		$this->settings->method('getVoorzieningenConfig')->willReturn(['register' => '5']);

		$organisation = null;
		if ($organisationData !== null) {
			$organisation = $this->createMock(ObjectEntity::class);
			$organisation->method('getObject')->willReturn($organisationData);
		}

		$this->objectService->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend=[], bool $files=false, string|int|null $register=null, string|int|null $schema=null) use ($organisation) {
				$this->assertSame('org-uuid', $id);
				$this->assertSame(5, $register);
				$this->assertSame(3, $schema);
				return $organisation;
			}
		);

	}//end configured()

	/**
	 * A module without organisation is skipped before any lookup.
	 *
	 * @return void
	 */
	public function testModuleWithoutOrganisationIsSkipped(): void {
		$this->container->expects($this->never())->method('get');
		$this->logger->expects($this->once())->method('debug')
			->with('ModuleRegistrationService: Module has no organisation, skipping', ['moduleId' => 42]);

		$this->service->handleModuleRegistration($this->module(''));

	}//end testModuleWithoutOrganisationIsSkipped()

	/**
	 * The organisation's type is written to registeredBy, keeping the other fields.
	 *
	 * @return void
	 */
	public function testOrganisationTypeIsWrittenAsRegisteredBy(): void {
		$this->configured(['type' => 'Supplier']);

		$this->objectService->expects($this->once())->method('saveObject')
			->with(
				['name' => 'Mod', 'registeredBy' => 'Supplier'],
				[],
				'7',
				'9',
				'module-uuid',
				false,
				false
			)
			->willReturn($this->createMock(ObjectEntity::class));

		$this->service->handleModuleRegistration($this->module('org-uuid', ['name' => 'Mod']));

	}//end testOrganisationTypeIsWrittenAsRegisteredBy()

	/**
	 * An already correct registeredBy is not saved again.
	 *
	 * @return void
	 */
	public function testCorrectRegisteredByIsNotSavedAgain(): void {
		$this->configured(['type' => 'Municipality']);
		$this->objectService->expects($this->never())->method('saveObject');

		$this->service->handleModuleRegistration(
			$this->module('org-uuid', ['registeredBy' => 'Municipality'])
		);

	}//end testCorrectRegisteredByIsNotSavedAgain()

	/**
	 * An unknown organisation type is logged and not saved.
	 *
	 * @return void
	 */
	public function testUnknownOrganisationTypeIsNotSaved(): void {
		$this->configured(['type' => 'Alien']);
		$this->objectService->expects($this->never())->method('saveObject');
		$this->logger->expects($this->once())->method('warning')
			->with(
				'ModuleRegistrationService: Unknown org type, cannot map registeredBy',
				['moduleId' => 42, 'orgType' => 'Alien']
			);

		$this->service->handleModuleRegistration($this->module('org-uuid'));

	}//end testUnknownOrganisationTypeIsNotSaved()

	/**
	 * An organisation without type is skipped.
	 *
	 * @return void
	 */
	public function testOrganisationWithoutTypeIsSkipped(): void {
		$this->configured(['name' => 'Gemeente']);
		$this->objectService->expects($this->never())->method('saveObject');

		$this->service->handleModuleRegistration($this->module('org-uuid'));

	}//end testOrganisationWithoutTypeIsSkipped()

	/**
	 * A missing organisation is skipped.
	 *
	 * @return void
	 */
	public function testMissingOrganisationIsSkipped(): void {
		$this->configured(null);
		$this->objectService->expects($this->never())->method('saveObject');

		$this->service->handleModuleRegistration($this->module('org-uuid'));

	}//end testMissingOrganisationIsSkipped()

	/**
	 * A lookup that throws is treated as not found.
	 *
	 * @return void
	 */
	public function testThrowingLookupIsTreatedAsNotFound(): void {
		$this->container->method('get')->willReturn($this->objectService);
		$this->settings->method('getSchemaIdForObjectType')->willReturn(3);
		$this->settings->method('getVoorzieningenConfig')->willReturn(['register' => 5]);
		$this->objectService->method('find')->willThrowException(new RuntimeException('gone'));
		$this->objectService->expects($this->never())->method('saveObject');
		$this->logger->expects($this->never())->method('error');

		$this->service->handleModuleRegistration($this->module('org-uuid'));

	}//end testThrowingLookupIsTreatedAsNotFound()

	/**
	 * Unconfigured register or schema logs a warning and stops.
	 *
	 * @return void
	 */
	public function testUnconfiguredRegisterStops(): void {
		$this->container->method('get')->willReturn($this->objectService);
		$this->settings->method('getSchemaIdForObjectType')->willReturn(3);
		$this->settings->method('getVoorzieningenConfig')->willReturn([]);
		$this->objectService->expects($this->never())->method('find');
		$this->logger->expects($this->once())->method('warning')
			->with(
				'ModuleRegistrationService: Organisatie schema or register not configured',
				['organisatieSchemaId' => 3, 'registerId' => null]
			);

		$this->service->handleModuleRegistration($this->module('org-uuid'));

	}//end testUnconfiguredRegisterStops()

	/**
	 * An unavailable ObjectService is logged as an error and stops.
	 *
	 * @return void
	 */
	public function testUnavailableObjectServiceStops(): void {
		$this->container->method('get')->willThrowException(new RuntimeException('no app'));
		$this->settings->expects($this->never())->method('getSchemaIdForObjectType');
		$messages = [];
		$this->logger->method('error')->willReturnCallback(
			function (string $message) use (&$messages): void {
				$messages[] = $message;
			}
		);

		$this->service->handleModuleRegistration($this->module('org-uuid'));

		$this->assertSame(
			[
				'ModuleRegistrationService: Failed to get ObjectService',
				'ModuleRegistrationService: ObjectService not available',
			],
			$messages
		);

	}//end testUnavailableObjectServiceStops()

	/**
	 * A failing save is caught and logged.
	 *
	 * @return void
	 */
	public function testFailingSaveIsLogged(): void {
		$this->configured(['type' => 'Community']);
		$this->objectService->method('saveObject')->willThrowException(new RuntimeException('db down'));
		$this->logger->expects($this->once())->method('error')
			->with(
				'ModuleRegistrationService: Failed to set registeredBy',
				$this->callback(fn (array $ctx): bool => $ctx['moduleId'] === 42 && $ctx['exception'] === 'db down')
			);

		$this->service->handleModuleRegistration($this->module('org-uuid'));

	}//end testFailingSaveIsLogged()

}//end class
