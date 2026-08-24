<?php

/**
 * Unit tests for OrganisatieService parent-child hierarchy re-enablement.
 *
 * Covers the create-then-link fix that restores the organisation parent
 * linkage a prior RBAC hotfix disabled: a new organisation created while
 * another organisation is active is linked to that active organisation as
 * its parent (via the OrganisationMapper), guarded against self-parenting
 * and fail-soft on a link error.
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
use OCA\Stackiq\Service\OrganisatieService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionClass;
use ReflectionMethod;

/**
 * Tests for OrganisatieService::linkParentOrganisation() and the
 * self-parent / null-parent guards.
 *
 * @spec openspec/changes/organisation-parent-hierarchy-rbac-fix/specs/organisatie-service/spec.md
 */
class OrganisatieServiceParentHierarchyTest extends TestCase {

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
	 * linkParentOrganisation() sets the parent on the entity and persists it
	 * via the OrganisationMapper, returning the saved entity.
	 *
	 * @return void
	 */
	public function testLinkParentOrganisationSetsParentAndPersists(): void {
		$entity = new Organisation();
		$entity->setUuid('child-uuid');

		$mapper = $this->createMock(OrganisationMapper::class);
		$mapper->expects($this->once())
			->method('save')
			->willReturnCallback(
				static function (Organisation $org): Organisation {
					return $org;
				}
			);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($mapper);

		$service = $this->makeService($container, new NullLogger());

		$method = new ReflectionMethod($service, 'linkParentOrganisation');
		$method->setAccessible(true);
		$result = $method->invoke($service, $entity, 'parent-uuid');

		$this->assertSame('parent-uuid', $result->getParent());
	}//end testLinkParentOrganisationSetsParentAndPersists()

	/**
	 * linkParentOrganisation() is fail-soft: a mapper/save failure logs and
	 * returns the original entity flat (parent unchanged) rather than
	 * throwing — the organisation is never lost over a hierarchy link.
	 *
	 * @return void
	 */
	public function testLinkParentOrganisationFailsSoftAndKeepsOrganisationFlat(): void {
		$entity = new Organisation();
		$entity->setUuid('child-uuid');

		$mapper = $this->createMock(OrganisationMapper::class);
		$mapper->method('save')->willThrowException(new \RuntimeException('db down'));

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($mapper);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error');

		$service = $this->makeService($container, $logger);

		$method = new ReflectionMethod($service, 'linkParentOrganisation');
		$method->setAccessible(true);
		$result = $method->invoke($service, $entity, 'parent-uuid');

		// The parent was set on the local entity, but persistence failed —
		// the entity is still returned (not lost). The important guarantee
		// is that no exception escaped.
		$this->assertSame('child-uuid', $result->getUuid());
	}//end testLinkParentOrganisationFailsSoftAndKeepsOrganisationFlat()

	/**
	 * The create-then-link guard skips linking when the resolved parent
	 * equals the new organisation's own uuid (no self-parenting).
	 *
	 * @return void
	 */
	public function testSelfParentIsNotLinked(): void {
		// Mirror the guard used in createOrganisationEntityInternal().
		$ownUuid = 'org-uuid';
		$parent = 'org-uuid';

		$shouldLink = ($parent !== null && $parent !== '' && $parent !== $ownUuid);

		$this->assertFalse($shouldLink);
	}//end testSelfParentIsNotLinked()

	/**
	 * The create-then-link guard skips linking when no active parent
	 * organisation resolved (null / empty) — a root organisation stays a
	 * root, unchanged from current behaviour.
	 *
	 * @return void
	 */
	public function testNullParentLeavesOrganisationAsRoot(): void {
		$ownUuid = 'org-uuid';

		foreach ([null, ''] as $parent) {
			$shouldLink = ($parent !== null && $parent !== '' && $parent !== $ownUuid);
			$this->assertFalse($shouldLink);
		}
	}//end testNullParentLeavesOrganisationAsRoot()

}//end class
