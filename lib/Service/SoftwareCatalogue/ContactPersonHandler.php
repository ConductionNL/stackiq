<?php

/**
 * Contact Person Handler for Software Catalog
 *
 * This handler manages contact person-specific operations including user creation,
 * contact processing, and organizational hierarchy management.
 *
 * @category Handler
 * @package  OCA\SoftwareCatalog\Service\SoftwareCatalogue
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @version  1.0.0
 * @link     https://github.com/ConductionNL/SoftwareCatalog
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Service\SoftwareCatalogue;

use OCP\IUserManager;
use OCP\IUser;
use OCP\Security\ISecureRandom;
use OCP\IGroupManager;
use OCP\IGroup;
use Psr\Container\ContainerInterface;
use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;

/**
 * Handler for contact person-related operations
 *
 * @category Handler
 * @package  OCA\SoftwareCatalog\Service\SoftwareCatalogue
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @version  1.0.0
 * @link     https://github.com/ConductionNL/SoftwareCatalog
 */
class ContactPersonHandler
{
    /**
     * ContactPersonHandler constructor
     *
     * @param IUserManager           $_userManager    User manager interface
     * @param ISecureRandom          $_secureRandom   Secure random generator
     * @param IGroupManager          $_groupManager   Group manager interface
     * @param ContainerInterface     $_container      Container interface
     * @param IAppManager            $_appManager     App manager interface
     * @param LoggerInterface        $_logger         Logger interface
     */
    public function __construct(
        private readonly IUserManager $_userManager,
        private readonly ISecureRandom $_secureRandom,
        private readonly IGroupManager $_groupManager,
        private readonly ContainerInterface $_container,
        private readonly IAppManager $_appManager,
        private readonly LoggerInterface $_logger,
    ) {
    }

    /**
     * Gets the OpenRegister ObjectService if available
     *
     * @return \OCA\OpenRegister\Service\ObjectService|null ObjectService instance or null
     * @throws \RuntimeException If service is not available
     */
    private function _getObjectService(): ?\OCA\OpenRegister\Service\ObjectService
    {
        if (in_array('openregister', $this->_appManager->getInstalledApps())) {
            return $this->_container->get('OCA\OpenRegister\Service\ObjectService');
        }

        throw new \RuntimeException('OpenRegister service is not available.');
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

            // Get object data
            $objectData = $contactgegevensObject->getObject();
            
            // Check if username exists and is filled
            $username = $objectData['username'] ?? '';
            
            if (empty($username)) {
                $this->_logger->info('Username not found or empty, creating user account');
                
                // Generate username from name fields
                $username = $this->generateUsernameFromContactData($objectData);
                
                // Create the user account
                $user = $this->createUserAccount($contactgegevensObject);
                
                if ($user === null) {
                    throw new \Exception('Failed to create user account');
                }
                
                // Update the contactgegevens object with the username
                $objectData['username'] = $username;
                $contactgegevensObject->setObject($objectData);
                
                // Save the updated object via ObjectService
                $objectService = $this->_getObjectService();
                $objectService->saveObject($contactgegevensObject);
                
                $this->_logger->info(
                    'Successfully created user and updated contactgegevens', 
                    [
                        'username' => $username,
                        'objectId' => $contactgegevensObject->getId()
                    ]
                );
                
                return true;
            }
            
            $this->_logger->info(
                'Username already exists, contactgegevens processed', 
                [
                    'username' => $username,
                    'objectId' => $contactgegevensObject->getId()
                ]
            );
            
            return true;
            
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
     * Generates a username from contact data
     *
     * @param array $contactData The contact data array
     * 
     * @return string Generated username
     */
    public function generateUsernameFromContactData(array $contactData): string
    {
        $voornaam = $contactData['voornaam'] ?? '';
        $tussenvoegsel = $contactData['tussenvoegsel'] ?? '';
        $achternaam = $contactData['achternaam'] ?? '';
        
        // Start with first name
        $username = strtolower(trim($voornaam));
        
        // Add tussenvoegsel if present
        if (!empty($tussenvoegsel)) {
            $username .= '.' . strtolower(trim($tussenvoegsel));
        }
        
        // Add last name
        if (!empty($achternaam)) {
            $username .= '.' . strtolower(trim($achternaam));
        }
        
        // Clean up username (remove special characters, spaces)
        $username = preg_replace('/[^a-z0-9._-]/', '', $username);
        $username = preg_replace('/\.+/', '.', $username); // Remove duplicate dots
        $username = trim($username, '.');
        
        // Ensure username is not empty
        if (empty($username)) {
            $username = 'user' . time();
        }
        
        // Ensure username is unique
        $originalUsername = $username;
        $counter = 1;
        while ($this->_userManager->userExists($username)) {
            $username = $originalUsername . $counter;
            $counter++;
        }
        
        return $username;
    }

    /**
     * Creates a user account for a contact person
     *
     * @param object $contactgegevensObject The contact person object
     * 
     * @return \OCP\IUser|null The created user or null if failed
     */
    public function createUserAccount(object $contactgegevensObject): ?\OCP\IUser
    {
        try {
            $objectData = $contactgegevensObject->getObject();
            $email = $objectData['email'] ?? '';
            
            if (empty($email)) {
                $this->_logger->warning(
                    'Cannot create user account: no email address provided',
                    ['contactgegevensId' => $contactgegevensObject->getId()]
                );
                return null;
            }
            
            // Check if user already exists
            if ($this->_userManager->userExists($email)) {
                $this->_logger->info(
                    'User already exists with email',
                    ['email' => $email, 'contactgegevensId' => $contactgegevensObject->getId()]
                );
                return $this->_userManager->get($email);
            }
            
            // Generate username if not provided
            $username = $objectData['username'] ?? $this->generateUsernameFromContactData($objectData);
            
            // Create user account
            $user = $this->_userManager->createUser($username, $username);
            
            if ($user) {
                // Set user details
                $user->setEMailAddress($email);
                $user->setDisplayName($this->getDisplayNameFromContactData($objectData));
                
                // Set user groups based on roles and organization
                $this->assignUserGroups($user, $objectData);
                
                // Update contactgegevens with username
                $objectData['username'] = $username;
                $contactgegevensObject->setObject($objectData);
                
                $this->_logger->info(
                    'Created user account for contact person',
                    [
                        'contactgegevensId' => $contactgegevensObject->getId(),
                        'username' => $username,
                        'email' => $email
                    ]
                );
                
                return $user;
            }
            
            return null;
            
        } catch (\Exception $e) {
            $this->_logger->error(
                'Failed to create user account: ' . $e->getMessage(),
                [
                    'contactgegevensId' => $contactgegevensObject->getId(),
                    'exception' => $e
                ]
            );
            return null;
        }
    }

    /**
     * Assigns user groups based on roles and organization
     *
     * @param \OCP\IUser $user       The user to assign groups to
     * @param array      $objectData The contact person data
     * 
     * @return void
     */
    private function assignUserGroups(\OCP\IUser $user, array $objectData): void
    {
        try {
            $roles = $objectData['roles'] ?? [];
            $organizationId = $objectData['organisation'] ?? '';
            
            // Ensure roles is an array
            if (!is_array($roles)) {
                $roles = [$roles];
            }
            
            // Get list of allowed groups (roles that can be mapped to groups)
            $allowedGroups = $this->getAllowedRoleGroups();
            
            // Add user to role-based groups
            foreach ($roles as $role) {
                if (in_array($role, array_keys($allowedGroups))) {
                    $groupName = $allowedGroups[$role];
                    $this->addUserToGroup($user, $groupName, 'role-based');
                }
            }
            
            // Add user to organization group if available
            if (!empty($organizationId)) {
                $organizationGroup = $this->getOrganizationGroup($organizationId);
                if ($organizationGroup && !$organizationGroup->inGroup($user)) {
                    $organizationGroup->addUser($user);
                    $this->_logger->info(
                        'Added user to organization group',
                        [
                            'username' => $user->getUID(),
                            'organizationId' => $organizationId,
                            'groupName' => $organizationGroup->getGID()
                        ]
                    );
                }
                
                // Check if organization is of type "Gemeente" and add to "ambtenaar" group
                $organizationType = $this->getOrganizationType($organizationId);
                if (strtolower($organizationType) === 'gemeente') {
                    $this->addUserToGroup($user, 'ambtenaar', 'gemeente-organization');
                    $this->_logger->info(
                        'Added user to ambtenaar group due to Gemeente organization type',
                        [
                            'username' => $user->getUID(),
                            'organizationId' => $organizationId,
                            'organizationType' => $organizationType
                        ]
                    );
                }
            }
            
            // Always add organization contacts to "Organisaties-beheerder" group
            $this->addUserToGroup($user, 'organisaties-beheerder', 'organization-contact');
            
            // Always add to default software catalog users group
            $this->addUserToGroup($user, 'software-catalog-users', 'default');
            
        } catch (\Exception $e) {
            $this->_logger->error(
                'Failed to assign user groups: ' . $e->getMessage(),
                [
                    'username' => $user->getUID(),
                    'exception' => $e
                ]
            );
        }
    }

    /**
     * Gets the mapping of allowed roles to group names
     *
     * @return array Array mapping role names to group names
     */
    private function getAllowedRoleGroups(): array
    {
        return [
            'Aanbod-beheerder' => 'aanbod-beheerder',
            'Gebruik-beheerder' => 'gebruik-beheerder',
            'Gebruik-raadpleger' => 'gebruik-raadpleger',
            'Functioneel-beheerder' => 'functioneel-beheerder',
            'VNG-raadpleger' => 'vng-raadpleger',
            'Organisatie-beheerder' => 'organisatie-beheerder',
            'Ambtenaar' => 'ambtenaar'
        ];
    }

    /**
     * Adds a user to a group, creating the group if it doesn't exist
     *
     * @param \OCP\IUser $user      The user to add
     * @param string     $groupName The group name
     * @param string     $type      The type of group assignment (for logging)
     * 
     * @return void
     */
    private function addUserToGroup(\OCP\IUser $user, string $groupName, string $type): void
    {
        try {
            $group = $this->_groupManager->get($groupName);
            if (!$group) {
                $group = $this->_groupManager->createGroup($groupName);
                if ($group) {
                    $this->_logger->info(
                        'Created group for user assignment',
                        ['groupName' => $groupName, 'type' => $type]
                    );
                }
            }
            
            if ($group && !$group->inGroup($user)) {
                $group->addUser($user);
                $this->_logger->info(
                    'Added user to group',
                    [
                        'username' => $user->getUID(),
                        'groupName' => $groupName,
                        'type' => $type
                    ]
                );
            }
        } catch (\Exception $e) {
            $this->_logger->error(
                'Failed to add user to group: ' . $e->getMessage(),
                [
                    'username' => $user->getUID(),
                    'groupName' => $groupName,
                    'type' => $type,
                    'exception' => $e
                ]
            );
        }
    }

    /**
     * Updates user groups when roles change (handles role removal)
     *
     * @param \OCP\IUser $user        The user to update
     * @param array      $newRoles    The new roles
     * @param array      $oldRoles    The old roles (optional)
     * 
     * @return void
     */
    public function updateUserGroupsFromRoles(\OCP\IUser $user, array $newRoles, array $oldRoles = []): void
    {
        try {
            $allowedGroups = $this->getAllowedRoleGroups();
            
            // Remove user from groups for roles they no longer have
            if (!empty($oldRoles)) {
                $removedRoles = array_diff($oldRoles, $newRoles);
                foreach ($removedRoles as $removedRole) {
                    if (in_array($removedRole, array_keys($allowedGroups))) {
                        $groupName = $allowedGroups[$removedRole];
                        $group = $this->_groupManager->get($groupName);
                        if ($group && $group->inGroup($user)) {
                            $group->removeUser($user);
                            $this->_logger->info(
                                'Removed user from group after role removal',
                                [
                                    'username' => $user->getUID(),
                                    'groupName' => $groupName,
                                    'removedRole' => $removedRole
                                ]
                            );
                        }
                    }
                }
            }
            
            // Add user to groups for new roles
            foreach ($newRoles as $role) {
                if (in_array($role, array_keys($allowedGroups))) {
                    $groupName = $allowedGroups[$role];
                    $this->addUserToGroup($user, $groupName, 'role-update');
                }
            }
            
            // Ensure organization type-based groups are preserved
            // (e.g., "ambtenaar" for Gemeente organizations)
            $this->ensureOrganizationTypeGroups($user);
            
        } catch (\Exception $e) {
            $this->_logger->error(
                'Failed to update user groups from roles: ' . $e->getMessage(),
                [
                    'username' => $user->getUID(),
                    'exception' => $e
                ]
            );
        }
    }

    /**
     * Ensures organization type-based groups are assigned to the user
     *
     * @param \OCP\IUser $user The user to check and update
     * 
     * @return void
     */
    private function ensureOrganizationTypeGroups(\OCP\IUser $user): void
    {
        try {
            // Find the user's organization by looking for their contactgegevens
            $objectService = $this->_getObjectService();
            $contactgegevens = $this->findContactgegevensByUsername($user->getUID());
            
            if ($contactgegevens) {
                $contactData = $contactgegevens->getObject();
                $organizationId = $contactData['organisation'] ?? '';
                
                if (!empty($organizationId)) {
                    $organizationType = $this->getOrganizationType($organizationId);
                    
                    // If organization is Gemeente, ensure user is in ambtenaar group
                    if (strtolower($organizationType) === 'gemeente') {
                        $this->addUserToGroup($user, 'ambtenaar', 'gemeente-organization-preserve');
                    }
                }
            }
            
        } catch (\Exception $e) {
            $this->_logger->error(
                'Failed to ensure organization type groups: ' . $e->getMessage(),
                [
                    'username' => $user->getUID(),
                    'exception' => $e
                ]
            );
        }
    }

    /**
     * Finds contactgegevens object by username
     *
     * @param string $username The username to search for
     * 
     * @return object|null The contactgegevens object or null if not found
     */
    private function findContactgegevensByUsername(string $username): ?object
    {
        try {
            $objectService = $this->_getObjectService();
            
            // Search for contactgegevens with the given username
            $searchFilters = [
                'username' => $username
            ];
            
            $results = $objectService->findObjects('contactgegevens', $searchFilters);
            
            if (!empty($results)) {
                return $results[0]; // Return the first match
            }
            
            return null;
            
        } catch (\Exception $e) {
            $this->_logger->error(
                'Failed to find contactgegevens by username: ' . $e->getMessage(),
                [
                    'username' => $username,
                    'exception' => $e
                ]
            );
            return null;
        }
    }

    /**
     * Gets the organization group for a given organization ID
     *
     * @param string $organizationId The organization ID
     * 
     * @return \OCP\IGroup|null The organization group or null if not found
     */
    private function getOrganizationGroup(string $organizationId): ?\OCP\IGroup
    {
        try {
            // Get the organization object to find its group
            $objectService = $this->_getObjectService();
            $organizationObject = $objectService->getObject($organizationId);
            
            if ($organizationObject) {
                $organizationData = $organizationObject->getObject();
                $groupId = $organizationData['group'] ?? '';
                
                if (!empty($groupId)) {
                    return $this->_groupManager->get($groupId);
                }
            }
            
            return null;
            
        } catch (\Exception $e) {
            $this->_logger->error(
                'Failed to get organization group: ' . $e->getMessage(),
                [
                    'organizationId' => $organizationId,
                    'exception' => $e
                ]
            );
            return null;
        }
    }

    /**
     * Gets a display name from contact data
     *
     * @param array $contactData The contact data
     * 
     * @return string The display name
     */
    private function getDisplayNameFromContactData(array $contactData): string
    {
        $parts = array_filter([
            $contactData['voornaam'] ?? '',
            $contactData['tussenvoegsel'] ?? '',
            $contactData['achternaam'] ?? ''
        ]);
        
        return implode(' ', $parts) ?: ($contactData['email'] ?? 'Unknown User');
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
        try {
            $this->_logger->info('Handling new contact', [
                'objectId' => $contactObject->getId()
            ]);

            // Process the contact to ensure proper user structure
            $this->processContactgegevens($contactObject);

        } catch (\Exception $e) {
            $this->_logger->error(
                'Failed to handle new contact: ' . $e->getMessage(),
                [
                    'objectId' => $contactObject->getId(),
                    'exception' => $e
                ]
            );
        }
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
        try {
            $this->_logger->info('Handling contact update', [
                'objectId' => $contactObject->getId()
            ]);

            // Process the updated contact
            $this->processContactgegevens($contactObject);

        } catch (\Exception $e) {
            $this->_logger->error(
                'Failed to handle contact update: ' . $e->getMessage(),
                [
                    'objectId' => $contactObject->getId(),
                    'exception' => $e
                ]
            );
        }
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
        try {
            $this->_logger->info('Handling contact deletion', [
                'objectId' => $contactObject->getId()
            ]);

            // Get the contact data before deletion
            $objectData = $contactObject->getObject();
            $username = $objectData['username'] ?? '';

            if (!empty($username)) {
                $user = $this->_userManager->get($username);
                if ($user) {
                    // Option 1: Delete the user account
                    // $user->delete();
                    
                    // Option 2: Just disable the user
                    $user->setEnabled(false);
                    
                    $this->_logger->info(
                        'User account disabled due to contact deletion',
                        [
                            'username' => $username,
                            'contactId' => $contactObject->getId()
                        ]
                    );
                }
            }

        } catch (\Exception $e) {
            $this->_logger->error(
                'Failed to handle contact deletion: ' . $e->getMessage(),
                [
                    'objectId' => $contactObject->getId(),
                    'exception' => $e
                ]
            );
        }
    }

    /**
     * Assigns beheerder role to a user
     *
     * @param object $contactgegevensObject The contactgegevens object
     * @param string $username              The username
     * @param string $organizationUuid      The organization UUID
     * 
     * @return void
     */
    public function assignBeheerderRole(object $contactgegevensObject, string $username, string $organizationUuid): void
    {
        try {
            $objectData = $contactgegevensObject->getObject();
            $currentRoles = $objectData['roles'] ?? [];
            
            if (!is_array($currentRoles)) {
                $currentRoles = [];
            }
            
            // Add beheerder role if not already present
            if (!in_array('beheerder', array_map('strtolower', $currentRoles))) {
                $currentRoles[] = 'beheerder';
                
                // Update the contactgegevens object
                $objectData['roles'] = $currentRoles;
                $contactgegevensObject->setObject($objectData);
                
                // Save the updated object
                $objectService = $this->_getObjectService();
                $objectService->saveObject($contactgegevensObject);
                
                // Add user to beheerder group
                $beheerderGroup = $this->_groupManager->get('beheerder');
                if (!$beheerderGroup) {
                    $beheerderGroup = $this->_groupManager->createGroup('beheerder');
                }
                
                if ($beheerderGroup) {
                    $user = $this->_userManager->get($username);
                    if ($user && !$beheerderGroup->inGroup($user)) {
                        $beheerderGroup->addUser($user);
                    }
                }
                
                $this->_logger->info(
                    'Assigned beheerder role to first user in organization',
                    [
                        'username' => $username,
                        'organization' => $organizationUuid,
                        'newRoles' => $currentRoles
                    ]
                );
            }
            
        } catch (\Exception $e) {
            $this->_logger->error(
                'Failed to assign beheerder role: ' . $e->getMessage(),
                [
                    'username' => $username,
                    'organization' => $organizationUuid,
                    'exception' => $e
                ]
            );
        }
    }

    /**
     * Sets a user's manager in Nextcloud
     *
     * @param string $username        The username
     * @param string $managerUsername The manager's username
     * 
     * @return void
     */
    public function setUserManager(string $username, string $managerUsername): void
    {
        try {
            $user = $this->_userManager->get($username);
            $manager = $this->_userManager->get($managerUsername);
            
            if (!$user || !$manager) {
                $this->_logger->warning(
                    'Cannot set manager - user or manager not found',
                    [
                        'username' => $username,
                        'manager' => $managerUsername,
                        'userExists' => $user !== null,
                        'managerExists' => $manager !== null
                    ]
                );
                return;
            }
            
            // In Nextcloud, we can set this as a user preference or custom attribute
            // Since there's no built-in manager field, we'll use preferences
            \OC::$server->getConfig()->setUserValue(
                $username,
                'softwarecatalog',
                'manager',
                $managerUsername
            );
            
            $this->_logger->info(
                'Set user manager',
                [
                    'username' => $username,
                    'manager' => $managerUsername
                ]
            );
            
        } catch (\Exception $e) {
            $this->_logger->error(
                'Failed to set user manager: ' . $e->getMessage(),
                [
                    'username' => $username,
                    'manager' => $managerUsername,
                    'exception' => $e
                ]
            );
        }
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
        try {
            $manager = \OC::$server->getConfig()->getUserValue(
                $username,
                'softwarecatalog',
                'manager',
                ''
            );
            
            return !empty($manager) ? $manager : null;
            
        } catch (\Exception $e) {
            $this->_logger->error(
                'Failed to get user manager: ' . $e->getMessage(),
                [
                    'username' => $username,
                    'exception' => $e
                ]
            );
            return null;
        }
    }

    /**
     * Gets the organization type for a given organization ID
     *
     * @param string $organizationId The organization ID
     * 
     * @return string The organization type or empty string if not found
     */
    private function getOrganizationType(string $organizationId): string
    {
        try {
            // Get the organization object to find its type
            $objectService = $this->_getObjectService();
            $organizationObject = $objectService->getObject($organizationId);
            
            if ($organizationObject) {
                $organizationData = $organizationObject->getObject();
                return $organizationData['type'] ?? '';
            }
            
            return '';
            
        } catch (\Exception $e) {
            $this->_logger->error(
                'Failed to get organization type: ' . $e->getMessage(),
                [
                    'organizationId' => $organizationId,
                    'exception' => $e
                ]
            );
            return '';
        }
    }
} 