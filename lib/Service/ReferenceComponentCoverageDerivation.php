<?php

/**
 * Which reference components an organisation's applications in use cover, miss and double.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/architecture-reference-component-coverage/specs/reference-component-coverage/spec.md#requirement-req-rcc-001-stackiq-shall-give-each-reference-component-a-coverage-state-for-one-organisation
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

/**
 * Pure functions: reference components and usages in, a coverage state per component out.
 *
 * A usage counts unless its status is Acquisition, Planned or Phased out. A
 * component is a gap without a counting usage, covered with one module and an
 * overlap with two or more different modules; two usages of one module are one
 * application. A gap is fillable when a module the organisation uses declares
 * the component in its `referenceComponents`.
 *
 * @spec openspec/changes/architecture-reference-component-coverage/specs/reference-component-coverage/spec.md#requirement-req-rcc-001-stackiq-shall-give-each-reference-component-a-coverage-state-for-one-organisation
 */
class ReferenceComponentCoverageDerivation {
	/**
	 * Usage statuses that do not count as using the application.
	 */
	private const NOT_COUNTING = ['Acquisition', 'Planned', 'Phased out'];

	/**
	 * Constructor.
	 *
	 * @param PortfolioReportDerivation $relations Reads relation ids.
	 */
	public function __construct(
		private readonly PortfolioReportDerivation $relations,
	) {
	}//end __construct()

	/**
	 * Derive the coverage.
	 *
	 * @param array<int,array<string,mixed>> $components Reference component elements.
	 * @param array<int,array<string,mixed>> $usages The organisation's usages.
	 * @param array<string,string> $moduleNames Application names by module id.
	 * @param array<string,array<int,string>> $moduleComponents Component ids each used module declares.
	 *
	 * @return array{summary: array<string,int>, components: array<int,array<string,mixed>>}
	 *
	 * @spec openspec/changes/architecture-reference-component-coverage/specs/reference-component-coverage/spec.md#requirement-req-rcc-001-stackiq-shall-give-each-reference-component-a-coverage-state-for-one-organisation
	 */
	public function derive(array $components, array $usages, array $moduleNames, array $moduleComponents): array {
		$byComponent = [];
		$withoutComponent = 0;
		foreach ($usages as $usage) {
			$named = $this->ids(values: $usage['usedForReferenceComponents'] ?? []);
			if ($named === []) {
				$withoutComponent++;
			}

			if (in_array((string)($usage['status'] ?? ''), self::NOT_COUNTING, true) === true) {
				continue;
			}

			$moduleId = $this->relations->resolveRelationId(value: $usage['module'] ?? null);
			foreach ($named as $componentId) {
				$byComponent[$componentId][$moduleId] = [
					'usage' => $this->relations->resolveRelationId(value: $usage['id'] ?? ($usage['@self']['id'] ?? null)),
					'module' => $moduleId,
					'moduleName' => $moduleNames[$moduleId] ?? '',
					'status' => (string)($usage['status'] ?? ''),
				];
			}
		}//end foreach

		$fillableBy = [];
		foreach ($moduleComponents as $moduleId => $componentIds) {
			foreach ($componentIds as $componentId) {
				$fillableBy[$componentId][] = $moduleNames[$moduleId] ?? '';
			}
		}

		$summary = ['components' => 0, 'gap' => 0, 'fillable' => 0, 'covered' => 0, 'overlap' => 0, 'usagesWithoutComponent' => $withoutComponent];
		$rows = [];
		foreach ($components as $component) {
			$id = $this->relations->resolveRelationId(value: $component['id'] ?? ($component['@self']['id'] ?? null));
			$applications = array_values($byComponent[$id] ?? []);
			$state = $this->state(count: count($applications));
			$fillable = $state === 'gap' && isset($fillableBy[$id]) === true;

			$summary['components']++;
			$summary[$state]++;
			$summary['fillable'] += (int)$fillable;

			$rows[] = [
				'id' => $id,
				'name' => (string)($component['name'] ?? ''),
				'group' => (string)($component['grouping'] ?? ''),
				'documentation' => (string)($component['documentation'] ?? ''),
				'state' => $state,
				'fillable' => $fillable,
				'fillableBy' => $this->fillableNames(fillable: $fillable, names: $fillableBy[$id] ?? []),
				'applications' => $applications,
			];
		}//end foreach

		usort($rows, static fn (array $a, array $b): int => [$a['group'], $a['name']] <=> [$b['group'], $b['name']]);

		return ['summary' => $summary, 'components' => $rows];
	}//end derive()

	/**
	 * The relation ids in a list of references.
	 *
	 * @param mixed $values A list of ids or objects.
	 *
	 * @return array<int,string> Non-empty, unique ids.
	 */
	private function ids(mixed $values): array {
		if (is_array($values) === false) {
			return [];
		}

		$ids = array_map(fn (mixed $value): string => $this->relations->resolveRelationId(value: $value), $values);

		return array_values(array_unique(array_filter($ids, static fn (string $id): bool => $id !== '')));
	}//end ids()

	/**
	 * The state for a number of different applications.
	 *
	 * @param int $count Different modules covering the component.
	 *
	 * @return string gap, covered or overlap.
	 */
	private function state(int $count): string {
		if ($count === 0) {
			return 'gap';
		}

		if ($count === 1) {
			return 'covered';
		}

		return 'overlap';
	}//end state()

	/**
	 * The applications that could fill a gap.
	 *
	 * @param bool $fillable Whether the component is a fillable gap.
	 * @param array<int,string> $names Application names that declare it.
	 *
	 * @return array<int,string> Unique names, empty when it is not a fillable gap.
	 */
	private function fillableNames(bool $fillable, array $names): array {
		if ($fillable === false) {
			return [];
		}

		return array_values(array_unique(array_filter($names)));
	}//end fillableNames()
}//end class
