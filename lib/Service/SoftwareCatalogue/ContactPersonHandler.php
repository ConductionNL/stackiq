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
use OCA\SoftwareCatalog\Service\PhpEmailService;

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
     * @param PhpEmailService        $_emailService   Email service
     */
    public function __construct(
        private readonly IUserManager $_userManager,
        private readonly ISecureRandom $_secureRandom,
        private readonly IGroupManager $_groupManager,
        private readonly ContainerInterface $_container,
        private readonly IAppManager $_appManager,
        private readonly LoggerInterface $_logger,
        private readonly PhpEmailService $_emailService,
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
     * Generates a username from contact data with fallback strategies
     *
     * @param array $contactData The contact data array
     * 
     * @return string Generated username
     */
    public function generateUsernameFromContactData(array $contactData): string
    {
        $this->_logger->info(
            'DEBUG: Starting username generation',
            [
                'contactData' => $contactData,
                'contactData_keys' => array_keys($contactData),
                'contactData_count' => count($contactData),
                'voornaam' => $contactData['voornaam'] ?? 'NOT_SET',
                'tussenvoegsel' => $contactData['tussenvoegsel'] ?? 'NOT_SET',
                'achternaam' => $contactData['achternaam'] ?? 'NOT_SET',
                'email' => $contactData['email'] ?? 'NOT_SET'
            ]
        );

        $voornaam = $contactData['voornaam'] ?? '';
        $tussenvoegsel = $contactData['tussenvoegsel'] ?? '';
        $achternaam = $contactData['achternaam'] ?? '';
        $email = $contactData['email'] ?? '';
        
        $this->_logger->info(
            'DEBUG: Extracted field values',
            [
                'voornaam' => $voornaam,
                'voornaam_length' => strlen($voornaam),
                'tussenvoegsel' => $tussenvoegsel,
                'achternaam' => $achternaam,
                'achternaam_length' => strlen($achternaam),
                'email' => $email
            ]
        );

        // Strategy 1: firstname.lastname (with dots)
        if (!empty($voornaam) && !empty($achternaam)) {
            $username = strtolower($voornaam) . '.' . strtolower($achternaam);
            $this->_logger->info('DEBUG: Strategy 1 - firstname.lastname', ['username' => $username]);
            if ($this->isValidUsername($username)) {
                $this->_logger->info('DEBUG: Strategy 1 PASSED validation', ['username' => $username]);
                return $username;
            } else {
                $this->_logger->warning('DEBUG: Strategy 1 FAILED validation', ['username' => $username]);
            }
        } else {
            $this->_logger->warning('DEBUG: Strategy 1 - missing required fields', ['voornaam' => $voornaam, 'achternaam' => $achternaam]);
        }

        // Strategy 2: firstnamelastname (no dots)
        if (!empty($voornaam) && !empty($achternaam)) {
            $username = strtolower($voornaam) . strtolower($achternaam);
            $this->_logger->info('DEBUG: Strategy 2 - firstnamelastname', ['username' => $username]);
            if ($this->isValidUsername($username)) {
                $this->_logger->info('DEBUG: Strategy 2 PASSED validation', ['username' => $username]);
                return $username;
            } else {
                $this->_logger->warning('DEBUG: Strategy 2 FAILED validation', ['username' => $username]);
            }
        } else {
            $this->_logger->warning('DEBUG: Strategy 2 - missing required fields', ['voornaam' => $voornaam, 'achternaam' => $achternaam]);
        }

        // Strategy 3: email prefix (part before @)
        if (!empty($email) && strpos($email, '@') !== false) {
            $username = strtolower(explode('@', $email)[0]);
            $this->_logger->info('DEBUG: Strategy 3 - email prefix', ['username' => $username]);
            if ($this->isValidUsername($username)) {
                $this->_logger->info('DEBUG: Strategy 3 PASSED validation', ['username' => $username]);
                return $username;
            } else {
                $this->_logger->warning('DEBUG: Strategy 3 FAILED validation', ['username' => $username]);
            }
        } else {
            $this->_logger->warning('DEBUG: Strategy 3 - invalid email', ['email' => $email]);
        }

        // Strategy 4: timestamp fallback
        $username = 'user' . time();
        $this->_logger->info('DEBUG: Strategy 4 - timestamp fallback', ['username' => $username]);
        if ($this->isValidUsername($username)) {
            $this->_logger->info('DEBUG: Strategy 4 PASSED validation', ['username' => $username]);
            return $username;
        } else {
            $this->_logger->warning('DEBUG: Strategy 4 FAILED validation', ['username' => $username]);
        }

        // If all strategies fail, log error and return empty string
        $this->_logger->error('DEBUG: All username generation strategies failed', ['contactData' => $contactData]);
        return '';
    }
    
    /**
     * Validates if a username meets Nextcloud requirements
     */
    private function isValidUsername(string $username): bool
    {
        $this->_logger->info('DEBUG: Username validation started', ['username' => $username, 'length' => strlen($username)]);
        
        if (empty($username)) {
            $this->_logger->warning('DEBUG: Username validation failed - empty username');
            return false;
        }
        
        // Basic validation rules (adjust based on your Nextcloud configuration)
        if (strlen($username) < 3 || strlen($username) > 64) {
            $this->_logger->warning('DEBUG: Username validation failed - length check', ['length' => strlen($username)]);
            return false;
        }
        
        // Must start with alphanumeric
        if (!preg_match('/^[a-z0-9]/', $username)) {
            $this->_logger->warning('DEBUG: Username validation failed - must start with alphanumeric', ['first_char' => substr($username, 0, 1)]);
            return false;
        }
        
        // Only allow alphanumeric, dots, underscores, and dashes
        if (!preg_match('/^[a-z0-9._-]+$/', $username)) {
            $this->_logger->warning('DEBUG: Username validation failed - invalid characters', ['pattern' => '/^[a-z0-9._-]+$/']);
            return false;
        }
        
        $this->_logger->info('DEBUG: Username validation passed', ['username' => $username]);
        return true;
    }
    
    /**
     * Ensures username is unique by adding counter if needed
     */
    private function ensureUniqueUsername(string $username): string
    {
        $originalUsername = $username;
        $counter = 1;
        
        while ($this->_userManager->userExists($username)) {
            $username = $originalUsername . $counter;
            $counter++;
            $this->_logger->info('DEBUG: Username exists, trying', ['username' => $username, 'counter' => $counter]);
            
            // Safety check to prevent infinite loop
            if ($counter > 9999) {
                $username = $originalUsername . uniqid();
                break;
            }
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
            
            $this->_logger->info(
                'DEBUG: Starting user account creation',
                [
                    'contactgegevensId' => $contactgegevensObject->getId(),
                    'email' => $email,
                    'objectData' => $objectData
                ]
            );
            
            if (empty($email)) {
                $this->_logger->warning(
                    'Cannot create user account: no email address provided',
                    ['contactgegevensId' => $contactgegevensObject->getId()]
                );
                return null;
            }
            
            // Generate username first to check both email and username existence
            $username = $objectData['username'] ?? '';
            if (empty($username)) {
                $username = $this->generateUsernameFromContactData($objectData);
                $this->_logger->info('DEBUG: Generated username for existence check', ['username' => $username]);
            }
            
            // Check if user already exists by email
            if ($this->_userManager->userExists($email)) {
                $this->_logger->info(
                    'User already exists with email',
                    ['email' => $email, 'contactgegevensId' => $contactgegevensObject->getId()]
                );
                $existingUser = $this->_userManager->get($email);
                if ($existingUser) {
                    // Update groups for existing user
                    $this->assignUserGroups($existingUser, $objectData);
                    return $existingUser;
                }
            }
            
            // Check if user already exists by username
            $existingUserByUsername = $this->_userManager->get($username);
            if ($existingUserByUsername) {
                $this->_logger->info(
                    'User already exists with username',
                    ['username' => $username, 'contactgegevensId' => $contactgegevensObject->getId()]
                );
                // Update groups for existing user
                $this->assignUserGroups($existingUserByUsername, $objectData);
                return $existingUserByUsername;
            }
            
            // Username already generated above for existence checks
            $this->_logger->info(
                'DEBUG: About to create new user',
                [
                    'username' => $username,
                    'username_length' => strlen($username),
                    'contactgegevensId' => $contactgegevensObject->getId()
                ]
            );
            
            $this->_logger->info(
                'DEBUG: About to create user with Nextcloud',
                [
                    'username' => $username,
                    'username_length' => strlen($username),
                    'username_raw_bytes' => bin2hex($username),
                    'password' => $username, // Using username as password
                    'email' => $email,
                    'contactgegevensId' => $contactgegevensObject->getId()
                ]
            );
            
            // Create user account
            $user = $this->_userManager->createUser($username, $username);
            
            if ($user) {
                $this->_logger->info(
                    'DEBUG: User creation successful',
                    [
                        'username' => $username,
                        'userId' => $user->getUID(),
                        'email' => $email,
                        'contactgegevensId' => $contactgegevensObject->getId()
                    ]
                );
                
                // Set user details
                $user->setEMailAddress($email);
                $user->setDisplayName($this->getDisplayNameFromContactData($objectData));
                
                // Set user groups based on roles and organization
                $this->assignUserGroups($user, $objectData);
                
                // Update contactgegevens with username
                $objectData['username'] = $username;
                $contactgegevensObject->setObject($objectData);
                
                // Send user creation email
                $this->sendUserCreationEmail($user, $objectData);
                
                $this->_logger->info(
                    'Created user account for contact person',
                    [
                        'contactgegevensId' => $contactgegevensObject->getId(),
                        'username' => $username,
                        'email' => $email
                    ]
                );
                
                return $user;
            } else {
                $this->_logger->error(
                    'DEBUG: User creation returned null (no exception thrown)',
                    [
                        'username' => $username,
                        'email' => $email,
                        'contactgegevensId' => $contactgegevensObject->getId()
                    ]
                );
            }
            
            return null;
            
        } catch (\Exception $e) {
            $this->_logger->error(
                'Failed to create user account: ' . $e->getMessage(),
                [
                    'contactgegevensId' => $contactgegevensObject->getId(),
                    'exception' => $e,
                    'exception_class' => get_class($e),
                    'exception_code' => $e->getCode(),
                    'trace' => $e->getTraceAsString()
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
            
            $this->_logger->info(
                'DEBUG: Starting user group assignment',
                [
                    'username' => $user->getUID(),
                    'organizationId' => $organizationId,
                    'organizationId_type' => gettype($organizationId),
                    'roles' => $roles,
                    'objectData_keys' => array_keys($objectData)
                ]
            );
            
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
                $this->_logger->info(
                    'DEBUG: Attempting to get organization group',
                    [
                        'username' => $user->getUID(),
                        'organizationId' => $organizationId
                    ]
                );
                
                $organizationGroup = $this->getOrganizationGroup((string)$organizationId);
                
                $this->_logger->info(
                    'DEBUG: Organization group lookup result',
                    [
                        'username' => $user->getUID(),
                        'organizationId' => $organizationId,
                        'organizationGroup_found' => $organizationGroup !== null,
                        'organizationGroup_name' => $organizationGroup ? $organizationGroup->getGID() : 'NULL'
                    ]
                );
                
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
                } elseif ($organizationGroup && $organizationGroup->inGroup($user)) {
                    $this->_logger->info(
                        'DEBUG: User already in organization group',
                        [
                            'username' => $user->getUID(),
                            'organizationId' => $organizationId,
                            'groupName' => $organizationGroup->getGID()
                        ]
                    );
                } elseif (!$organizationGroup) {
                    $this->_logger->warning(
                        'DEBUG: Organization group not found',
                        [
                            'username' => $user->getUID(),
                            'organizationId' => $organizationId
                        ]
                    );
                }
                
                // Check if organization is of type "Gemeente" and add to "ambtenaar" group
                $organizationType = $this->getOrganizationType((string)$organizationId);
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
            } else {
                $this->_logger->warning(
                    'DEBUG: No organization ID provided for group assignment',
                    [
                        'username' => $user->getUID(),
                        'objectData_keys' => array_keys($objectData)
                    ]
                );
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
            $this->_logger->info(
                'DEBUG: getOrganizationGroup called',
                [
                    'organizationId' => $organizationId,
                    'organizationId_length' => strlen($organizationId)
                ]
            );
            
            // Get the organization object to find its group
            $objectService = $this->_getObjectService();
            if (!$objectService) {
                $this->_logger->warning('DEBUG: ObjectService not available');
                return null;
            }
            
            // Use find() method with proper register/schema context
            $organizationObject = $objectService->find($organizationId, [], false, 6, 35);
            $this->_logger->info(
                'DEBUG: Organization object lookup result',
                [
                    'organizationId' => $organizationId,
                    'organizationObject_found' => $organizationObject !== null,
                    'organizationObject_id' => $organizationObject ? $organizationObject->getId() : 'NULL'
                ]
            );
            
            if ($organizationObject) {
                $organizationData = $organizationObject->getObject();
                $groupId = $organizationData['group'] ?? '';
                
                $this->_logger->info(
                    'DEBUG: Organization data and group extraction',
                    [
                        'organizationId' => $organizationId,
                        'groupId' => $groupId,
                        'groupId_empty' => empty($groupId),
                        'organizationData_keys' => array_keys($organizationData)
                    ]
                );
                
                if (!empty($groupId)) {
                    $group = $this->_groupManager->get($groupId);
                    $this->_logger->info(
                        'DEBUG: Group manager lookup result',
                        [
                            'organizationId' => $organizationId,
                            'groupId' => $groupId,
                            'group_found' => $group !== null,
                            'group_gid' => $group ? $group->getGID() : 'NULL'
                        ]
                    );
                    return $group;
                } else {
                    $this->_logger->warning(
                        'DEBUG: No group ID found in organization data',
                        [
                            'organizationId' => $organizationId,
                            'organizationData_keys' => array_keys($organizationData)
                        ]
                    );
                }
            } else {
                $this->_logger->warning(
                    'DEBUG: Organization object not found',
                    [
                        'organizationId' => $organizationId
                    ]
                );
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
                    
                    // Send account suspension notification email
                    $this->sendAccountSuspensionEmail($user, $objectData);
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
            $organizationObject = $objectService->find($organizationId, [], false, 6, 35);
            
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

    /**
     * Sends user creation email
     *
     * @param \OCP\IUser $user       The created user
     * @param array      $objectData The contact person data
     * 
     * @return void
     */
    private function sendUserCreationEmail(\OCP\IUser $user, array $objectData): void
    {
        try {
            $this->_logger->info('Sending user creation email', [
                'username' => $user->getUID(),
                'email' => $user->getEMailAddress()
            ]);
            
            // Prepare user data for email
            $userData = [
                'username' => $user->getUID(),
                'email' => $user->getEMailAddress(),
                'displayName' => $user->getDisplayName(),
                'voornaam' => $objectData['voornaam'] ?? '',
                'achternaam' => $objectData['achternaam'] ?? '',
                'roles' => $objectData['roles'] ?? []
            ];
            
            // Get organization data if available
            $organizationData = [];
            $organizationId = $objectData['organisation'] ?? '';
            if (!empty($organizationId)) {
                try {
                    $objectService = $this->_getObjectService();
                    $organizationObject = $objectService->find($organizationId, [], false, 6, 35);
                    if ($organizationObject) {
                        $organizationData = $organizationObject->getObject();
                    }
                } catch (\Exception $e) {
                    $this->_logger->warning('Failed to get organization data for email: ' . $e->getMessage(), [
                        'organizationId' => $organizationId
                    ]);
                }
            }
            
            // Send user creation email
            $success = $this->_emailService->sendUserCreationEmail($userData, $organizationData);
            
            if ($success) {
                $this->_logger->info('User creation email sent successfully', [
                    'username' => $user->getUID(),
                    'email' => $user->getEMailAddress()
                ]);
            } else {
                $this->_logger->warning('Failed to send user creation email', [
                    'username' => $user->getUID(),
                    'email' => $user->getEMailAddress()
                ]);
            }
            
                 } catch (\Exception $e) {
             $this->_logger->error('Exception sending user creation email: ' . $e->getMessage(), [
                 'username' => $user->getUID(),
                 'email' => $user->getEMailAddress(),
                 'exception' => $e
             ]);
         }
     }

    /**
     * Sends account suspension notification email
     *
     * @param \OCP\IUser $user       The suspended user
     * @param array      $objectData The contact person data
     * 
     * @return void
     */
    private function sendAccountSuspensionEmail(\OCP\IUser $user, array $objectData): void
    {
        try {
            $this->_logger->info('Sending account suspension email', [
                'username' => $user->getUID(),
                'email' => $user->getEMailAddress()
            ]);
            
            // For now, we'll use a simple log message as the PhpEmailService 
            // doesn't have a specific suspension email method yet
            // This can be extended later if needed
            
            $this->_logger->info('Account suspension email would be sent here', [
                'username' => $user->getUID(),
                'email' => $user->getEMailAddress(),
                'displayName' => $user->getDisplayName()
            ]);
            
        } catch (\Exception $e) {
            $this->_logger->error('Exception sending account suspension email: ' . $e->getMessage(), [
                'username' => $user->getUID(),
                'email' => $user->getEMailAddress(),
                'exception' => $e
            ]);
        }
    }
}  