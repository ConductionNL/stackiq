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
 * @spec openspec/changes/operations-sync-status-and-progress/specs/sync-status-and-progress/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Controller;

use OCA\Stackiq\Controller\DashboardController;
use OCA\Stackiq\Service\SyncAccessPolicy;
use OCP\AppFramework\Services\IInitialState;
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
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(fn (string $id): bool => $id === $uid && $isAdmin);
		$groups->method('isInGroup')->willReturnCallback(
			fn (string $id, string $group): bool => $id === $uid && $group === 'functioneel-beheerder' && $inGroup
		);

		$provided = [];
		$state    = $this->createMock(IInitialState::class);
		$state->expects($this->once())->method('provideInitialState')->willReturnCallback(
			function (string $key, mixed $value) use (&$provided): void {
				$provided[] = [$key, $value];
			}
		);

		$controller = new DashboardController('stackiq', $this->createMock(IRequest::class), $session, $state, $groups, new SyncAccessPolicy($groups));
		$response   = $controller->page(null);
		$this->assertSame('index', $response->getTemplateName());

		return $provided;

	}//end provided()

	/**
	 * A regular user holds only `user`, so the sync entry is hidden.
	 *
	 * @return void
	 */
	public function testARegularUserHoldsOnlyUser(): void {
		$this->assertSame([['permissions', ['user']]], $this->provided('henk', false, false));

	}//end testARegularUserHoldsOnlyUser()

	/**
	 * A functional administrator may see the sync but is not an admin.
	 *
	 * @return void
	 */
	public function testAFunctionalAdministratorMaySeeTheSync(): void {
		$this->assertSame([['permissions', ['user', 'sync.view']]], $this->provided('fb', false, true));

	}//end testAFunctionalAdministratorMaySeeTheSync()

	/**
	 * A Nextcloud admin is an admin and may see the sync.
	 *
	 * @return void
	 */
	public function testAnAdminHoldsAdminAndSyncView(): void {
		$this->assertSame([['permissions', ['user', 'admin', 'sync.view']]], $this->provided('admin', true, false));

	}//end testAnAdminHoldsAdminAndSyncView()

	/**
	 * Without a signed-in user the list is empty.
	 *
	 * @return void
	 */
	public function testNoUserHoldsNothing(): void {
		$this->assertSame([['permissions', []]], $this->provided(null, false, false));

	}//end testNoUserHoldsNothing()
}//end class
