<?php

/**
 * Stackiq ContractApprovalController.
 *
 * Thin HTTP seam for delegating a contract approval / sign-off / renewal
 * DECISION to decidesk (cross-app interface contract #1) via the in-process
 * `IEventDispatcher` event contract. It owns NO approval workflow — it raises
 * the decision in decidesk (synchronous `DecisionRequestedEvent` dispatch) and
 * stores the returned decision id; the terminal outcome is projected back onto
 * the catalog-local `approvalState` / `status` fields by
 * `DecisionConcludedListener` (NOT an HTTP callback). It is NOT a CRUD wrapper
 * of OpenRegister's ObjectService (ADR-022): contract CRUD keeps running
 * through the manifest renderer's OR object store.
 *
 * FAIL-CLOSED: when decidesk is not installed, submit endpoints error and the
 * contract stays `In onderhandeling`; `status` is never set to `Actief`.
 *
 * AUTH (ADR-005): authenticated-only (`#[NoAdminRequired]`) AND a per-object
 * ownership guard on submit()/submitRenewal() — admin, or an aanbod-beheerder
 * whose active organisation owns the contract. Without the guard any
 * authenticated user could raise a decidesk approval decision for an
 * arbitrary contract uuid (IDOR / OWASP A01:2021), so the guard is enforced
 * server-side, before any decidesk event is dispatched.
 *
 * @category Controller
 * @package  OCA\Stackiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/contract-decision-delegation/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Controller;

use OCA\Stackiq\AppInfo\Application;
use OCA\Stackiq\Service\ContractApprovalService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Controller for the contract approval-delegation seam.
 *
 * @spec openspec/specs/contract-decision-delegation/spec.md
 */
class ContractApprovalController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param ContractApprovalService $approvalService The approval-delegation service.
	 * @param IUserSession $userSession The user session (auth guard).
	 * @param IGroupManager $groupManager Group membership (role check).
	 * @param IConfig $config Per-user organisation lookup.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly ContractApprovalService $approvalService,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly IConfig $config,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * Report whether contract approval delegation resolves on this instance.
	 *
	 * Drives the ContractDetail Approval panel: when false, the panel shows an
	 * "approval delegation not configured" state and hides the submit action so
	 * no fail-open path exists.
	 *
	 * @return JSONResponse `{configured: bool}`.
	 *
	 * @NoAdminRequired
	 * @spec            openspec/specs/contract-decision-delegation/spec.md
	 */
	#[NoAdminRequired]
	public function config(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(data: ['message' => 'Not logged in'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		return new JSONResponse(data: ['configured' => $this->approvalService->isDelegationConfigured()]);
	}//end config()

	/**
	 * Submit a contract for approval (decisionType=contract).
	 *
	 * @param string $contractUuid The contract OR object uuid.
	 *
	 * @return JSONResponse `{decisionId, approvalState}` on success, error otherwise.
	 *
	 * @NoAdminRequired
	 * @spec            openspec/specs/contract-decision-delegation/spec.md
	 */
	#[NoAdminRequired]
	public function submit(string $contractUuid): JSONResponse {
		$guard = $this->authorizeContract(contractUuid: $contractUuid);
		if ($guard instanceof JSONResponse) {
			return $guard;
		}

		return $this->raise(contractUuid: $contractUuid, isRenewal: false);
	}//end submit()

	/**
	 * Submit a contract renewal (decisionType=contract-renewal).
	 *
	 * @param string $contractUuid The contract OR object uuid.
	 *
	 * @return JSONResponse `{decisionId, approvalState}` on success, error otherwise.
	 *
	 * @NoAdminRequired
	 * @spec            openspec/specs/contract-decision-delegation/spec.md
	 */
	#[NoAdminRequired]
	public function submitRenewal(string $contractUuid): JSONResponse {
		$guard = $this->authorizeContract(contractUuid: $contractUuid);
		if ($guard instanceof JSONResponse) {
			return $guard;
		}

		return $this->raise(contractUuid: $contractUuid, isRenewal: true);
	}//end submitRenewal()

	/**
	 * Per-object authorization (IDOR guard). Returns a JSONResponse to short-
	 * circuit on failure, or null when the caller may submit/submitRenewal this
	 * contract.
	 *
	 * A caller may raise a decidesk decision for a contract only when they are
	 * an admin, OR an aanbod-beheerder whose active organisation owns the
	 * contract. Enforced before any decidesk event is dispatched.
	 *
	 * @param string $contractUuid The contract OR object uuid.
	 *
	 * @return JSONResponse|null Error response, or null when authorized.
	 *
	 * @spec openspec/changes/contract-approval-ownership-guard/specs/contract-decision-delegation/spec.md
	 */
	private function authorizeContract(string $contractUuid): ?JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Not logged in'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$groupNames = array_map(
			static function (IGroup $group) {
				return $group->getGID();
			},
			$this->groupManager->getUserGroups(user: $user)
		);

		$activeOrgUuid = (string)$this->config->getUserValue(
			userId: $user->getUID(),
			appName: 'core',
			key: 'organisation'
		);

		$authorized = $this->approvalService->authorizeSubmit(
			contractUuid: $contractUuid,
			groupNames: $groupNames,
			activeOrgUuid: $activeOrgUuid
		);

		if ($authorized === false) {
			$this->logger->warning(
				'ContractApprovalController: submit refused (not contract owner)',
				['contractUuid' => $contractUuid, 'uid' => $user->getUID()]
			);
			return new JSONResponse(
				data: ['message' => 'You do not have permission to submit this contract for approval'],
				statusCode: Http::STATUS_FORBIDDEN
			);
		}

		return null;
	}//end authorizeContract()

	/**
	 * Shared submit path for approval / renewal — fail-closed on error.
	 *
	 * @param string $contractUuid The contract uuid.
	 * @param bool $isRenewal Whether this is a renewal decision.
	 *
	 * @return JSONResponse The result envelope.
	 */
	private function raise(string $contractUuid, bool $isRenewal): JSONResponse {
		try {
			$decisionId = $this->approvalService->submitForApproval(contractUuid: $contractUuid, isRenewal: $isRenewal);
		} catch (\RuntimeException $e) {
			// Fail closed — the contract stays In onderhandeling; status untouched.
			$this->logger->info(
				'ContractApprovalController: submit failed closed',
				['contractUuid' => $contractUuid, 'isRenewal' => $isRenewal, 'error' => $e->getMessage()]
			);
			return new JSONResponse(
				data: ['message' => $e->getMessage(), 'configured' => $this->approvalService->isDelegationConfigured()],
				statusCode: Http::STATUS_SERVICE_UNAVAILABLE
			);
		}

		return new JSONResponse(data: ['decisionId' => $decisionId, 'approvalState' => ContractApprovalService::APPROVAL_PENDING]);
	}//end raise()
}//end class
