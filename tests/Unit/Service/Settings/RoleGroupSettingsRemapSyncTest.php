<?php

/**
 * Saving the role mapping syncs the members of every remapped group.
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

use OCA\Stackiq\Service\RoleGrantSync;
use OCA\Stackiq\Service\RoleGroupMap;
use OCA\Stackiq\Service\Settings\RoleGroupSettings;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;

/**
 * After the admin saves the mapping, the members of the changed groups are synced.
 *
 * @spec openspec/changes/organisations-role-mapping-and-access-review/specs/role-mapping-and-access-review/spec.md#requirement-req-rma-002-a-person-shall-be-in-exactly-the-role-groups-of-the-roles-they-derive
 */
#[CoversClass(RoleGroupSettings::class)]
#[UsesClass(RoleGroupMap::class)]
final class RoleGroupSettingsRemapSyncTest extends TestCase {

	/**
	 * The app config values, keyed by key.
	 *
	 * @var array<string, string>
	 */
	private array $stored = ['role_grants_seeded' => '1'];

	/**
	 * The Nextcloud groups and their members.
	 *
	 * @var array<string, list<string>>
	 */
	private array $groups = ['Inkoop' => ['ann', 'bob'], 'Lezers' => ['cee'], 'Beheer' => ['bob']];

	/**
	 * The users synced, in order.
	 *
	 * @var list<string>
	 */
	private array $synced = [];

	/**
	 * The sync double.
	 *
	 * @var RoleGrantSync&MockObject
	 */
	private RoleGrantSync&MockObject $sync;

	/**
	 * The settings under test.
	 *
	 * @return RoleGroupSettings
	 */
	private function settings(): RoleGroupSettings {
		$config = $this->createMock(originalClassName: IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => $this->stored[$key] ?? $default
		);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->stored[$key] = $value;
				return true;
			}
		);

		$groupManager = $this->createMock(originalClassName: IGroupManager::class);
		$groupManager->method('get')->willReturnCallback(
			function (string $gid): ?IGroup {
				if (isset($this->groups[$gid]) === false) {
					return null;
				}

				$group = $this->createMock(originalClassName: IGroup::class);
				$group->method('getUsers')->willReturn(
					array_map(
						function (string $uid): IUser {
							$user = $this->createMock(originalClassName: IUser::class);
							$user->method('getUID')->willReturn($uid);
							return $user;
						},
						$this->groups[$gid]
					)
				);
				return $group;
			}
		);
		$groupManager->method('search')->willReturn([]);
		$groupManager->method('groupExists')->willReturnCallback(fn (string $gid): bool => isset($this->groups[$gid]));

		$this->sync = $this->createMock(originalClassName: RoleGrantSync::class);
		$this->sync->method('syncCatalogueUser')->willReturnCallback(
			function (IUser $user): ?array {
				$this->synced[] = $user->getUID();
				if ($user->getUID() === 'cee') {
					throw new RuntimeException('no register');
				}

				return ['added' => [], 'removed' => []];
			}
		);

		return new RoleGroupSettings(
			config: $config,
			appManager: $this->createMock(originalClassName: IAppManager::class),
			groupManager: $groupManager,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			roleGroupMap: new RoleGroupMap(appConfig: $config),
			container: $this->container()
		);
	}//end settings()

	/**
	 * A container that builds the sync mock, or throws when $fails is set.
	 *
	 * @param bool $fails Whether resolving the sync throws.
	 *
	 * @return ContainerInterface The container.
	 */
	private function container(bool $fails=false): ContainerInterface {
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		if ($fails === true) {
			$container->method('get')->willThrowException(new RuntimeException('cannot build'));
			return $container;
		}

		$container->method('get')->with(RoleGrantSync::class)->willReturn($this->sync);
		return $container;
	}//end container()

	/**
	 * Mapping Gebruik-beheerder onto Inkoop syncs each Inkoop member once.
	 *
	 * @return void
	 */
	public function testANewlyMappedGroupsMembersAreSynced(): void {
		$result = $this->settings()->updateUserGroupsConfig(['roleMapping' => ['Gebruik-beheerder' => ['Inkoop']]]);

		$this->assertTrue(condition: $result['success']);
		$this->assertSame(expected: ['ann', 'bob'], actual: $this->synced);
	}//end testANewlyMappedGroupsMembersAreSynced()

	/**
	 * A group taken out of the mapping is synced too, an unchanged one is not, and a failed member does not fail the save.
	 *
	 * @return void
	 */
	public function testAnUnmappedGroupIsSyncedAndAnUnchangedOneIsNot(): void {
		$this->stored['role_mapping'] = json_encode(['Gebruik-beheerder' => ['Inkoop'], 'Gebruik-raadpleger' => ['Lezers']]);

		$result = $this->settings()->updateUserGroupsConfig(
			['roleMapping' => ['Gebruik-beheerder' => ['Inkoop'], 'Functioneel-beheerder' => ['Beheer']]]
		);

		$this->assertTrue(condition: $result['success']);
		$this->assertSame(expected: ['cee', 'bob'], actual: $this->synced);
	}//end testAnUnmappedGroupIsSyncedAndAnUnchangedOneIsNot()

	/**
	 * Before the role groups are seeded saving the mapping syncs nobody.
	 *
	 * @return void
	 */
	public function testNothingIsSyncedBeforeTheSeeding(): void {
		unset($this->stored['role_grants_seeded']);

		$this->settings()->updateUserGroupsConfig(['roleMapping' => ['Gebruik-beheerder' => ['Inkoop']]]);

		$this->assertSame(expected: [], actual: $this->synced);
	}//end testNothingIsSyncedBeforeTheSeeding()

	/**
	 * A mapping that is refused syncs nobody.
	 *
	 * @return void
	 */
	public function testARefusedMappingSyncsNobody(): void {
		$result = $this->settings()->updateUserGroupsConfig(['roleMapping' => ['Gebruik-beheerder' => ['Inkoop']], 'accessReviewIntervalDays' => 0]);

		$this->assertFalse(condition: $result['success']);
		$this->assertSame(expected: [], actual: $this->synced);
	}//end testARefusedMappingSyncsNobody()

	/**
	 * The constructor takes no RoleGrantSync: RoleGrantSync reads the register through SettingsService, which takes
	 * RoleGroupSettings, so that dependency is a constructor cycle the DI container recurses on until the stack runs out.
	 *
	 * @return void
	 */
	public function testTheConstructorDoesNotTakeTheRoleGrantSync(): void {
		$constructor = (new ReflectionClass(objectOrClass: RoleGroupSettings::class))->getConstructor();
		$this->assertNotNull(actual: $constructor);
		foreach ($constructor->getParameters() as $parameter) {
			$type = $parameter->getType();
			$this->assertFalse(
				condition: $type instanceof ReflectionNamedType && $type->getName() === RoleGrantSync::class,
				message: 'RoleGroupSettings must not take RoleGrantSync in its constructor'
			);
		}
	}//end testTheConstructorDoesNotTakeTheRoleGrantSync()

	/**
	 * When the container cannot build the sync, the save still succeeds and nobody is synced.
	 *
	 * @return void
	 */
	public function testAnUnresolvableSyncLeavesTheSaveIntact(): void {
		$settings = $this->settings();
		$settings = new RoleGroupSettings(
			config: $this->readProperty(object: $settings, name: 'config'),
			appManager: $this->createMock(originalClassName: IAppManager::class),
			groupManager: $this->readProperty(object: $settings, name: 'groupManager'),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			roleGroupMap: $this->readProperty(object: $settings, name: 'roleGroupMap'),
			container: $this->container(fails: true)
		);

		$result = $settings->updateUserGroupsConfig(['roleMapping' => ['Gebruik-beheerder' => ['Inkoop']]]);

		$this->assertTrue(condition: $result['success']);
		$this->assertSame(expected: [], actual: $this->synced);
	}//end testAnUnresolvableSyncLeavesTheSaveIntact()

	/**
	 * Reads a private property of the settings under test.
	 *
	 * @param object $object The object.
	 * @param string $name   The property name.
	 *
	 * @return mixed The value.
	 */
	private function readProperty(object $object, string $name): mixed {
		return (new ReflectionClass(objectOrClass: $object))->getProperty(name: $name)->getValue(object: $object);
	}//end readProperty()
}//end class
