<?php

/**
 * Shared doubles for the MCP tool tests.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/mcp-full-action-surface/tasks.md#6.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Mcp;

use OCA\Stackiq\Mcp\McpAdminGate;
use OCP\IConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * A signed-in (or anonymous) caller with groups, an admin flag and an active organisation.
 */
trait McpToolFixture {

	/**
	 * The group manager double of the last caller().
	 *
	 * @var IGroupManager
	 */
	private IGroupManager $groupManager;

	/**
	 * The config double of the last caller().
	 *
	 * @var IConfig
	 */
	private IConfig $config;

	/**
	 * An admin gate over a caller.
	 *
	 * @param string|null  $uid    The signed-in uid, or null.
	 * @param list<string> $groups The caller's group ids.
	 * @param string       $org    The active organisation.
	 * @param bool         $admin  Whether the caller is an administrator.
	 *
	 * @return McpAdminGate
	 */
	private function caller(?string $uid, array $groups=[], string $org='', bool $admin=false): McpAdminGate {
		/** @var TestCase $this */
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->groupManager->method('isAdmin')->willReturn($admin);
		$this->groupManager->method('isInGroup')->willReturnCallback(static fn (string $who, string $gid): bool => in_array($gid, $groups, true));
		$this->groupManager->method('getUserGroups')->willReturn(
			array_map(
				function (string $gid): IGroup {
					$group = $this->createMock(IGroup::class);
					$group->method('getGID')->willReturn($gid);
					return $group;
				},
				$groups
			)
		);
		$this->config = $this->createMock(IConfig::class);
		$this->config->method('getUserValue')->willReturnCallback(
			static fn (string $userId, string $app, string $key): string => ($app === 'core' && $key === 'organisation') ? $org : ''
		);

		return new McpAdminGate($session, $this->groupManager);
	}//end caller()
}//end trait
