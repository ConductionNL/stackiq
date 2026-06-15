<?php
/**
 * Contract Approval Reconcile Background Job.
 *
 * Daily TimedJob that reconciles the decidesk outcome for every contract still
 * sitting at `approvalState = pending` — covering any subscription push that
 * was missed. All decision/poll/projection logic lives in
 * ContractApprovalService (testable); this job is the scheduler shell. The
 * projection is idempotent, so a re-run never double-applies an outcome. The
 * `In onderhandeling -> Actief` transition is reached only as a projection of
 * an `approved` decidesk outcome — never on local authority.
 *
 * @category  BackgroundJob
 * @package   OCA\SoftwareCatalog\BackgroundJob
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 *
 * @spec openspec/changes/softwarecatalog-contracts-to-decidesk/specs/contract-decision-delegation/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\BackgroundJob;

use OCA\SoftwareCatalog\Service\ContractApprovalService;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Daily background job that reconciles pending contract-approval decisions.
 *
 * @spec openspec/changes/softwarecatalog-contracts-to-decidesk/specs/contract-decision-delegation/spec.md
 */
class ContractApprovalReconcileJob extends TimedJob
{
    /**
     * Constructor.
     *
     * @param ITimeFactory            $timeFactory     The time factory for scheduling.
     * @param ContractApprovalService $approvalService The approval-delegation service.
     * @param IAppManager             $appManager      The Nextcloud app manager.
     * @param LoggerInterface         $logger          The logger.
     */
    public function __construct(
        ITimeFactory $timeFactory,
        private readonly ContractApprovalService $approvalService,
        private readonly IAppManager $appManager,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(time: $timeFactory);
        // Run once a day (86400s).
        $this->setInterval(seconds: 86400);
    }//end __construct()

    /**
     * Run the daily contract-approval reconcile pass.
     *
     * @param mixed $argument Job arguments (not used).
     *
     * @return void
     *
     * @spec openspec/changes/softwarecatalog-contracts-to-decidesk/specs/contract-decision-delegation/spec.md
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    protected function run($argument): void
    {
        if (in_array('openregister', $this->appManager->getInstalledApps(), true) === false) {
            $this->logger->info('[ContractApprovalReconcileJob] OpenRegister not installed, skipping run');
            return;
        }

        try {
            $count = $this->approvalService->reconcilePendingApprovals();
            $this->logger->info('[ContractApprovalReconcileJob] Reconcile pass complete', ['projected' => $count]);
        } catch (\Throwable $e) {
            $this->logger->error(
                '[ContractApprovalReconcileJob] Fatal error during reconcile pass — cron pass protected',
                ['error' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]
            );
        }
    }//end run()
}//end class
