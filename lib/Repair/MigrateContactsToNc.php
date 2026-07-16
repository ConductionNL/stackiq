<?php

/**
 * Repair step that migrates softwarecatalog person/organisation identity into
 * the Nextcloud addressbook.
 *
 * For every existing `contactpersoon` and `organisatie` object in the
 * `voorzieningen` register, this step resolves (or creates) a matching
 * Nextcloud Contact via OCP\Contacts\IManager and writes the resulting
 * `contactsUid` onto the kept relationship/role record. It is idempotent
 * (records already carrying a valid `contactsUid` are skipped) and fail-safe:
 * a source object's identity fields are never cleared until its `contactsUid`
 * has been successfully set, so a partial/failed run loses no data and can be
 * re-run safely.
 *
 * All OCP service calls use POSITIONAL arguments (named args are FATAL on
 * `occ upgrade`).
 *
 * @category  Repair
 * @package   OCA\SoftwareCatalog\Repair
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 *
 * @spec openspec/specs/softwarecatalog-contacts-to-nc/spec.md
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Repair;

use OCA\SoftwareCatalog\AppInfo\Application;
use OCA\SoftwareCatalog\Service\SettingsService;
use OCA\SoftwareCatalog\Service\SoftwareCatalogContactSyncService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Idempotent, fail-safe migration of identity to Nextcloud Contacts.
 *
 * @category Repair
 * @package  OCA\SoftwareCatalog\Repair
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version  GIT: <git_id>
 * @link     https://codeberg.org/Conduction/SoftwareCatalog
 *
 * @spec openspec/specs/softwarecatalog-contacts-to-nc/spec.md
 */
class MigrateContactsToNc implements IRepairStep
{
    /**
     * App-config key marking that the migration has fully converged.
     *
     * Set after a run in which every readable object was migrated without a
     * single failure; subsequent runs (this step re-runs on every upgrade as a
     * post-migration repair step) skip quietly instead of re-scanning.
     *
     * @var string
     */
    private const CONVERGED_CONFIG_KEY = 'contacts_migration_done';

    /**
     * Constructor.
     *
     * @param IAppManager                       $appManager      The app manager.
     * @param ContainerInterface                $container       The DI container.
     * @param SettingsService                   $settingsService The settings service (register/schema id resolution).
     * @param SoftwareCatalogContactSyncService $contactSync     The contacts bridge.
     * @param IAppConfig                        $appConfig       The app config (convergence marker).
     * @param LoggerInterface                   $logger          The logger.
     *
     * @spec exclude system-context adoption
     */
    public function __construct(
        private readonly IAppManager $appManager,
        private readonly ContainerInterface $container,
        private readonly SettingsService $settingsService,
        private readonly SoftwareCatalogContactSyncService $contactSync,
        private readonly IAppConfig $appConfig,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Returns the name of this repair step.
     *
     * @return string The repair step name.
     *
     * @spec openspec/specs/softwarecatalog-contacts-to-nc/spec.md
     */
    public function getName(): string
    {
        return 'Migrate SoftwareCatalog contacts/organisations to the Nextcloud addressbook';
    }//end getName()

    /**
     * Run the migration.
     *
     * @param IOutput $output The output interface for progress reporting.
     *
     * @return void
     *
     * @spec openspec/specs/softwarecatalog-contacts-to-nc/spec.md
     */
    public function run(IOutput $output): void
    {
        $output->startProgress(2);

        // Converged on a previous run — skip quietly. This step is registered
        // as a post-migration repair step and therefore re-runs on every
        // upgrade; once every object migrated cleanly there is nothing left
        // to do.
        if ($this->appConfig->getValueString(Application::APP_ID, self::CONVERGED_CONFIG_KEY, '') === '1') {
            $output->advance(2);
            $output->finishProgress();
            return;
        }

        // OpenRegister must be present to read the objects.
        if (in_array('openregister', $this->appManager->getInstalledApps(), true) === false) {
            $output->info('OpenRegister not installed — skipping contacts migration');
            $output->advance(2);
            $output->finishProgress();
            return;
        }

        // Nextcloud Contacts must be available to resolve/create UIDs.
        if ($this->contactSync->isAvailable() === false) {
            $output->info('Nextcloud Contacts not available — skipping contacts migration (will retry on next upgrade)');
            $output->advance(2);
            $output->finishProgress();
            return;
        }

        $registerId = $this->settingsService->getVoorzieningenRegisterId();
        if ($registerId === null) {
            $output->info('Voorzieningen register not configured — skipping contacts migration');
            $output->advance(2);
            $output->finishProgress();
            return;
        }

        $totalFailed     = 0;
        $totalReadFailed = 0;

        foreach (['contactpersoon', 'organisatie'] as $objectType) {
            $stats            = $this->migrateType(output: $output, registerId: $registerId, objectType: $objectType);
            $totalFailed     += $stats['failed'];
            $totalReadFailed += $stats['readFailed'];
            $output->info(
                sprintf(
                    'Contacts migration [%s]: %d linked, %d created, %d already-linked, %d skipped',
                    $objectType,
                    $stats['linked'],
                    $stats['created'],
                    $stats['skipped'],
                    $stats['failed']
                )
            );
            $output->advance(1);
        }

        // Converge: when both types were read successfully and no per-object
        // migration failed, mark the step done so future upgrades skip it.
        if ($totalFailed === 0 && $totalReadFailed === 0) {
            $this->appConfig->setValueString(Application::APP_ID, self::CONVERGED_CONFIG_KEY, '1');
            $output->info('Contacts migration converged — future runs will skip.');
        }

        $output->finishProgress();
    }//end run()

    /**
     * Migrate every object of one type.
     *
     * @param IOutput $output     The output for progress reporting.
     * @param integer $registerId The voorzieningen register id.
     * @param string  $objectType The object type ('contactpersoon'|'organisatie').
     *
     * @return array{linked:int,created:int,skipped:int,failed:int,readFailed:int} The per-type counters.
     *
     * @spec exclude system-context adoption
     */
    private function migrateType(IOutput $output, int $registerId, string $objectType): array
    {
        $stats = [
            'linked'     => 0,
            'created'    => 0,
            'skipped'    => 0,
            'failed'     => 0,
            'readFailed' => 0,
        ];

        $schemaId = $this->settingsService->getSchemaIdForObjectType($objectType);
        if ($schemaId === null || $schemaId === false) {
            $output->warning(sprintf('Schema id for "%s" not configured — skipping', $objectType));
            return $stats;
        }

        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            $output->warning('OpenRegister ObjectService unavailable — skipping');
            return $stats;
        }

        try {
            // Repair steps run user-less; without elevation OpenRegister RBAC
            // denies the read as 'Anonymous'. The guard keeps compatibility
            // with released OR versions that do not ship runAsSystem() yet.
            $readObjects = function () use ($objectService, $registerId, $schemaId) {
                return $objectService->setRegister($registerId)
                    ->setSchema($schemaId)
                    ->findAll([], false, false);
            };

            if (method_exists($objectService, 'runAsSystem') === true) {
                $objects = $objectService->runAsSystem($readObjects);
            } else {
                $objects = $readObjects();
            }
        } catch (\Throwable $e) {
            $output->warning(sprintf('Could not read "%s" objects: %s', $objectType, $e->getMessage()));
            $this->logger->error(
                '[MigrateContactsToNc] Failed to read objects',
                ['objectType' => $objectType, 'error' => $e->getMessage()]
            );
            $stats['readFailed'] = 1;
            return $stats;
        }//end try

        foreach ($objects as $object) {
            $this->migrateOne(
                objectService: $objectService,
                object: $object,
                registerId: $registerId,
                schemaId: $schemaId,
                objectType: $objectType,
                stats: $stats
            );
        }

        return $stats;
    }//end migrateType()

    /**
     * Migrate a single object: resolve/create its NC Contact and persist the
     * `contactsUid` BEFORE any identity field is cleared (fail-safe).
     *
     * @param object            $objectService The OpenRegister ObjectService.
     * @param mixed             $object        The OpenRegister object entity.
     * @param integer           $registerId    The register id.
     * @param integer           $schemaId      The schema id.
     * @param string            $objectType    The object type.
     * @param array<string,int> $stats         The per-type counters (mutated by reference).
     *
     * @return void
     *
     * @spec exclude system-context adoption
     */
    private function migrateOne(
        object $objectService,
        mixed $object,
        int $registerId,
        int $schemaId,
        string $objectType,
        array &$stats
    ): void {
        try {
            $data = $this->extractData(object: $object);
            $uuid = $this->extractUuid(object: $object, data: $data);

            // Idempotent: already linked to an existing Contact → no-op.
            $existingUid = trim((string) ($data['contactsUid'] ?? ''));
            if ($existingUid !== '' && $this->contactSync->findContactByUid($existingUid) !== null) {
                $stats['skipped']++;
                return;
            }

            // Did the Contact already exist, or are we creating a fresh one?
            $alreadyExisted = ($this->contactSync->findContactForRecord($objectType, $data) !== null);

            $contactsUid = $this->contactSync->syncToContacts($objectType, $data);
            if ($contactsUid === null || $contactsUid === '') {
                // Fail-safe: leave the source object and its identity intact.
                $stats['failed']++;
                $this->logger->warning(
                    '[MigrateContactsToNc] Could not resolve/create a contact; leaving object intact for re-run',
                    ['objectType' => $objectType, 'uuid' => $uuid]
                );
                return;
            }

            // Persist the contactsUid onto the kept record. Identity fields are
            // intentionally left in place here — they are only superseded by
            // the reduced schema once OpenRegister re-imports it, and clearing
            // them before the link is set would risk data loss.
            $data['contactsUid'] = $contactsUid;

            // Repair steps run user-less; without elevation OpenRegister RBAC
            // denies the write as 'Anonymous'. The guard keeps compatibility
            // with released OR versions that do not ship runAsSystem() yet.
            $saveObject = function () use ($objectService, $data, $registerId, $schemaId, $uuid) {
                return $objectService->saveObject(
                    $data,
                    [],
                    $registerId,
                    $schemaId,
                    $uuid,
                    false,
                    false
                );
            };

            if (method_exists($objectService, 'runAsSystem') === true) {
                $objectService->runAsSystem($saveObject);
            } else {
                $saveObject();
            }

            if ($alreadyExisted === true) {
                $stats['linked']++;
            } else {
                $stats['created']++;
            }
        } catch (\Throwable $e) {
            // Fail-safe per-object: never abort the whole migration, never drop data.
            $stats['failed']++;
            $this->logger->error(
                '[MigrateContactsToNc] Failed to migrate object — left intact for re-run',
                ['objectType' => $objectType, 'error' => $e->getMessage()]
            );
        }//end try
    }//end migrateOne()

    /**
     * Extract the object data array from an OpenRegister entity or array.
     *
     * @param mixed $object The OpenRegister object.
     *
     * @return array<string, mixed> The object data.
     */
    private function extractData(mixed $object): array
    {
        if (is_object($object) === true && method_exists($object, 'getObject') === true) {
            return (array) $object->getObject();
        }

        if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
            return (array) $object->jsonSerialize();
        }

        return (array) $object;
    }//end extractData()

    /**
     * Extract the object UUID from an OpenRegister entity or its data.
     *
     * @param mixed                $object The OpenRegister object.
     * @param array<string, mixed> $data   The extracted object data.
     *
     * @return ?string The UUID, or null when unavailable.
     */
    private function extractUuid(mixed $object, array $data): ?string
    {
        if (is_object($object) === true && method_exists($object, 'getUuid') === true) {
            $uuid = $object->getUuid();
            if ($uuid !== null && $uuid !== '') {
                return (string) $uuid;
            }
        }

        if (isset($data['id']) === true && $data['id'] !== '') {
            return (string) $data['id'];
        }

        if (isset($data['@self']['id']) === true && $data['@self']['id'] !== '') {
            return (string) $data['@self']['id'];
        }

        return null;
    }//end extractUuid()
}//end class
