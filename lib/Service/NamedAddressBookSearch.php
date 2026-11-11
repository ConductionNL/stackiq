<?php

/**
 * Named address book search
 *
 * One search of a dedicated address book of the signed-in user.
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
 * What StackiqContactSyncService searches for in one named address book.
 *
 * @spec openspec/specs/softwarecatalog-contacts-to-nc/spec.md
 */
final class NamedAddressBookSearch {
	/**
	 * The vCard properties to search, such as FN or EMAIL.
	 *
	 * @var array<int, string>
	 */
	public array $properties = [];

	/**
	 * Constructor.
	 *
	 * @param string $query          The search query.
	 * @param string $addressBookUri The address book's URI.
	 */
	public function __construct(
		public readonly string $query,
		public readonly string $addressBookUri,
	) {
	}//end __construct()
}//end class
