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
use OCA\OpenRegister\Service\ObjectService;
use OCA\Stackiq\Service\SettingsService;
use OCP\AppFramework\Db\DoesNotExistException;
use Throwable;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;

/**
 * The contact sync turns a contact person into an account that joins the contact's
 * organisation, resets the role group of an existing account named by its e-mail
 * address, and writes names and e-mail address to the account named by `username`.
 * So a contact person is accepted only from an administrator, or from a member of its
 * organisation who may add members; it may name an existing account only when that
 * account already belongs to the same organisation, and `username` only when it is the
 * caller's own account. The sync reads several keys for the same value, so every key
 * is checked on its own and two keys that disagree are refused.
 *
 * @spec openspec/specs/contactpersoon-sync/spec.md#requirement-the-system-shall-only-accept-a-contact-person-from-someone-who-may-add-members-to-its-organisation-req-003
 */
class ContactPersonOrganisationGuard {
	/**
	 * The groups whose members may add members to their own organisation.
	 */
	public const MEMBER_MANAGER_GROUPS = ['gebruik-beheerder', 'aanbod-beheerder'];

	/**
	 * The keys the sync reads the organisation uuid from.
	 */
	public const ORGANISATION_KEYS = ['organisation', 'organization'];

	/**
	 * The keys the sync reads the e-mail address from.
	 */
	public const EMAIL_KEYS = ['email', 'e-mailadres'];

	/**
	 * The key the name sync reads the target account from.
	 */
	public const USERNAME_KEY = 'username';

	/**
	 * The organisatie statuses for which the sync creates the OpenRegister organisation.
	 */
	public const ACTIVE_STATUSES = ['actief', 'active'];

	/**
	 * Constructor.
	 *
	 * @param IUserSession       $userSession        Names the caller.
	 * @param IGroupManager      $groupManager       Tells administrators and member managers apart.
	 * @param IUserManager       $userManager        Tells whether a name is an existing account.
	 * @param OrganisationMapper $organisationMapper Finds the OpenRegister organisation and its members.
	 * @param ObjectService      $objectService      Reads the organisatie object's status.
	 * @param SettingsService    $settingsService    Names the organisatie register and schema.
	 */
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly IUserManager $userManager,
		private readonly OrganisationMapper $organisationMapper,
		private readonly ObjectService $objectService,
		private readonly SettingsService $settingsService,
	) {
	}//end __construct()

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
	public function allows(array $contactData, ?array $oldData = null): bool {
		$caller = $this->userSession->getUser()?->getUID();
		if ($caller !== null && $this->groupManager->isAdmin($caller) === true) {
			return true;
		}

		if ($this->isAmbiguousOrForeign(contactData: $contactData, caller: $caller) === true) {
			return false;
		}

		$organisationUuid = ($this->values(contactData: $contactData, keys: self::ORGANISATION_KEYS)[0] ?? '');
		$organisation     = $this->findOrganisation(uuid: $organisationUuid);
		$account          = $this->existingAccount(email: $this->values(contactData: $contactData, keys: self::EMAIL_KEYS)[0] ?? '', caller: $caller);
		if ($account !== null && $organisation?->hasUser($account) !== true) {
			return false;
		}

		if ($organisation === null && $this->organisatieMayBecomeActive(uuid: $organisationUuid) === true) {
			return false;
		}

		return $this->mayAddTo(organisation: $organisation, contactData: $contactData, oldData: $oldData, caller: $caller);
	}//end allows()

	/**
	 * Whether two keys disagree, or `username` names an account other than the caller's.
	 *
	 * @param array<string, mixed> $contactData The contact person's data.
	 * @param string|null          $caller      The caller's uid.
	 *
	 * @return bool
	 */
	private function isAmbiguousOrForeign(array $contactData, ?string $caller): bool {
		return count($this->values(contactData: $contactData, keys: self::ORGANISATION_KEYS)) > 1
			|| count($this->values(contactData: $contactData, keys: self::EMAIL_KEYS)) > 1
			|| $this->namesAnotherAccount(name: $this->values(contactData: $contactData, keys: [self::USERNAME_KEY])[0] ?? '', caller: $caller) === true;
	}//end isAmbiguousOrForeign()

	/**
	 * Whether the caller may add this contact to the organisation.
	 *
	 * @param Organisation|null         $organisation The OpenRegister organisation, or null when it has none yet.
	 * @param array<string, mixed>      $contactData  The new data.
	 * @param array<string, mixed>|null $oldData      The stored data, or null on a create.
	 * @param string|null               $caller       The caller's uid.
	 *
	 * @return bool
	 */
	private function mayAddTo(?Organisation $organisation, array $contactData, ?array $oldData, ?string $caller): bool {
		if ($organisation === null || $this->isOwnContactInSameOrganisation(contactData: $contactData, oldData: $oldData, caller: $caller) === true) {
			return true;
		}

		return $caller !== null && $organisation->hasUser($caller) === true && $this->managesMembers(uid: $caller) === true;
	}//end mayAddTo()

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
	public function needsCheck(array $contactData, ?array $oldData): bool {
		if ($oldData === null) {
			return true;
		}

		foreach ([...self::ORGANISATION_KEYS, ...self::EMAIL_KEYS, self::USERNAME_KEY] as $key) {
			if ($this->values(contactData: $contactData, keys: [$key]) !== $this->values(contactData: $oldData, keys: [$key])) {
				return true;
			}
		}

		return false;
	}//end needsCheck()

	/**
	 * The distinct non-empty values under these keys, trimmed and lower-cased.
	 *
	 * Account names compare case-insensitively in Nextcloud, and uuids are lower case.
	 *
	 * @param array<string, mixed> $contactData The contact person's data.
	 * @param array<int, string>   $keys        The keys to read.
	 *
	 * @return array<int, string>
	 */
	private function values(array $contactData, array $keys): array {
		$values = [];
		foreach ($keys as $key) {
			$value = ($contactData[$key] ?? '');
			if (is_array($value) === true) {
				$value = ($value['uuid'] ?? $value['id'] ?? '');
			}

			if (is_scalar($value) === true && trim((string)$value) !== '') {
				$values[] = strtolower(trim((string)$value));
			}
		}

		return array_values(array_unique($values));
	}//end values()

	/**
	 * Whether the caller edits their own contact person and keeps its organisation.
	 *
	 * @param array<string, mixed>      $contactData The new data.
	 * @param array<string, mixed>|null $oldData     The stored data.
	 * @param string|null               $caller      The caller's uid.
	 *
	 * @return bool
	 */
	private function isOwnContactInSameOrganisation(array $contactData, ?array $oldData, ?string $caller): bool {
		if ($oldData === null || $caller === null) {
			return false;
		}

		$own = [strtolower($caller)];
		return $this->values(contactData: $oldData, keys: [self::USERNAME_KEY]) === $own
			&& $this->values(contactData: $contactData, keys: [self::USERNAME_KEY]) === $own
			&& $this->values(contactData: $contactData, keys: self::ORGANISATION_KEYS) === $this->values(contactData: $oldData, keys: self::ORGANISATION_KEYS);
	}//end isOwnContactInSameOrganisation()

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
	 * Whether the sync would create the OpenRegister organisation for this organisatie.
	 *
	 * The sync creates the organisation, and adds the contact's account to it, when the
	 * organisatie object is active. A lookup that fails is treated as active.
	 *
	 * @param string $uuid The organisatie uuid.
	 *
	 * @return bool
	 */
	private function organisatieMayBecomeActive(string $uuid): bool {
		if ($uuid === '') {
			return false;
		}

		try {
			$config = $this->settingsService->getVoorzieningenConfig();
			$object = $this->objectService->find(
				id: $uuid,
				register: ($config['register'] ?? ''),
				schema: ($config['organisatie_schema'] ?? ''),
				_rbac: false,
				_multitenancy: false
			);
		} catch (DoesNotExistException $e) {
			return false;
		} catch (Throwable $e) {
			return true;
		}

		if ($object === null) {
			return false;
		}

		$status = ($object->getObject()['status'] ?? '');
		return is_string($status) === false || in_array(strtolower(trim($status)), self::ACTIVE_STATUSES, true) === true;
	}//end organisatieMayBecomeActive()

	/**
	 * The uid of the existing account this e-mail address names, when it is not the caller's own.
	 *
	 * @param string      $email  The e-mail address the sync uses as the account name.
	 * @param string|null $caller The caller's uid.
	 *
	 * @return string|null
	 */
	private function existingAccount(string $email, ?string $caller): ?string {
		if ($email === '') {
			return null;
		}

		$uid = $this->userManager->get($email)?->getUID();
		if ($uid === null || strtolower($uid) === strtolower((string)$caller)) {
			return null;
		}

		return $uid;
	}//end existingAccount()

	/**
	 * Whether this name is an existing account other than the caller's own.
	 *
	 * @param string      $name   The account name.
	 * @param string|null $caller The caller's uid.
	 *
	 * @return bool
	 */
	private function namesAnotherAccount(string $name, ?string $caller): bool {
		return $this->existingAccount(email: $name, caller: $caller) !== null;
	}//end namesAnotherAccount()

	/**
	 * Whether this user is in a group that may add members.
	 *
	 * @param string $uid The user id.
	 *
	 * @return bool
	 */
	private function managesMembers(string $uid): bool {
		$memberOf = array_filter(
			self::MEMBER_MANAGER_GROUPS,
			fn (string $group): bool => $this->groupManager->isInGroup($uid, $group)
		);

		return $memberOf !== [];
	}//end managesMembers()
}//end class
