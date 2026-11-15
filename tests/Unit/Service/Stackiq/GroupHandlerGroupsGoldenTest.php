<?php

/**
 * Golden test for the generic and role-based groups of GroupHandler.
 *
 * Pins, per case, which groups ensureGenericUserGroupsExist creates and
 * which role-based groups updateRoleBasedGroups adds a user to or removes
 * a user from (and which are kept), with every group manager, group and
 * logger call in order.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service\Stackiq
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/sc-handlers/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
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
use Psr\Log\AbstractLogger;

/**
 * Golden cases for creating groups and syncing role-based group membership.
 *
 * @covers \OCA\Stackiq\Service\Stackiq\GroupHandler
 *
 * @spec openspec/specs/sc-handlers/spec.md
 */
class GroupHandlerGroupsGoldenTest extends TestCase {

	/**
	 * The role-based groups, in the order they are ensured.
	 */
	private const ROLE_GROUPS = [
		'aanbod-beheerder',
		'gebruik-beheerder',
		'gebruik-raadpleger',
		'functioneel-beheerder',
		'vng-raadpleger',
		'organisatie-beheerder',
		'organisaties-beheerder',
		'ambtenaar',
	];

	/**
	 * Everything the doubles saw, in order.
	 *
	 * @var array<int, mixed>
	 */
	private array $trace = [];

	/**
	 * Per-case behaviour of the doubles.
	 *
	 * @var array<string, mixed>
	 */
	private array $plan = [];

	/**
	 * A group double.
	 *
	 * @param string $gid The group id.
	 *
	 * @return IGroup
	 */
	private function group(string $gid): IGroup {
		$group = $this->createMock(originalClassName: IGroup::class);
		$group->method('inGroup')->willReturnCallback(
			function () use ($gid) {
				$this->trace[] = ['inGroup', $gid];
				return in_array($gid, $this->plan['memberOf'], true);
			}
		);
		$group->method('addUser')->willReturnCallback(
			function () use ($gid) {
				$this->trace[] = ['addUser', $gid];
			}
		);
		$group->method('removeUser')->willReturnCallback(
			function () use ($gid) {
				$this->trace[] = ['removeUser', $gid];
			}
		);

		return $group;

	}//end group()

	/**
	 * Build the handler over the plan.
	 *
	 * @param array $plan existing: groups that exist; uncreatable: groups createGroup answers null for;
	 *                    failing: groups createGroup throws for; configured: the stored generic groups JSON;
	 *                    memberOf: the groups the user is in.
	 *
	 * @return GroupHandler
	 */
	private function handler(array $plan): GroupHandler {
		$this->trace = [];
		$this->plan  = array_merge(['existing' => [], 'uncreatable' => [], 'failing' => [], 'configured' => '', 'memberOf' => []], $plan);

		$config = $this->createMock(originalClassName: IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default='') {
				$this->trace[] = ['getValueString', $app, $key];
				return $this->plan['configured'];
			}
		);

		$groups = $this->createMock(originalClassName: IGroupManager::class);
		$groups->method('get')->willReturnCallback(
			function (string $gid) {
				$this->trace[] = ['get', $gid];
				if (in_array($gid, $this->plan['existing'], true) === true) {
					return $this->group(gid: $gid);
				}

				return null;
			}
		);
		$groups->method('createGroup')->willReturnCallback(
			function (string $gid) {
				$this->trace[] = ['createGroup', $gid];
				if (in_array($gid, $this->plan['failing'], true) === true) {
					throw new \RuntimeException('backend refused');
				}

				if (in_array($gid, $this->plan['uncreatable'], true) === true) {
					return null;
				}

				$this->plan['existing'][] = $gid;
				return $this->group(gid: $gid);
			}
		);

		$trace  = &$this->trace;
		$logger = new class($trace) extends AbstractLogger {
			/**
			 * Build the logger.
			 *
			 * @param array $trace The shared trace.
			 */
			public function __construct(private array &$trace) {
			}

			/**
			 * Record a log line, without exception objects.
			 *
			 * @param mixed              $level   The level.
			 * @param string|\Stringable $message The message.
			 * @param array              $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context=[]): void {
				unset($context['exception']);
				$this->trace[] = ['log', $level, (string) $message, $context];
			}
		};

		return new GroupHandler(
			$groups,
			$this->createMock(originalClassName: IUserManager::class),
			$config,
			$this->createMock(originalClassName: ContainerInterface::class),
			$this->createMock(originalClassName: IAppManager::class),
			$logger
		);

	}//end handler()

	/**
	 * The user double.
	 *
	 * @return IUser
	 */
	private function user(): IUser {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('alice');
		return $user;

	}//end user()

	/**
	 * The calls ensuring one group that does not exist yet and is created.
	 *
	 * @param string $gid     The group.
	 * @param string $message The log message.
	 *
	 * @return array
	 */
	private static function created(string $gid, string $message): array {
		return [['get', $gid], ['createGroup', $gid], ['log', 'info', $message, ['groupName' => $gid]]];

	}//end created()

	/**
	 * Missing generic and role-based groups are created, existing and uncreatable ones are not listed.
	 *
	 * @return void
	 */
	public function testMissingGroupsAreCreated(): void {
		$handler = $this->handler(
			plan: [
				'configured'  => '["generic-a","aanbod-beheerder","generic-b"]',
				'existing'    => ['generic-b', 'gebruik-beheerder', 'ambtenaar'],
				'uncreatable' => ['vng-raadpleger', 'generic-a'],
			]
		);

		$created = $handler->ensureGenericUserGroupsExist();

		$this->assertSame(['aanbod-beheerder', 'gebruik-raadpleger', 'functioneel-beheerder', 'organisatie-beheerder', 'organisaties-beheerder'], $created);
		$this->assertSame(
			array_merge(
				[['getValueString', 'stackiq', 'generic_user_groups']],
				[['get', 'generic-a'], ['createGroup', 'generic-a']],
				self::created(gid: 'aanbod-beheerder', message: 'Created generic user group'),
				[['get', 'generic-b']],
				[['get', 'aanbod-beheerder']],
				[['get', 'gebruik-beheerder']],
				self::created(gid: 'gebruik-raadpleger', message: 'Created role-based group'),
				self::created(gid: 'functioneel-beheerder', message: 'Created role-based group'),
				[['get', 'vng-raadpleger'], ['createGroup', 'vng-raadpleger']],
				self::created(gid: 'organisatie-beheerder', message: 'Created role-based group'),
				self::created(gid: 'organisaties-beheerder', message: 'Created role-based group'),
				[['get', 'ambtenaar']]
			),
			$this->trace
		);

	}//end testMissingGroupsAreCreated()

	/**
	 * Without stored groups the default generic group is ensured; an invalid store ensures only the role-based groups.
	 *
	 * @return void
	 */
	public function testDefaultAndInvalidGenericGroups(): void {
		$handler = $this->handler(plan: ['existing' => self::ROLE_GROUPS]);
		$this->assertSame(['software-catalog-users'], $handler->ensureGenericUserGroupsExist());

		$handler = $this->handler(plan: ['existing' => self::ROLE_GROUPS, 'configured' => '"not-a-list"']);
		$this->assertSame([], $handler->ensureGenericUserGroupsExist());
		$this->assertSame(
			array_merge([['getValueString', 'stackiq', 'generic_user_groups']], array_map(fn (string $gid) => ['get', $gid], self::ROLE_GROUPS)),
			$this->trace
		);

		$handler = $this->handler(plan: ['existing' => self::ROLE_GROUPS, 'configured' => '{"x":"keyed-a","y":"keyed-b"}']);
		$this->assertSame(['keyed-a', 'keyed-b'], $handler->ensureGenericUserGroupsExist());

	}//end testDefaultAndInvalidGenericGroups()

	/**
	 * A failing group backend escapes from ensureGenericUserGroupsExist.
	 *
	 * @return void
	 */
	public function testFailingCreateEscapes(): void {
		$handler = $this->handler(plan: ['configured' => '["generic-a"]', 'failing' => ['generic-a']]);

		$this->expectException(\RuntimeException::class);
		$handler->ensureGenericUserGroupsExist();

	}//end testFailingCreateEscapes()

	/**
	 * Holding a role adds the user to its group, losing it removes the user, except from the users group.
	 *
	 * @return void
	 */
	public function testRoleGroupsFollowTheRoles(): void {
		$handler = $this->handler(
			plan: [
				'configured' => '["aanbod-beheerder"," Gebruik-Beheerder ","software-catalog-users","vng-raadpleger","ambtenaar","broken"]',
				'existing'   => ['aanbod-beheerder', ' Gebruik-Beheerder ', 'software-catalog-users', 'vng-raadpleger'],
				'memberOf'   => ['software-catalog-users', 'vng-raadpleger', 'ambtenaar'],
				'failing'    => ['broken'],
			]
		);

		$handler->updateRoleBasedGroups($this->user(), ['roles' => [' Aanbod-Beheerder', 'gebruik-beheerder', 42, 'Ambtenaar ']]);

		$this->assertSame(
			[
				['log', 'info', 'Updating role-based groups for user', ['username' => 'alice', 'userRoles' => [' Aanbod-Beheerder', 'gebruik-beheerder', 42, 'Ambtenaar ']]],
				['getValueString', 'stackiq', 'generic_user_groups'],
				['get', 'aanbod-beheerder'],
				['inGroup', 'aanbod-beheerder'],
				['addUser', 'aanbod-beheerder'],
				['log', 'info', 'Added user to role-based group', ['username' => 'alice', 'group' => 'aanbod-beheerder', 'role' => 'aanbod-beheerder']],
				['get', ' Gebruik-Beheerder '],
				['inGroup', ' Gebruik-Beheerder '],
				['addUser', ' Gebruik-Beheerder '],
				['log', 'info', 'Added user to role-based group', ['username' => 'alice', 'group' => ' Gebruik-Beheerder ', 'role' => ' Gebruik-Beheerder ']],
				['get', 'software-catalog-users'],
				['inGroup', 'software-catalog-users'],
				['get', 'vng-raadpleger'],
				['inGroup', 'vng-raadpleger'],
				['removeUser', 'vng-raadpleger'],
				['log', 'info', 'Removed user from role-based group', ['username' => 'alice', 'group' => 'vng-raadpleger']],
				['get', 'ambtenaar'],
				['createGroup', 'ambtenaar'],
				['log', 'info', 'Created new group', ['groupName' => 'ambtenaar']],
				['inGroup', 'ambtenaar'],
				['get', 'broken'],
				['createGroup', 'broken'],
				['log', 'error', 'Failed to create group: backend refused', ['groupName' => 'broken']],
			],
			$this->trace
		);

	}//end testRoleGroupsFollowTheRoles()

	/**
	 * Missing or non-list roles count as no roles.
	 *
	 * @return void
	 */
	public function testMissingRolesRemoveTheUser(): void {
		foreach ([[], ['roles' => 'aanbod-beheerder'], ['roles' => null]] as $objectData) {
			$handler = $this->handler(plan: ['configured' => '["aanbod-beheerder"]', 'existing' => ['aanbod-beheerder'], 'memberOf' => ['aanbod-beheerder']]);
			$handler->updateRoleBasedGroups($this->user(), $objectData);
			$this->assertSame(
				[
					['log', 'info', 'Updating role-based groups for user', ['username' => 'alice', 'userRoles' => []]],
					['getValueString', 'stackiq', 'generic_user_groups'],
					['get', 'aanbod-beheerder'],
					['inGroup', 'aanbod-beheerder'],
					['removeUser', 'aanbod-beheerder'],
					['log', 'info', 'Removed user from role-based group', ['username' => 'alice', 'group' => 'aanbod-beheerder']],
				],
				$this->trace,
				var_export($objectData, true)
			);
		}

	}//end testMissingRolesRemoveTheUser()
}//end class
