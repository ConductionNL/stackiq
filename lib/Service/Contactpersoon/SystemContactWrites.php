<?php

/**
 * Marks the contact person writes stackiq makes itself.
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
 * The contact sync job and the contact migration run their writes through
 * {@see run()}; the contact person listener does not check a write made while one
 * is running. The mark is process-wide and ends when the callable returns or throws,
 * so a later job in the same cron run is checked again.
 *
 * @spec openspec/specs/contactpersoon-sync/spec.md#requirement-the-system-shall-only-accept-a-contact-person-from-someone-who-may-add-members-to-its-organisation-req-003
 */
class SystemContactWrites {
	/**
	 * How many system writes are running in this process.
	 *
	 * @var integer
	 */
	private static int $depth = 0;

	/**
	 * Run a callable as a system write.
	 *
	 * @param callable $write The writes to make.
	 *
	 * @return mixed What the callable returns.
	 *
	 * @spec openspec/specs/contactpersoon-sync/spec.md#requirement-the-system-shall-only-accept-a-contact-person-from-someone-who-may-add-members-to-its-organisation-req-003
	 */
	public function run(callable $write): mixed {
		self::$depth++;
		try {
			return $write();
		} finally {
			self::$depth--;
		}
	}//end run()

	/**
	 * Whether a system write is running.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/contactpersoon-sync/spec.md#requirement-the-system-shall-only-accept-a-contact-person-from-someone-who-may-add-members-to-its-organisation-req-003
	 */
	public function isActive(): bool {
		return self::$depth > 0;
	}//end isActive()
}//end class
