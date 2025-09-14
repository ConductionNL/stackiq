<?php

/**
 * Group Handler for Software Catalog
 *
 * This handler manages generic user groups, role-based group assignments,
 * and ensures all required groups exist and are properly configured.
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

use OCP\IGroupManager;
use OCP\IGroup;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;

/**
 * Handler for group management operations
 *
 * @category Handler
 * @package  OCA\SoftwareCatalog\Service\SoftwareCatalogue
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @version  1.0.0
 * @link     https://github.com/ConductionNL/SoftwareCatalog
 */
class GroupHandler
{
    /**
     * The application name for configuration storage
     *
     * @var string
     */
    private const APP_NAME = 'softwarecatalog';

    /**
     * GroupHandler constructor
     *
     * @param IGroupManager          $_groupManager  Group manager interface
     * @param IUserManager           $_userManager   User manager interface
     * @param IAppConfig             $_appConfig     App configuration interface
     * @param ContainerInterface     $_container     Container interface
     * @param IAppManager            $_appManager    App manager interface
     * @param LoggerInterface        $_logger        Logger interface
     */
    public function __construct(
        private readonly IGroupManager $_groupManager,
        private readonly IUserManager $_userManager,
        private readonly IAppConfig $_appConfig,
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
     * Gets the list of generic user groups from configuration
     *
     * @return array Array of generic user groups
     */
    public function getGenericUserGroups(): array
    {
        $groupsJson = $this->_appConfig->getValueString(self::APP_NAME, 'generic_user_groups', '');
        
        if (empty($groupsJson)) {
            // Return updated default groups if no configuration exists
            return [
                'Aanbod-beheerder',
                'Gebruik-beheerder', 
                'Gebruik-raadpleger',
                'Functioneel-beheerder',
                'VNG-raadpleger',
                'Organisatie-beheerder',
                'Ambtenaar',
                'software-catalog-users'
            ];
        }

        $groups = json_decode($groupsJson, true);
        return is_array($groups) ? $groups : [];
    }

    /**
     * Sets the list of generic user groups in configuration
     *
     * @param array $groups Array of generic user groups
     * 
     * @return void
     */
    public function setGenericUserGroups(array $groups): void
    {
        $groupsJson = json_encode($groups, JSON_THROW_ON_ERROR);
        $this->_appConfig->setValueString(self::APP_NAME, 'generic_user_groups', $groupsJson);
        
        $this->_logger->info(
            'Updated generic user groups configuration',
            [
                'groups' => $groups
            ]
        );
    }

    /**
     * Ensures that all generic user groups exist in the system
     *
     * @return array Array of groups that were created
     */
    public function ensureGenericUserGroupsExist(): array
    {
        $genericGroups = $this->getGenericUserGroups();
        $createdGroups = [];
        
        foreach ($genericGroups as $groupName) {
            if (!$this->_groupManager->get($groupName)) {
                $group = $this->_groupManager->createGroup($groupName);
                if ($group) {
                    $createdGroups[] = $groupName;
                    $this->_logger->info(
                        'Created generic user group',
                        ['groupName' => $groupName]
                    );
                }
            }
        }
        
        // Also ensure role-based groups exist
        $roleBasedGroups = [
            'aanbod-beheerder',
            'gebruik-beheerder',
            'gebruik-raadpleger',
            'functioneel-beheerder',
            'vng-raadpleger',
            'organisatie-beheerder',
            'organisaties-beheerder', // Plural form for organization contacts
            'ambtenaar' // For users from Gemeente organizations
        ];
        
        foreach ($roleBasedGroups as $groupName) {
            if (!$this->_groupManager->get($groupName)) {
                $group = $this->_groupManager->createGroup($groupName);
                if ($group) {
                    $createdGroups[] = $groupName;
                    $this->_logger->info(
                        'Created role-based group',
                        ['groupName' => $groupName]
                    );
                }
            }
        }
        
        return $createdGroups;
    }

    /**
     * Creates a group if it doesn't exist
     *
     * @param string $groupName The group name to create
     * 
     * @return IGroup|null The created or existing group
     */
    public function createGroupIfNotExists(string $groupName): ?IGroup
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
     * Updates user groups based on contactpersoon data
     *
     * @param object $contactpersoonObject The contactpersoon object
     * @param string $username              The username to update groups for
     * 
     * @return void
     */
    public function updateUserGroups(object $contactpersoonObject, string $username): void
    {
        try {
            $user = $this->_userManager->get($username);
            if (!$user) {
                $this->_logger->warning('User not found for group update', ['username' => $username]);
                return;
            }

            $objectData = $contactpersoonObject->getObject();
            
            // Handle role-based groups
            $this->updateRoleBasedGroups($user, $objectData);
            
            // Handle organization groups
            $this->updateOrganizationGroups($user, $objectData);
            
            // Handle special gemeente groups
            $this->updateGemeenteGroups($user, $objectData);
            
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
     * @param IUser $user       The user to update
     * @param array $objectData The contactpersoon data
     * 
     * @return void
     */
    public function updateRoleBasedGroups(IUser $user, array $objectData): void
    {
        $userRoles = $objectData['roles'] ?? [];
        if (!is_array($userRoles)) {
            $userRoles = [];
        }

        $this->_logger->info(
            'Updating role-based groups for user',
            [
                'username' => $user->getUID(),
                'userRoles' => $userRoles
            ]
        );

        // Get the configured generic user groups
        $genericGroups = $this->getGenericUserGroups();
        
        foreach ($genericGroups as $groupName) {
            $group = $this->createGroupIfNotExists($groupName);
            
            if ($group) {
                $hasRole = in_array($groupName, $userRoles);
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
                    // Remove user from group (except for system groups)
                    // Note: Removed 'ambtenaar' from protected groups since it's no longer automatically assigned
                    if (!in_array($groupName, ['software-catalog-users'])) {
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
    }

    /**
     * Updates organization-based groups for a user
     *
     * @param IUser $user       The user to update
     * @param array $objectData The contactpersoon data
     * 
     * @return void
     */
    public function updateOrganizationGroups(IUser $user, array $objectData): void
    {
        $organizationUuid = $objectData['organisation'] ?? $objectData['organization'] ?? '';
        
        if (!empty($organizationUuid)) {
            try {
                // Get organization object
                $objectService = $this->_getObjectService();
                
                // Get register and schema IDs dynamically from configuration
                $settingsService = $this->_container->get('OCA\SoftwareCatalog\Service\SettingsService');
                $registerId = $settingsService->getVoorzieningenRegisterId();
                $organisatieSchemaId = $settingsService->getSchemaIdForObjectType('organisatie');
                
                if (!$registerId || !$organisatieSchemaId) {
                    $this->_logger->warning('Register or schema ID not configured for organisatie');
                    return;
                }
                
                $organizationObject = $objectService->find($organizationUuid, [], false, $registerId, $organisatieSchemaId);
                
                if ($organizationObject) {
                    $orgData = $organizationObject->getObject();
                    $actualUuid = $orgData['id'] ?? $organizationUuid;
                    $groupId = $orgData['group'] ?? '';
                    
                    $this->_logger->info(
                        'DEBUG: Organization group lookup for user',
                        [
                            'username' => $user->getUID(),
                            'inputOrganizationUuid' => $organizationUuid,
                            'actualOrganizationUuid' => $actualUuid,
                            'groupId' => $groupId
                        ]
                    );
                    
                    if (!empty($groupId)) {
                        $group = $this->_groupManager->get($groupId);
                        
                        if ($group && !$group->inGroup($user)) {
                            $group->addUser($user);
                            $this->_logger->info(
                                'Added user to organization group',
                                [
                                    'username' => $user->getUID(),
                                    'group' => $groupId,
                                    'organizationUuid' => $actualUuid
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
     * @param IUser $user       The user to update
     * @param array $objectData The contactpersoon data
     * 
     * @return void
     */
    public function updateGemeenteGroups(IUser $user, array $objectData): void
    {
        $organizationUuid = $objectData['organisation'] ?? $objectData['organization'] ?? '';
        
        if (!empty($organizationUuid)) {
            try {
                // Get organization object
                $objectService = $this->_getObjectService();
                
                // Get register and schema IDs dynamically from configuration
                $settingsService = $this->_container->get('OCA\SoftwareCatalog\Service\SettingsService');
                $registerId = $settingsService->getVoorzieningenRegisterId();
                $organisatieSchemaId = $settingsService->getSchemaIdForObjectType('organisatie');
                
                if (!$registerId || !$organisatieSchemaId) {
                    $this->_logger->warning('Register or schema ID not configured for organisatie');
                    return;
                }
                
                $organizationObject = $objectService->find($organizationUuid, [], false, $registerId, $organisatieSchemaId);
                
                if ($organizationObject) {
                    $orgData = $organizationObject->getObject();
                    $actualUuid = $orgData['id'] ?? $organizationUuid;
                    $orgType = strtolower($orgData['type'] ?? $orgData['soort'] ?? '');
                    
                    // Note: Removed automatic assignment of 'ambtenaar' group for gemeente organizations
                    // The 'ambtenaar' group can still be created if needed, but users are not automatically assigned
                    if ($orgType === 'gemeente') {
                        $this->_logger->debug(
                            'User from gemeente organization (no automatic ambtenaar group assignment)',
                            [
                                'username' => $user->getUID(),
                                'organizationUuid' => $actualUuid,
                                'organizationType' => $orgType
                            ]
                        );
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
     * Gets all available groups with their information
     *
     * @return array Array of group information
     */
    public function getAllGroups(): array
    {
        $groups = $this->_groupManager->search('');
        $groupInfo = [];
        
        foreach ($groups as $group) {
            $groupInfo[] = [
                'id' => $group->getGID(),
                'displayName' => $group->getDisplayName(),
                'memberCount' => count($group->getUsers()),
                'isGeneric' => in_array($group->getGID(), $this->getGenericUserGroups())
            ];
        }
        
        return $groupInfo;
    }

    /**
     * Validates a list of group names
     *
     * @param array $groups Array of group names to validate
     * 
     * @return array Array with validation results
     */
    public function validateGroups(array $groups): array
    {
        $results = [
            'valid' => [],
            'invalid' => [],
            'errors' => []
        ];
        
        foreach ($groups as $groupName) {
            if (empty($groupName) || !is_string($groupName)) {
                $results['invalid'][] = $groupName;
                $results['errors'][] = 'Group name cannot be empty';
                continue;
            }
            
            // Check for invalid characters
            if (preg_match('/[^a-zA-Z0-9._-]/', $groupName)) {
                $results['invalid'][] = $groupName;
                $results['errors'][] = "Group name '{$groupName}' contains invalid characters";
                continue;
            }
            
            $results['valid'][] = $groupName;
        }
        
        return $results;
    }
} 