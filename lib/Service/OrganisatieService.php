<?php

/**
 * Organisatie Service.
 *
 * This file contains the service class for handling organization-specific operations
 * in the Stackiq application.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

use OCA\OpenRegister\Service\Organisation\NewOrganisation;
use OCP\App\IAppManager;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for handling organization-specific operations.
 *
 * This service provides functionality for organization entity creation,
 * status management, and integration with OpenRegister.
 *
 * @category Service
 * @package  OCA\Stackiq\Service
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version  GIT: <git_id>
 * @link     https://github.com/ConductionNL/stackiq
 *
 * @SuppressWarnings(PHPMD.UnusedPrivateMethod)
 *
 * @spec openspec/specs/organisatie-service/spec.md
 */
class OrganisatieService {
	/**
	 * OpenRegister's exception for a taken slug, by name (OrganisationSlug::CONFLICT_EXCEPTION;
	 * not imported, to keep this class's coupling down).
	 */
	private const SLUG_CONFLICT = 'OCA\\OpenRegister\\Exception\\OrganisationSlugConflictException';

	/**
	 * OrganisatieService constructor.
	 *
	 * @param LoggerInterface $logger Logger interface
	 * @param ContainerInterface $container Container interface
	 * @param IAppManager $appManager App manager
	 * @param IUserManager $userManager User manager service
	 * @param SymfonyEmailService $emailService Email service
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly ContainerInterface $container,
		private readonly IAppManager $appManager,
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
	 * OpenRegister's OrganisationSlugConflictException (another organisation has the name's slug) is passed on.
	 *
	 * @spec openspec/specs/organisatie-service/spec.md
	 */
	public function createOrganisationInOpenRegister(array $objectData): ?object {
		try {
			return $this->createOrganisation(objectData: $objectData);
		} catch (\Exception $e) {
			// The caller decides what a taken slug means; every other failure ends here.
			if (is_a($e, self::SLUG_CONFLICT) === true) {
				throw $e;
			}

			$this->logger->error(
				'OrganisatieService: Error creating organization entity',
				['error' => $e->getMessage(), 'organizationUuid' => $objectData['id'] ?? 'unknown']
			);
			return null;
		}
	}//end createOrganisationInOpenRegister()

	/**
	 * Create the organisation when its data carries a uuid.
	 *
	 * @param array $objectData The organization object data
	 *
	 * @return object|null The created organisation entity, or null without a uuid or OrganisationService.
	 */
	private function createOrganisation(array $objectData): ?object {
		$organizationUuid = $objectData['id'] ?? null;
		if (empty($organizationUuid) === true) {
			$this->logger->error('OrganisatieService: No organization UUID provided for creation');
			return null;
		}

		$this->logger->info(
			'OrganisatieService: Creating organization entity in OpenRegister',
			['organizationUuid' => $organizationUuid, 'name' => $objectData['name'] ?? 'Unknown']
		);

		return $this->createMappedOrganisation(objectData: $objectData, organizationUuid: $organizationUuid);
	}//end createOrganisation()

	/**
	 * Map the data for OpenRegister and create the organisation entity there.
	 *
	 * @param array $objectData       The organization object data
	 * @param mixed $organizationUuid The organisation's uuid, as given
	 *
	 * @return object|null The created organisation entity, or null when OrganisationService is unavailable.
	 */
	private function createMappedOrganisation(array $objectData, mixed $organizationUuid): ?object {
		$mappedData          = $this->mapOrganizationDataForOpenRegister(objectData: $objectData);
		$organisationService = $this->getOrganisationService();
		if ($organisationService === null) {
			$this->logger->error('OrganisatieService: OrganisationService not available');
			return null;
		}

		// The helper is declared non-nullable and throws on failure — the
		// caller's catch is the real failure path.
		$organisationEntity = $this->createOrganisationEntityInternal(
			organisationService: $organisationService,
			mappedData: $mappedData,
			organizationUuid: $organizationUuid
		);
		$this->logCreated(organizationUuid: $organizationUuid, organisationEntity: $organisationEntity);

		return $organisationEntity;
	}//end createMappedOrganisation()

	/**
	 * Log a created organisation entity.
	 *
	 * @param mixed  $organizationUuid   The organisation's uuid, as given
	 * @param object $organisationEntity The created organisation entity
	 *
	 * @return void
	 */
	private function logCreated(mixed $organizationUuid, object $organisationEntity): void {
		$this->logger->info(
			'OrganisatieService: Successfully created organization entity',
			['organizationUuid' => $organizationUuid, 'entityId' => $organisationEntity->getId()]
		);
	}//end logCreated()

	/**
	 * Updates organization entity status based on object data.
	 *
	 * @param string $organizationUuid The organization UUID
	 * @param array $objectData The organization object data
	 *
	 * @return bool True if update was successful
	 *
	 * @spec openspec/specs/organisatie-service/spec.md
	 */
	public function updateOrganizationStatus(string $organizationUuid, array $objectData): bool {
		try {
			$this->logger->info(
				'OrganisatieService: Updating organization status',
				[
					'organizationUuid' => $organizationUuid,
					'beoordeling' => $objectData['beoordeling'] ?? 'unknown',
				]
			);

			// Get the organization entity.
			$organisationMapper = $this->container->get('OCA\OpenRegister\Db\OrganisationMapper');
			$organisationEntity = $organisationMapper->findByUuid($organizationUuid);

			// Map status from Stackiq to OpenRegister.
			$active = $this->mapStatus(status: $objectData['beoordeling'] ?? 'actief');

			// Update the entity.
			$organisationEntity->setActive($active);
			$organisationMapper->save($organisationEntity);

			$this->logger->info(
				'OrganisatieService: Successfully updated organization status',
				[
					'organizationUuid' => $organizationUuid,
					'active' => $active,
				]
			);

			return true;
		} catch (\Exception $e) {
			$this->logger->error(
				'OrganisatieService: Failed to update organization status',
				[
					'organizationUuid' => $organizationUuid,
					'error' => $e->getMessage(),
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
	private function getOrganisationService(): ?\OCA\OpenRegister\Service\OrganisationService {
		if ($this->appManager->isEnabledForUser('openregister') === false) {
			return null;
		}

		try {
			return $this->container->get('OCA\OpenRegister\Service\OrganisationService');
		} catch (\Exception $e) {
			$this->logger->error('OrganisatieService: Failed to get OrganisationService: ' . $e->getMessage());
			return null;
		}
	}//end getOrganisationService()

	/**
	 * Maps organization data from Stackiq object to OpenRegister format.
	 *
	 * @param array $objectData The organization object data.
	 *
	 * @return array The mapped data for OpenRegister.
	 *
	 * @spec openspec/specs/organisatie-service/spec.md
	 */
	private function mapOrganizationDataForOpenRegister(array $objectData): array {
		return [
			'name' => (new OrganisationSlug())->nameFor(objectData: $objectData),
			'type' => $objectData['type'] ?? '',
			'website' => $objectData['website'] ?? '',
			'active' => $this->mapStatus(status: $objectData['status'] ?? $objectData['beoordeling'] ?? 'actief'),
			'contactpersonen' => $objectData['contactpersonen'] ?? [],
			'participants' => $objectData['participants'] ?? [],
		];
	}//end mapOrganizationDataForOpenRegister()

	/**
	 * Maps status from Stackiq to OpenRegister format.
	 *
	 * @param string $status The status from Stackiq
	 *
	 * @return bool The mapped active status for OpenRegister
	 *
	 * @spec openspec/specs/organisatie-service/spec.md
	 */
	private function mapStatus(string $status): bool {
		$normalizedStatus = strtolower(trim($status));

		return match ($normalizedStatus) {
			'actief', 'active' => true,
			'inactief', 'inactive', 'deactief' => false,
			// 'merged' is the organisation-merge tombstone status — a
			// merged-away organisation MUST NOT be reported as active.
			// @spec openspec/specs/organisation-merge/spec.md#requirement-the-source-organisation-must-be-tombstoned-never-hard-deleted
			'merged' => false,
			// Default to active for unknown statuses.
			default => true
		};
	}//end mapStatus()

	/**
	 * Internal method to create organization entity.
	 *
	 * Restores parent-child organisation hierarchy (VNG domein: gemeente →
	 * samenwerkingsverband, moederorganisatie → deelnemende partij) that a
	 * prior hotfix disabled. The hotfix disabled parent assignment because a
	 * child organisation created with a parent became inaccessible to its
	 * creator. Root cause (see openspec/changes/
	 * organisation-parent-hierarchy-rbac-fix/design.md): OpenRegister's
	 * multitenancy resource filter (`OrganisationService::
	 * getUserActiveOrganisations()`) scopes reads to the active organisation
	 * plus its PARENT chain — children can read up the hierarchy, but a
	 * parent-active user cannot read down into a newly created child's
	 * resources (documented invariant on the OR `Organisation` entity:
	 * "parents cannot view child resources"). Setting `parent` at OR's
	 * `createOrganisation()` seam is not possible either way (that method
	 * exposes no `parent` argument), so the parent is applied in a second
	 * step (create-then-link) via the OrganisationMapper — the same mapper
	 * this service already uses in `addUsersToOrganization()`.
	 *
	 * @param \OCA\OpenRegister\Service\OrganisationService $organisationService The organisation service
	 * @param array $mappedData The mapped data
	 * @param string $organizationUuid The organization UUID
	 *
	 * @return \OCA\OpenRegister\Db\Organisation The created organisation entity
	 *
	 * @spec openspec/changes/organisation-parent-hierarchy-rbac-fix/specs/organisatie-service/spec.md
	 */
	private function createOrganisationEntityInternal(
		\OCA\OpenRegister\Service\OrganisationService $organisationService,
		array $mappedData,
		string $organizationUuid,
	): \OCA\OpenRegister\Db\Organisation {
		// Resolve the active organisation as the parent for the new (child)
		// organisation, restoring the hierarchy the hotfix disabled.
		$parentOrgUuid = $this->getActiveOrganisationUuid(organisationService: $organisationService);

		$this->logger->info(
			'OrganisatieService: Creating organisation entity',
			[
				'uuid' => $organizationUuid,
				'name' => $mappedData['name'],
				'active' => $mappedData['active'],
				'parentOrganisation' => $parentOrgUuid,
			]
		);

		// Use OrganisationService to create the entity.
		// NOTE: Don't call save() afterwards as it causes UUID/ID issues in the mapper.
		$organisationEntity = $organisationService->createOrganisation(
			details: new NewOrganisation(
				name: (string)$mappedData['name'],
				description: (string)($mappedData['type'] ?? ''),
				uuid: $organizationUuid
			),
			addCurrentUser: false
		);

		// Create-then-link: apply the parent AFTER the organisation exists and
		// is accessible, never self-parent, and only when an active parent was
		// resolved. Persist via the OrganisationMapper (the seam this service
		// already uses in addUsersToOrganization()); a link failure is logged
		// and swallowed so the organisation is still returned (flat) rather
		// than lost — matching the pre-hotfix "org always created" guarantee.
		if ($parentOrgUuid !== null
			&& $parentOrgUuid !== ''
			&& $parentOrgUuid !== $organisationEntity->getUuid()
		) {
			$organisationEntity = $this->linkParentOrganisation(
				organisationEntity: $organisationEntity,
				parentOrgUuid: $parentOrgUuid
			);
		}

		$this->logger->info(
			'OrganisatieService: Organisation entity created successfully',
			[
				'uuid' => $organizationUuid,
				'entityId' => $organisationEntity->getId(),
				'active' => $organisationEntity->isActive(),
				'parent' => $organisationEntity->getParent(),
			]
		);

		return $organisationEntity;
	}//end createOrganisationEntityInternal()

	/**
	 * Set the parent organisation on an already-created organisation entity.
	 *
	 * Second step of the create-then-link sequence. Persists via the
	 * OrganisationMapper — the same seam this service already uses in
	 * addUsersToOrganization(). On any failure the original (parentless)
	 * entity is returned unchanged so organisation creation never fails
	 * outright over a hierarchy link.
	 *
	 * @param \OCA\OpenRegister\Db\Organisation $organisationEntity The created organisation entity.
	 * @param string $parentOrgUuid The parent organisation UUID to link.
	 *
	 * @return \OCA\OpenRegister\Db\Organisation The entity with the parent set (or the original on failure).
	 *
	 * @spec openspec/changes/organisation-parent-hierarchy-rbac-fix/specs/organisatie-service/spec.md
	 */
	private function linkParentOrganisation(
		\OCA\OpenRegister\Db\Organisation $organisationEntity,
		string $parentOrgUuid,
	): \OCA\OpenRegister\Db\Organisation {
		try {
			$organisationMapper = $this->container->get('OCA\OpenRegister\Db\OrganisationMapper');

			$organisationEntity->setParent($parentOrgUuid);
			$saved = $organisationMapper->save($organisationEntity);

			$this->logger->info(
				'OrganisatieService: Linked organisation to parent',
				[
					'uuid' => $organisationEntity->getUuid(),
					'parent' => $parentOrgUuid,
				]
			);

			return $saved;
		} catch (\Throwable $e) {
			$this->logger->error(
				'OrganisatieService: Failed to link organisation to parent, leaving it flat',
				[
					'uuid' => $organisationEntity->getUuid(),
					'parent' => $parentOrgUuid,
					'error' => $e->getMessage(),
				]
			);

			return $organisationEntity;
		}//end try
	}//end linkParentOrganisation()

	/**
	 * Get the currently active organisation UUID from the user session.
	 *
	 * @param \OCA\OpenRegister\Service\OrganisationService $organisationService The organisation service
	 *
	 * @return string|null The active organisation UUID or null if not set
	 *
	 * @spec openspec/specs/organisatie-service/spec.md
	 */
	private function getActiveOrganisationUuid(
		\OCA\OpenRegister\Service\OrganisationService $organisationService,
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
	 * @param array $usernames Array of usernames to add
	 *
	 * @return bool True if successful
	 *
	 * @spec openspec/specs/organisatie-service/spec.md
	 */
	public function addUsersToOrganization(string $organizationUuid, array $usernames): bool {
		try {
			$this->logger->info(
				'OrganisatieService: Adding users to organization',
				['organizationUuid' => $organizationUuid, 'userCount' => count($usernames)]
			);
			$users = $this->mergeUsers(organizationUuid: $organizationUuid, usernames: $usernames);
			$this->logAddedUsers(organizationUuid: $organizationUuid, users: $users);
			return true;
		} catch (\Exception $e) {
			$this->logAddUsersFailure(organizationUuid: $organizationUuid, exception: $e);
			return false;
		}
	}//end addUsersToOrganization()

	/**
	 * Merge users into an organisation entity, mailing each added user.
	 *
	 * @param string $organizationUuid The organization UUID
	 * @param array  $usernames        Array of usernames to add
	 *
	 * @return array{all: array, current: array} The users after and before the merge.
	 */
	private function mergeUsers(string $organizationUuid, array $usernames): array {
		// Get the organization entity.
		$organisationMapper = $this->container->get('OCA\OpenRegister\Db\OrganisationMapper');
		$organisationEntity = $organisationMapper->findByUuid($organizationUuid);

		// Get current users and merge with new ones.
		$currentUsers = $organisationEntity->getUsers() ?? [];
		$allUsers     = array_unique(array_merge($currentUsers, $usernames));
		$this->mailAddedUsers(usernames: $usernames, organisationEntity: $organisationEntity);

		// Update the entity.
		$organisationEntity->setUsers($allUsers);
		$organisationMapper->save($organisationEntity);

		return ['all' => $allUsers, 'current' => $currentUsers];
	}//end mergeUsers()

	/**
	 * Log the users an organisation holds after adding.
	 *
	 * @param string                             $organizationUuid The organization UUID
	 * @param array{all: array, current: array} $users            The users after and before the merge.
	 *
	 * @return void
	 */
	private function logAddedUsers(string $organizationUuid, array $users): void {
		$this->logger->info(
			'OrganisatieService: Successfully added users to organization',
			[
				'organizationUuid' => $organizationUuid,
				'totalUsers' => count($users['all']),
				'addedUsers' => array_diff($users['all'], $users['current']),
			]
		);
	}//end logAddedUsers()

	/**
	 * Send each added user the user update mail.
	 *
	 * @param array  $usernames          Array of usernames to add
	 * @param object $organisationEntity The organisation entity
	 *
	 * @return void
	 */
	private function mailAddedUsers(array $usernames, object $organisationEntity): void {
		foreach ($usernames as $username) {
			$this->emailService->sendUserUpdateEmail(
				user: self::userMailData(user: $this->userManager->get($username)),
				organization: $organisationEntity->jsonSerialize()
			);
		}
	}//end mailAddedUsers()

	/**
	 * The user fields the update mail needs.
	 *
	 * @param IUser|null $user The user.
	 *
	 * @return array{username: string, email: string|null, name: string}
	 */
	private static function userMailData(?IUser $user): array {
		return [
			'username' => $user->getUID(),
			'email' => $user->getEMailAddress(),
			'name' => $user->getDisplayName(),
		];
	}//end userMailData()

	/**
	 * Log why adding users to an organisation failed.
	 *
	 * @param string     $organizationUuid The organization UUID
	 * @param \Exception $exception        What went wrong
	 *
	 * @return void
	 */
	private function logAddUsersFailure(string $organizationUuid, \Exception $exception): void {
		$this->logger->error(
			'OrganisatieService: Failed to add users to organization',
			['organizationUuid' => $organizationUuid, 'error' => $exception->getMessage()]
		);
		// Log detailed error information using PSR-3 logger.
		$this->logger->error(
			'OrganisatieService: Exception details',
			['message' => $exception->getMessage(), 'trace' => $exception->getTraceAsString()]
		);
	}//end logAddUsersFailure()

	/**
	 * Gets admin group usernames.
	 *
	 * @return array Array of admin usernames
	 *
	 * @spec openspec/specs/organisatie-service/spec.md
	 */
	public function getAdminGroupUsernames(): array {
		try {
			return AdminGroupUids::of(groupManager: $this->container->get('OCP\IGroupManager'));
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
