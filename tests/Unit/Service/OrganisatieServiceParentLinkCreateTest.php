<?php

/**
 * Characterisation test for the create-then-link path of OrganisatieService.
 *
 * Pins that an organisation created while another organisation is active is
 * linked to it as its parent, with every log line, before the creation is
 * split into named steps.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/organisation-parent-hierarchy-rbac-fix/specs/organisatie-service/spec.md
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Service\Organisation\NewOrganisation;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\Stackiq\Service\OrganisatieService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * Tests for the parent link made while creating an organisation.
 */
class OrganisatieServiceParentLinkCreateTest extends TestCase {

	/**
	 * A child created under an active organisation is linked to it.
	 *
	 * @return void
	 */
	public function testAChildIsLinkedToTheActiveOrganisation(): void {
		$logs = [];
		$active = new Organisation();
		$active->setUuid('parent-1');
		$organisations = $this->createMock(OrganisationService::class);
		$organisations->method('getActiveOrganisation')->willReturn($active);
		$organisations->method('createOrganisation')->willReturnCallback(
			static function (NewOrganisation $details): Organisation {
				$entity = new Organisation();
				$entity->setUuid($details->uuid);
				$entity->setActive(true);
				return $entity;
			}
		);
		$saved  = [];
		$mapper = $this->createMock(OrganisationMapper::class);
		$mapper->method('save')->willReturnCallback(
			static function (Organisation $org) use (&$saved): Organisation {
				$saved[] = [$org->getUuid(), $org->getParent()];
				return $org;
			}
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): object => $id === 'OCA\OpenRegister\Db\OrganisationMapper' ? $mapper : $organisations
		);
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturn(true);
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'error', 'debug', 'warning'] as $level) {
			$logger->method($level)->willReturnCallback(
				static function (string $message, array $context = []) use (&$logs, $level): void {
					$logs[] = [$level, $message, $context];
				}
			);
		}

		$reflection = new ReflectionClass(OrganisatieService::class);
		$service    = $reflection->newInstanceWithoutConstructor();
		foreach (['container' => $container, 'logger' => $logger, 'appManager' => $apps] as $name => $value) {
			$reflection->getProperty($name)->setValue($service, $value);
		}

		$result = $service->createOrganisationInOpenRegister(['id' => 'child-1', 'name' => 'Team B', 'type' => 'Team', 'status' => 'actief']);

		$this->assertSame('parent-1', $result->getParent());
		$this->assertSame([['child-1', 'parent-1']], $saved);
		$this->assertSame(
			[
				['info', 'OrganisatieService: Creating organization entity in OpenRegister', ['organizationUuid' => 'child-1', 'name' => 'Team B']],
				['info', 'OrganisatieService: Creating organisation entity', ['uuid' => 'child-1', 'name' => 'Team B', 'active' => true, 'parentOrganisation' => 'parent-1']],
				['info', 'OrganisatieService: Linked organisation to parent', ['uuid' => 'child-1', 'parent' => 'parent-1']],
				['info', 'OrganisatieService: Organisation entity created successfully', ['uuid' => 'child-1', 'entityId' => null, 'active' => true, 'parent' => 'parent-1']],
				['info', 'OrganisatieService: Successfully created organization entity', ['organizationUuid' => 'child-1', 'entityId' => null]],
			],
			$logs
		);

	}//end testAChildIsLinkedToTheActiveOrganisation()
}//end class
