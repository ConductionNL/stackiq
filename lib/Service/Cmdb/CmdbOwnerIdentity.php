<?php

/**
 * How the CMDB import identifies an owner across imports.
 *
 * An owner with an employee number is the contact whose UID derives from
 * that number, the municipality and this instance's secret; the number
 * itself is never stored. An owner without one is identified by e-mail
 * address, else by display name.
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

use OCP\IConfig;
use Psr\Log\LoggerInterface;

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
	 * Whether the missing instance secret was logged already.
	 *
	 * @var boolean
	 */
	private bool $missingSecretLogged = false;

	/**
	 * Constructor.
	 *
	 * @param IConfig $config Reads this instance's `secret`, from which the UID's HMAC key derives.
	 * @param LoggerInterface $logger Says, once, that employee numbers are not used without a secret; never handed a number.
	 *
	 * @spec openspec/changes/cmdb-import-owner-identity/tasks.md#task-2
	 */
	public function __construct(
		private readonly IConfig $config,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The contact UID of an owner with this employee number, or null without a usable one.
	 *
	 * An HMAC-SHA256 with a key derived from this instance's `secret`
	 * (config.php), so the employee number cannot be recovered from the UID
	 * by hashing every possible number, and scoped to the municipality, so
	 * the same number at two municipalities is two owners. A number Excel
	 * stored as a decimal ("1234.0"), with leading zeros ("01234") or with
	 * surrounding spaces, non-breaking ones included, is the same number as
	 * "1234". A value without a digit other than 0 ("-", "n.v.t.", "0") is
	 * no employee number: it gives null, so the owner is matched on the
	 * e-mail address or the name instead of sharing one UID with every
	 * other owner whose cell holds the same placeholder.
	 *
	 * Fail-mode (ADR-102): closed. Without a `secret` the number is not used
	 * (null, logged once), never hashed with an empty key. A changed secret
	 * gives every owner a new UID, so a new contact on the next import.
	 *
	 * @param string $employeeNumber The employee number from the owner pack.
	 * @param string $municipalityUuid The municipality.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/cmdb-import-owner-identity/tasks.md#task-2
	 */
	public function contactUid(string $employeeNumber, string $municipalityUuid): ?string {
		$number = self::normaliseNumber(employeeNumber: $employeeNumber);
		if ($number === null) {
			return null;
		}

		$secret = $this->config->getSystemValueString('secret', '');
		if ($secret === '') {
			if ($this->missingSecretLogged === false) {
				$this->missingSecretLogged = true;
				$this->logger->warning('CmdbOwnerIdentity: this instance has no secret in config.php; owners are matched without their employee number');
			}

			return null;
		}

		$key = hash_hmac('sha256', self::UID_PREFIX, $secret);
		return self::UID_PREFIX . hash_hmac('sha256', $municipalityUuid . '|' . $number, $key);
	}//end contactUid()

	/**
	 * A random UID (UUID v4) for the contact of an owner without an employee number.
	 *
	 * The import gives every new owner contact its UID, so the contacts
	 * bridge never matches one on its own (on an e-mail address it would
	 * also take a contact identified by an employee number).
	 *
	 * @return string
	 *
	 * @spec openspec/changes/cmdb-import-owner-identity/tasks.md#task-2
	 */
	public function newContactUid(): string {
		$bytes = random_bytes(16);
		$bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
		$bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
	}//end newContactUid()

	/**
	 * The employee number in its compared form, or null when the value is no employee number.
	 *
	 * @param string $employeeNumber The value from the owner pack.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/cmdb-import-owner-identity/tasks.md#task-2
	 */
	private static function normaliseNumber(string $employeeNumber): ?string {
		$number = mb_strtolower((string)preg_replace('/^[\s\x{00A0}]+|[\s\x{00A0}]+$/u', '', $employeeNumber));
		$number = (string)preg_replace('/^(\d+)\.0+$/', '$1', $number);
		if (preg_match('/^\d+$/', $number) === 1) {
			$number = ltrim($number, '0');
		}

		if (preg_match('/[1-9]/', $number) !== 1) {
			return null;
		}

		return $number;
	}//end normaliseNumber()

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
