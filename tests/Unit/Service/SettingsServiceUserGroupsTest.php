<?php

/**
 * Unit tests for SettingsService::createAndConfigureUserGroups() and getAvailableUsersForCronjobs().
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/settings-service/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\Stackiq\Service\Settings\CatalogueLocator;
use OCA\Stackiq\Service\Settings\EmailSettings;
use OCA\Stackiq\Service\Settings\RoleGroupSettings;
use OCA\Stackiq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Creating the role groups the catalogue relies on, and listing the users
 * a background job may run as.
 */
final class SettingsServiceUserGroupsTest extends TestCase {

	/**
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager $groupManager;

	/**
	 * @var RoleGroupSettings&MockObject
	 */
	private RoleGroupSettings $roleGroups;

	/**
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface $container;

	/**
	 * Create the shared doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->roleGroups = $this->createMock(RoleGroupSettings::class);
		$this->container = $this->createMock(ContainerInterface::class);

	}//end setUp()

	/**
	 * Build the service.
	 *
	 * @return SettingsService
	 */
	private function service(): SettingsService {
		return new SettingsService(
			catalogue: $this->createMock(CatalogueLocator::class),
			config: $this->createMock(IAppConfig::class),
			request: $this->createMock(IRequest::class),
			roleGroupSettings: $this->roleGroups,
			container: $this->container,
			appManager: $this->createMock(IAppManager::class),
			logger: $this->createMock(LoggerInterface::class),
			groupManager: $this->groupManager,
			l10n: $this->createMock(IL10N::class),
			emailSettings: $this->createMock(EmailSettings::class)
		);

	}//end service()

	/**
	 * A user double.
	 *
	 * @param string $uid The user id.
	 *
	 * @return IUser
	 */
	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('getDisplayName')->willReturn(ucfirst($uid));
		$user->method('getEMailAddress')->willReturn($uid . '@example.org');
		return $user;

	}//end user()

	/**
	 * A group double holding the given users.
	 *
	 * @param IUser ...$users The members.
	 *
	 * @return IGroup
	 */
	private function group(IUser ...$users): IGroup {
		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn($users);
		return $group;

	}//end group()

	/**
	 * Missing groups are created, existing ones are left alone, and the
	 * generic and super user lists are set to the catalogue defaults.
	 *
	 * @return void
	 */
	public function testMissingGroupsAreCreatedAndTheDefaultsAreSet(): void {
		$existing = ['ambtenaar', 'software-catalog-users'];
		$this->groupManager->method('groupExists')->willReturnCallback(fn (string $gid): bool => in_array($gid, $existing, true));
		$created = [];
		$this->groupManager->method('createGroup')->willReturnCallback(
			function (string $gid) use (&$created): IGroup {
				$created[] = $gid;
				return $this->createMock(IGroup::class);
			}
		);
		$this->roleGroups->expects($this->once())->method('setGenericUserGroups')->with(['software-catalog-users']);
		$this->roleGroups->expects($this->once())->method('setOrganizationAdminGroups')->with([]);
		$this->roleGroups->expects($this->once())->method('setSuperUserGroups')->with(['admin', 'software-catalog-admins']);

		$result = $this->service()->createAndConfigureUserGroups();

		$this->assertTrue($result['success']);
		$this->assertSame(10, $result['total']);
		$this->assertSame($existing, $result['existing']);
		$this->assertSame($created, $result['created']);
		$this->assertCount(8, $created);
		$this->assertContains('aanbod-beheerder', $created);
		$this->assertNotContains('ambtenaar', $created);
		$this->assertSame('Created 8 new groups, 2 already existed', $result['message']);

	}//end testMissingGroupsAreCreatedAndTheDefaultsAreSet()

	/**
	 * When every group exists nothing is created and the message says so.
	 *
	 * @return void
	 */
	public function testNothingIsCreatedWhenEveryGroupExists(): void {
		$this->groupManager->method('groupExists')->willReturn(true);
		$this->groupManager->expects($this->never())->method('createGroup');

		$result = $this->service()->createAndConfigureUserGroups();

		$this->assertSame([], $result['created']);
		$this->assertSame('All 10 required groups already exist', $result['message']);

	}//end testNothingIsCreatedWhenEveryGroupExists()

	/**
	 * A group the manager refuses to create marks the run unsuccessful.
	 *
	 * @return void
	 */
	public function testARefusedGroupMarksTheRunUnsuccessful(): void {
		$this->groupManager->method('groupExists')->willReturn(false);
		$this->groupManager->method('createGroup')->willReturnCallback(
			fn (string $gid): ?IGroup => $gid === 'vng-raadpleger' ? null : $this->createMock(IGroup::class)
		);

		$result = $this->service()->createAndConfigureUserGroups();

		$this->assertFalse($result['success']);
		$this->assertCount(9, $result['created']);
		$this->assertNotContains('vng-raadpleger', $result['created']);

	}//end testARefusedGroupMarksTheRunUnsuccessful()

	/**
	 * An exception from the group manager is reported, not thrown.
	 *
	 * @return void
	 */
	public function testAGroupManagerFailureIsReported(): void {
		$this->groupManager->method('groupExists')->willThrowException(new \Exception('ldap down'));

		$result = $this->service()->createAndConfigureUserGroups();

		$this->assertFalse($result['success']);
		$this->assertSame('Failed to create user groups: ldap down', $result['message']);
		$this->assertSame('ldap down', $result['error']);
		$this->assertSame(0, $result['total']);

	}//end testAGroupManagerFailureIsReported()

	/**
	 * Admins come first; super user group members are added once, without
	 * duplicating an admin, and a missing group is skipped.
	 *
	 * @return void
	 */
	public function testCronjobUsersAreAdminsPlusSuperUsersWithoutDuplicates(): void {
		$groups = [
			'admin' => $this->group($this->user('alice')),
			'software-catalog-admins' => $this->group($this->user('alice'), $this->user('bob')),
		];
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('get')->willReturnCallback(fn (string $gid): ?IGroup => $groups[$gid] ?? null);
		$this->container->method('get')->willReturnMap(
			[
				[IUserManager::class, $this->createMock(IUserManager::class)],
				[IGroupManager::class, $groupManager],
			]
		);
		$this->roleGroups->method('getSuperUserGroups')->willReturn(['software-catalog-admins', 'gone']);

		$result = $this->service()->getAvailableUsersForCronjobs();

		$this->assertTrue($result['success']);
		$this->assertSame(
			[
				['id' => 'alice', 'displayName' => 'Alice', 'email' => 'alice@example.org'],
				['id' => 'bob', 'displayName' => 'Bob', 'email' => 'bob@example.org'],
			],
			$result['users']
		);

	}//end testCronjobUsersAreAdminsPlusSuperUsersWithoutDuplicates()

	/**
	 * Without an admin group only super user members are listed.
	 *
	 * @return void
	 */
	public function testCronjobUsersWithoutAnAdminGroup(): void {
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('get')->willReturnCallback(
			fn (string $gid): ?IGroup => $gid === 'ops' ? $this->group($this->user('carol')) : null
		);
		$this->container->method('get')->willReturnCallback(
			fn (string $id): object => $id === IGroupManager::class ? $groupManager : $this->createMock(IUserManager::class)
		);
		$this->roleGroups->method('getSuperUserGroups')->willReturn(['ops']);

		$this->assertSame(['carol'], array_column($this->service()->getAvailableUsersForCronjobs()['users'], 'id'));

	}//end testCronjobUsersWithoutAnAdminGroup()

	/**
	 * A container failure gives an empty, unsuccessful list.
	 *
	 * @return void
	 */
	public function testCronjobUsersReportAContainerFailure(): void {
		$this->container->method('get')->willThrowException(new \Exception('no container'));

		$this->assertSame(
			['success' => false, 'message' => 'Failed to get available users: no container', 'users' => []],
			$this->service()->getAvailableUsersForCronjobs()
		);

	}//end testCronjobUsersReportAContainerFailure()
}//end class
