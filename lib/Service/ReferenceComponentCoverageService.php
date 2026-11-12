<?php

/**
 * Reference component coverage of one organisation's landscape.
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

use DateTimeImmutable;
use Exception;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Reads the reference components and the organisation's usages, bounded, and derives the coverage.
 *
 * The caller has already checked the user may read the organisation.
 *
 * @spec openspec/changes/architecture-reference-component-coverage/specs/reference-component-coverage/spec.md#requirement-req-rcc-001-stackiq-shall-give-each-reference-component-a-coverage-state-for-one-organisation
 */
class ReferenceComponentCoverageService {
	/**
	 * The most reference components read, as FacetService bounds its element lookups.
	 */
	public const COMPONENT_LIMIT = 1000;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Resolves the register and schema ids.
	 * @param IAppManager $appManager Checks OpenRegister is installed.
	 * @param ContainerInterface $container Lazy OpenRegister lookups.
	 * @param LoggerInterface $logger Logger.
	 * @param ReferenceComponentCoverageDerivation $derivation Derives the states.
	 * @param PortfolioReportDerivation $relations Normalises results and relation ids.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly IAppManager $appManager,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly ReferenceComponentCoverageDerivation $derivation,
		private readonly PortfolioReportDerivation $relations,
	) {
	}//end __construct()

	/**
	 * Build the coverage of one organisation.
	 *
	 * @param string $organisationUuid The consuming organisation.
	 *
	 * @return array<string,mixed> organisation, summary, components and the bounds.
	 *
	 * @throws Exception When OpenRegister or the register configuration is unavailable.
	 *
	 * @spec openspec/changes/architecture-reference-component-coverage/specs/reference-component-coverage/spec.md#requirement-req-rcc-001-stackiq-shall-give-each-reference-component-a-coverage-state-for-one-organisation
	 */
	public function build(string $organisationUuid): array {
		$source   = $this->usageSource();
		$page     = $this->organisationUsages(organisationUuid: $organisationUuid, source: $source);
		$modules  = $this->modules(usages: $page['usages'], registerId: $source['register'], schemaId: $source['module']);
		$coverage = $this->coverage(usages: $page['usages'], modules: $modules);

		return [
			'organisation' => $organisationUuid,
			'generatedAt' => (new DateTimeImmutable())->format(DATE_ATOM),
			'summary' => $coverage['summary'],
			'components' => $coverage['components'],
		] + $page['counts'];
	}//end build()

	/**
	 * The register and the usage and module schemas the coverage reads, checked to be usable.
	 *
	 * @return array{register: int, usage: int, module: int} The register and schema ids.
	 *
	 * @throws Exception When OpenRegister or the register configuration is unavailable.
	 */
	private function usageSource(): array {
		if (in_array('openregister', $this->appManager->getInstalledApps(), true) === false) {
			throw new Exception('OpenRegister app is not installed');
		}

		$config = $this->settingsService->getVoorzieningenConfig();
		$source = [
			'register' => (int)($config['register'] ?? 0),
			'usage'    => (int)($config['gebruik_schema'] ?? 0),
			'module'   => (int)($config['module_schema'] ?? 0),
		];
		if ($source['register'] === 0 || $source['usage'] === 0) {
			throw new Exception('Voorzieningen configuration not found. Please configure the schemas in the admin panel.');
		}

		return $source;
	}//end usageSource()

	/**
	 * The usages one organisation consumes, with the page counts.
	 *
	 * Scoped by the `consumer` filter to the one organisation the coverage is for.
	 *
	 * @param string                                         $organisationUuid The consuming organisation.
	 * @param array{register: int, usage: int, module: int} $source           The register and schema ids.
	 *
	 * @return array{usages: array<int, array<string, mixed>>, counts: array{total: int, truncated: bool}}
	 */
	private function organisationUsages(string $organisationUuid, array $source): array {
		$usageResult = $this->search(query: [
			'@self' => ['register' => $source['register'], 'schema' => $source['usage']],
			'consumer' => $organisationUuid,
			'_limit' => PortfolioReportService::DEFAULT_PAGE_SIZE_CEILING,
		]);
		$usages = $this->relations->normalizeResults(results: $usageResult['results'] ?? []);
		$total  = (int)($usageResult['total'] ?? count($usages));

		return ['usages' => $usages, 'counts' => ['total' => $total, 'truncated' => $total > count($usages)]];
	}//end organisationUsages()

	/**
	 * Derive the coverage from the reference components, the usages and their modules.
	 *
	 * @param array<int, array<string, mixed>>    $usages  The organisation's usages.
	 * @param array<string, array<string, mixed>> $modules The used modules, by id.
	 *
	 * @return array<string, mixed> The derivation's summary and components.
	 */
	private function coverage(array $usages, array $modules): array {
		return $this->derivation->derive(
			components: $this->components(),
			usages: $usages,
			moduleNames: array_map(static fn (array $module): string => (string)($module['name'] ?? ''), $modules),
			moduleComponents: array_map(fn (array $module): array => $this->moduleComponentIds(module: $module), $modules)
		);
	}//end coverage()

	/**
	 * The reference component ids one module names.
	 *
	 * @param array<string, mixed> $module The module.
	 *
	 * @return array<int|string, string>
	 */
	private function moduleComponentIds(array $module): array {
		return array_map(
			fn (mixed $value): string => $this->relations->resolveRelationId(value: $value),
			(array)($module['referenceComponents'] ?? [])
		);
	}//end moduleComponentIds()

	/**
	 * The same coverage as CSV, one row per reference component.
	 *
	 * @param string $organisationUuid The consuming organisation.
	 *
	 * @return string The CSV document.
	 *
	 * @throws Exception When OpenRegister or the register configuration is unavailable.
	 *
	 * @spec openspec/changes/architecture-reference-component-coverage/specs/reference-component-coverage/spec.md#requirement-req-rcc-001-stackiq-shall-give-each-reference-component-a-coverage-state-for-one-organisation
	 */
	public function buildCsv(string $organisationUuid): string {
		$coverage = $this->build(organisationUuid: $organisationUuid);

		$handle = fopen('php://temp', 'r+');
		fputcsv($handle, ['group', 'referenceComponent', 'state', 'fillable', 'applications']);
		foreach ($coverage['components'] as $row) {
			fputcsv($handle, self::csvCells(row: $row));
		}

		rewind($handle);
		$csv = (string)stream_get_contents($handle);
		fclose($handle);

		return $csv;
	}//end buildCsv()

	/**
	 * The CSV cells of one reference component row, each through the formula guard.
	 *
	 * @param array<string,mixed> $row One component row of the coverage.
	 *
	 * @return array<int,string> The cells: group, name, state, fillable, applications.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) csvSafeCell() is the portfolio report's pure cell guard, shared so both CSVs escape alike.
	 */
	private static function csvCells(array $row): array {
		$fillable = 'no';
		if ($row['fillable'] === true) {
			$fillable = 'yes';
		}

		$cells = [
			$row['group'],
			$row['name'],
			$row['state'],
			$fillable,
			implode(' | ', array_column($row['applications'], 'moduleName')),
		];
		return array_map(static fn (string $cell): string => PortfolioReportService::csvSafeCell(value: $cell), $cells);
	}//end csvCells()

	/**
	 * The GEMMA reference components, bounded.
	 *
	 * @return array<int,array<string,mixed>> The elements, empty when no GEMMA model is imported.
	 */
	private function components(): array {
		$amef = $this->settingsService->getAmefConfig();
		$registerId = (int)($amef['register'] ?? 0);
		$schemaId = (int)($amef['element_schema'] ?? 0);
		if ($registerId === 0 || $schemaId === 0) {
			return [];
		}

		return $this->lookup(query: [
			'@self' => ['register' => $registerId, 'schema' => $schemaId],
			'gemmaType' => 'referentiecomponent',
			'_limit' => self::COMPONENT_LIMIT,
		]);
	}//end components()

	/**
	 * The modules the usages name, by id.
	 *
	 * @param array<int,array<string,mixed>> $usages The usages.
	 * @param int $registerId The voorzieningen register.
	 * @param int $schemaId The module schema.
	 *
	 * @return array<string,array<string,mixed>> Modules by id.
	 */
	private function modules(array $usages, int $registerId, int $schemaId): array {
		$ids = array_values(array_unique(array_filter(array_map(
			fn (array $usage): string => $this->relations->resolveRelationId(value: $usage['module'] ?? null),
			$usages
		))));
		if ($ids === [] || $schemaId === 0) {
			return [];
		}

		$modules = $this->lookup(query: [
			'@self' => ['register' => $registerId, 'schema' => $schemaId],
			'id' => $ids,
			'_limit' => count($ids),
		]);

		$byId = [];
		foreach ($modules as $module) {
			$id = $this->relations->resolveRelationId(value: $module['id'] ?? ($module['@self']['id'] ?? null));
			$byId[$id] = $module;
		}

		return $byId;
	}//end modules()

	/**
	 * One bounded OpenRegister search, RBAC off after the controller's organisation check.
	 *
	 * @param array<string,mixed> $query The query, with `_limit`.
	 *
	 * @return array<string,mixed> The paginated result.
	 */
	private function search(array $query): array {
		return $this->container->get('OCA\OpenRegister\Service\ObjectService')
			->searchObjectsPaginated(query: $query, _rbac: false, _multitenancy: false);
	}//end search()

	/**
	 * A lookup that may fail without failing the report: it then reads as empty.
	 *
	 * @param array<string,mixed> $query The query, with `_limit`.
	 *
	 * @return array<int,array<string,mixed>> The normalised results.
	 */
	private function lookup(array $query): array {
		try {
			return $this->relations->normalizeResults(results: $this->search(query: $query)['results'] ?? []);
		} catch (\Throwable $e) {
			$this->logger->warning('ReferenceComponentCoverageService: lookup failed', ['error' => $e->getMessage()]);
			return [];
		}
	}//end lookup()
}//end class
