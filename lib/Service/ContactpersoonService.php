<?php
/**
 * Contactpersoon Service
 *
 * This file contains the service class for handling contact person-specific operations
 * in the SoftwareCatalog application.
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

use OCA\SoftwareCatalog\Service\SoftwareCatalogue\ContactPersonHandler;
use OCA\SoftwareCatalog\Service\SoftwareCatalogue\GroupHandler;
use OCA\SoftwareCatalog\Service\SoftwareCatalogue\HierarchyHandler;
use Psr\Log\LoggerInterface;
use Psr\Container\ContainerInterface;
use OCP\App\IAppManager;
use OCP\IAppConfig;

/**
 * Service for handling contact person-specific operations
 * 
 * This service provides functionality for contact person processing,
 * user account creation, and group management.
 * 
 * @category Service
 * @package  OCA\SoftwareCatalog\Service
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @version  1.0.0
 * @link     https://github.com/ConductionNL/SoftwareCatalog
 */
class ContactpersoonService
{
    /**
     * ContactpersoonService constructor
     *
     * @param ContactPersonHandler $contactPersonHandler Contact person handler
     * @param GroupHandler         $groupHandler         Group handler
     * @param HierarchyHandler     $hierarchyHandler     Hierarchy handler
     * @param LoggerInterface      $logger               Logger interface
     * @param ContainerInterface   $container            Container interface
     * @param IAppManager         $appManager           App manager
     * @param IAppConfig          $config               Configuration service
     */
    public function __construct(
        private readonly ContactPersonHandler $contactPersonHandler,
        private readonly GroupHandler $groupHandler,
        private readonly HierarchyHandler $hierarchyHandler,
        private readonly LoggerInterface $logger,
        private readonly ContainerInterface $container,
        private readonly IAppManager $appManager,
        private readonly IAppConfig $config,
        private readonly SettingsService $settingsService
    ) {
    }

    /**
     * Processes a contactpersoon object to create a user account
     *
     * If the contactpersoon object doesn't have a user or the user is missing,
     * this method will create a user account with appropriate status.
     *
     * @param object $contactpersoonObject The contactpersoon object to process
     * @param bool   $isUpdate             Whether this is an update operation
     * 
     * @return bool True if processing was successful
     * @throws \Exception If processing fails
     */
    public function processContactpersoon(object $contactpersoonObject, bool $isUpdate = false): bool
    {
        $startTime = microtime(true);
        
        try {
            $contactData = $contactpersoonObject->getObject();
            $contactId = $contactpersoonObject->getId();
            
            $this->logger->info('ContactpersoonService: Starting contactpersoon processing', [
                'contactId' => $contactId,
                'isUpdate' => $isUpdate,
                'hasEmail' => !empty($contactData['email']),
                'hasOrganisation' => !empty($contactData['organisation'])
            ]);

            // Check if contactpersoon has required data
            $email = $contactData['email'] ?? '';
            if (empty($email)) {
                $this->logger->warning('ContactpersoonService: Contactpersoon has no email, skipping processing', [
                    'contactId' => $contactId
                ]);
                return false;
            }

            // Use email as username
            $username = $email;
            
            // Check if user already exists
            $userManager = \OC::$server->get('OCP\IUserManager');
            $user = $userManager->get($username);

            if (!$user) {
                // Check if organization is active before creating user account
                $organizationUuid = $contactData['organisation'] ?? $contactData['organisatie'] ?? '';
                
                if (!empty($organizationUuid)) {
                    try {
                        $organisationMapper = \OC::$server->get('OCA\OpenRegister\Db\OrganisationMapper');
                        $organisationEntity = $organisationMapper->findByUuid($organizationUuid);
                        
                        if ($organisationEntity && $organisationEntity->getActive()) {
                            // Create user account - organization is active
                            $this->logger->info('ContactpersoonService: Creating user account for contactpersoon (org is active)', [
                                'contactId' => $contactId,
                                'username' => $username,
                                'organizationUuid' => $organizationUuid,
                                'organizationActive' => true
                            ]);

                            $success = $this->contactPersonHandler->createUserAccount($contactpersoonObject);
                            if (!$success) {
                                throw new \Exception('Failed to create user account');
                            }

                            // Link user to organization entity
                            $this->contactPersonHandler->addUserToOrganizationEntity($contactpersoonObject, $username);

                            // Update contactpersoon object owner to user UID
                            $this->updateContactpersoonObjectOwner($contactpersoonObject, $username);

                            $this->logger->info('ContactpersoonService: Successfully created user account', [
                                'contactId' => $contactId,
                                'username' => $username
                            ]);
                        } else {
                            $this->logger->info('ContactpersoonService: Skipping user creation - organization not active or not found', [
                                'contactId' => $contactId,
                                'organizationUuid' => $organizationUuid,
                                'organizationFound' => $organisationEntity !== null,
                                'organizationActive' => $organisationEntity ? $organisationEntity->getActive() : false
                            ]);
                            return false;
                        }
                    } catch (\Exception $e) {
                        $this->logger->info('ContactpersoonService: Skipping user creation - organization not found in entity table (not active)', [
                            'contactId' => $contactId,
                            'organizationUuid' => $organizationUuid,
                            'reason' => 'Organization not found in entity table'
                        ]);
                        return false;
                    }
                } else {
                    $this->logger->warning('ContactpersoonService: Contactpersoon has no organization reference, skipping user creation', [
                        'contactId' => $contactId
                    ]);
                    return false;
                }
            } else {
                $this->logger->info('ContactpersoonService: User account already exists', [
                    'contactId' => $contactId,
                    'username' => $username
                ]);
            }

            // Update user groups based on contactpersoon data
            $this->updateUserGroups($contactpersoonObject, $username);

            // Ensure organization has at least one beheerder
            $this->ensureOrganizationBeheerder($contactpersoonObject, $username);

            // Update the contactpersoon object with username if not set
            if (empty($contactData['username'])) {
                $this->updateContactpersoonUsername($contactpersoonObject, $username);
            }

            $processingTime = round((microtime(true) - $startTime) * 1000, 2);
            $this->logger->info('ContactpersoonService: Successfully processed contactpersoon', [
                'contactId' => $contactId,
                'username' => $username,
                'processingTime' => $processingTime . 'ms'
            ]);

            return true;

        } catch (\Exception $e) {
            $this->logger->error('ContactpersoonService: Failed to process contactpersoon object', [
                'exception' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
                'objectId' => $contactpersoonObject->getId() ?? 'unknown',
                'processingTime' => round((microtime(true) - $startTime) * 1000, 2) . 'ms'
            ]);
            throw $e;
        }
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
        $this->groupHandler->updateUserGroups($contactpersoonObject, $username);
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
        $this->hierarchyHandler->ensureOrganizationBeheerder($contactpersoonObject, $username);
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
        return $this->contactPersonHandler->getUserManager($username);
    }

    /**
     * Updates contactpersoon object with username
     *
     * @param object $contactpersoonObject The contactpersoon object
     * @param string $username              The username to set
     * 
     * @return void
     */
    private function updateContactpersoonUsername(object $contactpersoonObject, string $username): void
    {
        try {
            $objectService = $this->getObjectService();
            if (!$objectService) {
                $this->logger->warning('ContactpersoonService: ObjectService not available for username update');
                return;
            }

            $contactData = $contactpersoonObject->getObject();
            $contactData['username'] = $username;

            $updatedObject = $objectService->saveObject(
                $contactData,                           // object data (array)
                [],                                     // extend (array)
                $contactpersoonObject->getRegister(),   // register (int)
                $contactpersoonObject->getSchema(),     // schema (int)
                $contactpersoonObject->getUuid()        // uuid (string)
            );

            $this->logger->info('ContactpersoonService: Updated contactpersoon with username', [
                'contactId' => $contactpersoonObject->getId(),
                'username' => $username
            ]);

        } catch (\Exception $e) {
            $this->logger->error('ContactpersoonService: Failed to update contactpersoon username', [
                'contactId' => $contactpersoonObject->getId(),
                'username' => $username,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Handles contactpersoon updates, particularly role changes
     *
     * @param object      $contactpersoonObject    The updated contactpersoon object
     * @param object|null $oldContactpersoonObject The previous contactpersoon object
     * 
     * @return void
     */
    public function handleContactpersoonUpdate(object $contactpersoonObject, object $oldContactpersoonObject = null): void
    {
        try {
            $contactData = $contactpersoonObject->getObject();
            $contactId = $contactpersoonObject->getId();
            
            $this->logger->info('ContactpersoonService: Handling contactpersoon update', [
                'contactId' => $contactId,
                'hasOldObject' => $oldContactpersoonObject !== null
            ]);

            // Process the contactpersoon (this will handle user creation/updates)
            $this->processContactpersoon($contactpersoonObject, true);

            // If we have old object, check for role changes
            if ($oldContactpersoonObject) {
                $this->handleRoleChanges($contactpersoonObject, $oldContactpersoonObject);
            }

            $this->logger->info('ContactpersoonService: Successfully handled contactpersoon update', [
                'contactId' => $contactId
            ]);

        } catch (\Exception $e) {
            $this->logger->error('ContactpersoonService: Failed to handle contactpersoon update', [
                'contactId' => $contactpersoonObject->getId(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Handles role changes between old and new contactpersoon objects
     *
     * @param object $newContactpersoonObject The new contactpersoon object
     * @param object $oldContactpersoonObject The old contactpersoon object
     * 
     * @return void
     */
    private function handleRoleChanges(object $newContactpersoonObject, object $oldContactpersoonObject): void
    {
        $newData = $newContactpersoonObject->getObject();
        $oldData = $oldContactpersoonObject->getObject();
        
        $newRoles = $newData['roles'] ?? [];
        $oldRoles = $oldData['roles'] ?? [];
        
        // Check if roles have changed
        if ($newRoles !== $oldRoles) {
            $username = $newData['email'] ?? $newData['username'] ?? '';
            if ($username) {
                $this->logger->info('ContactpersoonService: Roles changed, updating user groups', [
                    'contactId' => $newContactpersoonObject->getId(),
                    'username' => $username,
                    'oldRoles' => $oldRoles,
                    'newRoles' => $newRoles
                ]);

                // Update user groups based on new roles
                $this->updateUserGroups($newContactpersoonObject, $username);
            }
        }
    }

    /**
     * Gets the ObjectService instance
     *
     * @return \OCA\OpenRegister\Service\ObjectService|null
     */
    private function getObjectService(): ?\OCA\OpenRegister\Service\ObjectService
    {
        if (!$this->appManager->isEnabledForUser('openregister')) {
            return null;
        }

        try {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Exception $e) {
            $this->logger->error('ContactpersoonService: Failed to get ObjectService: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Handles contact person deletion
     *
     * @param object $contactObject The contact object being deleted
     * 
     * @return void
     */
    public function handleContactDeletion(object $contactObject): void
    {
        try {
            $contactData = $contactObject->getObject();
            $username = $contactData['email'] ?? $contactData['username'] ?? '';
            
            if (!$username) {
                $this->logger->warning('ContactpersoonService: Contact deletion - no username found', [
                    'contactId' => $contactObject->getId()
                ]);
                return;
            }

            $this->logger->info('ContactpersoonService: Handling contact deletion', [
                'contactId' => $contactObject->getId(),
                'username' => $username
            ]);

            // Get user manager to disable the user
            $userManager = \OC::$server->get('OCP\IUserManager');
            $user = $userManager->get($username);
            
            if ($user) {
                // Disable the user instead of deleting
                $user->setEnabled(false);
                
                $this->logger->info('ContactpersoonService: Disabled user for deleted contact', [
                    'contactId' => $contactObject->getId(),
                    'username' => $username
                ]);
            } else {
                $this->logger->warning('ContactpersoonService: User not found for deleted contact', [
                    'contactId' => $contactObject->getId(),
                    'username' => $username
                ]);
            }

        } catch (\Exception $e) {
            $this->logger->error('ContactpersoonService: Failed to handle contact deletion', [
                'contactId' => $contactObject->getId(),
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Gets all contact persons for an organization
     *
     * @param string $organizationUuid The organization UUID
     * 
     * @return array Array of contact person objects
     */
    public function getContactPersonsForOrganization(string $organizationUuid): array
    {
        try {
            $objectService = $this->getObjectService();
            if (!$objectService) {
                return [];
            }

        $voorzieningenConfig = $this->settingsService->getVoorzieningenConfig();
        $contactSchema = $voorzieningenConfig['contactpersoon_schema'] ?? null;
        $register = $voorzieningenConfig['register'] ?? null;
        
        // Skip if no proper configuration is available
        if (!$contactSchema || !$register) {
            $this->logger->warning('ContactpersoonService: Missing Voorzieningen configuration', [
                'contactSchema' => $contactSchema,
                'register' => $register
            ]);
            return [];
        }
            
            return $objectService->findAll(
                ['organisation' => $organizationUuid],
                (int) $register,
                (int) $contactSchema
            );

        } catch (\Exception $e) {
            $this->logger->error('ContactpersoonService: Failed to get contact persons for organization', [
                'organizationUuid' => $organizationUuid,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Updates the contactpersoon object's @self metadata to set owner to the user UID
     *
     * @param object $contactObject The contactpersoon object to update
     * @param string $userUID The user UID to set as owner
     * @return void
     */
    private function updateContactpersoonObjectOwner(object $contactObject, string $userUID): void
    {
        try {
            $contactId = $contactObject->getUuid();
            
            // Get configuration for register and schema
            $voorzieningenConfig = $this->settingsService->getVoorzieningenConfig();
            $register = $voorzieningenConfig['register'] ?? '';
            $contactSchema = $voorzieningenConfig['contactpersoon_schema'] ?? '';
            
            if (empty($register) || empty($contactSchema)) {
                $this->logger->warning('ContactpersoonService: Cannot update object owner - missing configuration', [
                    'contactId' => $contactId,
                    'register' => $register,
                    'contactSchema' => $contactSchema
                ]);
                return;
            }
            
            $this->logger->info('ContactpersoonService: Updating contactpersoon object owner', [
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
            
            // Update the object with the new @self metadata
            $currentObject['@self'] = $selfMetadata;
            $contactObject->setObject($currentObject);
            
            // Save the updated object using ObjectService
            $objectService = \OC::$server->get('OCA\OpenRegister\Service\ObjectService');
            $objectService->saveObject(
                object: $contactObject,
                register: $register,
                schema: $contactSchema,
                rbac: false,
                multi: false
            );
            
            $this->logger->info('ContactpersoonService: Successfully updated contactpersoon object owner', [
                'contactId' => $contactId,
                'userUID' => $userUID,
                'ownerSet' => $selfMetadata['owner']
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('ContactpersoonService: Failed to update contactpersoon object owner', [
                'contactId' => $contactObject->getUuid(),
                'userUID' => $userUID,
                'exception' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
        }
    }
} 