<?php

/**
 * Unit tests for the combined user groups config of RoleGroupSettings.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service\Settings
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service\Settings;

use OCA\Stackiq\Service\Settings\RoleGroupSettings;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Reading and updating the three role group lists in one call.
 *
 * @covers \OCA\Stackiq\Service\Settings\RoleGroupSettings
 */
class RoleGroupSettingsUserGroupsConfigTest extends TestCase {

	/**
	 * The stored app config values.
	 *
	 * @var array<string, string>
	 */
	private array $stored = [];

	/**
	 * Whether writes fail.
	 *
	 * @var boolean
	 */
	private bool $writesFail = false;

	/**
	 * Whether user_management is installed.
	 *
	 * @var boolean
	 */
	private bool $userManagement = false;

	/**
	 * An empty config answers the defaults, and no groups without user_management.
	 *
	 * @return void
	 */
	public function testTheDefaultsAreAnswered(): void {
		$answer = $this->settings()->getUserGroupsConfig();

		$this->assertTrue($answer['success']);
		$this->assertSame(
			[
				'generic' => ['software-catalog-users'],
				'organizationAdmin' => [],
				'superUser' => ['admin', 'software-catalog-admins'],
				'allGroups' => [],
			],
			$answer['config']
		);
		$this->assertIsInt($answer['timestamp']);
	}//end testTheDefaultsAreAnswered()

	/**
	 * Stored lists are answered, and every group is listed with its members
	 * and whether it is generic.
	 *
	 * @return void
	 */
	public function testStoredListsAndAllGroupsAreAnswered(): void {
		$this->stored = [
			'generic_user_groups' => '["users"]',
			'organization_admin_groups' => '["org-admins"]',
			'super_user_groups' => 'not json',
		];
		$this->userManagement = true;

		$config = $this->settings(groups: ['users' => 3, 'org-admins' => 1])->getUserGroupsConfig()['config'];

		$this->assertSame(['users'], $config['generic']);
		$this->assertSame(['org-admins'], $config['organizationAdmin']);
		$this->assertSame([], $config['superUser']);
		$this->assertSame(
			[
				['id' => 'users', 'displayName' => 'Users label', 'memberCount' => 3, 'isGeneric' => true],
				['id' => 'org-admins', 'displayName' => 'Org-admins label', 'memberCount' => 1, 'isGeneric' => false],
			],
			$config['allGroups']
		);
	}//end testStoredListsAndAllGroupsAreAnswered()

	/**
	 * A failing config read is reported, not thrown.
	 *
	 * @return void
	 */
	public function testAFailingReadIsReported(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willThrowException(new RuntimeException('db down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with('Failed to get user groups config', ['exception' => 'db down']);

		$settings = new RoleGroupSettings(
			config: $config,
			appManager: $this->createMock(IAppManager::class),
			groupManager: $this->createMock(IGroupManager::class),
			logger: $logger
		);

		$this->assertSame(
			['success' => false, 'message' => 'Failed to get user groups config: db down'],
			$settings->getUserGroupsConfig()
		);
	}//end testAFailingReadIsReported()

	/**
	 * Each given list is validated and stored; the answer carries the new config.
	 *
	 * @return void
	 */
	public function testEveryGivenListIsStored(): void {
		$answer = $this->settings()->updateUserGroupsConfig(
			['generic' => ['users'], 'organizationAdmin' => ['org.admins'], 'superUser' => ['root_group']]
		);

		$this->assertTrue($answer['success']);
		$this->assertSame('User groups configuration updated successfully', $answer['message']);
		$this->assertSame(
			[
				'generic_user_groups' => '["users"]',
				'organization_admin_groups' => '["org.admins"]',
				'super_user_groups' => '["root_group"]',
			],
			$this->stored
		);
		$this->assertSame(['users'], $answer['config']['config']['generic']);
		$this->assertSame(['root_group'], $answer['config']['config']['superUser']);
	}//end testEveryGivenListIsStored()

	/**
	 * Only the lists given are touched.
	 *
	 * @return void
	 */
	public function testOnlyGivenListsAreStored(): void {
		$this->settings()->updateUserGroupsConfig(['superUser' => ['admin']]);

		$this->assertSame(['super_user_groups' => '["admin"]'], $this->stored);
	}//end testOnlyGivenListsAreStored()

	/**
	 * An invalid name fails its list, keeps the valid lists stored, and
	 * reports every result.
	 *
	 * @return void
	 */
	public function testAnInvalidListFailsTheUpdate(): void {
		$answer = $this->settings()->updateUserGroupsConfig(['generic' => ['users'], 'superUser' => ['bad name!']]);

		$this->assertFalse($answer['success']);
		$this->assertSame('Some user group updates failed', $answer['message']);
		$this->assertTrue($answer['results']['generic']['success']);
		$this->assertSame('Invalid group names provided', $answer['results']['superUser']['message']);
		$this->assertSame(["Group name 'bad name!' contains invalid characters"], $answer['results']['superUser']['validation']['errors']);
		$this->assertSame(['generic_user_groups' => '["users"]'], $this->stored);
	}//end testAnInvalidListFailsTheUpdate()

	/**
	 * A failing write fails that list with its reason.
	 *
	 * @return void
	 */
	public function testAFailingWriteFailsTheUpdate(): void {
		$this->writesFail = true;

		$answer = $this->settings()->updateUserGroupsConfig(['organizationAdmin' => ['org-admins']]);

		$this->assertFalse($answer['success']);
		$this->assertFalse($answer['results']['organizationAdmin']['success']);
		$this->assertStringStartsWith('Failed to update ', $answer['results']['organizationAdmin']['message']);
		$this->assertStringEndsWith(': read only', $answer['results']['organizationAdmin']['message']);
	}//end testAFailingWriteFailsTheUpdate()

	/**
	 * The settings over an in-memory app config.
	 *
	 * @param array<string, int> $groups Group ids and their member counts.
	 *
	 * @return RoleGroupSettings
	 */
	private function settings(array $groups = []): RoleGroupSettings {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->stored[$key] ?? $default)
		);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				if ($this->writesFail === true) {
					throw new RuntimeException('read only');
				}

				$this->stored[$key] = $value;
				return true;
			}
		);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturnCallback(fn (string $app): bool => $app === 'user_management' && $this->userManagement);

		$found = [];
		foreach ($groups as $gid => $members) {
			$group = $this->createMock(IGroup::class);
			$group->method('getGID')->willReturn($gid);
			$group->method('getDisplayName')->willReturn(ucfirst($gid) . ' label');
			$group->method('getUsers')->willReturn(array_fill(0, $members, 'user'));
			$found[] = $group;
		}

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('search')->with('')->willReturn($found);

		return new RoleGroupSettings(
			config: $config,
			appManager: $appManager,
			groupManager: $groupManager,
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end settings()
}//end class
