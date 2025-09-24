<?php

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Controller;

use OCA\SoftwareCatalog\Service\SettingsService;
use OCA\SoftwareCatalog\Service\SoftwareCatalogue\ContactPersonHandler;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IGroupManager;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

/**
 * Controller for managing contactpersonen and their user accounts
 *
 * This controller handles operations related to contactpersonen including:
 * - Converting contactpersonen to users
 * - Managing user passwords
 * - Managing user group memberships
 *
 * @category Controller
 * @package  OCA\SoftwareCatalog\Controller
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/ConductionNL/SoftwareCatalog
 * @version  1.0.0
 */
class ContactpersonenController extends Controller
{
    /**
     * Settings service for configuration access
     *
     * @var SettingsService
     */
    private SettingsService $settingsService;

    /**
     * Contact person handler for user operations
     *
     * @var ContactPersonHandler
     */
    private ContactPersonHandler $contactPersonHandler;

    /**
     * User manager for user operations
     *
     * @var IUserManager
     */
    private IUserManager $userManager;

    /**
     * Group manager for group operations
     *
     * @var IGroupManager
     */
    private IGroupManager $groupManager;

    /**
     * Secure random generator for passwords
     *
     * @var ISecureRandom
     */
    private ISecureRandom $secureRandom;

    /**
     * Logger instance
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * Constructor
     *
     * @param string                $appName              The app name
     * @param IRequest              $request              The request object
     * @param SettingsService       $settingsService      Settings service
     * @param ContactPersonHandler  $contactPersonHandler Contact person handler
     * @param IUserManager          $userManager          User manager
     * @param IGroupManager         $groupManager         Group manager
     * @param ISecureRandom         $secureRandom         Secure random generator
     * @param LoggerInterface       $logger               Logger instance
     */
    public function __construct(
        string $appName,
        IRequest $request,
        SettingsService $settingsService,
        ContactPersonHandler $contactPersonHandler,
        IUserManager $userManager,
        IGroupManager $groupManager,
        ISecureRandom $secureRandom,
        LoggerInterface $logger
    ) {
        parent::__construct($appName, $request);
        $this->settingsService = $settingsService;
        $this->contactPersonHandler = $contactPersonHandler;
        $this->userManager = $userManager;
        $this->groupManager = $groupManager;
        $this->secureRandom = $secureRandom;
        $this->logger = $logger;
    }

    /**
     * Get contactpersonen for an organisation with user status
     *
     * @param string $organisationId The organisation ID
     *
     * @return JSONResponse List of contactpersonen with user information
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function getContactpersonen(string $organisationId): JSONResponse
    {
        try {
            // Get object service
            $objectService = \OC::$server->get('OCA\OpenRegister\Service\ObjectService');
            
            // Search for contactpersonen belonging to this organisation
            // Use a more generic search that doesn't require specific register/schema
            $searchParams = [
                'organisation' => $organisationId,
                '_limit' => 100,
                '_schema' => 'contactpersoon' // Let ObjectService resolve the schema
            ];

            $contactpersonen = $objectService->searchObjectsPaginated($searchParams);

            // Enhance with user information
            $enhancedContactpersonen = [];
            foreach ($contactpersonen['results'] as $contactpersoon) {
                $contactData = $contactpersoon->getObject();
                $username = $contactData['username'] ?? null;
                
                $userInfo = [
                    'hasUser' => !empty($username),
                    'username' => $username,
                    'groups' => []
                ];

                if (!empty($username)) {
                    $user = $this->userManager->get($username);
                    if ($user) {
                        $userGroups = $this->groupManager->getUserGroups($user);
                        $userInfo['groups'] = array_map(function($group) {
                            return $group->getGID();
                        }, $userGroups);
                    }
                }

                $enhancedContactpersonen[] = [
                    'id' => $contactpersoon->getId(),
                    'uuid' => $contactpersoon->getUuid(),
                    'data' => $contactData,
                    'user' => $userInfo
                ];
            }

            return new JSONResponse([
                'success' => true,
                'contactpersonen' => $enhancedContactpersonen,
                'total' => $contactpersonen['total'] ?? count($enhancedContactpersonen)
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to get contactpersonen: ' . $e->getMessage(), [
                'organisationId' => $organisationId,
                'exception' => $e
            ]);

            return new JSONResponse([
                'success' => false,
                'message' => 'Failed to retrieve contactpersonen: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Convert a contactpersoon to a user account
     *
     * @param string $contactpersoonId The contactpersoon ID
     *
     * @return JSONResponse Result of user creation
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function convertToUser(string $contactpersoonId): JSONResponse
    {
        try {
            // Get object service
            $objectService = \OC::$server->get('OCA\OpenRegister\Service\ObjectService');
            
            // First try to find the object by UUID without register/schema constraints
            // ObjectService can find objects via ObjectEntityMapper using UUID
            $contactpersoonObject = $objectService->findByUuid($contactpersoonId);
            
            if (!$contactpersoonObject) {
                return new JSONResponse([
                    'success' => false,
                    'message' => 'Contactpersoon not found'
                ], 404);
            }

            // Get register and schema from the found object
            $registerId = $contactpersoonObject->getRegister();
            $schemaId = $contactpersoonObject->getSchema();
            
            $this->logger->info('ContactpersonenController: Found contactpersoon object', [
                'contactpersoonId' => $contactpersoonId,
                'registerId' => $registerId,
                'schemaId' => $schemaId
            ]);

            $contactData = $contactpersoonObject->getObject();
            
            // Check if user already exists
            if (!empty($contactData['username'])) {
                return new JSONResponse([
                    'success' => false,
                    'message' => 'Contactpersoon already has a user account'
                ], 400);
            }

            // Create user account using ContactPersonHandler
            $user = $this->contactPersonHandler->createUserAccount($contactpersoonObject);
            
            if (!$user) {
                return new JSONResponse([
                    'success' => false,
                    'message' => 'Failed to create user account'
                ], 500);
            }

            // Ensure groups are assigned based on organization type
            // This is a safety check in case the createUserAccount didn't assign groups properly
            $contactData = $contactpersoonObject->getObject();
            $organizationId = $contactData['organisatie'] ?? $contactData['organisation'] ?? '';
            
            if (!empty($organizationId)) {
                $this->logger->info('ContactpersonenController: Ensuring groups are assigned based on organization type', [
                    'contactpersoonId' => $contactpersoonId,
                    'username' => $user->getUID(),
                    'organizationId' => $organizationId
                ]);
                
                // Call the ContactPersonHandler to update groups based on contact data
                $this->contactPersonHandler->updateUserGroupsFromContactData($user->getUID(), $contactData);
            }

            // Update the contactpersoon object with the username
            $contactData['username'] = $user->getUID();
            $contactpersoonObject->setObject($contactData);

            // Save the updated contactpersoon object
            $objectService->saveObject(
                object: $contactpersoonObject,
                register: $registerId,
                schema: $schemaId,
                rbac: false,
                multi: false
            );

            $this->logger->info('ContactpersonenController: Updated contactpersoon with username', [
                'contactpersoonId' => $contactpersoonId,
                'username' => $user->getUID()
            ]);

            // Get user groups to include in response
            $userGroups = $this->groupManager->getUserGroups($user);
            $softwareCatalogGroups = ['gebruik-beheerder', 'aanbod-beheerder', 'gebruik-raadpleger'];
            $userGroupNames = [];
            
            foreach ($userGroups as $group) {
                $groupId = $group->getGID();
                if (in_array($groupId, $softwareCatalogGroups)) {
                    $userGroupNames[] = $groupId;
                }
            }

            // Add groups to the contactpersoon data for frontend
            $updatedContactData = $contactpersoonObject->getObject();
            $updatedContactData['groups'] = $userGroupNames;

            // Return the updated contactpersoon object with groups
            return new JSONResponse([
                'success' => true,
                'message' => 'User account created successfully',
                'username' => $user->getUID(),
                'contactpersoon' => array_merge($contactpersoonObject->jsonSerialize(), [
                    'groups' => $userGroupNames
                ])
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to convert contactpersoon to user: ' . $e->getMessage(), [
                'contactpersoonId' => $contactpersoonId,
                'exception' => $e
            ]);

            return new JSONResponse([
                'success' => false,
                'message' => 'Failed to create user account: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Change user password
     *
     * @param string $username    The username
     * @param string $newPassword The new password
     *
     * @return JSONResponse Result of password change
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function changePassword(string $username, string $newPassword): JSONResponse
    {
        try {
            $user = $this->userManager->get($username);
            
            if (!$user) {
                return new JSONResponse([
                    'success' => false,
                    'message' => 'User not found'
                ], 404);
            }

            // Validate password (basic validation)
            if (strlen($newPassword) < 8) {
                return new JSONResponse([
                    'success' => false,
                    'message' => 'Password must be at least 8 characters long'
                ], 400);
            }

            // Set new password
            $user->setPassword($newPassword);

            $this->logger->info('Password changed for user', [
                'username' => $username
            ]);

            return new JSONResponse([
                'success' => true,
                'message' => 'Password changed successfully'
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to change password: ' . $e->getMessage(), [
                'username' => $username,
                'exception' => $e
            ]);

            return new JSONResponse([
                'success' => false,
                'message' => 'Failed to change password: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update user groups
     *
     * @param string $username The username
     * @param array  $groups   Array of group names to assign
     *
     * @return JSONResponse Result of group update
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function updateUserGroups(string $username, array $groups = []): JSONResponse
    {
        try {
            $user = $this->userManager->get($username);
            
            if (!$user) {
                return new JSONResponse([
                    'success' => false,
                    'message' => 'User not found'
                ], 404);
            }

            // Get allowed software catalog groups
            $allowedGroups = ['gebruik-beheerder', 'aanbod-beheerder', 'gebruik-raadpleger'];
            
            // Filter to only allowed groups
            $validGroups = array_intersect($groups, $allowedGroups);

            // Get current user groups (only software catalog groups)
            $currentGroups = $this->groupManager->getUserGroups($user);
            $currentSoftwareCatalogGroups = [];
            
            foreach ($currentGroups as $group) {
                if (in_array($group->getGID(), $allowedGroups)) {
                    $currentSoftwareCatalogGroups[] = $group->getGID();
                }
            }

            // Remove user from groups they should no longer be in
            $groupsToRemove = array_diff($currentSoftwareCatalogGroups, $validGroups);
            foreach ($groupsToRemove as $groupName) {
                $group = $this->groupManager->get($groupName);
                if ($group && $group->inGroup($user)) {
                    $group->removeUser($user);
                    $this->logger->info('Removed user from group', [
                        'username' => $username,
                        'group' => $groupName
                    ]);
                }
            }

            // Add user to new groups (only if they exist)
            $groupsToAdd = array_diff($validGroups, $currentSoftwareCatalogGroups);
            foreach ($groupsToAdd as $groupName) {
                $group = $this->groupManager->get($groupName);
                if ($group) {
                    if (!$group->inGroup($user)) {
                        $group->addUser($user);
                        $this->logger->info('Added user to group', [
                            'username' => $username,
                            'group' => $groupName
                        ]);
                    }
                } else {
                    $this->logger->warning('Group does not exist, skipping', [
                        'username' => $username,
                        'group' => $groupName
                    ]);
                }
            }

            // Get updated groups
            $updatedGroups = $this->groupManager->getUserGroups($user);
            $updatedGroupNames = array_map(function($group) {
                return $group->getGID();
            }, $updatedGroups);

            return new JSONResponse([
                'success' => true,
                'message' => 'User groups updated successfully',
                'groups' => $updatedGroupNames
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to update user groups: ' . $e->getMessage(), [
                'username' => $username,
                'groups' => $groups,
                'exception' => $e
            ]);

            return new JSONResponse([
                'success' => false,
                'message' => 'Failed to update user groups: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get user information and available groups for a specific contactpersoon
     *
     * Returns user information including current groups and available groups
     * for a specific contactpersoon identified by UUID.
     *
     * @param string $contactpersoonId The contactpersoon UUID
     * @return JSONResponse JSON response containing user info and available groups
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function getUserInfo(string $contactpersoonId): JSONResponse
    {
        try {
            $this->logger->info('ContactpersonenController: Getting user info for contactpersoon', [
                'contactpersoonId' => $contactpersoonId
            ]);

            // Get contactpersoon from OpenRegister
            $objectService = \OC::$server->get('OCA\OpenRegister\Service\ObjectService');
            
            // First try to find the object by UUID without register/schema constraints
            $contactObject = $objectService->findByUuid($contactpersoonId);
            
            if (!$contactObject) {
                return new JSONResponse([
                    'success' => false,
                    'message' => 'Contactpersoon not found'
                ], 404);
            }

            $contactData = $contactObject->getObject();
            $username = $contactData['username'] ?? null;
            
            $userInfo = [
                'hasUser' => !empty($username),
                'username' => $username,
                'groups' => []
            ];

            // If user exists, get their current groups
            if (!empty($username)) {
                $user = $this->userManager->get($username);
                if ($user) {
                    $userGroups = $this->groupManager->getUserGroups($user);
                    $softwareCatalogGroups = ['gebruik-beheerder', 'aanbod-beheerder', 'gebruik-raadpleger'];
                    
                    foreach ($userGroups as $group) {
                        $groupId = $group->getGID();
                        if (in_array($groupId, $softwareCatalogGroups)) {
                            $userInfo['groups'][] = $groupId;
                        }
                    }
                }
            }

            // Available groups (same as getAvailableGroups but inline for consistency)
            $availableGroups = [
                [
                    'id' => 'gebruik-beheerder',
                    'name' => 'Gebruik Beheerder',
                    'description' => 'Manages software usage and procurement'
                ],
                [
                    'id' => 'aanbod-beheerder',
                    'name' => 'Aanbod Beheerder',
                    'description' => 'Manages software offerings and catalog content'
                ],
                [
                    'id' => 'gebruik-raadpleger',
                    'name' => 'Gebruik Raadpleger',
                    'description' => 'Views software usage and procurement data'
                ]
            ];

            // Check which groups actually exist
            $existingGroups = [];
            foreach ($availableGroups as $groupInfo) {
                $group = $this->groupManager->get($groupInfo['id']);
                if ($group) {
                    $existingGroups[] = $groupInfo;
                }
            }

            return new JSONResponse([
                'success' => true,
                'userInfo' => $userInfo,
                'availableGroups' => $existingGroups
            ]);

        } catch (\Exception $e) {
            $this->logger->error('ContactpersonenController: Failed to get user info', [
                'contactpersoonId' => $contactpersoonId,
                'exception' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);

            return new JSONResponse([
                'success' => false,
                'message' => 'Failed to get user info: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get available software catalog groups
     *
     * @return JSONResponse List of available groups
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function getAvailableGroups(): JSONResponse
    {
        try {
            $availableGroups = [
                [
                    'id' => 'gebruik-beheerder',
                    'name' => 'Gebruik Beheerder',
                    'description' => 'Manages software usage and procurement'
                ],
                [
                    'id' => 'aanbod-beheerder',
                    'name' => 'Aanbod Beheerder',
                    'description' => 'Manages software offerings and catalog content'
                ],
                [
                    'id' => 'gebruik-raadpleger',
                    'name' => 'Gebruik Raadpleger',
                    'description' => 'Views software usage and procurement data'
                ]
            ];

            // Check which groups actually exist
            $existingGroups = [];
            foreach ($availableGroups as $groupInfo) {
                $group = $this->groupManager->get($groupInfo['id']);
                if ($group) {
                    $existingGroups[] = $groupInfo;
                }
            }

            return new JSONResponse([
                'success' => true,
                'groups' => $existingGroups
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to get available groups: ' . $e->getMessage(), [
                'exception' => $e
            ]);

            return new JSONResponse([
                'success' => false,
                'message' => 'Failed to retrieve available groups: ' . $e->getMessage()
            ], 500);
        }
    }
}
