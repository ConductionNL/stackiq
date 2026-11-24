<?php

/**
 * Reads a date that OpenRegister stored as a string.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/archive/2026-11-24-anonymous-aanbod-listing-is-published-only/specs/vendor-visibility-rbac/spec.md#requirement-the-aanbod-listing-endpoint-serves-anonymous-callers-published-entries-only-req-009
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

use DateTimeImmutable;

/**
 * One reading of a stored date value, shared by the services that compare
 * publication and lifecycle dates: a non-empty string that parses, else null.
 *
 * @spec openspec/changes/archive/2026-11-24-anonymous-aanbod-listing-is-published-only/specs/vendor-visibility-rbac/spec.md#requirement-the-aanbod-listing-endpoint-serves-anonymous-callers-published-entries-only-req-009
 */
trait ParsesStoredDates {

	/**
	 * A date value, or null.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return DateTimeImmutable|null The date.
	 */
	private function storedDate(mixed $value): ?DateTimeImmutable {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		try {
			return new DateTimeImmutable($value);
		} catch (\Exception $e) {
			return null;
		}
	}//end storedDate()
}//end trait
