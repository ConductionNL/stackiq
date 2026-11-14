<?php

/**
 * Contract of ContactpersoonService
 *
 * The public surface of ContactpersoonService that other components call, so they depend
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
 * Contract of ContactpersoonService.
 *
 * @category Kernel
 * @package  OCA\Stackiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/stackiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ContactpersoonServiceInterface {

	/**
	 * Disable user account for a contactpersoon.
	 *
	 * @param string $contactPersonId The UUID of the contactpersoon.
	 *
	 * @return void
	 *
	 * @throws \Exception If disabling fails.
	 * @spec   openspec/specs/contactpersoon-sync/spec.md
	 */
	public function disableUserForContactpersoon(string $contactPersonId): void;

	/**
	 * Enable user account for a contactpersoon.
	 *
	 * @param string $contactPersonId The UUID of the contactpersoon.
	 *
	 * @return void
	 *
	 * @throws \Exception If enabling fails.
	 * @spec   openspec/specs/contactpersoon-sync/spec.md
	 */
	public function enableUserForContactpersoon(string $contactPersonId): void;

	/**
	 * Gets bulk user information for multiple contact persons
	 *
	 * This method retrieves user information for multiple contact persons in a single operation,
	 * which is more efficient than individual calls.
	 *
	 * @param array $contactPersonIds Array of contact person IDs/UUIDs
	 *
	 * @return array Array of user information keyed by contact person ID
	 *
	 * @throws \Exception If bulk user info retrieval fails
	 * @spec   openspec/specs/contactpersoon-sync/spec.md
	 */
	public function getBulkUserInfo(array $contactPersonIds): array;

	/**
	 * Gets all contact persons for an organization with user details spliced in
	 *
	 * This method retrieves contact person objects linked to a specific organization
	 * and enhances each contact person with their corresponding user details from Nextcloud.
	 *
	 * @param string $organizationUuid The organization UUID to get contact persons for
	 *
	 * @return array Array of contact person objects with user details spliced in
	 *
	 * @throws \Exception If contact person retrieval fails
	 * @spec   openspec/specs/contactpersoon-sync/spec.md
	 */
	public function getContactPersonsWithUserDetailsForOrganization(string $organizationUuid): array;

	/**
	 * Processes a contactpersoon object to create a user account.
	 *
	 * If the contactpersoon object doesn't have a user or the user is missing,
	 * this method will create a user account with appropriate status.
	 *
	 * @param object $contactPersonObject The contactpersoon object to process.
	 * @param bool $isUpdate Whether this is an update operation.
	 *
	 * @return bool True if processing was successful.
	 *
	 * @throws \Exception If processing fails.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) $isUpdate is a simple create-vs-update toggle
	 *
	 * @spec openspec/specs/method-decomposition/spec.md
	 */
	public function processContactpersoon(object $contactPersonObject, bool $isUpdate = false): bool;

	/**
	 * Handles contact person deletion
	 *
	 * @param object $contactObject The contact object being deleted
	 *
	 * @return void
	 * @spec   openspec/specs/contactpersoon-sync/spec.md
	 */
	public function handleContactDeletion(object $contactObject): void;
}//end interface
