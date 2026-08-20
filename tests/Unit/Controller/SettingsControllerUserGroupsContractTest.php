<?php

/**
 * Wire-contract tests for the four dedicated user-groups GET endpoints.
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
 * WHAT THIS COVERS, AND WHY IT DID NOT EXIST BEFORE.
 *
 * Four routes each return one slice of the user-groups configuration:
 *
 *   GET /api/settings/user-groups/generic             -> getGenericUserGroups()
 *   GET /api/settings/user-groups/organization-admin  -> getOrganizationAdminGroups()
 *   GET /api/settings/user-groups/super-user          -> getSuperUserGroups()
 *   GET /api/settings/user-groups/all                 -> getAllGroups()
 *
 * SettingsControllerUserGroupsConfigAuthTest documents that these four are
 * the CORRECT implementation of the guard that the aggregate route
 * /api/user-groups/config was missing — and it tests the aggregate, not
 * these. So the four endpoints that carry the guard had no test of their
 * own, and their responses were never asserted on any wire.
 *
 * Each arm below calls the controller method BY NAME. That is deliberate:
 * a data-provider loop dispatching `$controller->$method()` would exercise
 * the same code and remain invisible to any reader — and to gate-25 — that
 * looks for the call. The literal call is the traceable one.
 *
 * Every endpoint is asserted on three axes, because any one of them alone
 * passes for the wrong reason:
 *
 *   - anonymous -> 401 (not 403; a 403 tells an anonymous prober the
 *     resource exists and is admin-only)
 *   - non-admin -> 403 AND the service is never consulted, AND the payload
 *     does not travel in the refusal
 *   - admin     -> 200 AND the groups themselves, not merely a 200
 *
 * The admin arm is the positive control for the other two: without it, an
 * endpoint that refuses everybody satisfies both refusal assertions while
 * breaking the admin settings panel.
 */
final class SettingsControllerUserGroupsContractTest extends TestCase {

	/**
	 * The service double whose data must not leak to a non-admin.
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
	 * @param string|null $uid     The caller's UID, or null for anonymous.
	 * @param bool        $isAdmin Whether that caller is a Nextcloud admin.
	 *
	 * @return SettingsController The controller under test.
	 */
	private function makeController(?string $uid, bool $isAdmin): SettingsController {
		$this->settingsService = $this->createMock(SettingsService::class);
		$this->groupManager    = $this->createMock(IGroupManager::class);
		$this->userSession     = $this->createMock(IUserSession::class);

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
	 * Arm the named service getter with a counting spy carrying a sentinel.
	 *
	 * A spy rather than expects($this->never()): each controller body wraps
	 * its work in catch (\Exception), which would swallow a PHPUnit
	 * expectation failure into a 500 and report a data leak as an unrelated
	 * server error.
	 *
	 * @param string $serviceMethod The SettingsService method to arm.
	 * @param int    $calls         Call counter, by reference.
	 *
	 * @return void
	 */
	private function spyOn(string $serviceMethod, int &$calls): void {
		$this->settingsService->method($serviceMethod)->willReturnCallback(
			function () use (&$calls): array {
				$calls++;
				return ['SENTINEL-group'];
			}
		);

	}//end spyOn()

	/**
	 * GET /api/settings/user-groups/generic — the full wire contract.
	 *
	 * @return void
	 */
	public function testGetGenericUserGroupsContract(): void {
		$controller = $this->makeController(uid: null, isAdmin: false);
		$this->assertSame(
			Http::STATUS_UNAUTHORIZED,
			$controller->getGenericUserGroups()->getStatus(),
			'An anonymous caller must get 401, not 403 — a 403 confirms the resource exists.'
		);

		$controller = $this->makeController(uid: 'plain-user', isAdmin: false);
		$calls      = 0;
		$this->spyOn(serviceMethod: 'getGenericUserGroups', calls: $calls);
		$response = $controller->getGenericUserGroups();
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(0, $calls, 'Deny before grant — the service must not be consulted.');
		$this->assertStringNotContainsString(
			'SENTINEL-group',
			(string) json_encode($response->getData()),
			'The refusal must not carry the payload it refuses.'
		);

		$controller = $this->makeController(uid: 'an-admin', isAdmin: true);
		$this->settingsService->expects($this->once())
			->method('getGenericUserGroups')
			->willReturn(['software-catalog-users']);
		$response = $controller->getGenericUserGroups();
		$this->assertSame(200, $response->getStatus());
		$this->assertSame(
			['success' => true, 'groups' => ['software-catalog-users']],
			$response->getData(),
			'The admin must receive the groups themselves, under the documented keys.'
		);

	}//end testGetGenericUserGroupsContract()

	/**
	 * GET /api/settings/user-groups/organization-admin — the full wire contract.
	 *
	 * @return void
	 */
	public function testGetOrganizationAdminGroupsContract(): void {
		$controller = $this->makeController(uid: null, isAdmin: false);
		$this->assertSame(
			Http::STATUS_UNAUTHORIZED,
			$controller->getOrganizationAdminGroups()->getStatus()
		);

		$controller = $this->makeController(uid: 'plain-user', isAdmin: false);
		$calls      = 0;
		$this->spyOn(serviceMethod: 'getOrganizationAdminGroups', calls: $calls);
		$response = $controller->getOrganizationAdminGroups();
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(0, $calls, 'Deny before grant — the service must not be consulted.');
		$this->assertStringNotContainsString(
			'SENTINEL-group',
			(string) json_encode($response->getData())
		);

		$controller = $this->makeController(uid: 'an-admin', isAdmin: true);
		$this->settingsService->expects($this->once())
			->method('getOrganizationAdminGroups')
			->willReturn(['organisation-beheerders']);
		$response = $controller->getOrganizationAdminGroups();
		$this->assertSame(200, $response->getStatus());
		$this->assertSame(
			['success' => true, 'groups' => ['organisation-beheerders']],
			$response->getData()
		);

	}//end testGetOrganizationAdminGroupsContract()

	/**
	 * GET /api/settings/user-groups/super-user — the full wire contract.
	 *
	 * @return void
	 */
	public function testGetSuperUserGroupsContract(): void {
		$controller = $this->makeController(uid: null, isAdmin: false);
		$this->assertSame(
			Http::STATUS_UNAUTHORIZED,
			$controller->getSuperUserGroups()->getStatus()
		);

		$controller = $this->makeController(uid: 'plain-user', isAdmin: false);
		$calls      = 0;
		$this->spyOn(serviceMethod: 'getSuperUserGroups', calls: $calls);
		$response = $controller->getSuperUserGroups();
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(0, $calls, 'Deny before grant — the service must not be consulted.');
		$this->assertStringNotContainsString(
			'SENTINEL-group',
			(string) json_encode($response->getData()),
			'The super-user group list is the escalation target — it must not travel in a refusal.'
		);

		$controller = $this->makeController(uid: 'an-admin', isAdmin: true);
		$this->settingsService->expects($this->once())
			->method('getSuperUserGroups')
			->willReturn(['admin']);
		$response = $controller->getSuperUserGroups();
		$this->assertSame(200, $response->getStatus());
		$this->assertSame(
			['success' => true, 'groups' => ['admin']],
			$response->getData()
		);

	}//end testGetSuperUserGroupsContract()

	/**
	 * GET /api/settings/user-groups/all — the full wire contract.
	 *
	 * This one returns the instance's entire group list, so its refusal arm
	 * is the enumeration guard, not a formality.
	 *
	 * @return void
	 */
	public function testGetAllGroupsContract(): void {
		$controller = $this->makeController(uid: null, isAdmin: false);
		$this->assertSame(
			Http::STATUS_UNAUTHORIZED,
			$controller->getAllGroups()->getStatus()
		);

		$controller = $this->makeController(uid: 'plain-user', isAdmin: false);
		$calls      = 0;
		$this->spyOn(serviceMethod: 'getAllGroups', calls: $calls);
		$response = $controller->getAllGroups();
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(0, $calls, 'Deny before grant — the service must not be consulted.');
		$this->assertStringNotContainsString(
			'SENTINEL-group',
			(string) json_encode($response->getData()),
			'A non-admin must not enumerate the instance group list through a refusal body.'
		);

		$controller = $this->makeController(uid: 'an-admin', isAdmin: true);
		$this->settingsService->expects($this->once())
			->method('getAllGroups')
			->willReturn([['gid' => 'admin', 'displayName' => 'admin', 'isGeneric' => false]]);
		$response = $controller->getAllGroups();
		$this->assertSame(200, $response->getStatus());
		$this->assertSame(
			[['gid' => 'admin', 'displayName' => 'admin', 'isGeneric' => false]],
			$response->getData()['groups'],
			'The admin must receive the group list itself, not merely a 200.'
		);

	}//end testGetAllGroupsContract()

}//end class
