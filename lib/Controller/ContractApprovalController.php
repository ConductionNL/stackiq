<?php

/**
 * Softwarecatalog ContractApprovalController.
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
 * @category Controller
 * @package  OCA\SoftwareCatalog\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://codeberg.org/Conduction/softwarecatalog
 *
 * @spec openspec/changes/softwarecatalog-delegation-via-events/specs/contract-decision-delegation/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Controller;

use OCA\SoftwareCatalog\AppInfo\Application;
use OCA\SoftwareCatalog\Service\ContractApprovalService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Controller for the contract approval-delegation seam.
 *
 * @spec openspec/changes/softwarecatalog-delegation-via-events/specs/contract-decision-delegation/spec.md
 */
class ContractApprovalController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest                $request         The request.
     * @param ContractApprovalService $approvalService The approval-delegation service.
     * @param IUserSession            $userSession     The user session (auth guard).
     * @param LoggerInterface         $logger          The logger.
     */
    public function __construct(
        IRequest $request,
        private readonly ContractApprovalService $approvalService,
        private readonly IUserSession $userSession,
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
     * @spec            openspec/changes/softwarecatalog-contracts-to-decidesk/specs/contract-decision-delegation/spec.md
     */
    #[NoAdminRequired]
    public function config(): JSONResponse
    {
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
     * @spec            openspec/changes/softwarecatalog-contracts-to-decidesk/specs/contract-decision-delegation/spec.md
     */
    #[NoAdminRequired]
    public function submit(string $contractUuid): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(data: ['message' => 'Not logged in'], statusCode: Http::STATUS_UNAUTHORIZED);
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
     * @spec            openspec/changes/softwarecatalog-contracts-to-decidesk/specs/contract-decision-delegation/spec.md
     */
    #[NoAdminRequired]
    public function submitRenewal(string $contractUuid): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(data: ['message' => 'Not logged in'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        return $this->raise(contractUuid: $contractUuid, isRenewal: true);

    }//end submitRenewal()

    /**
     * Shared submit path for approval / renewal — fail-closed on error.
     *
     * @param string $contractUuid The contract uuid.
     * @param bool   $isRenewal    Whether this is a renewal decision.
     *
     * @return JSONResponse The result envelope.
     */
    private function raise(string $contractUuid, bool $isRenewal): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(data: ['message' => 'Not logged in'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

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
