<?php

/**
 * Unit tests for ContractApprovalController's per-object ownership guard.
 *
 * Covers the IDOR fix: submit()/submitRenewal() MUST refuse (403) a caller who
 * is neither an admin nor an aanbod-beheerder whose active organisation owns
 * the contract, and MUST NOT reach ContractApprovalService::submitForApproval()
 * (so no decidesk event is ever dispatched) when refused. The owning
 * aanbod-beheerder and any admin MUST still succeed unchanged.
 *
 * @category  Tests
 * @package   OCA\Stackiq\Tests\Unit\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/contract-approval-ownership-guard/specs/contract-decision-delegation/spec.md
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Controller;

use OCA\Stackiq\Controller\ContractApprovalController;
use OCA\Stackiq\Service\ContractApprovalService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Test class for ContractApprovalController's ownership guard.
 */
class ContractApprovalControllerTest extends TestCase {
	/**
	 * @var ContractApprovalService|MockObject
	 */
	private ContractApprovalService|MockObject $approvalService;

	/**
	 * @var IUserSession|MockObject
	 */
	private IUserSession|MockObject $userSession;

	/**
	 * @var IGroupManager|MockObject
	 */
	private IGroupManager|MockObject $groupManager;

	/**
	 * @var IConfig|MockObject
	 */
	private IConfig|MockObject $config;

	/**
	 * Build the controller with the current mocks and a logged-in user.
	 *
	 * @return ContractApprovalController The controller under test.
	 */
	private function makeController(): ContractApprovalController {
		$request = $this->createMock(IRequest::class);

		$this->approvalService = $this->createMock(ContractApprovalService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->config = $this->createMock(IConfig::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('caller-uid');
		$this->userSession->method('getUser')->willReturn($user);

		$this->groupManager->method('getUserGroups')->willReturn([]);

		return new ContractApprovalController(
			$request,
			$this->approvalService,
			$this->userSession,
			$this->groupManager,
			$this->config,
			$this->createMock(LoggerInterface::class)
		);
	}//end makeController()

	/**
	 * A non-owning authenticated user (authorizeSubmit() returns false) gets
	 * 403 on submit(), and submitForApproval() is never invoked (no decidesk
	 * event is dispatched for an unauthorized submitter).
	 *
	 * @return void
	 */
	public function testSubmitRefusesUnauthorizedCaller(): void {
		$controller = $this->makeController();
		$this->approvalService->method('authorizeSubmit')->willReturn(false);
		$this->approvalService->expects($this->never())->method('submitForApproval');

		$response = $controller->submit('contract-uuid');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testSubmitRefusesUnauthorizedCaller()

	/**
	 * An authorized caller (owning aanbod-beheerder or admin) succeeds
	 * unchanged — submitForApproval() is reached and its result surfaced.
	 *
	 * @return void
	 */
	public function testSubmitAllowsAuthorizedCaller(): void {
		$controller = $this->makeController();
		$this->approvalService->method('authorizeSubmit')->willReturn(true);
		$this->approvalService->expects($this->once())
			->method('submitForApproval')
			->with('contract-uuid', false)
			->willReturn('decision-1');

		$response = $controller->submit('contract-uuid');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('decision-1', $response->getData()['decisionId']);
	}//end testSubmitAllowsAuthorizedCaller()

	/**
	 * submitRenewal() is covered by the same ownership guard: a non-owning
	 * caller is refused before submitForApproval() (isRenewal=true) runs.
	 *
	 * @return void
	 */
	public function testSubmitRenewalRefusesUnauthorizedCaller(): void {
		$controller = $this->makeController();
		$this->approvalService->method('authorizeSubmit')->willReturn(false);
		$this->approvalService->expects($this->never())->method('submitForApproval');

		$response = $controller->submitRenewal('contract-uuid');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testSubmitRenewalRefusesUnauthorizedCaller()

	/**
	 * submitRenewal() succeeds unchanged for an authorized caller.
	 *
	 * @return void
	 */
	public function testSubmitRenewalAllowsAuthorizedCaller(): void {
		$controller = $this->makeController();
		$this->approvalService->method('authorizeSubmit')->willReturn(true);
		$this->approvalService->expects($this->once())
			->method('submitForApproval')
			->with('contract-uuid', true)
			->willReturn('decision-2');

		$response = $controller->submitRenewal('contract-uuid');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('decision-2', $response->getData()['decisionId']);
	}//end testSubmitRenewalAllowsAuthorizedCaller()

	/**
	 * An unauthenticated caller (no user session) gets 401 before the
	 * ownership guard is even evaluated.
	 *
	 * @return void
	 */
	public function testSubmitRefusesUnauthenticatedCaller(): void {
		$request = $this->createMock(IRequest::class);
		$approvalService = $this->createMock(ContractApprovalService::class);
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);
		$groupManager = $this->createMock(IGroupManager::class);
		$config = $this->createMock(IConfig::class);

		$approvalService->expects($this->never())->method('authorizeSubmit');
		$approvalService->expects($this->never())->method('submitForApproval');

		$controller = new ContractApprovalController(
			$request,
			$approvalService,
			$userSession,
			$groupManager,
			$config,
			$this->createMock(LoggerInterface::class)
		);

		$response = $controller->submit('contract-uuid');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}//end testSubmitRefusesUnauthenticatedCaller()
}//end class
