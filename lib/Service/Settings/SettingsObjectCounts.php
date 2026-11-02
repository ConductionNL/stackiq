<?php

/**
 * Object counts and statistics for the settings page.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service\Settings
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/settings-service/spec.md
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service\Settings;

use OCA\Stackiq\Service\SettingsService;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Counts the objects of the voorzieningen and AMEF registers and builds the
 * statistics the settings page shows. Every count falls back to zero when
 * OpenRegister or ArchiMate cannot answer.
 *
 * @category Service
 * @package  OCA\Stackiq\Service\Settings
 *
 * @spec openspec/specs/settings-service/spec.md
 */
final class SettingsObjectCounts {

	/**
	 * Voorzieningen config key of each counted schema, with its count key.
	 *
	 * @var array<string, string>
	 */
	private const VOORZIENINGEN_COUNT_KEYS = [
		'organisatie_schema' => 'totalOrganisatieObjects',
		'contactpersoon_schema' => 'totalContactpersoonObjects',
		'voorziening_schema' => 'totalVoorzieningObjects',
		'voorziening_aanbod_schema' => 'totalVoorzieningAanbodObjects',
		'voorziening_versie_schema' => 'totalVoorzieningVersieObjects',
		'kwetsbaarheid_schema' => 'totalKwetsbaarheidObjects',
		'contract_schema' => 'totalContractObjects',
		'standaard_schema' => 'totalStandaardObjects',
		'review_schema' => 'totalReviewObjects',
		'koppeling_schema' => 'totalKoppelingObjects',
		'beoordeeling_schema' => 'totalBeoordeelingObjects',
		'module_schema' => 'totalVoorzieningModuleObjects',
		'verklaring_schema' => 'totalVerklaringObjects',
		'koppeling_gebruik_schema' => 'totalKoppelingGebruikObjects',
		'compliancy_schema' => 'totalCompliancyObjects',
		'module_gebruik_schema' => 'totalModuleGebruikObjects',
		'module_versie_schema' => 'totalModuleVersieObjects',
		'sector_schema' => 'totalSectorObjects',
		'gebruik_schema' => 'totalGebruikObjects',
	];

	/**
	 * AMEF count keys with the ArchiMateService getter that lists their objects.
	 *
	 * @var array<string, string>
	 */
	private const AMEF_COUNT_KEYS = [
		'totalElementObjects' => 'getElementObjects',
		'totalOrganizationObjects' => 'getOrganizationObjects',
		'totalViewObjects' => 'getViewObjects',
		'totalRelationshipsObjects' => 'getRelationshipObjects',
		'totalModelObjects' => 'getModelObjects',
	];

	/**
	 * Build the counter.
	 *
	 * @param SettingsService    $settings  The settings, for the object service and the register configs.
	 * @param ContainerInterface $container The container, for the ArchiMateService.
	 * @param LoggerInterface    $logger    The logger.
	 */
	public function __construct(
		private readonly SettingsService $settings,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Object counts per voorzieningen schema, zero for every schema that cannot be counted.
	 *
	 * @return array<string, int>
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function voorzieningenCounts(): array {
		try {
			$objectService = $this->settings->getObjectService();
			if ($objectService === null) {
				return self::zeroVoorzieningenCounts();
			}

			$config = $this->settings->getVoorzieningenConfig();
			$counts = self::zeroVoorzieningenCounts();
			foreach (self::VOORZIENINGEN_COUNT_KEYS as $configKey => $countKey) {
				$counts[$countKey] = $this->countSchema(
					objectService: $objectService,
					target: ['register' => ($config['register'] ?? null), 'schema' => ($config[$configKey] ?? null), 'configKey' => $configKey]
				);
			}

			$this->logger->debug('SettingsService: Retrieved Voorzieningen object counts', $counts);

			return $counts;
		} catch (\Exception $e) {
			$this->logger->error(
				'SettingsService: Failed to get Voorzieningen object counts',
				[
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				]
			);

			return self::zeroVoorzieningenCounts();
		}//end try

	}//end voorzieningenCounts()

	/**
	 * Object counts per AMEF type, zero for every type when ArchiMate cannot answer.
	 *
	 * @return array<string, int>
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function amefCounts(): array {
		try {
			// Get ArchiMateService from container to avoid circular dependency.
			$archiMateService = $this->container->get(\OCA\Stackiq\Service\ArchiMateService::class);

			$counts = [];
			foreach (self::AMEF_COUNT_KEYS as $countKey => $getter) {
				$counts[$countKey] = count($archiMateService->$getter());
			}

			$this->logger->debug(
				'SettingsService: Retrieved AMEF object counts',
				[
					'elementObjects' => $counts['totalElementObjects'],
					'organizationObjects' => $counts['totalOrganizationObjects'],
					'viewObjects' => $counts['totalViewObjects'],
					'relationshipObjects' => $counts['totalRelationshipsObjects'],
					'modelObjects' => $counts['totalModelObjects'],
				]
			);

			return $counts;
		} catch (\Exception $e) {
			$this->logger->error(
				'SettingsService: Failed to get AMEF object counts',
				[
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				]
			);

			return self::zeroAmefCounts();
		}//end try

	}//end amefCounts()

	/**
	 * Statistics for both registers: their config, their object counts and
	 * whether they are configured.
	 *
	 * @return array
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function statistics(): array {
		try {
			$statistics = [
				'voorzieningen' => [],
				'amef' => [],
				'timestamp' => time(),
			];

			$statistics['voorzieningen'] = $this->voorzieningenStatistics();
			$statistics['amef'] = $this->amefStatistics();

			return $statistics;
		} catch (\Exception $e) {
			$this->logger->error(
				'SettingsService: Failed to get object counts statistics',
				[
					'exception' => $e->getMessage(),
				]
			);
			return [
				'voorzieningen' => self::failedVoorzieningenStatistics(message: $e->getMessage()),
				'amef' => self::failedAmefStatistics(message: $e->getMessage()),
				'timestamp' => time(),
				'error' => $e->getMessage(),
			];
		}//end try

	}//end statistics()

	/**
	 * The voorzieningen statistics.
	 *
	 * @return array
	 */
	private function voorzieningenStatistics(): array {
		try {
			$voorzieningenConfig = $this->settings->getVoorzieningenConfig();
			$voorzieningenCounts = $this->voorzieningenCounts();

			return [
				'config' => $voorzieningenConfig,
				'object_counts' => $voorzieningenCounts,
				'configured' => empty($voorzieningenConfig['register']) === false
					&& empty($voorzieningenConfig['organisatie_schema']) === false,
			];
		} catch (\Exception $e) {
			$this->logger->error('Failed to get Voorzieningen statistics', ['error' => $e->getMessage()]);
			return self::failedVoorzieningenStatistics(message: $e->getMessage());
		}

	}//end voorzieningenStatistics()

	/**
	 * The AMEF statistics.
	 *
	 * @return array
	 */
	private function amefStatistics(): array {
		try {
			$amefConfig = $this->settings->getAmefConfig();
			$amefCounts = $this->amefCounts();

			return [
				'config' => $amefConfig,
				'object_counts' => $amefCounts,
				'configured' => empty($amefConfig['register_id']) === false
					&& empty($amefConfig['elements_schema']) === false,
			];
		} catch (\Exception $e) {
			$this->logger->error('Failed to get AMEF statistics', ['error' => $e->getMessage()]);
			return self::failedAmefStatistics(message: $e->getMessage());
		}

	}//end amefStatistics()

	/**
	 * Count the objects of one voorzieningen schema.
	 *
	 * @param object $objectService The OpenRegister object service.
	 * @param array  $target        `register`, `schema` and the `configKey` it came from.
	 *
	 * @return int The count, zero when the schema is not set or counting fails.
	 */
	private function countSchema(object $objectService, array $target): int {
		if (empty($target['register']) === true || empty($target['schema']) === true) {
			return 0;
		}

		try {
			$query = [
				'@self' => [
					'register' => (int) $target['register'],
					'schema' => (int) $target['schema'],
				],
			];
			// A true SQL COUNT via OpenRegister's countSearchObjects(), not
			// count(searchObjects()), which would load or under-report a large register.
			return $objectService->countSearchObjects($query);
		} catch (\Exception $e) {
			$this->logger->warning("Failed to get {$target['configKey']} count", ['error' => $e->getMessage()]);
		}

		return 0;

	}//end countSchema()

	/**
	 * Zero counts for every voorzieningen schema.
	 *
	 * @return array<string, int>
	 */
	private static function zeroVoorzieningenCounts(): array {
		return array_fill_keys(array_values(self::VOORZIENINGEN_COUNT_KEYS), 0);

	}//end zeroVoorzieningenCounts()

	/**
	 * Zero counts for every AMEF type.
	 *
	 * @return array<string, int>
	 */
	private static function zeroAmefCounts(): array {
		return array_fill_keys(array_keys(self::AMEF_COUNT_KEYS), 0);

	}//end zeroAmefCounts()

	/**
	 * The voorzieningen statistics when they cannot be read.
	 *
	 * @param string $message The error message.
	 *
	 * @return array
	 */
	private static function failedVoorzieningenStatistics(string $message): array {
		return [
			'config' => [],
			'object_counts' => ['totalOrganisatieObjects' => 0, 'totalContactpersoonObjects' => 0],
			'configured' => false,
			'error' => $message,
		];

	}//end failedVoorzieningenStatistics()

	/**
	 * The AMEF statistics when they cannot be read.
	 *
	 * @param string $message The error message.
	 *
	 * @return array
	 */
	private static function failedAmefStatistics(string $message): array {
		return [
			'config' => [],
			'object_counts' => self::zeroAmefCounts(),
			'configured' => false,
			'error' => $message,
		];

	}//end failedAmefStatistics()

}//end class
