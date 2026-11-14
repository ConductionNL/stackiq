<?php

/**
 * Usage classification
 *
 * The overall BBN level of a usage, derived from its three BIO aspects.
 *
 * @category Service
 * @package  OCA\Stackiq\Service
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version  GIT: <git_id>
 * @link     https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/security-baseline-classification/specs/baseline-classification/spec.md#requirement-req-bcl-002-the-overall-bbn-level-shall-be-the-highest-of-the-three-aspects-on-every-save
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

/**
 * Pure rules, no collaborators: the subscriber reads the event and saves, this
 * decides what changes. `bbnLevel` is the highest of availability, integrity
 * and confidentiality, or empty when none is set. `classifiedAt` and
 * `classifiedBy` are stamped only when one of the three levels changed, so a
 * save that changes nothing else, including the subscriber's own write-back,
 * yields no patch and the subscriber does not write again.
 *
 * @spec openspec/changes/security-baseline-classification/specs/baseline-classification/spec.md#requirement-req-bcl-002-the-overall-bbn-level-shall-be-the-highest-of-the-three-aspects-on-every-save
 */
final class UsageClassification {

	/**
	 * The three aspect properties, in the order GEMMA records them.
	 */
	public const ASPECTS = ['availabilityLevel', 'integrityLevel', 'confidentialityLevel'];

	/**
	 * The BIO baseline levels, lowest first.
	 */
	public const LEVELS = ['BBN1', 'BBN2', 'BBN3'];

	/**
	 * The highest of the three aspect levels, or null when none is a known level.
	 *
	 * @param array<string, mixed> $usage The usage data.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/security-baseline-classification/specs/baseline-classification/spec.md#requirement-req-bcl-002-the-overall-bbn-level-shall-be-the-highest-of-the-three-aspects-on-every-save
	 */
	public static function derivedLevel(array $usage): ?string {
		$highest = -1;
		foreach (self::ASPECTS as $aspect) {
			$rank = array_search(($usage[$aspect] ?? null), self::LEVELS, true);
			if ($rank !== false && $rank > $highest) {
				$highest = $rank;
			}
		}

		if ($highest < 0) {
			return null;
		}

		return self::LEVELS[$highest];
	}//end derivedLevel()

	/**
	 * The fields to write after a save, or an empty array when nothing needs writing.
	 *
	 * @param array<string, mixed> $usage    The usage as saved.
	 * @param array<string, mixed> $previous The usage before the save; empty on create.
	 * @param string               $userId   The user who saved, for classifiedBy.
	 * @param string               $now      The save time, ISO 8601, for classifiedAt.
	 *
	 * @return array<string, string|null>
	 *
	 * @spec openspec/changes/security-baseline-classification/specs/baseline-classification/spec.md#requirement-req-bcl-002-the-overall-bbn-level-shall-be-the-highest-of-the-three-aspects-on-every-save
	 */
	public static function patch(array $usage, array $previous, string $userId, string $now): array {
		$patch   = [];
		$derived = self::derivedLevel(usage: $usage);
		if (($usage['bbnLevel'] ?? null) !== $derived) {
			$patch['bbnLevel'] = $derived;
		}

		foreach (self::ASPECTS as $aspect) {
			if (self::value(raw: ($usage[$aspect] ?? null)) !== self::value(raw: ($previous[$aspect] ?? null))) {
				$patch['classifiedAt'] = $now;
				$patch['classifiedBy'] = $userId;
				break;
			}
		}

		return $patch;
	}//end patch()

	/**
	 * A stored aspect value, with an empty string read as not set.
	 *
	 * @param mixed $raw The stored value.
	 *
	 * @return mixed
	 */
	private static function value(mixed $raw): mixed {
		if ($raw === '') {
			return null;
		}

		return $raw;
	}//end value()
}//end class
