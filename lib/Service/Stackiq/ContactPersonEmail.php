<?php

/**
 * The email address of a contact person.
 *
 * A contact person made with the current form carries no email address of its
 * own: it links a Nextcloud contact by `contactsUid`, and the address lives on
 * that contact. Converting it into a user account reads the address there.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service\Stackiq
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/capability-requirements-from-the-matrix/specs/contactpersoon-sync/spec.md
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service\Stackiq;

use OCA\Stackiq\Kernel\Contracts\StackiqContactSyncServiceInterface;

/**
 * Resolves the email address a contact person's account is made for.
 *
 * @spec openspec/changes/capability-requirements-from-the-matrix/specs/contactpersoon-sync/spec.md
 */
class ContactPersonEmail {

	/**
	 * Constructor.
	 *
	 * @param StackiqContactSyncServiceInterface $contacts The Nextcloud Contacts lookups.
	 */
	public function __construct(
		private readonly StackiqContactSyncServiceInterface $contacts,
	) {
	}//end __construct()

	/**
	 * The email address of a contact person.
	 *
	 * The linked Nextcloud contact's first email address when the contact
	 * person names one by `contactsUid` and that contact has an address;
	 * otherwise the address on the record itself (`email`, then the older
	 * `e-mailadres`); otherwise an empty string.
	 *
	 * @param array<string, mixed> $contactData The contact person's properties.
	 *
	 * @return string The email address, or '' when there is none.
	 *
	 * @spec openspec/changes/capability-requirements-from-the-matrix/specs/contactpersoon-sync/spec.md
	 */
	public function of(array $contactData): string {
		$linked = $this->linkedContactEmail(contactsUid: $contactData['contactsUid'] ?? null);
		if ($linked !== '') {
			return $linked;
		}

		foreach (['email', 'e-mailadres'] as $key) {
			$value = $contactData[$key] ?? null;
			if (is_string($value) === true && trim($value) !== '') {
				return trim($value);
			}
		}

		return '';
	}//end of()

	/**
	 * The first email address of the Nextcloud contact a UID names.
	 *
	 * @param mixed $contactsUid The contact UID from the record.
	 *
	 * @return string The address, or '' when there is no such contact or it has none.
	 */
	private function linkedContactEmail(mixed $contactsUid): string {
		if (is_string($contactsUid) === false || trim($contactsUid) === '') {
			return '';
		}

		$contact = $this->contacts->findContactByUid(trim($contactsUid));
		if ($contact === null) {
			return '';
		}

		$emails = $contact['EMAIL'] ?? [];
		if (is_array($emails) === false) {
			$emails = [$emails];
		}

		foreach ($emails as $email) {
			$address = $this->address(email: $email);
			if ($address !== '') {
				return $address;
			}
		}

		return '';
	}//end linkedContactEmail()

	/**
	 * The address of a contact email entry: a plain string, or an entry carrying a value.
	 *
	 * @param mixed $email The email entry.
	 *
	 * @return string The trimmed address, or '' when the entry carries none.
	 */
	private function address(mixed $email): string {
		if (is_array($email) === true) {
			$email = ($email['value'] ?? '');
		}

		if (is_string($email) === false) {
			return '';
		}

		return trim($email);
	}//end address()
}//end class
