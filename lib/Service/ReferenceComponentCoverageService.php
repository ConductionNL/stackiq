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
		if (in_array('openregister', $this->appManager->getInstalledApps(), true) === false) {
			throw new Exception('OpenRegister app is not installed');
		}

		$config = $this->settingsService->getVoorzieningenConfig();
		$registerId = (int)($config['register'] ?? 0);
		$usageSchemaId = (int)($config['gebruik_schema'] ?? 0);
		$moduleSchemaId = (int)($config['module_schema'] ?? 0);
		if ($registerId === 0 || $usageSchemaId === 0) {
			throw new Exception('Voorzieningen configuration not found. Please configure the schemas in the admin panel.');
		}

		$usageResult = $this->search(query: [
			'@self' => ['register' => $registerId, 'schema' => $usageSchemaId],
			'consumer' => $organisationUuid,
			'_limit' => PortfolioReportService::DEFAULT_PAGE_SIZE_CEILING,
		]);
		$usages = $this->relations->normalizeResults(results: $usageResult['results'] ?? []);
		$total = (int)($usageResult['total'] ?? count($usages));

		$modules = $this->modules(usages: $usages, registerId: $registerId, schemaId: $moduleSchemaId);

		$coverage = $this->derivation->derive(
			components: $this->components(),
			usages: $usages,
			moduleNames: array_map(static fn (array $module): string => (string)($module['name'] ?? ''), $modules),
			moduleComponents: array_map(
				fn (array $module): array => array_map(
					fn (mixed $value): string => $this->relations->resolveRelationId(value: $value),
					(array)($module['referenceComponents'] ?? [])
				),
				$modules
			)
		);

		return [
			'organisation' => $organisationUuid,
			'generatedAt' => (new DateTimeImmutable())->format(DATE_ATOM),
			'summary' => $coverage['summary'],
			'components' => $coverage['components'],
			'total' => $total,
			'truncated' => $total > count($usages),
		];
	}//end build()

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
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) csvSafeCell() is the portfolio report's pure cell guard, shared so both CSVs escape alike.
	 */
	public function buildCsv(string $organisationUuid): string {
		$coverage = $this->build(organisationUuid: $organisationUuid);

		$handle = fopen('php://temp', 'r+');
		fputcsv($handle, ['group', 'referenceComponent', 'state', 'fillable', 'applications']);
		foreach ($coverage['components'] as $row) {
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
			fputcsv($handle, array_map(static fn (string $cell): string => PortfolioReportService::csvSafeCell(value: $cell), $cells));
		}

		rewind($handle);
		$csv = (string)stream_get_contents($handle);
		fclose($handle);

		return $csv;
	}//end buildCsv()

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
