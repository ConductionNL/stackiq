<?php

/**
 * Unit tests for the role group settings: the generic, organisation admin
 * and super user group lists, the group listing, and the combined user
 * groups configuration read and update.
 *
 * Which groups count as organisation admin or super user decides who may do
 * what, so the defaults, the refused updates and the partial-failure result
 * are pinned exactly. The update paths of the three lists are pinned by
 * SettingsServiceRoleGroupsGoldenTest.
 *
 * @category  Tests
 * @package   OCA\Stackiq\Tests\Unit\Service\Settings
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

namespace OCA\Stackiq\Tests\Unit\Service\Settings;

use OCA\Stackiq\Service\Settings\RoleGroupSettings;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins the role group reads, the group listing and the combined config.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 *
 * @spec openspec/specs/settings-service/spec.md
 */
class RoleGroupSettingsTest extends TestCase {

	/**
	 * The in-memory app config store.
	 *
	 * @var array<string, string>
	 */
	private array $store = [];

	/**
	 * Whether a write throws.
	 *
	 * @var boolean
	 */
	private bool $failWrites = false;

	/**
	 * Whether the user_management app counts as installed.
	 *
	 * @var boolean
	 */
	private bool $userManagement = true;

	/**
	 * The groups the group manager finds, or null to throw.
	 *
	 * @var array<int, IGroup>|null
	 */
	private ?array $groups = [];

	/**
	 * Every log call, in order, as [level, message, context].
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $logs = [];

	/**
	 * Defaults, stored lists and malformed values for the three lists.
	 *
	 * @return void
	 */
	public function testTheListsHaveTheirDefaultsAndReadBack(): void {
		$service = $this->service();
		$this->assertSame(['software-catalog-users'], $service->getGenericUserGroups());
		$this->assertSame([], $service->getOrganizationAdminGroups());
		$this->assertSame(['admin', 'software-catalog-admins'], $service->getSuperUserGroups());

		$this->store = [
			'generic_user_groups' => '["a"]',
			'organization_admin_groups' => '["b","c"]',
			'super_user_groups' => '[]',
		];
		$this->assertSame(['a'], $service->getGenericUserGroups());
		$this->assertSame(['b', 'c'], $service->getOrganizationAdminGroups());
		// A saved empty list is an empty list, not the default.
		$this->assertSame([], $service->getSuperUserGroups());

		$this->store = [
			'generic_user_groups' => 'nope',
			'organization_admin_groups' => '"b"',
			'super_user_groups' => '{',
		];
		$this->assertSame([], $service->getGenericUserGroups());
		$this->assertSame([], $service->getOrganizationAdminGroups());
		$this->assertSame([], $service->getSuperUserGroups());
	}//end testTheListsHaveTheirDefaultsAndReadBack()

	/**
	 * The setters store JSON and log the groups.
	 *
	 * @return void
	 */
	public function testTheSettersStoreAndLog(): void {
		$service = $this->service();
		$service->setGenericUserGroups(['g']);
		$service->setOrganizationAdminGroups(['o']);
		$service->setSuperUserGroups(['s']);

		$this->assertSame(
			['generic_user_groups' => '["g"]', 'organization_admin_groups' => '["o"]', 'super_user_groups' => '["s"]'],
			$this->store
		);
		$this->assertSame(
			[
				['info', 'Updated generic user groups configuration', ['groups' => ['g']]],
				['info', 'Updated organization admin groups configuration', ['groups' => ['o']]],
				['info', 'Updated super user groups configuration', ['groups' => ['s']]],
			],
			$this->logs
		);
	}//end testTheSettersStoreAndLog()

	/**
	 * Empty, non-string and badly-formed names are refused with a reason.
	 *
	 * @return void
	 */
	public function testValidationRefusesBadNames(): void {
		$this->assertSame(
			[
				'valid' => ['ok', 'a.b_c-1'],
				'invalid' => ['', 5, 'bad name', 'x/y'],
				'errors' => [
					'Group name cannot be empty',
					'Group name cannot be empty',
					"Group name 'bad name' contains invalid characters",
					"Group name 'x/y' contains invalid characters",
				],
			],
			$this->service()->validateGroups(['ok', '', 5, 'bad name', 'a.b_c-1', 'x/y'])
		);
	}//end testValidationRefusesBadNames()

	/**
	 * The listing marks the generic groups, needs user_management and logs a failure.
	 *
	 * @return void
	 */
	public function testTheGroupListing(): void {
		$this->groups = [$this->group('software-catalog-users', 'Users', 2), $this->group('other', 'Other', 0)];
		$this->assertSame(
			[
				['id' => 'software-catalog-users', 'displayName' => 'Users', 'memberCount' => 2, 'isGeneric' => true],
				['id' => 'other', 'displayName' => 'Other', 'memberCount' => 0, 'isGeneric' => false],
			],
			$this->service()->getAllGroups()
		);

		$this->userManagement = false;
		$this->assertSame([], $this->service()->getAllGroups());

		$this->userManagement = true;
		$this->groups = null;
		$this->assertSame([], $this->service()->getAllGroups());
		$this->assertSame([['error', 'Failed to get all groups: search down', []]], $this->logs);
	}//end testTheGroupListing()

	/**
	 * The combined config reads the three lists and the listing.
	 *
	 * @return void
	 */
	public function testTheCombinedConfigRead(): void {
		$result = $this->service()->getUserGroupsConfig();

		$this->assertTrue($result['success']);
		$this->assertIsInt($result['timestamp']);
		$this->assertSame(
			[
				'generic' => ['software-catalog-users'],
				'organizationAdmin' => [],
				'superUser' => ['admin', 'software-catalog-admins'],
				'allGroups' => [],
			],
			$result['config']
		);
	}//end testTheCombinedConfigRead()

	/**
	 * A combined update with one refused list stores the others and reports
	 * the failure; an all-valid update answers the new config.
	 *
	 * @return void
	 */
	public function testTheCombinedUpdate(): void {
		$service = $this->service();

		$partial = $service->updateUserGroupsConfig(['generic' => ['g'], 'superUser' => ['bad name']]);
		$this->assertFalse($partial['success']);
		$this->assertSame('Some user group updates failed', $partial['message']);
		$this->assertTrue($partial['results']['generic']['success']);
		$this->assertSame('Invalid group names provided', $partial['results']['superUser']['message']);
		$this->assertSame(['generic_user_groups' => '["g"]'], $this->store);

		$ok = $service->updateUserGroupsConfig(['organizationAdmin' => ['o'], 'ignored' => ['x']]);
		$this->assertTrue($ok['success']);
		$this->assertSame('User groups configuration updated successfully', $ok['message']);
		$this->assertSame(['o'], $ok['config']['config']['organizationAdmin']);
		$this->assertSame(['g'], $ok['config']['config']['generic']);

		$this->assertSame(
			['success' => true, 'message' => 'User groups configuration updated successfully'],
			array_intersect_key($service->updateUserGroupsConfig([]), ['success' => 0, 'message' => 0])
		);
	}//end testTheCombinedUpdate()

	/**
	 * A failing write inside the combined update is reported per list.
	 *
	 * @return void
	 */
	public function testAFailingWriteIsReportedPerList(): void {
		$service = $this->service();
		$this->failWrites = true;

		$result = $service->updateUserGroupsConfig(['generic' => ['g']]);

		$this->assertSame(
			[
				'success' => false,
				'message' => 'Some user group updates failed',
				'results' => [
					'generic' => ['success' => false, 'message' => 'Failed to update generic user groups: config down'],
				],
			],
			$result
		);
	}//end testAFailingWriteIsReportedPerList()

	/**
	 * A group double.
	 *
	 * @param string $gid   The group id.
	 * @param string $name  The display name.
	 * @param int    $users The member count.
	 *
	 * @return IGroup
	 */
	private function group(string $gid, string $name, int $users): IGroup {
		$group = $this->createMock(IGroup::class);
		$group->method('getGID')->willReturn($gid);
		$group->method('getDisplayName')->willReturn($name);
		$group->method('getUsers')->willReturn(array_fill(0, $users, null));
		return $group;
	}//end group()

	/**
	 * Build the service over the in-memory doubles.
	 *
	 * @return RoleGroupSettings
	 */
	private function service(): RoleGroupSettings {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = ''): string {
				$this->assertSame('stackiq', $app);
				return $this->store[$key] ?? $default;
			}
		);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				if ($this->failWrites === true) {
					throw new \RuntimeException('config down');
				}

				$this->assertSame('stackiq', $app);
				$this->store[$key] = $value;
				return true;
			}
		);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturnCallback(
			fn (string $app): bool => ($app === 'user_management' && $this->userManagement === true)
		);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('search')->willReturnCallback(
			function (string $search): array {
				$this->assertSame('', $search);
				if ($this->groups === null) {
					throw new \RuntimeException('search down');
				}

				return $this->groups;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->logs[] = [$level, $message, $context];
				}
			);
		}

		return new RoleGroupSettings(
			config: $config,
			appManager: $appManager,
			groupManager: $groupManager,
			logger: $logger
		);
	}//end service()
}//end class
