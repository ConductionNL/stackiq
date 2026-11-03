<?php

/**
 * OrganisatieService passes OpenRegister's taken-slug refusal on.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/organisatie-service/spec.md
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\OpenRegister\Service\OrganisationService;
use OCA\Stackiq\Service\OrganisatieService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use ReflectionClass;

if (class_exists('OCA\\OpenRegister\\Exception\\OrganisationSlugConflictException') === false) {
	require_once __DIR__ . '/../../Stubs/OpenRegister/Exception/OrganisationSlugConflictException.php';
}

/**
 * A taken slug reaches the caller; any other failure still ends as null.
 *
 * @covers \OCA\Stackiq\Service\OrganisatieService
 *
 * @spec openspec/specs/organisatie-service/spec.md
 */
class OrganisatieServiceSlugConflictTest extends TestCase {

	/**
	 * The refusal is passed on, not swallowed.
	 *
	 * @return void
	 */
	public function testATakenSlugIsPassedOn(): void {
		$this->expectException(\OCA\OpenRegister\Exception\OrganisationSlugConflictException::class);

		$this->service(failure: new \OCA\OpenRegister\Exception\OrganisationSlugConflictException(slug: 'gemeente-a'))
			->createOrganisationInOpenRegister(['id' => 'org-1', 'name' => 'Gemeente A']);
	}//end testATakenSlugIsPassedOn()

	/**
	 * Another failure still ends as null.
	 *
	 * @return void
	 */
	public function testAnotherFailureEndsAsNull(): void {
		self::assertNull(
			$this->service(failure: new \RuntimeException('db down'))->createOrganisationInOpenRegister(['id' => 'org-1', 'name' => 'Gemeente A'])
		);
	}//end testAnotherFailureEndsAsNull()

	/**
	 * The service over an OpenRegister whose createOrganisation() throws.
	 *
	 * @param \Throwable $failure What createOrganisation() throws.
	 *
	 * @return OrganisatieService
	 */
	private function service(\Throwable $failure): OrganisatieService {
		$organisations = $this->createMock(OrganisationService::class);
		$organisations->method('getActiveOrganisation')->willReturn(null);
		$organisations->method('createOrganisation')->willThrowException($failure);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($organisations);
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturn(true);

		$reflection = new ReflectionClass(OrganisatieService::class);
		$service    = $reflection->newInstanceWithoutConstructor();
		foreach (['container' => $container, 'logger' => new NullLogger(), 'appManager' => $apps] as $name => $value) {
			$reflection->getProperty($name)->setValue($service, $value);
		}

		return $service;
	}//end service()
}//end class
