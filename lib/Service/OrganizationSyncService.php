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

use OCA\SoftwareCatalog\Service\SoftwareCatalogueService;
use OCP\IConfig;
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
     * SoftwareCatalogueService instance
     *
     * @var SoftwareCatalogueService The main service for catalog operations
     */
    private SoftwareCatalogueService $softwareCatalogueService;

    /**
     * Configuration service instance
     *
     * @var IConfig The Nextcloud configuration service
     */
    private IConfig $config;

    /**
     * Logger instance
     *
     * @var LoggerInterface The logger for sync operations
     */
    private LoggerInterface $logger;

    /**
     * Constructor for OrganizationSyncService
     *
     * @param SoftwareCatalogueService $softwareCatalogueService The main catalog service
     * @param IConfig                  $config                   The configuration service
     * @param LoggerInterface          $logger                   The logger instance
     */
    public function __construct(
        SoftwareCatalogueService $softwareCatalogueService,
        IConfig $config,
        LoggerInterface $logger
    ) {
        $this->softwareCatalogueService = $softwareCatalogueService;
        $this->config = $config;
        $this->logger = $logger;
    }

    /**
     * Performs comprehensive organization and contact person synchronization
     *
     * This method synchronizes all organisatie objects with organisation entities,
     * ensures contact persons have user accounts, and maintains proper relationships.
     *
     * @return array Synchronization results and statistics
     */
    public function performFullSync(): array
    {
        $this->logger->info('OrganizationSyncService: Starting comprehensive organization synchronization');
        
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
            $register = $this->config->getAppValue('softwarecatalog', 'voorzieningen_register', '');
            $organizationSchema = $this->config->getAppValue('softwarecatalog', 'voorzieningen_organisatie_schema', '');
            $contactSchema = $this->config->getAppValue('softwarecatalog', 'voorzieningen_contactpersoon_schema', '');

            if (empty($register) || empty($organizationSchema)) {
                $error = 'Missing configuration: register or organization schema not configured';
                $this->logger->error('OrganizationSyncService: ' . $error);
                $stats['errors'][] = $error;
                return $stats;
            }

            // Get all organisatie objects
            $organisatieObjects = $this->getAllOrganisatieObjects($register, $organizationSchema);
            $this->logger->info('OrganizationSyncService: Found organisatie objects', [
                'count' => count($organisatieObjects)
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
     * Gets all organisatie objects from the specified register and schema
     *
     * @param string $register The register ID
     * @param string $organizationSchema The organization schema ID
     *
     * @return array Array of organisatie objects
     */
    private function getAllOrganisatieObjects(string $register, string $organizationSchema): array
    {
        try {
            $objectService = \OC::$server->get('OCA\OpenRegister\Service\ObjectService');
            
            $objects = $objectService->findAll(
                [],
                (int) $register,
                (int) $organizationSchema
            );

            $this->logger->debug('OrganizationSyncService: Retrieved organisatie objects', [
                'register' => $register,
                'schema' => $organizationSchema,
                'count' => count($objects)
            ]);

            return $objects;

        } catch (\Exception $e) {
            $this->logger->error('OrganizationSyncService: Failed to get organisatie objects', [
                'register' => $register,
                'schema' => $organizationSchema,
                'exception' => $e
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
            $objectData = $organisatieObject->getObject();
            $organisatieId = $objectData['id'] ?? $organisatieObject->getId();
            
            // Try to find existing organisation entity
            $organisationMapper = \OC::$server->get('OCA\OpenRegister\Db\OrganisationMapper');
            
            try {
                $organisationEntity = $organisationMapper->findByUuid($organisatieId);
                $this->logger->debug('OrganizationSyncService: Found existing organisation entity', [
                    'organisatieId' => $organisatieId,
                    'entityId' => $organisationEntity->getId()
                ]);
                return $organisationEntity;
            } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
                // Entity doesn't exist, create it
                $this->logger->info('OrganizationSyncService: Creating new organisation entity', [
                    'organisatieId' => $organisatieId
                ]);
                
                $organisationEntity = $this->softwareCatalogueService->createOrganisationInOpenRegister($objectData);
                if ($organisationEntity) {
                    $stats['entitiesCreated']++;
                    $this->logger->info('OrganizationSyncService: Successfully created organisation entity', [
                        'organisatieId' => $organisatieId,
                        'entityId' => $organisationEntity->getId(),
                        'active' => $organisationEntity->getActive()
                    ]);
                }
                return $organisationEntity;
            }
            
        } catch (\Exception $e) {
            $this->logger->error('OrganizationSyncService: Failed to ensure organisation entity', [
                'organisatieId' => $organisatieObject->getId(),
                'exception' => $e
            ]);
            return null;
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
            
            $contactPersons = $objectService->findAll(
                ['organisation' => $organisatieId],
                (int) $register,
                (int) $contactSchema
            );

            $this->logger->debug('OrganizationSyncService: Retrieved contact persons', [
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
            $email = $contactData['email'] ?? '';
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

                $success = $this->softwareCatalogueService->processContactpersoon($contactPerson, false);
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
     * Performs a quick sync status check
     *
     * @return array Status information about sync requirements
     */
    public function getSyncStatus(): array
    {
        try {
            // Check configuration
            $register = $this->config->getAppValue('softwarecatalog', 'voorzieningen_register', '');
            $organizationSchema = $this->config->getAppValue('softwarecatalog', 'voorzieningen_organisatie_schema', '');
            $contactSchema = $this->config->getAppValue('softwarecatalog', 'voorzieningen_contactpersoon_schema', '');

            if (empty($register) || empty($organizationSchema)) {
                return [
                    'configured' => false,
                    'message' => 'Sync not configured: missing register or organization schema'
                ];
            }

            // Get counts
            $organisatieObjects = $this->getAllOrganisatieObjects($register, $organizationSchema);
            $organisationMapper = \OC::$server->get('OCA\OpenRegister\Db\OrganisationMapper');
            
            $entitiesCount = 0;
            try {
                $entities = $organisationMapper->findAll();
                $entitiesCount = count($entities);
            } catch (\Exception $e) {
                // Ignore errors in count
            }

            return [
                'configured' => true,
                'organizationObjects' => count($organisatieObjects),
                'organizationEntities' => $entitiesCount,
                'contactSchemaConfigured' => !empty($contactSchema),
                'lastSyncTime' => $this->config->getAppValue('softwarecatalog', 'last_sync_time', 'Never'),
                'message' => 'Sync is configured and ready'
            ];

        } catch (\Exception $e) {
            return [
                'configured' => false,
                'message' => 'Error checking sync status: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Records the last sync time
     *
     * @return void
     */
    public function recordSyncTime(): void
    {
        $this->config->setAppValue('softwarecatalog', 'last_sync_time', date('Y-m-d H:i:s'));
    }
} 