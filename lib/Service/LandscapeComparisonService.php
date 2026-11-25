<?php

/**
 * Stackiq LandscapeComparisonService
 *
 * Compares an organisation's landscape today with its landscape on a date:
 * the plan alone, or a scenario's change set on top of it (design D3). Reads
 * the usages once with the coverage's bounded query, the scenario and its
 * changes with RBAC on, and scores both landscapes for reference component
 * coverage.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/architecture-future-state-scenarios/specs/future-state-scenarios/spec.md#requirement-req-fss-002-an-information-manager-shall-compare-todays-landscape-with-the-plan-on-a-date
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

use DateTimeImmutable;
use DomainException;
use Exception;
use InvalidArgumentException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\Stackiq\Kernel\Contracts\LandscapeComparisonServiceInterface;
use OCA\Stackiq\Service\Settings\RegisterConfigReaderInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OutOfBoundsException;

/**
 * Builds today's landscape and the landscape on a date and returns their differences.
 *
 * The caller has already checked the user may read the organisation.
 *
 * @spec openspec/changes/architecture-future-state-scenarios/specs/future-state-scenarios/spec.md#requirement-req-fss-002-an-information-manager-shall-compare-todays-landscape-with-the-plan-on-a-date
 */
class LandscapeComparisonService implements LandscapeComparisonServiceInterface {
	/**
	 * The register the scenarios live in.
	 */
	public const REGISTER = 'stackiq';

	/**
	 * The most changes read for one scenario.
	 */
	public const CHANGE_LIMIT = 500;

	/**
	 * The order the application differences are listed in.
	 *
	 * @var array<string,int>
	 */
	private const CHANGE_ORDER = ['added' => 0, 'removed' => 1, 'replaced' => 2, 'unchanged' => 3];

	/**
	 * Module names by id for the comparison being built, set at the start of compare().
	 *
	 * @var array<string,string>
	 */
	private array $names = [];

	/**
	 * Constructor.
	 *
	 * @param ReferenceComponentCoverageService    $reads      Reads the usages, modules and reference components, bounded.
	 * @param LandscapeAtDateDerivation            $landscapes Derives a landscape on a date.
	 * @param ReferenceComponentCoverageDerivation $coverage   Scores a landscape for coverage.
	 * @param PortfolioReportDerivation            $relations  Normalises results, relation ids and dates.
	 * @param RegisterConfigReaderInterface        $registers  Hands out OpenRegister's object service.
	 * @param ITimeFactory                         $time       Today.
	 *
	 * @spec openspec/changes/architecture-future-state-scenarios/specs/future-state-scenarios/spec.md#requirement-req-fss-002-an-information-manager-shall-compare-todays-landscape-with-the-plan-on-a-date
	 */
	public function __construct(
		private readonly ReferenceComponentCoverageService $reads,
		private readonly LandscapeAtDateDerivation $landscapes,
		private readonly ReferenceComponentCoverageDerivation $coverage,
		private readonly PortfolioReportDerivation $relations,
		private readonly RegisterConfigReaderInterface $registers,
		private readonly ITimeFactory $time,
	) {
	}//end __construct()

	/**
	 * Compare an organisation's landscape today with its landscape on a date, or with a scenario's.
	 *
	 * With a scenario, its target date is the date and its changes that have
	 * not been applied yet go on top of the plan; an applied change is already
	 * in the usages, so applying it again would count it twice.
	 *
	 * @param string $organisationUuid The organisation, already checked to be readable by the caller.
	 * @param string $date             The date (Y-m-d); ignored when a scenario is named.
	 * @param string $scenarioId       The scenario, or '' for the plan alone.
	 *
	 * @return array<string,mixed> The comparison in the shape of design D3.
	 *
	 * @throws InvalidArgumentException When neither a valid date nor a scenario with a target date is given.
	 * @throws OutOfBoundsException     When the scenario does not exist or the caller may not read it.
	 * @throws DomainException          When the scenario belongs to another organisation.
	 *
	 * @spec openspec/changes/architecture-future-state-scenarios/specs/future-state-scenarios/spec.md#requirement-req-fss-002-an-information-manager-shall-compare-todays-landscape-with-the-plan-on-a-date
	 * @spec openspec/changes/architecture-future-state-scenarios/specs/future-state-scenarios/spec.md#requirement-req-fss-003-an-information-manager-shall-write-a-scenario-of-additions-phase-outs-and-replacements
	 */
	public function compare(string $organisationUuid, string $date='', string $scenarioId=''): array {
		$today    = new DateTimeImmutable($this->time->now()->format('Y-m-d'));
		$scenario = null;
		$changes  = [];
		if ($scenarioId !== '') {
			$scenario = $this->scenario(scenarioId: $scenarioId, organisationUuid: $organisationUuid);
			$date     = (string)($scenario['targetDate'] ?? '');
			$changes  = $this->openChanges(scenarioId: $scenario['id']);
		}

		$target = $this->day(value: $date);
		$page   = $this->reads->readOrganisationUsages(organisationUuid: $organisationUuid);
		$usages = $this->withIds(usages: $page['usages']);
		$now    = $this->landscapes->derive(usages: $usages, date: $today);
		$future = $this->landscapes->derive(usages: $usages, date: $target, changes: $changes);
		$this->names = $this->moduleNames(landscapes: [$now, $future]);

		return [
			'organisation' => $organisationUuid,
			'today' => $today->format('Y-m-d'),
			'date' => $target->format('Y-m-d'),
			'scenario' => $scenario,
			'landscapes' => ['today' => $this->column(landscape: $now), 'future' => $this->column(landscape: $future)],
			'applications' => $this->applicationDifferences(landscapes: ['today' => $now, 'future' => $future], usages: $usages),
			'coverage' => $this->coverageDifferences(now: $now, future: $future),
			'notDated' => $future['notDated'],
		] + $page['counts'];
	}//end compare()

	/**
	 * A date given as Y-m-d, at midnight.
	 *
	 * @param string $value The date.
	 *
	 * @return DateTimeImmutable
	 *
	 * @throws InvalidArgumentException When the value is not a Y-m-d date.
	 */
	private function day(string $value): DateTimeImmutable {
		$day = DateTimeImmutable::createFromFormat('!Y-m-d', trim($value));
		if ($day === false || $day->format('Y-m-d') !== trim($value)) {
			throw new InvalidArgumentException('date must be a date as YYYY-MM-DD');
		}

		return $day;
	}//end day()

	/**
	 * The scenario, read with RBAC on, checked to be the organisation's.
	 *
	 * @param string $scenarioId       The scenario id.
	 * @param string $organisationUuid The organisation the comparison is for.
	 *
	 * @return array{id: string, name: string, status: string, targetDate: string, appliedAt: string|null}
	 *
	 * @throws OutOfBoundsException When the scenario does not exist or the caller may not read it.
	 * @throws DomainException      When the scenario belongs to another organisation.
	 */
	private function scenario(string $scenarioId, string $organisationUuid): array {
		try {
			$entity = $this->objectService()->find(
				id: $scenarioId,
				options: new ObjectFindOptions(register: self::REGISTER, schema: 'scenario'),
				_rbac: true,
				_multitenancy: true
			);
		} catch (\Throwable $e) {
			$entity = null;
		}

		if ($entity === null) {
			throw new OutOfBoundsException('Scenario not found');
		}

		$data = $entity->getObject();
		if ($this->relations->resolveRelationId(value: ($data['organisation'] ?? null)) !== $organisationUuid) {
			throw new DomainException('The scenario belongs to another organisation');
		}

		$appliedAt = (string)($data['appliedAt'] ?? '');
		if ($appliedAt === '') {
			$appliedAt = null;
		}

		return [
			'id' => (string)($entity->getUuid() ?? $scenarioId),
			'name' => (string)($data['name'] ?? ''),
			'status' => (string)($data['status'] ?? ''),
			'targetDate' => (string)($data['targetDate'] ?? ''),
			'appliedAt' => $appliedAt,
		];
	}//end scenario()

	/**
	 * The scenario's changes not applied yet, read with RBAC on and bounded.
	 *
	 * @param string $scenarioId The scenario id.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) SearchSelection is a stateless adapter between OpenRegister search signatures.
	 */
	private function openChanges(string $scenarioId): array {
		$objectService = $this->objectService();
		$found         = $objectService->searchObjectsBySlug(
			...SearchSelection::bySlug(service: $objectService, method: 'searchObjectsBySlug', register: self::REGISTER, schema: 'scenarioChange'),
			filters: ['scenario' => $scenarioId, '_limit' => self::CHANGE_LIMIT],
			_rbac: true,
			_multitenancy: true
		);
		if (is_array($found) === false) {
			return [];
		}

		$changes = $this->relations->normalizeResults(results: ($found['results'] ?? $found));

		return array_values(array_filter(
			$changes,
			fn (array $change): bool => $this->relations->resolveRelationId(value: ($change['appliedTo'] ?? null)) === ''
		));
	}//end openChanges()

	/**
	 * The usages, each with its `id` set from `@self` when the data bag has none.
	 *
	 * @param array<int,array<string,mixed>> $usages The usages.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function withIds(array $usages): array {
		return array_map(
			static fn (array $usage): array => $usage + ['id' => ($usage['@self']['id'] ?? null)],
			$usages
		);
	}//end withIds()

	/**
	 * The names of every module the landscapes name, by id.
	 *
	 * @param array<int,array<string,mixed>> $landscapes Derived landscapes.
	 *
	 * @return array<string,string>
	 */
	private function moduleNames(array $landscapes): array {
		$ids = [];
		foreach ($landscapes as $landscape) {
			foreach (array_merge($landscape['entries'], $landscape['removed']) as $entry) {
				$ids[] = (string)($entry['module'] ?? '');
				$ids[] = (string)($entry['replaces'] ?? '');
				$ids[] = (string)($entry['replacedBy'] ?? '');
			}
		}

		return array_map(
			static fn (array $module): string => (string)($module['name'] ?? ''),
			$this->reads->readModules(moduleIds: $ids)
		);
	}//end moduleNames()

	/**
	 * One landscape as a column: each application with its module name, by name.
	 *
	 * @param array<string,mixed>  $landscape The derived landscape.
	 *
	 * @return array<int,array{usage: string|null, module: string, moduleName: string}>
	 */
	private function column(array $landscape): array {
		$column = array_map(
			fn (array $entry): array => [
				'usage' => $entry['usage'],
				'module' => $entry['module'],
				'moduleName' => ($this->names[$entry['module']] ?? $entry['module']),
			],
			$landscape['entries']
		);
		usort($column, static fn (array $a, array $b): int => strcasecmp($a['moduleName'], $b['moduleName']));

		return $column;
	}//end column()

	/**
	 * The application differences between today's landscape and the future one.
	 *
	 * @param array{today: array<string,mixed>, future: array<string,mixed>} $landscapes Today's landscape and the future one.
	 * @param array<int,array<string,mixed>>                                   $usages     The organisation's usages.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function applicationDifferences(array $landscapes, array $usages): array {
		$future  = $landscapes['future'];
		$today   = $this->byUsage(entries: $landscapes['today']['entries']);
		$later   = $this->byUsage(entries: $future['entries']);
		$removed = $this->byUsage(entries: $future['removed']);
		$rows    = [];
		foreach ($future['entries'] as $entry) {
			if ($entry['usage'] === null) {
				$rows[] = $this->row(change: 'added', entry: ['module' => $entry['module'], 'sources' => $entry['sources'], 'date' => $entry['date']]);
			}
		}

		foreach (array_keys($today + $later) as $usageId) {
			$rows[] = $this->usageRow(
				sides: [
					'before' => ($today[$usageId] ?? null),
					'after' => ($later[$usageId] ?? null),
					'removal' => ($removed[$usageId] ?? null),
				],
				usage: $this->usageById(usages: $usages, usageId: (string)$usageId)
			);
		}

		usort($rows, static fn (array $a, array $b): int => self::sortKey(row: $a) <=> self::sortKey(row: $b));

		return $rows;
	}//end applicationDifferences()

	/**
	 * Where an application difference sorts: by change, then by module name.
	 *
	 * @param array<string,mixed> $row The difference.
	 *
	 * @return array{0: int, 1: string}
	 */
	private static function sortKey(array $row): array {
		return [self::CHANGE_ORDER[$row['change']], mb_strtolower((string)$row['moduleName'])];
	}//end sortKey()

	/**
	 * The difference for one usage between today and the date.
	 *
	 * Sides: `before` is its entry today, `after` its entry on the date, and
	 * `removal` why it left the landscape by the date, when it did.
	 *
	 * @param array<string,array<string,mixed>|null> $sides The before, after and removal entries.
	 * @param array<string,mixed> $usage The usage.
	 *
	 * @return array<string,mixed>
	 */
	private function usageRow(array $sides, array $usage): array {
		[$before, $after, $removal] = [$sides['before'], $sides['after'], $sides['removal']];
		if ($before === null) {
			$date = $this->dateOf(value: ($usage['startDateInProduction'] ?? null));
			return $this->row(change: 'added', entry: ['module' => $after['module'], 'sources' => ['plan'], 'date' => $date, 'usage' => $after['usage']]);
		}

		if ($after === null) {
			$sources = ($removal['sources'] ?? ['plan']);
			$date    = ($removal['date'] ?? $this->dateOf(value: ($usage['startDateOutPhased'] ?? null)));
			return $this->row(change: 'removed', entry: ['module' => $before['module'], 'sources' => $sources, 'date' => $date, 'usage' => $before['usage']]);
		}

		if ($after['module'] !== $before['module']) {
			$row = $this->row(
				change: 'replaced',
				entry: ['module' => $before['module'], 'sources' => $after['sources'], 'date' => $after['date'], 'usage' => $before['usage']]
			);
			return $row + ['replacedBy' => ($this->names[$after['module']] ?? $after['module']), 'replacedById' => $after['module']];
		}

		return $this->row(change: 'unchanged', entry: ['module' => $before['module'], 'sources' => [], 'date' => null, 'usage' => $before['usage']]);
	}//end usageRow()

	/**
	 * One application difference.
	 *
	 * The source is `scenario` when a scenario change caused it and `plan`
	 * otherwise; `sources` names every source, so a scenario change that won
	 * over a plan replacement names both.
	 *
	 * Entry: `module` id, its `sources`, the `date` of the difference, and
	 * the `usage` id (absent or null for a scenario addition).
	 *
	 * @param string              $change added, removed, replaced or unchanged.
	 * @param array<string,mixed> $entry  The module, sources, date and usage.
	 *
	 * @return array<string,mixed>
	 */
	private function row(string $change, array $entry): array {
		$module  = $entry['module'];
		$sources = array_values(array_diff($entry['sources'], ['today']));
		$source  = null;
		if ($sources !== []) {
			$source = 'plan';
			if (in_array('scenario', $sources, true) === true) {
				$source = 'scenario';
			}
		}

		return [
			'usage' => ($entry['usage'] ?? null),
			'module' => $module,
			'moduleName' => ($this->names[$module] ?? $module),
			'change' => $change,
			'source' => $source,
			'sources' => $sources,
			'date' => $entry['date'],
		];
	}//end row()

	/**
	 * The reference components whose coverage state differs between the two landscapes.
	 *
	 * @param array<string,mixed> $now    Today's landscape.
	 * @param array<string,mixed> $future The future landscape.
	 *
	 * @return array<int,array{componentId: string, component: string, group: string, today: string, future: string, change: string}>
	 */
	private function coverageDifferences(array $now, array $future): array {
		$components = $this->reads->readReferenceComponents();
		if ($components === []) {
			return [];
		}

		$before = $this->states(landscape: $now, components: $components);
		$after  = $this->states(landscape: $future, components: $components);
		$rows   = [];
		foreach ($after as $id => $row) {
			$was = ($before[$id]['state'] ?? 'gap');
			if ($was === $row['state']) {
				continue;
			}

			$rows[] = [
				'componentId' => (string)$id,
				'component' => $row['name'],
				'group' => $row['group'],
				'today' => $was,
				'future' => $row['state'],
				'change' => self::coverageChange(today: $was, future: $row['state']),
			];
		}

		return $rows;
	}//end coverageDifferences()

	/**
	 * The coverage state of each reference component in one landscape, by component id.
	 *
	 * @param array<string,mixed>            $landscape  The derived landscape.
	 * @param array<int,array<string,mixed>> $components The reference components.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function states(array $landscape, array $components): array {
		$usages = [];
		foreach ($landscape['entries'] as $index => $entry) {
			$usages[] = [
				'id' => ($entry['usage'] ?? 'addition-' . $index),
				'module' => $entry['module'],
				'status' => 'In production',
				'usedForReferenceComponents' => $entry['referenceComponents'],
			];
		}

		$rows = $this->coverage->derive(components: $components, usages: $usages, moduleNames: $this->names, moduleComponents: [])['components'];

		return array_column($rows, null, 'id');
	}//end states()

	/**
	 * What a change of coverage state means: a gap closed or opened, an overlap resolved or created.
	 *
	 * @param string $today  The state today: gap, covered or overlap.
	 * @param string $future The state on the date.
	 *
	 * @return string
	 */
	private static function coverageChange(string $today, string $future): string {
		if ($today === 'gap') {
			return 'gap closed';
		}

		if ($future === 'gap') {
			return 'gap opened';
		}

		if ($today === 'overlap') {
			return 'overlap resolved';
		}

		return 'overlap created';
	}//end coverageChange()

	/**
	 * Landscape entries keyed by their usage id; additions without a usage left out.
	 *
	 * @param array<int,array<string,mixed>> $entries The entries.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function byUsage(array $entries): array {
		$byUsage = [];
		foreach ($entries as $entry) {
			if (($entry['usage'] ?? null) !== null) {
				$byUsage[(string)$entry['usage']] = $entry;
			}
		}

		return $byUsage;
	}//end byUsage()

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
			if ($this->relations->resolveRelationId(value: ($usage['id'] ?? null)) === $usageId) {
				return $usage;
			}
		}

		return [];
	}//end usageById()

	/**
	 * A date value as Y-m-d, or null.
	 *
	 * @param mixed $value The raw date.
	 *
	 * @return string|null
	 */
	private function dateOf(mixed $value): ?string {
		return $this->relations->parseDate(value: $value)?->format('Y-m-d');
	}//end dateOf()

	/**
	 * OpenRegister's object service.
	 *
	 * @return ObjectServiceInterface
	 *
	 * @throws Exception When OpenRegister is not available.
	 */
	private function objectService(): ObjectServiceInterface {
		$objectService = $this->registers->getObjectService();
		if ($objectService === null) {
			throw new Exception('OpenRegister app is not installed');
		}

		return $objectService;
	}//end objectService()
}//end class
