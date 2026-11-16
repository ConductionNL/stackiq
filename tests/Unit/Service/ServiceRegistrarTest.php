<?php

/**
 * Unit tests for ServiceRegistrar.
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
use OCA\Stackiq\Kernel\Contracts\SettingsServiceInterface;
use OCA\Stackiq\Service\ModuleVersionService;
use OCA\Stackiq\Service\ServiceRegistrar;
use OCA\Stackiq\Service\Settings\CatalogueLocator;
use OCA\Stackiq\Service\Settings\ObjectTypeLocatorInterface;
use OCA\Stackiq\Service\Settings\RegisterConfigReaderInterface;
use OCA\Stackiq\Service\SettingsService;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;

/**
 * The registrar states the cross-app and locator aliases, the contract
 * bindings and the handler factories, and the domain factories separately.
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Unit\Service
 *
 * @covers \OCA\Stackiq\Service\ServiceRegistrar
 * @uses   \OCA\Stackiq\Service\ContractBindings
 * @uses   \OCA\Stackiq\Service\Registration\ServiceFactories
 */
class ServiceRegistrarTest extends TestCase {

	/**
	 * A context that records aliases and the ids of registered factories.
	 *
	 * @param array<string, string> $aliases  Filled with alias => target.
	 * @param array<int, string>    $services Filled with factory ids.
	 *
	 * @return IRegistrationContext
	 */
	private function recordingContext(array &$aliases, array &$services): IRegistrationContext {
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerServiceAlias')->willReturnCallback(
			function (string $alias, string $target) use (&$aliases): void {
				$aliases[$alias] = $target;
			}
		);
		$context->method('registerService')->willReturnCallback(
			function (string $name, callable $factory) use (&$services): void {
				$services[] = $name;
			}
		);
		return $context;

	}//end recordingContext()

	/**
	 * register() binds OpenRegister's published interface to its service by
	 * name, the locator and config reader, every contract, and the handlers.
	 *
	 * @return void
	 */
	public function testRegisterBindsAliasesContractsAndHandlers(): void {
		$aliases  = [];
		$services = [];

		(new ServiceRegistrar())->register($this->recordingContext($aliases, $services));

		$this->assertSame('OCA\OpenRegister\Service\ObjectService', $aliases[ObjectServiceInterface::class]);
		$this->assertSame(CatalogueLocator::class, $aliases[ObjectTypeLocatorInterface::class]);
		$this->assertSame(SettingsService::class, $aliases[RegisterConfigReaderInterface::class]);
		$this->assertSame(SettingsService::class, $aliases[SettingsServiceInterface::class]);
		$this->assertCount(62, $aliases);
		$this->assertSame(
			[
				'OCA\Stackiq\Service\Stackiq\OrganizationHandler',
				'OCA\Stackiq\Service\Stackiq\ContactPersonHandler',
				'OCA\Stackiq\Service\Stackiq\GroupHandler',
				'OCA\Stackiq\Service\Stackiq\HierarchyHandler',
			],
			$services
		);

	}//end testRegisterBindsAliasesContractsAndHandlers()

	/**
	 * registerDomainServices() registers the domain factories and no alias.
	 *
	 * @return void
	 */
	public function testRegisterDomainServicesRegistersFactoriesOnly(): void {
		$aliases  = [];
		$services = [];

		(new ServiceRegistrar())->registerDomainServices($this->recordingContext($aliases, $services));

		$this->assertSame([], $aliases);
		$this->assertContains(SettingsService::class, $services);
		$this->assertContains(ModuleVersionService::class, $services);
		$this->assertNotContains('OCA\Stackiq\Service\Stackiq\ContactPersonHandler', $services);

	}//end testRegisterDomainServicesRegistersFactoriesOnly()
}//end class
