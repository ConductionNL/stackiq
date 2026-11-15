<?php

/**
 * Contract of RoleGroupSettings
 *
 * The public surface of RoleGroupSettings that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Stackiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
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

namespace OCA\Stackiq\Kernel\Contracts;

/**
 * Contract of RoleGroupSettings.
 *
 * @category Kernel
 * @package  OCA\Stackiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/stackiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface RoleGroupSettingsInterface {

	/**
	 * Gets all available groups with their information
	 *
	 * @return array Array of group information
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function getAllGroups(): array;

	/**
	 * Gets the list of generic user groups from configuration
	 *
	 * @return array Array of generic user groups
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function getGenericUserGroups(): array;

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
	public function getOrganizationAdminGroups(): array;

	/**
	 * Gets the list of super user groups from configuration
	 *
	 * @return array Array of super user groups
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function getSuperUserGroups(): array;

	/**
	 * Get user groups configuration only
	 *
	 * @return array User groups configuration
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function getUserGroupsConfig(): array;

	/**
	 * Update generic user groups with validation
	 *
	 * @param array $groups Groups to set.
	 *
	 * @return array Update result with validation.
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function updateGenericUserGroups(array $groups): array;

	/**
	 * Update organization admin groups with validation
	 *
	 * @param array $groups Groups to set.
	 *
	 * @return array Update result with validation.
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function updateOrganizationAdminGroups(array $groups): array;

	/**
	 * Update super user groups with validation
	 *
	 * @param array $groups Groups to set.
	 *
	 * @return array Update result with validation.
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function updateSuperUserGroups(array $groups): array;

	/**
	 * Update user groups configuration
	 *
	 * @param array $config User groups configuration data
	 *
	 * @return array Result of the update operation
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function updateUserGroupsConfig(array $config): array;

	/**
	 * Validates a list of group names
	 *
	 * @param array $groups Array of group names to validate
	 *
	 * @return array Array with validation results
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function validateGroups(array $groups): array;

	/**
	 * Sets the list of generic user groups in configuration
	 *
	 * @param array $groups Array of generic user groups
	 *
	 * @return void
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function setGenericUserGroups(array $groups): void;

	/**
	 * Sets the list of organization admin groups in configuration
	 *
	 * @param array $groups Array of organization admin groups
	 *
	 * @return void
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function setOrganizationAdminGroups(array $groups): void;

	/**
	 * Sets the list of super user groups in configuration
	 *
	 * @param array $groups Array of super user groups
	 *
	 * @return void
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function setSuperUserGroups(array $groups): void;
}//end interface
