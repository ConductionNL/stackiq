<?php

/**
 * How the CMDB import identifies an owner across imports.
 *
 * An owner with an employee number is the contact whose UID derives from
 * that number and the municipality; the number itself is never stored. An
 * owner without one is identified by e-mail address, else by display name.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service\Cmdb
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/cmdb-import-owner-identity/tasks.md#task-2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service\Cmdb;

/**
 * Derives the contact UID and the per-run cache key of an owner.
 *
 * @spec openspec/changes/cmdb-import-owner-identity/tasks.md#task-2
 */
class CmdbOwnerIdentity {
	/**
	 * The prefix of a contact UID derived from an employee number.
	 */
	public const UID_PREFIX = 'stackiq-cmdb-owner-';

	/**
	 * The contact UID of an owner with this employee number, or null without one.
	 *
	 * A hash, so the employee number is not readable from the contact, and
	 * scoped to the municipality, so the same number at two municipalities
	 * is two owners. A number Excel stored as a decimal ("1234.0") is the
	 * same number as "1234".
	 *
	 * @param string $employeeNumber The employee number from the owner pack.
	 * @param string $municipalityUuid The municipality.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/cmdb-import-owner-identity/tasks.md#task-2
	 */
	public function contactUid(string $employeeNumber, string $municipalityUuid): ?string {
		$number = mb_strtolower(trim($employeeNumber));
		$number = (string)preg_replace('/^(\d+)\.0+$/', '$1', $number);
		if ($number === '') {
			return null;
		}

		return self::UID_PREFIX . hash('sha256', $municipalityUuid . '|' . $number);
	}//end contactUid()

	/**
	 * The key under which one import run remembers the owner's contact.
	 *
	 * @param string|null $contactUid The UID from the employee number, or null.
	 * @param string $email The e-mail address, or ''.
	 * @param string $displayName The display name.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/cmdb-import-owner-identity/tasks.md#task-2
	 */
	public function cacheKey(?string $contactUid, string $email, string $displayName): string {
		if ($contactUid !== null) {
			return 'uid:' . $contactUid;
		}

		if ($email !== '') {
			return 'email:' . mb_strtolower($email);
		}

		return 'name:' . mb_strtolower($displayName);
	}//end cacheKey()
}//end class
