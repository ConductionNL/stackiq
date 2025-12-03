<?php

/**
 * Organization Contact Synchronization Background Job
 *
 * This file contains the background job class for synchronizing organizations and contact persons
 * between SoftwareCatalog objects and OpenRegister entities.
 *
 * @category BackgroundJob
 * @package  OCA\SoftwareCatalog\BackgroundJob
 * @author   Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @version  1.0.0
 * @link     https://github.com/ConductionNL/SoftwareCatalog
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\BackgroundJob;

use OCA\SoftwareCatalog\Service\OrganizationSyncService;
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
 * The job uses CronjobContextTrait to set user and organisation context based on
 * administrator configuration, enabling proper RBAC authorization during execution.
 *
 * @category BackgroundJob
 * @package  OCA\SoftwareCatalog\BackgroundJob
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @version  1.0.0
 * @link     https://github.com/ConductionNL/SoftwareCatalog
 */
class OrganizationContactSyncJob extends TimedJob
{
    use CronjobContextTrait;

    /**
     * The cronjob identifier for configuration lookup
     */
    private const JOB_ID = 'organization_contact_sync';

    /**
     * Organization synchronization service
     *
     * @var OrganizationSyncService The service handling sync operations
     */
    private OrganizationSyncService $organizationSyncService;

    /**
     * Logger instance for this cronjob
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * Constructor for OrganizationContactSyncJob
     *
     * @param ITimeFactory            $timeFactory             The time factory for job scheduling
     * @param OrganizationSyncService $organizationSyncService The sync service
     * @param LoggerInterface         $logger                  The logger instance
     */
    public function __construct(
        ITimeFactory $timeFactory,
        OrganizationSyncService $organizationSyncService,
        LoggerInterface $logger
    ) {
        parent::__construct($timeFactory);
        $this->setInterval(300); // 5 minutes.
        $this->organizationSyncService = $organizationSyncService;
        $this->logger = $logger;
    }

    /**
     * Get the logger instance for the trait.
     *
     * @return LoggerInterface
     */
    protected function getLogger(): LoggerInterface
    {
        return $this->logger;
    }

    /**
     * Runs the background job
     *
     * This method sets the user and organisation context based on configuration,
     * then delegates all synchronization logic to the OrganizationSyncService.
     * The service handles all business logic, logging, and error handling.
     *
     * @param mixed $argument Job arguments (not used)
     *
     * @return void
     */
    protected function run($argument): void
    {
        try {
            // Set the cronjob context (user and organisation) from configuration.
            $contextSet = $this->setCronjobContext(self::JOB_ID);

            if (!$contextSet) {
                $this->logger->warning('[CRONJOB] OrganizationContactSyncJob: Running without context - RBAC checks may fail', [
                    'jobId' => self::JOB_ID,
                    'hint' => 'Configure user and organisation in Settings > Cronjobs to enable proper authorization'
                ]);
            }

            // Delegate all synchronization logic to the service.
            $this->organizationSyncService->performScheduledSync();

        } finally {
            // Always clear the context when done.
            $this->clearCronjobContext(self::JOB_ID);
        }
    }
}
