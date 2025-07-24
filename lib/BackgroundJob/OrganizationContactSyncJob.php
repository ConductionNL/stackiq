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
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Background job for comprehensive organization and contact person synchronization
 * 
 * This job runs every 5 minutes to ensure data consistency between SoftwareCatalog objects
 * and OpenRegister entities. It handles organization entity creation, user account management,
 * and maintains proper relationships between all components.
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
    /**
     * Organization synchronization service
     *
     * @var OrganizationSyncService The service handling sync operations
     */
    private OrganizationSyncService $organizationSyncService;

    /**
     * Configuration service instance
     *
     * @var IConfig The Nextcloud configuration service
     */
    private IConfig $config;

    /**
     * Logger instance
     *
     * @var LoggerInterface The logger for background job operations
     */
    private LoggerInterface $logger;

    /**
     * Constructor for OrganizationContactSyncJob
     *
     * @param ITimeFactory            $timeFactory             The time factory for job scheduling
     * @param OrganizationSyncService $organizationSyncService The sync service
     * @param IConfig                 $config                  The configuration service
     * @param LoggerInterface         $logger                  The logger instance
     */
    public function __construct(
        ITimeFactory $timeFactory,
        OrganizationSyncService $organizationSyncService,
        IConfig $config,
        LoggerInterface $logger
    ) {
        parent::__construct($timeFactory);
        $this->setInterval(300); // 5 minutes
        $this->organizationSyncService = $organizationSyncService;
        $this->config = $config;
        $this->logger = $logger;
    }

    /**
     * Runs the background job
     *
     * This method performs comprehensive organization synchronization using the
     * OrganizationSyncService and logs the results.
     *
     * @param mixed $argument Job arguments (not used)
     *
     * @return void
     */
    protected function run($argument): void
    {
        $this->logger->info('OrganizationContactSyncJob: Starting scheduled synchronization');

        try {
            // Perform the synchronization using the service
            $syncResults = $this->organizationSyncService->performFullSync();

            // Record the sync time
            $this->organizationSyncService->recordSyncTime();

            // Log summary results
            $this->logger->info('OrganizationContactSyncJob: Scheduled synchronization completed', [
                'organizationsProcessed' => $syncResults['organizationsProcessed'],
                'entitiesCreated' => $syncResults['entitiesCreated'],
                'entitiesUpdated' => $syncResults['entitiesUpdated'],
                'contactPersonsProcessed' => $syncResults['contactPersonsProcessed'],
                'usersCreated' => $syncResults['usersCreated'],
                'usersUpdated' => $syncResults['usersUpdated'],
                'errorCount' => count($syncResults['errors']),
                'duration' => $syncResults['duration']
            ]);

            // Log errors if any occurred
            if (!empty($syncResults['errors'])) {
                $this->logger->warning('OrganizationContactSyncJob: Synchronization completed with errors', [
                    'errors' => $syncResults['errors']
                ]);
            }

        } catch (\Exception $e) {
            $this->logger->error('OrganizationContactSyncJob: Scheduled synchronization failed', [
                'exception' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
} 