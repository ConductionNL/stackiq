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

use OCA\SoftwareCatalog\Service\OrganisatieService;
use OCA\SoftwareCatalog\Service\ContactpersoonService;
use OCA\SoftwareCatalog\Service\SymfonyEmailService;
use OCP\IAppConfig;
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
        LoggerInterface $logger
    ) {
        $this->organisatieService = $organisatieService;
        $this->contactpersoonService = $contactpersoonService;
        $this->emailService = $emailService;
        $this->config = $config;
        $this->logger = $logger;
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
            $register = $this->config->getValueString('softwarecatalog', 'voorzieningen_register', '');
            $organizationSchema = $this->config->getValueString('softwarecatalog', 'voorzieningen_organisatie_schema', '');
            $contactSchema = $this->config->getValueString('softwarecatalog', 'voorzieningen_contactpersoon_schema', '');

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
            $objectData = $organisatieObject->getObject();
            $organisatieId = $objectData['id'] ?? $organisatieObject->getId();
            
            // Try to find existing organisation entity
            $organisationMapper = \OC::$server->get('OCA\OpenRegister\Db\OrganisationMapper');
            
            try {
                $organisationEntity = $organisationMapper->findByUuid($organisatieId);
                
                // Entity exists - update it if needed
                $beoordeling = strtolower($objectData['beoordeling'] ?? 'actief');
                $shouldBeActive = in_array($beoordeling, ['actief', 'active']);
                
                if ($organisationEntity->getActive() !== $shouldBeActive) {
                    $this->logger->info('OrganizationSyncService: Updating organisation entity status', [
                        'organisatieId' => $organisatieId,
                        'oldActive' => $organisationEntity->getActive(),
                        'newActive' => $shouldBeActive
                    ]);
                    
                    $organisationEntity->setActive($shouldBeActive);
                    $organisationMapper->save($organisationEntity);
                    $stats['entitiesUpdated']++;

                    // Send activation email if organization became active
                    if ($shouldBeActive && !$organisationEntity->getActive()) {
                        $emailSent = $this->sendOrganizationActivationEmail($objectData);
                        if ($emailSent) {
                            $this->logger->info('OrganizationSyncService: Organization activation email sent successfully', [
                                'organisatieId' => $organisatieId
                            ]);
                        } else {
                            $this->logger->info('OrganizationSyncService: Organization activation email not sent (disabled or not configured)', [
                                'organisatieId' => $organisatieId
                            ]);
                        }
                    }
                }
                
                $this->logger->debug('OrganizationSyncService: Found existing organisation entity', [
                    'organisatieId' => $organisatieId,
                    'entityId' => $organisationEntity->getId(),
                    'active' => $organisationEntity->getActive()
                ]);
                return $organisationEntity;
            } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
                // Entity doesn't exist, create it
                $this->logger->info('OrganizationSyncService: Creating new organisation entity', [
                    'organisatieId' => $organisatieId
                ]);
                
                $organisationEntity = $this->organisatieService->createOrganisationInOpenRegister($objectData);
                if ($organisationEntity) {
                    $stats['entitiesCreated']++;
                    $this->logger->info('OrganizationSyncService: Successfully created organisation entity', [
                        'organisatieId' => $organisatieId,
                        'entityId' => $organisationEntity->getId(),
                        'active' => $organisationEntity->getActive()
                    ]);

                    // Send registration email for new organization
                    $emailSent = $this->sendOrganizationRegistrationEmail($objectData);
                    if ($emailSent) {
                        $this->logger->info('OrganizationSyncService: Organization registration email sent successfully', [
                            'organisatieId' => $organisatieId
                        ]);
                    } else {
                        $this->logger->info('OrganizationSyncService: Organization registration email not sent (disabled or not configured)', [
                            'organisatieId' => $organisatieId
                        ]);
                    }
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
            $register = $this->config->getValueString('softwarecatalog', 'voorzieningen_register', '');
            $organizationSchema = $this->config->getValueString('softwarecatalog', 'voorzieningen_organisatie_schema', '');
            $contactSchema = $this->config->getValueString('softwarecatalog', 'voorzieningen_contactpersoon_schema', '');

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
     * Performs scheduled synchronization with comprehensive logging
     *
     * This method is designed to be called by the background job and includes
     * all necessary logging, error handling, and status tracking.
     * Uses default 10-minute lookback for incremental sync.
     *
     * @param int $minutesBack Number of minutes to look back for changes (default: 10)
     * 
     * @return array Synchronization results with detailed logging information
     */
    public function performScheduledSync(int $minutesBack = 10): array
    {
        $this->logger->info('OrganizationSyncService: Starting scheduled synchronization', [
            'minutesBack' => $minutesBack,
            'syncMode' => $minutesBack === 0 ? 'full' : 'incremental'
        ]);

        try {
            // Perform the core synchronization with time-based filtering
            $syncResults = $this->performFullSync($minutesBack);

            // Record the sync time
            $this->recordSyncTime();

            // Log summary results
            $this->logger->info('OrganizationSyncService: Scheduled synchronization completed', [
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
            $this->logger->error('OrganizationSyncService: Scheduled synchronization failed', [
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
            // Perform the core synchronization with time-based filtering
            $syncResults = $this->performFullSync($minutesBack);

            // Record the sync time
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
                'message' => 'Synchronization failed: ' . $e->getMessage()
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
} 