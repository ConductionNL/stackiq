<?php

/**
 * Role Group Settings for Stackiq
 *
 * Moved out of the general settings service: the configured generic,
 * organisation admin and super user group lists, the validation of group
 * names, the group listing for the settings page, and the combined user
 * groups configuration read and update.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service\Settings
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/settings-service/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service\Settings;

use OCA\Stackiq\AppInfo\AppIdentity;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroupManager;
use Psr\Log\LoggerInterface;

/**
 * Reads, validates and writes the role group lists.
 *
 * Not final, like the other settings handlers in this directory, so the
 * services that depend on it can be tested against a mock.
 *
 * @spec openspec/specs/settings-service/spec.md
 */
class RoleGroupSettings {

	/**
	 * The name each role group list goes by in result messages and logs.
	 *
	 * @var array<string, string>
	 */
	private const ROLE_GROUP_LABELS = [
		'generic' => 'generic user groups',
		'organizationAdmin' => 'organization admin groups',
		'superUser' => 'super user groups',
	];


	/**
	 * Constructor.
	 *
	 * @param IAppConfig      $config       The app config holding the group lists.
	 * @param IAppManager     $appManager   Tells whether user_management is installed.
	 * @param IGroupManager   $groupManager Lists the Nextcloud groups.
	 * @param LoggerInterface $logger       The logger.
	 */
	public function __construct(
		private readonly IAppConfig $config,
		private readonly IAppManager $appManager,
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Gets the list of generic user groups from configuration
	 *
	 * @return array Array of generic user groups
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function getGenericUserGroups(): array {
		$groupsJson = $this->config->getValueString(AppIdentity::APP_ID, 'generic_user_groups', '');

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
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function setGenericUserGroups(array $groups): void {
		$groupsJson = json_encode($groups, JSON_THROW_ON_ERROR);
		$this->config->setValueString(AppIdentity::APP_ID, 'generic_user_groups', $groupsJson);

		$this->logger->info(
			'Updated generic user groups configuration',
			[
				'groups' => $groups,
			]
		);
	}//end setGenericUserGroups()

	/**
	 * Gets the list of organization admin groups from configuration
	 *
	 * Returns the list an admin saved under `organization_admin_groups`, or an
	 * empty list when nothing is saved. There is no default list: commit
	 * bc4dc9ea dropped the old default (organisaties-beheerder) on purpose,
	 * and first contacts are not added to these groups automatically (see
	 * ContactPersonHandler::assignUserGroups()). Until stackiq#1136 this getter
	 * returned an empty list unconditionally, which discarded the saved
	 * setting as well.
	 *
	 * @return array Array of organization admin groups
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function getOrganizationAdminGroups(): array {
		$groupsJson = $this->config->getValueString(AppIdentity::APP_ID, 'organization_admin_groups', '');

		if (empty($groupsJson) === true) {
			return [];
		}

		$groups = json_decode($groupsJson, true);
		if (is_array($groups) === true) {
			return $groups;
		}

		return [];
	}//end getOrganizationAdminGroups()

	/**
	 * Sets the list of organization admin groups in configuration
	 *
	 * @param array $groups Array of organization admin groups
	 *
	 * @return void
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function setOrganizationAdminGroups(array $groups): void {
		$groupsJson = json_encode($groups, JSON_THROW_ON_ERROR);
		$this->config->setValueString(AppIdentity::APP_ID, 'organization_admin_groups', $groupsJson);

		$this->logger->info(
			'Updated organization admin groups configuration',
			[
				'groups' => $groups,
			]
		);
	}//end setOrganizationAdminGroups()

	/**
	 * Gets the list of super user groups from configuration
	 *
	 * @return array Array of super user groups
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function getSuperUserGroups(): array {
		$groupsJson = $this->config->getValueString(AppIdentity::APP_ID, 'super_user_groups', '');

		if (empty($groupsJson) === true) {
			// Return default groups if no configuration exists.
			return [
				'admin',
				'software-catalog-admins',
			];
		}

		$groups = json_decode($groupsJson, true);
		if (is_array($groups) === true) {
			return $groups;
		}

		return [];
	}//end getSuperUserGroups()

	/**
	 * Sets the list of super user groups in configuration
	 *
	 * @param array $groups Array of super user groups
	 *
	 * @return void
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function setSuperUserGroups(array $groups): void {
		$groupsJson = json_encode($groups, JSON_THROW_ON_ERROR);
		$this->config->setValueString(AppIdentity::APP_ID, 'super_user_groups', $groupsJson);

		$this->logger->info(
			'Updated super user groups configuration',
			[
				'groups' => $groups,
			]
		);
	}//end setSuperUserGroups()

	/**
	 * Validates a list of group names
	 *
	 * @param array $groups Array of group names to validate
	 *
	 * @return array Array with validation results
	 * @spec   openspec/specs/settings-service/spec.md
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
			if (preg_match('/[^a-zA-Z0-9._-]/', $groupName) === 1) {
				$results['invalid'][] = $groupName;
				$results['errors'][] = "Group name '{$groupName}' contains invalid characters";
				continue;
			}

			$results['valid'][] = $groupName;
		}

		return $results;
	}//end validateGroups()

	/**
	 * Gets all available groups with their information
	 *
	 * @return array Array of group information
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function getAllGroups(): array {
		$groups = [];

		// Get group manager if possible.
		if ($this->appManager->isInstalled('user_management') === true) {
			try {
				$groupManager = $this->groupManager;
				$allGroups = $groupManager->search('');

				foreach ($allGroups as $group) {
					$groups[] = [
						'id' => $group->getGID(),
						'displayName' => $group->getDisplayName(),
						'memberCount' => count($group->getUsers()),
						'isGeneric' => in_array($group->getGID(), $this->getGenericUserGroups()) === true,
					];
				}
			} catch (\Exception $e) {
				$this->logger->error('Failed to get all groups: ' . $e->getMessage());
			}
		}

		return $groups;
	}//end getAllGroups()

	/**
	 * Update generic user groups with validation
	 *
	 * @param array $groups Groups to set.
	 *
	 * @return array Update result with validation.
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function updateGenericUserGroups(array $groups): array {
		return $this->updateRoleGroups(groups: $groups, role: 'generic');
	}//end updateGenericUserGroups()

	/**
	 * Update organization admin groups with validation
	 *
	 * @param array $groups Groups to set.
	 *
	 * @return array Update result with validation.
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function updateOrganizationAdminGroups(array $groups): array {
		return $this->updateRoleGroups(groups: $groups, role: 'organizationAdmin');
	}//end updateOrganizationAdminGroups()

	/**
	 * Update super user groups with validation
	 *
	 * @param array $groups Groups to set.
	 *
	 * @return array Update result with validation.
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function updateSuperUserGroups(array $groups): array {
		return $this->updateRoleGroups(groups: $groups, role: 'superUser');
	}//end updateSuperUserGroups()

	/**
	 * Validate a role's group list and store it when every name is valid.
	 *
	 * @param array  $groups Groups to set.
	 * @param string $role   The role: generic, organizationAdmin or superUser.
	 *
	 * @psalm-param 'generic'|'organizationAdmin'|'superUser' $role
	 *
	 * @return array Update result with validation.
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	private function updateRoleGroups(array $groups, string $role): array {
		$label = self::ROLE_GROUP_LABELS[$role];
		try {
			$validation = $this->validateGroups(groups: $groups);

			if (empty($validation['invalid']) === false) {
				return [
					'success' => false,
					'message' => 'Invalid group names provided',
					'validation' => $validation,
				];
			}

			match ($role) {
				'generic' => $this->setGenericUserGroups(groups: $validation['valid']),
				'organizationAdmin' => $this->setOrganizationAdminGroups(groups: $validation['valid']),
				'superUser' => $this->setSuperUserGroups(groups: $validation['valid']),
			};

			return [
				'success' => true,
				'message' => ucfirst($label) . ' updated successfully',
				'groups' => $validation['valid'],
			];
		} catch (\Exception $e) {
			$this->logger->error('SettingsService: Failed to update ' . $label, ['exception' => $e->getMessage()]);
			return [
				'success' => false,
				'message' => 'Failed to update ' . $label . ': ' . $e->getMessage(),
			];
		}//end try
	}//end updateRoleGroups()

	/**
	 * Get user groups configuration only
	 *
	 * @return array User groups configuration
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function getUserGroupsConfig(): array {
		try {
			$config = [
				'generic' => $this->getGenericUserGroups(),
				'organizationAdmin' => $this->getOrganizationAdminGroups(),
				'superUser' => $this->getSuperUserGroups(),
				'allGroups' => $this->getAllGroups(),
			];

			return [
				'success' => true,
				'config' => $config,
				'timestamp' => time(),
			];
		} catch (\Exception $e) {
			$this->logger->error(
				'Failed to get user groups config',
				[
					'exception' => $e->getMessage(),
				]
			);
			return [
				'success' => false,
				'message' => 'Failed to get user groups config: ' . $e->getMessage(),
			];
		}//end try
	}//end getUserGroupsConfig()

	/**
	 * Update user groups configuration
	 *
	 * @param array $config User groups configuration data
	 *
	 * @return array Result of the update operation
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function updateUserGroupsConfig(array $config): array {
		try {
			$results = [];

			if (isset($config['generic']) === true) {
				$results['generic'] = $this->updateGenericUserGroups(groups: $config['generic']);
			}

			if (isset($config['organizationAdmin']) === true) {
				$results['organizationAdmin'] = $this->updateOrganizationAdminGroups(groups: $config['organizationAdmin']);
			}

			if (isset($config['superUser']) === true) {
				$results['superUser'] = $this->updateSuperUserGroups(groups: $config['superUser']);
			}

			// Check if any updates failed.
			$failed = array_filter(
				$results,
				function ($result) {
					return $result['success'] === false;
				}
			);

			if (empty($failed) === false) {
				return [
					'success' => false,
					'message' => 'Some user group updates failed',
					'results' => $results,
				];
			}

			return [
				'success' => true,
				'message' => 'User groups configuration updated successfully',
				'config' => $this->getUserGroupsConfig(),
			];
		} catch (\Exception $e) {
			$this->logger->error(
				'Failed to update user groups config',
				[
					'exception' => $e->getMessage(),
					'config' => $config,
				]
			);
			return [
				'success' => false,
				'message' => 'Failed to update user groups config: ' . $e->getMessage(),
			];
		}//end try
	}//end updateUserGroupsConfig()
}//end class
