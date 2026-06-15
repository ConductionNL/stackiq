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
use OCA\SoftwareCatalog\Service\SettingsService;
use OCA\SoftwareCatalog\Service\SoftwareCatalogContactSyncService;
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
     * @param ITimeFactory                      $timeFactory     The time factory for job scheduling
     * @param OrganizationSyncService           $orgSyncService  The sync service
     * @param SoftwareCatalogContactSyncService $contactSync     The Nextcloud-Contacts bridge
     * @param SettingsService                   $settingsService The settings service (register/schema id resolution)
     * @param IAppManager                       $appManager      The Nextcloud app manager
     * @param LoggerInterface                   $logger          The logger
     */
    public function __construct(
        ITimeFactory $timeFactory,
        OrganizationSyncService $orgSyncService,
        private readonly SoftwareCatalogContactSyncService $contactSync,
        private readonly SettingsService $settingsService,
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
     * Delegates organisation/contact synchronisation to the
     * OrganizationSyncService, then keeps every catalog relationship record's
     * `contactsUid` link to the Nextcloud addressbook fresh via
     * SoftwareCatalogContactSyncService. Per cross-app interface contract #2,
     * identity lives in Nextcloud Contacts — this job refreshes the link, it
     * does NOT mirror identity into OpenRegister.
     *
     * No user context is needed since all ObjectService calls use _rbac: false.
     *
     * @param mixed $argument Job arguments (not used)
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     * @spec                                          openspec/changes/softwarecatalog-contacts-to-nc/specs/softwarecatalog-contacts-to-nc/spec.md#REQ-SCNC-003
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

        // Keep contactsUid links fresh (identity stays in NC Contacts).
        try {
            $this->refreshContactsUidLinks();
        } catch (\Throwable $e) {
            $this->logger->error(
                '[OrganizationContactSyncJob] Fatal error during contactsUid link refresh — cron pass protected',
                [
                    'error' => $e->getMessage(),
                    'file'  => $e->getFile(),
                    'line'  => $e->getLine(),
                ]
            );
        }//end try
    }//end run()


    /**
     * Refresh the `contactsUid` link on every contactpersoon/organisatie record.
     *
     * For each record this (re)resolves its Nextcloud Contact through
     * SoftwareCatalogContactSyncService and writes back the UID only when it is
     * missing or has changed. Never mirrors identity into OpenRegister and
     * never deletes a source object — a record that cannot be resolved is left
     * intact for a later pass.
     *
     * @return void
     */
    private function refreshContactsUidLinks(): void
    {
        if ($this->contactSync->isAvailable() === false) {
            $this->logger->info('[OrganizationContactSyncJob] Nextcloud Contacts unavailable, skipping link refresh');
            return;
        }

        $registerId = $this->settingsService->getVoorzieningenRegisterId();
        if ($registerId === null) {
            $this->logger->info('[OrganizationContactSyncJob] Voorzieningen register not configured, skipping link refresh');
            return;
        }

        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return;
        }

        foreach (['contactpersoon', 'organisatie'] as $objectType) {
            $schemaId = $this->settingsService->getSchemaIdForObjectType($objectType);
            if ($schemaId === null || $schemaId === false) {
                continue;
            }

            $objects = $objectService->setRegister($registerId)
                ->setSchema($schemaId)
                ->findAll([], false, false);

            foreach ($objects as $object) {
                $this->refreshOne($objectService, $object, $registerId, $schemaId, $objectType);
            }
        }
    }//end refreshContactsUidLinks()


    /**
     * Refresh the contactsUid link for a single record.
     *
     * @param object  $objectService The OpenRegister ObjectService.
     * @param mixed   $object        The OpenRegister object entity.
     * @param integer $registerId    The register id.
     * @param integer $schemaId      The schema id.
     * @param string  $objectType    The object type.
     *
     * @return void
     */
    private function refreshOne(
        object $objectService,
        mixed $object,
        int $registerId,
        int $schemaId,
        string $objectType
    ): void {
        try {
            $data = (is_object($object) === true && method_exists($object, 'getObject') === true)
                ? (array) $object->getObject() : (array) $object;
            $uuid = (is_object($object) === true && method_exists($object, 'getUuid') === true)
                ? (string) $object->getUuid() : (string) ($data['id'] ?? '');

            $current = trim((string) ($data['contactsUid'] ?? ''));
            if ($current !== '' && $this->contactSync->findContactByUid($current) !== null) {
                // Link already fresh.
                return;
            }

            $resolved = $this->contactSync->syncToContacts($objectType, $data);
            if ($resolved === null || $resolved === '' || $resolved === $current) {
                return;
            }

            $data['contactsUid'] = $resolved;
            $objectService->saveObject($data, [], $registerId, $schemaId, ($uuid !== '' ? $uuid : null), false, false);
        } catch (\Throwable $e) {
            $this->logger->warning(
                '[OrganizationContactSyncJob] Could not refresh contactsUid link — record left intact',
                ['objectType' => $objectType, 'error' => $e->getMessage()]
            );
        }//end try
    }//end refreshOne()
}//end class
