<?php

/**
 * Contact account
 *
 * A contact person and the account the organisation sync ensures for it,
 * built and read only by OrganizationSyncService.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/organization-sync/spec.md
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

/**
 * The account of one contact person: its e-mail, its username and whether that username was stored.
 *
 * @category Service
 * @package  OCA\Stackiq\Service
 *
 * @spec openspec/specs/organization-sync/spec.md
 */
final class ContactAccount {

	/**
	 * The contact person object.
	 *
	 * @var object
	 */
	public object $contactPerson;

	/**
	 * The contact person's e-mail (`email`, else `e-mailadres`).
	 *
	 * @var mixed
	 */
	public mixed $email = '';

	/**
	 * The username: the stored one, else the e-mail.
	 *
	 * @var mixed
	 */
	public mixed $username = '';

	/**
	 * Whether the contact person already stored a username.
	 *
	 * @var bool
	 */
	public bool $hasStoredUsername = false;
}//end class
