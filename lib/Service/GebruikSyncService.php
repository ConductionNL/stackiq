<?php

/**
 * Gebruik Sync Service.
 *
 * Service for synchronizing and processing Gebruik (Usage) objects.
 *
 * This service handles the processing of gebruik schema objects, including:
 * - Processing gebruiktVoorReferentiecomponenten to populate amefElements
 * - Auto-updating status based on date fields
 *
 * @category  Service
 * @package   OCA\SoftwareCatalog\Service
 * @author    Ruben van der Linde <ruben@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl
 * @version   GIT: <git_id>
 * @link      https://github.com/conduction/nextcloud-software-catalog
 *
 * @spec openspec/specs/method-decomposition/spec.md
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Service;

use DateTime;
use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Service for synchronizing and processing Gebruik (Usage) objects.
 *
 * @category Service
 * @package  OCA\SoftwareCatalog\Service
 * @author   Ruben van der Linde <ruben@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl
 * @version  GIT: <git_id>
 * @link     https://github.com/conduction/nextcloud-software-catalog
 *
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 * @SuppressWarnings(PHPMD.LongVariable)
 * @SuppressWarnings(PHPMD.ShortVariable)
 * @SuppressWarnings(PHPMD.MissingImport)
 * @SuppressWarnings(PHPMD.UnusedLocalVariable)
 * @SuppressWarnings(PHPMD.UnusedPrivateMethod)
 * @SuppressWarnings(PHPMD.Superglobals)
 * @SuppressWarnings(PHPMD.CamelCaseVariableName)
 * @SuppressWarnings(PHPMD.CamelCaseParameterName)
 */
class GebruikSyncService {

	/**
	 * Logger for debugging and error reporting.
	 *
	 * @var LoggerInterface
	 */
	private LoggerInterface $logger;

	/**
	 * Service for retrieving configuration settings.
	 *
	 * @var SettingsService
	 */
	private SettingsService $settingsService;

	/**
	 * Constructor for GebruikSyncService.
	 *
	 * @param LoggerInterface        $logger          Logger for debugging and error reporting
	 * @param SettingsService        $settingsService Service for retrieving configuration settings
	 * @param ObjectServiceInterface $objectService   OpenRegister's published data-access contract (ADR-084)
	 */
	public function __construct(
		LoggerInterface $logger,
		SettingsService $settingsService,
		private readonly ObjectServiceInterface $objectService,
	) {
		$this->logger = $logger;
		$this->settingsService = $settingsService;
	}//end __construct()

	/**
	 * Process a specific gebruik object.
	 *
	 * This method handles both AMEF elements processing and status auto-update.
	 *
	 * @param ObjectEntity $gebruikObject The gebruik object to process
	 *
	 * @return array Processing statistics.
	 *
	 * @spec openspec/specs/method-decomposition/spec.md
	 */
	public function processSpecificGebruik(ObjectEntity $gebruikObject): array {
		$startTime = microtime(true);
		$stats = [
			'startTime' => date('Y-m-d H:i:s'),
			'gebruikId' => $gebruikObject->getUuid(),
			'amefElementsProcessed' => 0,
			'statusUpdated' => false,
			'errors' => [],
			'duration' => 0,
		];

		try {
			$gebruikData = $gebruikObject->getObject();
			$gebruikUuid = $gebruikObject->getUuid();

			$this->logger->debug(
				'Processing gebruik object',
				[
					'app' => 'softwarecatalog',
					'gebruikId' => $gebruikUuid,
					'currentStatus' => $gebruikData['status'] ?? 'Unknown',
				]
			);

			// Step 1: Process gebruiktVoorReferentiecomponenten for AMEF elements.
			$amefStats = $this->processAmefElements(gebruikObject: $gebruikObject);
			$stats['amefElementsProcessed'] = $amefStats['amefElementsProcessed'];
			$stats['errors'] = array_merge($stats['errors'], $amefStats['errors']);

			// Step 2: Auto-update status based on dates.
			$statusStats = $this->updateStatusBasedOnDates(gebruikObject: $gebruikObject);
			$stats['statusUpdated'] = $statusStats['statusUpdated'];
			$stats['errors'] = array_merge($stats['errors'], $statusStats['errors']);

			$stats['endTime'] = date('Y-m-d H:i:s');
			$stats['duration'] = round(microtime(true) - $startTime, 3);

			$this->logger->critical(
				'GEBRUIK PROCESSING COMPLETED',
				[
					'app' => 'softwarecatalog',
					'gebruikId' => $gebruikUuid,
					'stats' => $stats,
					'processingTime' => $stats['duration'] . 's',
				]
			);

			return $stats;
		} catch (Exception $e) {
			$stats['errors'][] = $e->getMessage();
			$stats['duration'] = round(microtime(true) - $startTime, 3);

			$this->logger->error(
				'GEBRUIK PROCESSING ERROR',
				[
					'app' => 'softwarecatalog',
					'gebruikId' => $gebruikObject->getUuid(),
					'exception' => $e->getMessage(),
					'file' => $e->getFile(),
					'line' => $e->getLine(),
					'trace' => $e->getTraceAsString(),
				]
			);

			return $stats;
		}//end try
	}//end processSpecificGebruik()

	/**
	 * Process AMEF elements from gebruiktVoorReferentiecomponenten.
	 *
	 * Searches for AMEF elements based on IDs in gebruiktVoorReferentiecomponenten
	 * and adds their slugs to the amefElements array.
	 *
	 * @param ObjectEntity $gebruikObject The gebruik object to process
	 *
	 * @return array Processing statistics.
	 */
	private function processAmefElements(ObjectEntity $gebruikObject): array {
		$stats = [
			'amefElementsProcessed' => 0,
			'errors' => [],
		];

		try {
			$gebruikData = $gebruikObject->getObject();
			$gebruikUuid = $gebruikObject->getUuid();

			// Get the referentiecomponenten IDs.
			$referenceComponents = $gebruikData['usedForReferenceComponents'] ?? [];

			if (empty($referenceComponents) === true) {
				$this->logger->info(
					'No referentiecomponenten found for gebruik object',
					[
						'gebruikId' => $gebruikUuid,
					]
				);
				return $stats;
			}

			$this->logger->debug(
				'Processing referentiecomponenten',
				[
					'app' => 'softwarecatalog',
					'gebruikId' => $gebruikUuid,
					'referentieComponentenCount' => count($referenceComponents),
				]
			);

			// Extract IDs from referentiecomponenten.
			$referenceIds = [];
			foreach ($referenceComponents as $component) {
				if (isset($component['id']) === true) {
					$referenceIds[] = $component['id'];
				}
			}

			if (empty($referenceIds) === true) {
				$this->logger->warning(
					'No valid IDs found in referentiecomponenten',
					[
						'gebruikId' => $gebruikUuid,
					]
				);
				return $stats;
			}

			// Get AMEF register configuration.
			$voorzieningenConfig = $this->settingsService->getVoorzieningenConfig();
			$amefRegister = $voorzieningenConfig['amef_register'] ?? '';
			$elementSchema = $voorzieningenConfig['element_schema'] ?? '';

			if (empty($amefRegister) === true || empty($elementSchema) === true) {
				$stats['errors'][] = 'AMEF register or element schema not configured';
				$this->logger->error(
					'AMEF configuration missing',
					[
						'app' => 'softwarecatalog',
						'amefRegister' => $amefRegister,
						'elementSchema' => $elementSchema,
					]
				);
				return $stats;
			}

			// Search for AMEF elements.
			$amefElements = $this->searchAmefElementsByIds(
				ids: $referenceIds,
				register: $amefRegister,
				schema: $elementSchema
			);

			// Extract slugs from found AMEF elements.
			$amefSlugs = [];
			foreach ($amefElements as $amefElement) {
				$amefData = $amefElement->getObject();
				if (isset($amefData['slug']) === true) {
					$amefSlugs[] = $amefData['slug'];
					$stats['amefElementsProcessed']++;
				}
			}

			// Update the gebruik object with AMEF slugs.
			if (empty($amefSlugs) === true) {
				$this->logger->info(
					'No AMEF elements with slugs found',
					[
						'gebruikId' => $gebruikUuid,
					]
				);
			}//end if

			if (empty($amefSlugs) === false) {
				$gebruikData['amefElements'] = array_unique($amefSlugs);
				$this->updateGebruikObject(
					gebruikObject: $gebruikObject,
					updatedData: $gebruikData
				);

				$this->logger->critical(
					'AMEF ELEMENTS UPDATED',
					[
						'app' => 'softwarecatalog',
						'gebruikId' => $gebruikUuid,
						'amefSlugs' => $amefSlugs,
						'amefElementsCount' => count($amefSlugs),
					]
				);
			}

			return $stats;
		} catch (Exception $e) {
			$stats['errors'][] = 'AMEF processing error: ' . $e->getMessage();
			$this->logger->error(
				'AMEF PROCESSING ERROR',
				[
					'app' => 'softwarecatalog',
					'gebruikId' => $gebruikObject->getUuid(),
					'exception' => $e->getMessage(),
				]
			);

			return $stats;
		}//end try
	}//end processAmefElements()

	/**
	 * Search for AMEF elements by IDs.
	 *
	 * Uses searchObjects to find AMEF elements based on provided IDs.
	 * Since searchObjects may not support direct IDs array parameter,
	 * this method implements multiple individual searches.
	 *
	 * @param array $ids Array of IDs to search for
	 * @param string $register AMEF register ID
	 * @param string $schema Element schema ID
	 *
	 * @return array Array of found ObjectEntity objects.
	 */
	private function searchAmefElementsByIds(array $ids, string $register, string $schema): array {
		$foundElements = [];

		foreach ($ids as $id) {
			try {
				// Try to search by ID.
				$query = [
					'@self' => [
						'register' => (int)$register,
						'schema' => (int)$schema,
					],
					'id' => $id,
					// Looking up a single id — bound to a small safe ceiling.
					'_limit' => 5,
				];

				$elements = $this->objectService->searchObjects($query);
				$foundElements = array_merge($foundElements, $elements);
			} catch (Exception $e) {
				$this->logger->warning(
					'Failed to search for AMEF element',
					[
						'app' => 'softwarecatalog',
						'id' => $id,
						'error' => $e->getMessage(),
					]
				);
			}//end try
		}//end foreach

		$this->logger->info(
			'AMEF elements search completed',
			[
				'app' => 'softwarecatalog',
				'searchedIds' => $ids,
				'foundElementsCount' => count($foundElements),
			]
		);

		return $foundElements;
	}//end searchAmefElementsByIds()

	/**
	 * Update status based on date fields.
	 *
	 * Looks at all status date fields and updates the status to the one
	 * with the highest date that is not in the future.
	 *
	 * @param ObjectEntity $gebruikObject The gebruik object to process
	 *
	 * @return array Processing statistics.
	 */
	private function updateStatusBasedOnDates(ObjectEntity $gebruikObject): array {
		$stats = [
			'statusUpdated' => false,
			'errors' => [],
		];

		try {
			$gebruikData = $gebruikObject->getObject();
			$gebruikUuid = $gebruikObject->getUuid();
			$currentStatus = $gebruikData['status'] ?? '';
			$statusDates = $this->extractStatusDateMap(gebruikData: $gebruikData);

			$this->logger->info(
				'CHECKING STATUS DATES',
				[
					'app' => 'softwarecatalog',
					'gebruikId' => $gebruikUuid,
					'currentStatus' => $currentStatus,
					'statusDates' => $statusDates,
				]
			);

			[$targetStatus, $targetDate] = $this->resolveLatestEligibleStatus(statusDates: $statusDates, gebruikUuid: $gebruikUuid);

			if ($targetStatus !== null && $targetStatus !== $currentStatus) {
				$gebruikData['status'] = $targetStatus;
				$this->updateGebruikObject(
					gebruikObject: $gebruikObject,
					updatedData: $gebruikData
				);
				$stats['statusUpdated'] = true;

				$this->logger->critical(
					'STATUS AUTO-UPDATED',
					[
						'app' => 'softwarecatalog',
						'gebruikId' => $gebruikUuid,
						'oldStatus' => $currentStatus,
						'newStatus' => $targetStatus,
						'basedOnDate' => $targetDate?->format('Y-m-d'),
					]
				);
			}//end if

			return $stats;
		} catch (Exception $e) {
			$stats['errors'][] = 'Status update error: ' . $e->getMessage();
			$this->logger->error(
				'STATUS UPDATE ERROR',
				[
					'app' => 'softwarecatalog',
					'gebruikId' => $gebruikObject->getUuid(),
					'exception' => $e->getMessage(),
				]
			);

			return $stats;
		}//end try
	}//end updateStatusBasedOnDates()

	/**
	 * Build the status → start-date map from a gebruik payload.
	 *
	 * @param array $gebruikData The decoded gebruik object data
	 *
	 * @return array<string,string|null> The status-to-date-string map
	 */
	private function extractStatusDateMap(array $gebruikData): array {
		return [
			'Acquisition' => $gebruikData['startDateAcquisition'] ?? null,
			'Planned' => $gebruikData['startDatePlanned'] ?? null,
			'In production' => $gebruikData['startDateInProduction'] ?? null,
			'To be phased out' => $gebruikData['startDateOutPhasing'] ?? null,
			'Phased out' => $gebruikData['startDateOutPhased'] ?? null,
		];

	}//end extractStatusDateMap()

	/**
	 * Pick the status whose start-date is the latest non-future one.
	 *
	 * Logs (and skips) entries with an unparseable date string.
	 *
	 * @param array<string,string|null> $statusDates The status-to-date map
	 * @param string $gebruikUuid The gebruik UUID (for logging)
	 *
	 * @return array{0: string|null, 1: \DateTime|null} Tuple of [targetStatus, targetDate]
	 */
	private function resolveLatestEligibleStatus(array $statusDates, string $gebruikUuid): array {
		$now = new DateTime();
		$targetStatus = null;
		$targetDate = null;

		foreach ($statusDates as $status => $dateString) {
			if (empty($dateString) === true) {
				continue;
			}

			try {
				$date = new DateTime($dateString);
			} catch (Exception $e) {
				$this->logger->warning(
					'Invalid date format',
					[
						'app' => 'softwarecatalog',
						'gebruikId' => $gebruikUuid,
						'status' => $status,
						'dateString' => $dateString,
						'error' => $e->getMessage(),
					]
				);
				continue;
			}

			if ($date > $now) {
				continue;
			}

			if ($targetDate === null || $date > $targetDate) {
				$targetDate = $date;
				$targetStatus = $status;
			}
		}//end foreach

		return [$targetStatus, $targetDate];
	}//end resolveLatestEligibleStatus()

	/**
	 * Update a gebruik object in OpenRegister.
	 *
	 * @param ObjectEntity $gebruikObject The object to update
	 * @param array $updatedData The updated data
	 *
	 * @return void
	 *
	 * @throws Exception If the update fails.
	 */
	private function updateGebruikObject(ObjectEntity $gebruikObject, array $updatedData): void {
		try {
			// Get voorzieningenConfig to find the correct register and schema.
			$voorzieningenConfig = $this->settingsService->getVoorzieningenConfig();
			$register = $voorzieningenConfig['register'] ?? '';
			$gebruikSchema = $voorzieningenConfig['gebruik_schema'] ?? '';

			if (empty($register) === true || empty($gebruikSchema) === true) {
				throw new Exception('Register or gebruik schema not configured');
			}

			// Update the object. The published contract names the identifier
			// `uuid`, not `id` (ADR-084) — and it really is the UUID that is
			// passed here, so this was a wrong argument NAME, not a wrong value.
			$this->objectService->saveObject(
				object: $updatedData,
				register: (int)$register,
				schema: (int)$gebruikSchema,
				uuid: $gebruikObject->getUuid()
			);

			$this->logger->info(
				'Gebruik object updated successfully',
				[
					'app' => 'softwarecatalog',
					'gebruikId' => $gebruikObject->getUuid(),
				]
			);
		} catch (Exception $e) {
			$this->logger->error(
				'Failed to update gebruik object',
				[
					'app' => 'softwarecatalog',
					'gebruikId' => $gebruikObject->getUuid(),
					'error' => $e->getMessage(),
				]
			);
			throw $e;
		}//end try
	}//end updateGebruikObject()
}//end class
