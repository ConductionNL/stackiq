<?php

/**
 * Stackiq LandscapeAtDateDerivation
 *
 * An organisation's application landscape on any date, read from its usages'
 * phase dates (design D1): every usage In production or To be phased out on
 * that date, a planned replacement due on or before it swapped for its
 * successor module, and optionally a scenario's change set on top (design D2).
 * Pure: no reads, no writes.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/architecture-future-state-scenarios/specs/future-state-scenarios/spec.md#requirement-req-fss-001-stackiq-shall-derive-an-organisations-landscape-on-any-date-from-its-usage-dates
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

use DateTimeImmutable;

/**
 * Derives the landscape on a date, with or without a scenario.
 *
 * @spec openspec/changes/architecture-future-state-scenarios/specs/future-state-scenarios/spec.md#requirement-req-fss-001-stackiq-shall-derive-an-organisations-landscape-on-any-date-from-its-usage-dates
 */
class LandscapeAtDateDerivation {

	/**
	 * The phases, and the status values, that put a usage in the landscape.
	 *
	 * @var array<int,string>
	 */
	public const IN_LANDSCAPE = ['In production', 'To be phased out'];

	/**
	 * The phase date fields of a usage.
	 *
	 * @var array<int,string>
	 */
	private const PHASE_DATES = ['startDateAcquisition', 'startDatePlanned', 'startDateInProduction', 'startDateOutPhasing', 'startDateOutPhased'];

	/**
	 * Constructor.
	 *
	 * @param PortfolioReportDerivation $phases Derives a usage's phase on a date and reads relation ids.
	 *
	 * @spec openspec/changes/architecture-future-state-scenarios/specs/future-state-scenarios/spec.md#requirement-req-fss-001-stackiq-shall-derive-an-organisations-landscape-on-any-date-from-its-usage-dates
	 */
	public function __construct(
		private readonly PortfolioReportDerivation $phases,
	) {
	}//end __construct()

	/**
	 * The landscape on a date.
	 *
	 * Each entry is one application in the landscape: the usage it comes from
	 * (null for a scenario addition), its module, the reference components it
	 * is used for, the sources that put it there (`today`, `plan` or
	 * `scenario`) and, for a successor, the usage it replaces and from when.
	 * `removed` lists the usages a planned replacement or a scenario took out.
	 *
	 * @param array<int,array<string,mixed>> $usages  The organisation's usages, each with its `id`.
	 * @param DateTimeImmutable              $date    The date.
	 * @param array<int,array<string,mixed>> $changes A scenario's changes, empty for the plan alone.
	 *
	 * @return array{entries: array<int,array<string,mixed>>, removed: array<int,array<string,mixed>>, notDated: int}
	 *
	 * @spec openspec/changes/architecture-future-state-scenarios/specs/future-state-scenarios/spec.md#requirement-req-fss-001-stackiq-shall-derive-an-organisations-landscape-on-any-date-from-its-usage-dates
	 */
	public function derive(array $usages, DateTimeImmutable $date, array $changes=[]): array {
		$entries  = [];
		$removed  = [];
		$notDated = 0;
		foreach ($usages as $usage) {
			$phase = $this->phaseOn(usage: $usage, date: $date);
			if ($phase === null) {
				$notDated++;
				continue;
			}

			if (in_array($phase, self::IN_LANDSCAPE, true) === false) {
				continue;
			}

			$id = $this->phases->resolveRelationId(value: ($usage['id'] ?? null));
			$entries[$id] = $this->entry(usage: $usage, date: $date, removed: $removed);
		}

		foreach ($this->changesInOrder(changes: $changes, date: $date) as $position => $change) {
			$this->applyChange(change: $change + ['position' => $position], usages: $usages, entries: $entries, removed: $removed);
		}

		return ['entries' => array_values($entries), 'removed' => array_values($removed), 'notDated' => $notDated];
	}//end derive()

	/**
	 * A usage's phase on the date; its status when it has no phase date; null when neither tells.
	 *
	 * @param array<string,mixed> $usage The usage.
	 * @param DateTimeImmutable   $date  The date.
	 *
	 * @return string|null
	 */
	private function phaseOn(array $usage, DateTimeImmutable $date): ?string {
		foreach (self::PHASE_DATES as $field) {
			if ($this->phases->parseDate(value: ($usage[$field] ?? null)) !== null) {
				return $this->phases->deriveLifecyclePhase(usage: $usage, now: $date);
			}
		}

		$status = (string)($usage['status'] ?? '');
		if (in_array($status, self::IN_LANDSCAPE, true) === true) {
			return $status;
		}

		return null;
	}//end phaseOn()

	/**
	 * The entry a usage puts in the landscape: itself, or its planned successor when the replacement is due.
	 *
	 * @param array<string,mixed>                       $usage   The usage.
	 * @param DateTimeImmutable                         $date    The date.
	 * @param array<string,array<string,mixed>>         $removed The removed usages, appended to.
	 *
	 * @return array<string,mixed>
	 */
	private function entry(array $usage, DateTimeImmutable $date, array &$removed): array {
		$id        = $this->phases->resolveRelationId(value: ($usage['id'] ?? null));
		$module    = $this->phases->resolveRelationId(value: ($usage['module'] ?? null));
		$components = $this->ids(values: ($usage['usedForReferenceComponents'] ?? []));
		$successor = $this->phases->resolveRelationId(value: ($usage['plannedReplacement'] ?? null));
		$due       = $this->phases->parseDate(value: ($usage['plannedReplacementDate'] ?? null));

		if ($successor === '' || $due === null || $due > $date) {
			return ['usage' => $id, 'module' => $module, 'referenceComponents' => $components, 'sources' => ['today'], 'replaces' => null, 'date' => null];
		}

		$due           = $due->format('Y-m-d');
		$removed[$id] = ['usage' => $id, 'module' => $module, 'sources' => ['plan'], 'date' => $due, 'replacedBy' => $successor];

		return ['usage' => $id, 'module' => $successor, 'referenceComponents' => $components, 'sources' => ['plan'], 'replaces' => $module, 'date' => $due];
	}//end entry()

	/**
	 * The scenario changes effective on or before the date, by effective date.
	 *
	 * A change without its own effective date takes the date asked for, which
	 * is the scenario's target date when the comparison asks for a scenario.
	 *
	 * @param array<int,array<string,mixed>> $changes The changes.
	 * @param DateTimeImmutable              $date    The date.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function changesInOrder(array $changes, DateTimeImmutable $date): array {
		$dated = [];
		foreach (array_values($changes) as $index => $change) {
			$effective = ($this->phases->parseDate(value: ($change['effectiveDate'] ?? null)) ?? $date);
			if ($effective <= $date) {
				$dated[] = ['at' => $effective->format('Y-m-d'), 'index' => $index, 'change' => $change];
			}
		}

		usort($dated, static fn (array $a, array $b): int => [$a['at'], $a['index']] <=> [$b['at'], $b['index']]);

		return array_map(static fn (array $one): array => $one['change'] + ['effectiveDate' => $one['at']], $dated);
	}//end changesInOrder()

	/**
	 * Apply one scenario change to the landscape. A change on a usage the plan
	 * also touched wins, and names both sources.
	 *
	 * @param array<string,mixed>               $change  The change.
	 * @param array<int,array<string,mixed>>    $usages  The organisation's usages.
	 * @param array<string,array<string,mixed>> $entries The landscape, changed in place.
	 * @param array<string,array<string,mixed>> $removed The removed usages, changed in place.
	 *
	 * @return void
	 */
	private function applyChange(array $change, array $usages, array &$entries, array &$removed): void {
		$action = (string)($change['action'] ?? '');
		$date   = (string)$change['effectiveDate'];
		$module = $this->phases->resolveRelationId(value: ($change['module'] ?? null));
		if ($action === 'add') {
			$entries['change:' . (int)$change['position']] = [
				'usage' => null, 'module' => $module, 'referenceComponents' => $this->ids(values: ($change['referenceComponents'] ?? [])),
				'sources' => ['scenario'], 'replaces' => null, 'date' => $date,
			];
			return;
		}

		$usageId = $this->phases->resolveRelationId(value: ($change['usage'] ?? null));
		if (isset($entries[$usageId]) === false || in_array($action, ['phase out', 'replace'], true) === false) {
			return;
		}

		$sources  = array_values(array_unique(array_merge(['scenario'], array_diff($entries[$usageId]['sources'], ['today']))));
		$original = $this->originalModule(usages: $usages, usageId: $usageId);
		$removed[$usageId] = ['usage' => $usageId, 'module' => $original, 'sources' => $sources, 'date' => $date, 'replacedBy' => null];
		unset($entries[$usageId]);

		if ($action === 'replace') {
			$components = $this->ids(values: ($change['referenceComponents'] ?? []));
			if ($components === []) {
				$components = $this->ids(values: ($this->usageById(usages: $usages, usageId: $usageId)['usedForReferenceComponents'] ?? []));
			}

			$removed[$usageId]['replacedBy'] = $module;
			$entries[$usageId] = [
				'usage' => $usageId, 'module' => $module, 'referenceComponents' => $components,
				'sources' => $sources, 'replaces' => $original, 'date' => $date,
			];
		}
	}//end applyChange()

	/**
	 * The module a usage has today, before any replacement.
	 *
	 * @param array<int,array<string,mixed>> $usages  The usages.
	 * @param string                         $usageId The usage id.
	 *
	 * @return string
	 */
	private function originalModule(array $usages, string $usageId): string {
		return $this->phases->resolveRelationId(value: ($this->usageById(usages: $usages, usageId: $usageId)['module'] ?? null));
	}//end originalModule()

	/**
	 * One usage by id, or an empty array.
	 *
	 * @param array<int,array<string,mixed>> $usages  The usages.
	 * @param string                         $usageId The usage id.
	 *
	 * @return array<string,mixed>
	 */
	private function usageById(array $usages, string $usageId): array {
		foreach ($usages as $usage) {
			if ($this->phases->resolveRelationId(value: ($usage['id'] ?? null)) === $usageId) {
				return $usage;
			}
		}

		return [];
	}//end usageById()

	/**
	 * Distinct, non-empty relation ids.
	 *
	 * @param mixed $values A list of relations.
	 *
	 * @return array<int,string>
	 */
	private function ids(mixed $values): array {
		if (is_array($values) === false) {
			return [];
		}

		$ids = array_map(fn (mixed $value): string => $this->phases->resolveRelationId(value: $value), $values);

		return array_values(array_unique(array_filter($ids, static fn (string $id): bool => $id !== '')));
	}//end ids()
}//end class
