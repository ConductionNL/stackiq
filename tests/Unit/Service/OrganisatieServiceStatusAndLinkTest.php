<?php

/**
 * Characterisation tests for OrganisatieService status update, parent link,
 * active-organisation lookup and admin usernames.
 *
 * Pins return values and every log call before the methods are split into
 * named steps.
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

use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\Stackiq\Service\OrganisatieService;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;

/**
 * Tests for the OrganisatieService methods split into named steps.
 */
class OrganisatieServiceStatusAndLinkTest extends TestCase {

	/**
	 * Every log call as [level, message, context].
	 *
	 * @var array<int, array{0: string, 1: string, 2: array}>
	 */
	private array $logs = [];

	/**
	 * Build the service with only a container and a recording logger wired.
	 *
	 * @param ContainerInterface $container The container
	 *
	 * @return OrganisatieService
	 */
	private function service(ContainerInterface $container): OrganisatieService {
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'error', 'debug', 'warning'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->logs[] = [$level, $message, $context];
				}
			);
		}

		$reflection = new ReflectionClass(OrganisatieService::class);
		$service    = $reflection->newInstanceWithoutConstructor();
		foreach (['container' => $container, 'logger' => $logger] as $name => $value) {
			$property = $reflection->getProperty($name);
			$property->setAccessible(true);
			$property->setValue($service, $value);
		}

		return $service;

	}//end service()

	/**
	 * A container answering get() with the given service.
	 *
	 * @param object $service The service
	 *
	 * @return ContainerInterface
	 */
	private function containerWith(object $service): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($service);
		return $container;

	}//end containerWith()

	/**
	 * Invoke a private method.
	 *
	 * @param OrganisatieService $service The service
	 * @param string             $name    Method name
	 * @param array              $args    Arguments
	 *
	 * @return mixed
	 */
	private function invoke(OrganisatieService $service, string $name, array $args): mixed {
		$method = new ReflectionMethod($service, $name);
		$method->setAccessible(true);
		return $method->invokeArgs($service, $args);

	}//end invoke()

	/**
	 * A successful status update saves the mapped status and logs twice.
	 *
	 * @return void
	 */
	public function testStatusUpdateSucceeds(): void {
		$entity = new Organisation();
		$mapper = $this->createMock(OrganisationMapper::class);
		$mapper->expects($this->once())->method('findByUuid')->with('org-1')->willReturn($entity);
		$mapper->expects($this->once())->method('save')->with($entity)->willReturn($entity);

		$result = $this->service($this->containerWith($mapper))->updateOrganizationStatus('org-1', ['beoordeling' => 'Inactief ']);

		$this->assertTrue($result);
		$this->assertFalse($entity->isActive());
		$this->assertSame(
			[
				['info', 'OrganisatieService: Updating organization status', ['organizationUuid' => 'org-1', 'beoordeling' => 'Inactief ']],
				['info', 'OrganisatieService: Successfully updated organization status', ['organizationUuid' => 'org-1', 'active' => false]],
			],
			$this->logs
		);

	}//end testStatusUpdateSucceeds()

	/**
	 * Without a beoordeling the start log says unknown and the status defaults to active.
	 *
	 * @return void
	 */
	public function testStatusUpdateDefaults(): void {
		$entity = new Organisation();
		$entity->setActive(false);
		$mapper = $this->createMock(OrganisationMapper::class);
		$mapper->method('findByUuid')->willReturn($entity);

		$this->assertTrue($this->service($this->containerWith($mapper))->updateOrganizationStatus('org-2', []));
		$this->assertTrue($entity->isActive());
		$this->assertSame('unknown', $this->logs[0][2]['beoordeling']);
		$this->assertSame(['organizationUuid' => 'org-2', 'active' => true], $this->logs[1][2]);

	}//end testStatusUpdateDefaults()

	/**
	 * A failing lookup answers false and logs an error after the start log.
	 *
	 * @return void
	 */
	public function testStatusUpdateFailure(): void {
		$mapper = $this->createMock(OrganisationMapper::class);
		$mapper->method('findByUuid')->willThrowException(new \RuntimeException('gone'));
		$mapper->expects($this->never())->method('save');

		$this->assertFalse($this->service($this->containerWith($mapper))->updateOrganizationStatus('org-3', ['beoordeling' => 'actief']));
		$this->assertSame(
			[
				['info', 'OrganisatieService: Updating organization status', ['organizationUuid' => 'org-3', 'beoordeling' => 'actief']],
				['error', 'OrganisatieService: Failed to update organization status', ['organizationUuid' => 'org-3', 'error' => 'gone']],
			],
			$this->logs
		);

	}//end testStatusUpdateFailure()

	/**
	 * A parent link logs the child and parent; a failure logs them with the error.
	 *
	 * @return void
	 */
	public function testParentLinkLogs(): void {
		$entity = new Organisation();
		$entity->setUuid('child');
		$saved  = new Organisation();
		$mapper = $this->createMock(OrganisationMapper::class);
		$mapper->method('save')->willReturn($saved);

		$this->assertSame($saved, $this->invoke($this->service($this->containerWith($mapper)), 'linkParentOrganisation', [$entity, 'parent']));
		$this->assertSame([['info', 'OrganisatieService: Linked organisation to parent', ['uuid' => 'child', 'parent' => 'parent']]], $this->logs);

		$this->logs = [];
		$failing    = $this->createMock(OrganisationMapper::class);
		$failing->method('save')->willThrowException(new \TypeError('bad'));
		$flat = new Organisation();
		$flat->setUuid('child2');

		$this->assertSame($flat, $this->invoke($this->service($this->containerWith($failing)), 'linkParentOrganisation', [$flat, 'p2']));
		$this->assertSame(
			[['error', 'OrganisatieService: Failed to link organisation to parent, leaving it flat', ['uuid' => 'child2', 'parent' => 'p2', 'error' => 'bad']]],
			$this->logs
		);

	}//end testParentLinkLogs()

	/**
	 * The active organisation's uuid, null without one, null plus a debug log on failure.
	 *
	 * @return void
	 */
	public function testActiveOrganisationUuid(): void {
		$service = $this->service($this->createMock(ContainerInterface::class));
		$active  = new Organisation();
		$active->setUuid('active-1');
		$organisations = $this->createMock(OrganisationService::class);
		$organisations->method('getActiveOrganisation')->willReturnOnConsecutiveCalls($active, null, $this->throwException(new \RuntimeException('no session')));

		$this->assertSame('active-1', $this->invoke($service, 'getActiveOrganisationUuid', [$organisations]));
		$this->assertNull($this->invoke($service, 'getActiveOrganisationUuid', [$organisations]));
		$this->assertNull($this->invoke($service, 'getActiveOrganisationUuid', [$organisations]));
		$this->assertSame([['debug', 'OrganisatieService: Could not get active organisation', ['error' => 'no session']]], $this->logs);

	}//end testActiveOrganisationUuid()

	/**
	 * Admin usernames: the members' uids, [] without the group, [] plus an error on failure.
	 *
	 * @return void
	 */
	public function testAdminGroupUsernames(): void {
		$users = [];
		foreach (['root', 'ops'] as $uid) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$users[] = $user;
		}

		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn($users);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('get')->with('admin')->willReturnOnConsecutiveCalls($group, null);

		$service = $this->service($this->containerWith($groups));
		$this->assertSame(['root', 'ops'], $service->getAdminGroupUsernames());
		$this->assertSame([], $service->getAdminGroupUsernames());
		$this->assertSame([], $this->logs);

		$broken = $this->createMock(ContainerInterface::class);
		$broken->method('get')->willThrowException(new \RuntimeException('no container'));
		$this->assertSame([], $this->service($broken)->getAdminGroupUsernames());
		$this->assertSame([['error', 'OrganisatieService: Failed to get admin users', ['exception' => 'no container']]], $this->logs);

	}//end testAdminGroupUsernames()
}//end class
