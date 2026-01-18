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

use OCP\IConfig;
use OCP\IUserManager;
use OCP\IUser;
use OCP\Security\ISecureRandom;
use OCP\IGroupManager;
use OCP\IGroup;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;
use OCA\SoftwareCatalog\Service\SymfonyEmailService;

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
     * @param IUserManager $_userManager User manager interface
     * @param ISecureRandom $_secureRandom Secure random generator
     * @param IGroupManager $_groupManager Group manager interface
     * @param IAppConfig $_config Config interface
     * @param ContainerInterface $_container Container interface
     * @param IAppManager $_appManager App manager interface
     * @param LoggerInterface $_logger Logger interface
     * @param SymfonyEmailService $_emailService Email service
     */
    public function __construct(
        private readonly IUserManager        $_userManager,
        private readonly ISecureRandom       $_secureRandom,
        private readonly IGroupManager       $_groupManager,
        private readonly IAppConfig          $_config,
        private readonly ContainerInterface  $_container,
        private readonly IAppManager         $_appManager,
        private readonly LoggerInterface     $_logger,
        private readonly SymfonyEmailService $_emailService,
        private readonly IConfig             $config,
    )
    {
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
     * Generates a username from contact data with fallback strategies
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
        $email = $contactData['email'] ?? $contactData['e-mailadres'] ?? '';


        // Strategy 1: full email address (PRIORITY)
        if (!empty($email) && strpos($email, '@') !== false) {
            $username = strtolower($email);
            if ($this->isValidUsername($username)) {
                return $username;
            }
        }

        // Strategy 2: firstname.lastname (fallback)
        if (!empty($voornaam) && !empty($achternaam)) {
            $username = strtolower($voornaam) . '.' . strtolower($achternaam);
            if ($this->isValidUsername($username)) {
                return $username;
            }
        }

        // Strategy 3: firstnamelastname (fallback)
        if (!empty($voornaam) && !empty($achternaam)) {
            $username = strtolower($voornaam) . strtolower($achternaam);
            if ($this->isValidUsername($username)) {
                return $username;
            }
        }

        // Strategy 4: timestamp fallback
        $username = 'user' . time();
        if ($this->isValidUsername($username)) {
            return $username;
        }

        // If all strategies fail, log error and return empty string
        $this->_logger->error('All username generation strategies failed', ['contactData' => $contactData]);
        return '';
    }

    /**
     * Validates if a username meets Nextcloud requirements
     */
    private function isValidUsername(string $username): bool
    {
        if (empty($username)) {
            return false;
        }

        // Basic validation rules (adjust based on your Nextcloud configuration)
        if (strlen($username) < 3 || strlen($username) > 64) {
            return false;
        }

        // Must start with alphanumeric
        if (!preg_match('/^[a-z0-9]/', $username)) {
            return false;
        }

        // Only allow alphanumeric, dots, underscores, dashes, and @ symbol (for email addresses)
        if (!preg_match('/^[a-z0-9._@-]+$/', $username)) {
            return false;
        }

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
     * @param object $contactpersoonObject The contact person object
     *
     * @return \OCP\IUser|null The created user or null if failed
     */
    public function createUserAccount(object $contactpersoonObject, bool $isFirstContact = false): ?\OCP\IUser
    {
        $startTime = microtime(true);
        
        try {
            $objectData = $contactpersoonObject->getObject();
            $contactId = $contactpersoonObject->getId();
            $email = $objectData['email'] ?? $objectData['e-mailadres'] ?? '';
            $organizationUuid = $objectData['organisation'] ?? $objectData['organisatie'] ?? '';

            $this->_logger->critical('🔥 USER ACCOUNT CREATION STARTED', [
                'app' => 'softwarecatalog',
                'contactId' => $contactId,
                'email' => $email,
                'organizationUuid' => $organizationUuid,
                'isFirstContact' => $isFirstContact,
                'timestamp' => date('Y-m-d H:i:s'),
                'microtime' => microtime(true)
            ]);

            if (empty($email)) {
                $this->_logger->error('❌ USER CREATION FAILED - NO EMAIL', [
                    'app' => 'softwarecatalog',
                    'contactpersoonId' => $contactId
                ]);
                return null;
            }

            // Generate username first to check both email and username existence
            $username = $objectData['username'] ?? '';
            if (empty($username)) {
                $this->_logger->info('[USER] Step 1: Generating username', [
                    'contactId' => $contactId,
                    'email' => $email
                ]);
                $username = $this->generateUsernameFromContactData($objectData);
                $this->_logger->critical('📝 USERNAME GENERATED', [
                    'app' => 'softwarecatalog',
                    'contactId' => $contactId,
                    'generatedUsername' => $username,
                    'email' => $email
                ]);
            }

            // Check if user already exists by email
            $this->_logger->info('[USER] Step 2: Checking existing user by email', [
                'email' => $email
            ]);
            if ($this->_userManager->userExists($email)) {
                $this->_logger->critical('♻️ USER EXISTS BY EMAIL', [
                    'app' => 'softwarecatalog',
                    'email' => $email,
                    'contactpersoonId' => $contactId
                ]);
                
                $existingUser = $this->_userManager->get($email);
                if ($existingUser) {
                    // Store organization UUID for existing user
                    if (!empty($organizationUuid)) {
                        $this->storeUserOrganizationUuid($existingUser, $organizationUuid);
                    }

                    // Update groups for existing user
                    $this->assignUserGroups($existingUser, $objectData, $isFirstContact);
                    
                    $this->_logger->critical('✅ EXISTING USER UPDATED', [
                        'app' => 'softwarecatalog',
                        'username' => $existingUser->getUID(),
                        'email' => $email,
                        'organizationUuid' => $organizationUuid
                    ]);
                    
                    return $existingUser;
                }
            }

            // Check if user already exists by username
            $this->_logger->info('[USER] Step 3: Checking existing user by username', [
                'username' => $username
            ]);
            $existingUserByUsername = $this->_userManager->get($username);
            if ($existingUserByUsername) {
                $this->_logger->critical('♻️ USER EXISTS BY USERNAME', [
                    'app' => 'softwarecatalog',
                    'username' => $username,
                    'contactpersoonId' => $contactId
                ]);

                // Store organization UUID for existing user
                if (!empty($organizationUuid)) {
                    $this->storeUserOrganizationUuid($existingUserByUsername, $organizationUuid);
                }

                // Update groups for existing user
                $this->assignUserGroups($existingUserByUsername, $objectData, $isFirstContact);
                
                $this->_logger->critical('✅ EXISTING USER UPDATED BY USERNAME', [
                    'app' => 'softwarecatalog',
                    'username' => $username,
                    'email' => $existingUserByUsername->getEMailAddress(),
                    'organizationUuid' => $organizationUuid
                ]);
                
                return $existingUserByUsername;
            }

            // Create new user account
            $this->_logger->critical('🚀 CREATING NEW USER ACCOUNT', [
                'app' => 'softwarecatalog',
                'username' => $username,
                'email' => $email,
                'contactId' => $contactId
            ]);

            $randomPw = $this->_secureRandom->generate(length: 12);
            $user = $this->_userManager->createUser(uid: $username, password: $randomPw);

            if ($user) {
                $this->_logger->critical('🎊 NEW USER ACCOUNT CREATED', [
                    'app' => 'softwarecatalog',
                    'username' => $username,
                    'email' => $email,
                    'contactId' => $contactId,
                    'userId' => $user->getUID()
                ]);

                // Set user details
                $this->_logger->info('[USER] Step 4: Setting user details', [
                    'username' => $username
                ]);
                $user->setEMailAddress($email);
                $displayName = $this->getDisplayNameFromContactData($objectData);
                $user->setDisplayName($displayName);
                
                $this->_logger->critical('📋 USER DETAILS SET', [
                    'app' => 'softwarecatalog',
                    'username' => $username,
                    'email' => $email,
                    'displayName' => $displayName
                ]);

                // Store organization UUID in user config for OpenConnector access
                if (!empty($organizationUuid)) {
                    $this->_logger->info('[USER] Step 5: Storing organization UUID', [
                        'username' => $username,
                        'organizationUuid' => $organizationUuid
                    ]);
                    $this->storeUserOrganizationUuid($user, $organizationUuid);
                }

                // Set user groups based on roles and organization
                $this->_logger->info('[USER] Step 6: Assigning user groups', [
                    'username' => $username,
                    'isFirstContact' => $isFirstContact
                ]);
                $this->assignUserGroups($user, $objectData, $isFirstContact);

                // Update contactpersoon with username
                $objectData['username'] = $username;
                $contactpersoonObject->setObject($objectData);

                // Send user creation email
                $this->_logger->info('[USER] Step 7: Sending user creation email', [
                    'username' => $username,
                    'email' => $email
                ]);
                $this->sendUserCreationEmail($user, $objectData);

                $creationTime = round(microtime(true) - $startTime, 3);
                $this->_logger->critical('🎉 USER ACCOUNT CREATION COMPLETED', [
                    'app' => 'softwarecatalog',
                    'contactpersoonId' => $contactId,
                    'username' => $username,
                    'email' => $email,
                    'displayName' => $displayName,
                    'organizationUuid' => $organizationUuid,
                    'creationTime' => $creationTime . 's'
                ]);

                return $user;
            } else {
                $this->_logger->error('❌ USER CREATION RETURNED NULL', [
                    'app' => 'softwarecatalog',
                    'username' => $username,
                    'email' => $email,
                    'contactpersoonId' => $contactId,
                    'note' => 'No exception thrown but createUser returned null'
                ]);
            }

            return null;

        } catch (\Exception $e) {
            $this->_logger->error('💥 USER CREATION EXCEPTION', [
                'app' => 'softwarecatalog',
                'contactpersoonId' => $contactpersoonObject->getId(),
                'email' => $objectData['email'] ?? $objectData['e-mailadres'] ?? 'unknown',
                'username' => $username ?? 'unknown',
                'exception' => $e->getMessage(),
                'exception_class' => get_class($e),
                'exception_code' => $e->getCode(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }

    /**
     * Assigns user groups based on organization type and roles
     *
     * @param \OCP\IUser $user The user to assign groups to
     * @param array $objectData The contact person data
     * @param bool $isFirstContact Whether this is the first contact for the organization
     *
     * @return void
     */
    /**
     * Assign user to appropriate groups based on their role and organization.
     * 
     * Users are NOT added to generic groups or organization-specific groups.
     * Users are tied to organization entities in OpenRegister instead.
     * 
     * @param \OCP\IUser $user The user to assign groups to.
     * @param array $objectData The contactpersoon object data.
     * @param bool $isFirstContact Whether this is the first contact of the organization.
     * 
     * @return void
     */
    private function assignUserGroups(\OCP\IUser $user, array $objectData, bool $isFirstContact = false): void
    {
        try {
            $roles = $objectData['roles'] ?? [];
            $organizationId = $objectData['organisation'] ?? $objectData['organisatie'] ?? '';

            // Ensure roles is an array.
            if (!is_array($roles)) {
                $roles = [$roles];
            }

            // Get the settings service to access group configurations.
            $settingsService = $this->_container->get('OCA\SoftwareCatalog\Service\SettingsService');

            // Add user to organization admin groups if this is the first contact.
            if ($isFirstContact) {
                $organizationAdminGroups = $settingsService->getOrganizationAdminGroups();
                foreach ($organizationAdminGroups as $groupName) {
                    $this->addUserToGroupWithCheck($user, $groupName, 'organization-admin');
                }
                
                $this->_logger->info(
                    'Assigned organization admin groups to first contact',
                    [
                        'username' => $user->getUID(),
                        'organizationId' => $organizationId,
                        'adminGroups' => $organizationAdminGroups
                    ]
                );
            }

            // Assign role based on organization type.
            if (!empty($organizationId)) {
                $organizationType = $this->getOrganizationType((string)$organizationId);
                $roleGroup = $this->getRoleGroupByOrganizationType($organizationType);
                
                if (!empty($roleGroup)) {
                    $this->addUserToGroupWithCheck($user, $roleGroup, 'organization-type-role');
                    
                    $this->_logger->info(
                        'Assigned role based on organization type',
                        [
                            'username' => $user->getUID(),
                            'organizationId' => $organizationId,
                            'organizationType' => $organizationType,
                            'assignedRole' => $roleGroup
                        ]
                    );
                } else {
                    $this->_logger->warning(
                        'No role mapping found for organization type',
                        [
                            'username' => $user->getUID(),
                            'organizationId' => $organizationId,
                            'organizationType' => $organizationType
                        ]
                    );
                }
            }

            // Users are now tied to organisation entities in OpenRegister.
            // No need to add to organization-specific groups.

            $this->_logger->info(
                'Successfully assigned user groups based on organization type',
                [
                    'username' => $user->getUID(),
                    'isFirstContact' => $isFirstContact,
                    'organizationAdminGroups' => $isFirstContact ? ($organizationAdminGroups ?? []) : [],
                    'organizationId' => $organizationId,
                    'roleGroup' => $roleGroup ?? 'none',
                    'organizationType' => $organizationType ?? 'unknown'
                ]
            );

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
            'Aanbod-beheerder' => 'Aanbod-beheerder',
            'Gebruik-beheerder' => 'Gebruik-beheerder',
            'Gebruik-raadpleger' => 'Gebruik-raadpleger',
            'Functioneel-beheerder' => 'Functioneel-beheerder',
            'VNG-raadpleger' => 'VNG-raadpleger',
            'Organisatie-beheerder' => 'Organisatie-beheerder',
            'Ambtenaar' => 'Ambtenaar'
        ];
    }

    /**
     * Adds a user to a group, creating the group if it doesn't exist
     *
     * @param \OCP\IUser $user The user to add
     * @param string $groupName The group name
     * @param string $type The type of group assignment (for logging)
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
     * Adds a user to a group only if the group exists, does not create new groups
     *
     * @param \OCP\IUser $user The user to add to the group
     * @param string $groupName The name of the group
     * @param string $type The type of group assignment for logging
     *
     * @return void
     */
    private function addUserToGroupWithCheck(\OCP\IUser $user, string $groupName, string $type): void
    {
        try {
            $group = $this->_groupManager->get($groupName);
            
            if (!$group) {
                $this->_logger->warning(
                    'Group does not exist, skipping user assignment',
                    [
                        'username' => $user->getUID(),
                        'groupName' => $groupName,
                        'type' => $type
                    ]
                );
                return;
            }

            if (!$group->inGroup($user)) {
                $group->addUser($user);
                $this->_logger->info(
                    'Added user to existing group',
                    [
                        'username' => $user->getUID(),
                        'groupName' => $groupName,
                        'type' => $type
                    ]
                );
            } else {
                $this->_logger->debug(
                    'User already in group',
                    [
                        'username' => $user->getUID(),
                        'groupName' => $groupName,
                        'type' => $type
                    ]
                );
            }
        } catch (\Exception $e) {
            $this->_logger->error(
                'Failed to add user to group with check: ' . $e->getMessage(),
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
     * Updates user groups when contact person data changes
     * Note: Role assignment is now based on organization type, not individual roles
     *
     * @param \OCP\IUser $user The user to update
     * @param array $contactData The updated contact person data
     *
     * @return void
     */
    public function updateUserGroupsFromContactData(\OCP\IUser $user, array $contactData): void
    {
        try {
            $organizationId = $contactData['organisation'] ?? $contactData['organisatie'] ?? '';
            
            if (empty($organizationId)) {
                $this->_logger->warning(
                    'No organization ID found for user group update',
                    ['username' => $user->getUID()]
                );
                return;
            }

            // Get organization type and determine role group
            $organizationType = $this->getOrganizationType((string)$organizationId);
            $newRoleGroup = $this->getRoleGroupByOrganizationType($organizationType);
            
            if (empty($newRoleGroup)) {
                $this->_logger->warning(
                    'No role group mapping found for organization type during update',
                    [
                        'username' => $user->getUID(),
                        'organizationId' => $organizationId,
                        'organizationType' => $organizationType
                    ]
                );
                return;
            }

            // Remove user from old organization type role groups
            $allPossibleRoleGroups = ['gebruik-beheerder', 'aanbod-beheerder'];
            foreach ($allPossibleRoleGroups as $roleGroup) {
                if ($roleGroup !== $newRoleGroup) {
                    $group = $this->_groupManager->get($roleGroup);
                    if ($group && $group->inGroup($user)) {
                        $group->removeUser($user);
                        $this->_logger->info(
                            'Removed user from old organization type role group',
                            [
                                'username' => $user->getUID(),
                                'groupName' => $roleGroup,
                                'reason' => 'organization type changed'
                            ]
                        );
                    }
                }
            }

            // Add user to new role group if it exists
            $this->addUserToGroupWithCheck($user, $newRoleGroup, 'organization-type-role-update');

            $this->_logger->info(
                'Updated user groups based on organization type',
                [
                    'username' => $user->getUID(),
                    'organizationId' => $organizationId,
                    'organizationType' => $organizationType,
                    'assignedRole' => $newRoleGroup
                ]
            );

        } catch (\Exception $e) {
            $this->_logger->error(
                'Failed to update user groups from contact data: ' . $e->getMessage(),
                [
                    'username' => $user->getUID(),
                    'exception' => $e
                ]
            );
        }
    }

    /**
     * Legacy method for backward compatibility - now redirects to organization type-based logic
     *
     * @param \OCP\IUser $user The user to update
     * @param array $newRoles The new roles (ignored - kept for compatibility)
     * @param array $oldRoles The old roles (ignored - kept for compatibility)
     *
     * @return void
     * @deprecated Use updateUserGroupsFromContactData instead
     */
    public function updateUserGroupsFromRoles(\OCP\IUser $user, array $newRoles, array $oldRoles = []): void
    {
        $this->_logger->info(
            'updateUserGroupsFromRoles is deprecated - role assignment now based on organization type',
            [
                'username' => $user->getUID(),
                'newRoles' => $newRoles,
                'oldRoles' => $oldRoles
            ]
        );
        
        // For backward compatibility, try to find the user's contact data and update based on organization type
        try {
            $contactObject = $this->findContactpersoonByUsername($user->getUID());
            if ($contactObject) {
                $contactData = $contactObject->getObject();
                $this->updateUserGroupsFromContactData($user, $contactData);
            } else {
                $this->_logger->warning(
                    'Could not find contact person data for user - cannot update groups',
                    ['username' => $user->getUID()]
                );
            }
        } catch (\Exception $e) {
            $this->_logger->error(
                'Failed to update user groups via legacy method: ' . $e->getMessage(),
                [
                    'username' => $user->getUID(),
                    'exception' => $e
                ]
            );
        }
    }



    /**
     * Finds contactpersoon object by username
     *
     * @param string $username The username to search for
     *
     * @return object|null The contactpersoon object or null if not found
     */
    private function findContactpersoonByUsername(string $username): ?object
    {
        try {
            $objectService = $this->_getObjectService();
            $settingsService = $this->_container->get('OCA\SoftwareCatalog\Service\SettingsService');

            // Get configuration values
            $registerId = $settingsService->getVoorzieningenRegisterId();
            $contactpersoonSchemaId = $settingsService->getSchemaIdForObjectType('contactpersoon');

            if (!$registerId || !$contactpersoonSchemaId) {
                throw new \Exception('Register or schema ID not configured for contactpersoon');
            }

            // Search for contactpersoon with the given username
            $searchFilters = [
                'username' => $username
            ];

            $results = $objectService->findAll($searchFilters, $registerId, $contactpersoonSchemaId);

            if (!empty($results)) {
                return $results[0]; // Return the first match
            }

            return null;

        } catch (\Exception $e) {
            $this->_logger->error(
                'Failed to find contactpersoon by username: ' . $e->getMessage(),
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
            if (!$objectService) {
                return null;
            }

            // Get register and schema IDs dynamically from configuration
            $settingsService = $this->_container->get('OCA\SoftwareCatalog\Service\SettingsService');
            $registerId = $settingsService->getVoorzieningenRegisterId();
            $organisatieSchemaId = $settingsService->getSchemaIdForObjectType('organisatie');

            if (!$registerId || !$organisatieSchemaId) {
                $this->_logger->warning('Register or schema ID not configured for organisatie');
                return null;
            }

            // Use find() method with proper register/schema context
            $organizationObject = $objectService->find($organizationId, [], false, $registerId, $organisatieSchemaId);

            if ($organizationObject) {
                $organizationData = $organizationObject->getObject();
                $groupId = $organizationData['group'] ?? '';

                if (!empty($groupId)) {
                    $group = $this->_groupManager->get($groupId);
                    return $group;
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
     * Determines if this contact object is the first contact for the organization
     *
     * @param object $contactObject The contact object being processed (contactpersoon)
     * @param array $objectData The contact data
     *
     * @return bool True if this is the first contact for the organization
     */
    private function isFirstContactForOrganization(object $contactObject, array $objectData): bool
    {
        try {
            $organizationId = $objectData['organisation'] ?? $objectData['organisatie'] ?? '';
            $currentContactId = $contactObject->getId();

            if (empty($organizationId)) {
                $this->_logger->warning('No organization ID found for contact object');
                return false;
            }

            $this->_logger->info(
                'Checking if contact is first for organization',
                [
                    'contactId' => $currentContactId,
                    'organizationId' => $organizationId
                ]
            );

            // Simple approach: Check if any OTHER users exist with this organization UUID
            $objectService = $this->_getObjectService();
            if (!$objectService) {
                $this->_logger->error('ObjectService not available for first contact check');
                return false;
            }

            // Get settings for schema IDs
            $settingsService = $this->_container->get('OCA\SoftwareCatalog\Service\SettingsService');
            $registerId = $settingsService->getVoorzieningenRegisterId();

            // Check contactpersoon schema
            $contactpersoonSchemaId = $settingsService->getSchemaIdForObjectType('contactpersoon');
            if ($contactpersoonSchemaId) {
                $existingContacts = $objectService->findAll(
                    ['organisation' => $organizationId],
                    $registerId,
                    $contactpersoonSchemaId
                );

                // Filter out the current contact being processed
                $otherContacts = array_filter($existingContacts, function ($contact) use ($currentContactId) {
                    return $contact->getId() !== $currentContactId;
                });

                $this->_logger->info(
                    'Found existing contacts for organization',
                    [
                        'organizationId' => $organizationId,
                        'totalContacts' => count($existingContacts),
                        'otherContacts' => count($otherContacts),
                        'currentContactId' => $currentContactId,
                        'isFirstContact' => empty($otherContacts)
                    ]
                );

                // If there are any OTHER existing contacts, this is not the first
                if (!empty($otherContacts)) {
                    return false;
                }
            }

            return true;

        } catch (\Exception $e) {
            $this->_logger->error(
                'Failed to determine if first contact: ' . $e->getMessage(),
                [
                    'contactId' => $contactObject->getId(),
                    'exception' => $e
                ]
            );
            // Default to false for safety
            return false;
        }
    }

    /**
     * Stores organization UUID in user config for OpenConnector access
     *
     * This method stores the organization UUID in the user's 'core' namespace
     * configuration, making it accessible to other apps like OpenConnector.
     * It also sets the user's active organisation in OpenRegister so they're
     * automatically logged into the correct organisation.
     *
     * @param IUser $user The user object
     * @param string|int $organizationUuid The organization UUID (can be string or int)
     *
     * @return void
     */
    private function storeUserOrganizationUuid(IUser $user, string|int $organizationUuid): void
    {
        try {
            if (!empty($organizationUuid)) {
                // Convert to string to ensure consistent storage
                $organizationUuidStr = (string)$organizationUuid;

                // Store in core config for OpenConnector access
                $this->config->setUserValue(
                    $user->getUID(),
                    'core',
                    'organisation',
                    $organizationUuidStr
                );

                // Also set as active organisation in OpenRegister
                try {
                    $this->config->setUserValue(
                        $user->getUID(),
                        'openregister',
                        'active_organisation',
                        $organizationUuidStr
                    );

                    $this->_logger->info(
                        'Stored organization UUID in user config and set as active organisation',
                        [
                            'username' => $user->getUID(),
                            'organizationUuid' => $organizationUuidStr,
                            'organizationUuid_type' => gettype($organizationUuid)
                        ]
                    );
                } catch (\Exception $e) {
                    $this->_logger->warning(
                        'Failed to set active organisation in OpenRegister config, but core config was successful',
                        [
                            'username' => $user->getUID(),
                            'organizationUuid' => $organizationUuidStr,
                            'error' => $e->getMessage()
                        ]
                    );
                }
            }
        } catch (\Exception $e) {
            $this->_logger->error(
                'Failed to store organization UUID in user config: ' . $e->getMessage(),
                [
                    'username' => $user->getUID(),
                    'organizationUuid' => $organizationUuid,
                    'organizationUuid_type' => gettype($organizationUuid),
                    'exception' => $e
                ]
            );
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

        return implode(' ', $parts) ?: ($contactData['email'] ?? $contactData['e-mailadres'] ?? 'Unknown User');
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
            $this->processContactpersoon($contactObject);

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
            $this->processContactpersoon($contactObject);

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
     * @param object $contactpersoonObject The contactpersoon object
     * @param string $username The username
     * @param string $organizationUuid The organization UUID
     *
     * @return void
     */
    public function assignBeheerderRole(object $contactpersoonObject, string $username, string $organizationUuid): void
    {
        try {
            $objectData = $contactpersoonObject->getObject();
            $currentRoles = $objectData['roles'] ?? [];

            if (!is_array($currentRoles)) {
                $currentRoles = [];
            }

            // Add beheerder role if not already present
            if (!in_array('beheerder', array_map('strtolower', $currentRoles))) {
                $currentRoles[] = 'beheerder';

                // Update the contactpersoon object (but don't save to prevent event loops)
                $objectData['roles'] = $currentRoles;
                $contactpersoonObject->setObject($objectData);

                // Note: NOT saving the object here to prevent infinite event loops
                // The original API call/operation will handle persistence
                $this->_logger->info('Beheerder role added to contactpersoon object, but not saved to prevent event loops', [
                    'username' => $username,
                    'organizationUuid' => $organizationUuid,
                    'updatedRoles' => $currentRoles,
                    'objectId' => $contactpersoonObject->getId()
                ]);

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
     * @param string $username The username
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

            $this->_logger->info('Getting organization type', [
                'organizationId' => $organizationId
            ]);

            // Get voorzieningen config for register and schema
            $settingsService = $this->_container->get('OCA\SoftwareCatalog\Service\SettingsService');
            $voorzieningenConfig = $settingsService->getVoorzieningenConfig();
            $register = $voorzieningenConfig['register'] ?? '';
            $organizationSchema = $voorzieningenConfig['organisatie_schema'] ?? '';

            // Find by UUID - use find() with register and schema
            $organizationObject = $objectService->find(
                id: $organizationId,
                register: $register,
                schema: $organizationSchema,
                _rbac: false,
                _multitenancy: false
            );

            if ($organizationObject) {
                $organizationData = $organizationObject->getObject();
                $organizationType = $organizationData['type'] ?? '';
                
                $this->_logger->info('Found organization type', [
                    'organizationId' => $organizationId,
                    'type' => $organizationType,
                    'normalizedType' => strtolower($organizationType)
                ]);
                
                return $organizationType; // Don't convert to lowercase here, let getRoleGroupByOrganizationType handle it
            }

            $this->_logger->warning('Organization not found', [
                'organizationId' => $organizationId
            ]);
            return '';

        } catch (\Exception $e) {
            $this->_logger->error(
                'Failed to get organization type: ' . $e->getMessage(),
                [
                    'organizationId' => $organizationId,
                    'exception' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]
            );
            return '';
        }
    }

    /**
     * Maps organization type to role group based on business rules
     *
     * @param string $organizationType The organization type (case-insensitive)
     *
     * @return string The role group name or empty string if no mapping exists
     */
    private function getRoleGroupByOrganizationType(string $organizationType): string
    {
        // Normalize the organization type to lowercase for comparison
        $normalizedType = strtolower(trim($organizationType));
        
        // Define the mapping based on requirements:
        // "Gemeente" -> "gebruik-beheerder"
        // "Leverancier" -> "aanbod-beheerder"
        // "Samenwerking" -> "gebruik-beheerder"
        // "Community" -> "aanbod-beheerder"
        $typeToRoleMapping = [
            'gemeente' => 'gebruik-beheerder',
            'leverancier' => 'aanbod-beheerder',
            'samenwerking' => 'gebruik-beheerder',
            'community' => 'aanbod-beheerder'
        ];

        return $typeToRoleMapping[$normalizedType] ?? '';
    }

    /**
     * Sends user creation email
     *
     * @param \OCP\IUser $user The created user
     * @param array $objectData The contact person data
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
            $organizationId = $objectData['organisation'] ?? $objectData['organisatie'] ?? '';
            if (!empty($organizationId)) {
                try {
                    $objectService = $this->_getObjectService();
                    // Get register and schema IDs dynamically from configuration
                    $settingsService = $this->_container->get('OCA\SoftwareCatalog\Service\SettingsService');
                    $registerId = $settingsService->getVoorzieningenRegisterId();
                    $organisatieSchemaId = $settingsService->getSchemaIdForObjectType('organisatie');

                    if (!$registerId || !$organisatieSchemaId) {
                        $this->_logger->warning('Register or schema ID not configured for organisatie');
                        return;
                    }

                    $organizationObject = $objectService->find($organizationId, [], false, $registerId, $organisatieSchemaId);
                    if ($organizationObject) {
                        $organizationData = $organizationObject->getObject();
                        $this->_logger->info('Retrieved organization data for email', [
                            'organizationId' => $organizationId,
                            'organizationUuid' => $organizationData['id'] ?? 'NOT_SET',
                            'organizationName' => $organizationData['naam'] ?? 'NOT_SET'
                        ]);
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
     * Processes a contactpersoon object to create an inactive user
     *
     * If the contactpersoon object doesn't have a username or user,
     * this method will create an inactive user account and set the username property.
     *
     * @param object $contactpersoonObject The contactpersoon object to process
     * @param bool $isUpdate Whether this is an update operation (defaults to false)
     *
     * @return bool True if processing was successful
     * @throws \Exception If processing fails
     */
    public function processContactpersoon(object $contactpersoonObject, bool $isUpdate = false): bool
    {
        try {
            $this->_logger->info('Processing contactpersoon object', [
                'objectId' => $contactpersoonObject->getId(),
                'isUpdate' => $isUpdate
            ]);

            // Get object data
            $objectData = $contactpersoonObject->getObject();

            // Check if username exists and is filled
            $username = $objectData['username'] ?? '';

            if (empty($username)) {
                $this->_logger->info('Username not found or empty, creating inactive user account');

                // Generate username from name fields
                $username = $this->generateUsernameFromContactData($objectData);

                // For updates, try to find existing user first to avoid expensive isFirstContactForOrganization check
                if ($isUpdate) {
                    $existingUser = $this->_userManager->get($username);

                    if ($existingUser) {
                        $this->_logger->info('Found existing user during update, skipping expensive first contact check', [
                            'username' => $username,
                            'objectId' => $contactpersoonObject->getId()
                        ]);

                        // Update the contactpersoon object with the username (but don't save to prevent event loops)
                        $objectData['username'] = $username;
                        $contactpersoonObject->setObject($objectData);

                        $this->_logger->info('Username added to contactpersoon object during update, but not saved to prevent event loops', [
                            'username' => $username,
                            'objectId' => $contactpersoonObject->getId()
                        ]);

                        // Ensure contactpersoon is added to organization
                        $this->ensureContactpersoonInOrganization($contactpersoonObject);

                        return true;
                    }
                }

                // Determine if this is the first contact for the organization (expensive operation)
                $isFirstContact = $this->isFirstContactForOrganization($contactpersoonObject, $objectData);

                // Create the user account
                $user = $this->createUserAccount($contactpersoonObject, $isFirstContact);

                if ($user === null) {
                    throw new \Exception('Failed to create user account');
                }

                // Set user to inactive initially
                $this->setUserInactive($user->getUID());

                // Update the contactpersoon object with the username (but don't save to prevent event loops)
                $objectData['username'] = $username;
                $contactpersoonObject->setObject($objectData);

                // Note: NOT saving the object here to prevent infinite event loops
                // The original API call/operation will handle persistence
                $this->_logger->info('Username added to contactpersoon object, but not saved to prevent event loops', [
                    'username' => $username,
                    'objectId' => $contactpersoonObject->getId()
                ]);

                // Ensure contactpersoon is added to organization
                $this->ensureContactpersoonInOrganization($contactpersoonObject);

                // Also add user to organization entity (OpenRegister entity, not object)
                $this->addUserToOrganizationEntity($contactpersoonObject, $username);

                $this->_logger->info(
                    'Successfully created inactive user and updated contactpersoon',
                    [
                        'username' => $username,
                        'objectId' => $contactpersoonObject->getId()
                    ]
                );

                return true;
            }

            $this->_logger->info(
                'Username already exists, contactpersoon processed',
                [
                    'username' => $username,
                    'objectId' => $contactpersoonObject->getId()
                ]
            );

            // Ensure contactpersoon is added to organization (even for existing users)
            $this->ensureContactpersoonInOrganization($contactpersoonObject);

            return true;

        } catch (\Exception $e) {
            $this->_logger->error(
                'Failed to process contactpersoon object: ' . $e->getMessage(),
                [
                    'exception' => $e,
                    'objectId' => $contactpersoonObject->getId() ?? 'unknown'
                ]
            );
            throw $e;
        }
    }

    /**
     * Sets a user account to inactive
     *
     * @param string $username The username to set as inactive
     *
     * @return bool True if successful
     */
    public function setUserInactive(string $username): bool
    {
        try {
            $user = $this->_userManager->get($username);

            if ($user) {
                $user->setEnabled(false);

                $this->_logger->info(
                    'Set user account to inactive',
                    [
                        'username' => $username
                    ]
                );

                return true;
            } else {
                $this->_logger->warning(
                    'User not found when trying to set inactive',
                    [
                        'username' => $username
                    ]
                );

                return false;
            }

        } catch (\Exception $e) {
            $this->_logger->error(
                'Failed to set user inactive: ' . $e->getMessage(),
                [
                    'username' => $username,
                    'exception' => $e
                ]
            );

            return false;
        }
    }

    /**
     * Sets a user account to active
     *
     * @param string $username The username to set as active
     *
     * @return bool True if successful
     */
    public function setUserActive(string $username): bool
    {
        try {
            $user = $this->_userManager->get($username);

            if ($user) {
                $user->setEnabled(true);

                $this->_logger->info(
                    'Set user account to active',
                    [
                        'username' => $username
                    ]
                );

                return true;
            } else {
                $this->_logger->warning(
                    'User not found when trying to set active',
                    [
                        'username' => $username
                    ]
                );

                return false;
            }

        } catch (\Exception $e) {
            $this->_logger->error(
                'Failed to set user active: ' . $e->getMessage(),
                [
                    'username' => $username,
                    'exception' => $e
                ]
            );

            return false;
        }
    }

    /**
     * Handles contactpersoon updates, particularly role changes
     *
     * @param object $contactpersoonObject The updated contactpersoon object
     * @param object $oldContactpersoonObject The previous contactpersoon object
     *
     * @return void
     */
    public function handleContactpersoonUpdate(object $contactpersoonObject, object $oldContactpersoonObject): void
    {
        try {
            $this->_logger->info('Handling contactpersoon update', [
                'objectId' => $contactpersoonObject->getId()
            ]);

            // Process the updated contactpersoon
            $this->processContactpersoon($contactpersoonObject);

            // Check for role changes and update groups accordingly
            $newData = $contactpersoonObject->getObject();
            $oldData = $oldContactpersoonObject->getObject();

            $newRoles = $newData['roles'] ?? [];
            $oldRoles = $oldData['roles'] ?? [];

            // Ensure both are arrays
            if (!is_array($newRoles)) {
                $newRoles = [$newRoles];
            }
            if (!is_array($oldRoles)) {
                $oldRoles = [$oldRoles];
            }

            // Check if roles or organization have changed (organization type determines role assignment)
            $oldOrganization = $oldData['organisation'] ?? $oldData['organisatie'] ?? '';
            $newOrganization = $newData['organisation'] ?? $newData['organisatie'] ?? '';
            
            if ($newRoles !== $oldRoles || $oldOrganization !== $newOrganization) {
                $username = $newData['username'] ?? '';
                if (!empty($username)) {
                    $user = $this->_userManager->get($username);
                    if ($user) {
                        $this->_logger->info(
                            'Contact person data changed, updating user groups based on organization type',
                            [
                                'contactpersoonId' => $contactpersoonObject->getId(),
                                'username' => $username,
                                'oldRoles' => $oldRoles,
                                'newRoles' => $newRoles,
                                'oldOrganization' => $oldOrganization,
                                'newOrganization' => $newOrganization
                            ]
                        );

                        // Update user groups based on organization type (roles are now ignored)
                        $this->updateUserGroupsFromContactData($user, $newData);
                    }
                }
            }

        } catch (\Exception $e) {
            $this->_logger->error(
                'Failed to handle contactpersoon update: ' . $e->getMessage(),
                [
                    'objectId' => $contactpersoonObject->getId(),
                    'exception' => $e
                ]
            );
        }
    }

    /**
     * Sends account suspension notification email
     *
     * @param \OCP\IUser $user The suspended user
     * @param array $objectData The contact person data
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
            $settingsService = $this->_container->get('OCA\SoftwareCatalog\Service\SettingsService');
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
                    $this->_logger->info('ContactPersonHandler: Contactpersoon should be added to organization', [
                        'username' => $username,
                        'organizationUuid' => $organizationUuid,
                        'currentUsers' => $organizationUsers
                    ]);
                    return true;
                }

                return false;

            } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
                // Organization doesn't exist, so we can't add the user
                $this->_logger->warning('ContactPersonHandler: Organization not found for contactpersoon', [
                    'username' => $username,
                    'organizationUuid' => $organizationUuid
                ]);
                return false;
            }

        } catch (\Exception $e) {
            $this->_logger->error(
                'ContactPersonHandler: Failed to check if contactpersoon should be added to organization: ' . $e->getMessage(),
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
                $this->_logger->warning('ContactPersonHandler: Cannot add contactpersoon to organization - missing username or organization', [
                    'username' => $username,
                    'organizationUuid' => $organizationUuid
                ]);
                return false;
            }

            $objectService = $this->_getObjectService();
            if (!$objectService) {
                $this->_logger->error('ContactPersonHandler: OpenRegister ObjectService not available');
                return false;
            }

            // Get the organization object
            $settingsService = $this->_container->get('OCA\SoftwareCatalog\Service\SettingsService');
            $registerId = $settingsService->getVoorzieningenRegisterId();
            $organisatieSchemaId = $settingsService->getSchemaIdForObjectType('organisatie');

            if (!$registerId || !$organisatieSchemaId) {
                $this->_logger->error('ContactPersonHandler: Register or schema not configured for organisatie');
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

                    $this->_logger->info('ContactPersonHandler: Successfully added contactpersoon to organization', [
                        'username' => $username,
                        'organizationUuid' => $organizationUuid,
                        'updatedUsers' => $organizationUsers
                    ]);

                    return true;
                } else {
                    $this->_logger->debug('ContactPersonHandler: Contactpersoon already in organization', [
                        'username' => $username,
                        'organizationUuid' => $organizationUuid
                    ]);
                    return true; // Already there, consider it successful
                }

            } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
                $this->_logger->error('ContactPersonHandler: Organization not found for contactpersoon', [
                    'username' => $username,
                    'organizationUuid' => $organizationUuid
                ]);
                return false;
            }

        } catch (\Exception $e) {
            $this->_logger->error(
                'ContactPersonHandler: Failed to add contactpersoon to organization: ' . $e->getMessage(),
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
     * Ensures contactpersoon is added to organization after user creation/update
     *
     * @param object $contactpersoonObject The contactpersoon object
     *
     * @return void
     */
    public function ensureContactpersoonInOrganization(object $contactpersoonObject): void
    {
        try {
            $this->_logger->info('ContactPersonHandler: Ensuring contactpersoon is in organization', [
                'objectId' => $contactpersoonObject->getId()
            ]);

            // Check if user should be added to organization
            if ($this->shouldAddContactpersoonToOrganization($contactpersoonObject)) {
                // Add user to organization
                $result = $this->addContactpersoonToOrganization($contactpersoonObject);

                if ($result) {
                    $this->_logger->info('ContactPersonHandler: Successfully ensured contactpersoon in organization', [
                        'objectId' => $contactpersoonObject->getId()
                    ]);
                } else {
                    $this->_logger->warning('ContactPersonHandler: Failed to add contactpersoon to organization', [
                        'objectId' => $contactpersoonObject->getId()
                    ]);
                }
            } else {
                $this->_logger->debug('ContactPersonHandler: Contactpersoon already in organization or no action needed', [
                    'objectId' => $contactpersoonObject->getId()
                ]);
            }

        } catch (\Exception $e) {
            $this->_logger->error(
                'ContactPersonHandler: Failed to ensure contactpersoon in organization: ' . $e->getMessage(),
                [
                    'objectId' => $contactpersoonObject->getId(),
                    'exception' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString()
                ]
            );
        }
    }

    /**
     * Adds a user to the organization entity (OpenRegister entity, not object)
     *
     * This method ensures that an organization entity exists in OpenRegister's database
     * before adding the user to it. If the entity doesn't exist, it will be created
     * from the organization object data.
     *
     * @param object $contactpersoonObject The contactpersoon object
     * @param string $username The username to add
     *
     * @return void
     */
    public function addUserToOrganizationEntity(object $contactpersoonObject, string $username): void
    {
        try {
            $objectData = $contactpersoonObject->getObject();
            $organizationUuid = $objectData['organisation'] ?? $objectData['organisatie'] ?? '';

            if (empty($organizationUuid)) {
                $this->_logger->warning('ContactPersonHandler: No organization reference found for contact person', [
                    'objectId' => $contactpersoonObject->getId(),
                    'username' => $username
                ]);
                return;
            }

            $this->_logger->info('ContactPersonHandler: Adding user to organization entity', [
                'objectId' => $contactpersoonObject->getId(),
                'username' => $username,
                'organizationUuid' => $organizationUuid
            ]);

            try {
                $organisationMapper = $this->_container->get('OCA\\OpenRegister\\Db\\OrganisationMapper');
                
                // Try to find the organisation entity
                try {
                    $organisation = $organisationMapper->findByUuid($organizationUuid);
                    
                    $this->_logger->info('ContactPersonHandler: Found existing organization entity', [
                        'organizationUuid' => $organizationUuid,
                        'organizationName' => $organisation->getName()
                    ]);
                } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
                    // Organization entity doesn't exist, create it from the object data
                    $this->_logger->info('ContactPersonHandler: Organization entity not found, creating it', [
                        'organizationUuid' => $organizationUuid
                    ]);
                    
                    $organisation = $this->ensureOrganizationEntity($organizationUuid);
                    
                    if (!$organisation) {
                        $this->_logger->error('ContactPersonHandler: Failed to create organization entity', [
                            'organizationUuid' => $organizationUuid
                        ]);
                        return;
                    }
                }

                // Add user to the organisation entity
                $currentUsers = $organisation->getUsers() ?? [];
                if (!in_array($username, $currentUsers)) {
                    $currentUsers[] = $username;
                    $organisation->setUsers($currentUsers);
                    $organisationMapper->update($organisation);

                    $this->_logger->info('ContactPersonHandler: Successfully added user to organization entity', [
                        'objectId' => $contactpersoonObject->getId(),
                        'username' => $username,
                        'organizationUuid' => $organizationUuid,
                        'totalUsers' => count($currentUsers)
                    ]);
                } else {
                    $this->_logger->info('ContactPersonHandler: User already in organization entity', [
                        'objectId' => $contactpersoonObject->getId(),
                        'username' => $username,
                        'organizationUuid' => $organizationUuid
                    ]);
                }
            } catch (\Exception $e) {
                $this->_logger->error('ContactPersonHandler: Failed to add user to organization entity', [
                    'objectId' => $contactpersoonObject->getId(),
                    'username' => $username,
                    'organizationUuid' => $organizationUuid,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
        } catch (\Exception $e) {
            $this->_logger->error('ContactPersonHandler: Exception in addUserToOrganizationEntity', [
                'objectId' => $contactpersoonObject->getId(),
                'username' => $username,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Ensures an organization entity exists in OpenRegister
     *
     * If the organization entity doesn't exist, this method creates it from
     * the organization object data in the voorzieningen register.
     *
     * @param string $organizationUuid The organization UUID
     *
     * @return \OCA\OpenRegister\Db\Organisation|null The organization entity or null on failure
     */
    private function ensureOrganizationEntity(string $organizationUuid): ?\OCA\OpenRegister\Db\Organisation
    {
        try {
            // Get the organization object from OpenRegister
            $objectService = $this->_getObjectService();
            
            // Get voorzieningen config for register and schema
            $settingsService = $this->_container->get('OCA\SoftwareCatalog\Service\SettingsService');
            $voorzieningenConfig = $settingsService->getVoorzieningenConfig();
            $register = $voorzieningenConfig['register'] ?? '';
            $organizationSchema = $voorzieningenConfig['organisatie_schema'] ?? '';
            
            // Find the organization object by UUID - use find() with register and schema
            $organizationObject = $objectService->find(
                id: $organizationUuid,
                register: $register,
                schema: $organizationSchema,
                _rbac: false,
                _multitenancy: false
            );
            
            if (!$organizationObject) {
                $this->_logger->error('ContactPersonHandler: Organization object not found in OpenRegister', [
                    'organizationUuid' => $organizationUuid
                ]);
                return null;
            }
            
            $organizationData = $organizationObject->getObject();
            
            // Get organization name and description
            $organizationName = $organizationData['naam'] ?? $organizationData['name'] ?? 'Unknown Organization';
            $organizationDescription = $organizationData['beschrijving'] ?? $organizationData['beschrijvingLang'] ?? $organizationData['description'] ?? '';
            
            $this->_logger->info('ContactPersonHandler: Creating organization entity from object data', [
                'organizationUuid' => $organizationUuid,
                'organizationName' => $organizationName
            ]);
            
            // Create the organization entity using OrganisationService
            $organisationService = $this->_container->get('OCA\\OpenRegister\\Service\\OrganisationService');
            
            // Create organisation with specific UUID, without adding current user (as we're in admin context)
            $organisation = $organisationService->createOrganisationWithUuid(
                $organizationName,
                $organizationDescription,
                $organizationUuid,
                false  // Don't add current user (admin) to this organisation
            );
            
            $this->_logger->info('ContactPersonHandler: Successfully created organization entity', [
                'organizationUuid' => $organizationUuid,
                'organizationName' => $organizationName,
                'organizationId' => $organisation->getId()
            ]);
            
            return $organisation;
            
        } catch (\Exception $e) {
            $this->_logger->error('ContactPersonHandler: Failed to ensure organization entity', [
                'organizationUuid' => $organizationUuid,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }

}
