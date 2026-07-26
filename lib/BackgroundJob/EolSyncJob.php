<?php
/**
 * EOL Sync Background Job.
 *
 * Scheduled TimedJob that re-runs the endoflife.date EOL matcher on its
 * configured interval, operating in system (non-RBAC) context per the
 * `cronjob-context` pattern. All decision and persistence logic lives in
 * `EolSyncService` (testable, and shared with the manual "sync now" admin
 * endpoint so both trigger paths can never drift); this job is the
 * scheduler shell. `EolSyncService::run()` never throws — it degrades to a
 * recorded "unavailable" status instead — but this job still guards the
 * call so a future defect can never break a cron pass for the other jobs
 * sharing it.
 *
 * @category  BackgroundJob
 * @package   OCA\SoftwareCatalog\BackgroundJob
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 *
 * @spec openspec/specs/eol-feed-integration/spec.md#requirement-eol-sync-runs-on-a-schedule-with-a-manual-trigger
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\BackgroundJob;

use OCA\SoftwareCatalog\Service\EolSyncService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Runs the EOL matcher on a schedule (default: once a day).
 *
 * The interval is re-read from the EOL sync configuration on every
 * construction (Nextcloud re-instantiates background jobs each cron pass),
 * so an admin's interval change takes effect on the next pass without a
 * code change or app restart.
 */
class EolSyncJob extends TimedJob
{
    /**
     * Constructor.
     *
     * @param ITimeFactory    $timeFactory    The time factory for job scheduling.
     * @param EolSyncService  $eolSyncService The EOL sync orchestration service.
     * @param LoggerInterface $logger         The logger.
     */
    public function __construct(
        ITimeFactory $timeFactory,
        private readonly EolSyncService $eolSyncService,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(time: $timeFactory);

        // Floor at 300s (the shortest interval any existing SoftwareCatalog
        // background job runs at — OrganizationContactSyncJob) so a
        // mistyped admin value can never schedule a tighter loop than the
        // rest of the app's cron surface.
        $intervalSeconds = $this->eolSyncService->getConfig()['intervalSeconds'] ?? 86400;
        $this->setInterval(seconds: max(300, (int) $intervalSeconds));
    }//end __construct()

    /**
     * Runs the background job.
     *
     * Delegates entirely to `EolSyncService::run()`, which resolves the
     * configured EOL register/schema via OpenRegister's `ObjectService`
     * (never HTTP), matches and stamps mapped modules' versions, and
     * records a status summary. Operates in system (non-RBAC) context —
     * every downstream OpenRegister call is made with `_rbac: false`.
     *
     * @param mixed $argument Job arguments (not used).
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     * @spec                                          openspec/specs/eol-feed-integration/spec.md#requirement-eol-sync-runs-on-a-schedule-with-a-manual-trigger
     */
    protected function run($argument): void
    {
        try {
            $status = $this->eolSyncService->run();
            $this->logger->info(
                '[EolSyncJob] EOL sync run completed',
                $status
            );
        } catch (\Throwable $e) {
            // EolSyncService::run() is designed to never throw (it degrades
            // to a recorded status instead), but this guard keeps a future
            // regression there from breaking the shared cron pass.
            $this->logger->error(
                '[EolSyncJob] Fatal error during EOL sync — cron pass protected',
                [
                    'error' => $e->getMessage(),
                    'file'  => $e->getFile(),
                    'line'  => $e->getLine(),
                ]
            );
        }//end try
    }//end run()
}//end class
