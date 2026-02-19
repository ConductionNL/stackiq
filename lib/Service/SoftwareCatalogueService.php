<?php

/**
 * Software Catalogue Service
 *
 * Service for handling software catalog specific operations including 
 * user management, contact processing, and object lifecycle management.
 *
 * @category Service
 * @package  OCA\SoftwareCatalog\Service
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @version  1.0.0
 * @link     https://github.com/ConductionNL/SoftwareCatalog
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Service;

use OCA\SoftwareCatalog\Service\SoftwareCatalogue\OrganizationHandler;
use OCA\SoftwareCatalog\Service\SoftwareCatalogue\ContactPersonHandler;
use OCA\SoftwareCatalog\Service\SoftwareCatalogue\GroupHandler;
use OCA\SoftwareCatalog\Service\SoftwareCatalogue\HierarchyHandler;
use OCA\SoftwareCatalog\Service\SymfonyEmailService;
use Psr\Log\LoggerInterface;
use Psr\Container\ContainerInterface;
use OCP\App\IAppManager;

/**
 * Service for handling software catalog operations
 *
 * Provides functionality for user management, contact processing,
 * email notifications, and object lifecycle management.
 *
 * @category Service
 * @package  OCA\SoftwareCatalog\Service
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @version  1.0.0
 * @link     https://github.com/ConductionNL/SoftwareCatalog
 */
class SoftwareCatalogueService
{
    /**
     * The name of the app
     *
     * @var string
     */
    private string $_appName;

    /**
     * SoftwareCatalogueService constructor
     *
     * @param OrganizationHandler   $_organizationHandler  Organization handler
     * @param ContactPersonHandler  $_contactPersonHandler Contact person handler
     * @param GroupHandler          $_groupHandler         Group handler
     * @param HierarchyHandler      $_hierarchyHandler     Hierarchy handler
     * @param SymfonyEmailService   $_emailService         Email service
     * @param LoggerInterface       $_logger               Logger interface
     */
    public function __construct(
        private readonly OrganizationHandler $_organizationHandler,
        private readonly ContactPersonHandler $_contactPersonHandler,
        private readonly GroupHandler $_groupHandler,
        private readonly HierarchyHandler $_hierarchyHandler,
        private readonly SymfonyEmailService $_emailService,
        private readonly LoggerInterface $_logger,
        private readonly ContainerInterface $_container,
        private readonly IAppManager $_appManager,
    ) {
        $this->_appName = 'softwarecatalog';
    }

    /**
     * Gets the ObjectService instance
     *
     * @return \OCA\OpenRegister\Service\ObjectService|null
     */
    private function _getObjectService(): ?\OCA\OpenRegister\Service\ObjectService
    {
        if (!$this->_appManager->isEnabledForUser('openregister')) {
            return null;
        }

        try {
            return $this->_container->get('OCA\\OpenRegister\\Service\\ObjectService');
        } catch (\Exception $e) {
            $this->_logger->error('Failed to get ObjectService: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Gets the OrganisationService instance
     *
     * @return \OCA\OpenRegister\Service\OrganisationService|null
     */
    private function _getOrganisationService(): ?\OCA\OpenRegister\Service\OrganisationService
    {
        if (!$this->_appManager->isEnabledForUser('openregister')) {
            return null;
        }

        try {
            return $this->_container->get('OCA\\OpenRegister\\Service\\OrganisationService');
        } catch (\Exception $e) {
            $this->_logger->error('Failed to get OrganisationService: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Processes a contactpersoon object to create an inactive user
     *
     * If the contactpersoon object doesn't have a user or the user is missing,
     * this method will create an inactive user account.
     *
     * @param object $contactpersoonObject The contactpersoon object to process
     * @param bool   $isUpdate             Whether this is an update operation (defaults to false)
     * 
     * @return bool True if processing was successful
     * @throws \Exception If processing fails
     */
    public function processContactpersoon(object $contactpersoonObject, bool $isUpdate = false): bool
    {
        $startTime = microtime(true);
        
        try {
            $objectId = $contactpersoonObject->getId();
            $objectData = $contactpersoonObject->getObject();
            
            $this->_logger->info('SoftwareCatalogueService: Starting contactpersoon processing', [
                'objectId' => $objectId,
                'objectData' => $objectData,
                'timestamp' => date('Y-m-d H:i:s')
            ]);

            // Delegate to contact person handler
            $this->_logger->debug('SoftwareCatalogueService: Delegating to ContactPersonHandler for contactpersoon processing', [
                'objectId' => $objectId
            ]);
            
            $result = $this->_contactPersonHandler->processContactpersoon($contactpersoonObject, $isUpdate);
            
            $this->_logger->info('SoftwareCatalogueService: ContactPersonHandler processing completed', [
                'objectId' => $objectId,
                'result' => $result,
                'processingTime' => round((microtime(true) - $startTime) * 1000, 2) . 'ms'
            ]);
            
            if ($result) {
                // Get the username from the processed object
                $updatedObjectData = $contactpersoonObject->getObject();
                $username = $updatedObjectData['username'] ?? '';
                
                $this->_logger->info('SoftwareCatalogueService: Username extracted from processed object', [
                    'objectId' => $objectId,
                    'username' => $username,
                    'hasUsername' => !empty($username)
                ]);
                
                if (!empty($username)) {
                    // NOTE: Group assignment is already handled by ContactPersonHandler.assignUserGroups()
                    // during user creation, so we don't need to call GroupHandler.updateUserGroups() here
                    // as it would overwrite the correct group assignments.
                    
                    // Ensure organization has beheerder and set up manager relationships
                    $this->_logger->debug('SoftwareCatalogueService: Ensuring organization beheerder', [
                        'objectId' => $objectId,
                        'username' => $username
                    ]);
                    
                    $this->_hierarchyHandler->ensureOrganizationBeheerder($contactpersoonObject, $username);
                    
                    // Set user to inactive initially
                    $this->_logger->debug('SoftwareCatalogueService: Setting user to inactive', [
                        'objectId' => $objectId,
                        'username' => $username
                    ]);
                    
                    $this->_contactPersonHandler->setUserInactive($username);
                    
                    $this->_logger->info('SoftwareCatalogueService: User setup completed', [
                        'objectId' => $objectId,
                        'username' => $username,
                        'totalProcessingTime' => round((microtime(true) - $startTime) * 1000, 2) . 'ms'
                    ]);
                    
                    // Add the newly created user to the organization entity
                    $organisatie = $objectData['organisatie'] ?? null;
                    if ($organisatie) {
                        $this->_logger->info('SoftwareCatalogueService: Adding user to organization entity', [
                            'objectId' => $objectId,
                            'username' => $username,
                            'organisatie' => $organisatie
                        ]);
                        
                        try {
                            $organisationMapper = $this->_container->get('OCA\\OpenRegister\\Db\\OrganisationMapper');
                            $organisation = $organisationMapper->findByUuid($organisatie);
                            
                            if ($organisation) {
                                $currentUsers = $organisation->getUsers() ?? [];
                                if (!in_array($username, $currentUsers)) {
                                    $currentUsers[] = $username;
                                    $organisation->setUsers($currentUsers);
                                    $organisationMapper->save($organisation);
                                    
                                    $this->_logger->info('SoftwareCatalogueService: Successfully added user to organization entity', [
                                        'objectId' => $objectId,
                                        'username' => $username,
                                        'organisatie' => $organisatie,
                                        'totalUsers' => count($currentUsers)
                                    ]);
                                } else {
                                    $this->_logger->info('SoftwareCatalogueService: User already in organization entity', [
                                        'objectId' => $objectId,
                                        'username' => $username,
                                        'organisatie' => $organisatie
                                    ]);
                                }
                            } else {
                                $this->_logger->warning('SoftwareCatalogueService: Organization entity not found', [
                                    'objectId' => $objectId,
                                    'username' => $username,
                                    'organisatie' => $organisatie
                                ]);
                            }
                        } catch (\Exception $e) {
                            $this->_logger->error('SoftwareCatalogueService: Failed to add user to organization entity', [
                                'objectId' => $objectId,
                                'username' => $username,
                                'organisatie' => $organisatie,
                                'error' => $e->getMessage()
                            ]);
                        }
                    } else {
                        $this->_logger->warning('SoftwareCatalogueService: No organisation reference found for contact person', [
                            'objectId' => $objectId,
                            'username' => $username
                        ]);
                    }
                } else {
                    $this->_logger->warning('SoftwareCatalogueService: No username generated for contactpersoon', [
                        'objectId' => $objectId,
                        'objectData' => $updatedObjectData
                    ]);
                }
            } else {
                $this->_logger->warning('SoftwareCatalogueService: ContactPersonHandler returned false', [
                    'objectId' => $objectId,
                    'processingTime' => round((microtime(true) - $startTime) * 1000, 2) . 'ms'
                ]);
            }
            
            return $result;
            
        } catch (\Exception $e) {
            $this->_logger->error(
                'SoftwareCatalogueService: Failed to process contactpersoon object: ' . $e->getMessage(), 
                [
                    'exception' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString(),
                    'objectId' => $contactpersoonObject->getId() ?? 'unknown',
                    'processingTime' => round((microtime(true) - $startTime) * 1000, 2) . 'ms'
                ]
            );
            throw $e;
        }
    }

    /**
     * Processes a contactpersoon object to ensure it has a username
     *
     * If the contactpersoon object doesn't have a username or it's empty,
     * this method will create a user account and set the username property.
     *
     * @param object $contactpersoonObject The contactpersoon object to process
     * 
     * @return bool True if processing was successful
     * @throws \Exception If processing fails
     */


    /**
     * Processes organization without contactpersonen processing
     * 
     * @deprecated This method is disabled to prevent organization duplication
     * @param object $organizationObject The organization object to process
     * 
     * @return bool True if processing was successful
     * @throws \Exception If processing fails
     */
    public function processOrganization(object $organizationObject): bool
    {
        // DISABLED: Organization processing is disabled to prevent duplication
        $this->_logger->info(
            'Organization processing is disabled to prevent duplication',
            [
                'organizationId' => $organizationObject->getId()
            ]
        );
        
        return false;
        
        /*
        try {
            // Delegate to organization handler for basic processing
            $processed = $this->_organizationHandler->processOrganization($organizationObject);
            
            $this->_logger->info(
                'Successfully processed organization without contactpersonen',
                [
                    'organizationId' => $organizationObject->getId()
                ]
            );
            
            return $processed;
            
        } catch (\Exception $e) {
            $this->_logger->error(
                'Failed to process organization: ' . $e->getMessage(),
                [
                    'organizationId' => $organizationObject->getId(),
                    'exception' => $e
                ]
            );
            throw $e;
        }
        */
    }

    /**
     * Updates user groups based on contactpersoon data
     *
     * @param object $contactpersoonObject The contactpersoon object
     * @param string $username              The username to update groups for
     * 
     * @return void
     */
    public function updateUserGroups(object $contactpersoonObject, string $username): void
    {
        // Use the new organization type-based logic instead of old role-based logic
        $user = $this->_userManager->get($username);
        if ($user) {
            $contactData = $contactpersoonObject->getObject();
            $this->_contactPersonHandler->updateUserGroupsFromContactData($user, $contactData);
        } else {
            $this->_logger->warning('User not found for group update', ['username' => $username]);
        }
    }

    /**
     * Ensures organization has at least one beheerder and manages user hierarchy
     *
     * @param object $contactpersoonObject The contactpersoon object
     * @param string $username              The username being processed
     * 
     * @return void
     */
    public function ensureOrganizationBeheerder(object $contactpersoonObject, string $username): void
    {
        // Delegate to hierarchy handler
        $this->_hierarchyHandler->ensureOrganizationBeheerder($contactpersoonObject, $username);
    }

    /**
     * Gets a user's manager
     *
     * @param string $username The username
     * 
     * @return string|null The manager's username or null if not set
     */
    public function getUserManager(string $username): ?string
    {
        // Delegate to contact person handler
        return $this->_contactPersonHandler->getUserManager($username);
    }

    /**
     * Handles new organization creation - syncs with OpenRegister and processes organization
     *
     * @param object $organizationObject The new organization object
     * 
     * @return void
     */
    public function handleNewOrganization(object $organizationObject): void
    {
        try {
            $this->_logger->info('SoftwareCatalogueService: Handling new organization', [
                'objectId' => $organizationObject->getId()
            ]);

            // First, sync the organization with OpenRegister
            $syncResult = $this->syncOrganizationWithOpenRegister($organizationObject);
            
            if ($syncResult) {
                $this->_logger->info('SoftwareCatalogueService: Successfully synced organization with OpenRegister', [
                    'objectId' => $organizationObject->getId()
                ]);
                
                // Update organization references on objects to point to the newly created organization entity
                $this->updateOrganizationReferences($organizationObject);
            } else {
                $this->_logger->warning('SoftwareCatalogueService: Failed to sync organization with OpenRegister', [
                    'objectId' => $organizationObject->getId()
                ]);
            }

            // Process the organization (existing functionality) - this creates users
            $this->processOrganization($organizationObject);
            
            // Add all admin group users to the organization
            $objectData = $organizationObject->getObject();
            $organizationUuid = $objectData['id'] ?? $organizationObject->getId();
            $this->addAdminGroupUsersToOrganization($organizationUuid);
            
            // Handle ownership assignment for anonymous user registrations AFTER user creation
            $this->handleOwnershipAssignment($organizationObject);
            
            // Send welcome email for new organization
            $this->sendOrganizationWelcomeEmail($organizationObject);
            
            // If organization is active, send activation email too
            $objectData = $organizationObject->getObject();
            $beoordeling = strtolower($objectData['beoordeling'] ?? '');
            
            if ($beoordeling === 'actief') {
                try {
                    $success = $this->_emailService->sendOrganizationActivationEmail($objectData);
                    $this->_logger->info('Organization activation email sent', [
                        'objectId' => $organizationObject->getId(),
                        'success' => $success
                    ]);
                } catch (\Exception $e) {
                    $this->_logger->error('Failed to send organization activation email: ' . $e->getMessage(), [
                        'objectId' => $organizationObject->getId(),
                        'exception' => $e
                    ]);
                }
            }
            
            // Process nested contact persons and add their users to the organization entity
            $contactpersonen = $objectData['contactpersonen'] ?? [];
            if (!empty($contactpersonen)) {
                $this->_logger->info('SoftwareCatalogueService: Processing nested contact persons', [
                    'objectId' => $organizationObject->getId(),
                    'contactPersonCount' => count($contactpersonen)
                ]);
                
                $organizationUuid = $objectData['id'] ?? $organizationObject->getId();
                $objectService = $this->_getObjectService();
                
                if ($objectService) {
                    $settingsService = $this->_container->get('OCA\SoftwareCatalog\Service\SettingsService');
                    $voorzieningenConfig = $settingsService->getVoorzieningenConfig();
                    $contactSchemaId = $voorzieningenConfig['contactpersoon_schema'] ?? null;
                    
                    if (!$contactSchemaId) {
                        $this->_logger->warning('SoftwareCatalogueService: Missing contactpersoon schema configuration');
                        return;
                    }
                    $organisationMapper = $this->_container->get('OCA\\OpenRegister\\Db\\OrganisationMapper');
                    $organisation = $organisationMapper->findByUuid($organizationUuid);
                    
                    if ($organisation) {
                        $currentUsers = $organisation->getUsers() ?? [];
                        $addedUsers = [];
                        
                        foreach ($contactpersonen as $contactPersonId) {
                            try {
                                $contactPersonObject = $objectService->find($contactPersonId);
                                $contactData = $contactPersonObject->getObject();
                                $email = $contactData['email'] ?? null;
                                
                                if ($email && !in_array($email, $currentUsers)) {
                                    $currentUsers[] = $email;
                                    $addedUsers[] = $email;
                                    
                                    $this->_logger->info('SoftwareCatalogueService: Added nested contact person user to organization', [
                                        'objectId' => $organizationObject->getId(),
                                        'contactPersonId' => $contactPersonId,
                                        'username' => $email,
                                        'organizationUuid' => $organizationUuid
                                    ]);
                                }
                            } catch (\Exception $e) {
                                $this->_logger->warning('SoftwareCatalogueService: Failed to process nested contact person', [
                                    'objectId' => $organizationObject->getId(),
                                    'contactPersonId' => $contactPersonId,
                                    'error' => $e->getMessage()
                                ]);
                            }
                        }
                        
                        if (!empty($addedUsers)) {
                            $organisation->setUsers($currentUsers);
                            $organisationMapper->save($organisation);
                            
                            $this->_logger->info('SoftwareCatalogueService: Successfully updated organization with nested contact person users', [
                                'objectId' => $organizationObject->getId(),
                                'organizationUuid' => $organizationUuid,
                                'addedUsers' => $addedUsers,
                                'totalUsers' => count($currentUsers)
                            ]);
                        }
                    }
                }
            }
            
            // Final synchronization: ensure all contact persons associated with this organization are in the users array
            // This handles cases where contact persons were created separately and not as nested objects
            $objectData = $organizationObject->getObject();
            $organizationUuid = $objectData['id'] ?? $organizationObject->getId();
            $this->syncContactPersonUsernamesWithOrganization($organizationUuid);
            
            $this->_logger->info('SoftwareCatalogueService: Completed final contact person synchronization for new organization', [
                'objectId' => $organizationObject->getId(),
                'organizationUuid' => $organizationUuid
            ]);
            
        } catch (\Exception $e) {
            $this->_logger->error(
                'SoftwareCatalogueService: Failed to handle new organization: ' . $e->getMessage(),
                [
                    'objectId' => $organizationObject->getId(),
                    'exception' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString()
                ]
            );
        }
    }

    /**
     * Handles organization updates - syncs with OpenRegister and manages user status based on organization status
     *
     * @param object $organizationObject    The updated organization object
     * @param object $oldOrganizationObject The previous organization object
     * 
     * @return void
     */
    public function handleOrganizationUpdate(object $organizationObject, object $oldOrganizationObject): void
    {
        try {
            $this->_logger->info('SoftwareCatalogueService: Handling organization update', [
                'objectId' => $organizationObject->getId()
            ]);

        $newData = $organizationObject->getObject();
        $oldData = $oldOrganizationObject->getObject();
        
        // Check both 'beoordeling' and 'status' fields (different schemas use different field names)
        $newBeoordeling = strtolower($newData['beoordeling'] ?? $newData['status'] ?? '');
        $oldBeoordeling = strtolower($oldData['beoordeling'] ?? $oldData['status'] ?? '');
            
            // Sync the organization with OpenRegister
            $syncResult = $this->syncOrganizationWithOpenRegister($organizationObject);
            
            if ($syncResult) {
                $this->_logger->info('SoftwareCatalogueService: Successfully synced organization with OpenRegister', [
                    'objectId' => $organizationObject->getId()
                ]);
            } else {
                $this->_logger->warning('SoftwareCatalogueService: Failed to sync organization with OpenRegister', [
                    'objectId' => $organizationObject->getId()
                ]);
            }
            
            // Add all admin group users to the organization (ensure they're always included)
            $organizationUuid = $newData['id'] ?? $organizationObject->getId();
            $this->addAdminGroupUsersToOrganization($organizationUuid);
            
            // Check if organization status changed to active
            if ($newBeoordeling === 'actief') {
                $becameActive = ($oldBeoordeling !== 'actief');
                
                $this->_logger->info(
                    $becameActive ? 'Organization became active, activating users' : 'Organization is active',
                    [
                        'organizationId' => $organizationObject->getId(),
                        'oldBeoordeling' => $oldBeoordeling,
                        'newBeoordeling' => $newBeoordeling,
                        'becameActive' => $becameActive
                    ]
                );
                
                if ($becameActive) {
                    $organizationUuid = $newData['id'] ?? $organizationObject->getId();
                    
                    $this->_logger->info('SoftwareCatalogueService: Organization became active - creating users from contactpersonen', [
                        'organizationUuid' => $organizationUuid
                    ]);
                    
                    // Process the organization to create users from contactpersonen.
                    // This is crucial when an organization is activated for the first time
                    // and contactpersonen were added before activation.
                    $this->processOrganization($organizationObject);
                    
                    // Activate SoftwareCatalog-specific users in this organization
                    $this->activateSoftwareCatalogUsersForOrganization($organizationUuid);
                    
                    // Send activation email
                    try {
                        $success = $this->_emailService->sendOrganizationActivationEmail($newData);
                        $this->_logger->info('Organization activation email sent', [
                            'objectId' => $organizationObject->getId(),
                            'success' => $success
                        ]);
                    } catch (\Exception $e) {
                        $this->_logger->error('Failed to send organization activation email: ' . $e->getMessage(), [
                            'objectId' => $organizationObject->getId(),
                            'exception' => $e
                        ]);
                    }
                }
            }
            
            // Check if organization status changed to inactive
            if ($newBeoordeling === 'inactief' || $newBeoordeling === 'deactief') {
                $becameInactive = ($oldBeoordeling === 'actief');
                
                $this->_logger->info(
                    $becameInactive ? 'Organization became inactive, deactivating users' : 'Organization is inactive',
                    [
                        'organizationId' => $organizationObject->getId(),
                        'oldBeoordeling' => $oldBeoordeling,
                        'newBeoordeling' => $newBeoordeling,
                        'becameInactive' => $becameInactive
                    ]
                );
                
                if ($becameInactive) {
                    // Deactivate SoftwareCatalog-specific users in this organization
                    $organizationUuid = $newData['id'] ?? $organizationObject->getId();
                    $this->deactivateSoftwareCatalogUsersForOrganization($organizationUuid);
                }
            }
            
        } catch (\Exception $e) {
            $this->_logger->error(
                'SoftwareCatalogueService: Failed to handle organization update: ' . $e->getMessage(),
                [
                    'objectId' => $organizationObject->getId(),
                    'exception' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString()
                ]
            );
        }
    }

    /**
     * Activates contactpersonen users for an organization
     * 
     * @deprecated This method is disabled to prevent organization duplication
     * @param string $organizationId The organization ID
     * 
     * @return void
     */
    private function activateContactpersonenForOrganization(string $organizationId): void
    {
        // DISABLED: Organization handling is disabled to prevent duplication
        $this->_logger->info(
            'Organization contactpersonen activation is disabled to prevent duplication',
            [
                'organizationId' => $organizationId
            ]
        );
        
        return;
        
        /*
        try {
            $this->_logger->info('Activating contactpersonen for organization', [
                'organizationId' => $organizationId
            ]);
            
            // Get ObjectService to find contactpersonen
            $objectService = $this->_getObjectService();
            if (!$objectService) {
                $this->_logger->error('ObjectService not available');
                return;
            }
            
            // Get settings service to get schema IDs
            $settingsService = $this->_container->get('OCA\SoftwareCatalog\Service\SettingsService');
            $voorzieningenConfig = $settingsService->getVoorzieningenConfig();
            $registerId = $voorzieningenConfig['register'] ?? null;
            
            // Find contactpersonen related to this organization
            $contactpersoonSchemaId = $voorzieningenConfig['contactpersoon_schema'] ?? null;
            
            // Skip if no proper configuration is available
            if (!$registerId || !$contactpersoonSchemaId) {
                $this->_logger->warning('SoftwareCatalogueService: Missing Voorzieningen configuration for contactpersonen', [
                    'organizationId' => $organizationId,
                    'registerId' => $registerId,
                    'contactpersoonSchemaId' => $contactpersoonSchemaId
                ]);
                return $organizationData;
            }
            
            $this->_logger->info('Schema IDs for contactpersonen search', [
                'organizationId' => $organizationId,
                'registerId' => $registerId,
                'contactpersoonSchemaId' => $contactpersoonSchemaId
            ]);
            
            $activatedUsers = [];
            
            // Check contactpersoon objects (new data model)
            if ($contactpersoonSchemaId) {
                $contactpersoonObjects = $objectService->findAll(
                    (int) $registerId,
                    (int) $contactpersoonSchemaId,
                    ['organisation' => $organizationId]
                );
                
                foreach ($contactpersoonObjects as $contactpersoonObject) {
                    $contactData = $contactpersoonObject->getObject();
                    $username = $contactData['username'] ?? '';
                    
                    if (!empty($username)) {
                        $success = $this->_contactPersonHandler->setUserActive($username);
                        if ($success) {
                            $activatedUsers[] = $username;
                            $this->_logger->info('Activated contactpersoon user', [
                                'username' => $username,
                                'organizationId' => $organizationId,
                                'contactpersoonId' => $contactpersoonObject->getId()
                            ]);
                        }
                    }
                }
            }
            
            // Only use contactpersoon objects now - contactgegevens is deprecated
            
            $this->_logger->info('Completed contactpersonen activation for organization', [
                'organizationId' => $organizationId,
                'activatedUsers' => $activatedUsers,
                'totalActivated' => count($activatedUsers)
            ]);
            
        } catch (\Exception $e) {
            $this->_logger->error(
                'Failed to activate contactpersonen for organization: ' . $e->getMessage(),
                [
                    'organizationId' => $organizationId,
                    'exception' => $e
                ]
            );
        }
        */
    }

    /**
     * Sends welcome email to organization
     * 
     * @deprecated This method is disabled to prevent organization duplication
     * @param object $organizationObject The organization object
     * 
     * @return void
     */
    public function sendOrganizationWelcomeEmail(object $organizationObject): void
    {
        // DISABLED: Organization handling is disabled to prevent duplication
        $this->_logger->info(
            'Organization welcome email sending is disabled to prevent duplication',
            [
                'organizationId' => $organizationObject->getId()
            ]
        );
        
        return;
        
        /*
        try {
            $this->_logger->info('Sending organization welcome email', [
                'objectId' => $organizationObject->getId()
            ]);
            
            $objectData = $organizationObject->getObject();
            
            // Send organization registration email
            $success = $this->_emailService->sendOrganizationRegistrationEmail($objectData);
            
            if ($success) {
                $this->_logger->info('Organization welcome email sent successfully', [
                    'objectId' => $organizationObject->getId()
                ]);
            } else {
                $this->_logger->warning('Failed to send organization welcome email', [
                    'objectId' => $organizationObject->getId()
                ]);
            }
        } catch (\Exception $e) {
            $this->_logger->error('Exception sending organization welcome email: ' . $e->getMessage(), [
                'objectId' => $organizationObject->getId(),
                'exception' => $e
            ]);
        }
        */
    }

    /**
     * Handles new contact creation
     *
     * @param object $contactObject The contact object
     * 
     * @return void
     */
    public function handleNewContact(object $contactObject): void
    {
        // Delegate to contact person handler
        $this->_contactPersonHandler->handleNewContact($contactObject);
    }

    /**
     * Creates user for contact if not exists
     *
     * @param object $contactObject The contact object
     * 
     * @return void
     */
    public function createUserForContactIfNotExists(object $contactObject): void
    {
        // Implementation for creating user from contact
        $this->_logger->info('Creating user for contact if not exists', [
            'objectId' => $contactObject->getId()
        ]);
    }

    /**
     * Handles new gebruiker creation
     *
     * @param object $gebruikerObject The gebruiker object
     * 
     * @return void
     */
    public function handleNewGebruiker(object $gebruikerObject): void
    {
        // Implementation for handling new gebruiker
        $this->_logger->info('Handling new gebruiker', [
            'objectId' => $gebruikerObject->getId()
        ]);
    }

    /**
     * Sends welcome email to gebruiker
     *
     * @param object $gebruikerObject The gebruiker object
     * 
     * @return void
     */
    public function sendGebruikerWelcomeEmail(object $gebruikerObject): void
    {
        // Implementation for sending gebruiker welcome email
        $this->_logger->info('Sending gebruiker welcome email', [
            'objectId' => $gebruikerObject->getId()
        ]);
    }

    /**
     * Handles contact update
     *
     * @param object $contactObject The contact object
     * 
     * @return void
     */
    public function handleContactUpdate(object $contactObject): void
    {
        // Delegate to contact person handler
        $this->_contactPersonHandler->handleContactUpdate($contactObject);
    }

    /**
     * Handles gebruiker update
     *
     * @param object $gebruikerObject    The new gebruiker object
     * @param object $oldGebruikerObject The old gebruiker object
     * 
     * @return void
     */
    public function handleGebruikerUpdate(object $gebruikerObject, object $oldGebruikerObject): void
    {
        // Implementation for handling gebruiker updates
        $this->_logger->info('Handling gebruiker update', [
            'objectId' => $gebruikerObject->getId()
        ]);
    }

    /**
     * Handles contact deletion
     *
     * @param object $contactObject The contact object
     * 
     * @return void
     */
    public function handleContactDeletion(object $contactObject): void
    {
        // Delegate to contact person handler
        $this->_contactPersonHandler->handleContactDeletion($contactObject);
    }

    /**
     * Blocks user for gebruiker
     *
     * @param object $gebruikerObject The gebruiker object
     * 
     * @return void
     */
    public function blockUserForGebruiker(object $gebruikerObject): void
    {
        // Implementation for blocking user
        $this->_logger->info('Blocking user for gebruiker', [
            'objectId' => $gebruikerObject->getId()
        ]);
    }

    /**
     * Temporarily blocks user for gebruiker
     *
     * @param object $gebruikerObject The gebruiker object
     * 
     * @return void
     */
    public function temporarilyBlockUserForGebruiker(object $gebruikerObject): void
    {
        // Implementation for temporarily blocking user
        $this->_logger->info('Temporarily blocking user for gebruiker', [
            'objectId' => $gebruikerObject->getId()
        ]);
    }

    /**
     * Restores user access for gebruiker
     *
     * @param object $gebruikerObject The gebruiker object
     * 
     * @return void
     */
    public function restoreUserAccessForGebruiker(object $gebruikerObject): void
    {
        // Implementation for restoring user access
        $this->_logger->info('Restoring user access for gebruiker', [
            'objectId' => $gebruikerObject->getId()
        ]);
    }

    /**
     * Syncs user with reverted contact
     *
     * @param object $contactObject The contact object
     * @param mixed  $revertPoint   The revert point
     * 
     * @return void
     */
    public function syncUserWithRevertedContact(object $contactObject, mixed $revertPoint): void
    {
        // Implementation for syncing user with reverted contact
        $this->_logger->info('Syncing user with reverted contact', [
            'objectId' => $contactObject->getId()
        ]);
    }

    /**
     * Updates user from reverted gebruiker
     *
     * @param object $gebruikerObject The gebruiker object
     * @param mixed  $revertPoint     The revert point
     * 
     * @return void
     */
    public function updateUserFromRevertedGebruiker(object $gebruikerObject, mixed $revertPoint): void
    {
        // Implementation for updating user from reverted gebruiker
        $this->_logger->info('Updating user from reverted gebruiker', [
            'objectId' => $gebruikerObject->getId()
        ]);
    }

    /**
     * Gets the list of generic user groups
     *
     * @return array Array of generic user groups
     */
    public function getGenericUserGroups(): array
    {
        return $this->_groupHandler->getGenericUserGroups();
    }

    /**
     * Sets the list of generic user groups
     *
     * @param array $groups Array of generic user groups
     * 
     * @return void
     */
    public function setGenericUserGroups(array $groups): void
    {
        $this->_groupHandler->setGenericUserGroups($groups);
    }

    /**
     * Ensures all generic user groups exist
     *
     * @return array Array of created/existing groups
     */
    public function ensureGenericUserGroupsExist(): array
    {
        return $this->_groupHandler->ensureGenericUserGroupsExist();
    }

    /**
     * Gets organizational hierarchy information for a user
     *
     * @param string $username The username to get hierarchy for
     * 
     * @return array Array containing hierarchy information
     */
    public function getUserHierarchy(string $username): array
    {
        return $this->_hierarchyHandler->getUserHierarchy($username);
    }

    /**
     * Gets complete organizational structure
     *
     * @param string $organizationUuid The organization UUID
     * 
     * @return array Array containing organizational structure
     */
    public function getOrganizationStructure(string $organizationUuid): array
    {
        return $this->_hierarchyHandler->getOrganizationStructure($organizationUuid);
    }

    /**
     * Handles contactpersoon updates, particularly role changes
     *
     * @param object $contactpersoonObject    The updated contactpersoon object
     * @param object $oldContactpersoonObject The previous contactpersoon object (optional)
     * 
     * @return void
     */
    public function handleContactpersoonUpdate(object $contactpersoonObject, object $oldContactpersoonObject = null): void
    {
        $startTime = microtime(true);
        
        try {
            $objectId = $contactpersoonObject->getId();
            $this->_logger->info('SoftwareCatalogueService: Starting contactpersoon update handling', [
                'objectId' => $objectId,
                'hasOldObject' => $oldContactpersoonObject !== null,
                'timestamp' => date('Y-m-d H:i:s')
            ]);

            // Get current and old data for comparison
            $newData = $contactpersoonObject->getObject();
            $oldData = $oldContactpersoonObject ? $oldContactpersoonObject->getObject() : [];
            
            $newRoles = $newData['roles'] ?? [];
            $oldRoles = $oldData['roles'] ?? [];
            
            $this->_logger->debug('SoftwareCatalogueService: Comparing roles for contactpersoon update', [
                'objectId' => $objectId,
                'newRoles' => $newRoles,
                'oldRoles' => $oldRoles,
                'newRolesType' => gettype($newRoles),
                'oldRolesType' => gettype($oldRoles)
            ]);
            
            // Ensure both are arrays
            if (!is_array($newRoles)) {
                $newRoles = [$newRoles];
                $this->_logger->debug('SoftwareCatalogueService: Converted newRoles to array', [
                    'objectId' => $objectId,
                    'newRoles' => $newRoles
                ]);
            }
            if (!is_array($oldRoles)) {
                $oldRoles = [$oldRoles];
                $this->_logger->debug('SoftwareCatalogueService: Converted oldRoles to array', [
                    'objectId' => $objectId,
                    'oldRoles' => $oldRoles
                ]);
            }
            
            // For updates, we need to handle differently based on whether roles changed
            if ($newRoles !== $oldRoles) {
                // Roles changed - use role-based group assignment instead of generic group assignment
                $this->_logger->info(
                    'SoftwareCatalogueService: Roles changed for contactpersoon, using role-based group assignment',
                    [
                        'contactpersoonId' => $objectId,
                        'oldRoles' => $oldRoles,
                        'newRoles' => $newRoles,
                        'addedRoles' => array_diff($newRoles, $oldRoles),
                        'removedRoles' => array_diff($oldRoles, $newRoles)
                    ]
                );
                
                // Ensure user exists (but don't assign generic groups)
                $username = $newData['username'] ?? '';
                if (empty($username)) {
                    // Generate username and create user if needed
                    $result = $this->_contactPersonHandler->processContactpersoon($contactpersoonObject, true);
                    if ($result) {
                        $updatedData = $contactpersoonObject->getObject();
                        $username = $updatedData['username'] ?? '';
                    }
                }
                
                if (!empty($username)) {
                    $user = $this->_container->get(\OCP\IUserManager::class)->get($username);
                    if ($user) {
                        // Use new organization type-based logic instead of old role-based logic
                        $contactData = $contactpersoonObject->getObject();
                        $this->_contactPersonHandler->updateUserGroupsFromContactData($user, $contactData);
                        
                        $this->_logger->info('SoftwareCatalogueService: Organization type-based group updates completed', [
                            'username' => $username,
                            'objectId' => $objectId,
                            'newRoles' => $newRoles
                        ]);
                    } else {
                        $this->_logger->warning('SoftwareCatalogueService: User not found for role-based group updates', [
                            'username' => $username,
                            'objectId' => $objectId
                        ]);
                    }
                } else {
                    $this->_logger->warning('SoftwareCatalogueService: No username available for role-based group updates', [
                        'objectId' => $objectId,
                        'newData' => $newData
                    ]);
                }
            } else {
                // No role changes - use standard processing (assigns generic groups)
                $this->_logger->debug('SoftwareCatalogueService: No role changes, using standard contactpersoon processing', [
                    'objectId' => $objectId,
                    'roles' => $newRoles
                ]);
                
                $result = $this->processContactpersoon($contactpersoonObject, true);
                
                $this->_logger->info('SoftwareCatalogueService: Standard contactpersoon processing completed', [
                    'objectId' => $objectId,
                    'result' => $result,
                    'processingTime' => round((microtime(true) - $startTime) * 1000, 2) . 'ms'
                ]);
            }
            
            $this->_logger->info('SoftwareCatalogueService: Contactpersoon update handling completed', [
                'objectId' => $objectId,
                'totalProcessingTime' => round((microtime(true) - $startTime) * 1000, 2) . 'ms'
            ]);
            
        } catch (\Exception $e) {
            $this->_logger->error(
                'SoftwareCatalogueService: Failed to handle contactpersoon update: ' . $e->getMessage(),
                [
                    'objectId' => $contactpersoonObject->getId(),
                    'exception' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString(),
                    'processingTime' => round((microtime(true) - $startTime) * 1000, 2) . 'ms'
                ]
            );
        }
    }


    /**
     * Handles organization deletion - deactivates all users in the organization
     *
     * @param object $organizationObject The organization object being deleted
     * 
     * @return void
     */
    public function handleOrganizationDeletion(object $organizationObject): void
    {
        try {
            $this->_logger->info('SoftwareCatalogueService: Handling organization deletion', [
                'objectId' => $organizationObject->getId()
            ]);

            $objectData = $organizationObject->getObject();
            $organizationUuid = $objectData['id'] ?? $organizationObject->getId();

            // Deactivate all users in this organization
            $this->deactivateUsersForOrganization($organizationUuid);

            $this->_logger->info(
                'SoftwareCatalogueService: Successfully handled organization deletion',
                [
                    'organizationId' => $organizationUuid,
                    'timestamp' => date('Y-m-d H:i:s')
                ]
            );

        } catch (\Exception $e) {
            $this->_logger->error(
                'SoftwareCatalogueService: Failed to handle organization deletion: ' . $e->getMessage(),
                [
                    'objectId' => $organizationObject->getId(),
                    'exception' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString()
                ]
            );
        }
    }

    /**
     * Syncs organization data with OpenRegister
     *
     * @param object $organizationObject The organization object to sync
     * 
     * @return bool True if sync was successful
     */
    public function syncOrganizationWithOpenRegister(object $organizationObject): bool
    {
        try {
            $this->_logger->info('SoftwareCatalogueService: SYNC_STEP_1 - Starting syncOrganizationWithOpenRegister', [
                'objectId' => $organizationObject->getId(),
                'objectClass' => get_class($organizationObject)
            ]);

            $objectData = $organizationObject->getObject();
            $organizationUuid = $objectData['id'] ?? $organizationObject->getId();
            
            $this->_logger->info('SoftwareCatalogueService: SYNC_STEP_2 - Extracted organization data', [
                'organizationUuid' => $organizationUuid,
                'objectDataKeys' => array_keys($objectData),
                'hasId' => isset($objectData['id'])
            ]);

            // Get OpenRegister OrganisationService for proper organization entity management
            $this->_logger->info('SoftwareCatalogueService: SYNC_STEP_3 - Getting OrganisationService');
            $organisationService = $this->_getOrganisationService();
            if (!$organisationService) {
                $this->_logger->error('SoftwareCatalogueService: SYNC_STEP_3 - OpenRegister OrganisationService not available');
                return false;
            }
            $this->_logger->info('SoftwareCatalogueService: SYNC_STEP_3 - OrganisationService retrieved', [
                'serviceClass' => get_class($organisationService)
            ]);

            $this->_logger->info('SoftwareCatalogueService: SYNC_STEP_4 - OpenRegister configuration', [
                'organizationUuid' => $organizationUuid,
                'organizationName' => $objectData['naam'] ?? 'Unknown'
            ]);

            // Check if organization already exists in OpenRegister
            $this->_logger->info('SoftwareCatalogueService: SYNC_STEP_5 - Checking if organization exists in OpenRegister');
            try {
                $this->_logger->info('SoftwareCatalogueService: SYNC_STEP_5A - Getting OrganisationMapper for lookup');
                $organisationMapper = $this->_container->get('OCA\\OpenRegister\\Db\\OrganisationMapper');
                $this->_logger->info('SoftwareCatalogueService: SYNC_STEP_5B - Calling findByUuid', [
                    'uuid' => $organizationUuid
                ]);
                $existingOrganisation = $organisationMapper->findByUuid($organizationUuid);
                
                // Organization exists - update it
                $this->_logger->info('SoftwareCatalogueService: SYNC_STEP_6 - Organization exists in OpenRegister, updating', [
                    'organizationId' => $organizationUuid,
                    'existingOrganisationClass' => get_class($existingOrganisation)
                ]);

                // Map status from SoftwareCatalog to OpenRegister
                $this->_logger->info('SoftwareCatalogueService: SYNC_STEP_7 - Mapping organization data');
                $mappedData = $this->mapOrganizationDataForOpenRegister($objectData);
                
                // Update the organization using OrganisationService
                $updatedOrganisation = $this->updateOrganisationInOpenRegister($organisationService, $existingOrganisation, $mappedData);

                $this->_logger->info('SoftwareCatalogueService: Successfully updated organization in OpenRegister', [
                    'organizationId' => $organizationUuid,
                    'openRegisterId' => $updatedOrganisation->getUuid()
                ]);

                return true;

            } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
                // Organization doesn't exist - create it
                $this->_logger->info('SoftwareCatalogueService: SYNC_STEP_8 - Organization not found in OpenRegister, creating', [
                    'organizationId' => $organizationUuid,
                    'exception' => $e->getMessage()
                ]);

                // Map status from SoftwareCatalog to OpenRegister
                $this->_logger->info('SoftwareCatalogueService: SYNC_STEP_9 - Mapping organization data for creation');
                $mappedData = $this->mapOrganizationDataForOpenRegister($objectData);
                
                // Create the organization using OrganisationService
                $this->_logger->info('SoftwareCatalogueService: SYNC_STEP_10 - Calling createOrganisationInOpenRegister');
                $createdOrganisation = $this->createOrganisationInOpenRegisterInternal($organisationService, $mappedData, $organizationUuid);

                $this->_logger->info('SoftwareCatalogueService: SYNC_STEP_11 - Successfully created organization in OpenRegister', [
                    'organizationId' => $organizationUuid,
                    'openRegisterId' => $createdOrganisation->getUuid(),
                    'createdOrganisationClass' => get_class($createdOrganisation)
                ]);

                return true;
            }

        } catch (\Exception $e) {
            $this->_logger->error(
                'SoftwareCatalogueService: Failed to sync organization with OpenRegister: ' . $e->getMessage(),
                [
                    'objectId' => $organizationObject->getId(),
                    'exception' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString()
                ]
            );
            return false;
        }
    }

    /**
     * Public wrapper for creating organization in OpenRegister (used by background job)
     *
     * @param array $objectData The organization object data
     * 
     * @return object|null The created organisation entity or null on failure
     */
    public function createOrganisationInOpenRegister(array $objectData): ?object
    {
        try {
            $organizationUuid = $objectData['id'] ?? null;
            if (!$organizationUuid) {
                $this->_logger->error('SoftwareCatalogueService: No organization UUID provided for creation');
                return null;
            }
            
            // Map the data
            $mappedData = [
                'naam' => $objectData['naam'] ?? 'Unknown',
                'type' => $objectData['type'] ?? '',
                'website' => $objectData['website'] ?? '',
                'active' => $this->mapStatus($objectData['beoordeling'] ?? 'actief'),
                'contactpersonen' => $objectData['contactpersonen'] ?? [],
                'deelnemers' => $objectData['deelnemers'] ?? []
            ];
            
            // Get organisation service
            $organisationService = $this->_container->get('OCA\OpenRegister\Service\OrganisationService');
            
            return $this->createOrganisationInOpenRegisterInternal($organisationService, $mappedData, $organizationUuid);
            
        } catch (\Exception $e) {
            $this->_logger->error('SoftwareCatalogueService: Error in public createOrganisationInOpenRegister', [
                'error' => $e->getMessage(),
                'objectData' => $objectData
            ]);
            return null;
        }
    }

    /**
     * Map status from Software Catalog to OpenRegister format
     *
     * @param string $status The status from Software Catalog
     * 
     * @return bool The mapped active status for OpenRegister
     */
    private function mapStatus(string $status): bool
    {
        switch (strtolower($status)) {
            case 'actief':
                return true;
            case 'inactief':
                return false;
            default:
                return true; // Default to active
        }
    }

    /**
     * Creates an organization in OpenRegister using OrganisationService
     *
     * @param \OCA\OpenRegister\Service\OrganisationService $organisationService The OpenRegister organisation service
     * @param array $mappedData The mapped organization data
     * @param string $organizationUuid The organization UUID to use
     * 
     * @return \OCA\OpenRegister\Db\Organisation The created organization
     */
    private function createOrganisationInOpenRegisterInternal(
        \OCA\OpenRegister\Service\OrganisationService $organisationService,
        array $mappedData,
        string $organizationUuid
    ): \OCA\OpenRegister\Db\Organisation {
        $this->_logger->info('SoftwareCatalogueService: STEP 1 - Starting createOrganisationInOpenRegister', [
            'organizationUuid' => $organizationUuid,
            'name' => $mappedData['naam'] ?? 'Unknown',
            'mappedDataKeys' => array_keys($mappedData)
        ]);

        // Check if we're in an anonymous context (no logged-in user)
        $userSession = \OC::$server->getUserSession();
        $currentUser = $userSession->getUser();
        
        $this->_logger->info('SoftwareCatalogueService: STEP 2 - Checking user context', [
            'hasUserSession' => $userSession !== null,
            'currentUser' => $currentUser ? $currentUser->getUID() : 'null',
            'isAnonymous' => $currentUser === null
        ]);
        
        if (!$currentUser) {
            $this->_logger->info('SoftwareCatalogueService: STEP 3A - Anonymous path: No user logged in, creating organization directly via mapper', [
                'organizationUuid' => $organizationUuid
            ]);
            
            // Keep the original UUID format - no conversion needed
            $this->_logger->info('SoftwareCatalogueService: STEP 3B - Using original UUID format for OpenRegister (anonymous)', [
                'organizationUuid' => $organizationUuid
            ]);
            
            // Create organization directly via mapper to avoid user context requirements
            $this->_logger->info('SoftwareCatalogueService: STEP 3C - Getting OrganisationMapper from container');
            $organisationMapper = $this->_container->get('OCA\\OpenRegister\\Db\\OrganisationMapper');
            $this->_logger->info('SoftwareCatalogueService: STEP 3D - OrganisationMapper retrieved', [
                'mapperClass' => get_class($organisationMapper)
            ]);
            
            // Create a new Organisation entity
            $this->_logger->info('SoftwareCatalogueService: STEP 3E - Creating new Organisation entity');
            $organisation = new \OCA\OpenRegister\Db\Organisation();
            
            $this->_logger->info('SoftwareCatalogueService: STEP 3F - Setting organisation properties', [
                'name' => $mappedData['naam'] ?? 'Unknown Organization',
                'description' => $mappedData['website'] ?? '',
                'uuid' => $organizationUuid
            ]);
            
            // Collect all contact person usernames for this organization
            $contactPersonUsernames = $this->collectContactPersonUsernames($organizationUuid, $mappedData);
            
            // Start with admin user and add all contact person usernames
            $allUsernames = array_merge(['admin'], $contactPersonUsernames);
            $allUsernames = array_unique($allUsernames);
            
            $this->_logger->info('SoftwareCatalogueService: STEP 3F_2 - Collected usernames for organization', [
                'organizationUuid' => $organizationUuid,
                'totalUsernames' => count($allUsernames),
                'usernames' => $allUsernames
            ]);
            
            $organisation->setName($mappedData['naam'] ?? 'Unknown Organization');
            $organisation->setDescription($mappedData['website'] ?? ''); // Use website as description
            $organisation->setUuid($organizationUuid);
            $organisation->setUsers($allUsernames);
            $organisation->setOwner('admin'); // Set admin as owner for anonymous registrations
            $organisation->setActive($mappedData['active'] ?? true); // Set active status based on organization beoordeling
            
            // Debug: Check if UUID was set correctly
            $this->_logger->info('SoftwareCatalogueService: STEP 3G - Debug - UUID before save', [
                'setUuid' => $organizationUuid,
                'getUuid' => $organisation->getUuid(),
                'uuidMatches' => $organisation->getUuid() === $organizationUuid,
                'organisationClass' => get_class($organisation)
            ]);
            
            // Save the organization
            $this->_logger->info('SoftwareCatalogueService: STEP 3H - Calling organisationMapper->save()');
            try {
                $savedOrganisation = $organisationMapper->save($organisation);
                $this->_logger->info('SoftwareCatalogueService: STEP 3I - organisationMapper->save() completed successfully');
            } catch (\Exception $e) {
                $this->_logger->error('SoftwareCatalogueService: STEP 3I - organisationMapper->save() failed', [
                    'error' => $e->getMessage(),
                    'errorClass' => get_class($e),
                    'trace' => $e->getTraceAsString()
                ]);
                throw $e;
            }
            
            $this->_logger->info('SoftwareCatalogueService: Successfully created organization in OpenRegister via mapper', [
                'organizationUuid' => $organizationUuid,
                'openRegisterId' => $savedOrganisation->getUuid(),
                'savedUuid' => $savedOrganisation->getUuid(),
                'expectedUuid' => $organizationUuid
            ]);
            
            // Verify the UUID was preserved
            if ($savedOrganisation->getUuid() !== $organizationUuid) {
                $this->_logger->warning('SoftwareCatalogueService: UUID mismatch after saving organization', [
                    'expectedUuid' => $organizationUuid,
                    'actualUuid' => $savedOrganisation->getUuid()
                ]);
            }
            
            return $savedOrganisation;
        } else {
            $this->_logger->info('SoftwareCatalogueService: STEP 4A - Authenticated path: User logged in, creating organization via mapper', [
                'organizationUuid' => $organizationUuid,
                'currentUser' => $currentUser->getUID()
            ]);
            
            // Keep the original UUID format - no conversion needed
            $this->_logger->info('SoftwareCatalogueService: STEP 4B - Using original UUID format for OpenRegister', [
                'organizationUuid' => $organizationUuid
            ]);
            
            // Create organization directly via mapper to avoid service issues
            $this->_logger->info('SoftwareCatalogueService: STEP 4C - Getting OrganisationMapper from container');
            $organisationMapper = $this->_container->get('OCA\\OpenRegister\\Db\\OrganisationMapper');
            $this->_logger->info('SoftwareCatalogueService: STEP 4D - OrganisationMapper retrieved', [
                'mapperClass' => get_class($organisationMapper)
            ]);
            
            // Debug: Check UUID before creating
            // Collect all contact person usernames for this organization
            $contactPersonUsernames = $this->collectContactPersonUsernames($organizationUuid, $mappedData);
            
            // Start with current user and add all contact person usernames
            $allUsernames = array_merge([$currentUser->getUID()], $contactPersonUsernames);
            $allUsernames = array_unique($allUsernames);
            
            $this->_logger->info('SoftwareCatalogueService: STEP 4E - Debug - UUID before createWithUuid', [
                'organizationUuid' => $organizationUuid,
                'uuidLength' => strlen($organizationUuid),
                'uuidIsEmpty' => empty($organizationUuid),
                'name' => $mappedData['naam'] ?? 'Unknown Organization',
                'description' => $mappedData['website'] ?? '',
                'owner' => $currentUser->getUID(),
                'users' => $allUsernames,
                'contactPersonUsernames' => $contactPersonUsernames
            ]);
            
            $this->_logger->info('SoftwareCatalogueService: STEP 4F - Calling organisationMapper->createWithUuid()');
            try {
                // Debug: Log the exact parameters being passed
                $this->_logger->info('SoftwareCatalogueService: STEP 4F_DEBUG - Parameters for createWithUuid', [
                    'name' => $mappedData['naam'] ?? 'Unknown Organization',
                    'description' => $mappedData['website'] ?? '',
                    'uuid' => $organizationUuid,
                    'owner' => $currentUser->getUID(),
                    'users' => $allUsernames,
                    'isDefault' => false,
                    'uuidLength' => strlen($organizationUuid),
                    'uuidIsEmpty' => empty($organizationUuid)
                ]);
                
                $organisation = $organisationMapper->createWithUuid(
                    $mappedData['naam'] ?? 'Unknown Organization',
                    $mappedData['website'] ?? '', // Use website as description
                    $organizationUuid, // Pass the original UUID
                    $currentUser->getUID(), // Set current user as owner
                    $allUsernames, // Add all users including contact persons
                    false // Not default
                );
                $this->_logger->info('SoftwareCatalogueService: STEP 4G - organisationMapper->createWithUuid() completed successfully');
            } catch (\Exception $e) {
                $this->_logger->error('SoftwareCatalogueService: STEP 4G - organisationMapper->createWithUuid() failed', [
                    'error' => $e->getMessage(),
                    'errorClass' => get_class($e),
                    'trace' => $e->getTraceAsString()
                ]);
                throw $e;
            }
            
            // Note: OpenRegister Organisation entity doesn't have status or type fields
            // These are managed in the SoftwareCatalog object, not in the OpenRegister organisation

            $this->_logger->info('SoftwareCatalogueService: Successfully created organization in OpenRegister via service', [
                'organizationUuid' => $organizationUuid,
                'openRegisterId' => $organisation->getUuid(),
                'savedUuid' => $organisation->getUuid(),
                'expectedUuid' => $organizationUuid
            ]);

            return $organisation;
        }
    }

    /**
     * Updates an organization in OpenRegister using OrganisationService
     *
     * @param \OCA\OpenRegister\Service\OrganisationService $organisationService The OpenRegister organisation service
     * @param \OCA\OpenRegister\Db\Organisation $existingOrganisation The existing organization
     * @param array $mappedData The mapped organization data
     * 
     * @return \OCA\OpenRegister\Db\Organisation The updated organization
     */
    private function updateOrganisationInOpenRegister(
        \OCA\OpenRegister\Service\OrganisationService $organisationService,
        \OCA\OpenRegister\Db\Organisation $existingOrganisation,
        array $mappedData
    ): \OCA\OpenRegister\Db\Organisation {
        $this->_logger->info('SoftwareCatalogueService: Updating organization in OpenRegister', [
            'organizationUuid' => $existingOrganisation->getUuid(),
            'name' => $mappedData['name'] ?? 'Unknown'
        ]);

        // Update organization fields (only those that exist on the Organisation entity)
        if (isset($mappedData['name'])) {
            $existingOrganisation->setName($mappedData['name']);
        }
        
        if (isset($mappedData['description'])) {
            $existingOrganisation->setDescription($mappedData['description']);
        }
        
        // Note: OpenRegister Organisation entity doesn't have status or type fields
        // These are managed in the SoftwareCatalog object, not in the OpenRegister organisation

        // Save the updated organization
        $organisationMapper = $this->_container->get('OCA\\OpenRegister\\Db\\OrganisationMapper');
        $updatedOrganisation = $organisationMapper->save($existingOrganisation);

        $this->_logger->info('SoftwareCatalogueService: Successfully updated organization in OpenRegister', [
            'organizationUuid' => $existingOrganisation->getUuid(),
            'openRegisterId' => $updatedOrganisation->getUuid()
        ]);

        return $updatedOrganisation;
    }

        /**
     * Collects all contact person usernames associated with an organization
     * 
     * @param string $organizationUuid The organization UUID
     * @param array $objectData The organization object data (for nested contact persons)
     * 
     * @return array Array of usernames
     */
    private function collectContactPersonUsernames(string $organizationUuid, array $objectData = []): array
    {
        $usernames = [];
        
        // Focus on nested contact persons in the organization object data
        // These are available immediately when the organization is created
        $nestedContactPersons = $objectData['contactpersonen'] ?? [];
        $this->_logger->info('SoftwareCatalogueService: Processing nested contact persons', [
            'organizationUuid' => $organizationUuid,
            'nestedContactPersonCount' => count($nestedContactPersons)
        ]);
        
        foreach ($nestedContactPersons as $contactPerson) {
            if (is_array($contactPerson) && isset($contactPerson['email'])) {
                $usernames[] = $contactPerson['email'];
                $this->_logger->info('SoftwareCatalogueService: Added nested contact person username', [
                    'username' => $contactPerson['email'],
                    'contactPersonData' => $contactPerson
                ]);
            }
        }
        
        // Also try to find existing contact persons by their organisatie field
        // This is useful for updates or when contact persons were created separately
        $objectService = $this->_getObjectService();
        if ($objectService) {
            $settingsService = $this->_container->get('OCA\SoftwareCatalog\Service\SettingsService');
            $voorzieningenConfig = $settingsService->getVoorzieningenConfig();
            $contactSchemaId = $voorzieningenConfig['contactpersoon_schema'] ?? null;
            
            if (!$contactSchemaId) {
                $this->_logger->warning('SoftwareCatalogueService: Missing contactpersoon schema configuration for username extraction');
                return $usernames;
            }
            
            try {
                // Try multiple approaches to find contact persons
                $contactPersons = [];
                
                // Approach 1: Find by organisatie field
                try {
                    $contactPersons = $objectService->findAll([
                        'filters' => [
                            'register' => $objectData['register'] ?? '6',
                            'schema' => $contactSchemaId,
                            'organisatie' => $organizationUuid
                        ]
                    ]);
                } catch (\Exception $e) {
                    $this->_logger->info('SoftwareCatalogueService: Approach 1 failed, trying approach 2', [
                        'organizationUuid' => $organizationUuid,
                        'error' => $e->getMessage()
                    ]);
                }
                
                // Approach 2: If approach 1 fails, try to find all contact persons and filter by organisatie
                if (empty($contactPersons)) {
                    try {
                        $allContactPersons = $objectService->findAll([
                            'filters' => [
                                'register' => $objectData['register'] ?? '6',
                                'schema' => $contactSchemaId
                            ]
                        ]);
                        
                        foreach ($allContactPersons as $contactPerson) {
                            $contactData = $contactPerson->getObject();
                            $contactOrganisatie = $contactData['organisatie'] ?? null;
                            if ($contactOrganisatie === $organizationUuid) {
                                $contactPersons[] = $contactPerson;
                            }
                        }
                    } catch (\Exception $e) {
                        $this->_logger->info('SoftwareCatalogueService: Approach 2 also failed', [
                            'organizationUuid' => $organizationUuid,
                            'error' => $e->getMessage()
                        ]);
                    }
                }
                
                $this->_logger->info('SoftwareCatalogueService: Found existing contact persons for organization', [
                    'organizationUuid' => $organizationUuid,
                    'contactPersonCount' => count($contactPersons),
                    'contactPersonIds' => array_map(function($cp) { return $cp->getId(); }, $contactPersons)
                ]);
                
                foreach ($contactPersons as $contactPerson) {
                    $contactData = $contactPerson->getObject();
                    $email = $contactData['email'] ?? null;
                    if ($email) {
                        $usernames[] = $email;
                        $this->_logger->info('SoftwareCatalogueService: Added existing contact person username', [
                            'username' => $email,
                            'contactPersonId' => $contactPerson->getId()
                        ]);
                    }
                }
                
            } catch (\Exception $e) {
                $this->_logger->error('SoftwareCatalogueService: Error collecting existing contact person usernames', [
                    'organizationUuid' => $organizationUuid,
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        // Remove duplicates and return
        $uniqueUsernames = array_unique($usernames);
        $this->_logger->info('SoftwareCatalogueService: Collected contact person usernames', [
            'organizationUuid' => $organizationUuid,
            'totalUsernames' => count($uniqueUsernames),
            'usernames' => $uniqueUsernames
        ]);
        
        return $uniqueUsernames;
    }

    /**
     * Maps organization data from SoftwareCatalog format to OpenRegister format
     * 
     * @param array $objectData The organization data from SoftwareCatalog
     * 
     * @return array The mapped data for OpenRegister
     */
    private function mapOrganizationDataForOpenRegister(array $objectData): array
    {
        $mappedData = [
            'naam' => $objectData['naam'] ?? $objectData['name'] ?? '',
            'type' => $objectData['type'] ?? '',
            'website' => $objectData['website'] ?? '',
            'active' => false, // Default to inactive for new organizations
            'contactpersonen' => [],
            'deelnemers' => []
        ];

        // Map status from SoftwareCatalog to OpenRegister
        $beoordeling = strtolower($objectData['beoordeling'] ?? '');
        if ($beoordeling === 'actief') {
            $mappedData['active'] = true;
        } elseif ($beoordeling === 'inactief' || $beoordeling === 'deactief') {
            $mappedData['active'] = false;
        }

        // Map other fields if they exist
        if (isset($objectData['adres'])) {
            $mappedData['adres'] = $objectData['adres'];
        }
        if (isset($objectData['postcode'])) {
            $mappedData['postcode'] = $objectData['postcode'];
        }
        if (isset($objectData['plaats'])) {
            $mappedData['plaats'] = $objectData['plaats'];
        }
        if (isset($objectData['telefoon'])) {
            $mappedData['telefoon'] = $objectData['telefoon'];
        }
        if (isset($objectData['email'])) {
            $mappedData['email'] = $objectData['email'];
        }

        return $mappedData;
    }

    /**
     * Activates all users in an organization when the organization becomes active
     *
     * @param string $organizationUuid The organization UUID
     * 
     * @return void
     */
    private function activateUsersForOrganization(string $organizationUuid): void
    {
        try {
            $this->_logger->info('SoftwareCatalogueService: Activating users for organization', [
                'organizationUuid' => $organizationUuid
            ]);

            $objectService = $this->_getObjectService();
            if (!$objectService) {
                $this->_logger->error('SoftwareCatalogueService: OpenRegister ObjectService not available');
                return;
            }

            // Get all contactpersonen for this organization
            $settingsService = $this->_container->get(SettingsService::class);
            $registerId = $settingsService->getVoorzieningenRegisterId();
            $contactpersoonSchemaId = $settingsService->getSchemaIdForObjectType('contactpersoon');

            if (!$registerId || !$contactpersoonSchemaId) {
                $this->_logger->error('SoftwareCatalogueService: Register or schema not configured for contactpersonen');
                return;
            }

            $contactpersonen = $objectService->findAll(
                ['organisation' => $organizationUuid],
                $registerId,
                $contactpersoonSchemaId
            );

            $userManager = $this->_container->get(\OCP\IUserManager::class);
            $activatedCount = 0;

            foreach ($contactpersonen as $contactpersoon) {
                $contactData = $contactpersoon->getObject();
                $username = $contactData['username'] ?? '';

                if (!empty($username)) {
                    $user = $userManager->get($username);
                    if ($user && !$user->isEnabled()) {
                        $user->setEnabled(true);
                        $activatedCount++;

                        $this->_logger->info('SoftwareCatalogueService: Activated user for organization', [
                            'username' => $username,
                            'organizationUuid' => $organizationUuid
                        ]);
                    }
                }
            }

            $this->_logger->info('SoftwareCatalogueService: Completed user activation for organization', [
                'organizationUuid' => $organizationUuid,
                'totalContactpersonen' => count($contactpersonen),
                'activatedUsers' => $activatedCount
            ]);

        } catch (\Exception $e) {
            $this->_logger->error(
                'SoftwareCatalogueService: Failed to activate users for organization: ' . $e->getMessage(),
                [
                    'organizationUuid' => $organizationUuid,
                    'exception' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString()
                ]
            );
        }
    }

    /**
     * Deactivates all users in an organization when the organization becomes inactive
     *
     * @param string $organizationUuid The organization UUID
     * 
     * @return void
     */
    private function deactivateUsersForOrganization(string $organizationUuid): void
    {
        try {
            $this->_logger->info('SoftwareCatalogueService: Deactivating users for organization', [
                'organizationUuid' => $organizationUuid
            ]);

            $objectService = $this->_getObjectService();
            if (!$objectService) {
                $this->_logger->error('SoftwareCatalogueService: OpenRegister ObjectService not available');
                return;
            }

            // Get all contactpersonen for this organization
            $settingsService = $this->_container->get(SettingsService::class);
            $registerId = $settingsService->getVoorzieningenRegisterId();
            $contactpersoonSchemaId = $settingsService->getSchemaIdForObjectType('contactpersoon');

            if (!$registerId || !$contactpersoonSchemaId) {
                $this->_logger->error('SoftwareCatalogueService: Register or schema not configured for contactpersonen');
                return;
            }

            $contactpersonen = $objectService->findAll(
                ['organisation' => $organizationUuid],
                $registerId,
                $contactpersoonSchemaId
            );

            $userManager = $this->_container->get(\OCP\IUserManager::class);
            $deactivatedCount = 0;

            foreach ($contactpersonen as $contactpersoon) {
                $contactData = $contactpersoon->getObject();
                $username = $contactData['username'] ?? '';

                if (!empty($username)) {
                    $user = $userManager->get($username);
                    if ($user && $user->isEnabled()) {
                        $user->setEnabled(false);
                        $deactivatedCount++;

                        $this->_logger->info('SoftwareCatalogueService: Deactivated user for organization', [
                            'username' => $username,
                            'organizationUuid' => $organizationUuid
                        ]);
                    }
                }
            }

            $this->_logger->info('SoftwareCatalogueService: Completed user deactivation for organization', [
                'organizationUuid' => $organizationUuid,
                'totalContactpersonen' => count($contactpersonen),
                'deactivatedUsers' => $deactivatedCount
            ]);

        } catch (\Exception $e) {
            $this->_logger->error(
                'SoftwareCatalogueService: Failed to deactivate users for organization: ' . $e->getMessage(),
                [
                    'organizationUuid' => $organizationUuid,
                    'exception' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString()
                ]
            );
        }
    }

    /**
     * Activates SoftwareCatalog-specific users for an organization
     * Only affects users from contactpersoon objects, not admin group users
     *
     * @param string $organizationUuid The organization UUID
     * 
     * @return void
     */
    private function activateSoftwareCatalogUsersForOrganization(string $organizationUuid): void
    {
        try {
            $this->_logger->info('SoftwareCatalogueService: Activating SoftwareCatalog users for organization', [
                'organizationUuid' => $organizationUuid
            ]);

            // Get SoftwareCatalog-specific users (from contactpersonen)
            $softwareCatalogUsers = $this->getSoftwareCatalogUsersForOrganization($organizationUuid);
            
            if (empty($softwareCatalogUsers)) {
                $this->_logger->info('SoftwareCatalogueService: No SoftwareCatalog users found for organization', [
                    'organizationUuid' => $organizationUuid
                ]);
                return;
            }

            $this->_logger->info('SoftwareCatalogueService: Found SoftwareCatalog users to activate', [
                'organizationUuid' => $organizationUuid,
                'userCount' => count($softwareCatalogUsers),
                'users' => $softwareCatalogUsers
            ]);

            // Get the user manager
            $userManager = \OC::$server->getUserManager();
            $activatedUsers = [];
            $failedUsers = [];

            foreach ($softwareCatalogUsers as $username) {
                try {
                    $user = $userManager->get($username);
                    if ($user && !$user->isEnabled()) {
                        $user->setEnabled(true);
                        $activatedUsers[] = $username;
                        $this->_logger->debug('SoftwareCatalogueService: Activated SoftwareCatalog user', [
                            'organizationUuid' => $organizationUuid,
                            'username' => $username
                        ]);
                    } elseif ($user && $user->isEnabled()) {
                        $this->_logger->debug('SoftwareCatalogueService: SoftwareCatalog user already active', [
                            'organizationUuid' => $organizationUuid,
                            'username' => $username
                        ]);
                    } else {
                        $failedUsers[] = $username;
                        $this->_logger->warning('SoftwareCatalogueService: SoftwareCatalog user not found', [
                            'organizationUuid' => $organizationUuid,
                            'username' => $username
                        ]);
                    }
                } catch (\Exception $e) {
                    $failedUsers[] = $username;
                    $this->_logger->error('SoftwareCatalogueService: Failed to activate SoftwareCatalog user', [
                        'organizationUuid' => $organizationUuid,
                        'username' => $username,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            $this->_logger->info('SoftwareCatalogueService: SoftwareCatalog user activation complete', [
                'organizationUuid' => $organizationUuid,
                'activatedUsers' => $activatedUsers,
                'failedUsers' => $failedUsers,
                'totalProcessed' => count($softwareCatalogUsers)
            ]);

        } catch (\Exception $e) {
            $this->_logger->error('SoftwareCatalogueService: Error activating SoftwareCatalog users for organization', [
                'organizationUuid' => $organizationUuid,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Deactivates SoftwareCatalog-specific users for an organization
     * Only affects users from contactpersoon objects, not admin group users
     *
     * @param string $organizationUuid The organization UUID
     * 
     * @return void
     */
    private function deactivateSoftwareCatalogUsersForOrganization(string $organizationUuid): void
    {
        try {
            $this->_logger->info('SoftwareCatalogueService: Deactivating SoftwareCatalog users for organization', [
                'organizationUuid' => $organizationUuid
            ]);

            // Get SoftwareCatalog-specific users (from contactpersonen)
            $softwareCatalogUsers = $this->getSoftwareCatalogUsersForOrganization($organizationUuid);
            
            if (empty($softwareCatalogUsers)) {
                $this->_logger->info('SoftwareCatalogueService: No SoftwareCatalog users found for organization', [
                    'organizationUuid' => $organizationUuid
                ]);
                return;
            }

            $this->_logger->info('SoftwareCatalogueService: Found SoftwareCatalog users to deactivate', [
                'organizationUuid' => $organizationUuid,
                'userCount' => count($softwareCatalogUsers),
                'users' => $softwareCatalogUsers
            ]);

            // Get the user manager
            $userManager = \OC::$server->getUserManager();
            $deactivatedUsers = [];
            $failedUsers = [];

            foreach ($softwareCatalogUsers as $username) {
                try {
                    $user = $userManager->get($username);
                    if ($user && $user->isEnabled()) {
                        $user->setEnabled(false);
                        $deactivatedUsers[] = $username;
                        $this->_logger->debug('SoftwareCatalogueService: Deactivated SoftwareCatalog user', [
                            'organizationUuid' => $organizationUuid,
                            'username' => $username
                        ]);
                    } elseif ($user && !$user->isEnabled()) {
                        $this->_logger->debug('SoftwareCatalogueService: SoftwareCatalog user already inactive', [
                            'organizationUuid' => $organizationUuid,
                            'username' => $username
                        ]);
                    } else {
                        $failedUsers[] = $username;
                        $this->_logger->warning('SoftwareCatalogueService: SoftwareCatalog user not found', [
                            'organizationUuid' => $organizationUuid,
                            'username' => $username
                        ]);
                    }
                } catch (\Exception $e) {
                    $failedUsers[] = $username;
                    $this->_logger->error('SoftwareCatalogueService: Failed to deactivate SoftwareCatalog user', [
                        'organizationUuid' => $organizationUuid,
                        'username' => $username,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            $this->_logger->info('SoftwareCatalogueService: SoftwareCatalog user deactivation complete', [
                'organizationUuid' => $organizationUuid,
                'deactivatedUsers' => $deactivatedUsers,
                'failedUsers' => $failedUsers,
                'totalProcessed' => count($softwareCatalogUsers)
            ]);

        } catch (\Exception $e) {
            $this->_logger->error('SoftwareCatalogueService: Error deactivating SoftwareCatalog users for organization', [
                'organizationUuid' => $organizationUuid,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Gets SoftwareCatalog-specific users for an organization
     * These are users from contactpersoon objects, excluding admin group users
     *
     * @param string $organizationUuid The organization UUID
     * 
     * @return array Array of usernames
     */
    private function getSoftwareCatalogUsersForOrganization(string $organizationUuid): array
    {
        try {
            $this->_logger->debug('SoftwareCatalogueService: Getting SoftwareCatalog users for organization', [
                'organizationUuid' => $organizationUuid
            ]);

            // Get the object service to find contactpersonen
            $objectService = $this->_getObjectService();
            if (!$objectService) {
                $this->_logger->error('SoftwareCatalogueService: ObjectService not available for getting SoftwareCatalog users');
                return [];
            }

            // Find all contactpersonen for this organization
            $contactpersonen = $objectService->findAll([
                'filters' => [
                    'register' => 6, // Voorzieningen register
                    'schema' => 38   // Contactpersoon schema
                ]
            ]);

            $softwareCatalogUsers = [];
            $adminGroupUsers = $this->getAdminGroupUsernames();

            foreach ($contactpersonen as $contactpersoonObject) {
                $contactData = $contactpersoonObject->getObject();
                $contactOrganisatie = $contactData['organisatie'] ?? null;
                
                // Check if this contactpersoon belongs to our organization
                if ($contactOrganisatie === $organizationUuid) {
                    // Extract username from contactpersoon object data
                    $contactData = $contactpersoonObject->getObject();
                    $username = $contactData['username'] ?? null;
                    
                    if ($username && !in_array($username, $adminGroupUsers)) {
                        $softwareCatalogUsers[] = $username;
                        $this->_logger->debug('SoftwareCatalogueService: Found SoftwareCatalog user', [
                            'organizationUuid' => $organizationUuid,
                            'username' => $username,
                            'contactpersoonId' => $contactpersoonObject->getId()
                        ]);
                    }
                }
            }

            $this->_logger->info('SoftwareCatalogueService: Found SoftwareCatalog users for organization', [
                'organizationUuid' => $organizationUuid,
                'userCount' => count($softwareCatalogUsers),
                'users' => $softwareCatalogUsers
            ]);

            return $softwareCatalogUsers;

        } catch (\Exception $e) {
            $this->_logger->error('SoftwareCatalogueService: Error getting SoftwareCatalog users for organization', [
                'organizationUuid' => $organizationUuid,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Gets all usernames from the admin group
     *
     * @return array Array of admin usernames
     */
    private function getAdminGroupUsernames(): array
    {
        try {
            $groupManager = \OC::$server->getGroupManager();
            $adminGroup = $groupManager->get('admin');
            
            if (!$adminGroup) {
                $this->_logger->warning('SoftwareCatalogueService: Admin group not found');
                return [];
            }

            $adminUsers = $adminGroup->getUsers();
            $adminUsernames = [];
            
            foreach ($adminUsers as $adminUser) {
                $adminUsernames[] = $adminUser->getUID();
            }

            $this->_logger->debug('SoftwareCatalogueService: Found admin group users', [
                'adminUserCount' => count($adminUsernames),
                'adminUsers' => $adminUsernames
            ]);

            return $adminUsernames;

        } catch (\Exception $e) {
            $this->_logger->error('SoftwareCatalogueService: Error getting admin group usernames', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Adds all users from the admin group to the organization entity
     *
     * @param string $organizationUuid The organization UUID
     * 
     * @return void
     */
    private function addAdminGroupUsersToOrganization(string $organizationUuid): void
    {
        try {
            $this->_logger->info('SoftwareCatalogueService: Adding admin group users to organization entity', [
                'organizationUuid' => $organizationUuid
            ]);

            // Get the group manager to access admin group users
            $groupManager = \OC::$server->getGroupManager();
            $adminGroup = $groupManager->get('admin');
            
            if (!$adminGroup) {
                $this->_logger->warning('SoftwareCatalogueService: Admin group not found');
                return;
            }

            $adminUsers = $adminGroup->getUsers();
            $this->_logger->info('SoftwareCatalogueService: Found admin group users', [
                'organizationUuid' => $organizationUuid,
                'adminUserCount' => count($adminUsers)
            ]);

            // Get the organization entity (not object) to update its users list
            $organisationMapper = $this->_container->get('OCA\\OpenRegister\\Db\\OrganisationMapper');
            if (!$organisationMapper) {
                $this->_logger->error('SoftwareCatalogueService: OrganisationMapper not available for adding admin users');
                return;
            }

            // Find the organization entity by UUID
            $this->_logger->info('SoftwareCatalogueService: Searching for organization entity', [
                'organizationUuid' => $organizationUuid
            ]);
            
            try {
                $targetOrganisation = $organisationMapper->findByUuid($organizationUuid);
                
                $this->_logger->info('SoftwareCatalogueService: Found target organization entity', [
                    'organizationUuid' => $organizationUuid,
                    'entityId' => $targetOrganisation->getId()
                ]);
            } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
                $this->_logger->warning('SoftwareCatalogueService: Organization entity not found for adding admin users', [
                    'organizationUuid' => $organizationUuid
                ]);
                return;
            }

            // Get current users list from the entity
            $currentUsers = $targetOrganisation->getUsers() ?? [];
            
            $this->_logger->info('SoftwareCatalogueService: Current organization entity users', [
                'organizationUuid' => $organizationUuid,
                'currentUsers' => $currentUsers,
                'currentUserCount' => count($currentUsers)
            ]);
            
            // Add admin users to the list
            $updatedUsers = $currentUsers;
            $addedUsers = [];
            foreach ($adminUsers as $adminUser) {
                $adminUsername = $adminUser->getUID();
                if (!in_array($adminUsername, $updatedUsers)) {
                    $updatedUsers[] = $adminUsername;
                    $addedUsers[] = $adminUsername;
                    $this->_logger->debug('SoftwareCatalogueService: Added admin user to organization entity', [
                        'organizationUuid' => $organizationUuid,
                        'adminUsername' => $adminUsername
                    ]);
                }
            }
            
            $this->_logger->info('SoftwareCatalogueService: Admin users processing complete', [
                'organizationUuid' => $organizationUuid,
                'addedUsers' => $addedUsers,
                'totalUsersAfterUpdate' => count($updatedUsers)
            ]);

            // Update the organization entity with the new users list
            if (count($updatedUsers) > count($currentUsers)) {
                $this->_logger->info('SoftwareCatalogueService: Updating organization entity with new users', [
                    'organizationUuid' => $organizationUuid,
                    'entityId' => $targetOrganisation->getId(),
                    'usersToAdd' => count($updatedUsers) - count($currentUsers)
                ]);
                
                // Set the updated users list on the entity
                $targetOrganisation->setUsers($updatedUsers);
                
                $this->_logger->info('SoftwareCatalogueService: Saving updated organization entity', [
                    'organizationUuid' => $organizationUuid,
                    'entityId' => $targetOrganisation->getId(),
                    'newUserCount' => count($updatedUsers)
                ]);
                
                // Save the updated organization entity
                $savedOrganisation = $organisationMapper->save($targetOrganisation);
                
                $this->_logger->info('SoftwareCatalogueService: Successfully added admin users to organization entity', [
                    'organizationUuid' => $organizationUuid,
                    'addedUsers' => count($updatedUsers) - count($currentUsers),
                    'totalUsers' => count($updatedUsers)
                ]);
            } else {
                $this->_logger->info('SoftwareCatalogueService: All admin users already in organization entity', [
                    'organizationUuid' => $organizationUuid,
                    'totalUsers' => count($updatedUsers)
                ]);
            }

        } catch (\Exception $e) {
            $this->_logger->error('SoftwareCatalogueService: Failed to add admin users to organization entity: ' . $e->getMessage(), [
                'organizationUuid' => $organizationUuid,
                'exception' => $e
            ]);
        }
    }

    /**
     * Checks if a contactpersoon username is in the organization's users list
     *
     * @param object $contactpersoonObject The contactpersoon object
     * 
     * @return bool True if the user should be added to the organization
     */
    public function shouldAddContactpersoonToOrganization(object $contactpersoonObject): bool
    {
        try {
            $objectData = $contactpersoonObject->getObject();
            $username = $objectData['username'] ?? '';
            $organizationUuid = $objectData['organisation'] ?? '';

            if (empty($username) || empty($organizationUuid)) {
                return false;
            }

            $objectService = $this->_getObjectService();
            if (!$objectService) {
                return false;
            }

            // Get the organization object
            $settingsService = $this->_container->get(SettingsService::class);
            $registerId = $settingsService->getVoorzieningenRegisterId();
            $organisatieSchemaId = $settingsService->getSchemaIdForObjectType('organisatie');

            if (!$registerId || !$organisatieSchemaId) {
                return false;
            }

            try {
                $organizationObject = $objectService->find($organizationUuid, [], false, $registerId, $organisatieSchemaId);
                $organizationData = $organizationObject->getObject();
                
                // Check if the username is already in the organization's users
                $organizationUsers = $organizationData['users'] ?? [];
                
                if (is_array($organizationUsers) && !in_array($username, $organizationUsers)) {
                    $this->_logger->info('SoftwareCatalogueService: Contactpersoon should be added to organization', [
                        'username' => $username,
                        'organizationUuid' => $organizationUuid,
                        'currentUsers' => $organizationUsers
                    ]);
                    return true;
                }

                return false;

            } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
                // Organization doesn't exist, so we can't add the user
                $this->_logger->warning('SoftwareCatalogueService: Organization not found for contactpersoon', [
                    'username' => $username,
                    'organizationUuid' => $organizationUuid
                ]);
                return false;
            }

        } catch (\Exception $e) {
            $this->_logger->error(
                'SoftwareCatalogueService: Failed to check if contactpersoon should be added to organization: ' . $e->getMessage(),
                [
                    'objectId' => $contactpersoonObject->getId(),
                    'exception' => $e->getMessage()
                ]
            );
            return false;
        }
    }

    /**
     * Adds a contactpersoon username to the organization's users list
     *
     * @param object $contactpersoonObject The contactpersoon object
     * 
     * @return bool True if the user was successfully added
     */
    public function addContactpersoonToOrganization(object $contactpersoonObject): bool
    {
        try {
            $objectData = $contactpersoonObject->getObject();
            $username = $objectData['username'] ?? '';
            $organizationUuid = $objectData['organisation'] ?? '';

            if (empty($username) || empty($organizationUuid)) {
                $this->_logger->warning('SoftwareCatalogueService: Cannot add contactpersoon to organization - missing username or organization', [
                    'username' => $username,
                    'organizationUuid' => $organizationUuid
                ]);
                return false;
            }

            $objectService = $this->_getObjectService();
            if (!$objectService) {
                $this->_logger->error('SoftwareCatalogueService: OpenRegister ObjectService not available');
                return false;
            }

            // Get the organization object
            $settingsService = $this->_container->get(SettingsService::class);
            $registerId = $settingsService->getVoorzieningenRegisterId();
            $organisatieSchemaId = $settingsService->getSchemaIdForObjectType('organisatie');

            if (!$registerId || !$organisatieSchemaId) {
                $this->_logger->error('SoftwareCatalogueService: Register or schema not configured for organisatie');
                return false;
            }

            try {
                $organizationObject = $objectService->find($organizationUuid, [], false, $registerId, $organisatieSchemaId);
                $organizationData = $organizationObject->getObject();
                
                // Add the username to the organization's users list
                $organizationUsers = $organizationData['users'] ?? [];
                if (!is_array($organizationUsers)) {
                    $organizationUsers = [];
                }
                
                if (!in_array($username, $organizationUsers)) {
                    $organizationUsers[] = $username;
                    $organizationData['users'] = $organizationUsers;
                    
                    // Update the organization object
                    $updatedOrganization = $objectService->saveObject(
                        $organizationData,
                        [],
                        $registerId,
                        $organisatieSchemaId,
                        $organizationUuid
                    );

                    $this->_logger->info('SoftwareCatalogueService: Successfully added contactpersoon to organization', [
                        'username' => $username,
                        'organizationUuid' => $organizationUuid,
                        'updatedUsers' => $organizationUsers
                    ]);

                    return true;
                } else {
                    $this->_logger->debug('SoftwareCatalogueService: Contactpersoon already in organization', [
                        'username' => $username,
                        'organizationUuid' => $organizationUuid
                    ]);
                    return true; // Already there, consider it successful
                }

            } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
                $this->_logger->error('SoftwareCatalogueService: Organization not found for contactpersoon', [
                    'username' => $username,
                    'organizationUuid' => $organizationUuid
                ]);
                return false;
            }

        } catch (\Exception $e) {
            $this->_logger->error(
                'SoftwareCatalogueService: Failed to add contactpersoon to organization: ' . $e->getMessage(),
                [
                    'objectId' => $contactpersoonObject->getId(),
                    'exception' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString()
                ]
            );
            return false;
        }
    }

    /**
     * Handles ownership assignment for anonymous user registrations
     * 
     * @param object $organizationObject The organization object
     * 
     * @return void
     */
    private function handleOwnershipAssignment(object $organizationObject): void
    {
        try {
            $this->_logger->info('SoftwareCatalogueService: Handling ownership assignment for organization', [
                'objectId' => $organizationObject->getId()
            ]);

            $objectData = $organizationObject->getObject();
            $organizationUuid = $objectData['id'] ?? $organizationObject->getId();
            $contactpersonen = $objectData['contactpersonen'] ?? [];

            if (empty($contactpersonen)) {
                $this->_logger->info('SoftwareCatalogueService: No contact persons found for ownership assignment', [
                    'organizationUuid' => $organizationUuid
                ]);
                return;
            }

            // Get the first contact person as the primary owner
            $primaryContactUuid = $contactpersonen[0];
            
            $objectService = $this->_getObjectService();
            if (!$objectService) {
                $this->_logger->error('SoftwareCatalogueService: OpenRegister ObjectService not available for ownership assignment');
                return;
            }

            // Get the primary contact person object
            $settingsService = $this->_container->get(SettingsService::class);
            $registerId = $settingsService->getVoorzieningenRegisterId();
            $contactpersoonSchemaId = $settingsService->getSchemaIdForObjectType('contactpersoon');
            $organisatieSchemaId = $settingsService->getSchemaIdForObjectType('organisatie');

            if (!$registerId || !$contactpersoonSchemaId || !$organisatieSchemaId) {
                $this->_logger->error('SoftwareCatalogueService: Register or schema not configured for contactpersoon or organisatie');
                return;
            }

            // Retry mechanism for user creation timing
            $maxRetries = 3;
            $retryDelay = 1; // seconds
            
            for ($retry = 0; $retry < $maxRetries; $retry++) {
                try {
                    $primaryContactObject = $objectService->find($primaryContactUuid, [], false, $registerId, $contactpersoonSchemaId);
                    $primaryContactData = $primaryContactObject->getObject();
                    $primaryUsername = $primaryContactData['username'] ?? '';

                    if (empty($primaryUsername)) {
                        if ($retry < $maxRetries - 1) {
                            $this->_logger->info('SoftwareCatalogueService: Primary contact person has no username, retrying in ' . $retryDelay . ' seconds', [
                                'contactUuid' => $primaryContactUuid,
                                'organizationUuid' => $organizationUuid,
                                'retry' => $retry + 1,
                                'maxRetries' => $maxRetries
                            ]);
                            sleep($retryDelay);
                            continue;
                        } else {
                            $this->_logger->warning('SoftwareCatalogueService: Primary contact person still has no username after retries', [
                                'contactUuid' => $primaryContactUuid,
                                'organizationUuid' => $organizationUuid
                            ]);
                            return;
                        }
                    }

                    // Get the organization entity UUID - use the same UUID as the organization object
                    $organisationEntityUuid = $organizationUuid; // Organization entity should have same UUID as object

                    // Add users to the organization entity
                    $organisationMapper = $this->_container->get('OCA\\OpenRegister\\Db\\OrganisationMapper');
                    try {
                        $organisationEntity = $organisationMapper->findByUuid($organisationEntityUuid);
                        
                        // Add all contact person users to the organization entity
                        foreach ($contactpersonen as $contactUuid) {
                            try {
                                $contactObject = $objectService->find($contactUuid, [], false, $registerId, $contactpersoonSchemaId);
                                $contactData = $contactObject->getObject();
                                $contactUsername = $contactData['username'] ?? '';
                                
                                if (!empty($contactUsername)) {
                                    $organisationEntity->addUser($contactUsername);
                                }
                            } catch (\Exception $e) {
                                $this->_logger->warning('SoftwareCatalogueService: Failed to add contact person to organization entity', [
                                    'contactUuid' => $contactUuid,
                                    'error' => $e->getMessage()
                                ]);
                            }
                        }
                        
                        // Save the updated organization entity
                        $organisationMapper->save($organisationEntity);
                        
                        $this->_logger->info('SoftwareCatalogueService: Successfully added users to organization entity', [
                            'organizationUuid' => $organisationEntityUuid,
                            'userCount' => count($organisationEntity->getUserIds())
                        ]);
                        
                    } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
                        $this->_logger->error('SoftwareCatalogueService: Organization entity not found for adding users', [
                            'organizationUuid' => $organisationEntityUuid
                        ]);
                    }

                    // Update organization object ownership and organization reference
                    $organizationData['owner'] = $primaryUsername;
                    $organizationData['organisation'] = $organisationEntityUuid;
                    
                    $updatedOrganization = $objectService->saveObject(
                        $organizationData,
                        [],
                        $registerId,
                        $organisatieSchemaId,
                        $organizationUuid
                    );

                    // Update primary contact person object ownership and organization reference
                    $primaryContactData['owner'] = $primaryUsername;
                    $primaryContactData['organisatie'] = $organisationEntityUuid;
                    
                    $updatedPrimaryContact = $objectService->saveObject(
                        $primaryContactData,
                        [],
                        $registerId,
                        $contactpersoonSchemaId,
                        $primaryContactUuid
                    );

                    // Update other contact persons with organization reference
                    for ($i = 1; $i < count($contactpersonen); $i++) {
                        $contactUuid = $contactpersonen[$i];
                        try {
                            $contactObject = $objectService->find($contactUuid, [], false, $registerId, $contactpersoonSchemaId);
                            $contactData = $contactObject->getObject();
                            $contactUsername = $contactData['username'] ?? '';

                            if (!empty($contactUsername)) {
                                $contactData['owner'] = $contactUsername;
                                $contactData['organisatie'] = $organisationEntityUuid;
                                
                                $objectService->saveObject(
                                    $contactData,
                                    [],
                                    $registerId,
                                    $contactpersoonSchemaId,
                                    $contactUuid
                                );
                            }
                        } catch (\Exception $e) {
                            $this->_logger->warning('SoftwareCatalogueService: Failed to update contact person ownership', [
                                'contactUuid' => $contactUuid,
                                'error' => $e->getMessage()
                            ]);
                        }
                    }

                    $this->_logger->info('SoftwareCatalogueService: Successfully assigned ownership for organization', [
                        'organizationUuid' => $organizationUuid,
                        'primaryOwner' => $primaryUsername,
                        'organisationEntityUuid' => $organisationEntityUuid,
                        'contactPersonCount' => count($contactpersonen),
                        'retries' => $retry
                    ]);

                    return; // Success, exit retry loop

                } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
                    if ($retry < $maxRetries - 1) {
                        $this->_logger->info('SoftwareCatalogueService: Primary contact person not found, retrying in ' . $retryDelay . ' seconds', [
                            'contactUuid' => $primaryContactUuid,
                            'organizationUuid' => $organizationUuid,
                            'retry' => $retry + 1,
                            'maxRetries' => $maxRetries
                        ]);
                        sleep($retryDelay);
                        continue;
                    } else {
                        $this->_logger->error('SoftwareCatalogueService: Primary contact person not found after retries', [
                            'contactUuid' => $primaryContactUuid,
                            'organizationUuid' => $organizationUuid
                        ]);
                        return;
                    }
                }
            }

        } catch (\Exception $e) {
            $this->_logger->error('SoftwareCatalogueService: Error handling ownership assignment', [
                'objectId' => $organizationObject->getId(),
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
        }
    }

    /**
     * Synchronizes contact person usernames with the organization entity's users array
     * This method finds all contact persons associated with a given organization UUID
     * and ensures their emails are present in the organization entity's users array
     * 
     * @param string $organizationUuid The UUID of the organization
     * 
     * @return void
     */
    public function syncContactPersonUsernamesWithOrganization(string $organizationUuid): void
    {
        $this->_logger->info('SoftwareCatalogueService: Starting contact person username synchronization', [
            'organizationUuid' => $organizationUuid
        ]);
        
        // Get the ObjectService to find contact persons
        $objectService = $this->_getObjectService();
        if (!$objectService) {
            $this->_logger->error('SoftwareCatalogueService: ObjectService not available for username synchronization');
            return;
        }
        
        // Get the contact person schema ID from configuration
        $settingsService = $this->_container->get('OCA\SoftwareCatalog\Service\SettingsService');
        $voorzieningenConfig = $settingsService->getVoorzieningenConfig();
        $contactSchemaId = $voorzieningenConfig['contactpersoon_schema'] ?? null;
        $registerId = $voorzieningenConfig['register'] ?? null;
        
        if (!$contactSchemaId || !$registerId) {
            $this->_logger->warning('SoftwareCatalogueService: Missing Voorzieningen configuration for contact person sync', [
                'organizationUuid' => $organizationUuid,
                'contactSchemaId' => $contactSchemaId,
                'registerId' => $registerId
            ]);
            return;
        }
        
        try {
            // Find all contact persons that have this organization as their organisatie
            $contactPersons = $objectService->findAll([
                'filters' => [
                    'register' => (string) $registerId,
                    'schema' => $contactSchemaId,
                    'organisatie' => $organizationUuid
                ]
            ]);
            
            $this->_logger->info('SoftwareCatalogueService: Found contact persons for synchronization', [
                'organizationUuid' => $organizationUuid,
                'contactPersonCount' => count($contactPersons)
            ]);
            
            // Collect all usernames from contact persons
            $contactPersonUsernames = [];
            foreach ($contactPersons as $contactPerson) {
                $contactData = $contactPerson->getObject();
                $email = $contactData['email'] ?? null;
                if ($email) {
                    $contactPersonUsernames[] = $email;
                    $this->_logger->info('SoftwareCatalogueService: Found contact person username', [
                        'username' => $email,
                        'contactPersonId' => $contactPerson->getId()
                    ]);
                }
            }
            
            // Get the organization entity
            $organisationMapper = $this->_container->get('OCA\\OpenRegister\\Db\\OrganisationMapper');
            $organisation = $organisationMapper->findByUuid($organizationUuid);
            
            if (!$organisation) {
                $this->_logger->error('SoftwareCatalogueService: Organization entity not found for synchronization', [
                    'organizationUuid' => $organizationUuid
                ]);
                return;
            }
            
            // Get current users and add contact person usernames
            $currentUsers = $organisation->getUsers() ?? [];
            $allUsers = array_merge($currentUsers, $contactPersonUsernames);
            $allUsers = array_unique($allUsers);
            
            $this->_logger->info('SoftwareCatalogueService: Updating organization entity users', [
                'organizationUuid' => $organizationUuid,
                'currentUsers' => $currentUsers,
                'contactPersonUsernames' => $contactPersonUsernames,
                'finalUsers' => $allUsers
            ]);
            
            // Update the organization entity
            $organisation->setUsers($allUsers);
            $organisationMapper->save($organisation);
            
            $this->_logger->info('SoftwareCatalogueService: Successfully synchronized contact person usernames', [
                'organizationUuid' => $organizationUuid,
                'totalUsers' => count($allUsers)
            ]);
            
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            // Organization entity doesn't exist yet - this can happen due to race conditions
            // Log and return gracefully, the organization sync will handle this later
            $this->_logger->warning('SoftwareCatalogueService: Organization entity not found during username sync (race condition)', [
                'organizationUuid' => $organizationUuid,
                'message' => 'This is expected during anonymous registration - organization entity is created after contact persons'
            ]);
        } catch (\Exception $e) {
            $this->_logger->error('SoftwareCatalogueService: Error synchronizing contact person usernames', [
                'organizationUuid' => $organizationUuid,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Ensures a contact person's username is in their organization's users array
     * This method is called when a contact person is created or updated
     * 
     * @param object $contactPersonObject The contact person object
     * 
     * @return void
     */
    public function ensureContactPersonInOrganization(object $contactPersonObject): void
    {
        $contactData = $contactPersonObject->getObject();
        $email = $contactData['email'] ?? null;
        $organisatie = $contactData['organisatie'] ?? null;
        
        if (!$email || !$organisatie) {
            $this->_logger->info('SoftwareCatalogueService: Contact person missing email or organisation', [
                'contactPersonId' => $contactPersonObject->getId(),
                'hasEmail' => !empty($email),
                'hasOrganisatie' => !empty($organisatie)
            ]);
            return;
        }
        
        // Skip if the contact person is owned by the default organization
        $owner = $contactPersonObject->getOwner();
        if ($owner === 'system') {
            $this->_logger->info('SoftwareCatalogueService: Skipping contact person owned by system', [
                'contactPersonId' => $contactPersonObject->getId(),
                'username' => $email
            ]);
            return;
        }
        
        $this->_logger->info('SoftwareCatalogueService: Ensuring contact person in organization', [
            'contactPersonId' => $contactPersonObject->getId(),
            'username' => $email,
            'organisatie' => $organisatie
        ]);
        
        try {
            // Get the organization entity
            $organisationMapper = $this->_container->get('OCA\\OpenRegister\\Db\\OrganisationMapper');
            $organisation = $organisationMapper->findByUuid($organisatie);
            
            if (!$organisation) {
                $this->_logger->error('SoftwareCatalogueService: Organization entity not found for contact person', [
                    'contactPersonId' => $contactPersonObject->getId(),
                    'organisatie' => $organisatie
                ]);
                return;
            }
            
            // Check if the username is already in the organization's users array
            $currentUsers = $organisation->getUsers() ?? [];
            if (in_array($email, $currentUsers)) {
                $this->_logger->info('SoftwareCatalogueService: Contact person already in organization', [
                    'contactPersonId' => $contactPersonObject->getId(),
                    'username' => $email,
                    'organisatie' => $organisatie
                ]);
                return;
            }
            
            // Add the username to the organization's users array
            $currentUsers[] = $email;
            $organisation->setUsers($currentUsers);
            $organisationMapper->save($organisation);
            
            $this->_logger->info('SoftwareCatalogueService: Successfully added contact person to organization', [
                'contactPersonId' => $contactPersonObject->getId(),
                'username' => $email,
                'organisatie' => $organisatie,
                'totalUsers' => count($currentUsers)
            ]);
            
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            // Organization entity doesn't exist yet - this can happen due to race conditions
            // Log and return gracefully, the organization sync will handle this later
            $this->_logger->warning('SoftwareCatalogueService: Organization entity not found (race condition), will be handled by organization sync', [
                'contactPersonId' => $contactPersonObject->getId(),
                'username' => $email,
                'organisatie' => $organisatie,
                'message' => 'This is expected during anonymous registration - organization entity is created after contact persons'
            ]);
            return;
        }
    }

    /**
     * Updates organization references on objects to point to the newly created organization entity
     * 
     * @param object $organizationObject The organization object
     * 
     * @return void
     */
    private function updateOrganizationReferences(object $organizationObject): void
    {
        try {
            $this->_logger->info('SoftwareCatalogueService: Updating organization references', [
                'objectId' => $organizationObject->getId()
            ]);

            $objectData = $organizationObject->getObject();
            $organizationUuid = $objectData['id'] ?? $organizationObject->getId();

            // Get the ObjectService to update objects
            $objectService = $this->_getObjectService();
            if (!$objectService) {
                $this->_logger->error('SoftwareCatalogueService: ObjectService not available for updating references');
                return;
            }

            // Get the organization entity UUID (should be the same as the organization object UUID)
            $organisationMapper = $this->_container->get('OCA\\OpenRegister\\Db\\OrganisationMapper');
            try {
                // Use the original UUID format for OpenRegister lookup
                $organisationEntity = $organisationMapper->findByUuid($organizationUuid);
                $organisationEntityUuid = $organisationEntity->getUuid();
                
                $this->_logger->info('SoftwareCatalogueService: Found organization entity for reference update', [
                    'organizationObjectUuid' => $organizationUuid,
                    'organizationEntityUuid' => $organisationEntityUuid
                ]);
            } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
                $this->_logger->error('SoftwareCatalogueService: Organization entity not found for reference update', [
                    'organizationUuid' => $organizationUuid
                ]);
                return;
            }

            // Update the organization object's @self.organisation field
            $this->_logger->info('SoftwareCatalogueService: Updating organization object reference', [
                'objectId' => $organizationObject->getId(),
                'newOrganisationUuid' => $organisationEntityUuid
            ]);

            // Get the current object data and update the organisation field
            $currentObjectData = $organizationObject->getObject();
            $currentObjectData['@self']['organisation'] = $organisationEntityUuid;
            
            // Update the organization object using the ObjectService
            $objectService->updateFromArray(
                $organizationObject->getId(),
                $currentObjectData,
                false, // don't update version
                false, // not a patch
                [], // no extend
                $organizationObject->getRegisterId(),
                $organizationObject->getSchemaId()
            );

            // Update contact person objects' @self.organisatie field
            $contactpersonen = $objectData['contactpersonen'] ?? [];
            foreach ($contactpersonen as $contactUuid) {
                $this->_logger->info('SoftwareCatalogueService: Updating contact person object reference', [
                    'contactUuid' => $contactUuid,
                    'newOrganisationUuid' => $organisationEntityUuid
                ]);

                // Get the contact person schema ID from configuration
                $settingsService = $this->_container->get('OCA\SoftwareCatalog\Service\SettingsService');
                $voorzieningenConfig = $settingsService->getVoorzieningenConfig();
                $contactSchemaId = $voorzieningenConfig['contactpersoon_schema'] ?? null;
                
                if (!$contactSchemaId) {
                    $this->_logger->warning('SoftwareCatalogueService: Missing contactpersoon schema configuration for object update', [
                        'contactUuid' => $contactUuid
                    ]);
                    continue;
                }

                // Find the contact person object
                try {
                    $contactObject = $objectService->find($contactUuid, [], false, $organizationObject->getRegisterId(), $contactSchemaId);
                    if ($contactObject) {
                        // Get the current object data and update the organisatie field
                        $contactObjectData = $contactObject->getObject();
                        $contactObjectData['@self']['organisatie'] = $organisationEntityUuid;
                        
                        // Update the contact person object using the ObjectService
                        $objectService->updateFromArray(
                            $contactObject->getId(),
                            $contactObjectData,
                            false, // don't update version
                            false, // not a patch
                            [], // no extend
                            $organizationObject->getRegisterId(),
                            $contactSchemaId
                        );
                    }
                } catch (\Exception $e) {
                    $this->_logger->error('SoftwareCatalogueService: Failed to update contact person object', [
                        'contactUuid' => $contactUuid,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            $this->_logger->info('SoftwareCatalogueService: Successfully updated organization references', [
                'organizationUuid' => $organizationUuid,
                'organizationEntityUuid' => $organisationEntityUuid,
                'contactPersonCount' => count($contactpersonen)
            ]);

        } catch (\Exception $e) {
            $this->_logger->error(
                'SoftwareCatalogueService: Failed to update organization references: ' . $e->getMessage(),
                [
                    'objectId' => $organizationObject->getId(),
                    'exception' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString()
                ]
            );
        }
    }

} 