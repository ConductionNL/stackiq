<?php

/**
 * The roles a person derives and the role groups the sync keeps them in.
 *
 * @category  Tests
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/organisations-role-mapping-and-access-review/specs/role-mapping-and-access-review/spec.md#requirement-req-rma-002-a-person-shall-be-in-exactly-the-role-groups-of-the-roles-they-derive
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Stackiq\Service\RoleGrantSync;
use OCA\Stackiq\Service\RoleGroupMap;
use OCA\Stackiq\Service\Settings\RegisterConfigReaderInterface;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Runs the real sync over the real role map; groups, OpenRegister and app config are doubles.
 *
 * @spec openspec/changes/organisations-role-mapping-and-access-review/specs/role-mapping-and-access-review/spec.md#requirement-req-rma-002-a-person-shall-be-in-exactly-the-role-groups-of-the-roles-they-derive
 */
final class RoleGrantSyncTest extends TestCase {

	/**
	 * The organisation every contact person belongs to.
	 *
	 * @var string
	 */
	private const ORG = 'b3a1f0c2-7d4e-4f61-9a2b-0c1d2e3f4a5b';

	/**
	 * The app config values, by key.
	 *
	 * @var array<string, string>
	 */
	private array $stored = [];

	/**
	 * The groups that exist, each with its members.
	 *
	 * @var array<string, list<string>>
	 */
	private array $groups = [];

	/**
	 * Every membership change and group creation, in order.
	 *
	 * @var list<string>
	 */
	private array $changes = [];

	/**
	 * The type of the organisation in OpenRegister.
	 *
	 * @var string
	 */
	private string $organisationType = 'Municipality';

	/**
	 * Whether the register is configured.
	 *
	 * @var bool
	 */
	private bool $configured = true;

	/**
	 * OpenRegister's object service.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * The sync under test.
	 *
	 * @return RoleGrantSync
	 */
	private function sync(): RoleGrantSync {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => $this->stored[$key] ?? $default
		);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('get')->willReturnCallback(fn (string $gid): ?IGroup => $this->groupDouble(gid: $gid));
		$groupManager->method('createGroup')->willReturnCallback(
			function (string $gid): IGroup {
				$this->changes[] = 'create ' . $gid;
				$this->groups[$gid] = [];
				return $this->groupDouble(gid: $gid);
			}
		);
		$groupManager->method('getUserGroups')->willReturnCallback(
			function (IUser $user): array {
				$mine = [];
				foreach ($this->groups as $gid => $members) {
					if (in_array($user->getUID(), $members, true) === true) {
						$mine[$gid] = $this->groupDouble(gid: $gid);
					}
				}

				return $mine;
			}
		);

		$organisation = $this->createMock(ObjectEntityInterface::class);
		$organisation->method('getObject')->willReturnCallback(fn (): array => ['id' => self::ORG, 'type' => $this->organisationType]);
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->objectService->method('find')->willReturn($organisation);

		$registerConfig = $this->createMock(RegisterConfigReaderInterface::class);
		$registerConfig->method('getObjectService')->willReturn($this->objectService);
		$registerConfig->method('getRegisterIdForObjectType')->willReturnCallback(fn (): ?int => $this->configured === true ? 1 : null);
		$registerConfig->method('getSchemaIdForObjectType')->willReturnCallback(
			fn (string $type): ?int => $this->configured === true ? ['organization' => 2, 'contactPerson' => 3][$type] : null
		);

		return new RoleGrantSync(
			roleGroupMap: new RoleGroupMap(appConfig: $config),
			groupManager: $groupManager,
			registerConfig: $registerConfig,
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end sync()

	/**
	 * A group double over $this->groups, or null when the group does not exist.
	 *
	 * @param string $gid The group id.
	 *
	 * @return IGroup|null
	 */
	private function groupDouble(string $gid): ?IGroup {
		if (array_key_exists($gid, $this->groups) === false) {
			return null;
		}

		$group = $this->createMock(IGroup::class);
		$group->method('getGID')->willReturn($gid);
		$group->method('inGroup')->willReturnCallback(fn (IUser $user): bool => in_array($user->getUID(), $this->groups[$gid], true));
		$group->method('addUser')->willReturnCallback(
			function (IUser $user) use ($gid): void {
				$this->changes[] = 'add ' . $gid;
				$this->groups[$gid][] = $user->getUID();
			}
		);
		$group->method('removeUser')->willReturnCallback(
			function (IUser $user) use ($gid): void {
				$this->changes[] = 'remove ' . $gid;
				$this->groups[$gid] = array_values(array_diff($this->groups[$gid], [$user->getUID()]));
			}
		);
		return $group;
	}//end groupDouble()

	/**
	 * A user double.
	 *
	 * @return IUser
	 */
	private function user(): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('ann');
		return $user;
	}//end user()

	/**
	 * Give every role group an empty member list, as on an upgraded instance.
	 *
	 * @return void
	 */
	private function roleGroupsExist(): void {
		foreach (RoleGroupMap::ROLES as $group) {
			$this->groups[$group] ??= [];
		}
	}//end roleGroupsExist()

	/**
	 * Roles from the contact person, a mapped group and the organisation type are united, without regard to case.
	 *
	 * @return void
	 */
	public function testRolesForIsTheUnionOfTheThreeSources(): void {
		$this->stored['role_mapping'] = json_encode(['Gebruik-raadpleger' => ['Inkoop'], 'VNG-raadpleger' => ['Elders']]);
		$this->groups = ['inkoop' => ['ann'], 'Elders' => []];

		$sync = $this->sync();
		$contact = ['roles' => ['functioneel-BEHEERDER', 'Ambtenaar', 7], 'organisation' => self::ORG];

		$this->assertSame(
			['Gebruik-beheerder', 'Gebruik-raadpleger', 'Functioneel-beheerder'],
			$sync->rolesFor(user: $this->user(), contactPerson: $contact)
		);
		$this->assertSame(
			[
				'Gebruik-beheerder' => [['type' => 'organisationType', 'organisationType' => 'Municipality']],
				'Gebruik-raadpleger' => [['type' => 'group', 'group' => 'Inkoop']],
				'Functioneel-beheerder' => [['type' => 'contactPerson']],
			],
			$sync->roleSourcesFor(user: $this->user(), contactPerson: $contact)
		);
	}//end testRolesForIsTheUnionOfTheThreeSources()

	/**
	 * A role from more than one source lists every source.
	 *
	 * @return void
	 */
	public function testARoleFromTwoSourcesNamesBoth(): void {
		$this->stored['role_mapping'] = json_encode(['Gebruik-beheerder' => ['Inkoop']]);
		$this->groups = ['Inkoop' => ['ann']];

		$sources = $this->sync()->roleSourcesFor(user: $this->user(), contactPerson: ['roles' => ['Gebruik-beheerder'], 'organisation' => self::ORG]);

		$this->assertSame(
			[
				['type' => 'contactPerson'],
				['type' => 'group', 'group' => 'Inkoop'],
				['type' => 'organisationType', 'organisationType' => 'Municipality'],
			],
			$sources['Gebruik-beheerder']
		);
	}//end testARoleFromTwoSourcesNamesBoth()

	/**
	 * A new contact person without roles gets the role group of their organisation's type.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function organisationTypes(): array {
		return [
			'Supplier' => ['Supplier', 'aanbod-beheerder'],
			'Municipality' => ['Municipality', 'gebruik-beheerder'],
			'Collaboration' => ['Collaboration', 'gebruik-beheerder'],
			'Community' => ['Community', 'aanbod-beheerder'],
		];
	}//end organisationTypes()

	/**
	 * A contact person of each organisation type, with no roles, is put in that type's role group.
	 *
	 * @param string $type      The organisation type.
	 * @param string $roleGroup The role group it grants.
	 *
	 * @return void
	 */
	#[DataProvider('organisationTypes')]
	public function testANewContactGetsTheRoleGroupOfTheirOrganisationType(string $type, string $roleGroup): void {
		$this->organisationType = $type;
		$this->roleGroupsExist();

		$result = $this->sync()->sync(user: $this->user(), contactPerson: ['roles' => [], 'organisation' => self::ORG]);

		$this->assertSame(['added' => [$roleGroup], 'removed' => []], $result);
		$this->assertSame(['ann'], $this->groups[$roleGroup]);
		$this->assertSame(['add ' . $roleGroup], $this->changes);
	}//end testANewContactGetsTheRoleGroupOfTheirOrganisationType()

	/**
	 * Leaving the mapped group ends the role, and every non-role group is kept.
	 *
	 * @return void
	 */
	public function testLeavingTheMappedGroupEndsTheRoleAndKeepsOtherGroups(): void {
		$this->stored['role_mapping'] = json_encode(['Gebruik-beheerder' => ['Inkoop']]);
		$this->stored['organisation_type_roles'] = json_encode(['Municipality' => null]);
		$this->roleGroupsExist();
		$this->groups['gebruik-beheerder'] = ['ann'];
		$this->groups['Inkoop'] = ['bob'];
		$this->groups['software-catalog-users'] = ['ann'];
		$this->groups['admin'] = ['ann'];
		$this->groups['organisaties-beheerder'] = ['ann'];

		$result = $this->sync()->sync(user: $this->user(), contactPerson: ['roles' => [], 'organisation' => self::ORG]);

		$this->assertSame(['added' => [], 'removed' => ['gebruik-beheerder']], $result);
		$this->assertSame([], $this->groups['gebruik-beheerder']);
		$this->assertSame(['ann'], $this->groups['software-catalog-users']);
		$this->assertSame(['ann'], $this->groups['admin']);
		$this->assertSame(['ann'], $this->groups['organisaties-beheerder'], 'organisaties-beheerder is no catalogue role group, so the sync leaves it.');
		$this->assertSame(['remove gebruik-beheerder'], $this->changes);
	}//end testLeavingTheMappedGroupEndsTheRoleAndKeepsOtherGroups()

	/**
	 * Staying in the mapped group keeps the role and changes nothing.
	 *
	 * @return void
	 */
	public function testStayingInTheMappedGroupChangesNothing(): void {
		$this->stored['role_mapping'] = json_encode(['Gebruik-beheerder' => ['Inkoop']]);
		$this->organisationType = 'Supplier';
		$this->roleGroupsExist();
		$this->groups['gebruik-beheerder'] = ['ann'];
		$this->groups['aanbod-beheerder'] = ['ann'];
		$this->groups['Inkoop'] = ['ann'];

		$result = $this->sync()->sync(user: $this->user(), contactPerson: ['roles' => [], 'organisation' => self::ORG]);

		$this->assertSame(['added' => [], 'removed' => []], $result);
		$this->assertSame([], $this->changes);
	}//end testStayingInTheMappedGroupChangesNothing()

	/**
	 * A missing role group is created only when someone must join it.
	 *
	 * @return void
	 */
	public function testAMissingRoleGroupIsCreatedOnlyWhenNeeded(): void {
		$this->organisationType = 'Supplier';

		$result = $this->sync()->sync(user: $this->user(), contactPerson: ['roles' => ['Organisatie-beheerder'], 'organisation' => self::ORG]);

		$this->assertSame(['added' => ['aanbod-beheerder', 'organisatie-beheerder'], 'removed' => []], $result);
		$this->assertSame(
			['create aanbod-beheerder', 'add aanbod-beheerder', 'create organisatie-beheerder', 'add organisatie-beheerder'],
			$this->changes
		);
		$this->assertArrayNotHasKey('gebruik-beheerder', $this->groups);
	}//end testAMissingRoleGroupIsCreatedOnlyWhenNeeded()

	/**
	 * Without a configured register the sync refuses and changes no group.
	 *
	 * Reading "no organisation type" there would take the type's role away from everyone.
	 *
	 * @return void
	 */
	public function testAMissingRegisterChangesNothing(): void {
		$this->configured = false;
		$this->roleGroupsExist();
		$this->groups['gebruik-beheerder'] = ['ann'];

		try {
			$this->sync()->sync(user: $this->user(), contactPerson: ['roles' => [], 'organisation' => self::ORG]);
			$this->fail('The sync must refuse without a configured register.');
		} catch (RuntimeException $e) {
			$this->assertStringContainsString('not configured', $e->getMessage());
		}

		$this->assertSame(['ann'], $this->groups['gebruik-beheerder']);
		$this->assertSame([], $this->changes);
	}//end testAMissingRegisterChangesNothing()

	/**
	 * Without contact person data the sync finds the contact person by username.
	 *
	 * @return void
	 */
	public function testTheContactPersonIsFoundByUsername(): void {
		$this->roleGroupsExist();
		$sync = $this->sync();

		$contact = $this->createMock(ObjectEntityInterface::class);
		$contact->method('getObject')->willReturn(['username' => 'ann', 'roles' => ['VNG-raadpleger']]);
		$this->objectService->expects($this->once())
			->method('searchObjects')
			->with(['@self' => ['register' => 1, 'schema' => 3], 'username' => 'ann', '_limit' => 1], false, false)
			->willReturn([$contact]);

		$this->assertSame(['VNG-raadpleger'], $sync->rolesFor(user: $this->user()));
	}//end testTheContactPersonIsFoundByUsername()

	/**
	 * A withdrawn role leaves only its own group, and only when no other source still grants it.
	 *
	 * @return void
	 */
	public function testRevokeIfUnderivedTouchesOnlyThatRole(): void {
		$this->stored['role_mapping'] = json_encode(['Gebruik-raadpleger' => ['Lezers']]);
		$this->stored['organisation_type_roles'] = json_encode(['Municipality' => null]);
		$this->roleGroupsExist();
		$this->groups['functioneel-beheerder'] = ['ann'];
		$this->groups['gebruik-raadpleger'] = ['ann'];
		$this->groups['vng-raadpleger'] = ['ann'];
		$this->groups['Lezers'] = ['ann'];
		$after = ['roles' => [], 'organisation' => self::ORG];
		$sync = $this->sync();

		$this->assertTrue($sync->revokeIfUnderived(user: $this->user(), role: 'functioneel-beheerder', contactPerson: $after));
		$this->assertFalse($sync->revokeIfUnderived(user: $this->user(), role: 'Gebruik-raadpleger', contactPerson: $after));

		$this->assertSame(['remove functioneel-beheerder'], $this->changes);
		$this->assertSame(['ann'], $this->groups['vng-raadpleger'], 'Another role group is left alone, even one the person does not derive.');

		$this->expectException(RuntimeException::class);
		$sync->revokeIfUnderived(user: $this->user(), role: 'Ambtenaar', contactPerson: $after);
	}//end testRevokeIfUnderivedTouchesOnlyThatRole()
}//end class
