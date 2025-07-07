<?php

/**
 * Organization Handler for Software Catalog
 *
 * This handler manages organization-specific operations including group creation,
 * organization processing, and hierarchy management.
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
use OCP\IUserManager;
use OCP\IUser;
use Psr\Container\ContainerInterface;
use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;

/**
 * Handler for organization-related operations
 *
 * @category Handler
 * @package  OCA\SoftwareCatalog\Service\SoftwareCatalogue
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @version  1.0.0
 * @link     https://github.com/ConductionNL/SoftwareCatalog
 */
class OrganizationHandler
{
    /**
     * OrganizationHandler constructor
     *
     * @param IGroupManager          $_groupManager  Group manager interface
     * @param IUserManager           $_userManager   User manager interface
     * @param ContainerInterface     $_container     Container interface
     * @param IAppManager            $_appManager    App manager interface
     * @param LoggerInterface        $_logger        Logger interface
     */
    public function __construct(
        private readonly IGroupManager $_groupManager,
        private readonly IUserManager $_userManager,
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
            $this->_logger->info('Processing organization object', [
                'objectId' => $organizationObject->getId()
            ]);

            $objectData = $organizationObject->getObject();
            
            // Check if organization is active (beoordeling = "actief" or "Actief")
            $beoordeling = strtolower($objectData['beoordeling'] ?? '');
            if ($beoordeling !== 'actief') {
                $this->_logger->info(
                    'Organization not active, skipping processing',
                    [
                        'organizationId' => $organizationObject->getId(),
                        'beoordeling' => $beoordeling
                    ]
                );
                return true; // Not an error, just not ready for processing
            }
            
            // Ensure organization has a unique group
            $groupId = $this->ensureOrganizationGroup($organizationObject, $objectData);
            
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
     * Ensures an organization has a unique group and returns the group ID
     *
     * @param object $organizationObject  The organization object
     * @param array  $objectData          The organization data
     * 
     * @return string|null The group ID or null if failed
     */
    public function ensureOrganizationGroup(object $organizationObject, array &$objectData): ?string
    {
        $groupProperty = $objectData['group'] ?? '';
        
        if (empty($groupProperty)) {
            // Create group with organization name
            $organizationName = $objectData['naam'] ?? $objectData['name'] ?? 'Organization';
            $groupName = $this->sanitizeGroupName($organizationName);
            
            // Ensure group name is unique
            $groupName = $this->ensureUniqueGroupName($groupName);
            
            $group = $this->createGroupIfNotExists($groupName);
            
            if ($group) {
                // Set the group ID in the organization object
                $objectData['group'] = $group->getGID();
                $organizationObject->setObject($objectData);
                
                // Save the updated organization
                $objectService = $this->_getObjectService();
                $objectService->saveObject($organizationObject);
                
                $this->_logger->info(
                    'Created and assigned unique group to organization',
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
     * Ensures a group name is unique by appending a counter if necessary
     *
     * @param string $baseName  The base group name
     * 
     * @return string A unique group name
     */
    private function ensureUniqueGroupName(string $baseName): string
    {
        $groupName = $baseName;
        $counter = 1;
        
        while ($this->_groupManager->get($groupName) !== null) {
            $groupName = $baseName . '_' . $counter;
            $counter++;
        }
        
        return $groupName;
    }

    /**
     * Creates a group if it doesn't exist
     *
     * @param string $groupName  The group name to create
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
     * Sanitizes a group name for safe usage
     *
     * @param string $name  The name to sanitize
     * 
     * @return string The sanitized group name
     */
    public function sanitizeGroupName(string $name): string
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
     * Processes contactpersonen from organization data into Contactgegevens objects
     *
     * @param object $organizationObject The organization object
     * 
     * @return array Array of created contactgegevens objects
     */
    public function processContactpersonen(object $organizationObject): array
    {
        try {
            $objectData = $organizationObject->getObject();
            $contactpersonen = $objectData['contactpersonen'] ?? [];
            // Get the actual UUID from object data instead of database ID
            $organizationUuid = $objectData['id'] ?? $organizationObject->getId();
            $createdContacts = [];

            if (!is_array($contactpersonen) || empty($contactpersonen)) {
                $this->_logger->info('No contactpersonen found in organization', [
                    'organizationId' => $organizationUuid
                ]);
                return $createdContacts;
            }

            $objectService = $this->_getObjectService();

            foreach ($contactpersonen as $index => $contactpersoon) {
                try {
                    // Get the contactgegevens schema ID from settings
                    $settingsService = $this->_container->get('OCA\SoftwareCatalog\Service\SettingsService');
                    $contactgegevensSchemaId = $settingsService->getSchemaIdForObjectType('contactgegevens');
                    
                    $this->_logger->info(
                        'Creating contactgegevens object with schema',
                        [
                            'contactgegevensSchemaId' => $contactgegevensSchemaId,
                            'organizationUuid' => $organizationUuid,
                            'contactpersoonIndex' => $index,
                            'email' => $contactpersoon['email'] ?? $contactpersoon['e-mailadres'] ?? ''
                        ]
                    );
                    
                    // Generate title from name components
                    $titleParts = array_filter([
                        $contactpersoon['voornaam'] ?? '',
                        $contactpersoon['tussenvoegsel'] ?? '',
                        $contactpersoon['achternaam'] ?? ''
                    ]);
                    $title = !empty($titleParts) ? implode(' ', $titleParts) : ($contactpersoon['email'] ?? 'Contact Person');
                    
                    // Create contactgegevens object with proper schema
                    $contactgegevensData = [
                        'title' => $title, // Required by OpenRegister
                        'voornaam' => $contactpersoon['voornaam'] ?? '',
                        'tussenvoegsel' => $contactpersoon['tussenvoegsel'] ?? '',
                        'achternaam' => $contactpersoon['achternaam'] ?? '',
                        'telefoon' => $contactpersoon['telefoon'] ?? '',
                        'email' => $contactpersoon['email'] ?? $contactpersoon['e-mailadres'] ?? '',
                        'functie' => $contactpersoon['functie'] ?? '',
                        'organisation' => $organizationUuid, // Link to organization
                        'roles' => $this->mapFunctieToRoles($contactpersoon['functie'] ?? '', $index === 0),
                        'username' => '', // Will be set when user is created

                    ];

                    // Create the contactgegevens object via ObjectService with proper schema/register parameters
                    $contactgegevensObject = $objectService->saveObject(
                        object: $contactgegevensData,
                        extend: [],
                        register: 6, // Voorzieningen register
                        schema: $contactgegevensSchemaId // Schema ID 34 for contactgegevens
                    );
                    
                    if ($contactgegevensObject) {
                        $createdContacts[] = $contactgegevensObject;
                        
                        $this->_logger->info(
                            'Created contactgegevens from contactpersoon',
                            [
                                'organizationId' => $organizationUuid,
                                'contactgegevensId' => $contactgegevensObject->getId(),
                                'contactpersoonIndex' => $index,
                                'email' => $contactgegevensData['email']
                            ]
                        );
                    }

                } catch (\Exception $e) {
                    $this->_logger->error(
                        'Failed to create contactgegevens from contactpersoon: ' . $e->getMessage(),
                        [
                            'organizationId' => $organizationUuid,
                            'contactpersoonIndex' => $index,
                            'contactpersoon' => $contactpersoon,
                            'exception' => $e
                        ]
                    );
                }
            }

            return $createdContacts;

        } catch (\Exception $e) {
            $this->_logger->error(
                'Failed to process contactpersonen: ' . $e->getMessage(),
                [
                    'organizationId' => $organizationObject->getId(),
                    'exception' => $e
                ]
            );
            return [];
        }
    }

    /**
     * Gets all available roles in the system
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
            'beheerder' // Add beheerder role for group assignment
        ];
    }

    /**
     * Maps functie (job function) to appropriate roles
     *
     * @param string $functie The job function
     * @param bool   $isFirstContact Whether this is the first contact in the organization
     * 
     * @return array Array of roles
     */
    private function mapFunctieToRoles(string $functie, bool $isFirstContact = false): array
    {
        // If this is the first contact, give them all available roles
        if ($isFirstContact) {
            $this->_logger->info('Assigning all roles to first contact', ['functie' => $functie]);
            return $this->getAllAvailableRoles();
        }
        
        $functie = strtolower(trim($functie));
        
        // Default role mappings based on common job functions
        $roleMapping = [
            'ceo' => ['Functioneel-beheerder', 'Aanbod-beheerder'],
            'manager' => ['Functioneel-beheerder', 'Gebruik-beheerder'],
            'beheerder' => ['Gebruik-beheerder', 'beheerder'],
            'administrator' => ['Functioneel-beheerder'],
            'inkoper' => ['Gebruik-beheerder'],
            'procurement' => ['Gebruik-beheerder'],
            'raadpleger' => ['Gebruik-raadpleger'],
            'viewer' => ['Gebruik-raadpleger'],
            'vng' => ['VNG-raadpleger']
        ];

        // Check for specific matches
        foreach ($roleMapping as $key => $roles) {
            if (strpos($functie, $key) !== false) {
                return $roles;
            }
        }

        // Default role for unknown functions
        return ['Gebruik-raadpleger'];
    }

    /**
     * Handles new organization creation with contactpersonen processing
     *
     * @param object $organizationObject  The organization object
     * 
     * @return void
     */
    public function handleNewOrganization(object $organizationObject): void
    {
        try {
            $this->_logger->info('Handling new organization', [
                'objectId' => $organizationObject->getId()
            ]);

            // First process the organization to ensure it has proper group structure
            $processed = $this->processOrganization($organizationObject);
            
            if ($processed) {
                // Then process contactpersonen if organization is active
                $objectData = $organizationObject->getObject();
                $beoordeling = strtolower($objectData['beoordeling'] ?? '');
                
                if ($beoordeling === 'actief') {
                    $createdContacts = $this->processContactpersonen($organizationObject);
                    
                    $this->_logger->info(
                        'Processed organization and created contactgegevens',
                        [
                            'organizationId' => $organizationObject->getId(),
                            'contactgegevensCount' => count($createdContacts)
                        ]
                    );
                }
            }

        } catch (\Exception $e) {
            $this->_logger->error(
                'Failed to handle new organization: ' . $e->getMessage(),
                [
                    'objectId' => $organizationObject->getId(),
                    'exception' => $e
                ]
            );
        }
    }

    /**
     * Gets all beheerders for an organization
     *
     * @param string $organizationUuid  The organization UUID
     * 
     * @return array Array of usernames who are beheerders in this organization
     */
    public function getOrganizationBeheerders(string $organizationUuid): array
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
                if ($this->userBelongsToOrganization($user, $organizationUuid)) {
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
     * @param IUser  $user              The user to check
     * @param string $organizationUuid  The organization UUID
     * 
     * @return bool True if user belongs to organization
     */
    public function userBelongsToOrganization(IUser $user, string $organizationUuid): bool
    {
        try {
            // Check if user is in the organization-specific group
            $organizationGroupName = $this->sanitizeGroupName($organizationUuid);
            $organizationGroup = $this->_groupManager->get($organizationGroupName);
            
            if ($organizationGroup && $organizationGroup->inGroup($user)) {
                return true;
            }
            
            // Alternative approach: check user's groups for organization-specific groups
            $userGroups = $this->_groupManager->getUserGroups($user);
            foreach ($userGroups as $group) {
                // Check if any group name contains the organization UUID
                if (strpos($group->getGID(), $organizationUuid) !== false) {
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
} 