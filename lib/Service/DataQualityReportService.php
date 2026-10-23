<?php

/**
 * The Data quality report of one organisation's applications in use.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/landscape-completeness-score/specs/catalogue-data-quality/spec.md#requirement-req-cdq-003-a-data-quality-page-shows-how-complete-and-current-an-organisation-s-register-is
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
 * Reads the usage schema's declared quality rules and the organisation's usages, and evaluates them.
 *
 * Bounded like the portfolio report: one query with an explicit `_limit`, the
 * response says when it was cut off. The caller has already checked the user
 * may read the organisation.
 *
 * @spec openspec/changes/landscape-completeness-score/specs/catalogue-data-quality/spec.md#requirement-req-cdq-003-a-data-quality-page-shows-how-complete-and-current-an-organisation-s-register-is
 */
class DataQualityReportService {
	/**
	 * How many of the least complete entries the report names.
	 */
	public const LOWEST = 20;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Resolves the register and schema ids.
	 * @param IAppManager $appManager Checks OpenRegister is installed.
	 * @param ContainerInterface $container Lazy OpenRegister lookups.
	 * @param LoggerInterface $logger Logger.
	 * @param DataQualityDerivation $derivation Evaluates the rules.
	 * @param PortfolioReportDerivation $relations Normalises results and relation ids.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly IAppManager $appManager,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly DataQualityDerivation $derivation,
		private readonly PortfolioReportDerivation $relations,
	) {
	}//end __construct()

	/**
	 * Build the report for one organisation.
	 *
	 * @param string $organisationUuid The consuming organisation.
	 *
	 * @return array<string,mixed> summary, rules, the least complete entries and the bounds.
	 *
	 * @throws Exception When OpenRegister or the register configuration is unavailable.
	 *
	 * @spec openspec/changes/landscape-completeness-score/specs/catalogue-data-quality/spec.md#requirement-req-cdq-003-a-data-quality-page-shows-how-complete-and-current-an-organisation-s-register-is
	 */
	public function build(string $organisationUuid): array {
		if (in_array('openregister', $this->appManager->getInstalledApps(), true) === false) {
			throw new Exception('OpenRegister app is not installed');
		}

		$config = $this->settingsService->getVoorzieningenConfig();
		$registerId = (int)($config['register'] ?? 0);
		$usageSchemaId = (int)($config['gebruik_schema'] ?? 0);
		if ($registerId === 0 || $usageSchemaId === 0) {
			throw new Exception('Voorzieningen configuration not found. Please configure the schemas in the admin panel.');
		}

		$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
		$result = $objectService->searchObjectsPaginated(
			query: [
				'@self' => ['register' => $registerId, 'schema' => $usageSchemaId],
				'consumer' => $organisationUuid,
				'_limit' => PortfolioReportService::DEFAULT_PAGE_SIZE_CEILING,
			],
			_rbac: false,
			_multitenancy: false
		);
		$usages = $this->relations->normalizeResults(results: $result['results'] ?? []);
		$total = (int)($result['total'] ?? count($usages));

		[$rules, $titles] = $this->rules(schemaId: $usageSchemaId);
		$evaluation = $this->derivation->evaluate(
			rules: $rules,
			objects: $this->withIds(usages: $usages),
			now: new DateTimeImmutable()
		);

		$lowest = array_slice($evaluation['entries'], 0, self::LOWEST);
		$names = $this->moduleNames(usages: $usages, usageIds: array_column($lowest, 'id'));
		foreach ($lowest as $index => $entry) {
			$lowest[$index]['name'] = $names[$entry['id']] ?? '';
			$lowest[$index]['missing'] = array_map(static fn (string $field): string => $titles[$field] ?? $field, $entry['missing']);
		}

		$ruleRows = array_map(
			static function (array $rule) use ($titles): array {
				$rule['title'] = $titles[$rule['field']] ?? $rule['field'];
				return $rule;
			},
			$evaluation['rules']
		);

		return [
			'organisation' => $organisationUuid,
			'generatedAt' => (new DateTimeImmutable())->format(DATE_ATOM),
			'summary' => $evaluation['summary'],
			'rules' => $ruleRows,
			'lowest' => $lowest,
			'total' => $total,
			'truncated' => $total > count($usages),
		];
	}//end build()

	/**
	 * The usage schema's declared quality rules and the titles of its fields.
	 *
	 * @param int $schemaId The usage schema id.
	 *
	 * @return array{0: array<int,array<string,mixed>>, 1: array<string,string>} The rules (empty when none
	 *                                                                           are declared) and titles by field.
	 */
	private function rules(int $schemaId): array {
		try {
			$schema = $this->container->get('OCA\OpenRegister\Db\SchemaMapper')->find($schemaId, _rbac: false, _multitenancy: false);
			$configuration = (array)($schema->getConfiguration() ?? []);
			$properties = (array)($schema->getProperties() ?? []);
		} catch (\Throwable $e) {
			$this->logger->warning('DataQualityReportService: usage schema not readable', ['error' => $e->getMessage()]);
			return [[], []];
		}

		$titles = [];
		foreach ($properties as $field => $property) {
			$titles[(string)$field] = (string)($property['title'] ?? $field);
		}

		return [array_values((array)($configuration['x-openregister-quality']['rules'] ?? [])), $titles];
	}//end rules()

	/**
	 * Give each usage the `id` the derivation reports it by.
	 *
	 * @param array<int,array<string,mixed>> $usages The usages.
	 *
	 * @return array<int,array<string,mixed>> The usages with `id` set.
	 */
	private function withIds(array $usages): array {
		return array_map(
			fn (array $usage): array => ['id' => $this->relations->resolveRelationId(value: $usage['id'] ?? ($usage['@self']['id'] ?? null))] + $usage,
			$usages
		);
	}//end withIds()

	/**
	 * The application name of the named usages, by usage id.
	 *
	 * @param array<int,array<string,mixed>> $usages The usages.
	 * @param array<int,string> $usageIds The usages to name.
	 *
	 * @return array<string,string> Names by usage id.
	 */
	private function moduleNames(array $usages, array $usageIds): array {
		$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
		$cache = [];
		$names = [];
		foreach ($usages as $usage) {
			$usageId = $this->relations->resolveRelationId(value: $usage['id'] ?? ($usage['@self']['id'] ?? null));
			$moduleId = $this->relations->resolveRelationId(value: $usage['module'] ?? null);
			if ($moduleId === '' || in_array($usageId, $usageIds, true) === false) {
				continue;
			}

			if (array_key_exists($moduleId, $cache) === false) {
				$cache[$moduleId] = '';
				try {
					$cache[$moduleId] = (string)($objectService->find(id: $moduleId, _rbac: false, _multitenancy: false)?->getObject()['name'] ?? '');
				} catch (\Throwable $e) {
					$this->logger->debug('DataQualityReportService: module not readable', ['id' => $moduleId]);
				}
			}

			$names[$usageId] = $cache[$moduleId];
		}//end foreach

		return $names;
	}//end moduleNames()
}//end class
