<?php

/**
 * Unit tests for MergeController's admin-only authorization guard.
 *
 * Covers the no-admin-idor gate: dryRun()/execute() MUST refuse (403) a
 * caller who is not a member of the `admin` group, and MUST NOT reach
 * MergeOrganisatieService when refused. An admin caller MUST succeed
 * unchanged, and a blocked merge result from the service MUST surface as 409.
 *
 * @category  Tests
 * @package   OCA\SoftwareCatalog\Tests\Unit\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 *
 * @spec openspec/specs/organisation-merge/spec.md#requirement-both-merge-endpoints-must-be-admin-only-with-an-explicit-per-object-authorization-guard
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Tests\Unit\Controller;

use OCA\SoftwareCatalog\Controller\MergeController;
use OCA\SoftwareCatalog\Service\MergeOrganisatieService;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Test class for MergeController's admin-only guard.
 */
class MergeControllerTest extends TestCase {
	/**
	 * @var MergeOrganisatieService|MockObject
	 */
	private MergeOrganisatieService|MockObject $mergeService;

	/**
	 * @var IUserSession|MockObject
	 */
	private IUserSession|MockObject $userSession;

	/**
	 * @var IGroupManager|MockObject
	 */
	private IGroupManager|MockObject $groupManager;

	/**
	 * Build the controller with the current mocks and a logged-in user.
	 *
	 * @param bool $isAdmin Whether the logged-in user is an admin.
	 *
	 * @return MergeController The controller under test.
	 */
	private function makeController(bool $isAdmin): MergeController {
		$request = $this->createMock(IRequest::class);

		$this->mergeService = $this->createMock(MergeOrganisatieService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('caller-uid');
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->with('caller-uid')->willReturn($isAdmin);

		return new MergeController(
			$request,
			$this->userSession,
			$this->groupManager,
			$this->mergeService,
			$this->createMock(LoggerInterface::class)
		);
	}//end makeController()

	/**
	 * A non-admin caller is refused (403) on dryRun(), and the merge service
	 * is never invoked.
	 *
	 * @return void
	 */
	public function testDryRunRefusesNonAdmin(): void {
		$controller = $this->makeController(isAdmin: false);
		$this->mergeService->expects($this->never())->method('dryRun');

		$response = $controller->dryRun(uuid: 'org-a', targetUuid: 'org-b');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testDryRunRefusesNonAdmin()

	/**
	 * A non-admin caller is refused (403) on execute(), and the merge
	 * service is never invoked — no object or audit entry is written.
	 *
	 * @return void
	 */
	public function testExecuteRefusesNonAdmin(): void {
		$controller = $this->makeController(isAdmin: false);
		$this->mergeService->expects($this->never())->method('execute');

		$response = $controller->execute(uuid: 'org-a', targetUuid: 'org-b');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testExecuteRefusesNonAdmin()

	/**
	 * An admin caller is authorized: dryRun() reaches the service and its
	 * result is returned with 200.
	 *
	 * @return void
	 */
	public function testDryRunAuthorizesAdmin(): void {
		$controller = $this->makeController(isAdmin: true);
		$this->mergeService->expects($this->once())
			->method('dryRun')
			->with('org-a', 'org-b')
			->willReturn(['sourceUuid' => 'org-a', 'targetUuid' => 'org-b', 'counts' => [], 'blockers' => []]);

		$response = $controller->dryRun(uuid: 'org-a', targetUuid: 'org-b');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}//end testDryRunAuthorizesAdmin()

	/**
	 * An admin caller's blocked execute() result (ok: false) surfaces as 409,
	 * not a 5xx or silent success.
	 *
	 * @return void
	 */
	public function testExecuteSurfacesServiceBlockersAs409(): void {
		$controller = $this->makeController(isAdmin: true);
		$this->mergeService->method('execute')->willReturn(
			['ok' => false, 'sourceUuid' => 'org-a', 'targetUuid' => 'org-a', 'blockers' => [['type' => 'self-merge', 'message' => '...']]]
		);

		$response = $controller->execute(uuid: 'org-a', targetUuid: 'org-a');

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
	}//end testExecuteSurfacesServiceBlockersAs409()

	/**
	 * An admin caller's successful execute() result surfaces with 200.
	 *
	 * @return void
	 */
	public function testExecuteReturns200OnSuccess(): void {
		$controller = $this->makeController(isAdmin: true);
		$this->mergeService->method('execute')->willReturn(
			[
				'ok' => true,
				'operationId' => 'org_merge_1',
				'sourceUuid' => 'org-a',
				'targetUuid' => 'org-b',
				'status' => 'completed',
				'counts' => [],
			]
		);

		$response = $controller->execute(uuid: 'org-a', targetUuid: 'org-b');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}//end testExecuteReturns200OnSuccess()
}//end class
