<?php

/**
 * Contract of ContactPersonHandler
 *
 * The public surface of ContactPersonHandler that other components call, so they depend
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
 * Contract of ContactPersonHandler.
 *
 * @category Kernel
 * @package  OCA\Stackiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/stackiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ContactPersonHandlerInterface {

	/**
	 * Adds a user to the organization entity (OpenRegister entity, not object)
	 *
	 * This method ensures that an organization entity exists in OpenRegister's database
	 * before adding the user to it. If the entity doesn't exist, it will be created
	 * from the organization object data.
	 *
	 * @param object $contactPersonObject The contactpersoon object
	 * @param string $username The username to add
	 * @param string|null $organizationUuidOverride Optional organization UUID to use instead of extracting from object
	 *                                              (useful when organisatie field was removed from object data)
	 *
	 * @return void
	 * @spec   openspec/specs/sc-handlers/spec.md
	 */
	public function addUserToOrganizationEntity(
		object $contactPersonObject,
		string $username,
		?string $organizationUuidOverride = null,
	): void;

	/**
	 * Creates a user account for a contact person.
	 *
	 * @param object $contactPersonObject The contact person object.
	 * @param bool $isFirstContact Whether this is the first contact.
	 *
	 * @return \OCP\IUser|null The created user or null if failed.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) $isFirstContact is a simple role-assignment toggle
	 * @spec                                        openspec/specs/sc-handlers/spec.md
	 */
	public function createUserAccount(object $contactPersonObject, bool $isFirstContact = false): ?\OCP\IUser;

	/**
	 * Updates user groups when contact person data changes
	 * Note: Role assignment is now based on organization type, not individual roles
	 *
	 * @param \OCP\IUser $user The user to update
	 * @param array $contactData The updated contact person data
	 *
	 * @return void
	 * @spec   openspec/specs/sc-handlers/spec.md
	 */
	public function updateUserGroupsFromContactData(\OCP\IUser $user, array $contactData): void;

	/**
	 * Validates an email address for use as a Nextcloud username.
	 * Returns null if valid, or an error message string if invalid.
	 *
	 * If the email contains subaddressing (a +tag), it is stripped before
	 * validation since Nextcloud does not support + in usernames. The
	 * sanitized form will be used as the actual username.
	 *
	 * @param string $email The email address to validate.
	 *
	 * @return string|null Null if valid, error message if invalid.
	 * @spec   openspec/specs/sc-handlers/spec.md
	 */
	public function validateEmailForUsername(string $email): ?string;
}//end interface
