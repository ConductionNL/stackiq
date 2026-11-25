<?php

/**
 * Unit tests for the permissions DashboardController hands the shell.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/sync-status-and-progress/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Controller;

use OCA\Stackiq\Controller\DashboardController;
use OCA\Stackiq\Service\ShellPermissions;
use OCP\AppFramework\Services\IInitialState;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * The shell's menu hides an entry whose permission the user lacks, so the
 * list must name exactly what the user holds.
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Unit\Controller
 *
 * @covers \OCA\Stackiq\Controller\DashboardController
 * @uses   \OCA\Stackiq\Service\ShellPermissions
 * @uses   \OCA\Stackiq\Service\SyncAccessPolicy
 */
class DashboardControllerPermissionsTest extends TestCase {

	/**
	 * Render the page for a user and return the permissions it provided.
	 *
	 * @param string|null $uid     The signed-in user, or null.
	 * @param bool        $isAdmin Whether the user is a Nextcloud admin.
	 * @param bool        $inGroup Whether the user is a functional administrator.
	 *
	 * @return array<int, mixed> The provided [key, value] pairs.
	 */
	private function provided(?string $uid, bool $isAdmin, bool $inGroup): array {
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(originalClassName: IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$groups = $this->createMock(originalClassName: IGroupManager::class);
		$memberships = [];
		if ($inGroup === true) {
			$group = $this->createMock(originalClassName: IGroup::class);
			$group->method('getGID')->willReturn('functioneel-beheerder');
			$memberships[] = $group;
		}

		$groups->method('getUserGroups')->willReturn($memberships);
		$groups->method('isAdmin')->willReturnCallback(fn (string $id): bool => $id === $uid && $isAdmin);
		$groups->method('isInGroup')->willReturnCallback(
			fn (string $id, string $group): bool => $id === $uid && $group === 'functioneel-beheerder' && $inGroup
		);

		$provided = [];
		$state    = $this->createMock(originalClassName: IInitialState::class);
		$state->expects($this->once())->method('provideInitialState')->willReturnCallback(
			function (string $key, mixed $value) use (&$provided): void {
				$provided[] = [$key, $value];
			}
		);

		$controller = new DashboardController(
			appName: 'stackiq',
			request: $this->createMock(originalClassName: IRequest::class),
			userSession: $session,
			initialState: $state,
			shellPermissions: new ShellPermissions(groupManager: $groups)
		);
		$response   = $controller->page(null);
		$this->assertSame(expected: 'index', actual: $response->getTemplateName());

		return $provided;

	}//end provided()

	/**
	 * A regular user holds only `user`, so the sync entry is hidden.
	 *
	 * @return void
	 */
	public function testARegularUserHoldsOnlyUser(): void {
		$this->assertSame(expected: [['permissions', ['user']]], actual: $this->provided(uid: 'henk', isAdmin: false, inGroup: false));

	}//end testARegularUserHoldsOnlyUser()

	/**
	 * A functional administrator may see the sync and review access, but is not an admin.
	 *
	 * @return void
	 */
	public function testAFunctionalAdministratorMaySeeTheSync(): void {
		$this->assertSame(
			expected: [['permissions', ['user', 'access.review', 'sync.view']]],
			actual: $this->provided(uid: 'fb', isAdmin: false, inGroup: true)
		);

	}//end testAFunctionalAdministratorMaySeeTheSync()

	/**
	 * A Nextcloud admin is an admin and may see the sync.
	 *
	 * @return void
	 */
	public function testAnAdminHoldsAdminAndSyncView(): void {
		$this->assertSame(
			expected: [['permissions', ['user', 'admin', 'access.review', 'sync.view']]],
			actual: $this->provided(uid: 'admin', isAdmin: true, inGroup: false)
		);

	}//end testAnAdminHoldsAdminAndSyncView()

	/**
	 * Without a signed-in user the list is empty.
	 *
	 * @return void
	 */
	public function testNoUserHoldsNothing(): void {
		$this->assertSame(expected: [['permissions', []]], actual: $this->provided(uid: null, isAdmin: false, inGroup: false));

	}//end testNoUserHoldsNothing()
}//end class
