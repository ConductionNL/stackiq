<?php

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Controller;

use OCA\SoftwareCatalog\Service\SettingsService;
use OCA\SoftwareCatalog\Service\SoftwareCatalogue\ContactPersonHandler;
use OCA\SoftwareCatalog\Service\ContactpersoonService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IGroupManager;
use OCP\IUserSession;
use OCP\Security\ISecureRandom;
use Psr\Container\ContainerInterface;
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
     * User session for getting current user
     *
     * @var IUserSession
     */
    private IUserSession $userSession;

    /**
     * Container for dependency injection
     *
     * @var ContainerInterface
     */
    private ContainerInterface $container;

    /**
     * Contactpersoon service for business logic
     */
    private ContactpersoonService $contactpersoonService;

    /**
     * Constructor
     *
     * @param string                $appName              The app name
     * @param IRequest              $request              The request object
     * @param SettingsService       $settingsService      Settings service
     * @param ContactPersonHandler  $contactPersonHandler Contact person handler
     * @param ContactpersoonService $contactpersoonService Contactpersoon service
     * @param IUserManager          $userManager          User manager
     * @param IGroupManager         $groupManager         Group manager
     * @param IUserSession          $userSession          User session
     * @param ContainerInterface    $container            Container for DI
     * @param ISecureRandom         $secureRandom         Secure random generator
     * @param LoggerInterface       $logger               Logger instance
     */
    public function __construct(
        string $appName,
        IRequest $request,
        SettingsService $settingsService,
        ContactPersonHandler $contactPersonHandler,
        ContactpersoonService $contactpersoonService,
        IUserManager $userManager,
        IGroupManager $groupManager,
        IUserSession $userSession,
        ContainerInterface $container,
        ISecureRandom $secureRandom,
        LoggerInterface $logger
    ) {
        parent::__construct($appName, $request);
        $this->settingsService = $settingsService;
        $this->contactPersonHandler = $contactPersonHandler;
        $this->contactpersoonService = $contactpersoonService;
        $this->userManager = $userManager;
        $this->groupManager = $groupManager;
        $this->userSession = $userSession;
        $this->container = $container;
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
                    'groups' => [],
                    'disabled' => false
                ];

                if (!empty($username)) {
                    $user = $this->userManager->get($username);
                    if ($user) {
                        $userGroups = $this->groupManager->getUserGroups($user);
                        $userInfo['groups'] = array_map(function($group) {
                            return $group->getGID();
                        }, $userGroups);

                        // Get the disabled status from Nextcloud
                        $userInfo['disabled'] = !$user->isEnabled();
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

            // Find the contactpersoon object
            $contactpersoonObject = $objectService->find(
                id: $contactpersoonId,
                register: 'voorzieningen',
                schema: 'contactpersoon',
                _rbac: false,
                _multitenancy: false
            );

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
                $this->contactPersonHandler->updateUserGroupsFromContactData($user, $contactData);
            }

            // Link user to organization entity
            $this->contactPersonHandler->addUserToOrganizationEntity($contactpersoonObject, $user->getUID(), $organizationId);

            // Update the contactpersoon object with the username
            $contactData['username'] = $user->getUID();

            // Ensure string fields are properly typed (fixes data stored with incorrect types)
            $stringFields = ['voornaam', 'tussenvoegsel', 'achternaam', 'functie', 'telefoonnummer', 'email', 'e-mailadres'];
            foreach ($stringFields as $field) {
                if (isset($contactData[$field]) && !is_string($contactData[$field])) {
                    $contactData[$field] = (string)$contactData[$field];
                }
            }

            // Handle organisatie field - if it's a string UUID, convert to null to avoid validation errors
            // The relationship is maintained through the organisation entity's users array
            if (isset($contactData['organisatie']) && is_string($contactData['organisatie'])) {
                $this->logger->info('ContactpersonenController: Converting organisatie string to null for validation', [
                    'originalValue' => $contactData['organisatie']
                ]);
                $contactData['organisatie'] = null;
            }
            if (isset($contactData['organisation']) && is_string($contactData['organisation'])) {
                $contactData['organisation'] = null;
            }

            $contactpersoonObject->setObject($contactData);

            // Debug logging to understand data types before save
            $this->logger->info('ContactpersonenController: About to save contactpersoon object', [
                'contactpersoonId' => $contactpersoonId,
                'achternaamValue' => $contactData['achternaam'] ?? 'not set',
                'achternaamType' => isset($contactData['achternaam']) ? gettype($contactData['achternaam']) : 'not set',
                'registerId' => $registerId,
                'schemaId' => $schemaId
            ]);

            // Save using ObjectEntityMapper directly to bypass schema validation
            // This avoids "Unresolved reference" errors when schema references can't be resolved
            $objectMapper = $this->container->get('OCA\OpenRegister\Db\ObjectEntityMapper');
            $objectMapper->update($contactpersoonObject);

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
            if (strlen($newPassword) < 10) {
                return new JSONResponse([
                    'success' => false,
                    'message' => 'Password must be at least 10 characters long'
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
     * Get contact persons for an organization with user details
     *
     * Returns all contact persons linked to a specific organization,
     * with their corresponding Nextcloud user details spliced in.
     *
     * @param string $organizationUuid The organization UUID
     * @return JSONResponse JSON response containing contact persons with user details
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function getContactPersonsWithUserDetailsForOrganization(string $organizationUuid): JSONResponse
    {
        try {
            $this->logger->info('ContactpersonenController: Getting contact persons with user details for organization', [
                'organizationUuid' => $organizationUuid
            ]);

            // Validate organization UUID
            if (empty($organizationUuid)) {
                return new JSONResponse([
                    'success' => false,
                    'message' => 'Organization UUID is required'
                ], 400);
            }

            // Get contact persons with user details using the service
            $contactPersons = $this->contactpersoonService->getContactPersonsWithUserDetailsForOrganization($organizationUuid);

            // Convert objects to arrays for JSON response
            $contactPersonsData = [];
            foreach ($contactPersons as $contactPerson) {
                $contactPersonsData[] = [
                    'id' => $contactPerson->getId(),
                    'uuid' => $contactPerson->getUuid(),
                    'object' => $contactPerson->getObject(),
                    'register' => $contactPerson->getRegister(),
                    'schema' => $contactPerson->getSchema(),
                    'created' => $contactPerson->getCreated(),
                    'modified' => $contactPerson->getModified()
                ];
            }

            $this->logger->info('ContactpersonenController: Successfully retrieved contact persons with user details', [
                'organizationUuid' => $organizationUuid,
                'contactPersonCount' => count($contactPersonsData)
            ]);

            return new JSONResponse([
                'success' => true,
                'data' => $contactPersonsData,
                'count' => count($contactPersonsData),
                'organizationUuid' => $organizationUuid
            ]);

        } catch (\Exception $e) {
            $this->logger->error('ContactpersonenController: Failed to get contact persons with user details for organization', [
                'organizationUuid' => $organizationUuid,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return new JSONResponse([
                'success' => false,
                'message' => 'Failed to get contact persons with user details: ' . $e->getMessage()
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

            // First try to find the object by UUID
            $contactObject = $objectService->find(
                id: $contactpersoonId,
                register: 'voorzieningen',
                schema: 'contactpersoon',
                _rbac: false,
                _multitenancy: false
            );

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
                'groups' => [],
                'disabled' => false
            ];

            // If user exists, get their current groups and disabled status
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

                    // Get the disabled status from Nextcloud
                    $userInfo['disabled'] = !$user->isEnabled();
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

    /**
     * Disable a user account
     * @NoAdminRequired
     * @NoCSRFRequired
     * @param string $contactpersoonId
     * @return JSONResponse
     */
    public function disableUser(string $contactpersoonId): JSONResponse {
        try {
            // Delegate to service
            $this->contactpersoonService->disableUserForContactpersoon($contactpersoonId);

            $this->logger->info('User account disabled', [
                'contactpersoonId' => $contactpersoonId,
                'disabled_by' => $this->userId
            ]);
            return new JSONResponse(['success' => true, 'message' => 'User account disabled successfully']);

        } catch (\Exception $e) {
            $this->logger->error('Failed to disable user account', [
                'contactpersoonId' => $contactpersoonId,
                'error' => $e->getMessage()
            ]);
            return new JSONResponse(['success' => false, 'message' => 'Failed to disable user account: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Enable a user account
     * @NoAdminRequired
     * @NoCSRFRequired
     * @param string $contactpersoonId
     * @return JSONResponse
     */
    public function enableUser(string $contactpersoonId): JSONResponse {
        try {
            // Delegate to service
            $this->contactpersoonService->enableUserForContactpersoon($contactpersoonId);

            $this->logger->info('User account enabled', [
                'contactpersoonId' => $contactpersoonId,
                'enabled_by' => $this->userId
            ]);
            return new JSONResponse(['success' => true, 'message' => 'User account enabled successfully']);

        } catch (\Exception $e) {
            $this->logger->error('Failed to enable user account', [
                'contactpersoonId' => $contactpersoonId,
                'error' => $e->getMessage()
            ]);
            return new JSONResponse(['success' => false, 'message' => 'Failed to enable user account: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Test endpoint to debug bulk user info
     * @NoAdminRequired
     * @NoCSRFRequired
     * @return JSONResponse
     */
    public function testBulkUserInfo(): JSONResponse {
        try {
            $this->logger->info('testBulkUserInfo called', [
                'objectService' => $this->objectService ? 'available' : 'null',
                'userManager' => $this->userManager ? 'available' : 'null',
                'groupManager' => $this->groupManager ? 'available' : 'null'
            ]);

            return new JSONResponse([
                'success' => true,
                'message' => 'Test endpoint working',
                'services' => [
                    'objectService' => $this->objectService ? 'available' : 'null',
                    'userManager' => $this->userManager ? 'available' : 'null',
                    'groupManager' => $this->groupManager ? 'available' : 'null'
                ]
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Test endpoint error', ['error' => $e->getMessage()]);
            return new JSONResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Get user info for multiple contactpersonen in one request
     * @NoAdminRequired
     * @NoCSRFRequired
     * @return JSONResponse
     */
    public function getBulkUserInfo(): JSONResponse {
        try {
            $input = json_decode(file_get_contents('php://input'), true);
            $contactpersoonIds = $input['contactpersoonIds'] ?? [];

            $this->logger->info('Controller: getBulkUserInfo called', [
                'input' => $input,
                'contactpersoonIds' => $contactpersoonIds
            ]);

            if (empty($contactpersoonIds) || !is_array($contactpersoonIds)) {
                return new JSONResponse(['success' => false, 'message' => 'No contactpersoon IDs provided'], 400);
            }

            // Delegate to service
            $bulkUserInfo = $this->contactpersoonService->getBulkUserInfo($contactpersoonIds);

            return new JSONResponse([
                'success' => true,
                'userInfo' => $bulkUserInfo
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Controller: Failed to get bulk user info', [
                'error' => $e->getMessage()
            ]);
            return new JSONResponse([
                'success' => false,
                'message' => 'Failed to get bulk user info: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get current user profile information
     *
     * Returns the current logged-in user's profile including:
     * - email, firstName, middleName, lastName, functie
     * - organisations.active (the currently active organisation)
     * - organisations.all (all organisations the user belongs to)
     *
     * @return JSONResponse The user profile data
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function getMe(): JSONResponse
    {
        try {
            // Get current user from session
            $user = $this->userSession->getUser();
            if ($user === null) {
                return new JSONResponse([
                    'success' => false,
                    'message' => 'Not authenticated'
                ], 401);
            }

            $userId = $user->getUID();
            $userEmail = $user->getEMailAddress() ?? $userId;

            $this->logger->info('ContactpersonenController: Getting /me data for user', [
                'userId' => $userId,
                'userEmail' => $userEmail
            ]);

            // Initialize response with user data from Nextcloud
            $response = [
                'email' => $userEmail,
                'firstName' => '',
                'middleName' => '',
                'lastName' => '',
                'functie' => '',
                'organisations' => [
                    'active' => null,
                    'all' => []
                ]
            ];

            // Try to get contactpersoon data for additional profile info
            try {
                $objectService = \OC::$server->get('OCA\OpenRegister\Service\ObjectService');

                // Search for contactpersoon by username (which is the email)
                $searchParams = [
                    'username' => $userId,
                    '_limit' => 1,
                    '_schema' => 'contactpersoon'
                ];

                $contactpersonen = $objectService->searchObjectsPaginated($searchParams);

                if (!empty($contactpersonen['results'])) {
                    $contactpersoon = $contactpersonen['results'][0];
                    $contactData = $contactpersoon->getObject();

                    // Extract name parts
                    $response['firstName'] = $contactData['voornaam'] ?? $contactData['firstName'] ?? '';
                    $response['middleName'] = $contactData['tussenvoegsel'] ?? $contactData['middleName'] ?? '';
                    $response['lastName'] = $contactData['achternaam'] ?? $contactData['lastName'] ?? '';
                    $response['functie'] = $contactData['functie'] ?? '';

                    // If email not set, try from contact data
                    if (empty($response['email'])) {
                        $response['email'] = $contactData['e-mailadres'] ?? $contactData['email'] ?? $userEmail;
                    }
                }
            } catch (\Exception $e) {
                $this->logger->debug('ContactpersonenController: Could not find contactpersoon for user', [
                    'userId' => $userId,
                    'error' => $e->getMessage()
                ]);
            }

            // Get organisation data from OpenRegister
            try {
                $organisationService = $this->container->get('OCA\OpenRegister\Service\OrganisationService');

                // Get active organisation
                $activeOrg = $organisationService->getActiveOrganisation();
                if ($activeOrg !== null) {
                    $response['organisations']['active'] = [
                        'uuid' => $activeOrg->getUuid(),
                        'naam' => $activeOrg->getName(),
                        'id' => (string)$activeOrg->getId(),
                        'slug' => $activeOrg->getSlug() ?? $this->createSlug($activeOrg->getName())
                    ];
                }

                // Get all user organisations
                $userOrgs = $organisationService->getUserOrganisations();
                foreach ($userOrgs as $org) {
                    $response['organisations']['all'][] = [
                        'uuid' => $org->getUuid(),
                        'naam' => $org->getName(),
                        'id' => (string)$org->getId(),
                        'slug' => $org->getSlug() ?? $this->createSlug($org->getName())
                    ];
                }
            } catch (\Exception $e) {
                $this->logger->warning('ContactpersonenController: Could not get organisation data', [
                    'userId' => $userId,
                    'error' => $e->getMessage()
                ]);
            }

            return new JSONResponse($response);

        } catch (\Exception $e) {
            $this->logger->error('ContactpersonenController: Failed to get /me data', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return new JSONResponse([
                'success' => false,
                'message' => 'Failed to get user profile: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create a URL-friendly slug from a name
     *
     * @param string $name The name to convert
     *
     * @return string The slug
     */
    private function createSlug(string $name): string
    {
        // Convert to lowercase
        $slug = strtolower($name);
        // Replace spaces and special chars with hyphens
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        // Remove leading/trailing hyphens
        $slug = trim($slug, '-');
        return $slug;
    }
}
