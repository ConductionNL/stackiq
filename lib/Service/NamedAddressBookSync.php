<?php

/**
 * Named address book sync
 *
 * One request to resolve a record's contact in a dedicated address book of
 * the signed-in user.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/softwarecatalog-contacts-to-nc/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

/**
 * What StackiqContactSyncService::syncRecordToNamedAddressBook() resolves.
 *
 * @spec openspec/specs/softwarecatalog-contacts-to-nc/spec.md
 */
final class NamedAddressBookSync {
	/**
	 * Constructor.
	 *
	 * @param string               $objectType     The relationship type ('contactPerson'|'organization').
	 * @param array<string, mixed> $record         The relationship record.
	 * @param string               $addressBookUri The address book's URI, stable across languages.
	 * @param string               $displayName    The display name for a new address book.
	 * @param string|null          $fixedUid       The UID for a new contact; a random one when null.
	 */
	public function __construct(
		public readonly string $objectType,
		public readonly array $record,
		public readonly string $addressBookUri,
		public readonly string $displayName,
		public readonly ?string $fixedUid=null,
	) {
	}//end __construct()
}//end class
