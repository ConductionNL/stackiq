<?php

/**
 * Test stub for OCA\OpenRegister\Service\OrganisationService.
 *
 * The real OrganisationService lives in the OpenRegister app which is not
 * available as a Composer dependency in the test environment. This stub
 * declares the methods Stackiq's
 * AangebodenGebruikService::getCurrentOrganisation() /
 * AanbodService::getCurrentOrganisation() rely on (vendor-visibility-rbac's
 * deny-before-grant tests), plus the membership-management surface
 * `OrganisationMembersController` and its tests rely on
 * (multi-org-membership): `getUserOrganisations()`, `joinOrganisation()`,
 * `leaveOrganisation()`.
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Stackiq\Tests\Stubs\Service
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\Organisation;

/**
 * Stub for OrganisationService with the surface used by Stackiq
 * tests.
 */
abstract class OrganisationService {

	/**
	 * Get the caller's active organisation, or null when none is set /
	 * resolvable.
	 *
	 * @return Organisation|null
	 */
	abstract public function getActiveOrganisation(): ?Organisation;

	/**
	 * Get the default organisation, creating it when it does not exist yet.
	 *
	 * @return Organisation
	 */
	abstract public function ensureDefaultOrganisation(): Organisation;

	/**
	 * The uuid of the instance's default organisation, or null when none is set.
	 *
	 * @return string|null
	 */
	abstract public function getDefaultOrganisationUuid(): ?string;

	/**
	 * Get the caller's own organisations (session-scoped).
	 *
	 * @return Organisation[]
	 */
	abstract public function getUserOrganisations(): array;

	/**
	 * Add a user to an organisation. Real implementation performs no
	 * caller-side authorization — callers MUST authorize before invoking.
	 *
	 * @param string $organisationUuid The organisation UUID.
	 * @param string|null $targetUserId Target user id, or null for the current user.
	 *
	 * @return bool True on success.
	 *
	 * @throws \Exception When the organisation or target user is not found.
	 */
	abstract public function joinOrganisation(string $organisationUuid, ?string $targetUserId = null): bool;

	/**
	 * Remove a user from an organisation. Real implementation performs no
	 * caller-side authorization — callers MUST authorize before invoking.
	 *
	 * @param string $organisationUuid The organisation UUID.
	 * @param string|null $targetUserId Target user id, or null for the current user.
	 *
	 * @return bool True on success.
	 *
	 * @throws \Exception When the organisation is not found, or the target would be left with no organisation.
	 */
	abstract public function leaveOrganisation(string $organisationUuid, ?string $targetUserId = null): bool;

	/**
	 * Create an organisation; with addCurrentUser the signed-in user becomes its owner and a member.
	 *
	 * @param string $name           The name.
	 * @param string $description    The description.
	 * @param bool   $addCurrentUser Whether the signed-in user owns and joins it.
	 * @param string $uuid           The uuid to use.
	 *
	 * @return Organisation
	 */
	abstract public function createOrganisation(
		string $name,
		string $description = '',
		bool $addCurrentUser = true,
		string $uuid = '',
	): Organisation;

}//end class
