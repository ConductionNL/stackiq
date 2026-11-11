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
use OCA\Stackiq\AppInfo\AppIdentity;
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
	 * The CSV export's header line, in column order.
	 */
	private const CSV_HEADER = [
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
		'businessValue',
		'technicalFit',
		'riskScore',
		'scoredOn',
		'suggestedTimeClassification',
		'timeMismatch',
	];

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
	 * A cell a spreadsheet opens as text, never as a formula.
	 *
	 * Text that starts with `=`, `+`, `-`, `@`, a tab or a carriage return is
	 * run as a formula by Excel and LibreOffice when the CSV is opened. Such a
	 * cell gets a leading apostrophe, so it shows as the text it is. A plain
	 * number (a negative cost) is left as it is. Module names and rationales
	 * can come from an imported third-party file, so every cell goes through
	 * this.
	 *
	 * @param string $value The cell.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/portfolio-rationalization-time/specs/portfolio-rationalization-time/spec.md#requirement-csv-export-of-the-portfolio-report
	 */
	public static function csvSafeCell(string $value): string {
		if ($value === '' || is_numeric($value) === true || in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) === false) {
			return $value;
		}

		return "'" . $value;
	}//end csvSafeCell()

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

		$lines = [self::CSV_HEADER];
		foreach ($built['rows'] as $row) {
			$lines[] = $this->csvLine(row: $row, organisationUuid: $organisationUuid);
		}

		return $this->renderCsv(lines: $lines);
	}//end buildCsv()

	/**
	 * One CSV data line for a report row, every cell made spreadsheet-safe.
	 *
	 * @param array<string,mixed> $row              The report row.
	 * @param string              $organisationUuid The exported organisation UUID.
	 *
	 * @return array<int,string> The cells.
	 */
	private function csvLine(array $row, string $organisationUuid): array {
		return array_map(
			static fn ($cell): string => self::csvSafeCell(value: (string)$cell),
			array_merge(
				$this->csvClassificationCells(row: $row, organisationUuid: $organisationUuid),
				$this->csvFigureCells(row: $row)
			)
		);
	}//end csvLine()

	/**
	 * The organisation, module, classification, lifecycle and hosting cells of a row.
	 *
	 * @param array<string,mixed> $row              The report row.
	 * @param string              $organisationUuid The exported organisation UUID.
	 *
	 * @return array<int,mixed> The raw cells.
	 */
	private function csvClassificationCells(array $row, string $organisationUuid): array {
		return [
			$organisationUuid,
			$row['moduleName'],
			$row['timeClassification'] ?? '',
			$row['timeRationale'] ?? '',
			$row['timeReviewDate'] ?? '',
			$row['lifecyclePhase'],
			$this->derivation->eolStatusLabel(row: $row),
			implode('|', $row['hostingModel']),
		];
	}//end csvClassificationCells()

	/**
	 * The cost, score and suggestion cells of a row.
	 *
	 * @param array<string,mixed> $row The report row.
	 *
	 * @return array<int,mixed> The raw cells.
	 */
	private function csvFigureCells(array $row): array {
		return [
			(string)$row['annualisedCost'],
			(string)$row['oneOffCost'],
			(string)($row['businessValue'] ?? ''),
			(string)($row['technicalFit'] ?? ''),
			(string)($row['riskScore'] ?? ''),
			$row['scoredOn'] ?? '',
			$row['suggestedTimeClassification'] ?? '',
			$this->derivation->mismatchLabel(row: $row),
		];
	}//end csvFigureCells()

	/**
	 * Write CSV lines to a document.
	 *
	 * @param array<int,array<int,string>> $lines The lines, header first.
	 *
	 * @return string The CSV document, or '' when the buffer cannot be read.
	 */
	private function renderCsv(array $lines): string {
		$handle = fopen('php://temp', 'r+');
		foreach ($lines as $line) {
			fputcsv($handle, $line);
		}

		rewind($handle);
		$csv = stream_get_contents($handle);
		fclose($handle);

		if ($csv === false) {
			return '';
		}

		return $csv;
	}//end renderCsv()

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
		$run = $this->startRun(organisationUuid: $organisationUuid);

		$gebruikResult = $run->objectService->searchObjectsPaginated(query: $this->gebruikQuery(run: $run), _rbac: false, _multitenancy: false);
		$usages = $this->derivation->normalizeResults(results: $gebruikResult['results'] ?? []);
		$total = (int)($gebruikResult['total'] ?? count($usages));

		$run->contractsByGebruik = $this->fetchContractsForGebruiken(gebruikIds: $this->collectGebruikIds(usages: $usages), run: $run);

		return [
			'rows' => $this->buildRowsFor(usages: $usages, run: $run),
			'total' => $total,
			'ceiling' => $run->ceiling,
			'truncated' => $total > count($usages),
		];
	}//end buildRows()

	/**
	 * Resolve what one report build needs, in the order it was always resolved:
	 * the ObjectService, the register configuration, the ceiling, the moment.
	 *
	 * @param string $organisationUuid The `afnemer` organisation UUID.
	 *
	 * @return PortfolioReportRun The run.
	 *
	 * @throws Exception When OpenRegister or configuration resolution fails.
	 */
	private function startRun(string $organisationUuid): PortfolioReportRun {
		$run = new PortfolioReportRun();
		$run->organisationUuid = $organisationUuid;
		$run->objectService    = $this->getObjectService();
		$run->cfg     = $this->getRegisterConfig();
		$run->ceiling = $this->getPageSizeCeiling();
		$run->now     = new DateTimeImmutable();

		return $run;
	}//end startRun()

	/**
	 * The bounded gebruik query for the run's organisation.
	 *
	 * @param PortfolioReportRun $run The report run.
	 *
	 * @return array<string,mixed> The query.
	 */
	private function gebruikQuery(PortfolioReportRun $run): array {
		return [
			'@self' => [
				'register' => $run->cfg['registerId'],
				'schema' => $run->cfg['gebruikSchema'],
			],
			'consumer' => $run->organisationUuid,
			'_limit' => $run->ceiling,
		];
	}//end gebruikQuery()

	/**
	 * The resolvable uuids of a set of gebruiken, in order.
	 *
	 * @param array<int,array<string,mixed>> $usages The gebruik data bags.
	 *
	 * @return array<int,string> The uuids.
	 */
	private function collectGebruikIds(array $usages): array {
		$gebruikIds = [];
		foreach ($usages as $usage) {
			$id = $this->gebruikIdOf(usage: $usage);
			if ($id !== '') {
				$gebruikIds[] = $id;
			}
		}

		return $gebruikIds;
	}//end collectGebruikIds()

	/**
	 * The uuid of one gebruik, from its `id` or its `@self.id`.
	 *
	 * @param array<string,mixed> $usage The gebruik data bag.
	 *
	 * @return string The uuid, or '' when unresolved.
	 */
	private function gebruikIdOf(array $usage): string {
		return $this->derivation->resolveRelationId(value: $usage['id'] ?? ($usage['@self']['id'] ?? null));
	}//end gebruikIdOf()

	/**
	 * One report row per gebruik, in order.
	 *
	 * @param array<int,array<string,mixed>> $usages The gebruik data bags.
	 * @param PortfolioReportRun             $run    The report run.
	 *
	 * @return array<int,array<string,mixed>> The rows.
	 */
	private function buildRowsFor(array $usages, PortfolioReportRun $run): array {
		$rows = [];
		foreach ($usages as $usage) {
			$rows[] = $this->buildRow(usage: $usage, run: $run);
		}

		return $rows;
	}//end buildRowsFor()

	/**
	 * Build one report row for a single gebruik.
	 *
	 * The module is fetched before the moduleVersie, as it always was.
	 *
	 * @param array<string,mixed> $usage The gebruik data bag.
	 * @param PortfolioReportRun  $run   The report run.
	 *
	 * @return array<string,mixed> The row.
	 *
	 * @spec openspec/changes/portfolio-rationalization-time/specs/portfolio-rationalization-time/spec.md#requirement-portfolio-rationalization-report-aggregates-per-organisation
	 */
	private function buildRow(array $usage, PortfolioReportRun $run): array {
		$gebruikId = $this->gebruikIdOf(usage: $usage);
		$module = $this->moduleFields(usage: $usage, run: $run);
		$versionId = $this->derivation->resolveRelationId(value: $usage['moduleVersion'] ?? null);
		$moduleVersion = $this->fetchRelation(id: $versionId, schemaId: $run->cfg['moduleVersieSchema']);
		$classification = $this->normalizeClassification(value: $usage['timeClassification'] ?? null);

		return $this->scoresFor(usage: $usage, classification: $classification)
			+ ['uuid' => $gebruikId]
			+ $module
			+ $this->classificationFields(usage: $usage, classification: $classification)
			+ ['lifecyclePhase' => $this->derivation->deriveLifecyclePhase(usage: $usage, now: $run->now)]
			+ $this->eolFields(moduleVersion: $moduleVersion, now: $run->now)
			+ ['hostingModel' => $this->hostingModelOf(usage: $usage)]
			+ $this->costFields(contracts: $run->contractsByGebruik[$gebruikId] ?? []);
	}//end buildRow()

	/**
	 * The module id and display name of a gebruik, fetching the module.
	 *
	 * @param array<string,mixed> $usage The gebruik data bag.
	 * @param PortfolioReportRun  $run   The report run.
	 *
	 * @return array{moduleId: string, moduleName: mixed}
	 */
	private function moduleFields(array $usage, PortfolioReportRun $run): array {
		$moduleId = $this->derivation->resolveRelationId(value: $usage['module'] ?? null);
		$module = $this->fetchRelation(id: $moduleId, schemaId: $run->cfg['moduleSchema']);

		return [
			'moduleId' => $moduleId,
			'moduleName' => $module['name'] ?? $module['title'] ?? $moduleId,
		];
	}//end moduleFields()

	/**
	 * The value-assessment scores and suggestion of a gebruik.
	 *
	 * @param array<string,mixed> $usage          The gebruik data bag.
	 * @param string|null         $classification The normalized recorded classification.
	 *
	 * @return array<string,mixed> The score fields.
	 */
	private function scoresFor(array $usage, ?string $classification): array {
		return $this->derivation->valueAssessment(
			usage: $usage,
			classification: $classification,
			storedSuggestion: $this->normalizeClassification(value: $usage['suggestedTimeClassification'] ?? null)
		);
	}//end scoresFor()

	/**
	 * The TIME classification fields of a gebruik.
	 *
	 * @param array<string,mixed> $usage          The gebruik data bag.
	 * @param string|null         $classification The normalized recorded classification.
	 *
	 * @return array<string,mixed> The classification fields.
	 */
	private function classificationFields(array $usage, ?string $classification): array {
		return [
			'timeClassification' => $classification,
			'quadrant' => $classification ?? self::QUADRANT_UNCLASSIFIED,
			'timeRationale' => $usage['timeRationale'] ?? null,
			'timeReviewDate' => $usage['timeReviewDate'] ?? null,
		];
	}//end classificationFields()

	/**
	 * The end-of-support state of a moduleVersie.
	 *
	 * @param array<string,mixed>|null $moduleVersion The moduleVersie data bag, or null.
	 * @param DateTimeImmutable        $now           Reference moment.
	 *
	 * @return array{eol: array<string,mixed>, eolApproaching: bool}
	 */
	private function eolFields(?array $moduleVersion, DateTimeImmutable $now): array {
		return [
			'eol' => $this->derivation->deriveEolState(moduleVersion: $moduleVersion, now: $now),
			'eolApproaching' => $this->derivation->isEolApproaching(moduleVersion: $moduleVersion, now: $now),
		];
	}//end eolFields()

	/**
	 * The hosting models of a gebruik, as a list of non-empty strings.
	 *
	 * @param array<string,mixed> $usage The gebruik data bag.
	 *
	 * @return array<int,string> The hosting models.
	 */
	private function hostingModelOf(array $usage): array {
		$hostingModel = $usage['cloudDienstverleningsmodel'] ?? [];
		if (is_array($hostingModel) === false) {
			// A scalar (non-array) stored value — normalise to a one-element
			// list so the row/aggregate code always iterates an array.
			$hostingModel = [$hostingModel];
		}

		return array_values(array_filter($hostingModel, static fn ($v) => is_string($v) === true && $v !== ''));
	}//end hostingModelOf()

	/**
	 * The summed annualised and one-off cost of a gebruik's contracts.
	 *
	 * @param array<int,array<string,mixed>> $contracts Contract data bags.
	 *
	 * @return array{annualisedCost: float, oneOffCost: float}
	 */
	private function costFields(array $contracts): array {
		$cost = $this->sumContractCost(contracts: $contracts);

		return [
			'annualisedCost' => $cost['annual'],
			'oneOffCost' => $cost['oneOff'],
		];
	}//end costFields()

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
		$quadrants = $this->emptyQuadrants();
		foreach ($rows as $row) {
			$quadrants[$row['quadrant']] = $this->mergeRowIntoQuadrant(
				quadrant: $quadrants[$row['quadrant']] ?? $quadrants[self::QUADRANT_UNCLASSIFIED],
				row: $row
			);
		}

		return $quadrants;
	}//end aggregateQuadrants()

	/**
	 * Every quadrant in rendered order, with zeroed figures.
	 *
	 * @return array<string,array<string,mixed>> Quadrant key → empty aggregate.
	 */
	private function emptyQuadrants(): array {
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

		return $quadrants;
	}//end emptyQuadrants()

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
	 * @param array<int,string>  $gebruikIds The gebruik uuids to fetch contracts for.
	 * @param PortfolioReportRun $run        The report run (register ids, ceiling).
	 *
	 * @return array<string,array<int,array<string,mixed>>> Gebruik uuid → contract rows.
	 *
	 * @spec openspec/changes/portfolio-rationalization-time/specs/portfolio-rationalization-time/spec.md#requirement-report-aggregation-queries-are-bounded
	 */
	private function fetchContractsForGebruiken(array $gebruikIds, PortfolioReportRun $run): array {
		if ($gebruikIds === [] || $run->cfg['contractSchema'] === null) {
			return [];
		}

		$objectService = $this->getObjectService();
		$query = $this->contractQuery(gebruikIds: $gebruikIds, run: $run);

		try {
			$result = $objectService->searchObjectsPaginated(query: $query, _rbac: false, _multitenancy: false);
		} catch (\Throwable $e) {
			$this->logger->warning('PortfolioReportService: contract fetch failed', ['error' => $e->getMessage()]);
			return [];
		}

		return $this->indexContractsByGebruik(contracts: $this->derivation->normalizeResults(results: $result['results'] ?? []));
	}//end fetchContractsForGebruiken()

	/**
	 * The bounded contract query for a set of gebruik ids.
	 *
	 * @param array<int,string>  $gebruikIds The gebruik uuids.
	 * @param PortfolioReportRun $run        The report run.
	 *
	 * @return array<string,mixed> The query.
	 */
	private function contractQuery(array $gebruikIds, PortfolioReportRun $run): array {
		return [
			'@self' => [
				'register' => $run->cfg['registerId'],
				'schema' => $run->cfg['contractSchema'],
			],
			'usage' => $gebruikIds,
			'_limit' => min($run->ceiling * self::CONTRACT_LIMIT_MULTIPLIER, 5000),
		];
	}//end contractQuery()

	/**
	 * Index contracts by the gebruik they belong to, dropping unlinked ones.
	 *
	 * @param array<int,array<string,mixed>> $contracts The contract data bags.
	 *
	 * @return array<string,array<int,array<string,mixed>>> Gebruik uuid → contract rows.
	 */
	private function indexContractsByGebruik(array $contracts): array {
		$indexed = [];
		foreach ($contracts as $contract) {
			$gebruikId = $this->derivation->resolveRelationId(value: $contract['usage'] ?? null);
			if ($gebruikId === '') {
				continue;
			}

			$indexed[$gebruikId][] = $contract;
		}

		return $indexed;
	}//end indexContractsByGebruik()

	/**
	 * Fetch and cache a single related object (moduleVersie or module) by id.
	 *
	 * @param string   $id       The related object's uuid ('' resolves to null without a lookup).
	 * @param int|null $schemaId The related object's schema id (for a scoped lookup).
	 *
	 * @return array<string,mixed>|null The related object's data bag, or null when unresolved.
	 */
	private function fetchRelation(string $id, ?int $schemaId): ?array {
		if ($id === '') {
			return null;
		}

		$cacheKey = $schemaId . ':' . $id;
		if (array_key_exists($cacheKey, $this->relationCache) === false) {
			$this->relationCache[$cacheKey] = $this->loadRelation(id: $id);
		}

		return $this->relationCache[$cacheKey];
	}//end fetchRelation()

	/**
	 * Load a related object's data bag, or null when it cannot be found.
	 *
	 * @param string $id The related object's uuid.
	 *
	 * @return array<string,mixed>|null The data bag, or null.
	 */
	private function loadRelation(string $id): ?array {
		try {
			$objectService = $this->getObjectService();
			$entity = $objectService->find(id: $id, _rbac: false, _multitenancy: false);
			if ($entity !== null) {
				return $entity->getObject();
			}
		} catch (\Throwable $e) {
			$this->logger->debug('PortfolioReportService: relation fetch failed', ['id' => $id, 'error' => $e->getMessage()]);
		}

		return null;
	}//end loadRelation()

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

		return [
			'registerId' => (int)$registerId,
			'gebruikSchema' => self::schemaIdOrNull(value: $config['gebruik_schema'] ?? null),
			'contractSchema' => self::schemaIdOrNull(value: $config['contract_schema'] ?? null),
			'moduleSchema' => self::schemaIdOrNull(value: $config['module_schema'] ?? null),
			'moduleVersieSchema' => self::schemaIdOrNull(value: $config['moduleVersie_schema'] ?? null),
		];
	}//end getRegisterConfig()

	/**
	 * A configured schema id as an int, or null when unset/empty.
	 *
	 * @param mixed $value The configured value.
	 *
	 * @return int|null The schema id, or null.
	 */
	private static function schemaIdOrNull(mixed $value): ?int {
		if (empty($value) === true) {
			return null;
		}

		return (int)$value;
	}//end schemaIdOrNull()

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
			$value = $this->config->getValueInt(AppIdentity::APP_ID, 'portfolio_report_page_size_ceiling', self::DEFAULT_PAGE_SIZE_CEILING);
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
