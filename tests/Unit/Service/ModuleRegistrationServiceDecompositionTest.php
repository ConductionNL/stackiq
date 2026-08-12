<?php

/**
 * Unit tests for the decomposed ModuleRegistrationService helpers.
 *
 * Covers method-decomposition task 8.6 (split handleModuleRegistration into
 * resolveOrganisationType / mapOrgTypeToRegisteredBy / updateModuleRegisteredBy).
 *
 * @category  Test
 * @package   OCA\SoftwareCatalog\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-8-6
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Tests\Unit\Service;

use OCA\SoftwareCatalog\Service\ModuleRegistrationService;
use OCA\SoftwareCatalog\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for the private helpers extracted from
 * ModuleRegistrationService::handleModuleRegistration.
 *
 * @category Test
 * @package  OCA\SoftwareCatalog\Tests\Unit\Service
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-8-6
 */
class ModuleRegistrationServiceDecompositionTest extends TestCase {

	/**
	 * Build a service with stub collaborators.
	 *
	 * @return ModuleRegistrationService
	 */
	private function makeService(): ModuleRegistrationService {
		return new ModuleRegistrationService(
			$this->createMock(ContainerInterface::class),
			$this->createMock(SettingsService::class),
			$this->createMock(LoggerInterface::class),
		);

	}//end makeService()

	/**
	 * mapOrgTypeToRegisteredBy returns the canonical value for each known
	 * organisation type.
	 *
	 * @return void
	 */
	public function testMapOrgTypeToRegisteredByKnownTypes(): void {
		$service = $this->makeService();
		$reflection = new \ReflectionMethod($service, 'mapOrgTypeToRegisteredBy');
		$reflection->setAccessible(true);

		$this->assertSame('Gemeente', $reflection->invoke($service, 'm1', 'Gemeente'));
		$this->assertSame('Leverancier', $reflection->invoke($service, 'm1', 'Leverancier'));
		$this->assertSame('Samenwerking', $reflection->invoke($service, 'm1', 'Samenwerking'));
		$this->assertSame('Community', $reflection->invoke($service, 'm1', 'Community'));

	}//end testMapOrgTypeToRegisteredByKnownTypes()

	/**
	 * mapOrgTypeToRegisteredBy returns null when the type is unknown.
	 *
	 * @return void
	 */
	public function testMapOrgTypeToRegisteredByUnknownReturnsNull(): void {
		$service = $this->makeService();
		$reflection = new \ReflectionMethod($service, 'mapOrgTypeToRegisteredBy');
		$reflection->setAccessible(true);

		$this->assertNull($reflection->invoke($service, 'm1', 'NietBestaand'));
		$this->assertNull($reflection->invoke($service, 'm1', ''));

	}//end testMapOrgTypeToRegisteredByUnknownReturnsNull()

	/**
	 * resolveOrganisationType returns null when the container cannot resolve
	 * an ObjectService.
	 *
	 * @return void
	 */
	public function testResolveOrganisationTypeWithoutObjectServiceReturnsNull(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new \RuntimeException('not bound'));

		$service = new ModuleRegistrationService(
			$container,
			$this->createMock(SettingsService::class),
			$this->createMock(LoggerInterface::class),
		);

		$reflection = new \ReflectionMethod($service, 'resolveOrganisationType');
		$reflection->setAccessible(true);

		$this->assertNull($reflection->invoke($service, 'mod-1', 'org-uuid-1'));

	}//end testResolveOrganisationTypeWithoutObjectServiceReturnsNull()

}//end class
