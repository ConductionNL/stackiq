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
		$covered = $this->applicationsByComponent(usages: $usages, moduleNames: $moduleNames);
		$index = new CoverageIndex(
			applications: $covered['applications'],
			fillableBy: $this->fillableBy(moduleComponents: $moduleComponents, moduleNames: $moduleNames)
		);

		$rows = array_map(fn (array $component): array => $this->componentRow(component: $component, index: $index), array_values($components));
		$summary = $this->summary(rows: $rows, withoutComponent: $covered['withoutComponent']);

		usort($rows, static fn (array $a, array $b): int => [$a['group'], $a['name']] <=> [$b['group'], $b['name']]);

		return ['summary' => $summary, 'components' => $rows];
	}//end derive()

	/**
	 * The applications that cover each component: one per module, from the usages that count;
	 * and how many usages name no component.
	 *
	 * @param array<int, array<string, mixed>> $usages The usages.
	 * @param array<string, string> $moduleNames The module names by id.
	 *
	 * @return array{applications: array<string, array<string, array<string, string>>>, withoutComponent: int}
	 */
	private function applicationsByComponent(array $usages, array $moduleNames): array {
		return [
			'applications' => $this->coveringApplications(usages: $usages, moduleNames: $moduleNames),
			'withoutComponent' => $this->usagesWithoutComponent(usages: $usages),
		];
	}//end applicationsByComponent()

	/**
	 * The applications that cover each component, from the usages that count; one per module.
	 *
	 * @param array<int, array<string, mixed>> $usages The usages.
	 * @param array<string, string> $moduleNames The module names by id.
	 *
	 * @return array<string, array<string, array<string, string>>>
	 */
	private function coveringApplications(array $usages, array $moduleNames): array {
		$byComponent = [];
		foreach ($usages as $usage) {
			if (in_array((string)($usage['status'] ?? ''), self::NOT_COUNTING, true) === true) {
				continue;
			}

			$application = $this->application(usage: $usage, moduleNames: $moduleNames);
			foreach ($this->ids(values: $usage['usedForReferenceComponents'] ?? []) as $componentId) {
				$byComponent[$componentId][$application['module']] = $application;
			}
		}

		return $byComponent;
	}//end coveringApplications()

	/**
	 * How many usages, counting or not, name no component.
	 *
	 * @param array<int, array<string, mixed>> $usages The usages.
	 *
	 * @return int
	 */
	private function usagesWithoutComponent(array $usages): int {
		$withoutComponent = 0;
		foreach ($usages as $usage) {
			$withoutComponent += (int)($this->ids(values: $usage['usedForReferenceComponents'] ?? []) === []);
		}

		return $withoutComponent;
	}//end usagesWithoutComponent()

	/**
	 * The application a usage stands for: the usage, its module and the module's name, and the usage's status.
	 *
	 * @param array<string, mixed> $usage The usage.
	 * @param array<string, string> $moduleNames The module names by id.
	 *
	 * @return array{usage: string, module: string, moduleName: string, status: string}
	 */
	private function application(array $usage, array $moduleNames): array {
		$moduleId = $this->relations->resolveRelationId(value: $usage['module'] ?? null);

		return [
			'usage' => $this->relations->resolveRelationId(value: $usage['id'] ?? ($usage['@self']['id'] ?? null)),
			'module' => $moduleId,
			'moduleName' => $moduleNames[$moduleId] ?? '',
			'status' => (string)($usage['status'] ?? ''),
		];
	}//end application()

	/**
	 * The names of the modules that declare each component.
	 *
	 * @param array<string, array<int, string>> $moduleComponents The component ids each module declares.
	 * @param array<string, string> $moduleNames The module names by id.
	 *
	 * @return array<string, array<int, string>>
	 */
	private function fillableBy(array $moduleComponents, array $moduleNames): array {
		$fillableBy = [];
		foreach ($moduleComponents as $moduleId => $componentIds) {
			foreach ($componentIds as $componentId) {
				$fillableBy[$componentId][] = $moduleNames[$moduleId] ?? '';
			}
		}

		return $fillableBy;
	}//end fillableBy()

	/**
	 * One component's row: what it is, its state, whether a used module could fill a gap, and its applications.
	 *
	 * @param array<string, mixed> $component The reference component.
	 * @param CoverageIndex $index The applications and fillable modules per component.
	 *
	 * @return array<string, mixed>
	 */
	private function componentRow(array $component, CoverageIndex $index): array {
		$id = $this->relations->resolveRelationId(value: $component['id'] ?? ($component['@self']['id'] ?? null));

		return array_merge(
			['id' => $id],
			self::componentFacts(component: $component),
			$this->componentCoverage(id: $id, index: $index)
		);
	}//end componentRow()

	/**
	 * A component's coverage: its state, whether a used module could fill a gap, and its applications.
	 *
	 * @param string $id The component id.
	 * @param CoverageIndex $index The applications and fillable modules per component.
	 *
	 * @return array{state: string, fillable: bool, fillableBy: array<int, string>, applications: array<int, array<string, string>>}
	 */
	private function componentCoverage(string $id, CoverageIndex $index): array {
		$applications = array_values($index->applications[$id] ?? []);
		$state = $this->state(count: count($applications));
		$fillable = $state === 'gap' && isset($index->fillableBy[$id]) === true;

		return [
			'state' => $state,
			'fillable' => $fillable,
			'fillableBy' => $this->fillableNames(fillable: $fillable, names: $index->fillableBy[$id] ?? []),
			'applications' => $applications,
		];
	}//end componentCoverage()

	/**
	 * What a component is: its name, group and documentation.
	 *
	 * @param array<string, mixed> $component The reference component.
	 *
	 * @return array{name: string, group: string, documentation: string}
	 */
	private static function componentFacts(array $component): array {
		return [
			'name' => (string)($component['name'] ?? ''),
			'group' => (string)($component['grouping'] ?? ''),
			'documentation' => (string)($component['documentation'] ?? ''),
		];
	}//end componentFacts()

	/**
	 * The counts of the rows per state and of the fillable gaps, and the usages without a component.
	 *
	 * @param array<int, array<string, mixed>> $rows The component rows.
	 * @param int $withoutComponent The usages that name no component.
	 *
	 * @return array<string, int>
	 */
	private function summary(array $rows, int $withoutComponent): array {
		$summary = ['components' => 0, 'gap' => 0, 'fillable' => 0, 'covered' => 0, 'overlap' => 0, 'usagesWithoutComponent' => $withoutComponent];
		foreach ($rows as $row) {
			$summary['components']++;
			$summary[$row['state']]++;
			$summary['fillable'] += (int)$row['fillable'];
		}

		return $summary;
	}//end summary()

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
