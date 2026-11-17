<?php

/**
 * OrganizationSyncService: the users an organisation entity is given after a sync.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/organization-sync/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\Stackiq\Service\OrganizationSyncService;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;
use RuntimeException;

/**
 * The entity's users are the synced usernames plus every admin, saved only when they changed.
 *
 * @covers \OCA\Stackiq\Service\OrganizationSyncService
 *
 * @spec openspec/specs/organization-sync/spec.md
 */
class OrganizationSyncEntityUsersTest extends TestCase {

	/**
	 * Log lines as [level, message].
	 *
	 * @var array<int, array{0: string, 1: string}>
	 */
	private array $logs = [];

	/**
	 * The organisation mapper double.
	 *
	 * @var OrganisationMapper&MockObject
	 */
	private OrganisationMapper&MockObject $mapper;

	/**
	 * An organisation entity holding the given users.
	 *
	 * @param array<int, string>|null $users The users it holds.
	 *
	 * @return Organisation
	 */
	private function entity(?array $users): Organisation {
		$entity = new class($users) extends Organisation {
			/**
			 * The users as the entity reports them.
			 *
			 * @var array<int, string>|null
			 */
			public ?array $held;

			/**
			 * Constructor.
			 *
			 * @param array<int, string>|null $users The users it holds.
			 */
			public function __construct(?array $users) {
				$this->held = $users;
			}

			/**
			 * The users, as OpenRegister's Organisation::getUsers() returns them.
			 *
			 * @return array<int, string>|null
			 */
			public function getUsers(): ?array {
				return $this->held;
			}

			/**
			 * Replace the users.
			 *
			 * @param array<int, string> $users The users.
			 *
			 * @return void
			 */
			public function setUsers(array $users): void {
				$this->held = $users;
			}
		};
		$entity->setUuid('org-uuid-1');

		return $entity;
	}//end entity()

	/**
	 * A group manager whose admin group holds the given users, or none when null.
	 *
	 * @param array<int, string>|null $admins The admin user ids.
	 *
	 * @return IGroupManager
	 */
	private function groupManager(?array $admins): IGroupManager {
		$manager = $this->createMock(IGroupManager::class);
		if ($admins === null) {
			$manager->method('get')->with('admin')->willReturn(null);
			return $manager;
		}

		$users = [];
		foreach ($admins as $uid) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$users[] = $user;
		}

		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn($users);
		$manager->method('get')->with('admin')->willReturn($group);

		return $manager;
	}//end groupManager()

	/**
	 * The service with only the collaborators these methods read.
	 *
	 * @param ContainerInterface $container Resolves the group manager.
	 *
	 * @return OrganizationSyncService
	 */
	private function service(ContainerInterface $container): OrganizationSyncService {
		$logs = &$this->logs;
		$logger = new class($logs) extends AbstractLogger {
			/**
			 * Constructor.
			 *
			 * @param array<int, array{0: string, 1: string}> $logs Where log lines go.
			 */
			public function __construct(private array &$logs) {
			}

			/**
			 * Record one line.
			 *
			 * @param mixed              $level   The level.
			 * @param string|\Stringable $message The message.
			 * @param array<mixed>       $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->logs[] = [(string)$level, (string)$message];
			}
		};

		$this->mapper = $this->createMock(OrganisationMapper::class);

		$reflection = new \ReflectionClass(OrganizationSyncService::class);
		$service = $reflection->newInstanceWithoutConstructor();
		foreach (['logger' => $logger, 'container' => $container, 'organisationMapper' => $this->mapper] as $name => $value) {
			$reflection->getProperty($name)->setValue($service, $value);
		}

		return $service;
	}//end service()

	/**
	 * A container that hands out the group manager.
	 *
	 * @param IGroupManager $manager The group manager.
	 *
	 * @return ContainerInterface
	 */
	private function container(IGroupManager $manager): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCP\IGroupManager')->willReturn($manager);

		return $container;
	}//end container()

	/**
	 * Run the private updateOrganisationEntityUsers().
	 *
	 * @param OrganizationSyncService $service   The service.
	 * @param Organisation            $entity    The entity.
	 * @param array<int, string>      $usernames The synced usernames.
	 *
	 * @return array<string, int> The stats after the call.
	 */
	private function update(OrganizationSyncService $service, Organisation $entity, array $usernames): array {
		$stats = ['entitiesUpdated' => 0];
		$method = new \ReflectionMethod(OrganizationSyncService::class, 'updateOrganisationEntityUsers');
		$method->invokeArgs($service, [$entity, $usernames, &$stats]);

		return $stats;
	}//end update()

	/**
	 * New usernames are saved together with every admin, once, and counted.
	 *
	 * @return void
	 */
	public function testNewUsersAreSavedWithTheAdmins(): void {
		$service = $this->service(container: $this->container(manager: $this->groupManager(admins: ['admin', 'beheer'])));
		$entity = $this->entity(users: ['alice']);
		$this->mapper->expects($this->once())->method('save')->with($entity)->willReturn($entity);

		$stats = $this->update(service: $service, entity: $entity, usernames: ['alice', 'bob', 'admin']);

		$this->assertSame(['admin', 'alice', 'beheer', 'bob'], $entity->getUsers());
		$this->assertSame(['entitiesUpdated' => 1], $stats);
		$this->assertContains(['info', 'OrganizationSyncService: Successfully updated organisation entity users'], $this->logs);
	}//end testNewUsersAreSavedWithTheAdmins()

	/**
	 * The same users in another order are not saved again.
	 *
	 * @return void
	 */
	public function testUnchangedUsersAreNotSaved(): void {
		$service = $this->service(container: $this->container(manager: $this->groupManager(admins: ['admin'])));
		$entity = $this->entity(users: ['bob', 'admin', 'alice']);
		$this->mapper->expects($this->never())->method('save');

		$stats = $this->update(service: $service, entity: $entity, usernames: ['alice', 'bob']);

		$this->assertSame(['bob', 'admin', 'alice'], $entity->getUsers());
		$this->assertSame(['entitiesUpdated' => 0], $stats);
		$this->assertSame([['debug', 'OrganizationSyncService: Organisation entity users unchanged']], $this->logs);
	}//end testUnchangedUsersAreNotSaved()

	/**
	 * An entity without users and an instance without an admin group gets just the synced usernames.
	 *
	 * @return void
	 */
	public function testWithoutAnAdminGroupOnlyTheSyncedUsersAreSet(): void {
		$service = $this->service(container: $this->container(manager: $this->groupManager(admins: null)));
		$entity = $this->entity(users: null);
		$this->mapper->expects($this->once())->method('save')->willReturn($entity);

		$stats = $this->update(service: $service, entity: $entity, usernames: ['carol']);

		$this->assertSame(['carol'], $entity->getUsers());
		$this->assertSame(['entitiesUpdated' => 1], $stats);
	}//end testWithoutAnAdminGroupOnlyTheSyncedUsersAreSet()

	/**
	 * A group manager that cannot be resolved logs and still saves the synced usernames.
	 *
	 * @return void
	 */
	public function testAnUnresolvableGroupManagerIsLoggedAndTheSyncedUsersStillSaved(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('no group manager'));
		$service = $this->service(container: $container);
		$entity = $this->entity(users: []);
		$this->mapper->expects($this->once())->method('save')->willReturn($entity);

		$stats = $this->update(service: $service, entity: $entity, usernames: ['dave']);

		$this->assertSame(['dave'], $entity->getUsers());
		$this->assertSame(['entitiesUpdated' => 1], $stats);
		$this->assertSame(['error', 'OrganizationSyncService: Failed to get admin users'], $this->logs[0]);
	}//end testAnUnresolvableGroupManagerIsLoggedAndTheSyncedUsersStillSaved()

	/**
	 * A save that fails is logged and not counted.
	 *
	 * @return void
	 */
	public function testAFailedSaveIsLoggedAndNotCounted(): void {
		$service = $this->service(container: $this->container(manager: $this->groupManager(admins: [])));
		$entity = $this->entity(users: ['alice']);
		$this->mapper->method('save')->willThrowException(new RuntimeException('database down'));

		$stats = $this->update(service: $service, entity: $entity, usernames: ['erin']);

		$this->assertSame(['entitiesUpdated' => 0], $stats);
		$this->assertSame(['error', 'OrganizationSyncService: Failed to update organisation entity users'], end($this->logs));
	}//end testAFailedSaveIsLoggedAndNotCounted()
}//end class
