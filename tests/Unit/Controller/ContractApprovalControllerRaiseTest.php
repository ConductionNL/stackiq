<?php

/**
 * Unit tests for ContractApprovalController's config probe and the outcome of
 * an authorised submit: the pending decision on success and the fail-closed
 * 503 when the approval service cannot raise the decision.
 *
 * @category  Tests
 * @package   OCA\Stackiq\Tests\Unit\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/contract-approval-ownership-guard/specs/contract-decision-delegation/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Controller;

use OCA\Stackiq\Controller\ContractApprovalController;
use OCA\Stackiq\Service\ContractApprovalService;
use OCP\IConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Config probe and submit outcomes of ContractApprovalController.
 */
#[CoversClass(ContractApprovalController::class)]
class ContractApprovalControllerRaiseTest extends TestCase {

	/**
	 * @var ContractApprovalService&MockObject
	 */
	private ContractApprovalService&MockObject $approvalService;

	/**
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groupManager;

	/**
	 * @var IConfig&MockObject
	 */
	private IConfig&MockObject $config;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Set up fresh mocks for each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->approvalService = $this->createMock(ContractApprovalService::class);
		$this->userSession     = $this->createMock(IUserSession::class);
		$this->groupManager    = $this->createMock(IGroupManager::class);
		$this->config          = $this->createMock(IConfig::class);
		$this->logger          = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * Build the controller with the current mocks.
	 *
	 * @return ContractApprovalController
	 */
	private function controller(): ContractApprovalController {
		return new ContractApprovalController(
			$this->createMock(IRequest::class),
			$this->approvalService,
			$this->userSession,
			$this->groupManager,
			$this->config,
			$this->logger
		);
	}//end controller()

	/**
	 * Sign in a caller in the given groups whose active organisation is org-1.
	 *
	 * @param string[] $groupIds The caller's group ids.
	 *
	 * @return void
	 */
	private function signIn(array $groupIds): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('beheerder');
		$this->userSession->method('getUser')->willReturn($user);

		$groups = array_map(
			function (string $gid): IGroup {
				$group = $this->createMock(IGroup::class);
				$group->method('getGID')->willReturn($gid);
				return $group;
			},
			$groupIds
		);
		$this->groupManager->method('getUserGroups')->with($user)->willReturn($groups);
		$this->config->method('getUserValue')->with('beheerder', 'core', 'organisation')->willReturn('org-1');
	}//end signIn()

	/**
	 * config refuses an anonymous caller without asking the service.
	 *
	 * @return void
	 */
	public function testConfigRefusesAnonymousCaller(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->approvalService->expects($this->never())->method('isDelegationConfigured');

		$response = $this->controller()->config();

		$this->assertSame(401, $response->getStatus());
		$this->assertSame(['message' => 'Not logged in'], $response->getData());
	}//end testConfigRefusesAnonymousCaller()

	/**
	 * config reports whether delegation is configured.
	 *
	 * @return void
	 */
	public function testConfigReportsDelegationState(): void {
		$this->signIn(['users']);
		$this->approvalService->expects($this->once())->method('isDelegationConfigured')->willReturn(true);

		$response = $this->controller()->config();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['configured' => true], $response->getData());
	}//end testConfigReportsDelegationState()

	/**
	 * submit asks authorisation with the caller's group names and active
	 * organisation, then returns the pending decision.
	 *
	 * @return void
	 */
	public function testSubmitReturnsPendingDecision(): void {
		$this->signIn(['aanbod-beheerder', 'users']);
		$this->approvalService->expects($this->once())->method('authorizeSubmit')
			->with('contract-1', ['aanbod-beheerder', 'users'], 'org-1')
			->willReturn(true);
		$this->approvalService->expects($this->once())->method('submitForApproval')
			->with('contract-1', false)
			->willReturn('decision-9');

		$response = $this->controller()->submit('contract-1');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(
			['decisionId' => 'decision-9', 'approvalState' => ContractApprovalService::APPROVAL_PENDING],
			$response->getData()
		);
	}//end testSubmitReturnsPendingDecision()

	/**
	 * submitRenewal raises the decision as a renewal.
	 *
	 * @return void
	 */
	public function testSubmitRenewalRaisesRenewal(): void {
		$this->signIn(['aanbod-beheerder']);
		$this->approvalService->method('authorizeSubmit')->willReturn(true);
		$this->approvalService->expects($this->once())->method('submitForApproval')
			->with('contract-2', true)
			->willReturn('decision-10');

		$response = $this->controller()->submitRenewal('contract-2');

		$this->assertSame(['decisionId' => 'decision-10', 'approvalState' => 'pending'], $response->getData());
	}//end testSubmitRenewalRaisesRenewal()

	/**
	 * A submit the service cannot raise fails closed with 503, the service
	 * message and the delegation state, and logs the failure.
	 *
	 * @return void
	 */
	public function testSubmitFailsClosedWith503WhenServiceThrows(): void {
		$this->signIn(['aanbod-beheerder']);
		$this->approvalService->method('authorizeSubmit')->willReturn(true);
		$this->approvalService->method('submitForApproval')
			->willThrowException(new \RuntimeException('Decidesk not reachable'));
		$this->approvalService->method('isDelegationConfigured')->willReturn(false);
		$this->logger->expects($this->once())->method('info')
			->with(
				'ContractApprovalController: submit failed closed',
				['contractUuid' => 'contract-3', 'isRenewal' => true, 'error' => 'Decidesk not reachable']
			);

		$response = $this->controller()->submitRenewal('contract-3');

		$this->assertSame(503, $response->getStatus());
		$this->assertSame(
			['message' => 'Decidesk not reachable', 'configured' => false],
			$response->getData()
		);
	}//end testSubmitFailsClosedWith503WhenServiceThrows()
}//end class
