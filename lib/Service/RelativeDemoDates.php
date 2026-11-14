<?php

/**
 * Resolves relative dates in the demo descriptor against the import date.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/organisations-role-mapping-and-access-review/specs/role-mapping-and-access-review/spec.md#requirement-req-rma-003-a-functional-administrator-shall-review-the-access-of-their-organisations-people
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * Turns a demo value such as `-P7D` (seven days before the import) into a date.
 *
 * A demo dataset that holds fixed dates ages: a review "a week ago" is a year
 * old a year later. A top-level field of a demo object whose value is a
 * negative ISO 8601 duration is replaced by the import date minus that
 * duration, as an ISO 8601 date-time. Every other value is left as it is.
 *
 * @spec openspec/changes/organisations-role-mapping-and-access-review/specs/role-mapping-and-access-review/spec.md#requirement-req-rma-003-a-functional-administrator-shall-review-the-access-of-their-organisations-people
 */
final class RelativeDemoDates {

	/**
	 * A negative ISO 8601 duration of days, weeks, months or years.
	 *
	 * @var string
	 */
	private const PATTERN = '/^-(P(?:\d+Y)?(?:\d+M)?(?:\d+W)?(?:\d+D)?)$/';

	/**
	 * The descriptor with every relative date of its objects resolved.
	 *
	 * @param array<string, mixed> $data The decoded descriptor.
	 * @param DateTimeImmutable    $now  The import date.
	 *
	 * @return array<string, mixed> The descriptor with absolute dates.
	 *
	 * @spec openspec/changes/organisations-role-mapping-and-access-review/specs/role-mapping-and-access-review/spec.md#requirement-req-rma-003-a-functional-administrator-shall-review-the-access-of-their-organisations-people
	 */
	public static function resolve(array $data, DateTimeImmutable $now): array {
		$objects = ($data['components']['objects'] ?? null);
		if (is_array($objects) === false) {
			return $data;
		}

		foreach ($objects as $index => $object) {
			if (is_array($object) === false) {
				continue;
			}

			foreach ($object as $field => $value) {
				$date = self::dateOf(value: $value, now: $now);
				if ($date !== null) {
					$data['components']['objects'][$index][$field] = $date;
				}
			}
		}

		return $data;
	}//end resolve()

	/**
	 * The date a relative value stands for, or null for any other value.
	 *
	 * @param mixed             $value The field's value.
	 * @param DateTimeImmutable $now   The import date.
	 *
	 * @return string|null The ISO 8601 date-time.
	 */
	private static function dateOf(mixed $value, DateTimeImmutable $now): ?string {
		if (is_string($value) === false || $value === '-P' || preg_match(self::PATTERN, $value, $match) !== 1) {
			return null;
		}

		return $now->sub(new DateInterval($match[1]))->format(DateTimeInterface::ATOM);
	}//end dateOf()
}//end class
