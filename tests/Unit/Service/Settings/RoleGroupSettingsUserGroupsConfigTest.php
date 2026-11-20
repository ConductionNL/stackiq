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

		$this->assertTrue(condition: $answer['success']);
		$this->assertSame(
			expected: [
				'generic' => ['software-catalog-users'],
				'organizationAdmin' => [],
				'superUser' => ['admin', 'software-catalog-admins'],
				'allGroups' => [],
			],
			actual: array_intersect_key($answer['config'], array_flip(['generic', 'organizationAdmin', 'superUser', 'allGroups']))
		);
		// The catalogue role mapping rides along (organisations-role-mapping-and-access-review).
		$this->assertSame(expected: array_keys(\OCA\Stackiq\Service\RoleGroupMap::ROLES), actual: array_keys($answer['config']['roleMapping']));
		$this->assertSame(
			expected: \OCA\Stackiq\Service\RoleGroupMap::DEFAULT_ORGANISATION_TYPE_ROLES,
			actual: $answer['config']['organisationTypeRoles']
		);
		$this->assertSame(expected: 365, actual: $answer['config']['accessReviewIntervalDays']);
		$this->assertIsInt(actual: $answer['timestamp']);
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

		$this->assertSame(expected: ['users'], actual: $config['generic']);
		$this->assertSame(expected: ['org-admins'], actual: $config['organizationAdmin']);
		$this->assertSame(expected: [], actual: $config['superUser']);
		$this->assertSame(
			expected: [
				['id' => 'users', 'displayName' => 'Users label', 'memberCount' => 3, 'isGeneric' => true],
				['id' => 'org-admins', 'displayName' => 'Org-admins label', 'memberCount' => 1, 'isGeneric' => false],
			],
			actual: $config['allGroups']
		);
	}//end testStoredListsAndAllGroupsAreAnswered()

	/**
	 * A failing config read is reported, not thrown.
	 *
	 * @return void
	 */
	public function testAFailingReadIsReported(): void {
		$config = $this->createMock(originalClassName: IAppConfig::class);
		$config->method('getValueString')->willThrowException(new RuntimeException('db down'));
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with('Failed to get user groups config', ['exception' => 'db down']);

		$settings = new RoleGroupSettings(
			config: $config,
			appManager: $this->createMock(originalClassName: IAppManager::class),
			groupManager: $this->createMock(originalClassName: IGroupManager::class),
			logger: $logger
		);

		$this->assertSame(
			expected: ['success' => false, 'message' => 'Failed to get user groups config: db down'],
			actual: $settings->getUserGroupsConfig()
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

		$this->assertTrue(condition: $answer['success']);
		$this->assertSame(expected: 'User groups configuration updated successfully', actual: $answer['message']);
		$this->assertSame(
			expected: [
				'generic_user_groups' => '["users"]',
				'organization_admin_groups' => '["org.admins"]',
				'super_user_groups' => '["root_group"]',
			],
			actual: $this->stored
		);
		$this->assertSame(expected: ['users'], actual: $answer['config']['config']['generic']);
		$this->assertSame(expected: ['root_group'], actual: $answer['config']['config']['superUser']);
	}//end testEveryGivenListIsStored()

	/**
	 * Only the lists given are touched.
	 *
	 * @return void
	 */
	public function testOnlyGivenListsAreStored(): void {
		$this->settings()->updateUserGroupsConfig(['superUser' => ['admin']]);

		$this->assertSame(expected: ['super_user_groups' => '["admin"]'], actual: $this->stored);
	}//end testOnlyGivenListsAreStored()

	/**
	 * An invalid name fails its list, keeps the valid lists stored, and
	 * reports every result.
	 *
	 * @return void
	 */
	public function testAnInvalidListFailsTheUpdate(): void {
		$answer = $this->settings()->updateUserGroupsConfig(['generic' => ['users'], 'superUser' => ['bad name!']]);

		$this->assertFalse(condition: $answer['success']);
		$this->assertSame(expected: 'Some user group updates failed', actual: $answer['message']);
		$this->assertTrue(condition: $answer['results']['generic']['success']);
		$this->assertSame(expected: 'Invalid group names provided', actual: $answer['results']['superUser']['message']);
		$this->assertSame(
			expected: ["Group name 'bad name!' contains invalid characters"],
			actual: $answer['results']['superUser']['validation']['errors']
		);
		$this->assertSame(expected: ['generic_user_groups' => '["users"]'], actual: $this->stored);
	}//end testAnInvalidListFailsTheUpdate()

	/**
	 * A failing write fails that list with its reason.
	 *
	 * @return void
	 */
	public function testAFailingWriteFailsTheUpdate(): void {
		$this->writesFail = true;

		$answer = $this->settings()->updateUserGroupsConfig(['organizationAdmin' => ['org-admins']]);

		$this->assertFalse(condition: $answer['success']);
		$this->assertFalse(condition: $answer['results']['organizationAdmin']['success']);
		$this->assertStringStartsWith(prefix: 'Failed to update ', string: $answer['results']['organizationAdmin']['message']);
		$this->assertStringEndsWith(suffix: ': read only', string: $answer['results']['organizationAdmin']['message']);
	}//end testAFailingWriteFailsTheUpdate()

	/**
	 * The settings over an in-memory app config.
	 *
	 * @param array<string, int> $groups Group ids and their member counts.
	 *
	 * @return RoleGroupSettings
	 */
	private function settings(array $groups = []): RoleGroupSettings {
		$config = $this->createMock(originalClassName: IAppConfig::class);
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

		$appManager = $this->createMock(originalClassName: IAppManager::class);
		$appManager->method('isInstalled')->willReturnCallback(fn (string $app): bool => $app === 'user_management' && $this->userManagement);

		$found = [];
		foreach ($groups as $gid => $members) {
			$group = $this->createMock(originalClassName: IGroup::class);
			$group->method('getGID')->willReturn($gid);
			$group->method('getDisplayName')->willReturn(ucfirst($gid) . ' label');
			$group->method('getUsers')->willReturn(array_fill(0, $members, 'user'));
			$found[] = $group;
		}

		$groupManager = $this->createMock(originalClassName: IGroupManager::class);
		$groupManager->method('search')->with('')->willReturn($found);

		return new RoleGroupSettings(
			config: $config,
			appManager: $appManager,
			groupManager: $groupManager,
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);
	}//end settings()
}//end class
