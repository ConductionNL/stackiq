<?php

/**
 * Conflict Resolver for StackiqService
 *
 * Extracted from StackiqService to reduce ExcessiveClassLength and
 * ExcessiveClassComplexity on that service. Handles deduplication and conflict resolution.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service\Stackiq
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service\Stackiq;

use Psr\Log\LoggerInterface;

/**
 * Resolves conflicts and deduplicates entries in the Stackiq domain.
 *
 * StackiqService delegates all conflict detection and resolution
 * methods to this class, shrinking its own complexity metrics.
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-2
 */
class ConflictResolver {
	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger Logger instance.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md#task-2
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Detect whether two object data arrays represent the same logical entity.
	 *
	 * Compares canonical identity fields (uuid, kvkNummer, oin) to determine
	 * whether an incoming record would duplicate an existing one.
	 *
	 * @param array<string,mixed> $existing The existing data record.
	 * @param array<string,mixed> $incoming The incoming data record to check.
	 *
	 * @return bool True when the records are considered duplicates.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md#task-2
	 */
	public function isDuplicate(array $existing, array $incoming): bool {
		// UUID match is definitive.
		if ($this->matchesOnField(a: $existing, b: $incoming, field: 'uuid') === true) {
			return true;
		}

		// KVK number match (Dutch company registry).
		if ($this->matchesOnField(a: $existing, b: $incoming, field: 'kvkNummer') === true) {
			return true;
		}

		// OIN match (Dutch government unique identifier).
		if ($this->matchesOnField(a: $existing, b: $incoming, field: 'oin') === true) {
			return true;
		}

		return false;
	}//end isDuplicate()

	/**
	 * Resolve a conflict between an existing and an incoming record.
	 *
	 * When both records have the same identity but different data, the incoming
	 * record's non-null fields are merged on top of the existing record (last-write-wins
	 * per field).
	 *
	 * @param array<string,mixed> $existing The existing record.
	 * @param array<string,mixed> $incoming The incoming (newer) record.
	 *
	 * @return array<string,mixed> The merged record.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md#task-2
	 */
	public function resolve(array $existing, array $incoming): array {
		$merged = $existing;

		foreach ($incoming as $key => $value) {
			// Prefer incoming non-null values.
			if ($value !== null) {
				$merged[$key] = $value;
			}
		}

		$this->logger->debug(
			'ConflictResolver: Resolved conflict between existing and incoming record',
			['changedFields' => array_keys(array_diff_assoc($merged, $existing))]
		);

		return $merged;
	}//end resolve()

	/**
	 * Deduplicate a list of data records using isDuplicate().
	 *
	 * The first occurrence of each identity is kept; subsequent duplicates
	 * are merged into it using resolve().
	 *
	 * @param array<int,array<string,mixed>> $records The list of records to deduplicate.
	 *
	 * @return array<int,array<string,mixed>> Deduplicated record list.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md#task-2
	 */
	public function deduplicate(array $records): array {
		$unique = [];

		foreach ($records as $record) {
			$found = false;
			foreach ($unique as &$existing) {
				if ($this->isDuplicate(existing: $existing, incoming: $record) === true) {
					$existing = $this->resolve(existing: $existing, incoming: $record);
					$found = true;
					break;
				}
			}

			unset($existing);

			if ($found === false) {
				$unique[] = $record;
			}
		}

		$this->logger->debug(
			'ConflictResolver: Deduplicated records',
			['before' => count($records), 'after' => count($unique)]
		);

		return $unique;
	}//end deduplicate()

	/**
	 * Check whether two records share the same value for a given field.
	 *
	 * @param array<string,mixed> $a First record.
	 * @param array<string,mixed> $b Second record.
	 * @param string $field The field name to compare.
	 *
	 * @return bool True when both records have the same non-empty value for the field.
	 */
	private function matchesOnField(array $a, array $b, string $field): bool {
		$valA = $a[$field] ?? null;
		$valB = $b[$field] ?? null;

		if ($valA === null || $valB === null || $valA === '' || $valB === '') {
			return false;
		}

		return $valA === $valB;
	}//end matchesOnField()
}//end class
