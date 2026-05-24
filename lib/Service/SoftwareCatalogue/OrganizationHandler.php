<?php

/**
 * Organization Handler for Software Catalog.
 *
 * This handler manages organization-specific operations including group creation,
 * organization processing, and hierarchy management.
 *
 * @category Handler
 * @package  OCA\SoftwareCatalog\Service\SoftwareCatalogue
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @version  GIT: <git_id>
 * @link     https://github.com/ConductionNL/SoftwareCatalog
 *
 * @spec openspec/changes/retrofit-2026-05-24-annotate-softwarecatalog/tasks.md#task-9
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Service\SoftwareCatalogue;

use OCP\IGroupManager;
use OCP\IGroup;
use OCP\IUserManager;
use OCP\IUser;
use Psr\Container\ContainerInterface;
use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;

/**
 * Handler for organization-related operations.
 *
 * @category Handler
 * @package  OCA\SoftwareCatalog\Service\SoftwareCatalogue
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @version  GIT: <git_id>
 * @link     https://github.com/ConductionNL/SoftwareCatalog
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 * @SuppressWarnings(PHPMD.LongVariable)
 * @SuppressWarnings(PHPMD.ShortVariable)
 * @SuppressWarnings(PHPMD.MissingImport)
 * @SuppressWarnings(PHPMD.UnusedLocalVariable)
 * @SuppressWarnings(PHPMD.UnusedPrivateMethod)
 * @SuppressWarnings(PHPMD.Superglobals)
 * @SuppressWarnings(PHPMD.CamelCaseVariableName)
 * @SuppressWarnings(PHPMD.CamelCaseParameterName)
 */
class OrganizationHandler
{
    /**
     * OrganizationHandler constructor.
     *
     * @param IGroupManager      $_groupManager Group manager interface
     * @param IUserManager       $_userManager  User manager interface
     * @param ContainerInterface $_container    Container interface
     * @param IAppManager        $_appManager   App manager interface
     * @param LoggerInterface    $_logger       Logger interface
     */
    public function __construct(
        private readonly IGroupManager $_groupManager,
        private readonly IUserManager $_userManager,
        private readonly ContainerInterface $_container,
        private readonly IAppManager $_appManager,
        private readonly LoggerInterface $_logger,
    ) {
    }//end __construct()

    /**
     * Gets the OpenRegister ObjectService if available.
     *
     * @return \OCA\OpenRegister\Service\ObjectService|null ObjectService instance or null
     *
     * @throws \RuntimeException If service is not available
     */
    private function getObjectService(): ?\OCA\OpenRegister\Service\ObjectService
    {
        if (in_array(needle: 'openregister', haystack: $this->_appManager->getInstalledApps()) === true) {
            return $this->_container->get('OCA\OpenRegister\Service\ObjectService');
        }

        throw new \RuntimeException('OpenRegister service is not available.');
    }//end getObjectService()

    /**
     * Processes organization groups and ensures proper group assignment.
     *
     * @param object $organizationObject The organization object to process
     *
     * @return bool True if processing was successful
     *
     * @throws \Exception If processing fails
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-softwarecatalog/tasks.md#task-9
     */
    public function processOrganization(object $organizationObject): bool
    {
        try {
            $this->_logger->info(
                    'Processing organization object',
                    [
                        'objectId' => $organizationObject->getId(),
                    ]
                    );

            $objectData = $organizationObject->getObject();

            // Check if organization is active (beoordeling = "actief" or "Actief").
            $beoordeling = strtolower($objectData['beoordeling'] ?? '');
            if ($beoordeling !== 'actief') {
                $this->_logger->info(
                    'Organization not active, skipping processing',
                    [
                        'organizationId' => $organizationObject->getId(),
                        'beoordeling'    => $beoordeling,
                    ]
                );
                // Not an error, just not ready for processing.
                return true;
            }

            // Ensure organization has a unique group.
            $groupId = $this->ensureOrganizationGroup(
                organizationObject: $organizationObject,
                objectData: $objectData
            );

            if ($groupId !== null) {
                $this->_logger->info(
                    'Successfully processed organization group',
                    [
                        'organizationId' => $organizationObject->getId(),
                        'groupId'        => $groupId,
                    ]
                );
            }

            return true;
        } catch (\Exception $e) {
            $this->_logger->error(
                'Failed to process organization object: '.$e->getMessage(),
                [
                    'exception' => $e,
                    'objectId'  => $organizationObject->getId() ?? 'unknown',
                ]
            );
            throw $e;
        }//end try
    }//end processOrganization()

    /**
     * Ensures an organization has a unique group and returns the group ID.
     *
     * @param object $organizationObject The organization object
     * @param array  $objectData         The organization data
     *
     * @return string|null The group ID or null if failed
     */
    public function ensureOrganizationGroup(object $organizationObject, array &$objectData): ?string
    {
        $groupProperty = $objectData['group'] ?? '';

        if (empty($groupProperty) === true) {
            // Create group with organization name.
            $organizationName = $objectData['naam'] ?? $objectData['name'] ?? 'Organization';
            $groupName        = $this->sanitizeGroupName(name: $organizationName);

            // Ensure group name is unique.
            $groupName = $this->ensureUniqueGroupName(baseName: $groupName);

            $group = $this->createGroupIfNotExists(groupName: $groupName);

            if ($group !== null) {
                // Set the group ID in the organization object.
                $objectData['group'] = $group->getGID();
                $organizationObject->setObject($objectData);

                // Save the updated organization with correct register/schema IDs.
                $objectService        = $this->getObjectService();
                $settingsService      = $this->_container->get('OCA\SoftwareCatalog\Service\SettingsService');
                $registerId           = $settingsService->getVoorzieningenRegisterId();
                $organizationSchemaId = $settingsService->getSchemaIdForObjectType('organisatie');

                if ($registerId === null || $organizationSchemaId === null) {
                    $this->_logger->warning(
                        'Missing register or schema ID for organization, using fallback save method',
                        [
                            'registerId'           => $registerId,
                            'organizationSchemaId' => $organizationSchemaId,
                        ]
                    );
                    $objectService->saveObject($organizationObject);
                }

                if ($registerId !== null && $organizationSchemaId !== null) {
                    $objectService->saveObject(
                        object: $organizationObject,
                        extend: [],
                        register: (int) $registerId,
                        schema: (int) $organizationSchemaId,
                        uuid: $organizationObject->getUuid()
                    );
                }

                $this->_logger->info(
                    'Created and assigned unique group to organization',
                    [
                        'organizationId' => $organizationObject->getId(),
                        'groupName'      => $groupName,
                        'groupId'        => $group->getGID(),
                    ]
                );

                return $group->getGID();
            }//end if
        }//end if

        if (empty($groupProperty) === false) {
            return $groupProperty;
        }

        return null;
    }//end ensureOrganizationGroup()

    /**
     * Ensures a group name is unique by appending a counter if necessary.
     *
     * @param string $baseName The base group name
     *
     * @return string A unique group name
     */
    private function ensureUniqueGroupName(string $baseName): string
    {
        $groupName = $baseName;
        $counter   = 1;

        while ($this->_groupManager->get($groupName) !== null) {
            $groupName = $baseName.'_'.$counter;
            $counter++;
        }

        return $groupName;
    }//end ensureUniqueGroupName()

    /**
     * Creates a group if it doesn't exist.
     *
     * @param string $groupName The group name to create
     *
     * @return IGroup|null The created or existing group
     */
    public function createGroupIfNotExists(string $groupName): ?IGroup
    {
        $group = $this->_groupManager->get($groupName);

        if ($group === null) {
            try {
                $group = $this->_groupManager->createGroup($groupName);
                $this->_logger->info(
                    'Created new group',
                    [
                        'groupName' => $groupName,
                    ]
                );
            } catch (\Exception $e) {
                $this->_logger->error(
                    'Failed to create group: '.$e->getMessage(),
                    [
                        'groupName' => $groupName,
                        'exception' => $e,
                    ]
                );
                return null;
            }
        }

        return $group;
    }//end createGroupIfNotExists()

    /**
     * Sanitizes a group name for safe usage.
     *
     * @param string $name The name to sanitize
     *
     * @return string The sanitized group name
     */
    public function sanitizeGroupName(string $name): string
    {
        // Convert to lowercase and replace special characters.
        $sanitized = strtolower(trim($name));
        $sanitized = preg_replace('/[^a-z0-9._-]/', '_', $sanitized);
        $sanitized = preg_replace('/_{2,}/', '_', $sanitized);
        $sanitized = trim($sanitized, '_');

        // Ensure it's not empty.
        if (empty($sanitized) === true) {
            $sanitized = 'organization_'.time();
        }

        return $sanitized;
    }//end sanitizeGroupName()

    /**
     * Processes contactpersonen from organization data into Contactgegevens objects.
     *
     * @param object $organizationObject The organization object
     *
     * @return array Array of created or updated contactgegevens objects
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-softwarecatalog/tasks.md#task-9
     */
    public function processContactpersonen(object $organizationObject): array
    {
        try {
            $objectData      = $organizationObject->getObject();
            $contactpersonen = $objectData['contactpersonen'] ?? [];
            // Get the actual UUID from object data instead of database ID.
            $organizationUuid  = $objectData['id'] ?? $organizationObject->getId();
            $processedContacts = [];

            if (is_array($contactpersonen) === false || empty($contactpersonen) === true) {
                $this->_logger->info(
                        'No contactpersonen found in organization',
                        [
                            'organizationId' => $organizationUuid,
                        ]
                        );
                return $processedContacts;
            }

            $objectService = $this->getObjectService();

            foreach ($contactpersonen as $index => $contactpersoon) {
                try {
                    // Get the contactgegevens schema ID from settings.
                    $settingsService         = $this->_container->get('OCA\SoftwareCatalog\Service\SettingsService');
                    $contactgegevensSchemaId = $settingsService->getSchemaIdForObjectType('contactgegevens');
                    $registerId = $settingsService->getVoorzieningenRegisterId();

                    if ($registerId === null) {
                        throw new \Exception('Voorzieningen register ID not configured');
                    }

                    $contactEmail = $contactpersoon['email'] ?? $contactpersoon['e-mailadres'] ?? '';

                    // Check if contactgegevens object already exists for this email + organization.
                    $existingContactgegevens = $this->findExistingContactgegevens(
                        email: $contactEmail,
                        organizationUuid: $organizationUuid,
                        objectService: $objectService,
                        registerId: $registerId,
                        contactgegevensSchemaId: $contactgegevensSchemaId
                    );

                        $logMessage = 'Creating new contactgegevens object';
                    if ($existingContactgegevens !== null) {
                    }

                        $existingId = null;
                    if ($existingContactgegevens !== null) {
                    }

                    $this->_logger->info(
                        $logMessage,
                        [
                            'contactgegevensSchemaId' => $contactgegevensSchemaId,
                            'organizationUuid'        => $organizationUuid,
                            'contactpersoonIndex'     => $index,
                            'email'                   => $contactEmail,
                            'existingId'              => $existingId,
                        ]
                    );

                    // Generate title from name components.
                    $titleParts = array_filter(
                            [
                                $contactpersoon['voornaam'] ?? '',
                                $contactpersoon['tussenvoegsel'] ?? '',
                                $contactpersoon['achternaam'] ?? '',
                            ]
                            );

                        $title = $contactpersoon['email'] ?? 'Contact Person';
                    if (empty($titleParts) === false) {
                    }

                    // Create contactgegevens object with proper schema.
                    $contactFunctie      = $contactpersoon['functie'] ?? '';
                    $contactRoles        = $this->mapFunctieToRoles(
                        functie: $contactFunctie,
                        isFirstContact: ($index === 0)
                    );
                    $contactgegevensData = [
                        // Required by OpenRegister.
                        'title'         => $title,
                        'voornaam'      => $contactpersoon['voornaam'] ?? '',
                        'tussenvoegsel' => $contactpersoon['tussenvoegsel'] ?? '',
                        'achternaam'    => $contactpersoon['achternaam'] ?? '',
                        'telefoon'      => $contactpersoon['telefoon'] ?? '',
                        'email'         => $contactEmail,
                        'functie'       => $contactFunctie,
                        // Link to organization.
                        'organisation'  => $organizationUuid,
                        'roles'         => $contactRoles,
                        // Will be set when user is created.
                        'username'      => '',
                    ];

                    // If updating existing contactgegevens, preserve the username if it exists.
                    if ($existingContactgegevens !== null) {
                        $existingData = $existingContactgegevens->getObject();
                        $contactgegevensData['username'] = $existingData['username'] ?? '';
                    }

                    // Create or update the contactgegevens object via ObjectService.
                    // Create new contactgegevens object.
                    $contactgegevensObject = $objectService->saveObject(
                        object: $contactgegevensData,
                        extend: [],
                        register: $registerId,
                        schema: $contactgegevensSchemaId
                    );
                    if ($existingContactgegevens !== null) {
                        // Update existing contactgegevens object.
                        $contactgegevensObject = $objectService->saveObject(
                            object: $contactgegevensData,
                            extend: [],
                            register: $registerId,
                            schema: $contactgegevensSchemaId,
                            uuid: $existingContactgegevens->getUuid()
                        );
                    }

                    if ($contactgegevensObject !== null) {
                        $processedContacts[] = $contactgegevensObject;

                        $actionLogMessage = 'Created new contactgegevens from contactpersoon';
                        $actionValue      = 'create';
                        if ($existingContactgegevens !== null) {
                            $actionLogMessage = 'Updated existing contactgegevens from contactpersoon';
                            $actionValue      = 'update';
                        }

                        $this->_logger->info(
                            $actionLogMessage,
                            [
                                'organizationId'      => $organizationUuid,
                                'contactgegevensId'   => $contactgegevensObject->getId(),
                                'contactpersoonIndex' => $index,
                                'email'               => $contactgegevensData['email'],
                                'action'              => $actionValue,
                            ]
                        );
                    }//end if
                } catch (\Exception $e) {
                    $this->_logger->error(
                        'Failed to process contactpersoon: '.$e->getMessage(),
                        [
                            'organizationId'      => $organizationUuid,
                            'contactpersoonIndex' => $index,
                            'contactpersoon'      => $contactpersoon,
                            'exception'           => $e,
                        ]
                    );
                }//end try
            }//end foreach

            return $processedContacts;
        } catch (\Exception $e) {
            $this->_logger->error(
                'Failed to process contactpersonen: '.$e->getMessage(),
                [
                    'organizationId' => $organizationObject->getId(),
                    'exception'      => $e,
                ]
            );
            return [];
        }//end try
    }//end processContactpersonen()

    /**
     * Finds existing contactgegevens object for a given email and organization.
     *
     * @param string                                  $email                   The email address to search for
     * @param string                                  $organizationUuid        The organization UUID
     * @param \OCA\OpenRegister\Service\ObjectService $objectService           The object service
     * @param int                                     $registerId              The register ID
     * @param int                                     $contactgegevensSchemaId The contactgegevens schema ID
     *
     * @return object|null The existing contactgegevens object or null if not found
     */
    private function findExistingContactgegevens(
        string $email,
        string $organizationUuid,
        \OCA\OpenRegister\Service\ObjectService $objectService,
        int $registerId,
        int $contactgegevensSchemaId
    ): ?object {
        try {
            if (empty($email) === true || empty($organizationUuid) === true) {
                return null;
            }

            // Search for existing contactgegevens with this email and organization.
            $searchFilters = [
                'email'        => $email,
                'organisation' => $organizationUuid,
            ];

            $existingObjects = $objectService->findAll(
                config: [
                    'filters'   => $searchFilters,
                    '_register' => $registerId,
                    '_schema'   => $contactgegevensSchemaId,
                ]
            );

            if (empty($existingObjects) === false) {
                $this->_logger->info(
                    'Found existing contactgegevens object',
                    [
                        'email'            => $email,
                        'organizationUuid' => $organizationUuid,
                        'existingId'       => $existingObjects[0]->getUuid(),
                        'totalFound'       => count($existingObjects),
                    ]
                );
                // Return the first match.
                return $existingObjects[0];
            }

            return null;
        } catch (\Exception $e) {
            $this->_logger->error(
                'Failed to find existing contactgegevens: '.$e->getMessage(),
                [
                    'email'            => $email,
                    'organizationUuid' => $organizationUuid,
                    'exception'        => $e,
                ]
            );
            return null;
        }//end try
    }//end findExistingContactgegevens()

    /**
     * Gets all available roles in the system.
     *
     * @return array Array of all available roles
     */
    private function getAllAvailableRoles(): array
    {
        return [
            'Functioneel-beheerder',
            'Aanbod-beheerder',
            'Gebruik-beheerder',
            'Gebruik-raadpleger',
            'VNG-raadpleger',
            // Add beheerder role for group assignment.
            'beheerder',
        ];
    }//end getAllAvailableRoles()

    /**
     * Maps functie (job function) to appropriate roles.
     *
     * @param string $functie        The job function
     * @param bool   $isFirstContact Whether this is the first contact in the organization
     *
     * @return array Array of roles
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) $isFirstContact is a simple role-assignment toggle
     */
    private function mapFunctieToRoles(string $functie, bool $isFirstContact=false): array
    {
        // If this is the first contact, give them all available roles.
        if ($isFirstContact === true) {
            $this->_logger->info('Assigning all roles to first contact', ['functie' => $functie]);
            return $this->getAllAvailableRoles();
        }

        $functie = strtolower(trim($functie));

        // Default role mappings based on common job functions.
        $roleMapping = [
            'ceo'           => ['Functioneel-beheerder', 'Aanbod-beheerder'],
            'manager'       => ['Functioneel-beheerder', 'Gebruik-beheerder'],
            'beheerder'     => ['Gebruik-beheerder', 'beheerder'],
            'administrator' => ['Functioneel-beheerder'],
            'inkoper'       => ['Gebruik-beheerder'],
            'procurement'   => ['Gebruik-beheerder'],
            'raadpleger'    => ['Gebruik-raadpleger'],
            'viewer'        => ['Gebruik-raadpleger'],
            'vng'           => ['VNG-raadpleger'],
        ];

        // Check for specific matches.
        foreach ($roleMapping as $key => $roles) {
            if (strpos(haystack: $functie, needle: $key) !== false) {
                return $roles;
            }
        }

        // Default role for unknown functions.
        return ['Gebruik-raadpleger'];
    }//end mapFunctieToRoles()

    /**
     * Handles new organization creation with contactpersonen processing.
     *
     * @param object $organizationObject The organization object
     *
     * @return void
     */
    public function handleNewOrganization(object $organizationObject): void
    {
        try {
            $this->_logger->info(
                    'Handling new organization',
                    [
                        'objectId' => $organizationObject->getId(),
                    ]
                    );

            // First process the organization to ensure it has proper group structure.
            $processed = $this->processOrganization(organizationObject: $organizationObject);

            if ($processed === true) {
                // Then process contactpersonen if organization is active.
                $objectData  = $organizationObject->getObject();
                $beoordeling = strtolower($objectData['beoordeling'] ?? '');

                if ($beoordeling === 'actief') {
                    $processedContacts = $this->processContactpersonen(organizationObject: $organizationObject);

                    $this->_logger->info(
                        'Processed organization and contactgegevens',
                        [
                            'organizationId'       => $organizationObject->getId(),
                            'contactgegevensCount' => count($processedContacts),
                        ]
                    );
                }
            }
        } catch (\Exception $e) {
            $this->_logger->error(
                'Failed to handle new organization: '.$e->getMessage(),
                [
                    'objectId'  => $organizationObject->getId(),
                    'exception' => $e,
                ]
            );
        }//end try
    }//end handleNewOrganization()

    /**
     * Gets all beheerders for an organization.
     *
     * @param string $organizationUuid The organization UUID
     *
     * @return array Array of usernames who are beheerders in this organization
     */
    public function getOrganizationBeheerders(string $organizationUuid): array
    {
        try {
            $beheerders     = [];
            $beheerderGroup = $this->_groupManager->get('beheerder');

            if ($beheerderGroup === null) {
                return [];
            }

            // Get all users in beheerder group.
            $beheerderUsers = $beheerderGroup->getUsers();

            // Filter users who belong to this organization.
            foreach ($beheerderUsers as $user) {
                if ($this->userBelongsToOrganization(user: $user, organizationUuid: $organizationUuid) === true) {
                    $beheerders[] = $user->getUID();
                }
            }

            // Sort by user creation date (oldest first).
            usort(
                    $beheerders,
                    function ($a, $b) {
                        $userA = $this->_userManager->get($a);
                        $userB = $this->_userManager->get($b);

                        // Get user creation timestamps (fallback to 0 if not available).
                        $timeA = 0;
                        if ($userA !== null) {
                            $lastLoginA = $userA->getLastLogin();
                            if ($lastLoginA !== 0 && $lastLoginA !== null && $lastLoginA !== false) {
                            }
                        }

                        $timeB = 0;
                        if ($userB !== null) {
                            $lastLoginB = $userB->getLastLogin();
                            if ($lastLoginB !== 0 && $lastLoginB !== null && $lastLoginB !== false) {
                            }
                        }

                        return $timeA <=> $timeB;
                    }
                    );

            $this->_logger->info(
                'Found organization beheerders',
                [
                    'organization' => $organizationUuid,
                    'beheerders'   => $beheerders,
                ]
            );

            return $beheerders;
        } catch (\Exception $e) {
            $this->_logger->error(
                'Failed to get organization beheerders: '.$e->getMessage(),
                [
                    'organization' => $organizationUuid,
                    'exception'    => $e,
                ]
            );
            return [];
        }//end try
    }//end getOrganizationBeheerders()

    /**
     * Checks if a user belongs to an organization.
     *
     * @param IUser  $user             The user to check
     * @param string $organizationUuid The organization UUID
     *
     * @return bool True if user belongs to organization
     */
    public function userBelongsToOrganization(IUser $user, string $organizationUuid): bool
    {
        try {
            // Check if user is in the organization-specific group.
            $organizationGroupName = $this->sanitizeGroupName(name: $organizationUuid);
            $organizationGroup     = $this->_groupManager->get($organizationGroupName);

            if ($organizationGroup !== null && $organizationGroup->inGroup($user) === true) {
                return true;
            }

            // Alternative approach: check user's groups for organization-specific groups.
            $userGroups = $this->_groupManager->getUserGroups($user);
            foreach ($userGroups as $group) {
                // Check if any group name contains the organization UUID.
                if (strpos(haystack: $group->getGID(), needle: $organizationUuid) !== false) {
                    return true;
                }
            }

            return false;
        } catch (\Exception $e) {
            $this->_logger->error(
                'Failed to check user organization membership: '.$e->getMessage(),
                [
                    'username'     => $user->getUID(),
                    'organization' => $organizationUuid,
                ]
            );
            return false;
        }//end try
    }//end userBelongsToOrganization()
}//end class
