<?php

/**
 * Tests for ShellPermissions: the menu permission list per user.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/organisations-role-mapping-and-access-review/specs/role-mapping-and-access-review/spec.md#requirement-req-rma-003-a-functional-administrator-shall-review-the-access-of-their-organisations-people
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\Stackiq\Service\ShellPermissions;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Who sees the admin entries and the Access review entry.
 */
#[CoversClass(ShellPermissions::class)]
class ShellPermissionsTest extends TestCase {

	/**
	 * The list for a user who is or is not an admin and holds these groups.
	 *
	 * @param bool         $isAdmin Whether Nextcloud calls the user an admin.
	 * @param list<string> $groups  The user's group ids.
	 *
	 * @return list<string> The permissions.
	 */
	private function permissionsOf(bool $isAdmin, array $groups): array {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('marloes');

		$groupManager = $this->createMock(originalClassName: IGroupManager::class);
		$groupManager->expects($this->once())->method('isAdmin')->with('marloes')->willReturn($isAdmin);
		$groupManager->method('getUserGroups')->with($user)->willReturn(
			array_map(
				function (string $gid): IGroup {
					$group = $this->createMock(originalClassName: IGroup::class);
					$group->method('getGID')->willReturn($gid);
					return $group;
				},
				$groups
			)
		);

		return (new ShellPermissions(groupManager: $groupManager))->forUser(user: $user);
	}//end permissionsOf()

	/**
	 * The cases: admin, each reviewer group, and a plain user.
	 *
	 * @return array<string, array{bool, list<string>, list<string>}>
	 */
	public static function users(): array {
		return [
			'a Nextcloud admin' => [true, [], ['user', 'admin', 'access.review']],
			'a functional administrator' => [false, ['functioneel-beheerder', 'staff'], ['user', 'access.review']],
			'an organisation administrator' => [false, ['organisatie-beheerder'], ['user', 'access.review']],
			'a regular user' => [false, ['staff', 'gebruik-raadpleger'], ['user']],
			'a user without groups' => [false, [], ['user']],
		];
	}//end users()

	/**
	 * Each user gets exactly their permissions, `user` always first.
	 *
	 * @param bool         $isAdmin  Whether the user is an admin.
	 * @param list<string> $groups   The user's groups.
	 * @param list<string> $expected The permissions.
	 *
	 * @return void
	 */
	#[DataProvider('users')]
	public function testEachUserGetsTheirPermissions(bool $isAdmin, array $groups, array $expected): void {
		$this->assertSame(expected: $expected, actual: $this->permissionsOf(isAdmin: $isAdmin, groups: $groups));
	}//end testEachUserGetsTheirPermissions()

	/**
	 * Nobody signed in holds nothing, and Nextcloud is not asked.
	 *
	 * @return void
	 */
	public function testNoUserHoldsNothing(): void {
		$groupManager = $this->createMock(originalClassName: IGroupManager::class);
		$groupManager->expects($this->never())->method('isAdmin');

		$this->assertSame(expected: [], actual: (new ShellPermissions(groupManager: $groupManager))->forUser(user: null));
	}//end testNoUserHoldsNothing()
}//end class
