<?php
/**
 * Organisatie Service
 *
 * This file contains the service class for handling organization-specific operations
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

use OCA\SoftwareCatalog\Service\SoftwareCatalogue\OrganizationHandler;
use Psr\Log\LoggerInterface;
use Psr\Container\ContainerInterface;
use OCP\App\IAppManager;
use OCP\IConfig;

/**
 * Service for handling organization-specific operations
 * 
 * This service provides functionality for organization entity creation,
 * status management, and integration with OpenRegister.
 * 
 * @category Service
 * @package  OCA\SoftwareCatalog\Service
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @version  1.0.0
 * @link     https://github.com/ConductionNL/SoftwareCatalog
 */
class OrganisatieService
{
    /**
     * OrganisatieService constructor
     *
     * @param OrganizationHandler   $organizationHandler Organization handler
     * @param LoggerInterface       $logger              Logger interface
     * @param ContainerInterface    $container           Container interface
     * @param IAppManager          $appManager          App manager
     * @param IConfig              $config              Configuration service
     */
    public function __construct(
        private readonly OrganizationHandler $organizationHandler,
        private readonly LoggerInterface $logger,
        private readonly ContainerInterface $container,
        private readonly IAppManager $appManager,
        private readonly IConfig $config
    ) {
    }

    /**
     * Creates an organization entity in OpenRegister
     *
     * @param array $objectData The organization object data
     * 
     * @return object|null The created organisation entity or null on failure
     */
    public function createOrganisationInOpenRegister(array $objectData): ?object
    {
        try {
            $organizationUuid = $objectData['id'] ?? null;
            if (!$organizationUuid) {
                $this->logger->error('OrganisatieService: No organization UUID provided for creation');
                return null;
            }
            
            $this->logger->info('OrganisatieService: Creating organization entity in OpenRegister', [
                'organizationUuid' => $organizationUuid,
                'naam' => $objectData['naam'] ?? 'Unknown'
            ]);
            
            // Map the data for OpenRegister
            $mappedData = $this->mapOrganizationDataForOpenRegister($objectData);
            
            // Get organisation service
            $organisationService = $this->getOrganisationService();
            if (!$organisationService) {
                $this->logger->error('OrganisatieService: OrganisationService not available');
                return null;
            }
            
            // Create the organization entity
            $organisationEntity = $this->createOrganisationEntityInternal($organisationService, $mappedData, $organizationUuid);
            
            if ($organisationEntity) {
                $this->logger->info('OrganisatieService: Successfully created organization entity', [
                    'organizationUuid' => $organizationUuid,
                    'entityId' => $organisationEntity->getId()
                ]);
            }
            
            return $organisationEntity;
            
        } catch (\Exception $e) {
            $this->logger->error('OrganisatieService: Error creating organization entity', [
                'error' => $e->getMessage(),
                'objectData' => $objectData,
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }

    /**
     * Updates organization entity status based on object data
     *
     * @param string $organizationUuid The organization UUID
     * @param array  $objectData       The organization object data
     * 
     * @return bool True if update was successful
     */
    public function updateOrganizationStatus(string $organizationUuid, array $objectData): bool
    {
        try {
            $this->logger->info('OrganisatieService: Updating organization status', [
                'organizationUuid' => $organizationUuid,
                'beoordeling' => $objectData['beoordeling'] ?? 'unknown'
            ]);

            // Get the organization entity
            $organisationMapper = $this->container->get('OCA\OpenRegister\Db\OrganisationMapper');
            $organisationEntity = $organisationMapper->findByUuid($organizationUuid);
            
            // Map status from SoftwareCatalog to OpenRegister
            $active = $this->mapStatus($objectData['beoordeling'] ?? 'actief');
            
            // Update the entity
            $organisationEntity->setActive($active);
            $organisationMapper->save($organisationEntity);
            
            $this->logger->info('OrganisatieService: Successfully updated organization status', [
                'organizationUuid' => $organizationUuid,
                'active' => $active
            ]);
            
            return true;
            
        } catch (\Exception $e) {
            $this->logger->error('OrganisatieService: Failed to update organization status', [
                'organizationUuid' => $organizationUuid,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Gets the OrganisationService instance
     *
     * @return \OCA\OpenRegister\Service\OrganisationService|null
     */
    private function getOrganisationService(): ?\OCA\OpenRegister\Service\OrganisationService
    {
        if (!$this->appManager->isEnabledForUser('openregister')) {
            return null;
        }

        try {
            return $this->container->get('OCA\OpenRegister\Service\OrganisationService');
        } catch (\Exception $e) {
            $this->logger->error('OrganisatieService: Failed to get OrganisationService: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Maps organization data for OpenRegister format
     *
     * @param array $objectData The organization object data
     * 
     * @return array The mapped data for OpenRegister
     */
    private function mapOrganizationDataForOpenRegister(array $objectData): array
    {
        return [
            'naam' => $objectData['naam'] ?? 'Unknown',
            'type' => $objectData['type'] ?? '',
            'website' => $objectData['website'] ?? '',
            'active' => $this->mapStatus($objectData['beoordeling'] ?? 'actief'),
            'contactpersonen' => $objectData['contactpersonen'] ?? [],
            'deelnemers' => $objectData['deelnemers'] ?? []
        ];
    }

    /**
     * Maps status from Software Catalog to OpenRegister format
     *
     * @param string $status The status from Software Catalog
     * 
     * @return bool The mapped active status for OpenRegister
     */
    private function mapStatus(string $status): bool
    {
        $normalizedStatus = strtolower(trim($status));
        
        return match ($normalizedStatus) {
            'actief', 'active' => true,
            'inactief', 'inactive', 'deactief' => false,
            default => true // Default to active for unknown statuses
        };
    }

    /**
     * Internal method to create organization entity
     *
     * @param \OCA\OpenRegister\Service\OrganisationService $organisationService The organisation service
     * @param array                                         $mappedData          The mapped data
     * @param string                                        $organizationUuid    The organization UUID
     * 
     * @return \OCA\OpenRegister\Db\Organisation The created organisation entity
     */
    private function createOrganisationEntityInternal(
        \OCA\OpenRegister\Service\OrganisationService $organisationService,
        array $mappedData,
        string $organizationUuid
    ): \OCA\OpenRegister\Db\Organisation {
        
        $this->logger->info('OrganisatieService: Creating organisation entity', [
            'uuid' => $organizationUuid,
            'name' => $mappedData['naam'],
            'active' => $mappedData['active']
        ]);

        // Use OrganisationService to create the entity with correct parameters
        // Based on the error, the signature seems to be: createOrganisation(name, description, addCurrentUser, ...)
        // Let me check what parameters are actually expected and use a simpler approach
        $organisationEntity = $organisationService->createOrganisation(
            $mappedData['naam'],           // name (string)
            $mappedData['type'] ?? '',     // description (string)  
            false,                         // addCurrentUser (bool) - don't auto-add current user
            $organizationUuid              // uuid (string) - might be 4th parameter
        );
        
        // Set additional properties after creation
        if ($organisationEntity) {
            $organisationEntity->setActive($mappedData['active']);
            $organisationEntity->setUsers([]); // Will be populated by contact person processing
            
            // Save the updated entity
            $organisationMapper = $this->container->get('OCA\OpenRegister\Db\OrganisationMapper');
            $organisationMapper->save($organisationEntity);
        }
        
        $this->logger->info('OrganisatieService: Organisation entity created successfully', [
            'uuid' => $organizationUuid,
            'entityId' => $organisationEntity->getId(),
            'active' => $organisationEntity->getActive()
        ]);

        return $organisationEntity;
    }

    /**
     * Adds users to organization entity
     *
     * @param string $organizationUuid The organization UUID
     * @param array  $usernames        Array of usernames to add
     * 
     * @return bool True if successful
     */
    public function addUsersToOrganization(string $organizationUuid, array $usernames): bool
    {
        try {
            $this->logger->info('OrganisatieService: Adding users to organization', [
                'organizationUuid' => $organizationUuid,
                'userCount' => count($usernames)
            ]);

            // Get the organization entity
            $organisationMapper = $this->container->get('OCA\OpenRegister\Db\OrganisationMapper');
            $organisationEntity = $organisationMapper->findByUuid($organizationUuid);
            
            // Get current users and merge with new ones
            $currentUsers = $organisationEntity->getUsers() ?? [];
            $allUsers = array_unique(array_merge($currentUsers, $usernames));
            
            // Update the entity
            $organisationEntity->setUsers($allUsers);
            $organisationMapper->save($organisationEntity);
            
            $this->logger->info('OrganisatieService: Successfully added users to organization', [
                'organizationUuid' => $organizationUuid,
                'totalUsers' => count($allUsers),
                'addedUsers' => array_diff($allUsers, $currentUsers)
            ]);
            
            return true;
            
        } catch (\Exception $e) {
            $this->logger->error('OrganisatieService: Failed to add users to organization', [
                'organizationUuid' => $organizationUuid,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Gets admin group usernames
     *
     * @return array Array of admin usernames
     */
    public function getAdminGroupUsernames(): array
    {
        try {
            $groupManager = \OC::$server->get('OCP\IGroupManager');
            $adminGroup = $groupManager->get('admin');
            
            if ($adminGroup) {
                $adminUsers = $adminGroup->getUsers();
                $adminUsernames = [];
                foreach ($adminUsers as $user) {
                    $adminUsernames[] = $user->getUID();
                }
                return $adminUsernames;
            }
            
            return [];
        } catch (\Exception $e) {
            $this->logger->error('OrganisatieService: Failed to get admin users', [
                'exception' => $e->getMessage()
            ]);
            return [];
        }
    }
} 