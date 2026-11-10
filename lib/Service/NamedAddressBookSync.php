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
	 * The address book's URI, stable across languages.
	 *
	 * @var string
	 */
	public string $addressBookUri = '';

	/**
	 * The display name for a new address book.
	 *
	 * @var string
	 */
	public string $displayName = '';

	/**
	 * The UID for a new contact; a random one when null.
	 *
	 * @var string|null
	 */
	public ?string $fixedUid = null;

	/**
	 * Constructor.
	 *
	 * @param string               $objectType The relationship type ('contactPerson'|'organization').
	 * @param array<string, mixed> $record     The relationship record.
	 */
	public function __construct(
		public readonly string $objectType,
		public readonly array $record,
	) {
	}//end __construct()
}//end class
