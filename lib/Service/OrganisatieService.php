<?php
/**
 * Organisatie Service.
 *
 * This file contains the service class for handling organization-specific operations
 * in the SoftwareCatalog application.
 *
 * @category  Service
 * @package   OCA\SoftwareCatalog\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/SoftwareCatalog
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Service;

use OCA\SoftwareCatalog\Service\SoftwareCatalogue\OrganizationHandler;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use Psr\Container\ContainerInterface;
use OCP\App\IAppManager;
use OCP\IAppConfig;

/**
 * Service for handling organization-specific operations.
 *
 * This service provides functionality for organization entity creation,
 * status management, and integration with OpenRegister.
 *
 * @category Service
 * @package  OCA\SoftwareCatalog\Service
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @version  GIT: <git_id>
 * @link     https://github.com/ConductionNL/SoftwareCatalog
 *
 * @SuppressWarnings(PHPMD.UnusedPrivateMethod)
 */
class OrganisatieService
{
    /**
     * OrganisatieService constructor.
     *
     * @param OrganizationHandler $organizationHandler Organization handler
     * @param LoggerInterface     $logger              Logger interface
     * @param ContainerInterface  $container           Container interface
     * @param IAppManager         $appManager          App manager
     * @param IAppConfig          $config              Configuration service
     * @param IUserManager        $userManager         User manager service
     * @param SymfonyEmailService $emailService        Email service
     */
    public function __construct(
        private readonly OrganizationHandler $organizationHandler,
        private readonly LoggerInterface $logger,
        private readonly ContainerInterface $container,
        private readonly IAppManager $appManager,
        private readonly IAppConfig $config,
        private readonly IUserManager $userManager,
        private readonly SymfonyEmailService $emailService,
    ) {
    }//end __construct()

    /**
     * Creates an organization entity in OpenRegister.
     *
     * @param array $objectData The organization object data
     *
     * @return object|null The created organisation entity or null on failure
     *
     * @spec openspec/changes/retrofit-2026-05-24-organisatie-service/tasks.md#task-1
     */
    public function createOrganisationInOpenRegister(array $objectData): ?object
    {
        try {
            $organizationUuid = $objectData['id'] ?? null;
            if (empty($organizationUuid) === true) {
                $this->logger->error('OrganisatieService: No organization UUID provided for creation');
                return null;
            }

            $this->logger->info(
                    'OrganisatieService: Creating organization entity in OpenRegister',
                    [
                        'organizationUuid' => $organizationUuid,
                        'naam'             => $objectData['naam'] ?? 'Unknown',
                    ]
                    );

            // Map the data for OpenRegister.
            $mappedData = $this->mapOrganizationDataForOpenRegister(objectData: $objectData);

            // Get organisation service.
            $organisationService = $this->getOrganisationService();
            if ($organisationService === null) {
                $this->logger->error('OrganisatieService: OrganisationService not available');
                return null;
            }

            // Create the organization entity.
            $organisationEntity = $this->createOrganisationEntityInternal(
                organisationService: $organisationService,
                mappedData: $mappedData,
                organizationUuid: $organizationUuid
            );

            if ($organisationEntity !== null) {
                $this->logger->info(
                        'OrganisatieService: Successfully created organization entity',
                        [
                            'organizationUuid' => $organizationUuid,
                            'entityId'         => $organisationEntity->getId(),
                        ]
                        );
            }

            return $organisationEntity;
        } catch (\Exception $e) {
            $this->logger->error(
                    'OrganisatieService: Error creating organization entity',
                    [
                        'error'            => $e->getMessage(),
                        'organizationUuid' => $objectData['id'] ?? 'unknown',
                    ]
                    );
            return null;
        }//end try
    }//end createOrganisationInOpenRegister()

    /**
     * Updates organization entity status based on object data.
     *
     * @param string $organizationUuid The organization UUID
     * @param array  $objectData       The organization object data
     *
     * @return bool True if update was successful
     *
     * @spec openspec/changes/retrofit-2026-05-24-organisatie-service/tasks.md#task-2
     */
    public function updateOrganizationStatus(string $organizationUuid, array $objectData): bool
    {
        try {
            $this->logger->info(
                    'OrganisatieService: Updating organization status',
                    [
                        'organizationUuid' => $organizationUuid,
                        'beoordeling'      => $objectData['beoordeling'] ?? 'unknown',
                    ]
                    );

            // Get the organization entity.
            $organisationMapper = $this->container->get('OCA\OpenRegister\Db\OrganisationMapper');
            $organisationEntity = $organisationMapper->findByUuid($organizationUuid);

            // Map status from SoftwareCatalog to OpenRegister.
            $active = $this->mapStatus(status: $objectData['beoordeling'] ?? 'actief');

            // Update the entity.
            $organisationEntity->setActive($active);
            $organisationMapper->save($organisationEntity);

            $this->logger->info(
                    'OrganisatieService: Successfully updated organization status',
                    [
                        'organizationUuid' => $organizationUuid,
                        'active'           => $active,
                    ]
                    );

            return true;
        } catch (\Exception $e) {
            $this->logger->error(
                    'OrganisatieService: Failed to update organization status',
                    [
                        'organizationUuid' => $organizationUuid,
                        'error'            => $e->getMessage(),
                    ]
                    );
            return false;
        }//end try
    }//end updateOrganizationStatus()

    /**
     * Gets the OrganisationService instance.
     *
     * @return \OCA\OpenRegister\Service\OrganisationService|null The service instance or null if unavailable
     */
    private function getOrganisationService(): ?\OCA\OpenRegister\Service\OrganisationService
    {
        if ($this->appManager->isEnabledForUser('openregister') === false) {
            return null;
        }

        try {
            return $this->container->get('OCA\OpenRegister\Service\OrganisationService');
        } catch (\Exception $e) {
            $this->logger->error('OrganisatieService: Failed to get OrganisationService: '.$e->getMessage());
            return null;
        }
    }//end getOrganisationService()

    /**
     * Maps organization data from Software Catalog object to OpenRegister format.
     *
     * @param array $objectData The organization object data.
     *
     * @return array The mapped data for OpenRegister.
     *
     * @spec openspec/changes/retrofit-2026-05-24-organisatie-service/tasks.md#task-3
     */
    private function mapOrganizationDataForOpenRegister(array $objectData): array
    {
        // Get the organization name - try 'naam' first, then 'name', then use UUID as fallback.
        $naam = $objectData['naam'] ?? $objectData['name'] ?? null;

        // If still no name, create a unique one using the ID to avoid slug conflicts.
        if (empty($naam) === true || $naam === 'Unknown') {
            $orgId = $objectData['id'] ?? uniqid(prefix: 'org-');
            $naam  = 'Organisation '.substr($orgId, 0, 8);
        }

        return [
            'naam'            => $naam,
            'type'            => $objectData['type'] ?? '',
            'website'         => $objectData['website'] ?? '',
            'active'          => $this->mapStatus(status: $objectData['status'] ?? $objectData['beoordeling'] ?? 'actief'),
            'contactpersonen' => $objectData['contactpersonen'] ?? [],
            'deelnemers'      => $objectData['deelnemers'] ?? [],
        ];
    }//end mapOrganizationDataForOpenRegister()

    /**
     * Maps status from Software Catalog to OpenRegister format.
     *
     * @param string $status The status from Software Catalog
     *
     * @return bool The mapped active status for OpenRegister
     *
     * @spec openspec/changes/retrofit-2026-05-24-organisatie-service/tasks.md#task-2
     */
    private function mapStatus(string $status): bool
    {
        $normalizedStatus = strtolower(trim($status));

        return match ($normalizedStatus) {
            'actief', 'active' => true,
            'inactief', 'inactive', 'deactief' => false,
            // Default to active for unknown statuses.
            default => true
        };
    }//end mapStatus()

    /**
     * Internal method to create organization entity.
     *
     * HOTFIX: Parent organisation setting has been disabled due to RBAC issues.
     * Previously, new organisations were automatically set as children of the active organisation,
     * but this caused permission problems where users could not access newly created organisations.
     * TODO: Re-enable parent organisation setting after fixing RBAC logic.
     *
     * @param \OCA\OpenRegister\Service\OrganisationService $organisationService The organisation service
     * @param array                                         $mappedData          The mapped data
     * @param string                                        $organizationUuid    The organization UUID
     *
     * @return \OCA\OpenRegister\Db\Organisation The created organisation entity
     *
     * @spec openspec/changes/retrofit-2026-05-24-organisatie-service/tasks.md#task-1
     */
    private function createOrganisationEntityInternal(
        \OCA\OpenRegister\Service\OrganisationService $organisationService,
        array $mappedData,
        string $organizationUuid
    ): \OCA\OpenRegister\Db\Organisation {
        // HOTFIX: Commented out automatic parent organisation setting due to RBAC issues.
        // When child organisations are created, the parent relationship causes permission problems.
        // Where users cannot access the newly created organisations due to hierarchical RBAC filtering.
        // TODO: Investigate and fix RBAC logic to properly handle parent-child organisation relationships.
        // Disabled: $parentOrganisationUuid = $this->getActiveOrganisationUuid(organisationService: $organisationService).
        $this->logger->info(
                'OrganisatieService: Creating organisation entity',
                [
                    'uuid'   => $organizationUuid,
                    'name'   => $mappedData['naam'],
                    'active' => $mappedData['active'],
                    // 'parentOrganisation' => $parentOrganisationUuid // HOTFIX: Commented out.
                ]
                );

        // Use OrganisationService to create the entity.
        // NOTE: Don't call save() afterwards as it causes UUID/ID issues in the mapper.
        $organisationEntity = $organisationService->createOrganisation(
            name: (string) $mappedData['naam'],
            description: (string) ($mappedData['type'] ?? ''),
            addCurrentUser: false,
            uuid: $organizationUuid
        );

        $this->logger->info(
                'OrganisatieService: Organisation entity created successfully',
                [
                    'uuid'     => $organizationUuid,
                    'entityId' => $organisationEntity->getId(),
                    'active'   => $organisationEntity->isActive(),
                    'parent'   => $organisationEntity->getParent(),
                ]
                );

        return $organisationEntity;
    }//end createOrganisationEntityInternal()

    /**
     * Get the currently active organisation UUID from the user session.
     *
     * @param \OCA\OpenRegister\Service\OrganisationService $organisationService The organisation service
     *
     * @return string|null The active organisation UUID or null if not set
     *
     * @spec openspec/changes/retrofit-2026-05-24-organisatie-service/tasks.md#task-1
     */
    private function getActiveOrganisationUuid(
        \OCA\OpenRegister\Service\OrganisationService $organisationService
    ): ?string {
        try {
            // Try to get the active organisation from the OrganisationService.
            $activeOrganisation = $organisationService->getActiveOrganisation();
            if ($activeOrganisation !== null) {
                return $activeOrganisation->getUuid();
            }
        } catch (\Exception $e) {
            $this->logger->debug(
                    'OrganisatieService: Could not get active organisation',
                    [
                        'error' => $e->getMessage(),
                    ]
                    );
        }

        return null;
    }//end getActiveOrganisationUuid()

    /**
     * Adds users to organization entity.
     *
     * @param string $organizationUuid The organization UUID
     * @param array  $usernames        Array of usernames to add
     *
     * @return bool True if successful
     *
     * @spec openspec/changes/retrofit-2026-05-24-organisatie-service/tasks.md#task-4
     */
    public function addUsersToOrganization(string $organizationUuid, array $usernames): bool
    {
        try {
            $this->logger->info(
                    'OrganisatieService: Adding users to organization',
                    [
                        'organizationUuid' => $organizationUuid,
                        'userCount'        => count($usernames),
                    ]
                    );

            // Get the organization entity.
            $organisationMapper = $this->container->get('OCA\OpenRegister\Db\OrganisationMapper');
            $organisationEntity = $organisationMapper->findByUuid($organizationUuid);

            // Get current users and merge with new ones.
            $currentUsers = $organisationEntity->getUsers() ?? [];
            $allUsers     = array_unique(array_merge($currentUsers, $usernames));

            foreach ($usernames as $username) {
                $user = $this->userManager->get($username);

                $userData = [
                    'username' => $user->getUID(),
                    'email'    => $user->getEMailAddress(),
                    'name'     => $user->getDisplayName(),
                ];

                $this->emailService->sendUserUpdateEmail(
                    user: $userData,
                    organization: $organisationEntity->jsonSerialize()
                );
            }

            // Update the entity.
            $organisationEntity->setUsers($allUsers);
            $organisationMapper->save($organisationEntity);

            $this->logger->info(
                    'OrganisatieService: Successfully added users to organization',
                    [
                        'organizationUuid' => $organizationUuid,
                        'totalUsers'       => count($allUsers),
                        'addedUsers'       => array_diff($allUsers, $currentUsers),
                    ]
                    );

            return true;
        } catch (\Exception $e) {
            $this->logger->error(
                    'OrganisatieService: Failed to add users to organization',
                    [
                        'organizationUuid' => $organizationUuid,
                        'error'            => $e->getMessage(),
                    ]
                    );
            // Log detailed error information using PSR-3 logger.
            $this->logger->error(
                    'OrganisatieService: Exception details',
                    [
                        'message' => $e->getMessage(),
                        'trace'   => $e->getTraceAsString(),
                    ]
                    );
            return false;
        }//end try
    }//end addUsersToOrganization()

    /**
     * Gets admin group usernames.
     *
     * @return array Array of admin usernames
     *
     * @spec openspec/changes/retrofit-2026-05-24-organisatie-service/tasks.md#task-5
     */
    public function getAdminGroupUsernames(): array
    {
        try {
            $groupManager = $this->container->get('OCP\IGroupManager');
            $adminGroup   = $groupManager->get('admin');

            if ($adminGroup !== null) {
                $adminUsers     = $adminGroup->getUsers();
                $adminUsernames = [];
                foreach ($adminUsers as $user) {
                    $adminUsernames[] = $user->getUID();
                }

                return $adminUsernames;
            }

            return [];
        } catch (\Exception $e) {
            $this->logger->error(
                    'OrganisatieService: Failed to get admin users',
                    [
                        'exception' => $e->getMessage(),
                    ]
                    );
            return [];
        }//end try
    }//end getAdminGroupUsernames()
}//end class
