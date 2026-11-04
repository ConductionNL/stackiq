<?php

/**
 * Decides whether the signed-in caller may write a contact person for an organisation.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service\Contactpersoon
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/contactpersoon-sync/spec.md#requirement-the-system-shall-only-accept-a-contact-person-from-someone-who-may-add-members-to-its-organisation-req-003
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service\Contactpersoon;

use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;

/**
 * The contact sync turns a contact person into an account that joins the contact's
 * organisation, and resets the role group of an existing account named by its e-mail
 * address. So a contact person is accepted only from an administrator, or from a member
 * of its organisation who may add members, and it may name an existing account only
 * when that account already belongs to the same organisation.
 *
 * @spec openspec/specs/contactpersoon-sync/spec.md#requirement-the-system-shall-only-accept-a-contact-person-from-someone-who-may-add-members-to-its-organisation-req-003
 */
class ContactPersonOrganisationGuard {
	/**
	 * The groups whose members may add members to their own organisation.
	 */
	public const MEMBER_MANAGER_GROUPS = ['gebruik-beheerder', 'aanbod-beheerder'];

	/**
	 * Constructor.
	 *
	 * @param IUserSession       $userSession        Names the caller.
	 * @param IGroupManager      $groupManager       Tells administrators and member managers apart.
	 * @param IUserManager       $userManager        Tells whether the e-mail address names an existing account.
	 * @param OrganisationMapper $organisationMapper Finds the OpenRegister organisation and its members.
	 */
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly IUserManager $userManager,
		private readonly OrganisationMapper $organisationMapper,
	) {
	}//end __construct()

	/**
	 * Whether the caller may write this contact person.
	 *
	 * @param array<string, mixed> $contactData The contact person's data.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/contactpersoon-sync/spec.md#requirement-the-system-shall-only-accept-a-contact-person-from-someone-who-may-add-members-to-its-organisation-req-003
	 */
	public function allows(array $contactData): bool {
		$caller = $this->userSession->getUser()?->getUID();
		if ($caller !== null && $this->groupManager->isAdmin($caller) === true) {
			return true;
		}

		$organisation = $this->findOrganisation(uuid: self::organisationUuid(contactData: $contactData));
		$account      = $this->existingAccount(contactData: $contactData, caller: $caller);

		if ($organisation === null) {
			return $account === null;
		}

		if ($account !== null && $organisation->hasUser($account) === false) {
			return false;
		}

		return $caller !== null && $organisation->hasUser($caller) === true && $this->managesMembers(uid: $caller) === true;
	}//end allows()

	/**
	 * Whether the change from the stored contact person needs the check again.
	 *
	 * Only the organisation and the e-mail address decide which account joins which organisation.
	 *
	 * @param array<string, mixed>      $contactData The new data.
	 * @param array<string, mixed>|null $oldData     The stored data, or null when there is none.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/contactpersoon-sync/spec.md#requirement-the-system-shall-only-accept-a-contact-person-from-someone-who-may-add-members-to-its-organisation-req-003
	 */
	public static function needsCheck(array $contactData, ?array $oldData): bool {
		return $oldData === null
			|| self::organisationUuid(contactData: $contactData) !== self::organisationUuid(contactData: $oldData)
			|| self::email(contactData: $contactData) !== self::email(contactData: $oldData);
	}//end needsCheck()

	/**
	 * The uuid of the organisation the contact person names, or an empty string.
	 *
	 * @param array<string, mixed> $contactData The contact person's data.
	 *
	 * @return string
	 */
	private static function organisationUuid(array $contactData): string {
		$value = ($contactData['organisation'] ?? $contactData['organization'] ?? '');
		if (is_array($value) === true) {
			$value = ($value['uuid'] ?? $value['id'] ?? '');
		}

		return is_string($value) === true ? trim($value) : '';
	}//end organisationUuid()

	/**
	 * The e-mail address the sync uses as the account name, or an empty string.
	 *
	 * @param array<string, mixed> $contactData The contact person's data.
	 *
	 * @return string
	 */
	private static function email(array $contactData): string {
		$value = ($contactData['email'] ?? $contactData['e-mailadres'] ?? '');
		return is_string($value) === true ? trim($value) : '';
	}//end email()

	/**
	 * The OpenRegister organisation with this uuid, or null.
	 *
	 * @param string $uuid The organisation uuid.
	 *
	 * @return Organisation|null
	 */
	private function findOrganisation(string $uuid): ?Organisation {
		if ($uuid === '') {
			return null;
		}

		try {
			return $this->organisationMapper->findByUuid($uuid);
		} catch (DoesNotExistException $e) {
			return null;
		}
	}//end findOrganisation()

	/**
	 * The uid of the existing account the e-mail address names, when it is not the caller's own.
	 *
	 * @param array<string, mixed> $contactData The contact person's data.
	 * @param string|null          $caller      The caller's uid.
	 *
	 * @return string|null
	 */
	private function existingAccount(array $contactData, ?string $caller): ?string {
		$email = self::email(contactData: $contactData);
		if ($email === '') {
			return null;
		}

		$uid = $this->userManager->get($email)?->getUID();
		if ($uid === null || $uid === $caller) {
			return null;
		}

		return $uid;
	}//end existingAccount()

	/**
	 * Whether this user is in a group that may add members.
	 *
	 * @param string $uid The user id.
	 *
	 * @return bool
	 */
	private function managesMembers(string $uid): bool {
		foreach (self::MEMBER_MANAGER_GROUPS as $group) {
			if ($this->groupManager->isInGroup($uid, $group) === true) {
				return true;
			}
		}

		return false;
	}//end managesMembers()
}//end class
