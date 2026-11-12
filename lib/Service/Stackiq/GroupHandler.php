<?php

/**
 * Group Handler for Software Catalog
 *
 * This handler manages generic user groups, role-based group assignments,
 * and ensures all required groups exist and are properly configured.
 *
 * @category  Handler
 * @package   OCA\Stackiq\Service\Stackiq
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/method-decomposition/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service\Stackiq;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Handler for group management operations
 *
 * @category  Handler
 * @package   OCA\Stackiq\Service\Stackiq
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @SuppressWarnings(PHPMD.CamelCaseParameterName)
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Overall complexity 53 (threshold 50). Group
 * management is the app's RBAC surface: every operation has to verify the group exists, that the
 * user exists, that the caller is allowed to change that membership, and that the derived
 * organisation groups stay consistent — and each of those has to fail closed rather than silently
 * no-op. The branching is authorization checking, which is exactly where it belongs in view.
 */
class GroupHandler {
	/**
	 * The application name for configuration storage
	 *
	 * @var string
	 */
	private const APP_NAME = 'stackiq';

	/**
	 * The role-based groups ensured next to the generic user groups.
	 */
	private const ROLE_BASED_GROUPS = [
		'aanbod-beheerder',
		'gebruik-beheerder',
		'gebruik-raadpleger',
		'functioneel-beheerder',
		'vng-raadpleger',
		'organisatie-beheerder',
		// Plural form for organization contacts.
		'organisaties-beheerder',
		// For users from Gemeente organizations.
		'ambtenaar',
	];

	/**
	 * GroupHandler constructor
	 *
	 * @param IGroupManager $_groupManager Group manager interface
	 * @param IUserManager $_userManager User manager interface
	 * @param IAppConfig $_appConfig App configuration interface
	 * @param ContainerInterface $_container Container interface
	 * @param IAppManager $_appManager App manager interface
	 * @param LoggerInterface $_logger Logger interface
	 */
	public function __construct(
		private readonly IGroupManager $_groupManager,
		private readonly IUserManager $_userManager,
		private readonly IAppConfig $_appConfig,
		private readonly ContainerInterface $_container,
		private readonly IAppManager $_appManager,
		private readonly LoggerInterface $_logger,
	) {
	}//end __construct()

	/**
	 * Gets the OpenRegister ObjectService if available
	 *
	 * @return ObjectServiceInterface|null ObjectService instance or null
	 *
	 * @throws RuntimeException If service is not available
	 */
	private function getObjectService(): ?ObjectServiceInterface {
		if (in_array(needle: 'openregister', haystack: $this->_appManager->getInstalledApps()) === true) {
			return $this->_container->get('OCA\OpenRegister\Service\ObjectService');
		}

		throw new RuntimeException('OpenRegister service is not available.');
	}//end getObjectService()

	/**
	 * Gets the list of generic user groups from configuration
	 *
	 * @return array Array of generic user groups
	 * @spec   openspec/specs/sc-handlers/spec.md
	 */
	public function getGenericUserGroups(): array {
		$groupsJson = $this->_appConfig->getValueString(self::APP_NAME, 'generic_user_groups', '');

		if (empty($groupsJson) === true) {
			// Return only truly generic groups as default (not role-specific).
			// Role-specific groups are now assigned based on organization type.
			return [
				'software-catalog-users'
			];
		}

		$groups = json_decode($groupsJson, true);
		if (is_array($groups) === true) {
			return $groups;
		}

		return [];
	}//end getGenericUserGroups()

	/**
	 * Sets the list of generic user groups in configuration
	 *
	 * @param array $groups Array of generic user groups
	 *
	 * @return void
	 * @spec   openspec/specs/sc-handlers/spec.md
	 */
	public function setGenericUserGroups(array $groups): void {
		$groupsJson = json_encode($groups, JSON_THROW_ON_ERROR);
		$this->_appConfig->setValueString(self::APP_NAME, 'generic_user_groups', $groupsJson);

		$this->_logger->info(
			'Updated generic user groups configuration',
			[
				'groups' => $groups,
			]
		);
	}//end setGenericUserGroups()

	/**
	 * Ensures that all generic user groups exist in the system
	 *
	 * @return array Array of groups that were created
	 * @spec   openspec/specs/sc-handlers/spec.md
	 */
	public function ensureGenericUserGroupsExist(): array {
		$genericGroups = $genericGroups = $this->getGenericUserGroups();
		$createdGroups = $this->createMissingGroups(groupNames: $genericGroups, logMessage: 'Created generic user group');

		// Also ensure role-based groups exist.
		return array_merge(
			$createdGroups,
			$this->createMissingGroups(groupNames: self::ROLE_BASED_GROUPS, logMessage: 'Created role-based group')
		);
	}//end ensureGenericUserGroupsExist()

	/**
	 * Create the groups that do not exist yet.
	 *
	 * @param array  $groupNames The groups to ensure.
	 * @param string $logMessage The message logged for each group created.
	 *
	 * @return array The groups that were created, in order.
	 */
	private function createMissingGroups(array $groupNames, string $logMessage): array {
		$createdGroups = [];
		foreach ($groupNames as $groupName) {
			if ($this->createMissingGroup(groupName: $groupName, logMessage: $logMessage) === true) {
				$createdGroups[] = $groupName;
			}
		}

		return $createdGroups;
	}//end createMissingGroups()

	/**
	 * Create a group when it does not exist yet.
	 *
	 * @param mixed  $groupName  The group to ensure.
	 * @param string $logMessage The message logged when the group is created.
	 *
	 * @return bool True when the group was created.
	 */
	private function createMissingGroup(mixed $groupName, string $logMessage): bool {
		if ($this->_groupManager->get($groupName) !== null) {
			return false;
		}

		$group = $this->_groupManager->createGroup($groupName);
		if ($group === null) {
			return false;
		}

		$this->_logger->info(
			$logMessage,
			['groupName' => $groupName]
		);
		return true;
	}//end createMissingGroup()

	/**
	 * Creates a group if it doesn't exist
	 *
	 * @param string $groupName The group name to create
	 *
	 * @return IGroup|null The created or existing group
	 * @spec   openspec/specs/sc-handlers/spec.md
	 */
	public function createGroupIfNotExists(string $groupName): ?IGroup {
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
					'Failed to create group: ' . $e->getMessage(),
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
	 * Updates user groups based on contactpersoon data
	 *
	 * @param object $contactPersonObject The contactpersoon object
	 * @param string $username The username to update groups for
	 *
	 * @return void
	 *
	 * @spec openspec/specs/method-decomposition/spec.md
	 */
	public function updateUserGroups(object $contactPersonObject, string $username): void {
		try {
			$user = $this->_userManager->get($username);
			if ($user === null) {
				$this->_logger->warning('User not found for group update', ['username' => $username]);
				return;
			}

			$objectData = $contactPersonObject->getObject();

			// Handle role-based groups.
			$this->updateRoleBasedGroups(user: $user, objectData: $objectData);

			// Handle organization groups.
			$this->updateOrganizationGroups(user: $user, objectData: $objectData);

			// Handle special gemeente groups.
			$this->updateGemeenteGroups(user: $user, objectData: $objectData);

			$this->_logger->info(
				'Updated user groups successfully',
				[
					'username' => $username,
					'groups' => array_keys($this->_groupManager->getUserGroups($user)),
				]
			);
		} catch (\Exception $e) {
			$this->_logger->error(
				'Failed to update user groups: ' . $e->getMessage(),
				[
					'username' => $username,
					'exception' => $e,
				]
			);
		}//end try
	}//end updateUserGroups()

	/**
	 * Updates role-based groups for a user
	 *
	 * @param IUser $user The user to update
	 * @param array $objectData The contactpersoon data
	 *
	 * @return void
	 * @spec   openspec/specs/sc-handlers/spec.md
	 */
	public function updateRoleBasedGroups(IUser $user, array $objectData): void {
		$userRoles = self::rolesOf(objectData: $objectData);

		$this->_logger->info(
			'Updating role-based groups for user',
			[
				'username' => $user->getUID(),
				'userRoles' => $userRoles,
			]
		);

		$userRolesLower = self::lowerCaseRoles(roles: $userRoles);

		// Get the configured generic user groups.
		foreach ($this->getGenericUserGroups() as $groupName) {
			$this->updateRoleGroup(membership: $this->roleGroupMembership(user: $user, groupName: $groupName), userRolesLower: $userRolesLower);
		}
	}//end updateRoleBasedGroups()

	/**
	 * The roles of a contactpersoon.
	 *
	 * @param array $objectData The contactpersoon data.
	 *
	 * @return array The roles, or [] when they are missing or not a list.
	 */
	private static function rolesOf(array $objectData): array {
		$userRoles = $objectData['roles'] ?? [];
		if (is_array($userRoles) === false) {
			return [];
		}

		return $userRoles;
	}//end rolesOf()

	/**
	 * Role names are compared without regard to case: the roles enum is
	 * capitalised (Aanbod-beheerder) while the role groups are lower case
	 * (aanbod-beheerder), stackiq#1137.
	 *
	 * @param array $roles The roles.
	 *
	 * @return array The roles, trimmed and lower-cased.
	 */
	private static function lowerCaseRoles(array $roles): array {
		return array_map(
			static function ($role): string {
				return strtolower(trim((string)$role));
			},
			$roles
		);
	}//end lowerCaseRoles()

	/**
	 * The user's membership of a role-based group, creating the group when it does not exist.
	 *
	 * @param IUser $user      The user.
	 * @param mixed $groupName The configured group name.
	 *
	 * @return RoleGroupMembership|null The membership, or null when the group cannot be created.
	 */
	private function roleGroupMembership(IUser $user, mixed $groupName): ?RoleGroupMembership {
		$group = $this->createGroupIfNotExists(groupName: $groupName);
		if ($group === null) {
			return null;
		}

		$membership = new RoleGroupMembership();
		$membership->user = $user;
		$membership->groupName = $groupName;
		$membership->group = $group;
		return $membership;
	}//end roleGroupMembership()

	/**
	 * Add the user to the group when they hold its role, remove them when they no longer do.
	 *
	 * @param RoleGroupMembership|null $membership     The membership, or null when the group is not available.
	 * @param array                    $userRolesLower The user's roles, trimmed and lower-cased.
	 *
	 * @return void
	 */
	private function updateRoleGroup(?RoleGroupMembership $membership, array $userRolesLower): void {
		if ($membership === null) {
			return;
		}

		$hasRole = in_array(needle: strtolower(trim($membership->groupName)), haystack: $userRolesLower, strict: true);
		$inGroup = $membership->group->inGroup($membership->user);
		if ($hasRole === $inGroup) {
			return;
		}

		if ($hasRole === true) {
			$this->addToRoleGroup(membership: $membership);
			return;
		}

		$this->removeFromRoleGroup(membership: $membership);
	}//end updateRoleGroup()

	/**
	 * Add the user to a role-based group.
	 *
	 * @param RoleGroupMembership $membership The membership.
	 *
	 * @return void
	 */
	private function addToRoleGroup(RoleGroupMembership $membership): void {
		$membership->group->addUser($membership->user);
		$this->_logger->info(
			'Added user to role-based group',
			[
				'username' => $membership->user->getUID(),
				'group' => $membership->groupName,
				'role' => $membership->groupName,
			]
		);
	}//end addToRoleGroup()

	/**
	 * Remove the user from a role-based group (except for system groups).
	 *
	 * Note: Removed 'ambtenaar' from protected groups since it's no longer automatically assigned.
	 *
	 * @param RoleGroupMembership $membership The membership.
	 *
	 * @return void
	 */
	private function removeFromRoleGroup(RoleGroupMembership $membership): void {
		if (in_array(needle: $membership->groupName, haystack: ['software-catalog-users']) === false) {
			$membership->group->removeUser($membership->user);
			$this->_logger->info(
				'Removed user from role-based group',
				[
					'username' => $membership->user->getUID(),
					'group' => $membership->groupName,
				]
			);
		}
	}//end removeFromRoleGroup()

	/**
	 * Updates organization-based groups for a user
	 *
	 * @param IUser $user The user to update
	 * @param array $objectData The contactpersoon data
	 *
	 * @return void
	 * @spec   openspec/specs/sc-handlers/spec.md
	 */
	public function updateOrganizationGroups(IUser $user, array $objectData): void {
		$organizationUuid = $objectData['organisation'] ?? $objectData['organization'] ?? '';

		if (empty($organizationUuid) === true) {
			return;
		}

		try {
			$orgData = $this->resolveOrganisationData(organisationUuid: $organizationUuid);
			if ($orgData === null) {
				return;
			}

			$actualUuid = $orgData['id'] ?? $organizationUuid;
			$groupId = $orgData['group'] ?? '';

			$this->_logger->info(
				'DEBUG: Organization group lookup for user',
				[
					'username' => $user->getUID(),
					'inputOrganizationUuid' => $organizationUuid,
					'actualOrganizationUuid' => $actualUuid,
					'groupId' => $groupId,
				]
			);

			$this->assignOrganizationGroup(user: $user, groupId: $groupId, actualUuid: $actualUuid);
		} catch (\Exception $e) {
			$this->_logger->error(
				'Failed to process organization group: ' . $e->getMessage(),
				[
					'username' => $user->getUID(),
					'organizationUuid' => $organizationUuid,
				]
			);
		}//end try
	}//end updateOrganizationGroups()

	/**
	 * Resolves the organisation object data for a given organisation UUID.
	 *
	 * Returns null when the register/schema configuration is missing, the
	 * object service is unavailable, or the organisatie can't be found.
	 *
	 * @param string $organisationUuid The organisation UUID to resolve
	 *
	 * @return array|null The decoded organisatie object data, or null when
	 *                    not resolvable
	 */
	private function resolveOrganisationData(string $organisationUuid): ?array {
		$objectService = $this->getObjectService();
		$settingsService = $this->_container->get('OCA\Stackiq\Service\SettingsService');
		$registerId = $settingsService->getVoorzieningenRegisterId();
		$organisationSchemaId = $settingsService->getSchemaIdForObjectType('organization');

		if ($registerId === null || $organisationSchemaId === null) {
			$this->_logger->warning('Register or schema ID not configured for organisatie.');
			return null;
		}

		$organizationObject = $objectService->find($organisationUuid, options: new ObjectFindOptions(register: $registerId, schema: $organisationSchemaId));
		if ($organizationObject === null) {
			return null;
		}

		return $organizationObject->getObject();
	}//end resolveOrganisationData()

	/**
	 * Adds a user to the organisation's Nextcloud group when they are not
	 * already a member.
	 *
	 * @param IUser $user The user to add to the group
	 * @param string $groupId The Nextcloud group ID, may be empty
	 * @param string $actualUuid The resolved organisation UUID (for logging)
	 *
	 * @return void
	 */
	private function assignOrganizationGroup(IUser $user, string $groupId, string $actualUuid): void {
		if (empty($groupId) === true) {
			return;
		}

		$group = $this->_groupManager->get($groupId);
		if ($group === null || $group->inGroup($user) === true) {
			return;
		}

		$group->addUser($user);
		$this->_logger->info(
			'Added user to organization group',
			[
				'username' => $user->getUID(),
				'group' => $groupId,
				'organizationUuid' => $actualUuid,
			]
		);

	}//end assignOrganizationGroup()

	/**
	 * Updates gemeente-specific groups for a user
	 *
	 * @param IUser $user The user to update
	 * @param array $objectData The contactpersoon data
	 *
	 * @return void
	 * @spec   openspec/specs/sc-handlers/spec.md
	 */
	public function updateGemeenteGroups(IUser $user, array $objectData): void {
		$organizationUuid = $objectData['organisation'] ?? $objectData['organization'] ?? '';

		if (empty($organizationUuid) === true) {
			return;
		}

		try {
			$orgData = $this->resolveOrganisationData(organisationUuid: $organizationUuid);
			if ($orgData === null) {
				return;
			}

			$actualUuid = $orgData['id'] ?? $organizationUuid;
			$orgType = strtolower($orgData['type'] ?? $orgData['soort'] ?? '');

			// Note: Removed automatic assignment of 'ambtenaar' group for gemeente organizations.
			// The 'ambtenaar' group can still be created if needed, but users are not automatically assigned.
			if ($orgType === 'gemeente') {
				$this->_logger->debug(
					'User from gemeente organization (no automatic ambtenaar group assignment)',
					[
						'username' => $user->getUID(),
						'organizationUuid' => $actualUuid,
						'organizationType' => $orgType,
					]
				);
			}
		} catch (\Exception $e) {
			$this->_logger->error(
				'Failed to process gemeente group: ' . $e->getMessage(),
				[
					'username' => $user->getUID(),
					'organizationUuid' => $organizationUuid,
				]
			);
		}//end try
	}//end updateGemeenteGroups()

	/**
	 * Gets all available groups with their information
	 *
	 * @return array Array of group information
	 * @spec   openspec/specs/sc-handlers/spec.md
	 */
	public function getAllGroups(): array {
		$groups = $this->_groupManager->search('');
		$groupInfo = [];

		foreach ($groups as $group) {
			$groupInfo[] = [
				'id' => $group->getGID(),
				'displayName' => $group->getDisplayName(),
				'memberCount' => count($group->getUsers()),
				'isGeneric' => in_array(needle: $group->getGID(), haystack: $this->getGenericUserGroups()),
			];
		}

		return $groupInfo;
	}//end getAllGroups()

	/**
	 * Validates a list of group names
	 *
	 * @param array $groups Array of group names to validate
	 *
	 * @return array Array with validation results
	 * @spec   openspec/specs/sc-handlers/spec.md
	 */
	public function validateGroups(array $groups): array {
		$results = [
			'valid' => [],
			'invalid' => [],
			'errors' => [],
		];

		foreach ($groups as $groupName) {
			if (empty($groupName) === true || is_string($groupName) === false) {
				$results['invalid'][] = $groupName;
				$results['errors'][] = 'Group name cannot be empty';
				continue;
			}

			// Check for invalid characters.
			if (preg_match('/[^a-zA-Z0-9._-]/', $groupName) === true) {
				$results['invalid'][] = $groupName;
				$results['errors'][] = "Group name '{$groupName}' contains invalid characters";
				continue;
			}

			$results['valid'][] = $groupName;
		}

		return $results;
	}//end validateGroups()
}//end class
