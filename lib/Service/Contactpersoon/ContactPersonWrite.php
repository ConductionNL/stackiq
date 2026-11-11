<?php

/**
 * One contact person write the organisation guard decides on.
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

/**
 * The new data, the stored data and the caller of one contact person write,
 * as ContactPersonOrganisationGuard::allows() reads them. Built and read by
 * the guard only.
 *
 * @spec openspec/specs/contactpersoon-sync/spec.md#requirement-the-system-shall-only-accept-a-contact-person-from-someone-who-may-add-members-to-its-organisation-req-003
 */
final class ContactPersonWrite {
	/**
	 * The caller's uid, or null without a signed-in user.
	 *
	 * @var string|null
	 */
	public ?string $caller = null;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed>      $contactData The contact person's new data.
	 * @param array<string, mixed>|null $oldData     The stored data, or null on a create.
	 */
	public function __construct(
		public readonly array $contactData,
		public readonly ?array $oldData,
	) {
	}//end __construct()
}//end class
