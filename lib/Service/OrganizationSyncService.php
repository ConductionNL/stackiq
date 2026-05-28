<?php
/**
 * Organization Synchronization Service
 *
 * This file contains the service class for synchronizing organizations and contact persons
 * between SoftwareCatalog objects and OpenRegister entities.
 *
 * @category  Service
 * @package   OCA\SoftwareCatalog\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link      https://github.com/ConductionNL/SoftwareCatalog
 *
 * @spec openspec/changes/retrofit-2026-05-24-annotate-softwarecatalog/tasks.md#task-6
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
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for synchronizing organizations and contact persons.
 *
 * This service provides comprehensive synchronization between SoftwareCatalog objects
 * and OpenRegister entities, ensuring data consistency and proper user management.
 *
 * @category Service
 * @package  OCA\SoftwareCatalog\Service
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link     https://github.com/ConductionNL/SoftwareCatalog
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyMethods)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 * @SuppressWarnings(PHPMD.LongVariable)
 * @SuppressWarnings(PHPMD.ShortVariable)
 * @SuppressWarnings(PHPMD.MissingImport)
 * @SuppressWarnings(PHPMD.UnusedLocalVariable)
 * @SuppressWarnings(PHPMD.UnusedPrivateMethod)
 * @SuppressWarnings(PHPMD.Superglobals)
 * @SuppressWarnings(PHPMD.CamelCaseVariableName)
 * @SuppressWarnings(PHPMD.CamelCaseParameterName)
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
     * Container instance for lazy service resolution.
     *
     * @var ContainerInterface
     */
    private ContainerInterface $container;

    /**
     * Constructor for OrganizationSyncService
     *
     * @param OrganisatieService    $organisatieService    The organization service.
     * @param ContactpersoonService $contactpersoonService The contact person service.
     * @param SymfonyEmailService   $emailService          The email service.
     * @param IAppConfig            $config                The configuration service.
     * @param LoggerInterface       $logger                The logger instance.
     * @param SettingsService       $settingsService       The settings service.
     * @param IDBConnection         $db                    The database connection.
     * @param ContactPersonHandler  $contactpersonHandler  The contact person handler.
     * @param ContainerInterface    $container             The DI container.
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
        ContainerInterface $container,
    ) {
        $this->organisatieService    = $organisatieService;
        $this->contactpersoonService = $contactpersoonService;
        $this->emailService          = $emailService;
        $this->config          = $config;
        $this->logger          = $logger;
        $this->settingsService = $settingsService;
        $this->container       = $container;

    }//end __construct()

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
     *
     * @psalm-suppress UndefinedClass
     */
    private function jsonExtract(string $column, string $path): string
    {
        $platform   = $this->db->getDatabasePlatform();
        $isPostgres = $platform instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform;

        // Normalize path - remove '$.' prefix if present for PostgreSQL.
        $cleanPath = ltrim($path, '$.');

        if ($isPostgres === true) {
            // PostgreSQL: Use ->> operator for text extraction.
            // Cast to json first if needed, then extract.
            return "({$column}::json->>'{$cleanPath}')";
        }

        // MySQL/MariaDB: Use json_unquote(json_extract()).
        if (str_starts_with($path, '$.') === true) {
            $jsonPath = $path;
        } else {
            $jsonPath = '$.'.$path;
        }

        return "json_unquote(json_extract({$column}, '{$jsonPath}'))";

    }//end jsonExtract()

    /**
     * Create a database-agnostic JSON contains expression
     *
     * Returns the appropriate SQL for checking if a JSON array contains a value.
     *
     * @param string $column The column name containing JSON array
     * @param string $value  The value to check for
     *
     * @return string The SQL expression for JSON contains check
     *
     * @psalm-suppress UndefinedClass
     */
    private function jsonContains(string $column, string $value): string
    {
        $platform   = $this->db->getDatabasePlatform();
        $isPostgres = $platform instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform;

        if ($isPostgres === true) {
            // PostgreSQL: Use @> operator with jsonb.
            return "({$column}::jsonb @> '\"{$value}\"'::jsonb)";
        }

        // MySQL/MariaDB: Use JSON_CONTAINS.
        return "json_contains({$column}, '\"{$value}\"')";

    }//end jsonContains()

    /**
     * Perform synchronization of organizations.
     *
     * @param int $batchSize           The batch size for processing.
     * @param int $maxExecutionSeconds The maximum execution time in seconds.
     *
     * @return array The sync statistics.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-softwarecatalog/tasks.md#task-6
     */
    public function performOrganizationsSync(int $batchSize=50, int $maxExecutionSeconds=45): array
    {
        // Check configuration.
        $voorzieningenConfig = $this->settingsService->getVoorzieningenConfig();
        $register            = ($voorzieningenConfig['register'] ?? '');
        $organizationSchema  = ($voorzieningenConfig['organisatie_schema'] ?? '');

        $startTime = time();
        $stats     = [
            'organizationsProcessed' => 0,
            'entitiesCreated'        => 0,
            'entitiesUpdated'        => 0,
            'errors'                 => [],
            'batchSize'              => $batchSize,
            'maxExecutionSeconds'    => $maxExecutionSeconds,
            'timeoutReached'         => false,
            'totalRemaining'         => 0,
            'startTime'              => date('Y-m-d H:i:s'),
            'endTime'                => null,
            'duration'               => null,
        ];

        if (empty($register) === true || empty($organizationSchema) === true) {
            $this->logger->warning('OrganizationSync: voorzieningen config missing register or organisatie_schema');
            return $stats;
        }

        // Cast config values to integers before using in a table name to prevent injection.
        $registerIdOrg = (int) $register;
        $schemaIdOrg   = (int) $organizationSchema;
        if ($registerIdOrg <= 0 || $schemaIdOrg <= 0) {
            $this->logger->warning(
                    'OrganizationSync: register or organisatie_schema is not a valid positive integer',
                    [
                        'register'           => $register,
                        'organizationSchema' => $organizationSchema,
                    ]
                    );
            return $stats;
        }

        // Build table name dynamically from config (environment-agnostic).
        // Objects live in per-schema MagicMapper tables, NOT in openregister_objects.
        $magicTableName = 'openregister_table_'.$registerIdOrg.'_'.$schemaIdOrg;

        // Count total remaining orgs without entities for progress logging.
        $countQb        = $this->db->getQueryBuilder();
        $totalRemaining = (int) $countQb->select($countQb->createFunction('COUNT(*) as cnt'))
            ->from($magicTableName, 'o')
            ->leftJoin('o', 'openregister_organisations', 'org', 'o._uuid = org.uuid')
            ->where('org.uuid IS NULL')
            ->andWhere(
                $countQb->createFunction(
                    'LOWER(o.status) = '.$countQb->createNamedParameter('actief')
                )
            )
            ->execute()->fetchOne();

        $stats['totalRemaining'] = $totalRemaining;

        if ($totalRemaining === 0) {
            $this->logger->debug('OrganizationSync: all active orgs have entities, nothing to do');
            return $stats;
        }

        $this->logger->info(
            'OrganizationSync: '.$totalRemaining.' active orgs without entity, processing batch of '.$batchSize
        );

        // Query active orgs that don't have an OpenRegister organisation entity yet.
        $qb = $this->db->getQueryBuilder();
        $qb->select('o._uuid as uuid', 'o.status')
            ->from($magicTableName, 'o')
            ->leftJoin('o', 'openregister_organisations', 'org', 'o._uuid = org.uuid')
            ->where('org.uuid IS NULL')
            ->andWhere(
                $qb->createFunction(
                    'LOWER(o.status) = '.$qb->createNamedParameter('actief')
                )
            )
            ->orderBy('o._updated', 'ASC')
            ->setMaxResults($batchSize);

        $rows = $qb->execute()->fetchAll();

        $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
        if ($objectService instanceof ObjectService === false) {
            $this->logger->error('OrganizationSync: could not resolve ObjectService');
            return $stats;
        }

        foreach ($rows as $row) {
            if ((time() - $startTime) >= $maxExecutionSeconds) {
                $stats['timeoutReached'] = true;
                $this->logger->info(
                    'OrganizationSync: time limit reached',
                    [
                        'processed' => $stats['organizationsProcessed'],
                        'elapsed'   => (time() - $startTime),
                    ]
                );
                break;
            }

            try {
                $object = $objectService->find(
                    id: $row['uuid'],
                    register: $register,
                    schema: $organizationSchema,
                    _rbac: false,
                    _multitenancy: false
                );

                $this->ensureOrganisationEntity(organisatieObject: $object, stats: $stats, sendEmails: false);
                $stats['organizationsProcessed']++;
            } catch (\Exception $e) {
                $stats['errors'][] = $row['uuid'].': '.$e->getMessage();
                $this->logger->error(
                    'OrganizationSync: failed to process org '.$row['uuid'],
                    [
                        'error' => $e->getMessage(),
                    ]
                );
            }
        }//end foreach

        $stats['endTime']  = date('Y-m-d H:i:s');
        $stats['duration'] = (time() - $startTime);

        $this->logger->info(
            'OrganizationSync: batch complete',
            [
                'processed' => $stats['organizationsProcessed'],
                'created'   => $stats['entitiesCreated'],
                'remaining' => ($totalRemaining - $stats['organizationsProcessed']),
                'duration'  => $stats['duration'].'s',
            ]
        );

        return $stats;

    }//end performOrganizationsSync()

    /**
     * Perform synchronization of contact persons.
     *
     * @param int $batchSize           The batch size for processing.
     * @param int $maxExecutionSeconds The maximum execution time in seconds.
     *
     * @return array The sync statistics.
     * @spec   openspec/changes/retrofit-2026-05-26-organization-sync/tasks.md#task-1
     */
    public function performContactSync(int $batchSize=100, int $maxExecutionSeconds=30): array
    {
        // Check configuration.
        $voorzieningenConfig = $this->settingsService->getVoorzieningenConfig();
        $register            = ($voorzieningenConfig['register'] ?? '');
        $contactSchema       = ($voorzieningenConfig['contactpersoon_schema'] ?? '');

        $startTime = time();
        $stats     = [
            'contactPersonsProcessed' => 0,
            'errors'                  => [],
            'batchSize'               => $batchSize,
            'maxExecutionSeconds'     => $maxExecutionSeconds,
            'timeoutReached'          => false,
            'totalRemaining'          => 0,
            'startTime'               => date('Y-m-d H:i:s'),
            'endTime'                 => null,
            'duration'                => null,
        ];

        if (empty($register) === true || empty($contactSchema) === true) {
            $this->logger->warning('ContactSync: voorzieningen config missing register or contactpersoon_schema');
            return $stats;
        }

        // Cast config values to integers before using in a table name to prevent injection.
        $registerIdContact = (int) $register;
        $schemaIdContact   = (int) $contactSchema;
        if ($registerIdContact <= 0 || $schemaIdContact <= 0) {
            $this->logger->warning(
                    'ContactSync: register or contactpersoon_schema is not a valid positive integer',
                    [
                        'register'      => $register,
                        'contactSchema' => $contactSchema,
                    ]
                    );
            return $stats;
        }

        // Query per-schema magic table directly (NOT the empty openregister_objects blob table).
        $contactTableName = 'openregister_table_'.$registerIdContact.'_'.$schemaIdContact;

        // Find contacts without a username that DO have a matching Nextcloud account (by email).
        $qb = $this->db->getQueryBuilder();
        $qb->select('o._uuid as uuid', 'o.e_mailadres as email', 'o.organisatie', 'a.uid')
            ->from($contactTableName, 'o')
            ->innerJoin('o', 'accounts_data', 'a', 'o.e_mailadres = a.value')
            ->where(
                $qb->createFunction(
                    '(o.username IS NULL OR o.username = '.$qb->createNamedParameter('').')'
                )
            )
            ->andWhere($qb->createFunction('o.organisatie IS NOT NULL'))
            ->orderBy('o._updated', 'ASC')
            ->setMaxResults($batchSize);

        $contacts = $qb->execute()->fetchAll();
        $stats['totalRemaining'] = count($contacts);

        if (empty($contacts) === true) {
            $this->logger->debug('ContactSync: no contacts to sync');
            return $stats;
        }

        $this->logger->info('ContactSync: processing '.count($contacts).' contacts with existing NC accounts');

        $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
        if ($objectService instanceof ObjectService === false) {
            $this->logger->error('ContactSync: could not resolve ObjectService');
            return $stats;
        }

        foreach ($contacts as $contact) {
            if ((time() - $startTime) >= $maxExecutionSeconds) {
                $stats['timeoutReached'] = true;
                $this->logger->info(
                    'ContactSync: time limit reached',
                    [
                        'processed' => $stats['contactPersonsProcessed'],
                    ]
                );
                break;
            }

            try {
                $contactEntity       = $objectService->find(
                    id: $contact['uuid'],
                    register: $register,
                    schema: $contactSchema,
                    _rbac: false
                );
                $contactEntityObject = $contactEntity->getObject();
                $contactEntityObject['username'] = $contact['uid'];

                // Keep organisatie in the object so it is never temporarily absent from the
                // persisted record. The schema validation warning for a UUID-string value is
                // benign compared to a data-corruption window where the field is missing.
                $contactEntity->setObject($contactEntityObject);
                $objectService->saveObject(
                    object: $contactEntity,
                    register: $register,
                    schema: $contactSchema,
                    _rbac: false,
                    _multitenancy: false
                );

                $stats['contactPersonsProcessed']++;
            } catch (\Exception $e) {
                $stats['errors'][] = $contact['uuid'].': '.$e->getMessage();
                $this->logger->error(
                    'ContactSync: failed to sync contact '.$contact['uuid'],
                    [
                        'error' => $e->getMessage(),
                    ]
                );
            }//end try
        }//end foreach

        $stats['endTime']  = date('Y-m-d H:i:s');
        $stats['duration'] = (time() - $startTime);
        return $stats;

    }//end performContactSync()

    /**
     * Perform synchronization of users.
     *
     * @return array The sync statistics.
     *
     * @psalm-suppress UndefinedClass
     * @spec           openspec/changes/retrofit-2026-05-26-organization-sync/tasks.md#task-1
     */
    public function performUserSync(): array
    {
        $voorzieningenConfig = $this->settingsService->getVoorzieningenConfig();
        $register            = ($voorzieningenConfig['register'] ?? '');
        $contactSchema       = ($voorzieningenConfig['contactpersoon_schema'] ?? '');

        if (empty($register) === true || empty($contactSchema) === true) {
            $this->logger->warning('UserSync: voorzieningen config missing register or contactpersoon_schema');
            return [];
        }

        // Cast to int and validate before using in a table name to prevent injection.
        $registerId = (int) $register;
        $schemaId   = (int) $contactSchema;
        if ($registerId <= 0 || $schemaId <= 0) {
            $this->logger->warning(
                    'UserSync: register or contactpersoon_schema is not a valid positive integer',
                    [
                        'register'      => $register,
                        'contactSchema' => $contactSchema,
                    ]
                    );
            return [];
        }

        // Query per-schema magic table directly (NOT the empty openregister_objects blob table).
        $contactTableName = 'openregister_table_'.$registerId.'_'.$schemaId;

        // Build JSON contains check - platform-specific.
        $platform   = $this->db->getDatabasePlatform();
        $isPostgres = $platform instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform;

        if ($isPostgres === true) {
            $jsonContainsCheck = "NOT (oo.users::jsonb @> to_jsonb(o.username::text))";
        } else {
            $jsonContainsCheck = "JSON_CONTAINS(oo.users, CONCAT('\"', o.username, '\"')) = 0";
        }

        // Find contacts with a username whose username is NOT in their org's users array.
        $qb = $this->db->getQueryBuilder();
        $qb->select('o._uuid as uuid', 'o.username', 'o.organisatie', 'oo.users')
            ->from($contactTableName, 'o')
            ->leftJoin('o', 'openregister_organisations', 'oo', 'oo.uuid = o.organisatie')
            ->where($qb->createFunction('o.username IS NOT NULL'))
            ->andWhere($qb->createFunction('o.username <> '.$qb->createNamedParameter('')))
            ->andWhere($qb->createFunction('o.organisatie IS NOT NULL'))
            ->andWhere($qb->createFunction($jsonContainsCheck));

        $users = $qb->execute()->fetchAll();

        if (empty($users) === false) {
            $this->logger->info('UserSync: adding '.count($users).' users to their org entities');
        }

        foreach ($users as $user) {
            try {
                $this->organisatieService->addUsersToOrganization($user['organisatie'], [$user['username']]);
            } catch (\Exception $e) {
                $this->logger->error(
                    'UserSync: failed to add user '.$user['username'].' to org '.$user['organisatie'],
                    [
                        'error' => $e->getMessage(),
                    ]
                );
            }
        }

        return [];

    }//end performUserSync()

    /**
     * Performs comprehensive organization and contact person synchronization
     *
     * This method synchronizes organisatie objects that have been updated within
     * the specified time window with organisation entities.
     *
     * @param int $minutesBack Number of minutes to look back for changes (0 = all objects)
     *
     * @return array Synchronization results and statistics
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-softwarecatalog/tasks.md#task-6
     */
    public function performFullSync(int $minutesBack=10): array
    {
        if ($minutesBack === 0) {
            $syncModeValue = 'full';
        } else {
            $syncModeValue = 'incremental';
        }

        $this->logger->info(
            'OrganizationSyncService: Starting comprehensive organization synchronization',
            [
                'minutesBack' => $minutesBack,
                'syncMode'    => $syncModeValue,
            ]
        );

        $stats = [
            'organizationsProcessed'  => 0,
            'entitiesCreated'         => 0,
            'entitiesUpdated'         => 0,
            'contactPersonsProcessed' => 0,
            'usersCreated'            => 0,
            'usersUpdated'            => 0,
            'errors'                  => [],
            'startTime'               => date('Y-m-d H:i:s'),
            'endTime'                 => null,
            'duration'                => null,
        ];

        try {
            // Check configuration.
            $voorzieningenConfig = $this->settingsService->getVoorzieningenConfig();
            $register            = ($voorzieningenConfig['register'] ?? '');
            $organizationSchema  = ($voorzieningenConfig['organisatie_schema'] ?? '');
            $contactSchema       = ($voorzieningenConfig['contactpersoon_schema'] ?? '');

            if (empty($register) === true || empty($organizationSchema) === true) {
                $error = 'Missing configuration: register or organization schema not configured';
                $this->logger->error('OrganizationSyncService: '.$error);
                $stats['errors'][] = $error;
                return $stats;
            }

            // Get organisatie objects based on time window.
            $organisatieObjects = $this->getOrganisatieObjectsByTimeWindow(
                register: $register,
                organizationSchema: $organizationSchema,
                minutesBack: $minutesBack
            );
                $syncModeValue  = 'incremental';
            if ($minutesBack === 0) {
            }

            $this->logger->info(
                'OrganizationSyncService: Found organisatie objects',
                [
                    'count'       => count($organisatieObjects),
                    'minutesBack' => $minutesBack,
                    'syncMode'    => $syncModeValue,
                ]
            );

            // Process each organisatie object.
            foreach ($organisatieObjects as $organisatieObject) {
                try {
                    $this->processOrganisatieObject(
                        organisatieObject: $organisatieObject,
                        register: $register,
                        contactSchema: $contactSchema,
                        stats: $stats
                    );
                    $stats['organizationsProcessed']++;
                } catch (\Exception $e) {
                    $error = 'Failed to process organisatie object '.$organisatieObject->getId().': '.$e->getMessage();
                    $this->logger->error(
                        'OrganizationSyncService: '.$error,
                        [
                            'exception' => $e,
                            'objectId'  => $organisatieObject->getId(),
                        ]
                    );
                    $stats['errors'][] = $error;
                }
            }//end foreach

            $stats['endTime']  = date('Y-m-d H:i:s');
            $startTime         = new \DateTime($stats['startTime']);
            $endTime           = new \DateTime($stats['endTime']);
            $stats['duration'] = $endTime->diff($startTime)->format('%H:%I:%S');

            $this->logger->info('OrganizationSyncService: Completed comprehensive synchronization', $stats);

            return $stats;
        } catch (\Exception $e) {
            $error = 'Synchronization failed: '.$e->getMessage();
            $this->logger->error(
                'OrganizationSyncService: '.$error,
                ['exception' => $e]
            );
            $stats['errors'][] = $error;
            $stats['endTime']  = date('Y-m-d H:i:s');
            return $stats;
        }//end try

    }//end performFullSync()

    /**
     * Gets organisatie objects filtered by time window
     *
     * @param string $register           The register ID
     * @param string $organizationSchema The organization schema ID
     * @param int    $minutesBack        Number of minutes to look back (0 = all objects)
     *
     * @return array Array of organisatie objects
     */
    private function getOrganisatieObjectsByTimeWindow(string $register, string $organizationSchema, int $minutesBack): array
    {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

            // Build base query for register and schema.
            $query = [
                '@self' => [
                    'register' => (int) $register,
                    'schema'   => (int) $organizationSchema,
                ],
            ];

            // Add time-based filtering if minutesBack > 0.
            if ($minutesBack > 0) {
                $cutoffTime = new \DateTime();
                $cutoffTime->sub(new \DateInterval('PT'.$minutesBack.'M'));
                $cutoffTimeString = $cutoffTime->format('Y-m-d\TH:i:sP');

                // Add time filtering to the query.
                // Filter objects that were updated within the time window.
                $query['@self']['updated'] = ['gte' => $cutoffTimeString];

                $this->logger->debug(
                    'OrganizationSyncService: Using searchObjects with time-based filtering',
                    [
                        'register'        => $register,
                        'schema'          => $organizationSchema,
                        'minutesBack'     => $minutesBack,
                        'cutoffTime'      => $cutoffTimeString,
                        'currentTime'     => (new \DateTime())->format('Y-m-d\TH:i:sP'),
                        'timeWindowStart' => $cutoffTimeString,
                        'query'           => $query,
                    ]
                );
            }//end if

            if ($minutesBack <= 0) {
                $this->logger->debug(
                    'OrganizationSyncService: Using searchObjects for all objects',
                    [
                        'register' => $register,
                        'schema'   => $organizationSchema,
                        'query'    => $query,
                    ]
                );
            }

            // Use searchObjects method for filtering.
            $objects = $objectService->searchObjects(query: $query, _rbac: false, _multitenancy: false);

            $this->logger->debug(
                'OrganizationSyncService: Retrieved organisatie objects with searchObjects',
                [
                    'register'    => $register,
                    'schema'      => $organizationSchema,
                    'minutesBack' => $minutesBack,
                    'count'       => count($objects),
                ]
            );

            return $objects;
        } catch (\Exception $e) {
            $this->logger->error(
                'OrganizationSyncService: Failed to retrieve organisatie objects with searchObjects',
                [
                    'register'    => $register,
                    'schema'      => $organizationSchema,
                    'minutesBack' => $minutesBack,
                    'error'       => $e->getMessage(),
                    'trace'       => $e->getTraceAsString(),
                ]
            );

            return [];
        }//end try

    }//end getOrganisatieObjectsByTimeWindow()

    /**
     * Processes a single organisatie object
     *
     * @param object $organisatieObject The organisatie object to process
     * @param string $register          The register ID
     * @param string $contactSchema     The contact schema ID
     * @param array  $stats             Statistics array (passed by reference)
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-softwarecatalog/tasks.md#task-6
     */
    private function processOrganisatieObject(
        object $organisatieObject,
        string $register,
        string $contactSchema,
        array &$stats
    ): void {
        $objectData    = $organisatieObject->getObject();
        $organisatieId = ($objectData['id'] ?? $organisatieObject->getId());

        $this->logger->debug(
            'OrganizationSyncService: Processing organisatie object',
            [
                'organisatieId' => $organisatieId,
                'naam'          => ($objectData['naam'] ?? 'Unknown'),
            ]
        );

        // Step 1: Ensure organisation entity exists.
        $organisationEntity = $this->ensureOrganisationEntity(organisatieObject: $organisatieObject, stats: $stats);
        if ($organisationEntity === null) {
            $this->logger->warning(
                'OrganizationSyncService: Could not ensure organisation entity',
                ['organisatieId' => $organisatieId]
            );
            return;
        }

        // Step 2: Get all contact persons for this organisation.
        $contactPersons = $this->getContactPersonsForOrganisation(
            organisatieId: $organisatieId,
            register: $register,
            contactSchema: $contactSchema
        );
        $this->logger->debug(
            'OrganizationSyncService: Found contact persons',
            [
                'organisatieId' => $organisatieId,
                'contactCount'  => count($contactPersons),
            ]
        );

        // Step 3: Process each contact person to ensure they have user accounts.
        $usernames = [];
        foreach ($contactPersons as $contactPerson) {
            $username = $this->processContactPerson(contactPerson: $contactPerson, stats: $stats);
            if (empty($username) === false) {
                $usernames[] = $username;
            }
        }

        // Step 4: Update organisation entity with all usernames.
        $this->updateOrganisationEntityUsers(organisationEntity: $organisationEntity, usernames: $usernames, stats: $stats);

    }//end processOrganisatieObject()

    /**
     * Public wrapper for ensureOrganisationEntity.
     *
     * Used by ContactpersoonService for backup entity creation when org entity is missing.
     *
     * @param object $organisatieObject The organisatie object.
     * @param array  $stats             Statistics array (passed by reference).
     * @param bool   $sendEmails        Whether to send registration/activation emails.
     *
     * @return object|null The organisation entity or null on failure.
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) $sendEmails is a simple notification toggle
     * @spec                                        openspec/changes/retrofit-2026-05-26-organization-sync/tasks.md#task-2
     */
    public function ensureOrganisationEntityPublic(object $organisatieObject, array &$stats, bool $sendEmails=true): ?object
    {
        return $this->ensureOrganisationEntity(
            organisatieObject: $organisatieObject,
            stats: $stats,
            sendEmails: $sendEmails
        );

    }//end ensureOrganisationEntityPublic()

    /**
     * Ensures organisation entity exists for organisatie object.
     *
     * @param object $organisatieObject The organisatie object.
     * @param array  $stats             Statistics array (passed by reference).
     * @param bool   $sendEmails        Whether to send emails.
     *
     * @return object|null The organisation entity or null on failure.
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) $sendEmails is a simple notification toggle
     */
    private function ensureOrganisationEntity(object $organisatieObject, array &$stats, bool $sendEmails=true): ?object
    {
        try {
            // Get the full object data - the passed object might not have all fields populated.
            $organisatieId = $organisatieObject->getUuid();

            // Fetch the complete object from the database to ensure we have all data.
            try {
                $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
                $fullObject    = $objectService->find(
                    id: $organisatieId,
                    register: $organisatieObject->getRegister(),
                    schema: $organisatieObject->getSchema(),
                    _rbac: false,
                    _multitenancy: false
                );
                if (empty($fullObject) === false) {
                    $organisatieObject = $fullObject;
                }
            } catch (\Exception $e) {
                $this->logger->warning(
                    'Could not fetch full organisation object, using provided object',
                    [
                        'organisatieId' => $organisatieId,
                        'error'         => $e->getMessage(),
                    ]
                );
            }//end try

            $objectData = $organisatieObject->getObject();

            $this->logger->debug(
                'Ensuring organisation entity',
                [
                    'app'           => 'softwarecatalog',
                    'organisatieId' => $organisatieId,
                    'naam'          => ($objectData['naam'] ?? $objectData['name'] ?? 'Unknown'),
                    'status'        => ($objectData['status'] ?? 'Unknown'),
                ]
            );

            // Get configuration for object updates.
            $voorzieningenConfig = $this->settingsService->getVoorzieningenConfig();
            $register            = ($voorzieningenConfig['register'] ?? '');
            $organizationSchema  = ($voorzieningenConfig['organisatie_schema'] ?? '');

            // Try to find existing organisation entity.
            $organisationMapper = $this->container->get('OCA\OpenRegister\Db\OrganisationMapper');

            try {
                $organisationEntity = $organisationMapper->findByUuid($organisatieId);

                // Entity exists - update it if needed.
                $status         = strtolower(($objectData['status'] ?? 'actief'));
                $shouldBeActive = in_array($status, ['actief', 'active']) === true;

                $this->logger->debug(
                    'Existing entity found',
                    [
                        'app'            => 'softwarecatalog',
                        'organisatieId'  => $organisatieId,
                        'entityId'       => $organisationEntity->getId(),
                        'shouldBeActive' => $shouldBeActive,
                        'needsUpdate'    => $organisationEntity->getActive() !== $shouldBeActive,
                    ]
                );

                if ($organisationEntity->getActive() !== $shouldBeActive) {
                    $this->logger->debug(
                        'Updating entity status',
                        [
                            'app'           => 'softwarecatalog',
                            'organisatieId' => $organisatieId,
                            'oldActive'     => $organisationEntity->getActive(),
                            'newActive'     => $shouldBeActive,
                        ]
                    );

                    $wasActive = $organisationEntity->getActive();
                    $organisationEntity->setActive($shouldBeActive);
                    $organisationMapper->save($organisationEntity);
                    $stats['entitiesUpdated']++;

                    // Send activation email if organization became active.
                    if ($sendEmails !== false && $shouldBeActive === true && $wasActive === false) {
                        $this->logger->info(
                            '[FLOW] Sending organization activation email',
                            ['organisatieId' => $organisatieId]
                        );
                        $emailSent = $this->sendOrganizationActivationEmail(organizationData: $objectData);
                        if (empty($emailSent) === false) {
                            $this->logger->info(
                                '📧 Organization activation email sent successfully',
                                ['organisatieId' => $organisatieId]
                            );
                        }

                        if (empty($emailSent) === true) {
                            $this->logger->info(
                                '📧 Organization activation email not sent (disabled or not configured)',
                                ['organisatieId' => $organisatieId]
                            );
                        }
                    }//end if
                }//end if

                // Update organisatie object owner to organisation entity UUID.
                $this->updateOrganisatieObjectOwner(
                    organisatieObject: $organisatieObject,
                    organisationEntity: $organisationEntity,
                    register: $register,
                    organizationSchema: $organizationSchema
                );

                return $organisationEntity;
            } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
                // Entity not found by UUID — try finding by slug before creating.
                // This handles the case where the entity exists but with a different UUID (e.g. from slug collision).
                $orgName = ($objectData['naam'] ?? $objectData['name'] ?? '');
                if (empty($orgName) === false) {
                    $slug = strtolower(preg_replace('/[^a-z0-9]+/', '-', strtolower($orgName)));
                    $slug = trim($slug, '-');
                    try {
                        $organisationEntity = $organisationMapper->findBySlug($slug);
                        $this->logger->info(
                            'OrganizationSyncService: Found existing entity by slug, updating UUID to match object',
                            [
                                'app'           => 'softwarecatalog',
                                'organisatieId' => $organisatieId,
                                'oldEntityUuid' => $organisationEntity->getUuid(),
                                'slug'          => $slug,
                            ]
                        );

                        // Update the entity's UUID to match the object UUID so future lookups work.
                        $organisationEntity->setUuid($organisatieId);
                        $organisationMapper->save($organisationEntity);
                        $stats['entitiesUpdated']++;

                        // Update organisatie object owner to this entity.
                        $this->updateOrganisatieObjectOwner(
                        organisatieObject: $organisatieObject,
                        organisationEntity: $organisationEntity,
                        register: $register,
                        organizationSchema: $organizationSchema
                        );

                        return $organisationEntity;
                    } catch (\OCP\AppFramework\Db\DoesNotExistException $slugEx) {
                        // Not found by slug either — proceed with creation.
                    }//end try
                }//end if

                $this->logger->debug(
                    'Creating new organisation entity',
                    [
                        'app'           => 'softwarecatalog',
                        'organisatieId' => $organisatieId,
                        'naam'          => ($objectData['naam'] ?? 'Unknown'),
                    ]
                );

                $organisationEntity = $this->organisatieService->createOrganisationInOpenRegister($objectData);
                if (empty($organisationEntity) === false) {
                    $stats['entitiesCreated']++;
                    $this->logger->debug(
                        'New organisation entity created',
                        [
                            'app'           => 'softwarecatalog',
                            'organisatieId' => $organisatieId,
                            'entityId'      => $organisationEntity->getId(),
                            'active'        => $organisationEntity->getActive(),
                            'name'          => $organisationEntity->getName(),
                        ]
                    );

                    // Send registration email for new organization (skip for backfill).
                    if (empty($sendEmails) === false) {
                        $this->logger->info(
                            '[FLOW] Sending organization registration email',
                            ['organisatieId' => $organisatieId]
                        );
                        $emailSent = $this->sendOrganizationRegistrationEmail(organizationData: $objectData);
                        if (empty($emailSent) === false) {
                            $this->logger->info(
                                '📧 Organization registration email sent successfully',
                                ['organisatieId' => $organisatieId]
                            );
                        }

                        if (empty($emailSent) === true) {
                            $this->logger->info(
                                '📧 Organization registration email not sent (disabled or not configured)',
                                ['organisatieId' => $organisatieId]
                            );
                        }
                    }//end if

                    // Update organisatie object owner to organisation entity UUID.
                    $this->updateOrganisatieObjectOwner(
                    organisatieObject: $organisatieObject,
                    organisationEntity: $organisationEntity,
                    register: $register,
                    organizationSchema: $organizationSchema
                    );
                }//end if

                if (empty($organisationEntity) === true) {
                    $this->logger->error(
                        '❌ ORGANISATION ENTITY CREATION FAILED',
                        [
                            'app'           => 'softwarecatalog',
                            'organisatieId' => $organisatieId,
                        ]
                    );
                }

                return $organisationEntity;
            }//end try
        } catch (\Exception $e) {
            $this->logger->error(
                '💥 ENSURE ORGANISATION ENTITY EXCEPTION',
                [
                    'app'           => 'softwarecatalog',
                    'organisatieId' => $organisatieObject->getId(),
                    'exception'     => $e->getMessage(),
                    'file'          => $e->getFile(),
                    'line'          => $e->getLine(),
                    'trace'         => $e->getTraceAsString(),
                ]
            );
            return null;
        }//end try

    }//end ensureOrganisationEntity()

    /**
     * Safely sends organization registration email with error handling
     *
     * @param array $organizationData The organization data.
     *
     * @return bool True if email was sent successfully, false otherwise.
     */
    private function sendOrganizationRegistrationEmail(array $organizationData): bool
    {
        try {
            return $this->emailService->sendOrganizationRegistrationEmail($organizationData);
        } catch (\Exception $e) {
            $this->logger->warning(
                'OrganizationSyncService: Organization registration email failed',
                [
                    'organizationId'   => ($organizationData['id'] ?? 'unknown'),
                    'organizationName' => ($organizationData['naam'] ?? 'unknown'),
                    'error'            => $e->getMessage(),
                ]
            );
            return false;
        }

    }//end sendOrganizationRegistrationEmail()

    /**
     * Safely sends organization activation email with error handling
     *
     * @param array $organizationData The organization data.
     *
     * @return bool True if email was sent successfully, false otherwise.
     */
    private function sendOrganizationActivationEmail(array $organizationData): bool
    {
        try {
            return $this->emailService->sendOrganizationActivationEmail($organizationData);
        } catch (\Exception $e) {
            $this->logger->warning(
                'OrganizationSyncService: Organization activation email failed',
                [
                    'organizationId'   => ($organizationData['id'] ?? 'unknown'),
                    'organizationName' => ($organizationData['naam'] ?? 'unknown'),
                    'error'            => $e->getMessage(),
                ]
            );
            return false;
        }

    }//end sendOrganizationActivationEmail()

    /**
     * Gets all contact persons for a specific organisation
     *
     * @param string $organisatieId The organisation ID
     * @param string $register      The register ID
     * @param string $contactSchema The contact schema ID
     *
     * @return array Array of contact person objects
     */
    private function getContactPersonsForOrganisation(string $organisatieId, string $register, string $contactSchema): array
    {
        if (empty($contactSchema) === true) {
            $this->logger->debug('OrganizationSyncService: No contact schema configured, skipping contact person lookup');
            return [];
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

            // Use searchObjects for more efficient filtering on-demand.
            $query = [
                '@self'       => [
                    'register' => (int) $register,
                    'schema'   => (int) $contactSchema,
                ],
                'organisatie' => $organisatieId,
            ];

            $contactPersons = $objectService->searchObjects(query: $query, _rbac: false, _multitenancy: false);

            $this->logger->debug(
                'OrganizationSyncService: Retrieved contact persons on-demand',
                [
                    'organisatieId' => $organisatieId,
                    'register'      => $register,
                    'schema'        => $contactSchema,
                    'count'         => count($contactPersons),
                ]
            );

            return $contactPersons;
        } catch (\Exception $e) {
            $this->logger->error(
                'OrganizationSyncService: Failed to get contact persons',
                [
                    'organisatieId' => $organisatieId,
                    'register'      => $register,
                    'schema'        => $contactSchema,
                    'exception'     => $e,
                ]
            );
            return [];
        }//end try

    }//end getContactPersonsForOrganisation()

    /**
     * Processes a contact person to ensure they have a user account
     *
     * @param object $contactPerson The contact person object
     * @param array  $stats         Statistics array (passed by reference)
     *
     * @return string|null The username if successful, null otherwise
     */
    private function processContactPerson(object $contactPerson, array &$stats): ?string
    {
        try {
            $contactData = $contactPerson->getObject();
            // Schema uses 'e-mailadres' but some data may use 'email'.
            $email            = ($contactData['email'] ?? $contactData['e-mailadres'] ?? '');
            $existingUsername = ($contactData['username'] ?? '');

            if (empty($email) === true) {
                $this->logger->debug(
                    'OrganizationSyncService: Contact person has no email, skipping',
                    [
                        'contactId' => $contactPerson->getId(),
                    ]
                );
                return null;
            }

            // Check if user already exists.
            $userManager = $this->container->get('OCP\IUserManager');
            // Use the stored username when available, otherwise fall back to email as username.
            if (empty($existingUsername) === false) {
                $username = $existingUsername;
            } else {
                $username = $email;
            }

            $user = $userManager->get($username);

            if ($user === null) {
                // Create user account.
                $this->logger->info(
                    'OrganizationSyncService: Creating user account for contact person',
                    [
                        'contactId' => $contactPerson->getId(),
                        'email'     => $email,
                        'username'  => $username,
                    ]
                );

                $success = $this->contactpersoonService->processContactpersoon($contactPerson, false);
                if (empty($success) === false) {
                    $stats['usersCreated']++;
                    $this->logger->info(
                        'OrganizationSyncService: Successfully created user account',
                        [
                            'contactId' => $contactPerson->getId(),
                            'username'  => $username,
                        ]
                    );
                } else {
                    $this->logger->error(
                        'OrganizationSyncService: Failed to create user account',
                        [
                            'contactId' => $contactPerson->getId(),
                            'username'  => $username,
                        ]
                    );
                }
            }//end if

            // Contact exists without a stored username — record the resolved username for future syncs.
            if (empty($existingUsername) === true) {
                $this->logger->debug(
                    'OrganizationSyncService: Updating contact person with username',
                    [
                        'contactId' => $contactPerson->getId(),
                        'username'  => $username,
                    ]
                );
                $stats['usersUpdated']++;
            }

            return $username;
        } catch (\Exception $e) {
            $this->logger->error(
                'OrganizationSyncService: Failed to process contact person',
                [
                    'contactId' => $contactPerson->getId(),
                    'exception' => $e,
                ]
            );
            return null;
        }//end try

    }//end processContactPerson()

    /**
     * Updates organisation entity with all usernames
     *
     * @param object $organisationEntity The organisation entity
     * @param array  $usernames          Array of usernames to add
     * @param array  $stats              Statistics array (passed by reference)
     *
     * @return void
     */
    private function updateOrganisationEntityUsers(object $organisationEntity, array $usernames, array &$stats): void
    {
        try {
            $organisationUuid = $organisationEntity->getUuid();
            $currentUsers     = ($organisationEntity->getUsers() ?? []);

            // Add admin users to ensure they're always included.
            $adminUsers   = $this->getAdminUsers();
            $allUsernames = array_unique(array_merge($usernames, $adminUsers));

            // Check if users list has changed.
            $currentUsersSet = array_unique($currentUsers);
            sort($currentUsersSet);
            sort($allUsernames);

            if ($currentUsersSet !== $allUsernames) {
                $this->logger->info(
                    'OrganizationSyncService: Updating organisation entity users',
                    [
                        'organisationUuid' => $organisationUuid,
                        'currentUsers'     => count($currentUsers),
                        'newUsers'         => count($allUsernames),
                        'addedUsers'       => array_diff($allUsernames, $currentUsers),
                        'removedUsers'     => array_diff($currentUsers, $allUsernames),
                    ]
                );

                $organisationEntity->setUsers($allUsernames);

                $organisationMapper = $this->container->get('OCA\OpenRegister\Db\OrganisationMapper');
                $organisationMapper->save($organisationEntity);

                $stats['entitiesUpdated']++;

                $this->logger->info(
                    'OrganizationSyncService: Successfully updated organisation entity users',
                    [
                        'organisationUuid' => $organisationUuid,
                        'totalUsers'       => count($allUsernames),
                    ]
                );
            }//end if

            if ($currentUsersSet === $allUsernames) {
                $this->logger->debug(
                    'OrganizationSyncService: Organisation entity users unchanged',
                    [
                        'organisationUuid' => $organisationUuid,
                        'userCount'        => count($allUsernames),
                    ]
                );
            }
        } catch (\Exception $e) {
            $this->logger->error(
                'OrganizationSyncService: Failed to update organisation entity users',
                [
                    'organisationUuid' => $organisationEntity->getUuid(),
                    'exception'        => $e,
                ]
            );
        }//end try

    }//end updateOrganisationEntityUsers()

    /**
     * Gets admin users that should always be included in organizations
     *
     * @return array Array of admin usernames
     */
    private function getAdminUsers(): array
    {
        try {
            $groupManager = $this->container->get('OCP\IGroupManager');
            $adminGroup   = $groupManager->get('admin');

            if (empty($adminGroup) === false) {
                $adminUsers     = $adminGroup->getUsers();
                $adminUsernames = [];
                foreach ($adminUsers as $user) {
                    $adminUsernames[] = $user->getUID();
                }

                return $adminUsernames;
            }

            return [];
        } catch (\Exception $e) {
            $this->logger->error(
                'OrganizationSyncService: Failed to get admin users',
                ['exception' => $e]
            );
            return [];
        }//end try

    }//end getAdminUsers()

    /**
     * Performs a quick sync status check with prediction of objects to be processed
     *
     * @param int $minutesBack Number of minutes to look back for prediction (default: 10 for scheduled sync)
     *
     * @return array Status information about sync requirements including processing predictions
     * @spec   openspec/changes/retrofit-2026-05-26-organization-sync/tasks.md#task-3
     */
    public function getSyncStatus(int $minutesBack=10): array
    {
        try {
            // Check configuration.
            $voorzieningenConfig = $this->settingsService->getVoorzieningenConfig();
            $register            = ($voorzieningenConfig['register'] ?? '');
            $organizationSchema  = ($voorzieningenConfig['organisatie_schema'] ?? '');
            $contactSchema       = ($voorzieningenConfig['contactpersoon_schema'] ?? '');

            if (empty($register) === true || empty($organizationSchema) === true) {
                return [
                    'configured' => false,
                    'message'    => 'Sync not configured: missing register or organization schema',
                ];
            }

            // Get total counts (all objects).
            $allOrganisatieObjects = $this->getOrganisatieObjectsByTimeWindow(
                register: $register,
                organizationSchema: $organizationSchema,
                minutesBack: 0
            );

            // Get incremental counts (objects to be processed in next sync).
            $incrementalOrganisatieObjects = $this->getOrganisatieObjectsByTimeWindow(
                register: $register,
                organizationSchema: $organizationSchema,
                minutesBack: $minutesBack
            );

            // Get organization entities count.
            $organisationMapper = $this->container->get('OCA\OpenRegister\Db\OrganisationMapper');
            $entitiesCount      = 0;
            try {
                $entities      = $organisationMapper->findAllWithUserCount();
                $entitiesCount = count($entities);
            } catch (\Exception $e) {
                // Ignore errors in count.
            }

            // Predict contact persons to be processed.
            $predictedContactPersonsToProcess = 0;
            if (empty($contactSchema) === false) {
                foreach ($incrementalOrganisatieObjects as $orgObject) {
                    $objectData     = $orgObject->getObject();
                    $organisatieId  = ($objectData['id'] ?? $orgObject->getId());
                    $contactPersons = $this->getContactPersonsForOrganisation(
                        organisatieId: $organisatieId,
                        register: $register,
                        contactSchema: $contactSchema
                    );
                    $predictedContactPersonsToProcess += count($contactPersons);
                }
            }

            // Calculate efficiency metrics.
            $efficiencyImprovement = 0;
            if (count($allOrganisatieObjects) > 0) {
                $ratio = count($incrementalOrganisatieObjects) / count($allOrganisatieObjects);
                $efficiencyImprovement = round(((1 - $ratio) * 100), 1);
            }

            if ($minutesBack === 0) {
                $syncModeValue = 'full';
            } else {
                $syncModeValue = 'incremental';
            }

            $messageValue = 'No organizations to process in the current time window';
            if (count($incrementalOrganisatieObjects) > 0) {
                $orgCount     = $this->formatNumber(number: count($incrementalOrganisatieObjects));
                $contactCount = $this->formatNumber(number: $predictedContactPersonsToProcess);
                // phpcs:ignore Generic.Files.LineLength.TooLong
                $messageValue = "Ready to process {$orgCount} organizations and {$contactCount} contact persons";
            }

            if ($minutesBack > 0) {
                $nextScheduledSyncValue = "Will process organizations modified in the last {$minutesBack} minutes (incremental sync)";
            } else {
                $nextScheduledSyncValue = 'Will process all organizations (full sync)';
            }

            return [
                'configured'                => true,
                'syncMode'                  => $syncModeValue,
                'timeWindow'                => $minutesBack,

                // Total counts.
                'totalOrganizationObjects'  => count($allOrganisatieObjects),
                'totalOrganizationEntities' => $entitiesCount,

                // Processing predictions.
                'organizationsToProcess'    => count($incrementalOrganisatieObjects),
                'contactPersonsToProcess'   => $predictedContactPersonsToProcess,

                // Efficiency metrics.
                'efficiencyImprovement'     => $efficiencyImprovement.'%',
                'processingReduction'       => (count($allOrganisatieObjects) - count($incrementalOrganisatieObjects)),

                // Configuration.
                'contactSchemaConfigured'   => empty($contactSchema) === false,
                'lastSyncTime'              => $this->config->getValueString('softwarecatalog', 'last_sync_time', 'Never'),

                // Email configuration status.
                'emailStatus'               => $this->getEmailConfigurationStatus(),

                // Status messages.
                'message'                   => $messageValue,
                'nextScheduledSync'         => $nextScheduledSyncValue,
            ];
        } catch (\Exception $e) {
            return [
                'configured' => false,
                'message'    => 'Error checking sync status: '.$e->getMessage(),
            ];
        }//end try

    }//end getSyncStatus()

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
            $this->logger->warning(
                'OrganizationSyncService: Failed to check email configuration status',
                [
                    'error' => $e->getMessage(),
                ]
            );
            return [
                'configured'     => false,
                'reason'         => 'Error checking email configuration: '.$e->getMessage(),
                'hasCredentials' => false,
                'hasTemplates'   => false,
            ];
        }

    }//end getEmailConfigurationStatus()

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
            return number_format(($number / 1000), 1).'k';
        }

        return (string) $number;

    }//end formatNumber()

    /**
     * Records the last sync time
     *
     * @return void
     * @spec   openspec/changes/retrofit-2026-05-26-organization-sync/tasks.md#task-3
     */
    public function recordSyncTime(): void
    {
        $this->config->setValueString('softwarecatalog', 'last_sync_time', date('Y-m-d H:i:s'));

    }//end recordSyncTime()

    /**
     * Process a specific organization object (called from event listener)
     *
     * This method processes a single organization object, creating or updating
     * the corresponding organization entity as needed.
     *
     * @param \OCA\OpenRegister\Db\ObjectEntity $organizationObject The organization object to process
     *
     * @return array Processing results
     * @spec   openspec/changes/retrofit-2026-05-26-organization-sync/tasks.md#task-2
     */
    public function processSpecificOrganization($organizationObject): array
    {
        $startTime = microtime(true);
        $stats     = [
            'organizationsProcessed'  => 0,
            'entitiesCreated'         => 0,
            'entitiesUpdated'         => 0,
            'contactPersonsProcessed' => 0,
            'usersCreated'            => 0,
            'errors'                  => [],
            'startTime'               => date('Y-m-d H:i:s'),
        ];

        try {
            $objectData       = $organizationObject->getObject();
            $organizationUuid = $organizationObject->getUuid();

            $this->logger->info(
                '🏢 ORGANIZATION PROCESSING STARTED',
                [
                    'app'                => 'softwarecatalog',
                    'trigger'            => 'ObjectCreatedEvent',
                    'organizationId'     => $organizationUuid,
                    'organizationName'   => ($objectData['naam'] ?? 'Unknown'),
                    'organizationStatus' => ($objectData['status'] ?? 'Unknown'),
                    'timestamp'          => date('Y-m-d H:i:s'),
                    'microtime'          => microtime(true),
                ]
            );

            // Process organization entity.
            $this->logger->info(
                '[FLOW] Step 1: Creating/updating organisation entity',
                [
                    'organizationId' => $organizationUuid,
                    'action'         => 'ensure_organisation_entity',
                ]
            );

            $organisationEntity = $this->ensureOrganisationEntity(organisatieObject: $organizationObject, stats: $stats);

            if (empty($organisationEntity) === false) {
                $stats['organizationsProcessed']++;

                $this->logger->info(
                    '✅ ORGANISATION ENTITY CREATED/UPDATED',
                    [
                        'app'              => 'softwarecatalog',
                        'organizationUuid' => $organizationUuid,
                        'entityId'         => $organisationEntity->getId(),
                        'entityActive'     => $organisationEntity->getActive(),
                        'entitiesCreated'  => $stats['entitiesCreated'],
                        'entitiesUpdated'  => $stats['entitiesUpdated'],
                    ]
                );

                // Step 1.5: Process nested contact persons (from registration form data).
                $this->logger->info(
                    '[FLOW] Step 1.5: Processing nested contactpersonen from organization data',
                    [
                        'organizationId' => $organizationUuid,
                        'action'         => 'process_nested_contactpersonen',
                    ]
                );

                $this->processNestedContactPersons(organizationObject: $organizationObject, stats: $stats);

                // Step 2: Find and process related contactpersonen objects (separate objects, not nested).
                $this->logger->info(
                    '[FLOW] Step 2: Finding related contactpersoon objects',
                    [
                        'organizationId' => $organizationUuid,
                        'action'         => 'process_related_contactpersonen',
                    ]
                );

                $this->processRelatedContactPersons(
                    organizationUuid: $organizationUuid,
                    organizationObject: $organizationObject,
                    stats: $stats
                );
            }//end if

            if (empty($organisationEntity) === true) {
                $this->logger->error(
                    '❌ ORGANISATION ENTITY FAILED',
                    [
                        'app'              => 'softwarecatalog',
                        'organizationUuid' => $organizationUuid,
                        'error'            => 'Failed to create/update organisation entity',
                    ]
                );
                $stats['errors'][] = 'Failed to create/update organisation entity';
            }

            $stats['endTime']  = date('Y-m-d H:i:s');
            $stats['duration'] = round((microtime(true) - $startTime), 3);

            $this->logger->info(
                '🏁 ORGANIZATION PROCESSING COMPLETED',
                [
                    'app'            => 'softwarecatalog',
                    'organizationId' => $organizationUuid,
                    'stats'          => $stats,
                    'processingTime' => $stats['duration'].'s',
                ]
            );

            return $stats;
        } catch (\Exception $e) {
            $stats['errors'][] = $e->getMessage();
            $this->logger->error(
                '💥 ORGANIZATION PROCESSING EXCEPTION',
                [
                    'app'            => 'softwarecatalog',
                    'organizationId' => $organizationObject->getUuid(),
                    'exception'      => $e->getMessage(),
                    'file'           => $e->getFile(),
                    'line'           => $e->getLine(),
                    'trace'          => $e->getTraceAsString(),
                ]
            );

            return $stats;
        }//end try

    }//end processSpecificOrganization()

    /**
     * Process nested contact persons within an organization object
     *
     * @param \OCA\OpenRegister\Db\ObjectEntity $organizationObject The organization object.
     * @param array                             $stats              The statistics array to update.
     *
     * @return void
     */
    private function processNestedContactPersons($organizationObject, array &$stats): void
    {
        try {
            $objectData       = $organizationObject->getObject();
            $organizationUuid = $organizationObject->getUuid();

            // Check if organization has nested contact persons.
            $contactPersons = ($objectData['contactpersonen'] ?? $objectData['contactPersons'] ?? []);

            if (empty($contactPersons) === true) {
                $this->logger->info(
                    '[FLOW] No nested contact persons found in organization',
                    ['organizationId' => $organizationUuid]
                );
                return;
            }

            $this->logger->info(
                '👥 PROCESSING NESTED CONTACT PERSONS',
                [
                    'app'            => 'softwarecatalog',
                    'organizationId' => $organizationUuid,
                    'contactCount'   => count($contactPersons),
                ]
            );

            // Get configuration for contact person creation.
            $voorzieningenConfig = $this->settingsService->getVoorzieningenConfig();
            $register            = ($voorzieningenConfig['register'] ?? '');
            $contactSchema       = ($voorzieningenConfig['contactpersoon_schema'] ?? '');

            if (empty($register) === true || empty($contactSchema) === true) {
                $this->logger->warning(
                    '[FLOW] Contact person processing skipped - configuration missing',
                    [
                        'organizationId' => $organizationUuid,
                        'register'       => $register,
                        'contactSchema'  => $contactSchema,
                    ]
                );
                return;
            }

            foreach ($contactPersons as $index => $contactData) {
                try {
                    // Handle UUID references - if contactData is a string (UUID), fetch the actual object.
                    if (is_string($contactData) === true) {
                        $this->logger->info(
                            '[FLOW] Contact person is a UUID reference, fetching object',
                            [
                                'organizationId' => $organizationUuid,
                                'contactIndex'   => $index,
                                'contactUuid'    => $contactData,
                            ]
                        );

                        // Fetch the contact person object using the UUID.
                        $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
                        $contactObject = $objectService->find(
                            id: $contactData,
                            register: $register,
                            schema: $contactSchema,
                            _rbac: false,
                            _multitenancy: false
                        );

                        if ($contactObject === null) {
                            $this->logger->warning(
                                '[FLOW] Contact person not found by UUID',
                                [
                                    'organizationId' => $organizationUuid,
                                    'contactUuid'    => $contactData,
                                ]
                            );
                            continue;
                        }

                        // Get the object data as array.
                        $contactData = [];
                        if (is_array($contactObject) === true) {
                            $contactData = $contactObject;
                        }

                        if ($contactObject instanceof \OCA\OpenRegister\Db\ObjectEntity) {
                            $contactData = $contactObject->getObject();
                        }

                        // Add the UUID if not present.
                        if (isset($contactData['id']) === false
                            && $contactObject instanceof \OCA\OpenRegister\Db\ObjectEntity
                        ) {
                            $contactData['id'] = $contactObject->getUuid();
                        }
                    }//end if

                    $this->logger->info(
                        '[FLOW] Processing nested contact person',
                        [
                            'organizationId' => $organizationUuid,
                            'contactIndex'   => $index,
                            'contactEmail'   => ($contactData['email'] ?? $contactData['e-mailadres'] ?? 'unknown'),
                        ]
                    );

                    // Create contact person object in OpenRegister if it doesn't exist.
                    $this->createOrUpdateContactPersonObject(
                        contactData: $contactData,
                        organizationUuid: $organizationUuid,
                        register: $register,
                        contactSchema: $contactSchema,
                        stats: $stats
                    );
                } catch (\Exception $e) {
                    $this->logger->error(
                        '[FLOW] Failed to process nested contact person',
                        [
                            'organizationId' => $organizationUuid,
                            'contactIndex'   => $index,
                            'exception'      => $e->getMessage(),
                        ]
                    );
                    $stats['errors'][] = "Contact person {$index}: ".$e->getMessage();
                }//end try
            }//end foreach
        } catch (\Exception $e) {
            $this->logger->error(
                '[FLOW] Failed to process nested contact persons',
                [
                    'organizationId' => $organizationObject->getUuid(),
                    'exception'      => $e->getMessage(),
                ]
            );
            $stats['errors'][] = 'Failed to process nested contact persons: '.$e->getMessage();
        }//end try

    }//end processNestedContactPersons()

    /**
     * Process related contactpersoon objects that have this organization in their organisation property
     *
     * @param string                            $organizationUuid   The organization UUID.
     * @param \OCA\OpenRegister\Db\ObjectEntity $organizationObject The organization object.
     * @param array                             $stats              The statistics array to update.
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) $organizationObject reserved for future contact person enrichment
     */
    private function processRelatedContactPersons(string $organizationUuid, $organizationObject, array &$stats): void
    {
        try {
            $this->logger->debug(
                'Finding related contact persons',
                [
                    'app'            => 'softwarecatalog',
                    'organizationId' => $organizationUuid,
                ]
            );

            // Get configuration.
            $voorzieningenConfig = $this->settingsService->getVoorzieningenConfig();
            $register            = ($voorzieningenConfig['register'] ?? '');
            $contactSchema       = ($voorzieningenConfig['contactpersoon_schema'] ?? '');

            if (empty($register) === true || empty($contactSchema) === true) {
                $this->logger->warning(
                    '[FLOW] Related contact processing skipped - configuration missing',
                    [
                        'organizationId' => $organizationUuid,
                        'register'       => $register,
                        'contactSchema'  => $contactSchema,
                    ]
                );
                return;
            }

            // Find all contactpersoon objects that have this organization in their organisation property.
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

            // Search for contactpersoon objects with this organization reference.
            // Try both 'organisatie' and 'organisation' field names.
            $query = [
                '@self'       => [
                    'register' => (int) $register,
                    'schema'   => (int) $contactSchema,
                ],
                'organisatie' => $organizationUuid,
            ];

            $this->logger->info(
                '[FLOW] Searching for related contact persons with organisatie field',
                [
                    'organizationId' => $organizationUuid,
                    'register'       => $register,
                    'contactSchema'  => $contactSchema,
                    'query'          => $query,
                ]
            );

            $relatedContacts = $objectService->searchObjects($query);

            // If not found, try with 'organisation' field.
            if (empty($relatedContacts) === true) {
                $query['organisation'] = $organizationUuid;
                unset($query['organisatie']);

                $this->logger->info(
                    '[FLOW] Retrying search with organisation field',
                    [
                        'organizationId' => $organizationUuid,
                        'query'          => $query,
                    ]
                );

                $relatedContacts = $objectService->searchObjects($query);
            }

            if (empty($relatedContacts) === true) {
                // Fallback: fetch contacts by UUID from the org's contactpersonen array.
                // This handles the case where contacts were linked via inversedBy (org → contact).
                // but the contact's own organisatie field was never set.
                $this->logger->info(
                    '[FLOW] No contacts found by organisatie field, checking org contactpersonen array',
                    ['organizationId' => $organizationUuid]
                );

                // Re-fetch the org object without _extend to get raw UUIDs.
                try {
                    $voorzieningenConfig2 = $this->settingsService->getVoorzieningenConfig();
                    $orgRegister          = ($voorzieningenConfig2['register'] ?? '');
                    $orgSchema            = ($voorzieningenConfig2['organisatie_schema'] ?? '');

                    $rawOrgObject = $objectService->find(
                        id: $organizationUuid,
                        register: $orgRegister,
                        schema: $orgSchema,
                        _rbac: false,
                        _multitenancy: false
                    );

                        $rawOrgData = [];
                    if ($rawOrgObject !== null) {
                        $rawOrgData = $rawOrgObject->getObject();
                    }

                    $contactUuids = ($rawOrgData['contactpersonen'] ?? []);

                    $this->logger->info(
                        '[FLOW] Raw org contactpersonen array',
                        [
                            'organizationId'  => $organizationUuid,
                            'contactpersonen' => $contactUuids,
                            'count'           => count($contactUuids),
                        ]
                    );

                    if (empty($contactUuids) === false) {
                        foreach ($contactUuids as $contactUuid) {
                            if (is_string($contactUuid) === false || empty($contactUuid) === true) {
                                continue;
                            }

                            try {
                                $contactObj = $objectService->find(
                                    id: $contactUuid,
                                    register: $register,
                                    schema: $contactSchema,
                                    _rbac: false,
                                    _multitenancy: false
                                );
                                if ($contactObj !== null) {
                                    $relatedContacts[] = $contactObj;
                                }
                            } catch (\Exception $fetchEx) {
                                $this->logger->warning(
                                    '[FLOW] Could not fetch contact from contactpersonen array',
                                    [
                                        'organizationId' => $organizationUuid,
                                        'contactUuid'    => $contactUuid,
                                        'exception'      => $fetchEx->getMessage(),
                                    ]
                                );
                            }//end try
                        }//end foreach
                    }//end if
                } catch (\Exception $orgEx) {
                    $this->logger->warning(
                        '[FLOW] Could not re-fetch org for contactpersonen fallback',
                        [
                            'organizationId' => $organizationUuid,
                            'exception'      => $orgEx->getMessage(),
                        ]
                    );
                }//end try

                if (empty($relatedContacts) === true) {
                    $this->logger->info(
                        '[FLOW] No related contact persons found (including contactpersonen fallback)',
                        ['organizationId' => $organizationUuid]
                    );
                    return;
                }
            }//end if

            $this->logger->info(
                '👥 PROCESSING RELATED CONTACT PERSONS',
                [
                    'app'            => 'softwarecatalog',
                    'organizationId' => $organizationUuid,
                    'contactCount'   => count($relatedContacts),
                ]
            );

            foreach ($relatedContacts as $contactObject) {
                try {
                    $contactUuid = $contactObject->getUuid();
                    $contactData = $contactObject->getObject();

                    // Check if contact data is complete (has email).
                    $email = ($contactData['email'] ?? $contactData['e-mailadres'] ?? '');
                    if (empty($email) === true && empty($contactUuid) === false) {
                        // Re-fetch the full contact object if email is missing.
                        $this->logger->info(
                            '[FLOW] Contact data incomplete, re-fetching full object',
                            [
                                'organizationId' => $organizationUuid,
                                'contactId'      => $contactUuid,
                            ]
                        );

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
                                $contactData   = $contactObject->getObject();
                                $email         = ($contactData['email'] ?? $contactData['e-mailadres'] ?? '');
                            }
                        } catch (\Exception $e) {
                            $this->logger->warning(
                                '[FLOW] Failed to re-fetch contact object',
                                [
                                    'organizationId' => $organizationUuid,
                                    'contactId'      => $contactUuid,
                                    'exception'      => $e->getMessage(),
                                ]
                            );
                        }//end try
                    }//end if

                    // Ensure the contact has the organisatie field set before processing.
                    // (may be missing when contacts were linked via inversedBy from the org side).
                    if (empty($contactData['organisatie']) === true) {
                        $contactData['organisatie'] = $organizationUuid;
                        $contactObject->setObject($contactData);
                        $objectMapper = $this->container->get('OCA\OpenRegister\Db\MagicMapper');
                        $objectMapper->update($contactObject);
                        $this->logger->info(
                            '[FLOW] Set missing organisatie field on related contact',
                            [
                                'contactId'        => $contactUuid,
                                'organizationUuid' => $organizationUuid,
                            ]
                        );
                    }

                    $contactEmailValue = $email;
                    if ($contactEmailValue === false || $contactEmailValue === '' || $contactEmailValue === null) {
                        $contactEmailValue = 'unknown';
                    }

                    $this->logger->info(
                        '[FLOW] Processing related contact person',
                        [
                            'organizationId' => $organizationUuid,
                            'contactId'      => $contactUuid,
                            'contactEmail'   => $contactEmailValue,
                        ]
                    );

                    // Process the contact person through processSpecificContactPerson.
                    $contactStats = $this->processSpecificContactPerson(contactObject: $contactObject);

                    // Merge stats.
                    $stats['contactPersonsProcessed'] += ($contactStats['contactPersonsProcessed'] ?? 0);
                    $stats['usersCreated']            += ($contactStats['usersCreated'] ?? 0);
                    $stats['usersUpdated']            += ($contactStats['usersUpdated'] ?? 0);
                    if (empty($contactStats['errors']) === false) {
                        $stats['errors'] = array_merge($stats['errors'], $contactStats['errors']);
                    }
                } catch (\Exception $e) {
                    $this->logger->error(
                        '[FLOW] Failed to process related contact person',
                        [
                            'organizationId' => $organizationUuid,
                            'contactId'      => $contactObject->getUuid(),
                            'exception'      => $e->getMessage(),
                        ]
                    );
                    $stats['errors'][] = "Related contact {$contactObject->getUuid()}: ".$e->getMessage();
                }//end try
            }//end foreach
        } catch (\Exception $e) {
            $this->logger->error(
                '[FLOW] Failed to process related contact persons',
                [
                    'organizationId' => $organizationUuid,
                    'exception'      => $e->getMessage(),
                    'file'           => $e->getFile(),
                    'line'           => $e->getLine(),
                ]
            );
            $stats['errors'][] = 'Failed to process related contact persons: '.$e->getMessage();
        }//end try

    }//end processRelatedContactPersons()

    /**
     * Create or update a contact person object and user account
     *
     * @param array  $contactData      The contact person data.
     * @param string $organizationUuid The organization UUID.
     * @param string $register         The register ID.
     * @param string $contactSchema    The contact schema ID.
     * @param array  $stats            The statistics array to update.
     *
     * @return void
     */
    private function createOrUpdateContactPersonObject(
        array $contactData,
        string $organizationUuid,
        string $register,
        string $contactSchema,
        array &$stats
    ): void {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

            $email = ($contactData['email'] ?? $contactData['e-mailadres'] ?? '');
            if (empty($email) === true) {
                $this->logger->warning(
                    '[FLOW] Contact person has no email, skipping',
                    [
                        'organizationId' => $organizationUuid,
                        'contactData'    => array_keys($contactData),
                    ]
                );
                return;
            }

            // Check if contact already exists (has id from cascading or previous creation).
            $existingContactId = ($contactData['id'] ?? $contactData['uuid'] ?? null);
            $contactObject     = null;

            if (empty($existingContactId) === false) {
                // Contact already exists - fetch it instead of trying to re-create.
                $this->logger->info(
                    '📧 FETCHING EXISTING CONTACT PERSON',
                    [
                        'app'            => 'softwarecatalog',
                        'organizationId' => $organizationUuid,
                        'contactId'      => $existingContactId,
                        'email'          => $email,
                    ]
                );

                try {
                    $contactObject = $objectService->find(
                        id: $existingContactId,
                        register: $register,
                        schema: $contactSchema,
                        _rbac: false,
                        _multitenancy: false
                    );
                } catch (\Exception $e) {
                    $this->logger->warning(
                        '[FLOW] Could not fetch existing contact, will create new',
                        [
                            'organizationId' => $organizationUuid,
                            'contactId'      => $existingContactId,
                            'exception'      => $e->getMessage(),
                        ]
                    );
                }
            }//end if

            // If contact doesn't exist, create it (but don't set organisatie as string - it's handled by inversedBy).
            if ($contactObject === null) {
                $this->logger->info(
                    '📧 CREATING NEW CONTACT PERSON OBJECT',
                    [
                        'app'            => 'softwarecatalog',
                        'organizationId' => $organizationUuid,
                        'email'          => $email,
                        'name'           => ($contactData['voornaam'] ?? '').' '.($contactData['achternaam'] ?? ''),
                    ]
                );

                // Temporarily remove organisatie from contactData to avoid validation error.
                // (schema expects object type but field stores a UUID string).
                $savedOrganisatie = $contactData['organisatie'] ?? null;
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

                // Restore the organisatie field so the link to the organisation is preserved.
                if ($contactObject !== false && $savedOrganisatie !== null) {
                    $restoredData = $contactObject->getObject();
                    $restoredData['organisatie'] = $savedOrganisatie;
                    $contactObject->setObject($restoredData);
                    $objectMapper = $this->container->get('OCA\OpenRegister\Db\MagicMapper');
                    $objectMapper->update($contactObject);
                }
            }//end if

            if (empty($contactObject) === false) {
                $stats['contactPersonsProcessed']++;

                // Ensure the contact has the organisatie field set (may be missing when linked via inversedBy).
                $contactObjectData = $contactObject->getObject();
                if (empty($contactObjectData['organisatie']) === true && empty($organizationUuid) === false) {
                    $contactObjectData['organisatie'] = $organizationUuid;
                    $contactObject->setObject($contactObjectData);
                    $contactObject->setOrganisation($organizationUuid);
                    $objectMapper = $this->container->get('OCA\OpenRegister\Db\MagicMapper');
                    $objectMapper->update($contactObject);
                    $this->logger->info(
                        '[FLOW] Set missing organisatie field on contact person',
                        [
                            'contactId'        => $contactObject->getUuid(),
                            'organizationUuid' => $organizationUuid,
                        ]
                    );
                }

                $this->logger->info(
                    '✅ CONTACT PERSON OBJECT READY',
                    [
                        'app'            => 'softwarecatalog',
                        'organizationId' => $organizationUuid,
                        'contactId'      => $contactObject->getUuid(),
                        'email'          => $email,
                        'wasExisting'    => empty($existingContactId) === false,
                    ]
                );

                // Create user account if username is missing AND organization is active.
                if (empty($contactObjectData['username']) === true) {
                    // Check if organization exists in organisation entity table.
                    $organisationEntity = null;
                    try {
                        $organisationMapper = $this->container->get('OCA\OpenRegister\Db\OrganisationMapper');
                        $organisationEntity = $organisationMapper->findByUuid($organizationUuid);
                    } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
                        // Backup: org entity missing — create it now so user creation can proceed.
                        $this->logger->info(
                            'Organisation entity missing for '.$organizationUuid.', creating backup entity',
                            [
                                'app'       => 'softwarecatalog',
                                'contactId' => $contactObject->getUuid(),
                            ]
                        );
                        try {
                            $voorzieningenConfig = $this->settingsService->getVoorzieningenConfig();
                            $orgObject           = $objectService->find(
                                id: $organizationUuid,
                                register: ($voorzieningenConfig['register'] ?? ''),
                                schema: ($voorzieningenConfig['organisatie_schema'] ?? '')
                            );
                            if (empty($orgObject) === false) {
                                $backupStats        = [
                                    'entitiesCreated' => 0,
                                    'entitiesUpdated' => 0,
                                ];
                                $organisationEntity = $this->ensureOrganisationEntity(
                                    organisatieObject: $orgObject,
                                    stats: $backupStats
                                );
                            }
                        } catch (\Exception $backupEx) {
                            $this->logger->error(
                                'Backup org entity creation failed',
                                [
                                    'app'            => 'softwarecatalog',
                                    'organizationId' => $organizationUuid,
                                    'error'          => $backupEx->getMessage(),
                                ]
                            );
                        }//end try
                    }//end try

                    try {
                        if ($organisationEntity !== false && $organisationEntity->getActive() === true) {
                            // Determine if this is the first contact for the organization.
                            $isFirstContact = $this->contactpersonHandler->isFirstContactForOrganization(
                                $contactObject,
                                $contactObjectData
                            );

                            $this->logger->info(
                                'Creating user account for contact person (org is active)',
                                [
                                    'app'            => 'softwarecatalog',
                                    'contactId'      => $contactObject->getUuid(),
                                    'organizationId' => $organizationUuid,
                                    'email'          => $email,
                                    'isFirstContact' => $isFirstContact,
                                ]
                            );

                            $user = $this->contactpersonHandler->createUserAccount($contactObject, $isFirstContact);
                            if (empty($user) === false) {
                                $stats['usersCreated']++;

                                // FIX #434: Refresh contactObjectData from the entity AFTER.
                                // createUserAccount() because createUserAccount() modifies the.
                                // entity in-place (sets username). Using the stale $contactObjectData.
                                // captured before createUserAccount() would overwrite those changes.
                                // and could lose the organisatie field.
                                $contactObjectData = $contactObject->getObject();
                                $contactObjectData['username'] = $user->getUID();

                                // FIX #434: Use MagicMapper::update() directly instead of.
                                // ObjectService::saveObject() to persist the username. This avoids:.
                                // 1. Having to strip the organisatie field (saveObject validates it.
                                // as object type but it is stored as a UUID string).
                                // 2. Triggering ObjectUpdatedEvent cascades that re-enter.
                                // processContactpersoon() with missing organisatie field, causing.
                                // the org lookup to fail.
                                // 3. Potential data loss from stale variables.
                                $contactObject->setObject($contactObjectData);

                                $this->logger->debug(
                                    'Saving contact with username via direct mapper update',
                                    [
                                        'app'            => 'softwarecatalog',
                                        'contactId'      => $contactObject->getUuid(),
                                        'username'       => $user->getUID(),
                                        'hasOrganisatie' => isset($contactObjectData['organisatie']) === true,
                                    ]
                                );

                                try {
                                    $objectMapper = $this->container->get('OCA\OpenRegister\Db\MagicMapper');
                                    $objectMapper->update($contactObject);
                                    $this->logger->info(
                                        'Contact saved with username',
                                        [
                                            'app'       => 'softwarecatalog',
                                            'contactId' => $contactObject->getUuid(),
                                            'username'  => $user->getUID(),
                                        ]
                                    );
                                } catch (\Exception $saveEx) {
                                    $this->logger->warning(
                                        'Failed to save username to contact object (user was created)',
                                        [
                                            'app'       => 'softwarecatalog',
                                            'contactId' => $contactObject->getUuid(),
                                            'username'  => $user->getUID(),
                                            'error'     => $saveEx->getMessage(),
                                        ]
                                    );
                                }//end try

                                // Add user to organization entity in database.
                                $this->contactpersonHandler->addUserToOrganizationEntity(
                                $contactObject,
                                $user->getUID(),
                                $organizationUuid
                                );

                                // Update contactpersoon object owner to user UID and organisation.
                                $this->updateContactpersoonObjectOwner(
                                    contactObject: $contactObject,
                                    userUID: $user->getUID(),
                                    register: $register,
                                    contactSchema: $contactSchema,
                                    organizationUuidOverride: $organizationUuid
                                );

                                $this->logger->info(
                                    'User account created',
                                    [
                                        'app'       => 'softwarecatalog',
                                        'contactId' => $contactObject->getUuid(),
                                        'username'  => $user->getUID(),
                                    ]
                                );
                            }//end if

                            if (empty($user) === true) {
                                $this->logger->error(
                                    'User account creation failed',
                                    [
                                        'app'       => 'softwarecatalog',
                                        'contactId' => $contactObject->getUuid(),
                                        'email'     => $email,
                                    ]
                                );
                                $stats['errors'][] = "Failed to create user account for {$email}";
                            }
                        }//end if

                        if ($organisationEntity === false || $organisationEntity->getActive() !== true) {
                            if ($organisationEntity !== null) {
                                $organizationActiveValue = $organisationEntity->getActive();
                            } else {
                                $organizationActiveValue = false;
                            }

                            $this->logger->info(
                                'Skipping user creation - organization not active or not found in entity table',
                                [
                                    'contactId'          => $contactObject->getUuid(),
                                    'organizationId'     => $organizationUuid,
                                    'organizationFound'  => $organisationEntity !== null,
                                    'organizationActive' => $organizationActiveValue,
                                    'email'              => $email,
                                ]
                            );
                        }
                    } catch (\Exception $e) {
                        // Organization not found in entity table = not active.
                        $this->logger->info(
                            'Skipping user creation - organization not found in entity table (not active)',
                            [
                                'contactId'      => $contactObject->getUuid(),
                                'organizationId' => $organizationUuid,
                                'reason'         => 'Organization not found in entity table',
                                'email'          => $email,
                            ]
                        );
                    }//end try
                }//end if
            }//end if
        } catch (\Exception $e) {
            $this->logger->error(
                '[FLOW] Failed to create/update contact person object',
                [
                    'organizationId' => $organizationUuid,
                    'email'          => ($contactData['email'] ?? $contactData['e-mailadres'] ?? 'unknown'),
                    'exception'      => $e->getMessage(),
                    'trace'          => $e->getTraceAsString(),
                ]
            );
            $stats['errors'][] = "Contact person creation failed: ".$e->getMessage();
        }//end try

    }//end createOrUpdateContactPersonObject()

    /**
     * Process a specific contact person object (called from event listener)
     *
     * This method processes a single contact person object, creating user accounts
     * and updating the contact person object as needed.
     *
     * @param \OCA\OpenRegister\Db\ObjectEntity $contactObject The contact person object to process
     *
     * @return array Processing results
     * @spec   openspec/changes/retrofit-2026-05-26-organization-sync/tasks.md#task-2
     */
    public function processSpecificContactPerson($contactObject): array
    {
        $stats = [
            'contactPersonsProcessed' => 0,
            'usersCreated'            => 0,
            'usersUpdated'            => 0,
            'errors'                  => [],
            'startTime'               => date('Y-m-d H:i:s'),
        ];

        try {
            $voorzieningenConfig = $this->settingsService->getVoorzieningenConfig();
            $register            = ($voorzieningenConfig['register'] ?? '');
            $contactSchema       = ($voorzieningenConfig['contactpersoon_schema'] ?? '');

            $this->logger->info(
                '[EVENT] OrganizationSyncService: Processing specific contact person',
                [
                    'contactId' => $contactObject->getUuid(),
                    'trigger'   => 'event_listener',
                ]
            );

            /*
             * @var array<string, mixed> $contactEntityObject
             */

            $contactEntityObject = $contactObject->getObject();

            // Skip if no organization reference.
            $organizationUuid = $contactEntityObject['organisatie'] ?? null;
            if (empty($organizationUuid) === true) {
                $this->logger->warning(
                    '[EVENT] OrganizationSyncService: Contact person has no organization reference',
                    [
                        'contactId' => $contactObject->getUuid(),
                    ]
                );
                return $stats;
            }

            // Create user account if username is missing AND organization is active.
            if (empty($contactEntityObject['username']) === true) {
                // Check if organization exists in organisation entity table.
                $organisationEntity = null;
                try {
                    $organisationMapper = $this->container->get('OCA\OpenRegister\Db\OrganisationMapper');
                    $organisationEntity = $organisationMapper->findByUuid($organizationUuid);
                } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
                    // Backup: org entity missing — create it now so user creation can proceed.
                    $this->logger->info(
                        '[EVENT] Organisation entity missing for '.$organizationUuid.', creating backup entity',
                        [
                            'app'       => 'softwarecatalog',
                            'contactId' => $contactObject->getUuid(),
                        ]
                    );
                    try {
                        $voorzieningenConfig = $this->settingsService->getVoorzieningenConfig();
                        $objectService       = $this->container->get('OCA\OpenRegister\Service\ObjectService');
                        $orgObject           = $objectService->find(
                            id: $organizationUuid,
                            register: ($voorzieningenConfig['register'] ?? ''),
                            schema: ($voorzieningenConfig['organisatie_schema'] ?? '')
                        );
                        if (empty($orgObject) === false) {
                            $backupStats        = [
                                'entitiesCreated' => 0,
                                'entitiesUpdated' => 0,
                            ];
                            $organisationEntity = $this->ensureOrganisationEntity(
                                organisatieObject: $orgObject,
                                stats: $backupStats
                            );
                        }
                    } catch (\Exception $backupEx) {
                        $this->logger->error(
                            'Backup org entity creation failed',
                            [
                                'app'            => 'softwarecatalog',
                                'organizationId' => $organizationUuid,
                                'error'          => $backupEx->getMessage(),
                            ]
                        );
                    }//end try
                }//end try

                try {
                    if ($organisationEntity !== false && $organisationEntity->getActive() === true) {
                        // Determine if this is the first contact for the organization.
                        $isFirstContact = $this->contactpersonHandler->isFirstContactForOrganization(
                            $contactObject,
                            $contactEntityObject
                        );

                        $contactEmail = ($contactEntityObject['email'] ?? $contactEntityObject['e-mailadres'] ?? 'unknown');
                        $this->logger->info(
                            '[EVENT] OrganizationSyncService: Creating user account for contact person (org is active)',
                            [
                                'contactId'      => $contactObject->getUuid(),
                                'organizationId' => $organizationUuid,
                                'orgActive'      => true,
                                'email'          => $contactEmail,
                                'isFirstContact' => $isFirstContact,
                            ]
                        );

                        $user = $this->contactpersonHandler->createUserAccount($contactObject, $isFirstContact);
                        // Check if user was created successfully (can be null if no email).
                        if ($user !== null) {
                            $contactEntityObject['username'] = $user->getUID();

                            // Temporarily remove organisatie field to avoid validation error.
                            // (it's stored as UUID string but schema expects object type).
                            // Save the value so we can restore it after the save.
                            $savedOrganisatie = $contactEntityObject['organisatie'] ?? null;
                            unset($contactEntityObject['organisatie']);

                            // Update the contact object with the username (using RBAC bypass).
                            // Wrapped in its own try/catch because saveObject triggers event listeners.
                            // that may fail — but the user was already created successfully above.
                            try {
                                $contactObject->setObject($contactEntityObject);
                                $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
                                $objectService->saveObject(
                                    object: $contactObject,
                                    register: $register,
                                    schema: $contactSchema,
                                    _rbac: false,
                                    _multitenancy: false
                                );
                            } catch (\Exception $saveEx) {
                                $this->logger->warning(
                                    '[EVENT] Failed to save username to contact object (user was created)',
                                    [
                                        'contactId' => $contactObject->getUuid(),
                                        'username'  => $user->getUID(),
                                        'error'     => $saveEx->getMessage(),
                                    ]
                                );
                            }

                            // Add user to organization entity in database.
                            $this->contactpersonHandler->addUserToOrganizationEntity(
                                $contactObject,
                                $user->getUID(),
                                $organizationUuid
                            );

                            // Update contactpersoon object owner to user UID.
                            $this->updateContactpersoonObjectOwner(
                                    contactObject: $contactObject,
                                    userUID: $user->getUID(),
                                    register: $register,
                                    contactSchema: $contactSchema,
                                    organizationUuidOverride: $organizationUuid
                                );

                            $stats['usersCreated']++;
                        }//end if

                        if ($user === null) {
                            $this->logger->debug(
                                '[EVENT] Skipping contact - user account creation failed (likely no email)',
                                [
                                    'app'       => 'softwarecatalog',
                                    'contactId' => $contactObject->getUuid(),
                                ]
                            );
                        }
                    }//end if

                    if ($organisationEntity === false || $organisationEntity->getActive() !== true) {
                        if ($organisationEntity !== null) {
                            $organizationActiveValue = $organisationEntity->getActive();
                        } else {
                            $organizationActiveValue = false;
                        }

                        $skipEmail = ($contactEntityObject['email'] ?? $contactEntityObject['e-mailadres'] ?? 'unknown');
                        $this->logger->info(
                            '[EVENT] OrganizationSyncService: Skipping user creation - org not active or entity not found',
                            [
                                'contactId'      => $contactObject->getUuid(),
                                'organizationId' => $organizationUuid,
                                'orgFound'       => $organisationEntity !== null,
                                'orgActive'      => $organizationActiveValue,
                                'email'          => $skipEmail,
                            ]
                        );
                    }
                } catch (\Exception $e) {
                    $this->logger->error(
                        '[EVENT] User creation failed for contact',
                        [
                            'contactId'      => $contactObject->getUuid(),
                            'organizationId' => $organizationUuid,
                            'error'          => $e->getMessage(),
                        ]
                    );
                }//end try
            }//end if

            $stats['contactPersonsProcessed']++;
            $stats['endTime'] = date('Y-m-d H:i:s');
            $endTs            = (new \DateTime($stats['endTime']))->getTimestamp();
            $startTs          = (new \DateTime($stats['startTime']))->getTimestamp();
            $stats['duration'] = ($endTs - $startTs);

            $this->logger->info(
                '[EVENT] OrganizationSyncService: Specific contact person processing completed',
                [
                    'contactId' => $contactObject->getUuid(),
                    'stats'     => $stats,
                ]
            );

            return $stats;
        } catch (\Exception $e) {
            $stats['errors'][] = $e->getMessage();
            $this->logger->error(
                '[EVENT] OrganizationSyncService: Failed to process specific contact person',
                [
                    'contactId' => $contactObject->getUuid(),
                    'exception' => $e->getMessage(),
                ]
            );

            return $stats;
        }//end try

    }//end processSpecificContactPerson()

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
     * @spec   openspec/changes/retrofit-2026-05-26-organization-sync/tasks.md#task-1
     */
    public function performOptimizedManualSync(int $maxRounds=10, int $batchSize=100): array
    {
        $totalStartTime = time();
        $allResults     = [
            'totalRounds'             => 0,
            'organizationsProcessed'  => 0,
            'contactPersonsProcessed' => 0,
            'entitiesCreated'         => 0,
            'entitiesUpdated'         => 0,
            'usersCreated'            => 0,
            'usersUpdated'            => 0,
            'totalExecutionTime'      => 0,
            'timeoutReached'          => false,
            'roundsCompleted'         => [],
            'errors'                  => [],
            'startTime'               => date('Y-m-d H:i:s'),
        ];

        $this->logger->info(
            '[MANUAL] OrganizationSyncService: Starting optimized manual sync',
            [
                'maxRounds' => $maxRounds,
                'batchSize' => $batchSize,
                'trigger'   => 'manual',
            ]
        );

        for ($round = 1; $round <= $maxRounds; $round++) {
            $roundStartTime = time();

            // Process organizations batch.
            $orgResults = $this->performOrganizationsSync(batchSize: $batchSize, maxExecutionSeconds: 45);

            // Process contacts batch.
            $contactResults = $this->performContactSync(batchSize: $batchSize, maxExecutionSeconds: 15);

            // Accumulate results.
            $allResults['organizationsProcessed']  += $orgResults['organizationsProcessed'];
            $allResults['contactPersonsProcessed'] += $contactResults['contactPersonsProcessed'];
            $allResults['entitiesCreated']         += $orgResults['entitiesCreated'];
            $allResults['entitiesUpdated']         += $orgResults['entitiesUpdated'];
            $allResults['usersCreated']            += $contactResults['usersCreated'] ?? 0;
            $allResults['usersUpdated']            += $contactResults['usersUpdated'] ?? 0;

            $roundTime = (time() - $roundStartTime);
            $allResults['roundsCompleted'][] = [
                'round'                   => $round,
                'organizationsProcessed'  => $orgResults['organizationsProcessed'],
                'contactPersonsProcessed' => $contactResults['contactPersonsProcessed'],
                'duration'                => $roundTime,
                'orgTimeoutReached'       => $orgResults['timeoutReached'] ?? false,
                'contactTimeoutReached'   => $contactResults['timeoutReached'] ?? false,
            ];

            // If no items were processed in this round, we're done.
            if ($orgResults['organizationsProcessed'] === 0 && $contactResults['contactPersonsProcessed'] === 0) {
                $this->logger->info(
                    '[MANUAL] OrganizationSyncService: No more items to process, stopping',
                    [
                        'round'          => $round,
                        'totalProcessed' => ($allResults['organizationsProcessed'] + $allResults['contactPersonsProcessed']),
                    ]
                );
                break;
            }

            $allResults['totalRounds'] = $round;

            // No sleep between rounds — this runs in an HTTP worker;
            // blocking the worker for up to 10 s degrades concurrency.
        }//end for

        // Final user sync.
        $this->performUserSync();
        $this->recordSyncTime();

        $allResults['totalExecutionTime'] = (time() - $totalStartTime);
        $allResults['endTime']            = date('Y-m-d H:i:s');

        $this->logger->info('[MANUAL] OrganizationSyncService: Optimized manual sync completed', $allResults);

        return $allResults;

    }//end performOptimizedManualSync()

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
     * @spec   openspec/changes/retrofit-2026-05-26-organization-sync/tasks.md#task-1
     */
    public function performScheduledSync(int $minutesBack=0): array
    {
        if ($minutesBack === 0) {
            $syncModeValue = 'full';
        } else {
            $syncModeValue = 'incremental';
        }

        $this->logger->info(
            '[CRONJOB] OrganizationSyncService: Starting scheduled synchronization',
            [
                'minutesBack' => $minutesBack,
                'syncMode'    => $syncModeValue,
                'trigger'     => 'cronjob',
            ]
        );

        try {
            // Perform optimized batch synchronization.
            // Use smaller batches for scheduled sync to ensure it completes within time limits.
            $orgBatchSize = 25;
            // Conservative batch size for organizations.
            $contactBatchSize = 50;
            // Larger batch size for contacts (faster processing).
            $maxOrgTime = 30;
            // 30 seconds max for organizations.
            $maxContactTime = 15;
            // 15 seconds max for contacts.
            $syncResults = $this->performOrganizationsSync(batchSize: $orgBatchSize, maxExecutionSeconds: $maxOrgTime);

            $contactResults = $this->performContactSync(batchSize: $contactBatchSize, maxExecutionSeconds: $maxContactTime);
            $syncResults    = array_merge($contactResults, $syncResults);

            $this->performUserSync();
            // Record the sync time.
            $this->recordSyncTime();

            // Log summary results.
            $this->logger->info(
                '[CRONJOB] OrganizationSyncService: Scheduled synchronization completed',
                [
                    'organizationsProcessed'  => $syncResults['organizationsProcessed'] ?? 0,
                    'entitiesCreated'         => $syncResults['entitiesCreated'] ?? 0,
                    'entitiesUpdated'         => $syncResults['entitiesUpdated'] ?? 0,
                    'contactPersonsProcessed' => $syncResults['contactPersonsProcessed'] ?? 0,
                    'errorCount'              => count($syncResults['errors'] ?? []),
                    'duration'                => $syncResults['duration'] ?? 0,
                ]
            );

            // Log errors if any occurred.
            if (empty($syncResults['errors']) === false) {
                $this->logger->warning(
                    'OrganizationSyncService: Scheduled synchronization completed with errors',
                    [
                        'errors' => $syncResults['errors'],
                    ]
                );
            }

            return $syncResults;
        } catch (\Exception $e) {
            $this->logger->error(
                '[CRONJOB] OrganizationSyncService: Scheduled synchronization failed',
                [
                    'exception' => $e->getMessage(),
                    'file'      => $e->getFile(),
                    'line'      => $e->getLine(),
                    'trace'     => $e->getTraceAsString(),
                ]
            );

            return [
                'organizationsProcessed'  => 0,
                'entitiesCreated'         => 0,
                'entitiesUpdated'         => 0,
                'contactPersonsProcessed' => 0,
                'usersCreated'            => 0,
                'usersUpdated'            => 0,
                'errors'                  => ['Scheduled synchronization failed: '.$e->getMessage()],
                'startTime'               => date('Y-m-d H:i:s'),
                'endTime'                 => date('Y-m-d H:i:s'),
                'duration'                => '00:00:00',
            ];
        }//end try

    }//end performScheduledSync()

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
     * @spec   openspec/changes/retrofit-2026-05-26-organization-sync/tasks.md#task-1
     */
    public function performManualSync(int $minutesBack=0): array
    {
        if ($minutesBack === 0) {
            $syncModeValue = 'full';
        } else {
            $syncModeValue = 'incremental';
        }

        $this->logger->info(
            'OrganizationSyncService: Manual organization synchronization started via API',
            [
                'minutesBack' => $minutesBack,
                'syncMode'    => $syncModeValue,
            ]
        );

        try {
            $syncResults = $this->performOrganizationsSync();

            $syncResults = array_merge($this->performContactSync(), $syncResults);

            $this->performUserSync();

            // Record the sync time for consistency with scheduled sync.
            $this->recordSyncTime();

            $this->logger->info(
                'OrganizationSyncService: Manual organization synchronization completed via API',
                [
                    'organizationsProcessed' => $syncResults['organizationsProcessed'],
                    'entitiesCreated'        => $syncResults['entitiesCreated'],
                    'entitiesUpdated'        => $syncResults['entitiesUpdated'],
                    'usersCreated'           => $syncResults['usersCreated'],
                    'errorCount'             => count($syncResults['errors']),
                ]
            );

            return [
                'success' => true,
                'results' => $syncResults,
                'message' => 'Synchronization completed successfully',
            ];
        } catch (\Exception $e) {
            $this->logger->error(
                'OrganizationSyncService: Manual organization synchronization failed via API',
                [
                    'exception' => $e->getMessage(),
                    'file'      => $e->getFile(),
                    'line'      => $e->getLine(),
                ]
            );

            return [
                'success' => false,
                'message' => 'Synchronization failed: '.$e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ];
        }//end try

    }//end performManualSync()

    /**
     * Gets synchronization status with error handling
     *
     * This method provides status information with proper error handling
     * for API responses.
     *
     * @param int $minutesBack The number of minutes to look back.
     *
     * @return array Status information with error handling.
     * @spec   openspec/changes/retrofit-2026-05-26-organization-sync/tasks.md#task-3
     */
    public function getSyncStatusWithErrorHandling(int $minutesBack=10): array
    {
        try {
            $status = $this->getSyncStatus(minutesBack: $minutesBack);
            return $status;
        } catch (\Exception $e) {
            $this->logger->error(
                'OrganizationSyncService: Failed to get sync status',
                [
                    'minutesBack' => $minutesBack,
                    'exception'   => $e->getMessage(),
                ]
            );

            if ($minutesBack === 0) {
                $syncModeValue = 'full';
            } else {
                $syncModeValue = 'incremental';
            }

            return [
                'configured' => false,
                'syncMode'   => $syncModeValue,
                'timeWindow' => $minutesBack,
                'message'    => 'Error getting sync status: '.$e->getMessage(),
            ];
        }//end try

    }//end getSyncStatusWithErrorHandling()

    /**
     * Updates the organisatie object's @self metadata to set owner to the organisation entity UUID
     *
     * @param object $organisatieObject  The organisatie object to update.
     * @param object $organisationEntity The organisation entity.
     * @param string $register           The register ID.
     * @param string $organizationSchema The organization schema ID.
     *
     * @return void
     */
    private function updateOrganisatieObjectOwner(
        object $organisatieObject,
        object $organisationEntity,
        string $register,
        string $organizationSchema
    ): void {
        try {
            $organisatieId          = $organisatieObject->getUuid();
            $organisationEntityUuid = $organisationEntity->getUuid();

            $this->logger->info(
                'OrganizationSyncService: Updating organisatie object owner and organisation',
                [
                    'organisatieId'          => $organisatieId,
                    'organisationEntityUuid' => $organisationEntityUuid,
                    'register'               => $register,
                    'schema'                 => $organizationSchema,
                ]
            );

            // Get the current object data.
            $currentObject = $organisatieObject->jsonSerialize();

            // Get current @self metadata or create new.
            $selfMetadata = ($currentObject['@self'] ?? []);

            // Check if both owner and organisation are already set correctly.
            $ownerAlreadySet        = ($selfMetadata['owner'] ?? null) === $organisationEntityUuid;
            $organisationAlreadySet = ($currentObject['organisation'] ?? null) === $organisationEntityUuid;

            if ($ownerAlreadySet !== false && $organisationAlreadySet === true) {
                $this->logger->debug(
                    'OrganizationSyncService: Owner and organisation already set correctly, skipping update',
                    [
                        'organisatieId'          => $organisatieId,
                        'organisationEntityUuid' => $organisationEntityUuid,
                    ]
                );
                return;
            }

            // Update the owner field in @self metadata to the organisation entity UUID.
            $selfMetadata['owner'] = $organisationEntityUuid;

            // Update the organisation field in @self metadata (so organisation owns itself).
            $selfMetadata['organisation'] = $organisationEntityUuid;

            // Update the organisation property to the organisation entity UUID (so organisation owns itself).
            $currentObject['organisation'] = $organisationEntityUuid;

            // Update the object with the new @self metadata.
            $currentObject['@self'] = $selfMetadata;
            $organisatieObject->setObject($currentObject);

            // Also update the entity's owner and organisation fields directly.
            // These are separate from the object data and control multi-tenancy.
            $organisatieObject->setOwner($organisationEntityUuid);
            $organisatieObject->setOrganisation($organisationEntityUuid);

            // Save using MagicMapper directly to bypass validation and ensure metadata is persisted.
            $objectMapper = $this->container->get('OCA\OpenRegister\Db\MagicMapper');
            $objectMapper->update($organisatieObject);

            $this->logger->info(
                'OrganizationSyncService: Successfully updated organisatie object owner and organisation',
                [
                    'organisatieId'          => $organisatieId,
                    'organisationEntityUuid' => $organisationEntityUuid,
                    'ownerSet'               => $selfMetadata['owner'],
                    'organisationSet'        => $currentObject['organisation'],
                ]
            );
        } catch (\Exception $e) {
            $this->logger->error(
                'OrganizationSyncService: Failed to update organisatie object owner and organisation',
                [
                    'organisatieId'          => $organisatieObject->getUuid(),
                    'organisationEntityUuid' => $organisationEntity->getUuid(),
                    'exception'              => $e->getMessage(),
                    'file'                   => $e->getFile(),
                    'line'                   => $e->getLine(),
                ]
            );
        }//end try

    }//end updateOrganisatieObjectOwner()

    /**
     * Updates the contactpersoon object's @self metadata to set owner to the user UID
     *
     * @param object      $contactObject            The contactpersoon object to update.
     * @param string      $userUID                  The user UID to set as owner.
     * @param string      $register                 The register ID.
     * @param string      $contactSchema            The contact schema ID.
     * @param string|null $organizationUuidOverride Optional organization UUID override.
     *
     * @return void
     */
    private function updateContactpersoonObjectOwner(
        object $contactObject,
        string $userUID,
        string $register,
        string $contactSchema,
        ?string $organizationUuidOverride=null
    ): void {
        try {
            $contactId = $contactObject->getUuid();

            $this->logger->info(
                'OrganizationSyncService: Updating contactpersoon object owner',
                [
                    'contactId'                => $contactId,
                    'userUID'                  => $userUID,
                    'register'                 => $register,
                    'schema'                   => $contactSchema,
                    'organizationUuidOverride' => $organizationUuidOverride,
                ]
            );

            // Get the current object data.
            $currentObject = $contactObject->getObject();

            // Get current @self metadata or create new.
            $selfMetadata = ($currentObject['@self'] ?? []);

            // Update the owner field to the user UID.
            $selfMetadata['owner'] = $userUID;

            // Set the organisation field in @self metadata to the organization UUID.
            // Use override if provided, otherwise try to get from object data.
            $orgUuid          = $currentObject['organisation'] ?? $currentObject['organisatie'] ?? '';
            $organizationUuid = ($organizationUuidOverride ?? $orgUuid);
            if (empty($organizationUuid) === false) {
                $selfMetadata['organisation'] = $organizationUuid;
                if (empty($organizationUuidOverride) === false) {
                    $sourceValue = 'override';
                } else {
                    $sourceValue = 'object';
                }

                $this->logger->info(
                    'OrganizationSyncService: Setting @self.organisation metadata',
                    [
                        'contactId'        => $contactId,
                        'organizationUuid' => $organizationUuid,
                        'source'           => $sourceValue,
                    ]
                );
            }//end if

            // Restore the organisatie field if it was removed during username save.
            // This is the safety net that ensures the contact→org link is never lost.
            if (empty($organizationUuid) === false && empty($currentObject['organisatie']) === true) {
                $currentObject['organisatie'] = $organizationUuid;
                $this->logger->info(
                    'OrganizationSyncService: Restored missing organisatie field on contact person',
                    [
                        'contactId'        => $contactId,
                        'organizationUuid' => $organizationUuid,
                    ]
                );
            }

            // Update the object with the new @self metadata.
            $currentObject['@self'] = $selfMetadata;
            $contactObject->setObject($currentObject);

            // Also update the entity's owner and organisation fields directly.
            // These are separate from the object data and control multi-tenancy.
            $contactObject->setOwner($userUID);
            if (empty($organizationUuid) === false) {
                $contactObject->setOrganisation($organizationUuid);
            }

            // Save using MagicMapper directly to bypass validation and ensure metadata is persisted.
            $objectMapper = $this->container->get('OCA\OpenRegister\Db\MagicMapper');
            $objectMapper->update($contactObject);

            $this->logger->info(
                'OrganizationSyncService: Successfully updated contactpersoon object owner and organisation',
                [
                    'contactId'       => $contactId,
                    'userUID'         => $userUID,
                    'ownerSet'        => $selfMetadata['owner'],
                    'organisationSet' => ($selfMetadata['organisation'] ?? 'not set'),
                ]
            );
        } catch (\Exception $e) {
            $this->logger->error(
                'OrganizationSyncService: Failed to update contactpersoon object owner',
                [
                    'contactId' => $contactObject->getUuid(),
                    'userUID'   => $userUID,
                    'exception' => $e->getMessage(),
                    'file'      => $e->getFile(),
                    'line'      => $e->getLine(),
                ]
            );
        }//end try

    }//end updateContactpersoonObjectOwner()
}//end class
