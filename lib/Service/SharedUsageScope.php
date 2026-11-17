<?php

/**
 * The usages an organisation reads because it participates in them.
 *
 * Pure functions over usage records: which of them are shared with an
 * organisation, the `_shared` marker the list shows, merging them into the
 * organisation's own page, and which connections those usages hold.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/shared-usage-visible-to-participants/specs/deelnames-gebruik/spec.md#requirement-a-participant-reads-the-usages-shared-with-it-and-their-connections-req-dlg-010
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

/**
 * Selects, marks and merges the usages shared with an organisation.
 *
 * @category Service
 * @package  OCA\Stackiq\Service
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/shared-usage-visible-to-participants/specs/deelnames-gebruik/spec.md#requirement-a-participant-reads-the-usages-shared-with-it-and-their-connections-req-dlg-010
 */
final class SharedUsageScope {
	/**
	 * The usages of a result that list the organisation as a participant and
	 * that it does not own, each with its `_shared` marker.
	 *
	 * The search that produced the records already filters on participants;
	 * this checks every record again, so a filter the store ignored cannot
	 * hand an outsider another organisation's usage.
	 *
	 * @param array<int, mixed> $usages       The usage records.
	 * @param string            $organisation The organisation's uuid.
	 *
	 * @return list<array<string, mixed>> The shared usages, marked.
	 *
	 * @spec openspec/changes/shared-usage-visible-to-participants/specs/deelnames-gebruik/spec.md#requirement-a-participant-reads-the-usages-shared-with-it-and-their-connections-req-dlg-010
	 */
	public static function sharedWith(array $usages, string $organisation): array {
		if ($organisation === '') {
			return [];
		}

		$shared = [];
		foreach ($usages as $usage) {
			if (is_array($usage) === false) {
				continue;
			}

			if (RelationRefs::ref(value: ($usage['consumer'] ?? null)) === $organisation) {
				continue;
			}

			if (in_array($organisation, RelationRefs::refs(values: ($usage['participants'] ?? [])), true) === false) {
				continue;
			}

			$usage['_shared'] = self::marker(usage: $usage);
			$shared[]         = $usage;
		}

		return $shared;
	}//end sharedWith()

	/**
	 * The `_shared` marker of a usage: the owning organisation, which runs it
	 * for the cooperation, and how many organisations share it (the owner and
	 * every participant, each counted once).
	 *
	 * @param array<string, mixed> $usage The usage record.
	 *
	 * @return array{cooperation: string|null, participantCount: int}
	 *
	 * @spec openspec/changes/shared-usage-visible-to-participants/specs/deelnames-gebruik/spec.md#requirement-a-participant-reads-the-usages-shared-with-it-and-their-connections-req-dlg-010
	 */
	public static function marker(array $usage): array {
		$owner   = RelationRefs::ref(value: ($usage['consumer'] ?? null));
		$sharers = RelationRefs::refs(values: ($usage['participants'] ?? []));
		if ($owner !== null) {
			$sharers[] = $owner;
		}

		return [
			'cooperation' => $owner,
			'participantCount' => count(array_unique($sharers)),
		];
	}//end marker()

	/**
	 * The organisation's own result page with the shared usages added.
	 *
	 * A shared usage already on the page (by id) is not added twice; the
	 * total grows by the number added.
	 *
	 * @param array<string, mixed>       $own    The paginated result of the organisation's own usages.
	 * @param list<array<string, mixed>> $shared The shared usages, marked.
	 *
	 * @return array<string, mixed> The merged result.
	 *
	 * @spec openspec/changes/shared-usage-visible-to-participants/specs/deelnames-gebruik/spec.md#requirement-a-participant-reads-the-usages-shared-with-it-and-their-connections-req-dlg-010
	 */
	public static function merge(array $own, array $shared): array {
		$results = [];
		if (is_array($own['results'] ?? null) === true) {
			$results = array_values($own['results']);
		}

		$seen = [];
		foreach ($results as $usage) {
			if (is_array($usage) === false) {
				continue;
			}

			$id = self::idOf(usage: $usage);
			if ($id !== null) {
				$seen[$id] = true;
			}
		}

		$added = 0;
		foreach ($shared as $usage) {
			$id = self::idOf(usage: $usage);
			if ($id !== null && isset($seen[$id]) === true) {
				continue;
			}

			if ($id !== null) {
				$seen[$id] = true;
			}

			$results[] = $usage;
			$added++;
		}

		$own['results'] = $results;
		$own['total']   = (int) ($own['total'] ?? 0) + $added;

		return $own;
	}//end merge()

	/**
	 * The connections held by usages the organisation owns or takes part in,
	 * with the organisations each is shared with.
	 *
	 * Only usages that have participants share their connections. For each
	 * connection: `sharedWith` lists every organisation of those usages except
	 * the asking one (owners and participants), and `usages` the usage ids.
	 * A usage the organisation neither owns nor takes part in is ignored.
	 *
	 * @param array<int, mixed> $usages       The usage records.
	 * @param string            $organisation The organisation's uuid.
	 *
	 * @return array<string, array{sharedWith: list<string>, usages: list<string>}> Keyed by connection uuid.
	 *
	 * @spec openspec/changes/shared-usage-visible-to-participants/specs/deelnames-gebruik/spec.md#requirement-the-connections-list-says-with-whom-a-connection-is-shared-req-dlg-011
	 */
	public static function connections(array $usages, string $organisation): array {
		if ($organisation === '') {
			return [];
		}

		$connections = [];
		foreach ($usages as $usage) {
			if (is_array($usage) === false) {
				continue;
			}

			$owner        = RelationRefs::ref(value: ($usage['consumer'] ?? null));
			$participants = RelationRefs::refs(values: ($usage['participants'] ?? []));
			if ($participants === []) {
				continue;
			}

			$involved = $participants;
			if ($owner !== null) {
				array_unshift($involved, $owner);
			}

			$involved = array_values(array_unique($involved));
			if (in_array($organisation, $involved, true) === false) {
				continue;
			}

			$others  = array_values(array_diff($involved, [$organisation]));
			$usageId = self::idOf(usage: $usage);
			foreach (RelationRefs::refs(values: ($usage['koppelingen'] ?? [])) as $connection) {
				$entry = $connections[$connection] ?? ['sharedWith' => [], 'usages' => []];
				$entry['sharedWith'] = array_values(array_unique(array_merge($entry['sharedWith'], $others)));
				if ($usageId !== null && in_array($usageId, $entry['usages'], true) === false) {
					$entry['usages'][] = $usageId;
				}

				$connections[$connection] = $entry;
			}
		}//end foreach

		return $connections;
	}//end connections()

	/**
	 * The id of a usage record.
	 *
	 * @param array<string, mixed> $usage The usage record.
	 *
	 * @return string|null
	 */
	private static function idOf(array $usage): ?string {
		$id = ($usage['id'] ?? $usage['uuid'] ?? $usage['@self']['id'] ?? null);
		if (is_string($id) === false || $id === '') {
			return null;
		}

		return $id;
	}//end idOf()
}//end class
