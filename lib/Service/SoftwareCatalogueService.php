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
                    // Update user groups
                    $this->_logger->debug('SoftwareCatalogueService: Updating user groups', [
                        'objectId' => $objectId,
                        'username' => $username
                    ]);
                    
                    $this->_groupHandler->updateUserGroups($contactpersoonObject, $username);
                    
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
     * Processes a contactgegevens object to ensure it has a username (for backward compatibility)
     *
     * If the contactgegevens object doesn't have a username or it's empty,
     * this method will create a user account and set the username property.
     *
     * @param object $contactgegevensObject The contactgegevens object to process
     * 
     * @return bool True if processing was successful
     * @throws \Exception If processing fails
     */
    public function processContactgegevens(object $contactgegevensObject): bool
    {
        try {
            $this->_logger->info('Processing contactgegevens object (backward compatibility)', [
                'objectId' => $contactgegevensObject->getId()
            ]);

            // Delegate to contact person handler
            $result = $this->_contactPersonHandler->processContactgegevens($contactgegevensObject);
            
            if ($result) {
                // Get the username from the processed object
                $objectData = $contactgegevensObject->getObject();
                $username = $objectData['username'] ?? '';
                
                if (!empty($username)) {
                    // Update user groups
                    $this->_groupHandler->updateUserGroups($contactgegevensObject, $username);
                    
                    // Ensure organization has beheerder and set up manager relationships
                    $this->_hierarchyHandler->ensureOrganizationBeheerder($contactgegevensObject, $username);
                }
            }
            
            return $result;
            
        } catch (\Exception $e) {
            $this->_logger->error(
                'Failed to process contactgegevens object: ' . $e->getMessage(), 
                [
                    'exception' => $e,
                    'objectId' => $contactgegevensObject->getId() ?? 'unknown'
                ]
            );
            throw $e;
        }
    }

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
     * Updates user groups based on contactgegevens data
     *
     * @param object $contactgegevensObject The contactgegevens object
     * @param string $username              The username to update groups for
     * 
     * @return void
     */
    public function updateUserGroups(object $contactgegevensObject, string $username): void
    {
        // Delegate to group handler
        $this->_groupHandler->updateUserGroups($contactgegevensObject, $username);
    }

    /**
     * Ensures organization has at least one beheerder and manages user hierarchy
     *
     * @param object $contactgegevensObject The contactgegevens object
     * @param string $username              The username being processed
     * 
     * @return void
     */
    public function ensureOrganizationBeheerder(object $contactgegevensObject, string $username): void
    {
        // Delegate to hierarchy handler
        $this->_hierarchyHandler->ensureOrganizationBeheerder($contactgegevensObject, $username);
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
     * Handles new organization creation
     * 
     * @deprecated This method is disabled to prevent organization duplication
     * @param object $organizationObject The organization object
     * 
     * @return void
     */
    public function handleNewOrganization(object $organizationObject): void
    {
        // DISABLED: Organization handling is disabled to prevent duplication
        $this->_logger->info(
            'Organization handling is disabled to prevent duplication',
            [
                'organizationId' => $organizationObject->getId()
            ]
        );
        
        return;
        
        /*
        try {
            $this->_logger->info('Handling new organization via main service', [
                'objectId' => $organizationObject->getId()
            ]);

            // Send welcome email for new organization
            $this->sendOrganizationWelcomeEmail($organizationObject);
            
            // Process the organization which will handle contactpersonen if active
            $this->processOrganization($organizationObject);
            
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
            
        } catch (\Exception $e) {
            $this->_logger->error(
                'Failed to handle new organization in main service: ' . $e->getMessage(),
                [
                    'objectId' => $organizationObject->getId(),
                    'exception' => $e
                ]
            );
        }
        */
    }

    /**
     * Handles organization updates - specifically checking for beoordeling status changes
     * 
     * @deprecated This method is disabled to prevent organization duplication
     * @param object $organizationObject    The updated organization object
     * @param object $oldOrganizationObject The previous organization object
     * 
     * @return void
     */
    public function handleOrganizationUpdate(object $organizationObject, object $oldOrganizationObject): void
    {
        // DISABLED: Organization handling is disabled to prevent duplication
        $this->_logger->info(
            'Organization update handling is disabled to prevent duplication',
            [
                'organizationId' => $organizationObject->getId()
            ]
        );
        
        return;
        
        /*
        try {
            $this->_logger->info('Handling organization update', [
                'objectId' => $organizationObject->getId()
            ]);

            $newData = $organizationObject->getObject();
            $oldData = $oldOrganizationObject->getObject();
            
            $newBeoordeling = strtolower($newData['beoordeling'] ?? '');
            $oldBeoordeling = strtolower($oldData['beoordeling'] ?? '');
            
            // Check if organization status changed to active
            if ($newBeoordeling === 'actief') {
                $becameActive = ($oldBeoordeling !== 'actief');
                
                $this->_logger->info(
                    $becameActive ? 'Organization became active, sending activation email and activating contactpersonen' : 'Organization is active',
                    [
                        'organizationId' => $organizationObject->getId(),
                        'oldBeoordeling' => $oldBeoordeling,
                        'newBeoordeling' => $newBeoordeling,
                        'becameActive' => $becameActive
                    ]
                );
                
                // Send activation email if organization just became active
                if ($becameActive) {
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
                    
                    // Activate related contactpersonen users
                    try {
                        $this->activateContactpersonenForOrganization($organizationObject->getUuid());
                    } catch (\Exception $e) {
                        $this->_logger->error('Failed to activate contactpersonen users: ' . $e->getMessage(), [
                            'objectId' => $organizationObject->getId(),
                            'exception' => $e
                        ]);
                    }
                }
            } else {
                $this->_logger->info(
                    'Organization not active, no special processing needed',
                    [
                        'organizationId' => $organizationObject->getId(),
                        'beoordeling' => $newBeoordeling
                    ]
                );
            }
            
        } catch (\Exception $e) {
            $this->_logger->error(
                'Failed to handle organization update: ' . $e->getMessage(),
                [
                    'objectId' => $organizationObject->getId(),
                    'exception' => $e
                ]
            );
        }
        */
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
            $registerId = $settingsService->getVoorzieningenRegisterId() ?? '6';
            
            // Find contactpersonen related to this organization
            $contactpersoonSchemaId = $settingsService->getSchemaIdForObjectType('contactpersoon') ?? '34';
            $contactgegevensSchemaId = $settingsService->getSchemaIdForObjectType('contactgegevens') ?? '34';
            
            $this->_logger->info('Schema IDs for contactpersonen search', [
                'organizationId' => $organizationId,
                'registerId' => $registerId,
                'contactpersoonSchemaId' => $contactpersoonSchemaId,
                'contactgegevensSchemaId' => $contactgegevensSchemaId
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
            
            // Check contactgegevens objects (backward compatibility)
            if ($contactgegevensSchemaId && $contactgegevensSchemaId !== $contactpersoonSchemaId) {
                $contactgegevensObjects = $objectService->findAll(
                    (int) $registerId,
                    (int) $contactgegevensSchemaId,
                    ['organisation' => $organizationId]
                );
                
                foreach ($contactgegevensObjects as $contactgegevensObject) {
                    $contactData = $contactgegevensObject->getObject();
                    $username = $contactData['username'] ?? '';
                    
                    if (!empty($username) && !in_array($username, $activatedUsers)) {
                        $success = $this->_contactPersonHandler->setUserActive($username);
                        if ($success) {
                            $activatedUsers[] = $username;
                            $this->_logger->info('Activated contactgegevens user', [
                                'username' => $username,
                                'organizationId' => $organizationId,
                                'contactgegevensId' => $contactgegevensObject->getId()
                            ]);
                        }
                    }
                }
            }
            
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
            
            // Process the contactpersoon to ensure user exists and is properly set up
            $this->_logger->debug('SoftwareCatalogueService: Processing contactpersoon to ensure user exists', [
                'objectId' => $objectId
            ]);
            
            $result = $this->processContactpersoon($contactpersoonObject, true);
            
            $this->_logger->info('SoftwareCatalogueService: Contactpersoon processing completed', [
                'objectId' => $objectId,
                'result' => $result,
                'processingTime' => round((microtime(true) - $startTime) * 1000, 2) . 'ms'
            ]);
            
            if ($result) {
                $username = $newData['username'] ?? '';
                
                $this->_logger->info('SoftwareCatalogueService: Username extracted from updated object', [
                    'objectId' => $objectId,
                    'username' => $username,
                    'hasUsername' => !empty($username)
                ]);
                
                if (!empty($username)) {
                    // Check if roles have changed - if so, handle role-specific group updates
                    if ($newRoles !== $oldRoles) {
                        $this->_logger->info(
                            'SoftwareCatalogueService: Roles changed for contactpersoon, updating user groups specifically for role changes',
                            [
                                'contactpersoonId' => $objectId,
                                'username' => $username,
                                'oldRoles' => $oldRoles,
                                'newRoles' => $newRoles,
                                'addedRoles' => array_diff($newRoles, $oldRoles),
                                'removedRoles' => array_diff($oldRoles, $newRoles)
                            ]
                        );
                        
                        // Get the user and update groups based on specific role changes
                        $this->_logger->debug('SoftwareCatalogueService: Getting user object for role-specific group updates', [
                            'username' => $username,
                            'objectId' => $objectId
                        ]);
                        
                        $user = $this->_container->get(\OCP\IUserManager::class)->get($username);
                        if ($user) {
                            $this->_logger->debug('SoftwareCatalogueService: User object found, updating groups from roles', [
                                'username' => $username,
                                'objectId' => $objectId,
                                'userExists' => true
                            ]);
                            
                            $this->_contactPersonHandler->updateUserGroupsFromRoles($user, $newRoles, $oldRoles);
                            
                            $this->_logger->info('SoftwareCatalogueService: Role-specific group updates completed', [
                                'username' => $username,
                                'objectId' => $objectId
                            ]);
                        } else {
                            $this->_logger->warning('SoftwareCatalogueService: User not found for role-specific group updates', [
                                'username' => $username,
                                'objectId' => $objectId
                            ]);
                        }
                    } else {
                        $this->_logger->info(
                            'SoftwareCatalogueService: No role changes detected for contactpersoon, groups updated via processContactpersoon',
                            [
                                'contactpersoonId' => $objectId,
                                'username' => $username,
                                'roles' => $newRoles
                            ]
                        );
                    }
                } else {
                    $this->_logger->warning('SoftwareCatalogueService: No username available for contactpersoon update', [
                        'objectId' => $objectId,
                        'newData' => $newData
                    ]);
                }
            } else {
                $this->_logger->warning('SoftwareCatalogueService: Contactpersoon processing returned false', [
                    'objectId' => $objectId
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
     * Handles contactgegevens updates, particularly role changes (for backward compatibility)
     *
     * @param object $contactgegevensObject    The updated contactgegevens object
     * @param object $oldContactgegevensObject The previous contactgegevens object (optional)
     * 
     * @return void
     */
    public function handleContactgegevensUpdate(object $contactgegevensObject, object $oldContactgegevensObject = null): void
    {
        try {
            $this->_logger->info('Handling contactgegevens update (backward compatibility)', [
                'objectId' => $contactgegevensObject->getId()
            ]);

            // Get current and old data for comparison
            $newData = $contactgegevensObject->getObject();
            $oldData = $oldContactgegevensObject ? $oldContactgegevensObject->getObject() : [];
            
            $newRoles = $newData['roles'] ?? [];
            $oldRoles = $oldData['roles'] ?? [];
            
            // Ensure both are arrays
            if (!is_array($newRoles)) {
                $newRoles = [$newRoles];
            }
            if (!is_array($oldRoles)) {
                $oldRoles = [$oldRoles];
            }

            // Process the contactgegevens to ensure user exists and is properly set up
            $result = $this->processContactgegevens($contactgegevensObject);
            
            if ($result) {
                $username = $newData['username'] ?? '';
                
                if (!empty($username)) {
                    // Check if roles have changed - if so, handle role-specific group updates
                    if ($newRoles !== $oldRoles) {
                        $this->_logger->info(
                            'Roles changed for contactgegevens, updating user groups specifically for role changes',
                            [
                                'contactgegevensId' => $contactgegevensObject->getId(),
                                'username' => $username,
                                'oldRoles' => $oldRoles,
                                'newRoles' => $newRoles
                            ]
                        );
                        
                        // Get the user and update groups based on specific role changes
                        $user = $this->_container->get(\OCP\IUserManager::class)->get($username);
                        if ($user) {
                            $this->_contactPersonHandler->updateUserGroupsFromRoles($user, $newRoles, $oldRoles);
                        }
                    } else {
                        $this->_logger->info(
                            'No role changes detected for contactgegevens, groups updated via processContactgegevens',
                            [
                                'contactgegevensId' => $contactgegevensObject->getId(),
                                'username' => $username,
                                'roles' => $newRoles
                            ]
                        );
                    }
                }
            }
            
        } catch (\Exception $e) {
            $this->_logger->error(
                'Failed to handle contactgegevens update: ' . $e->getMessage(),
                [
                    'objectId' => $contactgegevensObject->getId(),
                    'exception' => $e
                ]
            );
        }
    }
} 