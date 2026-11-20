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
 * @spec openspec/changes/contracts-expiry-and-owner/specs/contract-expiry-and-owner/spec.md#requirement-req-ceo-001-the-contract-status-must-include-expiring-set-by-the-daily-contract-job-inside-the-notice-window
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

use DateTimeImmutable;

/**
 * Turns `{{today±N[d|m|y]}}` strings in demo objects into dates.
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
	 * @spec openspec/changes/contracts-expiry-and-owner/specs/contract-expiry-and-owner/spec.md#requirement-req-ceo-001-the-contract-status-must-include-expiring-set-by-the-daily-contract-job-inside-the-notice-window
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
}//end class
