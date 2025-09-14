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

/**
 * Background job for comprehensive organization and contact person synchronization
 * 
 * This job runs every 5 minutes to ensure data consistency between SoftwareCatalog objects
 * and OpenRegister entities using full sync (all organizations). All business logic is 
 * delegated to the OrganizationSyncService.
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
     * Constructor for OrganizationContactSyncJob
     *
     * @param ITimeFactory            $timeFactory             The time factory for job scheduling
     * @param OrganizationSyncService $organizationSyncService The sync service
     */
    public function __construct(
        ITimeFactory $timeFactory,
        OrganizationSyncService $organizationSyncService
    ) {
        parent::__construct($timeFactory);
        $this->setInterval(300); // 5 minutes
        $this->organizationSyncService = $organizationSyncService;
    }

    /**
     * Runs the background job
     *
     * This method delegates all synchronization logic to the OrganizationSyncService.
     * The service handles all business logic, logging, and error handling.
     *
     * @param mixed $argument Job arguments (not used)
     *
     * @return void
     */
    protected function run($argument): void
    {
        // Delegate all synchronization logic to the service
        $this->organizationSyncService->performScheduledSync();
    }
} 