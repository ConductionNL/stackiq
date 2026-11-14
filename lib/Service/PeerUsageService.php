<?php

/**
 * What comparable organisations use per reference component.
 *
 * @category Service
 * @package  OCA\Stackiq\Service
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * @version GIT: <git_id>
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

use DateTimeImmutable;
use Exception;
use OCA\Stackiq\Service\Settings\RegisterConfigReaderInterface;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Counts, per reference component, the applications that comparable
 * organisations use: the same organisation type and the same inhabitants
 * band, or the same type when the organisation has no inhabitants value.
 *
 * The counts are computed over every usage with the service identity, so the
 * caller's own read rules do not shrink them, and the caller must already be
 * allowed to read the organisation's report (OrganisationReportRequest). The
 * answer never carries another organisation's id or name, and a count below
 * {@see self::MIN_COUNT} is answered as null with `fewerThan`.
 *
 * @spec openspec/changes/gemma-plate-views-peers-and-definitions/specs/gemma-plate/spec.md#requirement-the-plate-shows-what-comparable-organisations-use-req-gpl-002
 */
class PeerUsageService {

	/**
	 * The smallest count shown; anything below reads "fewer than 3".
	 */
	public const MIN_COUNT = 3;

	/**
	 * The lower bounds of the inhabitants bands; a band runs to the next bound.
	 */
	public const BANDS = [0, 30000, 60000, 100000, 250000];

	/**
	 * Constructor.
	 *
	 * @param RegisterConfigReaderInterface $settingsService The voorzieningen register and schemas.
	 * @param IAppManager                   $appManager      Tells whether OpenRegister is installed.
	 * @param ContainerInterface            $container       Resolves OpenRegister's ObjectService.
	 * @param LoggerInterface               $logger          Logs failed lookups.
	 * @param PortfolioReportDerivation     $relations       Normalises results and relation ids.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly RegisterConfigReaderInterface $settingsService,
		private readonly IAppManager $appManager,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly PortfolioReportDerivation $relations,
	) {
	}//end __construct()

	/**
	 * The band an inhabitants value falls in, or null without a value.
	 *
	 * @param mixed $inhabitants The inhabitants value.
	 *
	 * @return array{min: int, max: int|null}|null
	 *
	 * @spec openspec/changes/gemma-plate-views-peers-and-definitions/specs/gemma-plate/spec.md#requirement-the-plate-shows-what-comparable-organisations-use-req-gpl-002
	 */
	public static function band(mixed $inhabitants): ?array {
		if (is_int($inhabitants) === false && (is_string($inhabitants) === false || ctype_digit($inhabitants) === false)) {
			return null;
		}

		$value = (int)$inhabitants;
		$band = null;
		foreach (self::BANDS as $index => $min) {
			if ($value >= $min) {
				$band = ['min' => $min, 'max' => (self::BANDS[$index + 1] ?? null)];
			}
		}

		return $band;
	}//end band()

	/**
	 * The peer applications per reference component for one organisation.
	 *
	 * @param string $organisationUuid The organisation the caller may read.
	 *
	 * @return array<string, mixed> `{organisation, generatedAt, comparable, components, truncated}`.
	 *
	 * @throws Exception When OpenRegister or the voorzieningen configuration is missing.
	 *
	 * @spec openspec/changes/gemma-plate-views-peers-and-definitions/specs/gemma-plate/spec.md#requirement-the-plate-shows-what-comparable-organisations-use-req-gpl-002
	 */
	public function build(string $organisationUuid): array {
		$source = $this->source();
		$own = $this->lookup(query: [
			'@self' => ['register' => $source['register'], 'schema' => $source['organisation']],
			'id' => $organisationUuid,
			'_limit' => 1,
		]);
		$type = (string)($own[0]['type'] ?? '');
		$band = self::band(inhabitants: ($own[0]['inhabitants'] ?? null));

		$peerIds = $this->comparableOrganisations(source: $source, ownId: $organisationUuid, type: $type, band: $band);
		$usages = $this->peerUsages(source: $source, peerIds: $peerIds);
		$modules = $this->modules(source: $source, usages: $usages['usages']);

		return [
			'organisation' => $organisationUuid,
			'generatedAt' => (new DateTimeImmutable())->format(DATE_ATOM),
			'comparable' => ['type' => $type, 'band' => $band, 'organisations' => count($peerIds)],
			'components' => $this->components(usages: $usages['usages'], modules: $modules),
			'truncated' => $usages['truncated'],
		];
	}//end build()

	/**
	 * The register and the usage, module and organisation schemas.
	 *
	 * @return array{register: int, usage: int, module: int, organisation: int}
	 *
	 * @throws Exception When OpenRegister or the configuration is missing.
	 */
	private function source(): array {
		if (in_array('openregister', $this->appManager->getInstalledApps(), true) === false) {
			throw new Exception('OpenRegister app is not installed');
		}

		$config = $this->settingsService->getVoorzieningenConfig();
		$source = [
			'register' => (int)($config['register'] ?? 0),
			'usage' => (int)($config['gebruik_schema'] ?? 0),
			'module' => (int)($config['module_schema'] ?? 0),
			'organisation' => (int)($config['organisatie_schema'] ?? 0),
		];
		if (in_array(0, $source, true) === true) {
			throw new Exception('Voorzieningen configuration not found. Please configure the schemas in the admin panel.');
		}

		return $source;
	}//end source()

	/**
	 * The ids of the comparable organisations, never the organisation itself.
	 *
	 * Without a type nothing is comparable. With a band only organisations in
	 * the same band count; one without an inhabitants value is then left out.
	 *
	 * @param array<string, int>              $source The register and schemas.
	 * @param string                          $ownId  The organisation.
	 * @param string                          $type   Its type.
	 * @param array{min: int, max: int|null}|null $band Its band.
	 *
	 * @return array<int, string>
	 */
	private function comparableOrganisations(array $source, string $ownId, string $type, ?array $band): array {
		if ($type === '') {
			return [];
		}

		$ids = [];
		$organisations = $this->lookup(query: [
			'@self' => ['register' => $source['register'], 'schema' => $source['organisation']],
			'type' => $type,
			'_limit' => PortfolioReportService::DEFAULT_PAGE_SIZE_CEILING,
		]);
		foreach ($organisations as $organisation) {
			$id = $this->relations->resolveRelationId(value: ($organisation['id'] ?? ($organisation['@self']['id'] ?? null)));
			if ($id === '' || $id === $ownId || (string)($organisation['type'] ?? '') !== $type) {
				continue;
			}

			if ($band !== null && self::band(inhabitants: ($organisation['inhabitants'] ?? null)) !== $band) {
				continue;
			}

			$ids[] = $id;
		}

		return array_values(array_unique($ids));
	}//end comparableOrganisations()

	/**
	 * The usages of the comparable organisations, bounded like the coverage report.
	 *
	 * @param array<string, int> $source  The register and schemas.
	 * @param array<int, string> $peerIds The comparable organisations.
	 *
	 * @return array{usages: array<int, array<string, mixed>>, truncated: bool}
	 */
	private function peerUsages(array $source, array $peerIds): array {
		if ($peerIds === []) {
			return ['usages' => [], 'truncated' => false];
		}

		$result = $this->search(query: [
			'@self' => ['register' => $source['register'], 'schema' => $source['usage']],
			'consumer' => $peerIds,
			'_limit' => PortfolioReportService::DEFAULT_PAGE_SIZE_CEILING,
		]);
		$usages = $this->relations->normalizeResults(results: ($result['results'] ?? []));
		$total = (int)($result['total'] ?? count($usages));

		return ['usages' => $usages, 'truncated' => $total > count($usages)];
	}//end peerUsages()

	/**
	 * The modules the usages point at, by id.
	 *
	 * @param array<string, int>                 $source The register and schemas.
	 * @param array<int, array<string, mixed>>   $usages The usages.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function modules(array $source, array $usages): array {
		$ids = array_values(array_unique(array_filter(array_map(
			fn (array $usage): string => $this->relations->resolveRelationId(value: ($usage['module'] ?? null)),
			$usages
		))));
		if ($ids === []) {
			return [];
		}

		$byId = [];
		$modules = $this->lookup(query: [
			'@self' => ['register' => $source['register'], 'schema' => $source['module']],
			'id' => $ids,
			'_limit' => count($ids),
		]);
		foreach ($modules as $module) {
			$byId[$this->relations->resolveRelationId(value: ($module['id'] ?? ($module['@self']['id'] ?? null)))] = $module;
		}

		return $byId;
	}//end modules()

	/**
	 * Per reference component, the applications with the number of distinct organisations using them.
	 *
	 * @param array<int, array<string, mixed>>    $usages  The comparable organisations' usages.
	 * @param array<string, array<string, mixed>> $modules The modules by id.
	 *
	 * @return array<int, array{referenceComponent: string, applications: array<int, array<string, mixed>>}>
	 */
	private function components(array $usages, array $modules): array {
		$users = [];
		foreach ($usages as $usage) {
			$moduleId = $this->relations->resolveRelationId(value: ($usage['module'] ?? null));
			$consumer = $this->relations->resolveRelationId(value: ($usage['consumer'] ?? null));
			if ($moduleId === '' || $consumer === '' || isset($modules[$moduleId]) === false) {
				continue;
			}

			foreach ((array)($modules[$moduleId]['referenceComponents'] ?? []) as $component) {
				$componentId = $this->relations->resolveRelationId(value: $component);
				if ($componentId !== '') {
					$users[$componentId][$moduleId][$consumer] = true;
				}
			}
		}

		$rows = [];
		foreach ($users as $componentId => $byModule) {
			$rows[] = ['referenceComponent' => (string)$componentId, 'applications' => $this->applications(byModule: $byModule, modules: $modules)];
		}

		return $rows;
	}//end components()

	/**
	 * The applications of one component, most used first, counts below the minimum withheld.
	 *
	 * @param array<string, array<string, true>>  $byModule The organisations per module.
	 * @param array<string, array<string, mixed>> $modules  The modules by id.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function applications(array $byModule, array $modules): array {
		uasort($byModule, static fn (array $a, array $b): int => (count($b) <=> count($a)));
		$applications = [];
		foreach ($byModule as $moduleId => $organisations) {
			$application = ['moduleId' => (string)$moduleId, 'moduleName' => (string)($modules[$moduleId]['name'] ?? ''), 'count' => count($organisations)];
			if ($application['count'] < self::MIN_COUNT) {
				$application['count'] = null;
				$application['fewerThan'] = self::MIN_COUNT;
			}

			$applications[] = $application;
		}

		return $applications;
	}//end applications()

	/**
	 * Search with the service identity.
	 *
	 * @param array<string, mixed> $query The query.
	 *
	 * @return array<string, mixed>
	 */
	private function search(array $query): array {
		return $this->container->get('OCA\OpenRegister\Service\ObjectService')
			->searchObjectsPaginated(query: $query, _rbac: false, _multitenancy: false);
	}//end search()

	/**
	 * Search and normalise, answering nothing on failure.
	 *
	 * @param array<string, mixed> $query The query.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function lookup(array $query): array {
		try {
			return $this->relations->normalizeResults(results: ($this->search(query: $query)['results'] ?? []));
		} catch (Throwable $e) {
			$this->logger->warning('PeerUsageService: lookup failed', ['error' => $e->getMessage()]);
			return [];
		}
	}//end lookup()
}//end class
