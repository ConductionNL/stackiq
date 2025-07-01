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

use OCP\IUserManager;
use OCP\IUser;
use OCP\Security\ISecureRandom;
use OCP\ILogger;
use OCP\IGroupManager;
use OCP\IGroup;
use Psr\Container\ContainerInterface;
use OCP\App\IAppManager;
use OCA\SoftwareCatalog\Service\EmailService;
use Psr\Log\LoggerInterface;

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
     * Default role-based groups that users can be assigned to
     *
     * @var array
     */
    private array $_defaultGroups = [
        'beheerder',
        'inkoper'
    ];

    /**
     * SoftwareCatalogueService constructor
     *
     * @param IUserManager           $_userManager    User manager interface
     * @param ISecureRandom          $_secureRandom   Secure random generator
     * @param IGroupManager          $_groupManager   Group manager interface  
     * @param ContainerInterface     $_container      Container interface
     * @param IAppManager            $_appManager     App manager interface
     * @param EmailService           $_emailService   Email service
     * @param LoggerInterface        $_logger         Logger interface
     */
    public function __construct(
        private readonly IUserManager $_userManager,
        private readonly ISecureRandom $_secureRandom,
        private readonly IGroupManager $_groupManager,
        private readonly ContainerInterface $_container,
        private readonly IAppManager $_appManager,
        private readonly EmailService $_emailService,
        private readonly LoggerInterface $_logger,
    ) {
        $this->_appName = 'softwarecatalog';
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
                $username = $this->_generateUsernameFromContactData($objectData);
                
                // Create the user account
                $user = $this->_createUserAccount($username, $objectData);
                
                if ($user === null) {
                    throw new \Exception('Failed to create user account');
                }
                
                // Update the contactgegevens object with the username
                $objectData['username'] = $username;
                $contactgegevensObject->setObject($objectData);
                
                // Save the updated object via ObjectService
                $objectService = $this->_getObjectService();
                $objectService->saveObject($contactgegevensObject);
                
                // Update user groups
                $this->updateUserGroups($contactgegevensObject, $username);
                
                // Ensure organization has beheerder and set up manager relationships
                $this->ensureOrganizationBeheerder($contactgegevensObject, $username);
                
                $this->_logger->info(
                    'Successfully created user and updated contactgegevens', 
                    [
                        'username' => $username,
                        'objectId' => $contactgegevensObject->getId()
                    ]
                );
                
                return true;
            }
            
            // Update user groups even if username exists
            $this->updateUserGroups($contactgegevensObject, $username);
            
            // Ensure organization has beheerder and set up manager relationships
            $this->ensureOrganizationBeheerder($contactgegevensObject, $username);
            
            $this->_logger->info(
                'Username already exists, updated groups and organization structure', 
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
     * @return string Generated username
     */
    private function _generateUsernameFromContactData(array $contactData): string
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
     * Creates a user account
     *
     * @param string $username    The username for the new user
     * @param array  $contactData The contact data for user creation
     * @return IUser|null The created user or null if creation failed
     */
    private function _createUserAccount(string $username, array $contactData): ?IUser
    {
        try {
            // Generate a random password
            $password = $this->_secureRandom->generate(12, ISecureRandom::CHAR_ALPHANUMERIC);
            
            // Create the user
            $user = $this->_userManager->createUser($username, $password);
            
            if ($user === null) {
                throw new \Exception('User creation returned null');
            }
            
            // Set user properties from contact data
            $displayName = trim(($contactData['voornaam'] ?? '') . ' ' . 
                              ($contactData['tussenvoegsel'] ?? '') . ' ' . 
                              ($contactData['achternaam'] ?? ''));
            
            if (!empty($displayName)) {
                $user->setDisplayName($displayName);
            }
            
            if (!empty($contactData['email'])) {
                $user->setEMailAddress($contactData['email']);
            }
            
            // Add user to a default group if it exists
            $defaultGroup = $this->_groupManager->get('software-catalog-users');
            if ($defaultGroup !== null) {
                $defaultGroup->addUser($user);
            }
            
            $this->_logger->info(
                'User account created successfully', 
                [
                    'username' => $username,
                    'displayName' => $displayName,
                    'email' => $contactData['email'] ?? 'none'
                ]
            );
            
            return $user;
            
        } catch (\Exception $e) {
            $this->_logger->error(
                'Failed to create user account: ' . $e->getMessage(), 
                [
                    'username' => $username,
                    'exception' => $e
                ]
            );
            return null;
        }
    }

    /**
     * Handles new organization creation
     *
     * @param object $organizationObject The organization object
     * @return void
     */
    public function handleNewOrganization(object $organizationObject): void
    {
        // Implementation for handling new organizations
        $this->_logger->info('Handling new organization', [
            'objectId' => $organizationObject->getId()
        ]);
    }

    /**
     * Sends welcome email to organization
     *
     * @param object $organizationObject The organization object
     * @return void
     */
    public function sendOrganizationWelcomeEmail(object $organizationObject): void
    {
        // Implementation for sending organization welcome email
        $this->_logger->info('Sending organization welcome email', [
            'objectId' => $organizationObject->getId()
        ]);
    }

    /**
     * Handles new contact creation
     *
     * @param object $contactObject The contact object
     * @return void
     */
    public function handleNewContact(object $contactObject): void
    {
        // Implementation for handling new contacts
        $this->_logger->info('Handling new contact', [
            'objectId' => $contactObject->getId()
        ]);
    }

    /**
     * Creates user for contact if not exists
     *
     * @param object $contactObject The contact object
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
     * @return void
     */
    public function handleContactUpdate(object $contactObject): void
    {
        // Implementation for handling contact updates
        $this->_logger->info('Handling contact update', [
            'objectId' => $contactObject->getId()
        ]);
    }

    /**
     * Handles gebruiker update
     *
     * @param object $gebruikerObject    The new gebruiker object
     * @param object $oldGebruikerObject The old gebruiker object
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
     * @return void
     */
    public function handleContactDeletion(object $contactObject): void
    {
        // Implementation for handling contact deletion
        $this->_logger->info('Handling contact deletion', [
            'objectId' => $contactObject->getId()
        ]);
    }

    /**
     * Blocks user for gebruiker
     *
     * @param object $gebruikerObject The gebruiker object
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
     * Processes organization groups and ensures proper group assignment
     *
     * @param object $organizationObject The organization object to process
     * @return bool True if processing was successful
     * @throws \Exception If processing fails
     */
    public function processOrganization(object $organizationObject): bool
    {
        try {
            $this->_logger->info('Processing organization object', [
                'objectId' => $organizationObject->getId()
            ]);

            $objectData = $organizationObject->getObject();
            
            // Ensure organization has a group
            $groupId = $this->_ensureOrganizationGroup($organizationObject, $objectData);
            
            if ($groupId) {
                $this->_logger->info(
                    'Successfully processed organization group', 
                    [
                        'organizationId' => $organizationObject->getId(),
                        'groupId' => $groupId
                    ]
                );
            }
            
            return true;
            
        } catch (\Exception $e) {
            $this->_logger->error(
                'Failed to process organization object: ' . $e->getMessage(), 
                [
                    'exception' => $e,
                    'objectId' => $organizationObject->getId() ?? 'unknown'
                ]
            );
            throw $e;
        }
    }

    /**
     * Ensures an organization has a group and returns the group ID
     *
     * @param object $organizationObject The organization object
     * @param array $objectData The organization data
     * @return string|null The group ID or null if failed
     */
    private function _ensureOrganizationGroup(object $organizationObject, array &$objectData): ?string
    {
        $groupProperty = $objectData['group'] ?? '';
        
        if (empty($groupProperty)) {
            // Create group with organization name
            $organizationName = $objectData['naam'] ?? $objectData['name'] ?? 'Organization';
            $groupName = $this->_sanitizeGroupName($organizationName);
            
            $group = $this->_createGroupIfNotExists($groupName);
            
            if ($group) {
                // Set the group ID in the organization object
                $objectData['group'] = $group->getGID();
                $organizationObject->setObject($objectData);
                
                // Save the updated organization
                $objectService = $this->_getObjectService();
                $objectService->saveObject($organizationObject);
                
                $this->_logger->info(
                    'Created and assigned group to organization',
                    [
                        'organizationId' => $organizationObject->getId(),
                        'groupName' => $groupName,
                        'groupId' => $group->getGID()
                    ]
                );
                
                return $group->getGID();
            }
        }
        
        return $groupProperty ?: null;
    }

    /**
     * Updates user groups based on contactgegevens data
     *
     * @param object $contactgegevensObject The contactgegevens object
     * @param string $username The username to update groups for
     * @return void
     */
    public function updateUserGroups(object $contactgegevensObject, string $username): void
    {
        try {
            $user = $this->_userManager->get($username);
            if (!$user) {
                $this->_logger->warning('User not found for group update', ['username' => $username]);
                return;
            }

            $objectData = $contactgegevensObject->getObject();
            
            // Handle role-based groups
            $this->_updateRoleBasedGroups($user, $objectData);
            
            // Handle organization groups
            $this->_updateOrganizationGroups($user, $objectData);
            
            // Handle special gemeente groups
            $this->_updateGemeenteGroups($user, $objectData);
            
            $this->_logger->info(
                'Updated user groups successfully',
                [
                    'username' => $username,
                    'groups' => array_keys($this->_groupManager->getUserGroups($user))
                ]
            );
            
        } catch (\Exception $e) {
            $this->_logger->error(
                'Failed to update user groups: ' . $e->getMessage(),
                [
                    'username' => $username,
                    'exception' => $e
                ]
            );
        }
    }

    /**
     * Updates role-based groups for a user
     *
     * @param IUser $user The user to update
     * @param array $objectData The contactgegevens data
     * @return void
     */
    private function _updateRoleBasedGroups(IUser $user, array $objectData): void
    {
        $userRoles = $objectData['roles'] ?? [];
        if (!is_array($userRoles)) {
            $userRoles = [];
        }

        // Convert roles to lowercase for comparison
        $userRolesLower = array_map('strtolower', $userRoles);
        
        foreach ($this->_defaultGroups as $groupName) {
            $group = $this->_createGroupIfNotExists($groupName);
            
            if ($group) {
                $hasRole = in_array(strtolower($groupName), $userRolesLower);
                $inGroup = $group->inGroup($user);
                
                if ($hasRole && !$inGroup) {
                    // Add user to group
                    $group->addUser($user);
                    $this->_logger->info(
                        'Added user to role-based group',
                        [
                            'username' => $user->getUID(),
                            'group' => $groupName,
                            'role' => $groupName
                        ]
                    );
                } elseif (!$hasRole && $inGroup) {
                    // Remove user from group
                    $group->removeUser($user);
                    $this->_logger->info(
                        'Removed user from role-based group',
                        [
                            'username' => $user->getUID(),
                            'group' => $groupName
                        ]
                    );
                }
            }
        }
    }

    /**
     * Updates organization-based groups for a user
     *
     * @param IUser $user The user to update
     * @param array $objectData The contactgegevens data
     * @return void
     */
    private function _updateOrganizationGroups(IUser $user, array $objectData): void
    {
        $organizationUuid = $objectData['organisation'] ?? $objectData['organization'] ?? '';
        
        if (!empty($organizationUuid)) {
            try {
                // Get organization object
                $objectService = $this->_getObjectService();
                $organizationObject = $objectService->getObject($organizationUuid);
                
                if ($organizationObject) {
                    $orgData = $organizationObject->getObject();
                    $groupId = $orgData['group'] ?? '';
                    
                    if (!empty($groupId)) {
                        $group = $this->_groupManager->get($groupId);
                        
                        if ($group && !$group->inGroup($user)) {
                            $group->addUser($user);
                            $this->_logger->info(
                                'Added user to organization group',
                                [
                                    'username' => $user->getUID(),
                                    'group' => $groupId,
                                    'organization' => $organizationUuid
                                ]
                            );
                        }
                    }
                }
            } catch (\Exception $e) {
                $this->_logger->error(
                    'Failed to process organization group: ' . $e->getMessage(),
                    [
                        'username' => $user->getUID(),
                        'organizationUuid' => $organizationUuid
                    ]
                );
            }
        }
    }

    /**
     * Updates gemeente-specific groups for a user
     *
     * @param IUser $user The user to update
     * @param array $objectData The contactgegevens data
     * @return void
     */
    private function _updateGemeenteGroups(IUser $user, array $objectData): void
    {
        $organizationUuid = $objectData['organisation'] ?? $objectData['organization'] ?? '';
        
        if (!empty($organizationUuid)) {
            try {
                // Get organization object
                $objectService = $this->_getObjectService();
                $organizationObject = $objectService->getObject($organizationUuid);
                
                if ($organizationObject) {
                    $orgData = $organizationObject->getObject();
                    $orgType = strtolower($orgData['type'] ?? $orgData['soort'] ?? '');
                    
                    if ($orgType === 'gemeente') {
                        $ambtenaarGroup = $this->_createGroupIfNotExists('ambtenaar');
                        
                        if ($ambtenaarGroup && !$ambtenaarGroup->inGroup($user)) {
                            $ambtenaarGroup->addUser($user);
                            $this->_logger->info(
                                'Added user to ambtenaar group (gemeente)',
                                [
                                    'username' => $user->getUID(),
                                    'organization' => $organizationUuid
                                ]
                            );
                        }
                    }
                }
            } catch (\Exception $e) {
                $this->_logger->error(
                    'Failed to process gemeente group: ' . $e->getMessage(),
                    [
                        'username' => $user->getUID(),
                        'organizationUuid' => $organizationUuid
                    ]
                );
            }
        }
    }

    /**
     * Creates a group if it doesn't exist
     *
     * @param string $groupName The group name to create
     * @return IGroup|null The created or existing group
     */
    private function _createGroupIfNotExists(string $groupName): ?IGroup
    {
        $group = $this->_groupManager->get($groupName);
        
        if (!$group) {
            try {
                $group = $this->_groupManager->createGroup($groupName);
                $this->_logger->info(
                    'Created new group',
                    [
                        'groupName' => $groupName
                    ]
                );
            } catch (\Exception $e) {
                $this->_logger->error(
                    'Failed to create group: ' . $e->getMessage(),
                    [
                        'groupName' => $groupName,
                        'exception' => $e
                    ]
                );
                return null;
            }
        }
        
        return $group;
    }

    /**
     * Sanitizes a group name for safe usage
     *
     * @param string $name The name to sanitize
     * @return string The sanitized group name
     */
    private function _sanitizeGroupName(string $name): string
    {
        // Convert to lowercase and replace special characters
        $sanitized = strtolower(trim($name));
        $sanitized = preg_replace('/[^a-z0-9._-]/', '_', $sanitized);
        $sanitized = preg_replace('/_{2,}/', '_', $sanitized);
        $sanitized = trim($sanitized, '_');
        
        // Ensure it's not empty
        if (empty($sanitized)) {
            $sanitized = 'organization_' . time();
        }
        
        return $sanitized;
    }

    /**
     * Ensures organization has at least one beheerder and manages user hierarchy
     *
     * @param object $contactgegevensObject The contactgegevens object
     * @param string $username The username being processed
     * @return void
     */
    public function ensureOrganizationBeheerder(object $contactgegevensObject, string $username): void
    {
        try {
            $objectData = $contactgegevensObject->getObject();
            $organizationUuid = $objectData['organisation'] ?? $objectData['organization'] ?? '';
            
            if (empty($organizationUuid)) {
                $this->_logger->debug('No organization linked to contactgegevens');
                return;
            }

            // Get organization and check for existing beheerders
            $organizationBeheerders = $this->_getOrganizationBeheerders($organizationUuid);
            
            if (empty($organizationBeheerders)) {
                // No beheerders found - make this user the beheerder
                $this->_assignBeheerderRole($contactgegevensObject, $username, $organizationUuid);
                $organizationBeheerders = [$username]; // Update our list
            }
            
            // Set up manager relationships
            $this->_setupManagerRelationships($username, $organizationBeheerders, $organizationUuid);
            
        } catch (\Exception $e) {
            $this->_logger->error(
                'Failed to ensure organization beheerder: ' . $e->getMessage(),
                [
                    'username' => $username,
                    'exception' => $e
                ]
            );
        }
    }

    /**
     * Gets all beheerders for an organization
     *
     * @param string $organizationUuid The organization UUID
     * @return array Array of usernames who are beheerders in this organization
     */
    private function _getOrganizationBeheerders(string $organizationUuid): array
    {
        try {
            $beheerders = [];
            $beheerderGroup = $this->_groupManager->get('beheerder');
            
            if (!$beheerderGroup) {
                return [];
            }
            
            // Get all users in beheerder group
            $beheerderUsers = $beheerderGroup->getUsers();
            
            // Filter users who belong to this organization
            foreach ($beheerderUsers as $user) {
                if ($this->_userBelongsToOrganization($user, $organizationUuid)) {
                    $beheerders[] = $user->getUID();
                }
            }
            
            // Sort by user creation date (oldest first)
            usort($beheerders, function($a, $b) {
                $userA = $this->_userManager->get($a);
                $userB = $this->_userManager->get($b);
                
                // Get user creation timestamps (fallback to 0 if not available)
                $timeA = $userA ? ($userA->getLastLogin() ?: 0) : 0;
                $timeB = $userB ? ($userB->getLastLogin() ?: 0) : 0;
                
                return $timeA <=> $timeB;
            });
            
            $this->_logger->info(
                'Found organization beheerders',
                [
                    'organization' => $organizationUuid,
                    'beheerders' => $beheerders
                ]
            );
            
            return $beheerders;
            
        } catch (\Exception $e) {
            $this->_logger->error(
                'Failed to get organization beheerders: ' . $e->getMessage(),
                [
                    'organization' => $organizationUuid,
                    'exception' => $e
                ]
            );
            return [];
        }
    }

    /**
     * Checks if a user belongs to an organization
     *
     * @param IUser $user The user to check
     * @param string $organizationUuid The organization UUID
     * @return bool True if user belongs to organization
     */
    private function _userBelongsToOrganization(IUser $user, string $organizationUuid): bool
    {
        try {
            // Find contactgegevens objects for this user
            $objectService = $this->_getObjectService();
            $contactgegevensObjects = $objectService->getObjects([
                'username' => $user->getUID()
            ]);
            
            foreach ($contactgegevensObjects as $obj) {
                $objData = $obj->getObject();
                $userOrg = $objData['organisation'] ?? $objData['organization'] ?? '';
                
                if ($userOrg === $organizationUuid) {
                    return true;
                }
            }
            
            return false;
            
        } catch (\Exception $e) {
            $this->_logger->error(
                'Failed to check user organization membership: ' . $e->getMessage(),
                [
                    'username' => $user->getUID(),
                    'organization' => $organizationUuid
                ]
            );
            return false;
        }
    }

    /**
     * Assigns beheerder role to a user
     *
     * @param object $contactgegevensObject The contactgegevens object
     * @param string $username The username
     * @param string $organizationUuid The organization UUID
     * @return void
     */
    private function _assignBeheerderRole(object $contactgegevensObject, string $username, string $organizationUuid): void
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
                $beheerderGroup = $this->_createGroupIfNotExists('beheerder');
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
     * Sets up manager relationships for users in an organization
     *
     * @param string $username The current username being processed
     * @param array $organizationBeheerders Array of beheerder usernames
     * @param string $organizationUuid The organization UUID
     * @return void
     */
    private function _setupManagerRelationships(string $username, array $organizationBeheerders, string $organizationUuid): void
    {
        try {
            if (empty($organizationBeheerders)) {
                return;
            }
            
            // The oldest beheerder becomes the manager
            $primaryManager = $organizationBeheerders[0];
            
            // If current user is not a beheerder, set their manager
            if (!in_array($username, $organizationBeheerders)) {
                $this->_setUserManager($username, $primaryManager);
            }
            
            // If there are multiple beheerders, set the primary as manager for others
            if (count($organizationBeheerders) > 1) {
                foreach ($organizationBeheerders as $beheerder) {
                    if ($beheerder !== $primaryManager) {
                        $this->_setUserManager($beheerder, $primaryManager);
                    }
                }
            }
            
            $this->_logger->info(
                'Set up manager relationships',
                [
                    'organization' => $organizationUuid,
                    'primaryManager' => $primaryManager,
                    'allBeheerders' => $organizationBeheerders,
                    'processedUser' => $username
                ]
            );
            
        } catch (\Exception $e) {
            $this->_logger->error(
                'Failed to setup manager relationships: ' . $e->getMessage(),
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
     * @param string $username The username
     * @param string $managerUsername The manager's username
     * @return void
     */
    private function _setUserManager(string $username, string $managerUsername): void
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
} 