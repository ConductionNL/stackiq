<?php

/**
 * Stackiq RelativeDemoDates.
 *
 * Resolves date placeholders in a demo descriptor against the day it is
 * imported. A demo value that only makes sense relative to today (a contract
 * that ends in six weeks, so it reads Expiring) cannot be written as a fixed
 * date: it would go stale a few weeks after the file was written. Such a value
 * is written as `{{today}}`, `{{today+45d}}`, `{{today-2y}}` or `{{today+3m}}`
 * and becomes a `Y-m-d` date at import.
 *
 * @category Service
 * @package  OCA\Stackiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-11-24-contracts-expiry-and-owner/specs/contract-expiry-and-owner/spec.md#requirement-req-ceo-001-the-contract-status-must-include-expiring-set-by-the-daily-contract-job-inside-the-notice-window
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
 * Turns `{{today±N[d|m|y]}}` strings in demo objects into dates, and a
 * top-level negative ISO 8601 duration such as `-P7D` (seven days before the
 * import) into a date-time.
 *
 * @spec openspec/specs/contract-administration/spec.md
 */
class RelativeDemoDates {
	/**
	 * A whole-value placeholder: `{{today}}` or `{{today+45d}}`.
	 *
	 * @var string
	 */
	private const PLACEHOLDER = '/^\{\{today(?:([+-])(\d{1,4})([dmy]))?\}\}$/';

	/**
	 * The interval unit per placeholder unit.
	 *
	 * @var array<string, string>
	 */
	private const UNITS = ['d' => 'days', 'm' => 'months', 'y' => 'years'];

	/**
	 * A negative ISO 8601 duration of days, weeks, months or years.
	 *
	 * @var string
	 */
	private const DURATION = '/^-(P(?:\d+Y)?(?:\d+M)?(?:\d+W)?(?:\d+D)?)$/';

	/**
	 * Resolve every placeholder in the descriptor's objects against today.
	 *
	 * Only `components.objects` is walked: registers and schemas are
	 * definitions, never demo values. A string that merely contains a
	 * placeholder, or names an unknown unit, is left as it is.
	 *
	 * @param array<string, mixed> $data  The decoded descriptor.
	 * @param DateTimeImmutable    $today The import day.
	 *
	 * @return array<string, mixed> The descriptor with dates in place of the placeholders.
	 *
	 * @spec openspec/changes/archive/2026-11-24-contracts-expiry-and-owner/specs/contract-expiry-and-owner/spec.md#requirement-req-ceo-001-the-contract-status-must-include-expiring-set-by-the-daily-contract-job-inside-the-notice-window
	 */
	public function resolve(array $data, DateTimeImmutable $today): array {
		$objects = ($data['components']['objects'] ?? null);
		if (is_array($objects) === false) {
			return $data;
		}

		$data['components']['objects'] = $this->resolveValue(value: $objects, today: $today->setTime(0, 0));

		return $data;
	}//end resolve()

	/**
	 * Resolve one value, recursing into arrays.
	 *
	 * @param mixed             $value The value.
	 * @param DateTimeImmutable $today The import day at midnight.
	 *
	 * @return mixed
	 */
	private function resolveValue(mixed $value, DateTimeImmutable $today): mixed {
		if (is_array($value) === true) {
			foreach ($value as $key => $item) {
				$value[$key] = $this->resolveValue(value: $item, today: $today);
			}

			return $value;
		}

		if (is_string($value) === false || preg_match(self::PLACEHOLDER, $value, $match) !== 1) {
			return $value;
		}

		if (isset($match[1]) === false) {
			return $today->format('Y-m-d');
		}

		return $today->modify($match[1] . $match[2] . ' ' . self::UNITS[$match[3]])->format('Y-m-d');
	}//end resolveValue()
	/**
	 * The descriptor with every relative duration of its objects resolved.
	 *
	 * A demo dataset that holds fixed dates ages: a review "a week ago" is a
	 * year old a year later. A top-level field of a demo object whose value is
	 * a negative ISO 8601 duration is replaced by the import moment minus that
	 * duration, as an ISO 8601 date-time. Every other value is left as it is.
	 *
	 * @param array<string, mixed> $data The decoded descriptor.
	 * @param DateTimeImmutable    $now  The import moment.
	 *
	 * @return array<string, mixed> The descriptor with absolute dates.
	 *
	 * @spec openspec/changes/organisations-role-mapping-and-access-review/specs/role-mapping-and-access-review/spec.md#requirement-req-rma-003-a-functional-administrator-shall-review-the-access-of-their-organisations-people
	 */
	public static function resolveDurations(array $data, DateTimeImmutable $now): array {
		$objects = ($data['components']['objects'] ?? null);
		if (is_array($objects) === false) {
			return $data;
		}

		foreach ($objects as $index => $object) {
			if (is_array($object) === false) {
				continue;
			}

			foreach ($object as $field => $value) {
				$date = self::durationDate(value: $value, now: $now);
				if ($date !== null) {
					$data['components']['objects'][$index][$field] = $date;
				}
			}
		}

		return $data;
	}//end resolveDurations()

	/**
	 * The date a relative duration stands for, or null for any other value.
	 *
	 * @param mixed             $value The field value.
	 * @param DateTimeImmutable $now   The import moment.
	 *
	 * @return string|null The ISO 8601 date-time, or null.
	 */
	private static function durationDate(mixed $value, DateTimeImmutable $now): ?string {
		if (is_string($value) === false || $value === '-P' || preg_match(self::DURATION, $value, $match) !== 1) {
			return null;
		}

		return $now->sub(new DateInterval($match[1]))->format(DateTimeInterface::ATOM);
	}//end durationDate()
}//end class
