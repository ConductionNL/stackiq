<?php

/**
 * Portfolio Rationalization Report Service.
 *
 * Composes a per-organisation portfolio rationalization report: Gartner TIME
 * quadrant counts on `gebruik`, end-of-support exposure (reusing the
 * `application-lifecycle-tracking` derivation), cloud-transition share (from
 * the existing `cloudDienstverleningsmodel` field — no new deployment-model
 * field), and an annualised-cost overlay per quadrant (reusing the
 * `contract-administration` cost derivation). Every figure is computed at
 * query time and never persisted. Every query issued is bounded (explicit
 * `_limit` or `searchObjectsPaginated`), and results are scoped to the
 * caller-authorised organisation by the controller before this service is
 * invoked. Pure derivation rules (phase/EOL/cost/relation-id) live in
 * {@see PortfolioReportDerivation}.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/portfolio-rationalization-time/specs/portfolio-rationalization-time/spec.md#requirement-portfolio-rationalization-report-aggregates-per-organisation
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

use DateTimeImmutable;
use Exception;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Stackiq\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Server-side aggregation for the portfolio rationalization report.
 *
 * @spec openspec/changes/portfolio-rationalization-time/specs/portfolio-rationalization-time/spec.md
 */
class PortfolioReportService {
	/**
	 * The four Gartner TIME quadrant values, matching the gebruik schema's
	 * `timeClassification` enum.
	 */
	public const QUADRANT_TOLERATE = 'Tolerate';
	public const QUADRANT_INVEST = 'Invest';
	public const QUADRANT_MIGRATE = 'Migrate';
	public const QUADRANT_ELIMINATE = 'Eliminate';

	/**
	 * The bucket for gebruiken with no `timeClassification` value set.
	 */
	public const QUADRANT_UNCLASSIFIED = 'Unclassified';

	/**
	 * Rendered quadrant order — Unclassified last, so the four TIME
	 * quadrants read in the canonical Tolerate/Invest/Migrate/Eliminate
	 * order and unclassified entries stay visible rather than omitted.
	 */
	public const QUADRANTS = [
		self::QUADRANT_TOLERATE,
		self::QUADRANT_INVEST,
		self::QUADRANT_MIGRATE,
		self::QUADRANT_ELIMINATE,
		self::QUADRANT_UNCLASSIFIED,
	];

	/**
	 * Default report page-size ceiling, mirrored from
	 * `InitializeSettings::run()`'s seeded `portfolio_report_page_size_ceiling`.
	 */
	public const DEFAULT_PAGE_SIZE_CEILING = 500;

	/**
	 * Contract query limit as a multiple of the gebruik page-size ceiling —
	 * an organisation's gebruiken may each carry more than one linked
	 * contract (renewals, multiple services), so the contract bound is
	 * generous relative to the gebruik bound while staying explicit.
	 */
	private const CONTRACT_LIMIT_MULTIPLIER = 5;

	/**
	 * Per-request cache of resolved moduleVersie/module objects, keyed
	 * `"{schemaId}:{uuid}"`, so the same relation is never fetched twice
	 * while building one report.
	 *
	 * @var array<string,array<string,mixed>|null>
	 */
	private array $relationCache = [];

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Resolves register/schema ids.
	 * @param IAppManager $appManager The application manager.
	 * @param ContainerInterface $container The DI container (lazy OR lookup).
	 * @param LoggerInterface $logger Logger.
	 * @param IAppConfig $config App configuration (page-size ceiling).
	 * @param PortfolioReportDerivation $derivation Pure phase/EOL/cost/relation-id derivation helpers.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly IAppManager $appManager,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly IAppConfig $config,
		private readonly PortfolioReportDerivation $derivation,
	) {
	}//end __construct()

	/**
	 * Build the portfolio rationalization report for one organisation.
	 *
	 * Every OpenRegister query issued here carries an explicit `_limit`
	 * (`bound-unbounded-searchobjects-scans`). The caller (controller) MUST
	 * have already authorised the requesting user for `$organisationUuid`
	 * before invoking this method — this service does not itself gate
	 * access.
	 *
	 * @param string $organisationUuid The `afnemer` organisation UUID to report on.
	 *
	 * @return array<string,mixed> The report payload.
	 *
	 * @throws Exception When OpenRegister or the voorzieningen configuration is unavailable.
	 *
	 * @spec openspec/changes/portfolio-rationalization-time/specs/portfolio-rationalization-time/spec.md#requirement-portfolio-rationalization-report-aggregates-per-organisation
	 * @spec openspec/changes/portfolio-rationalization-time/specs/portfolio-rationalization-time/spec.md#requirement-report-aggregation-queries-are-bounded
	 */
	public function buildReport(string $organisationUuid): array {
		$this->relationCache = [];
		$rows = $this->buildRows(organisationUuid: $organisationUuid);

		return [
			'organisation' => $organisationUuid,
			'generatedAt' => (new DateTimeImmutable())->format(DATE_ATOM),
			'pageSizeCeiling' => $rows['ceiling'],
			'totalGebruiken' => $rows['total'],
			'includedGebruiken' => count($rows['rows']),
			'truncated' => $rows['truncated'],
			'quadrants' => $this->aggregateQuadrants(rows: $rows['rows']),
			'rows' => $rows['rows'],
		];
	}//end buildReport()

	/**
	 * Build the CSV export of the same bounded, organisation-scoped row set
	 * the JSON report uses — never a separate unbounded/unscoped data path.
	 *
	 * @param string $organisationUuid The `afnemer` organisation UUID to export.
	 *
	 * @return string The CSV document (header + one data row per gebruik).
	 *
	 * @throws Exception When OpenRegister or the voorzieningen configuration is unavailable.
	 *
	 * @spec openspec/changes/portfolio-rationalization-time/specs/portfolio-rationalization-time/spec.md#requirement-csv-export-of-the-portfolio-report
	 */
	public function buildCsv(string $organisationUuid): string {
		$this->relationCache = [];
		$built = $this->buildRows(organisationUuid: $organisationUuid);

		$handle = fopen('php://temp', 'r+');
		fputcsv(
			$handle,
			[
				'organisation',
				'module',
				'timeClassification',
				'timeRationale',
				'timeReviewDate',
				'lifecyclePhase',
				'eolStatus',
				'hostingModel',
				'annualisedCost',
				'oneOffCost',
			]
		);

		foreach ($built['rows'] as $row) {
			fputcsv(
				$handle,
				[
					$organisationUuid,
					$row['moduleName'],
					$row['timeClassification'] ?? '',
					$row['timeRationale'] ?? '',
					$row['timeReviewDate'] ?? '',
					$row['lifecyclePhase'],
					$this->derivation->eolStatusLabel(row: $row),
					implode('|', $row['hostingModel']),
					(string)$row['annualisedCost'],
					(string)$row['oneOffCost'],
				]
			);
		}

		rewind($handle);
		$csv = stream_get_contents($handle);
		fclose($handle);

		if ($csv === false) {
			return '';
		}

		return $csv;
	}//end buildCsv()

	/**
	 * Fetch the organisation's gebruiken (bounded), resolve their
	 * moduleVersie/module/contract context, and build one report row per
	 * gebruik.
	 *
	 * @param string $organisationUuid The `afnemer` organisation UUID.
	 *
	 * @return array{rows: array<int,array<string,mixed>>, total: int, ceiling: int, truncated: bool}
	 *
	 * @throws Exception When OpenRegister or configuration resolution fails.
	 *
	 * @spec openspec/changes/portfolio-rationalization-time/specs/portfolio-rationalization-time/spec.md#requirement-report-aggregation-queries-are-bounded
	 */
	private function buildRows(string $organisationUuid): array {
		$objectService = $this->getObjectService();
		$cfg = $this->getRegisterConfig();
		$ceiling = $this->getPageSizeCeiling();
		$now = new DateTimeImmutable();

		$gebruikQuery = [
			'@self' => [
				'register' => $cfg['registerId'],
				'schema' => $cfg['gebruikSchema'],
			],
			'consumer' => $organisationUuid,
			'_limit' => $ceiling,
		];

		$gebruikResult = $objectService->searchObjectsPaginated(query: $gebruikQuery, _rbac: false, _multitenancy: false);
		$usages = $this->derivation->normalizeResults(results: $gebruikResult['results'] ?? []);
		$total = (int)($gebruikResult['total'] ?? count($usages));
		$truncated = $total > count($usages);

		$gebruikIds = [];
		foreach ($usages as $usage) {
			$id = $this->derivation->resolveRelationId(value: $usage['id'] ?? ($usage['@self']['id'] ?? null));
			if ($id !== '') {
				$gebruikIds[] = $id;
			}
		}

		$contractsByGebruik = $this->fetchContractsForGebruiken(gebruikIds: $gebruikIds, cfg: $cfg, ceiling: $ceiling);

		$rows = [];
		foreach ($usages as $usage) {
			$rows[] = $this->buildRow(usage: $usage, cfg: $cfg, contractsByGebruik: $contractsByGebruik, now: $now);
		}

		return [
			'rows' => $rows,
			'total' => $total,
			'ceiling' => $ceiling,
			'truncated' => $truncated,
		];
	}//end buildRows()

	/**
	 * Build one report row for a single gebruik.
	 *
	 * @param array<string,mixed> $usage The gebruik data bag.
	 * @param array<string,int> $cfg Resolved register/schema ids.
	 * @param array<string,array<int,array>> $contractsByGebruik Contract rows indexed by gebruik uuid.
	 * @param DateTimeImmutable $now Reference moment.
	 *
	 * @return array<string,mixed> The row.
	 *
	 * @spec openspec/changes/portfolio-rationalization-time/specs/portfolio-rationalization-time/spec.md#requirement-portfolio-rationalization-report-aggregates-per-organisation
	 */
	private function buildRow(array $usage, array $cfg, array $contractsByGebruik, DateTimeImmutable $now): array {
		$gebruikId = $this->derivation->resolveRelationId(value: $usage['id'] ?? ($usage['@self']['id'] ?? null));
		$moduleId = $this->derivation->resolveRelationId(value: $usage['module'] ?? null);
		$versionId = $this->derivation->resolveRelationId(value: $usage['moduleVersion'] ?? null);

		$module = null;
		if ($moduleId !== '') {
			$module = $this->fetchRelation(id: $moduleId, schemaId: $cfg['moduleSchema']);
		}

		$moduleVersion = null;
		if ($versionId !== '') {
			$moduleVersion = $this->fetchRelation(id: $versionId, schemaId: $cfg['moduleVersieSchema']);
		}

		$eol = $this->derivation->deriveEolState(moduleVersion: $moduleVersion, now: $now);
		$eolApproaching = $this->derivation->isEolApproaching(moduleVersion: $moduleVersion, now: $now);

		$cost = $this->sumContractCost(contracts: $contractsByGebruik[$gebruikId] ?? []);

		$hostingModel = $usage['cloudDienstverleningsmodel'] ?? [];
		if (is_array($hostingModel) === false) {
			// A scalar (non-array) stored value — normalise to a one-element
			// list so the row/aggregate code always iterates an array.
			$hostingModel = [$hostingModel];
		}

		$classification = $this->normalizeClassification(value: $usage['timeClassification'] ?? null);

		return [
			'uuid' => $gebruikId,
			'moduleId' => $moduleId,
			'moduleName' => $module['name'] ?? $module['title'] ?? $moduleId,
			'timeClassification' => $classification,
			'quadrant' => $classification ?? self::QUADRANT_UNCLASSIFIED,
			'timeRationale' => $usage['timeRationale'] ?? null,
			'timeReviewDate' => $usage['timeReviewDate'] ?? null,
			'lifecyclePhase' => $this->derivation->deriveLifecyclePhase(usage: $usage, now: $now),
			'eol' => $eol,
			'eolApproaching' => $eolApproaching,
			'hostingModel' => array_values(array_filter($hostingModel, static fn ($v) => is_string($v) === true && $v !== '')),
			'annualisedCost' => $cost['annual'],
			'oneOffCost' => $cost['oneOff'],
		];
	}//end buildRow()

	/**
	 * Sum annualised + one-off cost across a set of contracts.
	 *
	 * @param array<int,array<string,mixed>> $contracts Contract data bags.
	 *
	 * @return array{annual: float, oneOff: float}
	 */
	private function sumContractCost(array $contracts): array {
		$cost = ['annual' => 0.0, 'oneOff' => 0.0];
		foreach ($contracts as $contract) {
			$c = $this->derivation->annualisedCost(contract: $contract);
			$cost['annual'] = $cost['annual'] + $c['annual'];
			$cost['oneOff'] = $cost['oneOff'] + $c['oneOff'];
		}

		return $cost;
	}//end sumContractCost()

	/**
	 * Normalize a raw `timeClassification` value to one of the four
	 * canonical quadrant values, or null when absent/invalid.
	 *
	 * @param mixed $value The raw stored value.
	 *
	 * @return string|null The normalized classification, or null.
	 */
	private function normalizeClassification(mixed $value): ?string {
		$valid = [
			self::QUADRANT_TOLERATE,
			self::QUADRANT_INVEST,
			self::QUADRANT_MIGRATE,
			self::QUADRANT_ELIMINATE,
		];

		if (is_string($value) === true && in_array($value, $valid, true) === true) {
			return $value;
		}

		return null;
	}//end normalizeClassification()

	/**
	 * Aggregate report rows into per-quadrant figures (count, EOL exposure,
	 * cloud-transition share, summed annualised/one-off cost).
	 *
	 * @param array<int,array<string,mixed>> $rows Report rows from {@see buildRows()}.
	 *
	 * @return array<string,array<string,mixed>> Quadrant key → aggregate figures.
	 *
	 * @spec openspec/changes/portfolio-rationalization-time/specs/portfolio-rationalization-time/spec.md#requirement-portfolio-rationalization-report-aggregates-per-organisation
	 */
	private function aggregateQuadrants(array $rows): array {
		$quadrants = [];
		foreach (self::QUADRANTS as $quadrant) {
			$quadrants[$quadrant] = [
				'count' => 0,
				'eolExposedCount' => 0,
				'cloudTransition' => [],
				'annualisedCost' => 0.0,
				'oneOffCost' => 0.0,
			];
		}

		foreach ($rows as $row) {
			$quadrants[$row['quadrant']] = $this->mergeRowIntoQuadrant(
				quadrant: $quadrants[$row['quadrant']] ?? $quadrants[self::QUADRANT_UNCLASSIFIED],
				row: $row
			);
		}

		return $quadrants;
	}//end aggregateQuadrants()

	/**
	 * Fold one report row's figures into a quadrant's running aggregate.
	 *
	 * @param array<string,mixed> $quadrant The quadrant's current aggregate.
	 * @param array<string,mixed> $row The row to fold in.
	 *
	 * @return array<string,mixed> The updated aggregate.
	 */
	private function mergeRowIntoQuadrant(array $quadrant, array $row): array {
		$quadrant['count']++;
		if ($row['eol']['passed'] === true || $row['eolApproaching'] === true) {
			$quadrant['eolExposedCount']++;
		}

		foreach ($row['hostingModel'] as $model) {
			$quadrant['cloudTransition'][$model] = ($quadrant['cloudTransition'][$model] ?? 0) + 1;
		}

		$quadrant['annualisedCost'] += $row['annualisedCost'];
		$quadrant['oneOffCost'] += $row['oneOffCost'];

		return $quadrant;
	}//end mergeRowIntoQuadrant()

	/**
	 * Fetch contracts linked to a bounded set of gebruik ids, indexed by
	 * gebruik uuid.
	 *
	 * @param array<int,string> $gebruikIds The gebruik uuids to fetch contracts for.
	 * @param array<string,mixed> $cfg Resolved register/schema ids.
	 * @param int $ceiling The gebruik page-size ceiling (basis for the contract bound).
	 *
	 * @return array<string,array<int,array<string,mixed>>> Gebruik uuid → contract rows.
	 *
	 * @spec openspec/changes/portfolio-rationalization-time/specs/portfolio-rationalization-time/spec.md#requirement-report-aggregation-queries-are-bounded
	 */
	private function fetchContractsForGebruiken(array $gebruikIds, array $cfg, int $ceiling): array {
		if ($gebruikIds === [] || $cfg['contractSchema'] === null) {
			return [];
		}

		$objectService = $this->getObjectService();
		$query = [
			'@self' => [
				'register' => $cfg['registerId'],
				'schema' => $cfg['contractSchema'],
			],
			'usage' => $gebruikIds,
			'_limit' => min($ceiling * self::CONTRACT_LIMIT_MULTIPLIER, 5000),
		];

		try {
			$result = $objectService->searchObjectsPaginated(query: $query, _rbac: false, _multitenancy: false);
		} catch (\Throwable $e) {
			$this->logger->warning('PortfolioReportService: contract fetch failed', ['error' => $e->getMessage()]);
			return [];
		}

		$indexed = [];
		foreach ($this->derivation->normalizeResults(results: $result['results'] ?? []) as $contract) {
			$gebruikId = $this->derivation->resolveRelationId(value: $contract['usage'] ?? null);
			if ($gebruikId === '') {
				continue;
			}

			$indexed[$gebruikId][] = $contract;
		}

		return $indexed;
	}//end fetchContractsForGebruiken()

	/**
	 * Fetch and cache a single related object (moduleVersie or module) by id.
	 *
	 * @param string $id The related object's uuid.
	 * @param int|null $schemaId The related object's schema id (for a scoped lookup).
	 *
	 * @return array<string,mixed>|null The related object's data bag, or null when unresolved.
	 */
	private function fetchRelation(string $id, ?int $schemaId): ?array {
		$cacheKey = $schemaId . ':' . $id;
		if (array_key_exists($cacheKey, $this->relationCache) === true) {
			return $this->relationCache[$cacheKey];
		}

		$result = null;
		try {
			$objectService = $this->getObjectService();
			$entity = $objectService->find(id: $id, _rbac: false, _multitenancy: false);
			if ($entity !== null) {
				$result = $entity->getObject();
			}
		} catch (\Throwable $e) {
			$this->logger->debug('PortfolioReportService: relation fetch failed', ['id' => $id, 'error' => $e->getMessage()]);
		}

		$this->relationCache[$cacheKey] = $result;

		return $result;
	}//end fetchRelation()

	/**
	 * Resolve the voorzieningen register + schema ids this service needs.
	 *
	 * @return array{registerId: int|null, gebruikSchema: int|null, contractSchema: int|null, moduleSchema: int|null, moduleVersieSchema: int|null}
	 *
	 * @throws Exception When the voorzieningen configuration is unavailable.
	 */
	private function getRegisterConfig(): array {
		$config = $this->settingsService->getVoorzieningenConfig();

		$registerId = $config['register'] ?? null;
		if (empty($registerId) === true) {
			throw new Exception('Voorzieningen configuration not found. Please configure the schemas in the admin panel.');
		}

		$toInt = static function ($value): ?int {
			if (empty($value) === true) {
				return null;
			}

			return (int)$value;
		};

		return [
			'registerId' => (int)$registerId,
			'gebruikSchema' => $toInt($config['gebruik_schema'] ?? null),
			'contractSchema' => $toInt($config['contract_schema'] ?? null),
			'moduleSchema' => $toInt($config['module_schema'] ?? null),
			'moduleVersieSchema' => $toInt($config['moduleVersie_schema'] ?? null),
		];
	}//end getRegisterConfig()

	/**
	 * Resolve the configured report page-size ceiling, falling back to
	 * {@see self::DEFAULT_PAGE_SIZE_CEILING} when unset or invalid.
	 *
	 * @return int The page-size ceiling (always >= 1).
	 *
	 * @spec openspec/changes/portfolio-rationalization-time/specs/portfolio-rationalization-time/spec.md#requirement-report-aggregation-queries-are-bounded
	 */
	private function getPageSizeCeiling(): int {
		try {
			$value = $this->config->getValueInt(Application::APP_ID, 'portfolio_report_page_size_ceiling', self::DEFAULT_PAGE_SIZE_CEILING);
		} catch (\Throwable $e) {
			$value = self::DEFAULT_PAGE_SIZE_CEILING;
		}

		if ($value > 0) {
			return $value;
		}

		return self::DEFAULT_PAGE_SIZE_CEILING;
	}//end getPageSizeCeiling()

	/**
	 * Lazily resolve the OpenRegister ObjectService.
	 *
	 * @return ObjectServiceInterface The service.
	 *
	 * @throws Exception When OpenRegister is not installed or unresolvable.
	 */
	private function getObjectService(): ObjectServiceInterface {
		if (in_array('openregister', $this->appManager->getInstalledApps(), true) === false) {
			throw new Exception('OpenRegister app is not installed');
		}

		try {
			return $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (\Throwable $e) {
			throw new Exception('Failed to get OpenRegister service: ' . $e->getMessage());
		}
	}//end getObjectService()
}//end class
