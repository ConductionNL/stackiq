<?php

/**
 * Splices the Nextcloud account details into the contact persons of an organisation.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service\Contactpersoon
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/contactpersoon-sync/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service\Contactpersoon;

use OCP\IUser;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Each contact person is returned as a copy whose data carries a `userDetails`
 * entry: the details of the account named by its `username`, or null when it
 * has no username or the account does not exist. A contact person that cannot
 * be read is logged and returned unchanged. Built by ContactpersoonService.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service\Contactpersoon
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/contactpersoon-sync/spec.md
 */
final class ContactPersonUserDetails {

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger The logger.
	 * @param IUserManager $userManager The Nextcloud user manager.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly IUserManager $userManager,
	) {
	}//end __construct()

	/**
	 * Splice the account details into every contact person of an organisation.
	 *
	 * @param array $contactPersons The contact person objects.
	 * @param string $organizationUuid The organisation the contact persons belong to.
	 *
	 * @return array The contact persons with user details, in the same order.
	 *
	 * @spec openspec/specs/contactpersoon-sync/spec.md
	 */
	public function enhanceAll(array $contactPersons, string $organizationUuid): array {
		$enhancedContactPersons = [];

		// Loop through each contact person and fetch user details.
		foreach ($contactPersons as $contactPerson) {
			$enhancedContactPersons[] = $this->enhanceOrKeep(
				contactPerson: $contactPerson,
				organizationUuid: $organizationUuid
			);
		}

		return $enhancedContactPersons;
	}//end enhanceAll()

	/**
	 * Splice the account details into one contact person, or keep it as it is on failure.
	 *
	 * @param object $contactPerson The contact person object.
	 * @param string $organizationUuid The organisation the contact person belongs to.
	 *
	 * @return object The enhanced copy, or the contact person itself when it failed.
	 */
	private function enhanceOrKeep(object $contactPerson, string $organizationUuid): object {
		try {
			return $this->enhance(contactPerson: $contactPerson);
		} catch (\Exception $e) {
			$this->logger->error(
				'ContactpersoonService: Failed to process contact person',
				[
					'contactPersonId' => $contactPerson->getId(),
					'organizationUuid' => $organizationUuid,
					'error' => $e->getMessage(),
				]
			);

			// Still add the contact person without user details.
			return $contactPerson;
		}//end try
	}//end enhanceOrKeep()

	/**
	 * Copy a contact person with its account details spliced into its data.
	 *
	 * @param object $contactPerson The contact person object.
	 *
	 * @return object The enhanced copy.
	 */
	private function enhance(object $contactPerson): object {
		$contactData = $contactPerson->getObject();
		$username = ($contactData['username'] ?? null);

		// Create enhanced contact person object with user details spliced in.
		$enhancedContactData = $contactData;
		$enhancedContactData['userDetails'] = $this->userDetails(contactPerson: $contactPerson, username: $username);

		// Create a new object with the enhanced data.
		$enhancedContactPerson = clone $contactPerson;
		$enhancedContactPerson->setObject($enhancedContactData);
		return $enhancedContactPerson;
	}//end enhance()

	/**
	 * The account details of a contact person, or null without a username.
	 *
	 * @param object $contactPerson The contact person object.
	 * @param mixed $username The username the contact person names, or null.
	 *
	 * @return array|null The account details, or null.
	 */
	private function userDetails(object $contactPerson, mixed $username): ?array {
		if ($username === null) {
			$this->logger->debug(
				'ContactpersoonService: No username found for contact person',
				[
					'contactPersonId' => $contactPerson->getId(),
				]
			);
			return null;
		}

		return $this->userDetailsByName(contactPerson: $contactPerson, username: $username);
	}//end userDetails()

	/**
	 * The details of the account a contact person names, or null when it does not exist.
	 *
	 * @param object $contactPerson The contact person object.
	 * @param mixed $username The username the contact person names.
	 *
	 * @return array|null The account details, or null.
	 */
	private function userDetailsByName(object $contactPerson, mixed $username): ?array {
		$user = $this->findUser(contactPerson: $contactPerson, username: $username);
		if ($user === null) {
			return null;
		}

		$userDetails = $this->describeUser(user: $user);
		$this->logger->debug(
			'ContactpersoonService: Fetched user details',
			['contactPersonId' => $contactPerson->getId(), 'username' => $username, 'userEnabled' => $user->isEnabled()]
		);
		return $userDetails;
	}//end userDetailsByName()

	/**
	 * Look up the account a contact person names; a missing account is logged.
	 *
	 * @param object $contactPerson The contact person object.
	 * @param mixed $username The username the contact person names.
	 *
	 * @return IUser|null The account, or null when it does not exist.
	 */
	private function findUser(object $contactPerson, mixed $username): ?IUser {
		$user = $this->userManager->get($username);
		if ($user === null) {
			$this->logger->warning(
				'ContactpersoonService: User not found for username',
				['contactPersonId' => $contactPerson->getId(), 'username' => $username]
			);
		}

		return $user;
	}//end findUser()

	/**
	 * The details of an account as spliced into a contact person.
	 *
	 * The account is untyped here as it was where these details were read
	 * before: `getFreeQuota()` is not declared on IUser.
	 *
	 * @param mixed $user The account.
	 *
	 * @return array The account details.
	 */
	private function describeUser(mixed $user): array {
		return [
			'uid' => $user->getUID(),
			'email' => $user->getEMailAddress(),
			'displayName' => $user->getDisplayName(),
			'enabled' => $user->isEnabled(),
			'lastLogin' => $user->getLastLogin(),
			'backend' => $user->getBackendClassName(),
			'home' => $user->getHome(),
			'avatarImage' => $user->getAvatarImage(64)->data(),
			'quota' => $user->getQuota(),
			'freeQuota' => $user->getFreeQuota(),
		];
	}//end describeUser()
}//end class
