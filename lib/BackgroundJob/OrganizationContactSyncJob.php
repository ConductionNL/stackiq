<?php

/**
 * Organization Contact Synchronization Background Job
 *
 * This file contains the background job class for synchronizing organizations and contact persons
 * between SoftwareCatalog objects and OpenRegister entities.
 *
 * @category  BackgroundJob
 * @package   OCA\SoftwareCatalog\BackgroundJob
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @version   GIT: 1.0.0
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\BackgroundJob;

use OCA\SoftwareCatalog\Service\OrganizationSyncService;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Background job for comprehensive organization and contact person synchronization
 *
 * This job runs every 5 minutes to ensure data consistency between SoftwareCatalog objects
 * and OpenRegister entities using full sync (all organizations). All business logic is
 * delegated to the OrganizationSyncService.
 *
 * All sync operations use _rbac: false and _multitenancy: false since this is a
 * system-level background job that needs unrestricted access to all objects.
 *
 * @category BackgroundJob
 * @package  OCA\SoftwareCatalog\BackgroundJob
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @version  GIT: 1.0.0
 * @link     https://codeberg.org/Conduction/SoftwareCatalog
 */
class OrganizationContactSyncJob extends TimedJob
{

    /**
     * Organization synchronization service
     *
     * @var OrganizationSyncService The service handling sync operations
     */
    private OrganizationSyncService $orgSyncService;

    /**
     * Constructor for OrganizationContactSyncJob
     *
     * @param ITimeFactory            $timeFactory    The time factory for job scheduling
     * @param OrganizationSyncService $orgSyncService The sync service
     * @param IAppManager             $appManager     The Nextcloud app manager
     * @param LoggerInterface         $logger         The logger
     */
    public function __construct(
        ITimeFactory $timeFactory,
        OrganizationSyncService $orgSyncService,
        private readonly IAppManager $appManager,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(time: $timeFactory);
        $this->setInterval(seconds: 300);
        // 5 minutes.
        $this->orgSyncService = $orgSyncService;
    }//end __construct()

    /**
     * Runs the background job
     *
     * Delegates all synchronization logic to the OrganizationSyncService.
     * The service handles all business logic, logging, and error handling.
     * No user context is needed since all ObjectService calls use _rbac: false.
     *
     * @param mixed $argument Job arguments (not used)
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     * @spec                                          openspec/changes/retrofit-2026-05-26-cronjob-context/tasks.md#task-2
     */
    protected function run($argument): void
    {
        if (in_array('openregister', $this->appManager->getInstalledApps(), true) === false) {
            $this->logger->info('[OrganizationContactSyncJob] OpenRegister not installed, skipping sync');
            return;
        }

        $this->logger->info('[OrganizationContactSyncJob] Starting scheduled organization sync');
        try {
            $this->orgSyncService->performScheduledSync();
        } catch (\Throwable $e) {
            $this->logger->error(
                '[OrganizationContactSyncJob] Fatal error during sync — cron pass protected',
                [
                    'error' => $e->getMessage(),
                    'file'  => $e->getFile(),
                    'line'  => $e->getLine(),
                ]
            );
        }//end try
    }//end run()
}//end class
