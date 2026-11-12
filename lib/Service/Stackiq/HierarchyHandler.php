<?php

/**
 * Hierarchy Handler for Software Catalog
 *
 * This handler manages organizational hierarchy, beheerder assignments,
 * and manager relationships within organizations.
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

use OCP\IGroupManager;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Handler for organizational hierarchy management
 *
 * @category  Handler
 * @package   OCA\Stackiq\Service\Stackiq
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
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
class HierarchyHandler {
	/**
	 * HierarchyHandler constructor
	 *
	 * @param OrganizationHandler $_organizationHandler Organization handler
	 * @param ContactPersonHandler $_contactPersonHandler Contact person handler
	 * @param LoggerInterface $_logger Logger interface
	 * @param IUserManager $_userManager User manager interface
	 * @param IGroupManager $_groupManager Group manager interface
	 */
	public function __construct(
		private readonly OrganizationHandler $_organizationHandler,
		private readonly ContactPersonHandler $_contactPersonHandler,
		private readonly LoggerInterface $_logger,
		private readonly IUserManager $_userManager,
		private readonly IGroupManager $_groupManager,
	) {
	}//end __construct()

	/**
	 * Ensures organization has at least one beheerder and manages user hierarchy
	 *
	 * @param object $contactgegevensObject The contactgegevens object
	 * @param string $username The username being processed
	 *
	 * @return void
	 * @spec   openspec/specs/sc-handlers/spec.md
	 */
	public function ensureOrganizationBeheerder(object $contactgegevensObject, string $username): void {
		try {
			$objectData = $contactgegevensObject->getObject();
			$organizationUuid = (string)($objectData['organisation'] ?? $objectData['organization'] ?? '');

			if (empty($organizationUuid) === true) {
				$this->_logger->debug('No organization linked to contactgegevens.');
				return;
			}

			// Get organization and check for existing beheerders.
			$organizationBeheerders = $this->_organizationHandler->getOrganizationBeheerders($organizationUuid);

			if (empty($organizationBeheerders) === true) {
				// No beheerders found - make this user the beheerder.
				$this->_contactPersonHandler->assignBeheerderRole(
					contactPersonObject: $contactgegevensObject,
					username: $username,
					organizationUuid: $organizationUuid
				);
				$organizationBeheerders = [$username];
				// Update our list.
			}

			// Set up manager relationships.
			$this->setupManagerRelationships(
				username: $username,
				organizationBeheerders: $organizationBeheerders,
				organizationUuid: $organizationUuid
			);
		} catch (\Exception $e) {
			$this->_logger->error(
				'Failed to ensure organization beheerder: ' . $e->getMessage(),
				[
					'username' => $username,
					'exception' => $e,
				]
			);
		}//end try
	}//end ensureOrganizationBeheerder()

	/**
	 * Sets up manager relationships for users in an organization
	 *
	 * @param string $username The current username being processed
	 * @param array $organizationBeheerders Array of beheerder usernames
	 * @param string $organizationUuid The organization UUID
	 *
	 * @return void
	 * @spec   openspec/specs/sc-handlers/spec.md
	 */
	public function setupManagerRelationships(
		string $username,
		array $organizationBeheerders,
		string $organizationUuid,
	): void {
		try {
			if (empty($organizationBeheerders) === true) {
				return;
			}

			$primaryManager = $this->resolvePrimaryManager(organizationBeheerders: $organizationBeheerders);
			$this->assignManagerForCurrentUser(username: $username, organizationBeheerders: $organizationBeheerders, primaryManager: $primaryManager);
			$this->assignManagerForOtherBeheerders(organizationBeheerders: $organizationBeheerders, primaryManager: $primaryManager);

			$this->_logger->info(
				'Set up manager relationships',
				[
					'organization' => $organizationUuid,
					'primaryManager' => $primaryManager,
					'allBeheerders' => $organizationBeheerders,
					'processedUser' => $username,
				]
			);
		} catch (\Exception $e) {
			$this->_logger->error(
				'Failed to setup manager relationships: ' . $e->getMessage(),
				[
					'username' => $username,
					'organization' => $organizationUuid,
					'exception' => $e,
				]
			);
		}//end try
	}//end setupManagerRelationships()

	/**
	 * Pick the primary manager from the list of beheerders.
	 *
	 * The oldest beheerder (first element) becomes the manager.
	 *
	 * @param array<int,string> $organizationBeheerders Beheerder usernames
	 *
	 * @return string The primary manager's username
	 */
	private function resolvePrimaryManager(array $organizationBeheerders): string {
		return $organizationBeheerders[0];
	}//end resolvePrimaryManager()

	/**
	 * Set the primary beheerder as the current user's manager when they
	 * are not themselves a beheerder.
	 *
	 * @param string $username The current username
	 * @param array<int,string> $organizationBeheerders Beheerder usernames
	 * @param string $primaryManager The primary manager
	 *
	 * @return void
	 */
	private function assignManagerForCurrentUser(string $username, array $organizationBeheerders, string $primaryManager): void {
		if (in_array(needle: $username, haystack: $organizationBeheerders) === true) {
			return;
		}

		$this->_contactPersonHandler->setUserManager(username: $username, managerUsername: $primaryManager);

	}//end assignManagerForCurrentUser()

	/**
	 * Point all secondary beheerders at the primary manager.
	 *
	 * @param array<int,string> $organizationBeheerders Beheerder usernames
	 * @param string $primaryManager The primary manager
	 *
	 * @return void
	 */
	private function assignManagerForOtherBeheerders(array $organizationBeheerders, string $primaryManager): void {
		if (count($organizationBeheerders) <= 1) {
			return;
		}

		foreach ($organizationBeheerders as $maintainer) {
			if ($maintainer === $primaryManager) {
				continue;
			}

			$this->_contactPersonHandler->setUserManager(username: $maintainer, managerUsername: $primaryManager);
		}

	}//end assignManagerForOtherBeheerders()

	/**
	 * Gets organizational hierarchy information for a user
	 *
	 * @param string $username The username to get hierarchy for
	 *
	 * @return array Array containing hierarchy information
	 *
	 * @spec openspec/specs/method-decomposition/spec.md
	 */
	public function getUserHierarchy(string $username): array {
		try {
			return $this->buildUserHierarchy(username: $username);
		} catch (\Exception $e) {
			$this->_logger->error(
				'Failed to get user hierarchy: ' . $e->getMessage(),
				[
					'username' => $username,
					'exception' => $e,
				]
			);
			return [];
		}//end try
	}//end getUserHierarchy()

	/**
	 * Build the hierarchy information of a user: manager, subordinates and roles.
	 *
	 * @param string $username The username to get hierarchy for
	 *
	 * @return array Array containing hierarchy information
	 */
	private function buildUserHierarchy(string $username): array {
		$hierarchy = $this->emptyHierarchy(username: $username);

		// Get user's manager.
		$manager = $this->_contactPersonHandler->getUserManager($username);
		if ($manager !== null) {
			$hierarchy['manager'] = $manager;
		}

		// Find subordinates (users who have this user as manager).
		$subordinates = $this->findSubordinates(username: $username);
		$hierarchy['subordinates'] = $subordinates;

		// Check if user is a beheerder.
		$hierarchy['isBeheerder'] = $this->isUserMaintainer(username: $username);

		// Check if user is primary manager (has subordinates and no manager).
		$hierarchy['isPrimaryManager'] = empty($hierarchy['manager']) === true
			&& empty($hierarchy['subordinates']) === false;

		return $hierarchy;
	}//end buildUserHierarchy()

	/**
	 * The hierarchy of a user before anything is looked up.
	 *
	 * @param string $username The username
	 *
	 * @return array The hierarchy with no manager, subordinates or roles
	 */
	private function emptyHierarchy(string $username): array {
		return [
			'username' => $username,
			'manager' => null,
			'subordinates' => [],
			'organization' => null,
			'isBeheerder' => false,
			'isPrimaryManager' => false,
		];
	}//end emptyHierarchy()

	/**
	 * Finds all subordinates for a given user
	 *
	 * @param string $username The username to find subordinates for
	 *
	 * @return array Array of subordinate usernames
	 */
	private function findSubordinates(string $username): array {
		$subordinates = [];

		try {
			$this->collectSubordinates(username: $username, subordinates: $subordinates);
		} catch (\Exception $e) {
			$this->_logger->error(
				'Failed to find subordinates: ' . $e->getMessage(),
				[
					'username' => $username,
					'exception' => $e,
				]
			);
		}//end try

		return $subordinates;
	}//end findSubordinates()

	/**
	 * Append every user whose manager is the given user to the list.
	 *
	 * Appends in place, so when a lookup fails part-way the subordinates
	 * found before the failure are kept.
	 *
	 * @param string $username     The username to find subordinates for
	 * @param array  $subordinates The list to append subordinate usernames to
	 *
	 * @return void
	 */
	private function collectSubordinates(string $username, array &$subordinates): void {
		// Get all users and check their managers.
		$userManager = $this->_userManager;
		$users = $userManager->search('');

		foreach ($users as $user) {
			$userUsername = $user->getUID();
			$manager = $this->_contactPersonHandler->getUserManager($userUsername);

			if ($manager === $username) {
				$subordinates[] = $userUsername;
			}
		}
	}//end collectSubordinates()

	/**
	 * Checks if a user is a beheerder
	 *
	 * @param string $username The username to check
	 *
	 * @return bool True if user is a beheerder
	 */
	private function isUserMaintainer(string $username): bool {
		try {
			return $this->isInMaintainerGroup(username: $username);
		} catch (\Exception $e) {
			$this->_logger->error(
				'Failed to check if user is beheerder: ' . $e->getMessage(),
				[
					'username' => $username,
					'exception' => $e,
				]
			);
			return false;
		}//end try
	}//end isUserBeheerder()

	/**
	 * Whether the user is a member of the maintainer group; a missing group
	 * or an unknown user is not.
	 *
	 * @param string $username The username to check
	 *
	 * @return bool True if the user is in the maintainer group
	 */
	private function isInMaintainerGroup(string $username): bool {
		$groupManager = $this->_groupManager;
		$maintainerGroup = $groupManager->get('maintainer');

		if ($maintainerGroup === null) {
			return false;
		}

		$userManager = $this->_userManager;
		$user = $userManager->get($username);

		if ($user === null) {
			return false;
		}

		return $maintainerGroup->inGroup($user);
	}//end isInMaintainerGroup()

	/**
	 * Gets complete organizational structure
	 *
	 * @param string $organizationUuid The organization UUID
	 *
	 * @return array Array containing organizational structure
	 *
	 * @spec openspec/specs/method-decomposition/spec.md
	 */
	public function getOrganizationStructure(string $organizationUuid): array {
		try {
			return $this->buildOrganizationStructure(organizationUuid: $organizationUuid);
		} catch (\Exception $e) {
			$this->_logger->error(
				'Failed to get organization structure: ' . $e->getMessage(),
				[
					'organization' => $organizationUuid,
					'exception' => $e,
				]
			);
			return [];
		}//end try
	}//end getOrganizationStructure()

	/**
	 * Build the structure of an organisation: its beheerders, the primary
	 * manager and the hierarchy of each beheerder.
	 *
	 * @param string $organizationUuid The organization UUID
	 *
	 * @return array Array containing organizational structure
	 */
	private function buildOrganizationStructure(string $organizationUuid): array {
		$structure = $this->emptyStructure(organizationUuid: $organizationUuid);

		// Get all beheerders for this organization.
		$beheerders = $this->_organizationHandler->getOrganizationBeheerders($organizationUuid);
		$structure['beheerders'] = $beheerders;

		if (empty($beheerders) === false) {
			$structure['primaryManager'] = $beheerders[0];
		}

		// Build hierarchy tree.
		foreach ($beheerders as $maintainer) {
			$hierarchy = $this->getUserHierarchy(username: $maintainer);
			$structure['hierarchy'][] = $hierarchy;
		}

		return $structure;
	}//end buildOrganizationStructure()

	/**
	 * The structure of an organisation before anything is looked up.
	 *
	 * @param string $organizationUuid The organization UUID
	 *
	 * @return array The structure with no beheerders, manager or hierarchy
	 */
	private function emptyStructure(string $organizationUuid): array {
		return [
			'organization' => $organizationUuid,
			'beheerders' => [],
			'primaryManager' => null,
			'hierarchy' => [],
		];
	}//end emptyStructure()
}//end class
