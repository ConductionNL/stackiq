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
				$group->method('getUsers')->willReturn(array_map(fn (string $uid): IUser => $this->user(uid: $uid), $this->groups[$gid]));
				return $group;
			}
		);

		$objectService = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objectService->method('searchObjects')->willReturnCallback(
			function (array $query): array {
				$contact = $this->contacts[$query['username']] ?? null;
				if ($contact === null) {
					return [];
				}

				$entity = $this->createMock(originalClassName: ObjectEntityInterface::class);
				$entity->method('getUuid')->willReturn('cp-' . $query['username']);
				$entity->method('getObject')->willReturn($contact);
				return [$entity];
			}
		);
		$objectService->method('patchObject')->willReturnCallback(
			function (ObjectPatch $patch, bool $_rbac, bool $_multitenancy): ObjectEntityInterface {
				$objectId = $patch->objectId;
				$data = $patch->data;
				$this->assertSame(expected: [1, 3, false, false], actual: [$patch->target->register, $patch->target->schema, $_rbac, $_multitenancy]);
				if (in_array(substr($objectId, 3), $this->failingPatches, true) === true) {
					throw new RuntimeException('write refused');
				}

				$this->patches[] = [$objectId, $data];
				return $this->createMock(originalClassName: ObjectEntityInterface::class);
			}
		);

		$registerConfig = $this->createMock(originalClassName: RegisterConfigReaderInterface::class);
		$registerConfig->method('getObjectService')->willReturn($objectService);
		$registerConfig->method('getRegisterIdForObjectType')->willReturnCallback(fn (): ?int => $this->whenConfigured(id: 1));
		$registerConfig->method('getSchemaIdForObjectType')->willReturnCallback(fn (): ?int => $this->whenConfigured(id: 3));

		return new RoleGrantSeeder(
			roleGroupMap: new RoleGroupMap(appConfig: $config),
			groupManager: $groupManager,
			registerConfig: $registerConfig,
			logger: $this->createMock(originalClassName: LoggerInterface::class)
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
		$user = $this->createMock(originalClassName: IUser::class);
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

		$this->assertSame(expected: [['cp-ann', ['roles' => ['VNG-raadpleger', 'Gebruik-beheerder']]]], actual: $this->patches);
		$this->assertSame(
			expected: ['written' => 1, 'unchanged' => 0, 'withoutContactPerson' => 0, 'failed' => 0, 'seeded' => true],
			actual: $result
		);
		$this->assertSame(expected: '1', actual: $this->stored['role_grants_seeded']);
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

		$this->assertSame(expected: [['cp-ann', ['roles' => ['Aanbod-beheerder', 'Functioneel-beheerder']]]], actual: $this->patches);
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

		$this->assertSame(expected: [], actual: $this->patches);
		$this->assertSame(expected: 1, actual: $result['unchanged']);
		$this->assertTrue(condition: $result['seeded']);
	}//end testARoleAlreadyOnTheContactPersonIsNotWrittenAgain()

	/**
	 * A member without a contact person is counted and left alone; the seeding still finishes.
	 *
	 * @return void
	 */
	public function testAMemberWithoutAContactPersonIsLeftAlone(): void {
		$this->groups = ['vng-raadpleger' => ['admin']];

		$result = $this->seeder()->seed();

		$this->assertSame(expected: [], actual: $this->patches);
		$this->assertSame(expected: 1, actual: $result['withoutContactPerson']);
		$this->assertTrue(condition: $result['seeded']);
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

		$this->assertSame(expected: [['cp-bob', ['roles' => ['Gebruik-beheerder']]]], actual: $this->patches);
		$this->assertSame(expected: 1, actual: $result['failed']);
		$this->assertFalse(condition: $result['seeded']);
		$this->assertArrayNotHasKey(key: 'role_grants_seeded', array: $this->stored);
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

		$this->expectException(exception: RuntimeException::class);
		$seeder->seed();
	}//end testAnUnconfiguredRegisterThrows()

	/**
	 * The given id while the register is configured, otherwise none.
	 *
	 * @param int|null $id The register or schema id.
	 *
	 * @return int|null The id, or null when nothing is configured.
	 */
	private function whenConfigured(?int $id): ?int {
		if ($this->configured === true) {
			return $id;
		}

		return null;

	}//end whenConfigured()
}//end class
