<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction b.v. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service\Stackiq;

use OCA\Stackiq\Service\Stackiq\GroupHandler;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * THE DEFECT UNDER TEST (stackiq#1137, second half).
 *
 * `GroupHandler::updateRoleBasedGroups()` compared group names with the
 * contact person's `roles` by exact case. The roles enum is capitalised
 * (Aanbod-beheerder) while the role groups the register's authorization
 * rules name are lower case (aanbod-beheerder), so a holder of the role was
 * never added, and a member of the group was removed on the next update.
 *
 * @spec openspec/changes/organisations-role-mapping-and-access-review/specs/role-mapping-and-access-review/spec.md#requirement-req-rma-002-a-person-shall-be-in-exactly-the-role-groups-of-the-roles-they-derive
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class GroupHandlerRoleCaseTest extends TestCase {

	/**
	 * Groups the user was added to.
	 *
	 * @var string[]
	 */
	private array $addedTo = [];

	/**
	 * Groups the user was removed from.
	 *
	 * @var string[]
	 */
	private array $removedFrom = [];

	/**
	 * A capitalised role puts the user in the lower-case role group.
	 *
	 * @return void
	 */
	public function testACapitalisedRoleAddsTheLowerCaseGroup(): void {
		$handler = $this->makeHandler(configuredGroups: ['aanbod-beheerder'], alreadyIn: []);

		$handler->updateRoleBasedGroups($this->user(), ['roles' => ['Aanbod-beheerder']]);

		$this->assertSame(['aanbod-beheerder'], $this->addedTo);
		$this->assertSame([], $this->removedFrom);
	}//end testACapitalisedRoleAddsTheLowerCaseGroup()

	/**
	 * A member holding the role in another case is not removed.
	 *
	 * @return void
	 */
	public function testAMemberHoldingTheRoleIsNotRemoved(): void {
		$handler = $this->makeHandler(configuredGroups: ['aanbod-beheerder'], alreadyIn: ['aanbod-beheerder']);

		$handler->updateRoleBasedGroups($this->user(), ['roles' => ['Aanbod-beheerder']]);

		$this->assertSame([], $this->addedTo);
		$this->assertSame([], $this->removedFrom);
	}//end testAMemberHoldingTheRoleIsNotRemoved()

	/**
	 * A member without the role still leaves the group (control).
	 *
	 * @return void
	 */
	public function testAMemberWithoutTheRoleLeavesTheGroup(): void {
		$handler = $this->makeHandler(configuredGroups: ['aanbod-beheerder'], alreadyIn: ['aanbod-beheerder']);

		$handler->updateRoleBasedGroups($this->user(), ['roles' => ['Gebruik-beheerder']]);

		$this->assertSame([], $this->addedTo);
		$this->assertSame(['aanbod-beheerder'], $this->removedFrom);
	}//end testAMemberWithoutTheRoleLeavesTheGroup()

	/**
	 * Build the handler over configured groups and existing memberships.
	 *
	 * @param string[] $configuredGroups The generic user groups saved in app config.
	 * @param string[] $alreadyIn The groups the user is already in.
	 *
	 * @return GroupHandler
	 */
	private function makeHandler(array $configuredGroups, array $alreadyIn): GroupHandler {
		$this->addedTo = [];
		$this->removedFrom = [];

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($configuredGroups): string {
				if ($key === 'generic_user_groups') {
					return (string)json_encode($configuredGroups);
				}

				return $default;
			}
		);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('get')->willReturnCallback(
			function (string $gid) use ($alreadyIn): IGroup {
				$group = $this->createMock(IGroup::class);
				$group->method('inGroup')->willReturn(in_array($gid, $alreadyIn, true));
				$group->method('addUser')->willReturnCallback(
					function () use ($gid): void {
						$this->addedTo[] = $gid;
					}
				);
				$group->method('removeUser')->willReturnCallback(
					function () use ($gid): void {
						$this->removedFrom[] = $gid;
					}
				);
				return $group;
			}
		);

		return new GroupHandler(
			$groupManager,
			$this->createMock(IUserManager::class),
			$appConfig,
			$this->createMock(ContainerInterface::class),
			$this->createMock(IAppManager::class),
			$this->createMock(LoggerInterface::class)
		);
	}//end makeHandler()

	/**
	 * A user double.
	 *
	 * @return IUser
	 */
	private function user(): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('contact-1');
		return $user;
	}//end user()
}//end class
