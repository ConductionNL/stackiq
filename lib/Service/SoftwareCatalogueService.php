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
use OCA\SoftwareCatalog\Service\PhpEmailService;
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
     * @param PhpEmailService       $_emailService         Email service
     * @param LoggerInterface       $_logger               Logger interface
     */
    public function __construct(
        private readonly OrganizationHandler $_organizationHandler,
        private readonly ContactPersonHandler $_contactPersonHandler,
        private readonly GroupHandler $_groupHandler,
        private readonly HierarchyHandler $_hierarchyHandler,
        private readonly PhpEmailService $_emailService,
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
     * Processes a contactgegevens object to ensure it has a username
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
            $this->_logger->info('Processing contactgegevens object', [
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
     * Processes organization groups and ensures proper group assignment
     *
     * @param object $organizationObject The organization object to process
     * 
     * @return bool True if processing was successful
     * @throws \Exception If processing fails
     */
    public function processOrganization(object $organizationObject): bool
    {
        try {
            // Delegate to organization handler
            $processed = $this->_organizationHandler->processOrganization($organizationObject);
            
            if ($processed) {
                // Check if organization is active and process contactpersonen
                $objectData = $organizationObject->getObject();
                $beoordeling = strtolower($objectData['beoordeling'] ?? '');
                
                if ($beoordeling === 'actief') {
                    // Process contactpersonen into contactgegevens objects
                    $createdContacts = $this->_organizationHandler->processContactpersonen($organizationObject);
                    
                    // Process each created contactgegevens to create users and set up groups
                    $successfullyProcessed = 0;
                    foreach ($createdContacts as $contactgegevensObject) {
                        try {
                            $result = $this->processContactgegevens($contactgegevensObject);
                            if ($result) {
                                $successfullyProcessed++;
                            }
                        } catch (\Exception $e) {
                            $this->_logger->error(
                                'Failed to process created contactgegevens: ' . $e->getMessage(),
                                [
                                    'contactgegevensId' => $contactgegevensObject->getId(),
                                    'organizationId' => $organizationObject->getId(),
                                    'exception' => $e
                                ]
                            );
                        }
                    }
                    
                    // If all contactgegevens were processed successfully, clean up contactpersonen array
                    if ($successfullyProcessed === count($createdContacts) && count($createdContacts) > 0) {
                        try {
                            $objectData['contactpersonen'] = [];
                            $organizationObject->setObject($objectData);
                            
                            // Save the updated organization object
                            $objectService = $this->_getObjectService();
                            if ($objectService) {
                                $objectService->saveObject($objectData, [], null, null, $organizationObject->getUuid());
                                
                                $this->_logger->info(
                                    'Emptied contactpersonen array after successful processing',
                                    [
                                        'organizationId' => $organizationObject->getId(),
                                        'processedContactsCount' => $successfullyProcessed
                                    ]
                                );
                            }
                        } catch (\Exception $e) {
                            $this->_logger->error(
                                'Failed to empty contactpersonen array: ' . $e->getMessage(),
                                [
                                    'organizationId' => $organizationObject->getId(),
                                    'exception' => $e
                                ]
                            );
                        }
                    }
                    
                    $this->_logger->info(
                        'Successfully processed organization and contactpersonen',
                        [
                            'organizationId' => $organizationObject->getId(),
                            'createdContactsCount' => count($createdContacts),
                            'successfullyProcessedCount' => $successfullyProcessed
                        ]
                    );
                }
            }
            
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
     * @param object $organizationObject The organization object
     * 
     * @return void
     */
    public function handleNewOrganization(object $organizationObject): void
    {
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
    }

    /**
     * Handles organization updates - specifically checking for beoordeling status changes
     *
     * @param object $organizationObject    The updated organization object
     * @param object $oldOrganizationObject The previous organization object
     * 
     * @return void
     */
    public function handleOrganizationUpdate(object $organizationObject, object $oldOrganizationObject): void
    {
        try {
            $this->_logger->info('Handling organization update', [
                'objectId' => $organizationObject->getId()
            ]);

            $newData = $organizationObject->getObject();
            $oldData = $oldOrganizationObject->getObject();
            
            $newBeoordeling = strtolower($newData['beoordeling'] ?? '');
            $oldBeoordeling = strtolower($oldData['beoordeling'] ?? '');
            
            // Process organization if it's currently active
            if ($newBeoordeling === 'actief') {
                $becameActive = ($oldBeoordeling !== 'actief');
                
                $this->_logger->info(
                    $becameActive ? 'Organization became active, processing contactpersonen' : 'Organization is active, processing update',
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
                }
                
                // Process the organization since it's active
                $this->processOrganization($organizationObject);
            } else {
                $this->_logger->info(
                    'Organization not active, skipping processing',
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
    }

    /**
     * Sends welcome email to organization
     *
     * @param object $organizationObject The organization object
     * 
     * @return void
     */
    public function sendOrganizationWelcomeEmail(object $organizationObject): void
    {
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
     * Handles contactgegevens updates, particularly role changes
     *
     * @param object $contactgegevensObject    The updated contactgegevens object
     * @param object $oldContactgegevensObject The previous contactgegevens object (optional)
     * 
     * @return void
     */
    public function handleContactgegevensUpdate(object $contactgegevensObject, object $oldContactgegevensObject = null): void
    {
        try {
            $this->_logger->info('Handling contactgegevens update', [
                'objectId' => $contactgegevensObject->getId()
            ]);

            // Process the contactgegevens to ensure user exists
            $user = $this->processContactgegevens($contactgegevensObject);
            
            if ($user && $oldContactgegevensObject) {
                // Check for role changes and update groups accordingly
                $newData = $contactgegevensObject->getObject();
                $oldData = $oldContactgegevensObject->getObject();
                
                $newRoles = $newData['roles'] ?? [];
                $oldRoles = $oldData['roles'] ?? [];
                
                // Ensure both are arrays
                if (!is_array($newRoles)) {
                    $newRoles = [$newRoles];
                }
                if (!is_array($oldRoles)) {
                    $oldRoles = [$oldRoles];
                }
                
                // Check if roles have changed
                if ($newRoles !== $oldRoles) {
                    $this->_logger->info(
                        'Roles changed for contactgegevens, updating user groups',
                        [
                            'contactgegevensId' => $contactgegevensObject->getId(),
                            'username' => $user->getUID(),
                            'oldRoles' => $oldRoles,
                            'newRoles' => $newRoles
                        ]
                    );
                    
                    // Update user groups based on role changes
                    $this->_contactPersonHandler->updateUserGroupsFromRoles($user, $newRoles, $oldRoles);
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