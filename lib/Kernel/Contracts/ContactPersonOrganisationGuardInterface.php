<?php

/**
 * Contract of ContactPersonOrganisationGuard
 *
 * The public surface of ContactPersonOrganisationGuard that other components call, so they depend
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
 * Contract of ContactPersonOrganisationGuard.
 *
 * @category Kernel
 * @package  OCA\Stackiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/stackiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ContactPersonOrganisationGuardInterface {

	/**
	 * Whether the caller may write this contact person.
	 *
	 * @param array<string, mixed>      $contactData The contact person's data.
	 * @param array<string, mixed>|null $oldData     The stored data, or null on a create.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/contactpersoon-sync/spec.md#requirement-the-system-shall-only-accept-a-contact-person-from-someone-who-may-add-members-to-its-organisation-req-003
	 */
	public function allows(array $contactData, ?array $oldData = null): bool;

	/**
	 * Whether the change from the stored contact person needs the check again.
	 *
	 * Every key that decides which account joins which organisation, or which account
	 * the name sync writes to, is compared on its own.
	 *
	 * @param array<string, mixed>      $contactData The new data.
	 * @param array<string, mixed>|null $oldData     The stored data, or null when there is none.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/contactpersoon-sync/spec.md#requirement-the-system-shall-only-accept-a-contact-person-from-someone-who-may-add-members-to-its-organisation-req-003
	 */
	public function needsCheck(array $contactData, ?array $oldData): bool;
}//end interface
