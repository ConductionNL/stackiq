<?php
/**
 * Organization Synchronization Service
 *
 * This file contains the service class for synchronizing organizations and contact persons
 * between SoftwareCatalog objects and OpenRegister entities.
 *
 * @category Service
 * @package  OCA\SoftwareCatalog\Service
 * @author   Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @version  1.0.0
 * @link     https://github.com/ConductionNL/SoftwareCatalog
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Service;

use OCA\OpenRegister\Service\ObjectService;
use OCA\SoftwareCatalog\Service\OrganisatieService;
use OCA\SoftwareCatalog\Service\ContactpersoonService;
use OCA\SoftwareCatalog\Service\SoftwareCatalogue\ContactPersonHandler;
use OCA\SoftwareCatalog\Service\SymfonyEmailService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Service for synchronizing organizations and contact persons
 *
 * This service provides comprehensive synchronization between SoftwareCatalog objects
 * and OpenRegister entities, ensuring data consistency and proper user management.
 *
 * @category Service
 * @package  OCA\SoftwareCatalog\Service
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @version  1.0.0
 * @link     https://github.com/ConductionNL/SoftwareCatalog
 */
class OrganizationSyncService
{
    /**
     * OrganisatieService instance
     *
     * @var OrganisatieService The service for organization operations
     */
    private OrganisatieService $organisatieService;

    /**
     * ContactpersoonService instance
     *
     * @var ContactpersoonService The service for contact person operations
     */
    private ContactpersoonService $contactpersoonService;

    /**
     * SymfonyEmailService instance
     *
     * @var SymfonyEmailService The service for sending emails
     */
    private SymfonyEmailService $emailService;

    /**
     * Configuration service instance
     *
     * @var IAppConfig The Nextcloud app configuration service
     */
    private IAppConfig $config;

    /**
     * Settings service instance
     *
     * @var SettingsService The settings service for configuration
     */
    private SettingsService $settingsService;

    /**
     * Logger instance
     *
     * @var LoggerInterface The logger for sync operations
     */
    private LoggerInterface $logger;

    /**
     * Constructor for OrganizationSyncService
     *
     * @param OrganisatieService      $organisatieService      The organization service
     * @param ContactpersoonService   $contactpersoonService   The contact person service
     * @param SymfonyEmailService     $emailService            The email service
     * @param IAppConfig              $config                  The configuration service
     * @param LoggerInterface         $logger                  The logger instance
     */
    public function __construct(
        OrganisatieService $organisatieService,
        ContactpersoonService $contactpersoonService,
        SymfonyEmailService $emailService,
        IAppConfig $config,
        LoggerInterface $logger,
        SettingsService $settingsService,
        private IDBConnection $db,
        private readonly ContactPersonHandler $contactpersonHandler,
    ) {
        $this->organisatieService = $organisatieService;
        $this->contactpersoonService = $contactpersoonService;
        $this->emailService = $emailService;
        $this->config = $config;
        $this->logger = $logger;
        $this->settingsService = $settingsService;
    }

    /**
     * Create a database-agnostic JSON extraction expression
     *
     * Returns the appropriate SQL function for extracting a value from a JSON column
     * based on the database platform (MySQL vs PostgreSQL).
     *
     * @param string $column The column name containing JSON data
     * @param string $path   The JSON path to extract (e.g., '$.status' or 'status')
     *
     * @return string The SQL expression for JSON extraction
     */
    private function jsonExtract(string $column, string $path): string
    {
        $platform = $this->db->getDatabasePlatform();
        $isPostgres = $platform->getName() === 'postgresql';

        // Normalize path - remove '$.' prefix if present for PostgreSQL
        $cleanPath = ltrim($path, '$.');

        if ($isPostgres) {
            // PostgreSQL: Use ->> operator for text extraction
            // Cast to json first if needed, then extract
            return "({$column}::json->>'{$cleanPath}')";
        }

        // MySQL/MariaDB: Use json_unquote(json_extract())
        $jsonPath = str_starts_with($path, '$.') ? $path : '$.'. $path;
        return "json_unquote(json_extract({$column}, '{$jsonPath}'))";
    }

    /**
     * Create a database-agnostic JSON contains expression
     *
     * Returns the appropriate SQL for checking if a JSON array contains a value.
     *
     * @param string $column The column name containing JSON array
     * @param string $value  The value to check for
     *
     * @return string The SQL expression for JSON contains check
     */
    private function jsonContains(string $column, string $value): string
    {
        $platform = $this->db->getDatabasePlatform();
        $isPostgres = $platform->getName() === 'postgresql';

        if ($isPostgres) {
            // PostgreSQL: Use @> operator with jsonb
            return "({$column}::jsonb @> '\"{$value}\"'::jsonb)";
        }

        // MySQL/MariaDB: Use JSON_CONTAINS
        return "json_contains({$column}, '\"{$value}\"')";
    }

    public function performOrganizationsSync(int $batchSize = 50, int $maxExecutionSeconds = 45): array
    {
        // Check configuration
        $voorzieningenConfig = $this->settingsService->getVoorzieningenConfig();
        $register = $voorzieningenConfig['register'] ?? '';
        $organizationSchema = $voorzieningenConfig['organisatie_schema'] ?? '';
//        $contactSchema = $voorzieningenConfig['contactpersoon_schema'] ?? '';

        $startTime = time();
        $stats = [
            'organizationsProcessed' => 0,
            'entitiesCreated' => 0,
            'entitiesUpdated' => 0,
            'contactPersonsProcessed' => 0,
            'usersCreated' => 0,
            'usersUpdated' => 0,
            'errors' => [],
            'batchSize' => $batchSize,
            'maxExecutionSeconds' => $maxExecutionSeconds,
            'timeoutReached' => false,
            'startTime' => date('Y-m-d H:i:s'),
            'endTime' => null,
            'duration' => null
        ];

        $qb = $this->db->getQueryBuilder();

        // Query to find organizations that need syncing.
        // Note: The 'active' column was removed as it doesn't exist in the openregister_organisations table.
        // Now syncs all non-Concept organizations that either don't have an organisation entity yet,
        // or need to be re-synced based on their updated timestamp.
        $statusExtract = $this->jsonExtract('o.object', '$.status');
        $qb->select('o.uuid', $qb->createFunction("{$statusExtract} as status"), 'o2.uuid as oreg_uuid')
            ->from(from: 'openregister_objects', alias: 'o')
            ->leftJoin(fromAlias:'o', join: 'openregister_organisations', alias: 'o2', condition: 'o.uuid = o2.uuid')
            ->where($qb->expr()->eq('o.schema', $qb->createNamedParameter($organizationSchema)))
            ->andWhere($qb->expr()->eq('o.register', $qb->createNamedParameter($register)))
            ->andWhere($qb->expr()->neq($qb->createFunction("LOWER({$statusExtract})"), $qb->createNamedParameter('concept')))
            ->orderBy('o.updated', 'ASC')  // Process oldest first for consistency.
            ->setMaxResults($batchSize);  // Limit batch size.

        $sql = $qb->getSQL();
        $objects = $qb->execute()->fetchAll();
        $orgs = [];


        foreach($objects as $object) {
            // Check if we're approaching the time limit
            if (time() - $startTime >= $maxExecutionSeconds) {
                $stats['timeoutReached'] = true;
                $this->logger->info('OrganizationSyncService: Execution time limit reached', [
                    'processedCount' => $stats['organizationsProcessed'],
                    'executionTime' => time() - $startTime,
                    'maxExecutionSeconds' => $maxExecutionSeconds,
                    'trigger' => 'batch_processing'
                ]);
                break;
            }

            $objectService = \OC::$server->get('OCA\OpenRegister\Service\ObjectService');
            if($objectService instanceOf ObjectService === false) {
                return [];
            }

            $object = $objectService->find(id: $object['uuid'], register: $register, schema: $organizationSchema);

            $org = $this->ensureOrganisationEntity($object,$stats);


        }

        return $stats;
    }

    public function performContactSync(int $batchSize = 100, int $maxExecutionSeconds = 30) :array
    {
        // Check configuration
        $voorzieningenConfig = $this->settingsService->getVoorzieningenConfig();
        $register = $voorzieningenConfig['register'] ?? '';
//        $organizationSchema = $voorzieningenConfig['organisatie_schema'] ?? '';
        $contactSchema = $voorzieningenConfig['contactpersoon_schema'] ?? '';

        $startTime = time();
        $stats = [
            'organizationsProcessed' => 0,
            'entitiesCreated' => 0,
            'entitiesUpdated' => 0,
            'contactPersonsProcessed' => 0,
            'usersCreated' => 0,
            'usersUpdated' => 0,
            'errors' => [],
            'batchSize' => $batchSize,
            'maxExecutionSeconds' => $maxExecutionSeconds,
            'timeoutReached' => false,
            'startTime' => date('Y-m-d H:i:s'),
            'endTime' => null,
            'duration' => null
        ];

        $qb = $this->db->getQueryBuilder();

        $emailExtract = $this->jsonExtract('o.object', '$.e-mailadres');
        $usernameExtract = $this->jsonExtract('o.object', '$.username');
        $organisatieExtract = $this->jsonExtract('o.object', '$.organisatie');

        $qb->select(
            'o.uuid',
            'a.uid',
            $qb->createFunction("{$emailExtract} as email"),
            $qb->createFunction("{$usernameExtract} as username"),
            'oo.uuid as organisation'
        )
            ->from('openregister_objects', 'o')
            ->leftJoin(
                fromAlias: 'o',
                join: 'accounts_data',
                alias: 'a',
                condition: "{$emailExtract} = a.value")
            ->leftJoin(
                fromAlias: 'o',
                join: 'openregister_organisations',
                alias: 'oo',
                condition: "oo.uuid = {$organisatieExtract}"
            )
            ->where($qb->expr()->eq('o.register', $qb->createNamedParameter($register)))
            ->andWhere($qb->expr()->eq('o.schema', $qb->createNamedParameter($contactSchema)))
            ->andWhere($qb->expr()->isNull($qb->createFunction($usernameExtract)))
            ->orderBy('o.updated', 'ASC')  // Process oldest first
            ->setMaxResults($batchSize);   // Limit batch size

        $contacts = $qb->execute()->fetchAll();

        foreach ($contacts as $contact) {
            // Check if we're approaching the time limit
            if (time() - $startTime >= $maxExecutionSeconds) {
                $stats['timeoutReached'] = true;
                $this->logger->info('OrganizationSyncService: Contact sync time limit reached', [
                    'contactsProcessed' => $stats['contactPersonsProcessed'],
                    'executionTime' => time() - $startTime,
                    'maxExecutionSeconds' => $maxExecutionSeconds,
                    'trigger' => 'batch_processing'
                ]);
                break;
            }

            $objectService = \OC::$server->get('OCA\OpenRegister\Service\ObjectService');
            $contactEntity = $objectService->find(id: $contact['uuid'], register: $register, schema: $contactSchema);
            $contactEntityObject = $contactEntity->getObject();

            if ($contact['organisation'] === null) {
                continue;
            }

            $contactEntityObject['username'] = $contact['uid'];

            if ($contact['uid'] === null) {
                $user = $this->contactpersonHandler->createUserAccount($contactEntity);
                // Check if user was created successfully (can be null if no email).
                if ($user !== null) {
                    $contactEntityObject['username'] = $user->getUID();
                } else {
                    // Skip this contact if user couldn't be created.
                    $this->logger->debug('Skipping contact - user account creation failed (likely no email)', [
                        'app' => 'softwarecatalog',
                        'contactId' => $contactEntity->getId()
                    ]);
                    continue;
                }
            }

            // Remove organisatie field to avoid validation error
            // (it's stored as UUID string but schema expects object type)
            unset($contactEntityObject['organisatie']);

            $contactEntity->setObject($contactEntityObject);
            $objectService->saveObject(object: $contactEntity, register: $register, schema: $contactSchema, _rbac: false, _multitenancy: false);

            $stats['contactPersonsProcessed']++;
        }

        return $stats;
    }


    public function performUserSync(): array
    {
        $voorzieningenConfig = $this->settingsService->getVoorzieningenConfig();
        $register = $voorzieningenConfig['register'] ?? '';
//        $organizationSchema = $voorzieningenConfig['organisatie_schema'] ?? '';
        $contactSchema = $voorzieningenConfig['contactpersoon_schema'] ?? '';


        $qb = $this->db->getQueryBuilder();
        $usernameExtract2 = $this->jsonExtract('o.object', '$.username');
        $organisatieExtract2 = $this->jsonExtract('o.object', '$.organisatie');

        // Build JSON contains check - this is complex and platform-specific
        $platform = $this->db->getDatabasePlatform();
        $isPostgres = $platform->getName() === 'postgresql';

        if ($isPostgres) {
            // PostgreSQL: Check if username is NOT in the users array
            $jsonContainsCheck = "NOT (oo.users::jsonb @> to_jsonb({$usernameExtract2}))";
        } else {
            // MySQL: Use JSON_CONTAINS
            $jsonContainsCheck = "json_contains(oo.users, json_extract(o.object, '$.username')) = 0";
        }

        $qb->select('o.uuid', $qb->createFunction("{$usernameExtract2} as username"), $qb->createFunction("{$organisatieExtract2} as organisation"), 'oo.users')
            ->from(from:'openregister_objects', alias: 'o')
            ->leftJoin(fromAlias: 'o', join: 'openregister_organisations', alias: 'oo', condition: "oo.uuid = {$organisatieExtract2}")
            ->where($qb->expr()->eq('o.register', $qb->createNamedParameter($register)))
            ->andWhere($qb->expr()->eq('o.schema', $qb->createNamedParameter($contactSchema)))
            ->andWhere($qb->createFunction($jsonContainsCheck));

        $sql = $qb->getSQL();
        $users = $qb->execute()->fetchAll();
        foreach($users as $user) {
            $this->organisatieService->addUsersToOrganization($user['organisation'], [$user['username']]);
        }

        return [];
    }


    /**
     * Performs comprehensive organization and contact person synchronization
     *
     * This method synchronizes organisatie objects that have been updated within
     * the specified time window with organisation entities.
     *
     * @param int $minutesBack Number of minutes to look back for changes (0 = all objects)
     *
     * @return array Synchronization results and statistics
     */
    public function performFullSync(int $minutesBack = 10): array
    {
        $this->logger->info('OrganizationSyncService: Starting comprehensive organization synchronization', [
            'minutesBack' => $minutesBack,
            'syncMode' => $minutesBack === 0 ? 'full' : 'incremental'
        ]);

        $stats = [
            'organizationsProcessed' => 0,
            'entitiesCreated' => 0,
            'entitiesUpdated' => 0,
            'contactPersonsProcessed' => 0,
            'usersCreated' => 0,
            'usersUpdated' => 0,
            'errors' => [],
            'startTime' => date('Y-m-d H:i:s'),
            'endTime' => null,
            'duration' => null
        ];

        try {
            // Check configuration
            $voorzieningenConfig = $this->settingsService->getVoorzieningenConfig();
            $register = $voorzieningenConfig['register'] ?? '';
            $organizationSchema = $voorzieningenConfig['organisatie_schema'] ?? '';
            $contactSchema = $voorzieningenConfig['contactpersoon_schema'] ?? '';

            if (empty($register) || empty($organizationSchema)) {
                $error = 'Missing configuration: register or organization schema not configured';
                $this->logger->error('OrganizationSyncService: ' . $error);
                $stats['errors'][] = $error;
                return $stats;
            }

            // Get organisatie objects based on time window
            $organisatieObjects = $this->getOrganisatieObjectsByTimeWindow($register, $organizationSchema, $minutesBack);
            $this->logger->info('OrganizationSyncService: Found organisatie objects', [
                'count' => count($organisatieObjects),
                'minutesBack' => $minutesBack,
                'syncMode' => $minutesBack === 0 ? 'full' : 'incremental'
            ]);

            // Process each organisatie object
            foreach ($organisatieObjects as $organisatieObject) {
                try {
                    $this->processOrganisatieObject($organisatieObject, $register, $contactSchema, $stats);
                    $stats['organizationsProcessed']++;
                } catch (\Exception $e) {
                    $error = 'Failed to process organisatie object ' . $organisatieObject->getId() . ': ' . $e->getMessage();
                    $this->logger->error('OrganizationSyncService: ' . $error, [
                        'exception' => $e,
                        'objectId' => $organisatieObject->getId()
                    ]);
                    $stats['errors'][] = $error;
                }
            }

            $stats['endTime'] = date('Y-m-d H:i:s');
            $startTime = new \DateTime($stats['startTime']);
            $endTime = new \DateTime($stats['endTime']);
            $stats['duration'] = $endTime->diff($startTime)->format('%H:%I:%S');

            $this->logger->info('OrganizationSyncService: Completed comprehensive synchronization', $stats);

            return $stats;

        } catch (\Exception $e) {
            $error = 'Synchronization failed: ' . $e->getMessage();
            $this->logger->error('OrganizationSyncService: ' . $error, [
                'exception' => $e
            ]);
            $stats['errors'][] = $error;
            $stats['endTime'] = date('Y-m-d H:i:s');
            return $stats;
        }
    }

    /**
     * Gets organisatie objects filtered by time window
     *
     * @param string $register The register ID
     * @param string $organizationSchema The organization schema ID
     * @param int $minutesBack Number of minutes to look back (0 = all objects)
     *
     * @return array Array of organisatie objects
     */
    private function getOrganisatieObjectsByTimeWindow(string $register, string $organizationSchema, int $minutesBack): array
    {
        try {
            $objectService = \OC::$server->get('OCA\OpenRegister\Service\ObjectService');

            // Build base query for register and schema
            $query = [
                '@self' => [
                    'register' => (int) $register,
                    'schema' => (int) $organizationSchema
                ]
            ];

            // Add time-based filtering if minutesBack > 0
            if ($minutesBack > 0) {
                $cutoffTime = new \DateTime();
                $cutoffTime->sub(new \DateInterval('PT' . $minutesBack . 'M'));
                $cutoffTimeString = $cutoffTime->format('Y-m-d\TH:i:sP');

                // Add time filtering to the query
                // Filter objects that were updated within the time window
                $query['@self']['updated'] = ['gte' => $cutoffTimeString];

                $this->logger->debug('OrganizationSyncService: Using searchObjects with time-based filtering', [
                    'register' => $register,
                    'schema' => $organizationSchema,
                    'minutesBack' => $minutesBack,
                    'cutoffTime' => $cutoffTimeString,
                    'currentTime' => (new \DateTime())->format('Y-m-d\TH:i:sP'),
                    'timeWindowStart' => $cutoffTimeString,
                    'query' => $query
                ]);
            } else {
                $this->logger->debug('OrganizationSyncService: Using searchObjects for all objects', [
                    'register' => $register,
                    'schema' => $organizationSchema,
                    'query' => $query
                ]);
            }

            // Use searchObjects method for filtering
            $objects = $objectService->searchObjects($query);

            $this->logger->debug('OrganizationSyncService: Retrieved organisatie objects with searchObjects', [
                'register' => $register,
                'schema' => $organizationSchema,
                'minutesBack' => $minutesBack,
                'count' => count($objects)
            ]);

            return $objects;
        } catch (\Exception $e) {
            $this->logger->error('OrganizationSyncService: Failed to retrieve organisatie objects with searchObjects', [
                'register' => $register,
                'schema' => $organizationSchema,
                'minutesBack' => $minutesBack,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [];
        }
    }

    /**
     * Processes a single organisatie object
     *
     * @param object $organisatieObject The organisatie object to process
     * @param string $register The register ID
     * @param string $contactSchema The contact schema ID
     * @param array &$stats Statistics array (passed by reference)
     *
     * @return void
     */
    private function processOrganisatieObject(object $organisatieObject, string $register, string $contactSchema, array &$stats): void
    {
        $objectData = $organisatieObject->getObject();
        $organisatieId = $objectData['id'] ?? $organisatieObject->getId();

        $this->logger->debug('OrganizationSyncService: Processing organisatie object', [
            'organisatieId' => $organisatieId,
            'naam' => $objectData['naam'] ?? 'Unknown'
        ]);

        // Step 1: Ensure organisation entity exists
        $organisationEntity = $this->ensureOrganisationEntity($organisatieObject, $stats);
        if (!$organisationEntity) {
            $this->logger->warning('OrganizationSyncService: Could not ensure organisation entity', [
                'organisatieId' => $organisatieId
            ]);
            return;
        }

        // Step 2: Get all contact persons for this organisation
        $contactPersons = $this->getContactPersonsForOrganisation($organisatieId, $register, $contactSchema);
        $this->logger->debug('OrganizationSyncService: Found contact persons', [
            'organisatieId' => $organisatieId,
            'contactCount' => count($contactPersons)
        ]);

        // Step 3: Process each contact person to ensure they have user accounts
        $usernames = [];
        foreach ($contactPersons as $contactPerson) {
            $username = $this->processContactPerson($contactPerson, $stats);
            if ($username) {
                $usernames[] = $username;
            }
        }

        // Step 4: Update organisation entity with all usernames
        $this->updateOrganisationEntityUsers($organisationEntity, $usernames, $stats);
    }

    /**
     * Ensures organisation entity exists for organisatie object
     *
     * @param object $organisatieObject The organisatie object
     * @param array &$stats Statistics array (passed by reference)
     *
     * @return object|null The organisation entity or null on failure
     */
    private function ensureOrganisationEntity(object $organisatieObject, array &$stats): ?object
    {
        try {
            // Get the full object data - the passed object might not have all fields populated
            $organisatieId = $organisatieObject->getUuid();
            
            // Fetch the complete object from the database to ensure we have all data
            try {
                $objectService = \OC::$server->get('OCA\OpenRegister\Service\ObjectService');
                $fullObject = $objectService->find(
                    id: $organisatieId,
                    register: $organisatieObject->getRegister(),
                    schema: $organisatieObject->getSchema()
                );
                if ($fullObject) {
                    $organisatieObject = $fullObject;
                }
            } catch (\Exception $e) {
                $this->logger->warning('Could not fetch full organisation object, using provided object', [
                    'organisatieId' => $organisatieId,
                    'error' => $e->getMessage()
                ]);
            }
            
            $objectData = $organisatieObject->getObject();

            $this->logger->critical('🔍 ENSURING ORGANISATION ENTITY', [
                'app' => 'softwarecatalog',
                'organisatieId' => $organisatieId,
                'naam' => $objectData['naam'] ?? $objectData['name'] ?? 'Unknown',
                'status' => $objectData['status'] ?? 'Unknown',
                'objectDataKeys' => array_keys($objectData)
            ]);

            // Get configuration for object updates
            $voorzieningenConfig = $this->settingsService->getVoorzieningenConfig();
            $register = $voorzieningenConfig['register'] ?? '';
            $organizationSchema = $voorzieningenConfig['organisatie_schema'] ?? '';

            // Try to find existing organisation entity
            $organisationMapper = \OC::$server->get('OCA\OpenRegister\Db\OrganisationMapper');

            try {
                $organisationEntity = $organisationMapper->findByUuid($organisatieId);

                // Entity exists - update it if needed
                $status = strtolower($objectData['status'] ?? 'actief');
                $shouldBeActive = in_array($status, ['actief', 'active']);

                $this->logger->critical('📋 EXISTING ENTITY FOUND', [
                    'app' => 'softwarecatalog',
                    'organisatieId' => $organisatieId,
                    'entityId' => $organisationEntity->getId(),
                    'currentActive' => $organisationEntity->getActive(),
                    'shouldBeActive' => $shouldBeActive,
                    'needsUpdate' => $organisationEntity->getActive() !== $shouldBeActive
                ]);

                if ($organisationEntity->getActive() !== $shouldBeActive) {
                    $this->logger->critical('🔄 UPDATING ENTITY STATUS', [
                        'app' => 'softwarecatalog',
                        'organisatieId' => $organisatieId,
                        'oldActive' => $organisationEntity->getActive(),
                        'newActive' => $shouldBeActive
                    ]);

                    $wasActive = $organisationEntity->getActive();
                    $organisationEntity->setActive($shouldBeActive);
                    $organisationMapper->save($organisationEntity);
                    $stats['entitiesUpdated']++;

                    // Send activation email if organization became active
                    if ($shouldBeActive && !$wasActive) {
                        $this->logger->info('[FLOW] Sending organization activation email', [
                            'organisatieId' => $organisatieId
                        ]);
                        $emailSent = $this->sendOrganizationActivationEmail($objectData);
                        if ($emailSent) {
                            $this->logger->info('📧 Organization activation email sent successfully', [
                                'organisatieId' => $organisatieId
                            ]);
                        } else {
                            $this->logger->info('📧 Organization activation email not sent (disabled or not configured)', [
                                'organisatieId' => $organisatieId
                            ]);
                        }
                    }
                }

                // Update organisatie object owner to organisation entity UUID
                $this->updateOrganisatieObjectOwner($organisatieObject, $organisationEntity, $register, $organizationSchema);

                return $organisationEntity;

            } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
                // Entity doesn't exist, create it
                $this->logger->critical('🆕 CREATING NEW ORGANISATION ENTITY', [
                    'app' => 'softwarecatalog',
                    'organisatieId' => $organisatieId,
                    'naam' => $objectData['naam'] ?? 'Unknown'
                ]);

                $organisationEntity = $this->organisatieService->createOrganisationInOpenRegister($objectData);
                if ($organisationEntity) {
                    $stats['entitiesCreated']++;
                    $this->logger->critical('🎊 NEW ORGANISATION ENTITY CREATED', [
                        'app' => 'softwarecatalog',
                        'organisatieId' => $organisatieId,
                        'entityId' => $organisationEntity->getId(),
                        'active' => $organisationEntity->getActive(),
                        'name' => $organisationEntity->getName()
                    ]);

                    // Send registration email for new organization
                    $this->logger->info('[FLOW] Sending organization registration email', [
                        'organisatieId' => $organisatieId
                    ]);
                    $emailSent = $this->sendOrganizationRegistrationEmail($objectData);
                    if ($emailSent) {
                        $this->logger->info('📧 Organization registration email sent successfully', [
                            'organisatieId' => $organisatieId
                        ]);
                    } else {
                        $this->logger->info('📧 Organization registration email not sent (disabled or not configured)', [
                            'organisatieId' => $organisatieId
                        ]);
                    }

                    // Update organisatie object owner to organisation entity UUID
                    $this->updateOrganisatieObjectOwner($organisatieObject, $organisationEntity, $register, $organizationSchema);
                } else {
                    $this->logger->error('❌ ORGANISATION ENTITY CREATION FAILED', [
                        'app' => 'softwarecatalog',
                        'organisatieId' => $organisatieId
                    ]);
                }
                return $organisationEntity;
            }

        } catch (\Exception $e) {
            $this->logger->error('💥 ENSURE ORGANISATION ENTITY EXCEPTION', [
                'app' => 'softwarecatalog',
                'organisatieId' => $organisatieObject->getId(),
                'exception' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }

    /**
     * Safely sends organization registration email with error handling
     *
     * @param array $organizationData The organization data
     * @return bool True if email was sent successfully, false otherwise
     */
    private function sendOrganizationRegistrationEmail(array $organizationData): bool
    {
        try {
            return $this->emailService->sendOrganizationRegistrationEmail($organizationData);
        } catch (\Exception $e) {
            $this->logger->warning('OrganizationSyncService: Organization registration email failed', [
                'organizationId' => $organizationData['id'] ?? 'unknown',
                'organizationName' => $organizationData['naam'] ?? 'unknown',
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Safely sends organization activation email with error handling
     *
     * @param array $organizationData The organization data
     * @return bool True if email was sent successfully, false otherwise
     */
    private function sendOrganizationActivationEmail(array $organizationData): bool
    {
        try {
            return $this->emailService->sendOrganizationActivationEmail($organizationData);
        } catch (\Exception $e) {
            $this->logger->warning('OrganizationSyncService: Organization activation email failed', [
                'organizationId' => $organizationData['id'] ?? 'unknown',
                'organizationName' => $organizationData['naam'] ?? 'unknown',
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Gets all contact persons for a specific organisation
     *
     * @param string $organisatieId The organisation ID
     * @param string $register The register ID
     * @param string $contactSchema The contact schema ID
     *
     * @return array Array of contact person objects
     */
    private function getContactPersonsForOrganisation(string $organisatieId, string $register, string $contactSchema): array
    {
        if (empty($contactSchema)) {
            $this->logger->debug('OrganizationSyncService: No contact schema configured, skipping contact person lookup');
            return [];
        }

        try {
            $objectService = \OC::$server->get('OCA\OpenRegister\Service\ObjectService');

            // Use searchObjects for more efficient filtering on-demand
            $query = [
                '@self' => [
                    'register' => (int) $register,
                    'schema' => (int) $contactSchema
                ],
                'organisatie' => $organisatieId
            ];

            $contactPersons = $objectService->searchObjects($query);

            $this->logger->debug('OrganizationSyncService: Retrieved contact persons on-demand', [
                'organisatieId' => $organisatieId,
                'register' => $register,
                'schema' => $contactSchema,
                'count' => count($contactPersons)
            ]);

            return $contactPersons;

        } catch (\Exception $e) {
            $this->logger->error('OrganizationSyncService: Failed to get contact persons', [
                'organisatieId' => $organisatieId,
                'register' => $register,
                'schema' => $contactSchema,
                'exception' => $e
            ]);
            return [];
        }
    }

    /**
     * Processes a contact person to ensure they have a user account
     *
     * @param object $contactPerson The contact person object
     * @param array &$stats Statistics array (passed by reference)
     *
     * @return string|null The username if successful, null otherwise
     */
    private function processContactPerson(object $contactPerson, array &$stats): ?string
    {
        try {
            $contactData = $contactPerson->getObject();
            // Schema uses 'e-mailadres' but some data may use 'email'
            $email = $contactData['email'] ?? $contactData['e-mailadres'] ?? '';
            $existingUsername = $contactData['username'] ?? '';

            if (empty($email)) {
                $this->logger->debug('OrganizationSyncService: Contact person has no email, skipping', [
                    'contactId' => $contactPerson->getId()
                ]);
                return null;
            }

            // Check if user already exists
            $userManager = \OC::$server->get('OCP\IUserManager');
            $username = $existingUsername ?: $email;
            $user = $userManager->get($username);

            if (!$user) {
                // Create user account
                $this->logger->info('OrganizationSyncService: Creating user account for contact person', [
                    'contactId' => $contactPerson->getId(),
                    'email' => $email,
                    'username' => $username
                ]);

                $success = $this->contactpersoonService->processContactpersoon($contactPerson, false);
                if ($success) {
                    $stats['usersCreated']++;
                    $this->logger->info('OrganizationSyncService: Successfully created user account', [
                        'contactId' => $contactPerson->getId(),
                        'username' => $username
                    ]);
                    return $username;
                } else {
                    $this->logger->error('OrganizationSyncService: Failed to create user account', [
                        'contactId' => $contactPerson->getId(),
                        'username' => $username
                    ]);
                    return null;
                }
            } else {
                // User exists, update username in contact if needed
                if (empty($existingUsername)) {
                    $this->logger->debug('OrganizationSyncService: Updating contact person with username', [
                        'contactId' => $contactPerson->getId(),
                        'username' => $username
                    ]);
                    $stats['usersUpdated']++;
                }
                return $username;
            }

        } catch (\Exception $e) {
            $this->logger->error('OrganizationSyncService: Failed to process contact person', [
                'contactId' => $contactPerson->getId(),
                'exception' => $e
            ]);
            return null;
        }
    }

    /**
     * Updates organisation entity with all usernames
     *
     * @param object $organisationEntity The organisation entity
     * @param array $usernames Array of usernames to add
     * @param array &$stats Statistics array (passed by reference)
     *
     * @return void
     */
    private function updateOrganisationEntityUsers(object $organisationEntity, array $usernames, array &$stats): void
    {
        try {
            $organisationUuid = $organisationEntity->getUuid();
            $currentUsers = $organisationEntity->getUsers() ?? [];

            // Add admin users to ensure they're always included
            $adminUsers = $this->getAdminUsers();
            $allUsernames = array_unique(array_merge($usernames, $adminUsers));

            // Check if users list has changed
            $currentUsersSet = array_unique($currentUsers);
            sort($currentUsersSet);
            sort($allUsernames);

            if ($currentUsersSet !== $allUsernames) {
                $this->logger->info('OrganizationSyncService: Updating organisation entity users', [
                    'organisationUuid' => $organisationUuid,
                    'currentUsers' => count($currentUsers),
                    'newUsers' => count($allUsernames),
                    'addedUsers' => array_diff($allUsernames, $currentUsers),
                    'removedUsers' => array_diff($currentUsers, $allUsernames)
                ]);

                $organisationEntity->setUsers($allUsernames);

                $organisationMapper = \OC::$server->get('OCA\OpenRegister\Db\OrganisationMapper');
                $organisationMapper->save($organisationEntity);

                $stats['entitiesUpdated']++;

                $this->logger->info('OrganizationSyncService: Successfully updated organisation entity users', [
                    'organisationUuid' => $organisationUuid,
                    'totalUsers' => count($allUsernames)
                ]);
            } else {
                $this->logger->debug('OrganizationSyncService: Organisation entity users unchanged', [
                    'organisationUuid' => $organisationUuid,
                    'userCount' => count($allUsernames)
                ]);
            }

        } catch (\Exception $e) {
            $this->logger->error('OrganizationSyncService: Failed to update organisation entity users', [
                'organisationUuid' => $organisationEntity->getUuid(),
                'exception' => $e
            ]);
        }
    }

    /**
     * Gets admin users that should always be included in organizations
     *
     * @return array Array of admin usernames
     */
    private function getAdminUsers(): array
    {
        try {
            $groupManager = \OC::$server->get('OCP\IGroupManager');
            $adminGroup = $groupManager->get('admin');

            if ($adminGroup) {
                $adminUsers = $adminGroup->getUsers();
                $adminUsernames = [];
                foreach ($adminUsers as $user) {
                    $adminUsernames[] = $user->getUID();
                }
                return $adminUsernames;
            }

            return [];
        } catch (\Exception $e) {
            $this->logger->error('OrganizationSyncService: Failed to get admin users', [
                'exception' => $e
            ]);
            return [];
        }
    }

    /**
     * Performs a quick sync status check with prediction of objects to be processed
     *
     * @param int $minutesBack Number of minutes to look back for prediction (default: 10 for scheduled sync)
     *
     * @return array Status information about sync requirements including processing predictions
     */
    public function getSyncStatus(int $minutesBack = 10): array
    {
        try {
            // Check configuration
            $voorzieningenConfig = $this->settingsService->getVoorzieningenConfig();
            $register = $voorzieningenConfig['register'] ?? '';
            $organizationSchema = $voorzieningenConfig['organisatie_schema'] ?? '';
            $contactSchema = $voorzieningenConfig['contactpersoon_schema'] ?? '';

            if (empty($register) || empty($organizationSchema)) {
                return [
                    'configured' => false,
                    'message' => 'Sync not configured: missing register or organization schema'
                ];
            }

            // Get total counts (all objects)
            $allOrganisatieObjects = $this->getOrganisatieObjectsByTimeWindow($register, $organizationSchema, 0);

            // Get incremental counts (objects to be processed in next sync)
            $incrementalOrganisatieObjects = $this->getOrganisatieObjectsByTimeWindow($register, $organizationSchema, $minutesBack);

            // Get organization entities count
            $organisationMapper = \OC::$server->get('OCA\OpenRegister\Db\OrganisationMapper');
            $entitiesCount = 0;
            try {
                $entities = $organisationMapper->findAllWithUserCount();
                $entitiesCount = count($entities);
            } catch (\Exception $e) {
                // Ignore errors in count
            }

            // Predict contact persons to be processed
            $predictedContactPersonsToProcess = 0;
            if (!empty($contactSchema)) {
                foreach ($incrementalOrganisatieObjects as $orgObject) {
                    $objectData = $orgObject->getObject();
                    $organisatieId = $objectData['id'] ?? $orgObject->getId();
                    $contactPersons = $this->getContactPersonsForOrganisation($organisatieId, $register, $contactSchema);
                    $predictedContactPersonsToProcess += count($contactPersons);
                }
            }

            // Calculate efficiency metrics
            $efficiencyImprovement = count($allOrganisatieObjects) > 0
                ? round((1 - (count($incrementalOrganisatieObjects) / count($allOrganisatieObjects))) * 100, 1)
                : 0;

            return [
                'configured' => true,
                'syncMode' => $minutesBack === 0 ? 'full' : 'incremental',
                'timeWindow' => $minutesBack,

                // Total counts
                'totalOrganizationObjects' => count($allOrganisatieObjects),
                'totalOrganizationEntities' => $entitiesCount,

                // Processing predictions
                'organizationsToProcess' => count($incrementalOrganisatieObjects),
                'contactPersonsToProcess' => $predictedContactPersonsToProcess,

                // Efficiency metrics
                'efficiencyImprovement' => $efficiencyImprovement . '%',
                'processingReduction' => count($allOrganisatieObjects) - count($incrementalOrganisatieObjects),

                // Configuration
                'contactSchemaConfigured' => !empty($contactSchema),
                'lastSyncTime' => $this->config->getValueString('softwarecatalog', 'last_sync_time', 'Never'),

                // Email configuration status
                'emailStatus' => $this->getEmailConfigurationStatus(),

                // Status messages
                'message' => count($incrementalOrganisatieObjects) > 0
                    ? "Ready to process {$this->formatNumber(count($incrementalOrganisatieObjects))} organizations and {$this->formatNumber($predictedContactPersonsToProcess)} contact persons"
                    : 'No organizations to process in the current time window',
                'nextScheduledSync' => $minutesBack > 0
                    ? "Will process organizations updated in the last {$minutesBack} minutes"
                    : 'Will process all organizations (full sync)'
            ];

        } catch (\Exception $e) {
            return [
                'configured' => false,
                'message' => 'Error checking sync status: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Gets email configuration status for sync reporting
     *
     * @return array Email configuration status
     */
    private function getEmailConfigurationStatus(): array
    {
        try {
            return $this->emailService->isEmailSystemConfigured();
        } catch (\Exception $e) {
            $this->logger->warning('OrganizationSyncService: Failed to check email configuration status', [
                'error' => $e->getMessage()
            ]);
            return [
                'configured' => false,
                'reason' => 'Error checking email configuration: ' . $e->getMessage(),
                'hasCredentials' => false,
                'hasTemplates' => false
            ];
        }
    }

    /**
     * Format numbers for better readability
     *
     * @param int $number The number to format
     *
     * @return string Formatted number
     */
    private function formatNumber(int $number): string
    {
        if ($number >= 1000) {
            return number_format($number / 1000, 1) . 'k';
        }
        return (string) $number;
    }

    /**
     * Records the last sync time
     *
     * @return void
     */
    public function recordSyncTime(): void
    {
        $this->config->setValueString('softwarecatalog', 'last_sync_time', date('Y-m-d H:i:s'));
    }

    /**
     * Process a specific organization object (called from event listener)
     *
     * This method processes a single organization object, creating or updating
     * the corresponding organization entity as needed.
     *
     * @param \OCA\OpenRegister\Db\ObjectEntity $organizationObject The organization object to process
     *
     * @return array Processing results
     */
    public function processSpecificOrganization($organizationObject): array
    {
        $startTime = microtime(true);
        $stats = [
            'organizationsProcessed' => 0,
            'entitiesCreated' => 0,
            'entitiesUpdated' => 0,
            'contactPersonsProcessed' => 0,
            'usersCreated' => 0,
            'errors' => [],
            'startTime' => date('Y-m-d H:i:s')
        ];

        try {
            $objectData = $organizationObject->getObject();
            $organizationUuid = $organizationObject->getUuid();

            $this->logger->critical('🏢 ORGANIZATION PROCESSING STARTED', [
                'app' => 'softwarecatalog',
                'trigger' => 'ObjectCreatedEvent',
                'organizationId' => $organizationUuid,
                'organizationName' => $objectData['naam'] ?? 'Unknown',
                'organizationStatus' => $objectData['status'] ?? 'Unknown',
                'timestamp' => date('Y-m-d H:i:s'),
                'microtime' => microtime(true)
            ]);



            // Process organization entity
            $this->logger->info('[FLOW] Step 1: Creating/updating organisation entity', [
                'organizationId' => $organizationUuid,
                'action' => 'ensure_organisation_entity'
            ]);

            $organisationEntity = $this->ensureOrganisationEntity($organizationObject, $stats);

            if ($organisationEntity) {
                $stats['organizationsProcessed']++;

                $this->logger->critical('✅ ORGANISATION ENTITY CREATED/UPDATED', [
                    'app' => 'softwarecatalog',
                    'organizationUuid' => $organizationUuid,
                    'entityId' => $organisationEntity->getId(),
                    'entityActive' => $organisationEntity->getActive(),
                    'entitiesCreated' => $stats['entitiesCreated'],
                    'entitiesUpdated' => $stats['entitiesUpdated']
                ]);

                // Step 1.5: Process nested contact persons (from registration form data)
                $this->logger->info('[FLOW] Step 1.5: Processing nested contactpersonen from organization data', [
                    'organizationId' => $organizationUuid,
                    'action' => 'process_nested_contactpersonen'
                ]);

                $this->processNestedContactPersons($organizationObject, $stats);

                // Step 2: Find and process related contactpersonen objects (separate objects, not nested)
                $this->logger->info('[FLOW] Step 2: Finding related contactpersoon objects', [
                    'organizationId' => $organizationUuid,
                    'action' => 'process_related_contactpersonen'
                ]);

                $this->processRelatedContactPersons($organizationUuid, $stats);

            } else {
                $this->logger->error('❌ ORGANISATION ENTITY FAILED', [
                    'app' => 'softwarecatalog',
                    'organizationUuid' => $organizationUuid,
                    'error' => 'Failed to create/update organisation entity'
                ]);
                $stats['errors'][] = 'Failed to create/update organisation entity';
            }

            $stats['endTime'] = date('Y-m-d H:i:s');
            $stats['duration'] = round(microtime(true) - $startTime, 3);

            $this->logger->critical('🏁 ORGANIZATION PROCESSING COMPLETED', [
                'app' => 'softwarecatalog',
                'organizationId' => $organizationUuid,
                'stats' => $stats,
                'processingTime' => $stats['duration'] . 's'
            ]);

            return $stats;

        } catch (\Exception $e) {
            $stats['errors'][] = $e->getMessage();
            $this->logger->error('💥 ORGANIZATION PROCESSING EXCEPTION', [
                'app' => 'softwarecatalog',
                'organizationId' => $organizationObject->getUuid(),
                'exception' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);

            return $stats;
        }
    }

    /**
     * Process nested contact persons within an organization object
     *
     * @param \OCA\OpenRegister\Db\ObjectEntity $organizationObject The organization object containing contact persons
     * @param array $stats The statistics array to update
     * @return void
     */
    private function processNestedContactPersons($organizationObject, array &$stats): void
    {
        try {
            $objectData = $organizationObject->getObject();
            $organizationUuid = $organizationObject->getUuid();

            // Check if organization has nested contact persons
            $contactPersons = $objectData['contactpersonen'] ?? $objectData['contactPersons'] ?? [];

            if (empty($contactPersons)) {
                $this->logger->info('[FLOW] No nested contact persons found in organization', [
                    'organizationId' => $organizationUuid
                ]);
                return;
            }

            $this->logger->critical('👥 PROCESSING NESTED CONTACT PERSONS', [
                'app' => 'softwarecatalog',
                'organizationId' => $organizationUuid,
                'contactCount' => count($contactPersons)
            ]);

            // Get configuration for contact person creation
            $voorzieningenConfig = $this->settingsService->getVoorzieningenConfig();
            $register = $voorzieningenConfig['register'] ?? '';
            $contactSchema = $voorzieningenConfig['contactpersoon_schema'] ?? '';

            if (empty($register) || empty($contactSchema)) {
                $this->logger->warning('[FLOW] Contact person processing skipped - configuration missing', [
                    'organizationId' => $organizationUuid,
                    'register' => $register,
                    'contactSchema' => $contactSchema
                ]);
                return;
            }

            foreach ($contactPersons as $index => $contactData) {
                try {
                    // Handle UUID references - if contactData is a string (UUID), fetch the actual object
                    if (is_string($contactData)) {
                        $this->logger->info('[FLOW] Contact person is a UUID reference, fetching object', [
                            'organizationId' => $organizationUuid,
                            'contactIndex' => $index,
                            'contactUuid' => $contactData
                        ]);

                        // Fetch the contact person object using the UUID
                        $objectService = \OC::$server->get('OCA\OpenRegister\Service\ObjectService');
                        $contactObject = $objectService->find(
                            id: $contactData,
                            register: $register,
                            schema: $contactSchema,
                            _rbac: false,
                            _multitenancy: false
                        );

                        if ($contactObject === null) {
                            $this->logger->warning('[FLOW] Contact person not found by UUID', [
                                'organizationId' => $organizationUuid,
                                'contactUuid' => $contactData
                            ]);
                            continue;
                        }

                        // Get the object data as array
                        $contactData = $contactObject instanceof \OCA\OpenRegister\Db\ObjectEntity
                            ? $contactObject->getObject()
                            : (is_array($contactObject) ? $contactObject : []);

                        // Add the UUID if not present
                        if (!isset($contactData['id']) && $contactObject instanceof \OCA\OpenRegister\Db\ObjectEntity) {
                            $contactData['id'] = $contactObject->getUuid();
                        }
                    }

                    $this->logger->info('[FLOW] Processing nested contact person', [
                        'organizationId' => $organizationUuid,
                        'contactIndex' => $index,
                        'contactEmail' => $contactData['email'] ?? $contactData['e-mailadres'] ?? 'unknown'
                    ]);

                    // Create contact person object in OpenRegister if it doesn't exist
                    $this->createOrUpdateContactPersonObject($contactData, $organizationUuid, $register, $contactSchema, $stats);

                } catch (\Exception $e) {
                    $this->logger->error('[FLOW] Failed to process nested contact person', [
                        'organizationId' => $organizationUuid,
                        'contactIndex' => $index,
                        'exception' => $e->getMessage()
                    ]);
                    $stats['errors'][] = "Contact person {$index}: " . $e->getMessage();
                }
            }

        } catch (\Exception $e) {
            $this->logger->error('[FLOW] Failed to process nested contact persons', [
                'organizationId' => $organizationObject->getUuid(),
                'exception' => $e->getMessage()
            ]);
            $stats['errors'][] = 'Failed to process nested contact persons: ' . $e->getMessage();
        }
    }

    /**
     * Process related contactpersoon objects that have this organization in their organisation property
     *
     * @param string $organizationUuid The organization UUID to find related contacts for
     * @param array $stats The statistics array to update
     * @return void
     */
    private function processRelatedContactPersons(string $organizationUuid, array &$stats): void
    {
        try {
            $this->logger->critical('🔍 FINDING RELATED CONTACT PERSONS', [
                'app' => 'softwarecatalog',
                'organizationId' => $organizationUuid,
                'action' => 'find_related_contacts'
            ]);

            // Get configuration
            $voorzieningenConfig = $this->settingsService->getVoorzieningenConfig();
            $register = $voorzieningenConfig['register'] ?? '';
            $contactSchema = $voorzieningenConfig['contactpersoon_schema'] ?? '';

            if (empty($register) || empty($contactSchema)) {
                $this->logger->warning('[FLOW] Related contact processing skipped - configuration missing', [
                    'organizationId' => $organizationUuid,
                    'register' => $register,
                    'contactSchema' => $contactSchema
                ]);
                return;
            }

            // Find all contactpersoon objects that have this organization in their organisation property
            $objectService = \OC::$server->get('OCA\OpenRegister\Service\ObjectService');

            // Search for contactpersoon objects with this organization reference
            // Try both 'organisatie' and 'organisation' field names
            $query = [
                '@self' => [
                    'register' => (int) $register,
                    'schema' => (int) $contactSchema
                ],
                'organisatie' => $organizationUuid
            ];

            $this->logger->critical('[FLOW] Searching for related contact persons with organisatie field', [
                'organizationId' => $organizationUuid,
                'register' => $register,
                'contactSchema' => $contactSchema,
                'query' => $query
            ]);

            $relatedContacts = $objectService->searchObjects($query);
            
            // If not found, try with 'organisation' field
            if (empty($relatedContacts)) {
                $query['organisation'] = $organizationUuid;
                unset($query['organisatie']);
                
                $this->logger->critical('[FLOW] Retrying search with organisation field', [
                    'organizationId' => $organizationUuid,
                    'query' => $query
                ]);
                
                $relatedContacts = $objectService->searchObjects($query);
            }

            if (empty($relatedContacts)) {
                $this->logger->info('[FLOW] No related contact persons found', [
                    'organizationId' => $organizationUuid
                ]);
                return;
            }

            $this->logger->critical('👥 PROCESSING RELATED CONTACT PERSONS', [
                'app' => 'softwarecatalog',
                'organizationId' => $organizationUuid,
                'contactCount' => count($relatedContacts)
            ]);

            foreach ($relatedContacts as $contactObject) {
                try {
                    $contactUuid = $contactObject->getUuid();
                    $contactData = $contactObject->getObject();

                    // Check if contact data is complete (has email)
                    $email = $contactData['email'] ?? $contactData['e-mailadres'] ?? '';
                    if (empty($email) && !empty($contactUuid)) {
                        // Re-fetch the full contact object if email is missing
                        $this->logger->info('[FLOW] Contact data incomplete, re-fetching full object', [
                            'organizationId' => $organizationUuid,
                            'contactId' => $contactUuid
                        ]);

                        try {
                            $fullContactObject = $objectService->find(
                                id: $contactUuid,
                                register: $register,
                                schema: $contactSchema,
                                _rbac: false,
                                _multitenancy: false
                            );
                            if ($fullContactObject !== null) {
                                $contactObject = $fullContactObject;
                                $contactData = $contactObject->getObject();
                                $email = $contactData['email'] ?? $contactData['e-mailadres'] ?? '';
                            }
                        } catch (\Exception $e) {
                            $this->logger->warning('[FLOW] Failed to re-fetch contact object', [
                                'organizationId' => $organizationUuid,
                                'contactId' => $contactUuid,
                                'exception' => $e->getMessage()
                            ]);
                        }
                    }

                    $this->logger->info('[FLOW] Processing related contact person', [
                        'organizationId' => $organizationUuid,
                        'contactId' => $contactUuid,
                        'contactEmail' => $email ?: 'unknown'
                    ]);

                    // Process the contact person through processSpecificContactPerson
                    $contactStats = $this->processSpecificContactPerson($contactObject);

                    // Merge stats
                    $stats['contactPersonsProcessed'] += $contactStats['contactPersonsProcessed'] ?? 0;
                    $stats['usersCreated'] += $contactStats['usersCreated'] ?? 0;
                    $stats['usersUpdated'] += $contactStats['usersUpdated'] ?? 0;
                    if (!empty($contactStats['errors'])) {
                        $stats['errors'] = array_merge($stats['errors'], $contactStats['errors']);
                    }

                } catch (\Exception $e) {
                    $this->logger->error('[FLOW] Failed to process related contact person', [
                        'organizationId' => $organizationUuid,
                        'contactId' => $contactObject->getUuid(),
                        'exception' => $e->getMessage()
                    ]);
                    $stats['errors'][] = "Related contact {$contactObject->getUuid()}: " . $e->getMessage();
                }
            }

        } catch (\Exception $e) {
            $this->logger->error('[FLOW] Failed to process related contact persons', [
                'organizationId' => $organizationUuid,
                'exception' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            $stats['errors'][] = 'Failed to process related contact persons: ' . $e->getMessage();
        }
    }

    /**
     * Create or update a contact person object and user account
     *
     * @param array $contactData The contact person data
     * @param string $organizationUuid The organization UUID
     * @param string $register The register ID
     * @param string $contactSchema The contact schema ID
     * @param array $stats The statistics array to update
     * @return void
     */
    private function createOrUpdateContactPersonObject(array $contactData, string $organizationUuid, string $register, string $contactSchema, array &$stats): void
    {
        try {
            $objectService = \OC::$server->get('OCA\OpenRegister\Service\ObjectService');

            $email = $contactData['email'] ?? $contactData['e-mailadres'] ?? '';
            if (empty($email)) {
                $this->logger->warning('[FLOW] Contact person has no email, skipping', [
                    'organizationId' => $organizationUuid,
                    'contactData' => array_keys($contactData)
                ]);
                return;
            }

            // Check if contact already exists (has id from cascading or previous creation)
            $existingContactId = $contactData['id'] ?? $contactData['uuid'] ?? null;
            $contactObject = null;

            if ($existingContactId) {
                // Contact already exists - fetch it instead of trying to re-create
                $this->logger->info('📧 FETCHING EXISTING CONTACT PERSON', [
                    'app' => 'softwarecatalog',
                    'organizationId' => $organizationUuid,
                    'contactId' => $existingContactId,
                    'email' => $email
                ]);

                try {
                    $contactObject = $objectService->find(
                        id: $existingContactId,
                        register: $register,
                        schema: $contactSchema,
                        _rbac: false,
                        _multitenancy: false
                    );
                } catch (\Exception $e) {
                    $this->logger->warning('[FLOW] Could not fetch existing contact, will create new', [
                        'organizationId' => $organizationUuid,
                        'contactId' => $existingContactId,
                        'exception' => $e->getMessage()
                    ]);
                }
            }

            // If contact doesn't exist, create it (but don't set organisatie as string - it's handled by inversedBy)
            if ($contactObject === null) {
                $this->logger->critical('📧 CREATING NEW CONTACT PERSON OBJECT', [
                    'app' => 'softwarecatalog',
                    'organizationId' => $organizationUuid,
                    'email' => $email,
                    'name' => ($contactData['voornaam'] ?? '') . ' ' . ($contactData['achternaam'] ?? '')
                ]);

                // Remove organisatie from contactData to avoid validation error
                // The relationship is handled by the inversedBy configuration
                unset($contactData['organisatie']);
                unset($contactData['id']);
                unset($contactData['uuid']);

                $contactObject = $objectService->saveObject(
                    object: $contactData,
                    register: $register,
                    schema: $contactSchema,
                    _rbac: false,
                    _multitenancy: false
                );
            }

            if ($contactObject) {
                $stats['contactPersonsProcessed']++;

                $this->logger->info('✅ CONTACT PERSON OBJECT READY', [
                    'app' => 'softwarecatalog',
                    'organizationId' => $organizationUuid,
                    'contactId' => $contactObject->getUuid(),
                    'email' => $email,
                    'wasExisting' => !empty($existingContactId)
                ]);

                // Create user account if username is missing AND organization is active
                $contactObjectData = $contactObject->getObject();
                if (empty($contactObjectData['username'])) {
                    // Check if organization exists in organisation entity table (only active orgs have entries)
                    try {
                        $organisationMapper = \OC::$server->get('OCA\OpenRegister\Db\OrganisationMapper');
                        $organisationEntity = $organisationMapper->findByUuid($organizationUuid);

                        if ($organisationEntity && $organisationEntity->getActive()) {
                            // Determine if this is the first contact for the organization
                            $isFirstContact = $this->contactpersonHandler->isFirstContactForOrganization($contactObject, $contactObjectData);

                            $this->logger->critical('👤 CREATING USER ACCOUNT (org is active)', [
                                'app' => 'softwarecatalog',
                                'contactId' => $contactObject->getUuid(),
                                'organizationId' => $organizationUuid,
                                'organizationActive' => true,
                                'email' => $email,
                                'isFirstContact' => $isFirstContact
                            ]);

                            $user = $this->contactpersonHandler->createUserAccount($contactObject, $isFirstContact);
                            if ($user) {
                                $stats['usersCreated']++;
                                $contactObjectData['username'] = $user->getUID();

                                // Remove organisatie field to avoid validation error
                                // (it's stored as UUID string but schema expects object type)
                                unset($contactObjectData['organisatie']);

                                $this->logger->critical('💾 ABOUT TO SAVE CONTACT WITH USERNAME', [
                                    'app' => 'softwarecatalog',
                                    'contactId' => $contactObject->getUuid(),
                                    'username' => $user->getUID(),
                                    'contactDataKeys' => array_keys($contactObjectData),
                                    'hasOrganisatie' => isset($contactObjectData['organisatie'])
                                ]);

                                // Update the contact object with username
                                $contactObject->setObject($contactObjectData);
                                try {
                                    $objectService->saveObject(
                                        object: $contactObject,
                                        register: $register,
                                        schema: $contactSchema,
                                        _rbac: false,
                                        _multitenancy: false
                                    );
                                    $this->logger->critical('✅ CONTACT SAVED WITH USERNAME', [
                                        'app' => 'softwarecatalog',
                                        'contactId' => $contactObject->getUuid(),
                                        'username' => $user->getUID()
                                    ]);
                                } catch (\Exception $saveEx) {
                                    $this->logger->error('❌ FAILED TO SAVE CONTACT WITH USERNAME', [
                                        'app' => 'softwarecatalog',
                                        'contactId' => $contactObject->getUuid(),
                                        'error' => $saveEx->getMessage(),
                                        'trace' => $saveEx->getTraceAsString()
                                    ]);
                                }

                                // Add user to organization entity in database
                                $this->contactpersonHandler->addUserToOrganizationEntity($contactObject, $user->getUID(), $organizationUuid);

                                // Update contactpersoon object owner to user UID
                                $this->updateContactpersoonObjectOwner($contactObject, $user->getUID(), $register, $contactSchema);

                                $this->logger->critical('🎉 USER ACCOUNT CREATED SUCCESS', [
                                    'app' => 'softwarecatalog',
                                    'contactId' => $contactObject->getUuid(),
                                    'username' => $user->getUID(),
                                    'email' => $email,
                                    'displayName' => $user->getDisplayName()
                                ]);
                            } else {
                                $this->logger->error('❌ USER ACCOUNT CREATION FAILED', [
                                    'app' => 'softwarecatalog',
                                    'contactId' => $contactObject->getUuid(),
                                    'email' => $email
                                ]);
                                $stats['errors'][] = "Failed to create user account for {$email}";
                            }
                        } else {
                            $this->logger->info('Skipping user creation - organization not active or not found in entity table', [
                                'contactId' => $contactObject->getUuid(),
                                'organizationId' => $organizationUuid,
                                'organizationFound' => $organisationEntity !== null,
                                'organizationActive' => $organisationEntity ? $organisationEntity->getActive() : false,
                                'email' => $email
                            ]);
                        }
                    } catch (\Exception $e) {
                        // Organization not found in entity table = not active
                        $this->logger->info('Skipping user creation - organization not found in entity table (not active)', [
                            'contactId' => $contactObject->getUuid(),
                            'organizationId' => $organizationUuid,
                            'reason' => 'Organization not found in entity table',
                            'email' => $email
                        ]);
                    }
                }
            }

        } catch (\Exception $e) {
            $this->logger->error('[FLOW] Failed to create/update contact person object', [
                'organizationId' => $organizationUuid,
                'email' => $contactData['email'] ?? $contactData['e-mailadres'] ?? 'unknown',
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $stats['errors'][] = "Contact person creation failed: " . $e->getMessage();
        }
    }

    /**
     * Process a specific contact person object (called from event listener)
     *
     * This method processes a single contact person object, creating user accounts
     * and updating the contact person object as needed.
     *
     * @param \OCA\OpenRegister\Db\ObjectEntity $contactObject The contact person object to process
     *
     * @return array Processing results
     */
    public function processSpecificContactPerson($contactObject): array
    {
        $stats = [
            'contactPersonsProcessed' => 0,
            'usersCreated' => 0,
            'usersUpdated' => 0,
            'errors' => [],
            'startTime' => date('Y-m-d H:i:s')
        ];

        try {
            $voorzieningenConfig = $this->settingsService->getVoorzieningenConfig();
            $register = $voorzieningenConfig['register'] ?? '';
            $contactSchema = $voorzieningenConfig['contactpersoon_schema'] ?? '';

            $this->logger->info('[EVENT] OrganizationSyncService: Processing specific contact person', [
                'contactId' => $contactObject->getUuid(),
                'trigger' => 'event_listener'
            ]);

            $contactEntityObject = $contactObject->getObject();

            // Skip if no organization reference
            $organizationUuid = $contactEntityObject['organisatie'] ?? null;
            if (empty($organizationUuid)) {
                $this->logger->warning('[EVENT] OrganizationSyncService: Contact person has no organization reference', [
                    'contactId' => $contactObject->getUuid()
                ]);
                return $stats;
            }

            // Create user account if username is missing AND organization is active
            if (empty($contactEntityObject['username'])) {
                // Check if organization exists in organisation entity table (only active orgs have entries)
                try {
                    $organisationMapper = \OC::$server->get('OCA\OpenRegister\Db\OrganisationMapper');
                    $organisationEntity = $organisationMapper->findByUuid($organizationUuid);

                    if ($organisationEntity && $organisationEntity->getActive()) {
                        // Determine if this is the first contact for the organization
                        $isFirstContact = $this->contactpersonHandler->isFirstContactForOrganization($contactObject, $contactEntityObject);

                        $this->logger->info('[EVENT] OrganizationSyncService: Creating user account for contact person (org is active)', [
                            'contactId' => $contactObject->getUuid(),
                            'organizationId' => $organizationUuid,
                            'organizationActive' => true,
                            'email' => $contactEntityObject['email'] ?? $contactEntityObject['e-mailadres'] ?? 'unknown',
                            'isFirstContact' => $isFirstContact
                        ]);

                        $user = $this->contactpersonHandler->createUserAccount($contactObject, $isFirstContact);
                        // Check if user was created successfully (can be null if no email).
                        if ($user !== null) {
                            $contactEntityObject['username'] = $user->getUID();

                            // Remove organisatie field to avoid validation error
                            // (it's stored as UUID string but schema expects object type)
                            unset($contactEntityObject['organisatie']);

                            // Update the contact object with the username (using RBAC bypass).
                            $contactObject->setObject($contactEntityObject);
                            $objectService = \OC::$server->get('OCA\OpenRegister\Service\ObjectService');
                            $objectService->saveObject(
                                object: $contactObject,
                                register: $register,
                                schema: $contactSchema,
                                _rbac: false,
                                _multitenancy: false
                            );

                            // Add user to organization entity in database.
                            $this->contactpersonHandler->addUserToOrganizationEntity($contactObject, $user->getUID(), $organizationUuid);

                            // Update contactpersoon object owner to user UID.
                            $this->updateContactpersoonObjectOwner($contactObject, $user->getUID(), $register, $contactSchema);

                            $stats['usersCreated']++;
                        } else {
                            $this->logger->debug('[EVENT] Skipping contact - user account creation failed (likely no email)', [
                                'app' => 'softwarecatalog',
                                'contactId' => $contactObject->getUuid()
                            ]);
                        }
                    } else {
                        $this->logger->info('[EVENT] OrganizationSyncService: Skipping user creation - organization not active or not found in entity table', [
                            'contactId' => $contactObject->getUuid(),
                            'organizationId' => $organizationUuid,
                            'organizationFound' => $organisationEntity !== null,
                            'organizationActive' => $organisationEntity ? $organisationEntity->getActive() : false,
                            'email' => $contactEntityObject['email'] ?? $contactEntityObject['e-mailadres'] ?? 'unknown'
                        ]);
                    }
                } catch (\Exception $e) {
                    // Organization not found in entity table = not active
                    $this->logger->info('[EVENT] OrganizationSyncService: Skipping user creation - organization not found in entity table (not active)', [
                        'contactId' => $contactObject->getUuid(),
                        'organizationId' => $organizationUuid,
                        'reason' => 'Organization not found in entity table',
                        'email' => $contactEntityObject['email'] ?? $contactEntityObject['e-mailadres'] ?? 'unknown'
                    ]);
                }
            }

            $stats['contactPersonsProcessed']++;
            $stats['endTime'] = date('Y-m-d H:i:s');
            $stats['duration'] = (new \DateTime($stats['endTime']))->getTimestamp() - (new \DateTime($stats['startTime']))->getTimestamp();

            $this->logger->info('[EVENT] OrganizationSyncService: Specific contact person processing completed', [
                'contactId' => $contactObject->getUuid(),
                'stats' => $stats
            ]);

            return $stats;

        } catch (\Exception $e) {
            $stats['errors'][] = $e->getMessage();
            $this->logger->error('[EVENT] OrganizationSyncService: Failed to process specific contact person', [
                'contactId' => $contactObject->getUuid(),
                'exception' => $e->getMessage()
            ]);

            return $stats;
        }
    }

    /**
     * Performs optimized manual synchronization for large datasets
     *
     * This method processes organizations in multiple batches to handle large numbers
     * (800+) efficiently while staying under timeout limits.
     *
     * @param int $maxRounds Maximum number of batch rounds to execute (default: 10)
     * @param int $batchSize Number of items to process per batch (default: 100)
     *
     * @return array Comprehensive synchronization results
     */
    public function performOptimizedManualSync(int $maxRounds = 10, int $batchSize = 100): array
    {
        $totalStartTime = time();
        $allResults = [
            'totalRounds' => 0,
            'organizationsProcessed' => 0,
            'contactPersonsProcessed' => 0,
            'entitiesCreated' => 0,
            'entitiesUpdated' => 0,
            'usersCreated' => 0,
            'usersUpdated' => 0,
            'totalExecutionTime' => 0,
            'timeoutReached' => false,
            'roundsCompleted' => [],
            'errors' => [],
            'startTime' => date('Y-m-d H:i:s')
        ];

        $this->logger->info('[MANUAL] OrganizationSyncService: Starting optimized manual sync', [
            'maxRounds' => $maxRounds,
            'batchSize' => $batchSize,
            'trigger' => 'manual'
        ]);

        for ($round = 1; $round <= $maxRounds; $round++) {
            $roundStartTime = time();

            // Process organizations batch
            $orgResults = $this->performOrganizationsSync($batchSize, 45);

            // Process contacts batch
            $contactResults = $this->performContactSync($batchSize, 15);

            // Accumulate results
            $allResults['organizationsProcessed'] += $orgResults['organizationsProcessed'];
            $allResults['contactPersonsProcessed'] += $contactResults['contactPersonsProcessed'];
            $allResults['entitiesCreated'] += $orgResults['entitiesCreated'];
            $allResults['entitiesUpdated'] += $orgResults['entitiesUpdated'];
            $allResults['usersCreated'] += $contactResults['usersCreated'];
            $allResults['usersUpdated'] += $contactResults['usersUpdated'];

            $roundTime = time() - $roundStartTime;
            $allResults['roundsCompleted'][] = [
                'round' => $round,
                'organizationsProcessed' => $orgResults['organizationsProcessed'],
                'contactPersonsProcessed' => $contactResults['contactPersonsProcessed'],
                'duration' => $roundTime,
                'orgTimeoutReached' => $orgResults['timeoutReached'] ?? false,
                'contactTimeoutReached' => $contactResults['timeoutReached'] ?? false
            ];

            // If no items were processed in this round, we're done
            if ($orgResults['organizationsProcessed'] === 0 && $contactResults['contactPersonsProcessed'] === 0) {
                $this->logger->info('[MANUAL] OrganizationSyncService: No more items to process, stopping', [
                    'round' => $round,
                    'totalProcessed' => $allResults['organizationsProcessed'] + $allResults['contactPersonsProcessed']
                ]);
                break;
            }

            $allResults['totalRounds'] = $round;

            // Small pause between rounds to prevent resource exhaustion
            if ($round < $maxRounds) {
                sleep(1);
            }
        }

        // Final user sync
        $this->performUserSync();
        $this->recordSyncTime();

        $allResults['totalExecutionTime'] = time() - $totalStartTime;
        $allResults['endTime'] = date('Y-m-d H:i:s');

        $this->logger->info('[MANUAL] OrganizationSyncService: Optimized manual sync completed', $allResults);

        return $allResults;
    }

    /**
     * Performs scheduled synchronization with comprehensive logging
     *
     * This method is designed to be called by the background job and includes
     * all necessary logging, error handling, and status tracking.
     * Uses default full sync (0 minutes) to process all organizations.
     *
     * @param int $minutesBack Number of minutes to look back for changes (default: 0 = full sync)
     *
     * @return array Synchronization results with detailed logging information
     */
    public function performScheduledSync(int $minutesBack = 0): array
    {
        $this->logger->info('[CRONJOB] OrganizationSyncService: Starting scheduled synchronization', [
            'minutesBack' => $minutesBack,
            'syncMode' => $minutesBack === 0 ? 'full' : 'incremental',
            'trigger' => 'cronjob'
        ]);

        try {
            // Perform optimized batch synchronization
            // Use smaller batches for scheduled sync to ensure it completes within time limits
            $orgBatchSize = 25;  // Conservative batch size for organizations
            $contactBatchSize = 50;  // Larger batch size for contacts (faster processing)
            $maxOrgTime = 30;  // 30 seconds max for organizations
            $maxContactTime = 15;  // 15 seconds max for contacts

            $syncResults = $this->performOrganizationsSync($orgBatchSize, $maxOrgTime);

            $contactResults = $this->performContactSync($contactBatchSize, $maxContactTime);
            $syncResults = array_merge($contactResults, $syncResults);

            $this->performUserSync();
            // Record the sync time
            $this->recordSyncTime();

            // Log summary results
            $this->logger->info('[CRONJOB] OrganizationSyncService: Scheduled synchronization completed', [
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
                $this->logger->warning('OrganizationSyncService: Scheduled synchronization completed with errors', [
                    'errors' => $syncResults['errors']
                ]);
            }

            return $syncResults;

        } catch (\Exception $e) {
            $this->logger->error('[CRONJOB] OrganizationSyncService: Scheduled synchronization failed', [
                'exception' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'organizationsProcessed' => 0,
                'entitiesCreated' => 0,
                'entitiesUpdated' => 0,
                'contactPersonsProcessed' => 0,
                'usersCreated' => 0,
                'usersUpdated' => 0,
                'errors' => ['Scheduled synchronization failed: ' . $e->getMessage()],
                'startTime' => date('Y-m-d H:i:s'),
                'endTime' => date('Y-m-d H:i:s'),
                'duration' => '00:00:00'
            ];
        }
    }

    /**
     * Performs manual synchronization with API-specific logging
     *
     * This method is designed to be called by the API controller and includes
     * all necessary logging, error handling, and response formatting.
     * Uses full sync (minutesBack = 0) for manual triggers by default.
     *
     * @param int $minutesBack Number of minutes to look back for changes (default: 0 for full sync)
     *
     * @return array Synchronization results formatted for API response
     */
    public function performManualSync(int $minutesBack = 0): array
    {
        $this->logger->info('OrganizationSyncService: Manual organization synchronization started via API', [
            'minutesBack' => $minutesBack,
            'syncMode' => $minutesBack === 0 ? 'full' : 'incremental'
        ]);

        try {
            $syncResults = $this->performOrganizationsSync();

            $syncResults = array_merge($this->performContactSync(), $syncResults);

            $this->performUserSync();

            // Record the sync time for consistency with scheduled sync
            $this->recordSyncTime();

            $this->logger->info('OrganizationSyncService: Manual organization synchronization completed via API', [
                'organizationsProcessed' => $syncResults['organizationsProcessed'],
                'entitiesCreated' => $syncResults['entitiesCreated'],
                'entitiesUpdated' => $syncResults['entitiesUpdated'],
                'usersCreated' => $syncResults['usersCreated'],
                'errorCount' => count($syncResults['errors'])
            ]);

            return [
                'success' => true,
                'results' => $syncResults,
                'message' => 'Synchronization completed successfully'
            ];

        } catch (\Exception $e) {
            $this->logger->error('OrganizationSyncService: Manual organization synchronization failed via API', [
                'exception' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);

            return [
                'success' => false,
                'message' => 'Synchronization failed: ' . $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ];
        }
    }

    /**
     * Gets synchronization status with error handling
     *
     * This method provides status information with proper error handling
     * for API responses.
     *
     * @return array Status information with error handling
     */
    public function getSyncStatusWithErrorHandling(int $minutesBack = 10): array
    {
        try {
            $status = $this->getSyncStatus($minutesBack);
            return $status;
        } catch (\Exception $e) {
            $this->logger->error('OrganizationSyncService: Failed to get sync status', [
                'minutesBack' => $minutesBack,
                'exception' => $e->getMessage()
            ]);

            return [
                'configured' => false,
                'syncMode' => $minutesBack === 0 ? 'full' : 'incremental',
                'timeWindow' => $minutesBack,
                'message' => 'Error getting sync status: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Updates the organisatie object's @self metadata to set owner to the organisation entity UUID
     *
     * @param object $organisatieObject The organisatie object to update
     * @param object $organisationEntity The organisation entity
     * @param string $register The register ID
     * @param string $organizationSchema The organization schema ID
     * @return void
     */
    private function updateOrganisatieObjectOwner(object $organisatieObject, object $organisationEntity, string $register, string $organizationSchema): void
    {
        try {
            $organisatieId = $organisatieObject->getUuid();
            $organisationEntityUuid = $organisationEntity->getUuid();

            $this->logger->info('OrganizationSyncService: Updating organisatie object owner and organisation', [
                'organisatieId' => $organisatieId,
                'organisationEntityUuid' => $organisationEntityUuid,
                'register' => $register,
                'schema' => $organizationSchema
            ]);

            // Get the current object data
            $currentObject = $organisatieObject->jsonSerialize();

            // Get current @self metadata or create new
            $selfMetadata = $currentObject['@self'] ?? [];

            // Check if both owner and organisation are already set correctly
            $ownerAlreadySet = ($selfMetadata['owner'] ?? null) === $organisationEntityUuid;
            $organisationAlreadySet = ($currentObject['organisation'] ?? null) === $organisationEntityUuid;

            if ($ownerAlreadySet && $organisationAlreadySet) {
                $this->logger->debug('OrganizationSyncService: Owner and organisation already set correctly, skipping update', [
                    'organisatieId' => $organisatieId,
                    'organisationEntityUuid' => $organisationEntityUuid
                ]);
                return;
            }

            // Update the owner field in @self metadata to the organisation entity UUID
            $selfMetadata['owner'] = $organisationEntityUuid;

            // Update the organisation property to the organisation entity UUID (so organisation owns itself)
            $currentObject['organisation'] = $organisationEntityUuid;

            // Update the object with the new @self metadata
            $currentObject['@self'] = $selfMetadata;
            $organisatieObject->setObject($currentObject);

            // Save the updated object using ObjectService
            $objectService = \OC::$server->get('OCA\OpenRegister\Service\ObjectService');
            $objectService->saveObject(
                object: $organisatieObject,
                register: $register,
                schema: $organizationSchema,
                _rbac: false,
                _multitenancy: false
            );

            $this->logger->info('OrganizationSyncService: Successfully updated organisatie object owner and organisation', [
                'organisatieId' => $organisatieId,
                'organisationEntityUuid' => $organisationEntityUuid,
                'ownerSet' => $selfMetadata['owner'],
                'organisationSet' => $currentObject['organisation']
            ]);

        } catch (\Exception $e) {
            $this->logger->error('OrganizationSyncService: Failed to update organisatie object owner and organisation', [
                'organisatieId' => $organisatieObject->getUuid(),
                'organisationEntityUuid' => $organisationEntity->getUuid(),
                'exception' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
        }
    }

    /**
     * Updates the contactpersoon object's @self metadata to set owner to the user UID
     *
     * @param object $contactObject The contactpersoon object to update
     * @param string $userUID The user UID to set as owner
     * @param string $register The register ID
     * @param string $contactSchema The contact schema ID
     * @return void
     */
    private function updateContactpersoonObjectOwner(object $contactObject, string $userUID, string $register, string $contactSchema): void
    {
        try {
            $contactId = $contactObject->getUuid();

            $this->logger->info('OrganizationSyncService: Updating contactpersoon object owner', [
                'contactId' => $contactId,
                'userUID' => $userUID,
                'register' => $register,
                'schema' => $contactSchema
            ]);

            // Get the current object data
            $currentObject = $contactObject->getObject();

            // Get current @self metadata or create new
            $selfMetadata = $currentObject['@self'] ?? [];

            // Update the owner field to the user UID
            $selfMetadata['owner'] = $userUID;

            // Set the organisation field in @self metadata to the organization UUID
            // This ensures the contact person is properly linked to their organization
            $organizationUuid = $currentObject['organisation'] ?? $currentObject['organisatie'] ?? '';
            if (!empty($organizationUuid)) {
                $selfMetadata['organisation'] = $organizationUuid;
                $this->logger->info('OrganizationSyncService: Setting @self.organisation metadata', [
                    'contactId' => $contactId,
                    'organizationUuid' => $organizationUuid
                ]);
            } else {
                $this->logger->warning('OrganizationSyncService: No organization UUID found for contact person', [
                    'contactId' => $contactId,
                    'contactData' => $currentObject
                ]);
            }

            // Update the object with the new @self metadata
            $currentObject['@self'] = $selfMetadata;
            $contactObject->setObject($currentObject);

            // Save the updated object using ObjectService
            $objectService = \OC::$server->get('OCA\OpenRegister\Service\ObjectService');
            $objectService->saveObject(
                object: $contactObject,
                register: $register,
                schema: $contactSchema,
                _rbac: false,
                _multitenancy: false
            );

            $this->logger->info('OrganizationSyncService: Successfully updated contactpersoon object owner and organisation', [
                'contactId' => $contactId,
                'userUID' => $userUID,
                'ownerSet' => $selfMetadata['owner'],
                'organisationSet' => $selfMetadata['organisation'] ?? 'not set'
            ]);

        } catch (\Exception $e) {
            $this->logger->error('OrganizationSyncService: Failed to update contactpersoon object owner', [
                'contactId' => $contactObject->getUuid(),
                'userUID' => $userUID,
                'exception' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
        }
    }
}
