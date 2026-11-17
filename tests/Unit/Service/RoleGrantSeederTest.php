<?php

/**
 * Unit tests for RoleGrantSeeder.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectPatch;
use OCA\Stackiq\Service\RoleGrantSeeder;
use OCA\Stackiq\Service\RoleGroupMap;
use OCA\Stackiq\Service\Settings\RegisterConfigReaderInterface;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The upgrade seeding writes every role group member's role onto their contact person.
 *
 * @spec openspec/changes/organisations-role-mapping-and-access-review/specs/role-mapping-and-access-review/spec.md#requirement-req-rma-002-a-person-shall-be-in-exactly-the-role-groups-of-the-roles-they-derive
 */
#[CoversClass(RoleGrantSeeder::class)]
#[UsesClass(RoleGroupMap::class)]
final class RoleGrantSeederTest extends TestCase {

	/**
	 * The app config values written, keyed by key.
	 *
	 * @var array<string, string>
	 */
	private array $stored = [];

	/**
	 * The role groups that exist, each with its members' ids.
	 *
	 * @var array<string, list<string>>
	 */
	private array $groups = [];

	/**
	 * The contact person per username; absent means none.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $contacts = [];

	/**
	 * Every patch written, as [id, data].
	 *
	 * @var list<array{0: string, 1: array}>
	 */
	private array $patches = [];

	/**
	 * The usernames whose patch fails.
	 *
	 * @var list<string>
	 */
	private array $failingPatches = [];

	/**
	 * Whether the register is configured.
	 *
	 * @var bool
	 */
	private bool $configured = true;

	/**
	 * The seeder under test.
	 *
	 * @return RoleGrantSeeder
	 */
	private function seeder(): RoleGrantSeeder {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => $this->stored[$key] ?? $default
		);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->stored[$key] = $value;
				return true;
			}
		);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('get')->willReturnCallback(
			function (string $gid): ?IGroup {
				if (isset($this->groups[$gid]) === false) {
					return null;
				}

				$group = $this->createMock(IGroup::class);
				$group->method('getUsers')->willReturn(array_map(fn (string $uid): IUser => $this->user(uid: $uid), $this->groups[$gid]));
				return $group;
			}
		);

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('searchObjects')->willReturnCallback(
			function (array $query): array {
				$contact = $this->contacts[$query['username']] ?? null;
				if ($contact === null) {
					return [];
				}

				$entity = $this->createMock(ObjectEntityInterface::class);
				$entity->method('getUuid')->willReturn('cp-' . $query['username']);
				$entity->method('getObject')->willReturn($contact);
				return [$entity];
			}
		);
		$objectService->method('patchObject')->willReturnCallback(
			function (ObjectPatch $patch, bool $_rbac, bool $_multitenancy): ObjectEntityInterface {
				$objectId = $patch->objectId;
				$data = $patch->data;
				$this->assertSame([1, 3, false, false], [$patch->target->register, $patch->target->schema, $_rbac, $_multitenancy]);
				if (in_array(substr($objectId, 3), $this->failingPatches, true) === true) {
					throw new RuntimeException('write refused');
				}

				$this->patches[] = [$objectId, $data];
				return $this->createMock(ObjectEntityInterface::class);
			}
		);

		$registerConfig = $this->createMock(RegisterConfigReaderInterface::class);
		$registerConfig->method('getObjectService')->willReturn($objectService);
		$registerConfig->method('getRegisterIdForObjectType')->willReturnCallback(fn (): ?int => $this->configured === true ? 1 : null);
		$registerConfig->method('getSchemaIdForObjectType')->willReturnCallback(fn (): ?int => $this->configured === true ? 3 : null);

		return new RoleGrantSeeder(
			roleGroupMap: new RoleGroupMap(appConfig: $config),
			groupManager: $groupManager,
			registerConfig: $registerConfig,
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end seeder()

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
		return $user;
	}//end user()

	/**
	 * A member put in a role group by hand gets its role on their contact person, beside the roles they had.
	 *
	 * @return void
	 */
	public function testAHandAddedMemberGetsTheRoleOnTheirContactPerson(): void {
		$this->groups = ['gebruik-beheerder' => ['ann']];
		$this->contacts = ['ann' => ['username' => 'ann', 'roles' => ['VNG-raadpleger']]];

		$result = $this->seeder()->seed();

		$this->assertSame([['cp-ann', ['roles' => ['VNG-raadpleger', 'Gebruik-beheerder']]]], $this->patches);
		$this->assertSame(['written' => 1, 'unchanged' => 0, 'withoutContactPerson' => 0, 'failed' => 0, 'seeded' => true], $result);
		$this->assertSame('1', $this->stored['role_grants_seeded']);
	}//end testAHandAddedMemberGetsTheRoleOnTheirContactPerson()

	/**
	 * A member of two role groups gets both roles in one write.
	 *
	 * @return void
	 */
	public function testAMemberOfTwoRoleGroupsIsWrittenOnce(): void {
		$this->groups = ['aanbod-beheerder' => ['ann'], 'functioneel-beheerder' => ['ann']];
		$this->contacts = ['ann' => ['username' => 'ann']];

		$this->seeder()->seed();

		$this->assertSame([['cp-ann', ['roles' => ['Aanbod-beheerder', 'Functioneel-beheerder']]]], $this->patches);
	}//end testAMemberOfTwoRoleGroupsIsWrittenOnce()

	/**
	 * A contact person that already carries the role, in another case, is not written.
	 *
	 * @return void
	 */
	public function testARoleAlreadyOnTheContactPersonIsNotWrittenAgain(): void {
		$this->groups = ['gebruik-beheerder' => ['ann']];
		$this->contacts = ['ann' => ['username' => 'ann', 'roles' => ['gebruik-BEHEERDER']]];

		$result = $this->seeder()->seed();

		$this->assertSame([], $this->patches);
		$this->assertSame(1, $result['unchanged']);
		$this->assertTrue($result['seeded']);
	}//end testARoleAlreadyOnTheContactPersonIsNotWrittenAgain()

	/**
	 * A member without a contact person is counted and left alone; the seeding still finishes.
	 *
	 * @return void
	 */
	public function testAMemberWithoutAContactPersonIsLeftAlone(): void {
		$this->groups = ['vng-raadpleger' => ['admin']];

		$result = $this->seeder()->seed();

		$this->assertSame([], $this->patches);
		$this->assertSame(1, $result['withoutContactPerson']);
		$this->assertTrue($result['seeded']);
	}//end testAMemberWithoutAContactPersonIsLeftAlone()

	/**
	 * One failed write keeps the role groups unseeded, and the other members are still written.
	 *
	 * @return void
	 */
	public function testAFailedWriteKeepsTheRoleGroupsUnseeded(): void {
		$this->groups = ['gebruik-beheerder' => ['ann', 'bob']];
		$this->contacts = ['ann' => ['username' => 'ann'], 'bob' => ['username' => 'bob']];
		$this->failingPatches = ['ann'];

		$result = $this->seeder()->seed();

		$this->assertSame([['cp-bob', ['roles' => ['Gebruik-beheerder']]]], $this->patches);
		$this->assertSame(1, $result['failed']);
		$this->assertFalse($result['seeded']);
		$this->assertArrayNotHasKey('role_grants_seeded', $this->stored);
	}//end testAFailedWriteKeepsTheRoleGroupsUnseeded()

	/**
	 * An unconfigured register stops the seeding before anything is read.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredRegisterThrows(): void {
		$this->configured = false;
		$this->groups = ['gebruik-beheerder' => ['ann']];
		$seeder = $this->seeder();

		$this->expectException(RuntimeException::class);
		$seeder->seed();
	}//end testAnUnconfiguredRegisterThrows()
}//end class
