<?php

/**
 * Authorization tests for the /api/user-groups/config endpoints.
 *
 * @category  Test
 * @package   OCA\SoftwareCatalog\Tests\Unit\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 *
 * @spec openspec/specs/settings-admin-controller/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Tests\Unit\Controller;

use OCA\SoftwareCatalog\Controller\SettingsController;
use OCA\SoftwareCatalog\Service\ArchiMateService;
use OCA\SoftwareCatalog\Service\EolSyncService;
use OCA\SoftwareCatalog\Service\OrganizationSyncService;
use OCA\SoftwareCatalog\Service\ProgressTracker;
use OCA\SoftwareCatalog\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * THE DEFECT UNDER TEST — a privilege bypass by sibling route.
 *
 * Four routes each returned one slice of the user-groups configuration,
 * and each carried an explicit admin guard:
 *
 *   GET /api/settings/user-groups/generic             -> getGenericUserGroups()
 *   GET /api/settings/user-groups/organization-admin  -> getOrganizationAdminGroups()
 *   GET /api/settings/user-groups/super-user          -> getSuperUserGroups()
 *   GET /api/settings/user-groups/all                 -> getAllGroups()
 *
 * A fifth route returned all four at once:
 *
 *   GET /api/user-groups/config                       -> getUserGroupsConfig()
 *
 * and checked only that the caller was logged in. SettingsService::
 * getUserGroupsConfig() is literally the union of the four guarded getters,
 * so any authenticated user could read through it exactly the data the
 * four dedicated routes refused them — including `allGroups`, the full
 * group list of the instance.
 *
 * WHICH SIDE TO FIX WAS NOT OBVIOUS FROM THE FINDING. The four guarded
 * getters have ZERO callers in src/ — every consumer, including
 * UserGroupsConfiguration.vue via the settings store, hits
 * /api/user-groups/config. Read as "dead code", the tempting move is to
 * delete the four guarded endpoints, which would have left the unguarded
 * aggregate as the only surviving reader. Tracing the sibling seam gives
 * the opposite answer: the four are the CORRECT implementation and the
 * live route is the one missing the guard.
 *
 * Two gates were silent on it by construction. gate-7 (no-admin-idor)
 * sees an auth guard in the body — `getUser() === null` is one — and
 * passes. gate-9 (semantic-auth) compares the annotation against the body
 * and finds no mismatch, because a @NoAdminRequired method with no admin
 * check is self-consistent. The defect lives in the relationship between
 * two endpoints, which neither gate models.
 */
final class SettingsControllerUserGroupsConfigAuthTest extends TestCase {

	/**
	 * The service double whose data must not leak.
	 *
	 * @var SettingsService|MockObject
	 */
	private SettingsService|MockObject $settingsService;

	/**
	 * The group manager double that decides admin-ness.
	 *
	 * @var IGroupManager|MockObject
	 */
	private IGroupManager|MockObject $groupManager;

	/**
	 * The session double.
	 *
	 * @var IUserSession|MockObject
	 */
	private IUserSession|MockObject $userSession;

	/**
	 * Build a SettingsController for a caller with the given identity.
	 *
	 * @param string|null $uid The caller's UID, or null for anonymous.
	 * @param bool $isAdmin Whether that caller is a Nextcloud admin.
	 *
	 * @return SettingsController The controller under test.
	 */
	private function makeController(?string $uid, bool $isAdmin): SettingsController {
		$this->settingsService = $this->createMock(SettingsService::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->userSession = $this->createMock(IUserSession::class);

		if ($uid === null) {
			$this->userSession->method('getUser')->willReturn(null);
		} else {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$this->userSession->method('getUser')->willReturn($user);
			$this->groupManager->method('isAdmin')->with($uid)->willReturn($isAdmin);
		}

		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn([]);

		return new SettingsController(
			'softwarecatalog',
			$request,
			$this->createMock(IAppConfig::class),
			$this->createMock(ContainerInterface::class),
			$this->createMock(IAppManager::class),
			$this->groupManager,
			$this->userSession,
			$this->settingsService,
			$this->createMock(OrganizationSyncService::class),
			$this->createMock(ArchiMateService::class),
			$this->createMock(ProgressTracker::class),
			$this->createMock(EolSyncService::class),
			$this->createMock(LoggerInterface::class)
		);

	}//end makeController()

	/**
	 * THE REGRESSION TEST. A non-admin, authenticated caller must be
	 * refused, and the service must never be reached — deny before grant,
	 * not deny after fetching.
	 *
	 * @return void
	 */
	public function testNonAdminIsRefusedAndTheServiceIsNeverReached(): void {
		$controller = $this->makeController(uid: 'plain-user', isAdmin: false);

		// A spy rather than expects($this->never()): the controller wraps its
		// body in catch (\Exception), which would swallow a PHPUnit
		// expectation failure into a 500 and report the leak as an unrelated
		// server error. Counting the calls and asserting afterwards keeps the
		// failure message about the thing that actually went wrong.
		$calls = 0;
		$this->settingsService->method('getUserGroupsConfig')->willReturnCallback(
			function () use (&$calls): array {
				$calls++;
				return [
					'success' => true,
					'config' => [
						'generic' => [],
						'organizationAdmin' => [],
						'superUser' => ['SENTINEL-super-user-group'],
						'allGroups' => ['SENTINEL-super-user-group'],
					],
				];
			}
		);

		$response = $controller->getUserGroupsConfig();

		$this->assertSame(
			Http::STATUS_FORBIDDEN,
			$response->getStatus(),
			'A non-admin must not read the user-groups configuration through the aggregate route: '
			. 'it is the union of four endpoints that each refuse this caller with 403.'
		);

		$this->assertStringNotContainsString(
			'SENTINEL-super-user-group',
			json_encode($response->getData()),
			'The refusal must not carry the payload: a non-admin received the super-user group list.'
		);

		$this->assertSame(
			0,
			$calls,
			'Deny before grant — the service must not be consulted at all for a caller who will be refused.'
		);

	}//end testNonAdminIsRefusedAndTheServiceIsNeverReached()

	/**
	 * The positive control. Without this arm the assertion above is
	 * satisfied by an endpoint that refuses EVERYONE, which would break
	 * the admin settings panel while still looking secure.
	 *
	 * @return void
	 */
	public function testAdminStillGetsTheConfiguration(): void {
		$controller = $this->makeController(uid: 'an-admin', isAdmin: true);

		$this->settingsService->expects($this->once())
			->method('getUserGroupsConfig')
			->willReturn(
				[
					'success' => true,
					'config' => [
						'generic' => ['software-catalog-users'],
						'organizationAdmin' => [],
						'superUser' => ['admin'],
						'allGroups' => ['admin', 'software-catalog-users'],
					],
				]
			);

		$response = $controller->getUserGroupsConfig();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(
			['software-catalog-users'],
			$response->getData()['config']['generic'],
			'The admin must still receive the configuration itself, not merely a 200.'
		);

	}//end testAdminStillGetsTheConfiguration()

	/**
	 * An anonymous caller keeps its 401 — the guard added here must not
	 * turn "not logged in" into "forbidden", which would tell an anonymous
	 * prober that the resource exists and is admin-only.
	 *
	 * @return void
	 */
	public function testAnonymousStillGets401(): void {
		$controller = $this->makeController(uid: null, isAdmin: false);

		$this->settingsService->expects($this->never())->method('getUserGroupsConfig');

		$response = $controller->getUserGroupsConfig();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testAnonymousStillGets401()

	/**
	 * The write half of the same route. It was already admin-only through
	 * the absence of @NoAdminRequired, i.e. through middleware alone; this
	 * asserts the in-body guard that now backs it up, so a future
	 * annotation edit cannot silently open a write path.
	 *
	 * @return void
	 */
	public function testNonAdminCannotUpdateTheConfiguration(): void {
		$controller = $this->makeController(uid: 'plain-user', isAdmin: false);

		$calls = 0;
		$this->settingsService->method('updateUserGroupsConfig')->willReturnCallback(
			function () use (&$calls): array {
				$calls++;
				return ['success' => true];
			}
		);

		$response = $controller->updateUserGroupsConfig();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(0, $calls, 'A non-admin write must never reach the service.');

	}//end testNonAdminCannotUpdateTheConfiguration()

	/**
	 * And its positive control.
	 *
	 * @return void
	 */
	public function testAdminCanUpdateTheConfiguration(): void {
		$controller = $this->makeController(uid: 'an-admin', isAdmin: true);

		$this->settingsService->expects($this->once())
			->method('updateUserGroupsConfig')
			->willReturn(['success' => true]);

		$response = $controller->updateUserGroupsConfig();

		$this->assertSame(200, $response->getStatus());

	}//end testAdminCanUpdateTheConfiguration()

}//end class
