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
 * @package   OCA\Stackiq\Service
 * @author    Ruben van der Linde <ruben@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl
 * @version   GIT: <git_id>
 * @link      https://github.com/conduction/nextcloud-software-catalog
 *
 * @spec openspec/specs/method-decomposition/spec.md
 *
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

use DateTime;
use Exception;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use Psr\Log\LoggerInterface;

/**
 * Service for synchronizing and processing Gebruik (Usage) objects.
 *
 * @category Service
 * @package  OCA\Stackiq\Service
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
	 * @param LoggerInterface $logger Logger for debugging and error reporting
	 * @param SettingsService $settingsService Service for retrieving configuration settings
	 * @param ObjectServiceInterface $objectService OpenRegister object access (ADR-084 contract)
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
					'app' => 'stackiq',
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
					'app' => 'stackiq',
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
					'app' => 'stackiq',
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
			$this->linkAmefElements(gebruikObject: $gebruikObject, stats: $stats);
			return $stats;
		} catch (Exception $e) {
			$stats['errors'][] = 'AMEF processing error: ' . $e->getMessage();
			$this->logAmefProcessingError(gebruikObject: $gebruikObject, exception: $e);
			return $stats;
		}//end try
	}//end processAmefElements()

	/**
	 * Find the AMEF elements a gebruik object refers to and store their slugs on it.
	 *
	 * @param ObjectEntity $gebruikObject The gebruik object to process
	 * @param array        $stats         Processing statistics, updated in place
	 *
	 * @return void
	 *
	 * @throws Exception If the gebruik object cannot be updated.
	 */
	private function linkAmefElements(ObjectEntity $gebruikObject, array &$stats): void {
		$referenceIds = $this->amefReferenceIds(gebruikObject: $gebruikObject);
		if ($referenceIds === []) {
			return;
		}

		$amefElements = $this->searchReferencedAmefElements(referenceIds: $referenceIds, stats: $stats);
		if ($amefElements === null) {
			return;
		}

		$amefSlugs = $this->amefSlugsOf(amefElements: $amefElements, stats: $stats);
		$this->storeAmefSlugs(gebruikObject: $gebruikObject, amefSlugs: $amefSlugs);
	}//end linkAmefElements()

	/**
	 * Search the configured AMEF register for the referenced elements.
	 *
	 * @param array $referenceIds The referenced ids
	 * @param array $stats        Processing statistics, updated in place
	 *
	 * @return array|null The found AMEF elements, or null when the register is not configured.
	 */
	private function searchReferencedAmefElements(array $referenceIds, array &$stats): ?array {
		$target = $this->amefSearchTarget(stats: $stats);
		if ($target === null) {
			return null;
		}

		return $this->searchAmefElementsByIds(
			ids: $referenceIds,
			register: $target['register'],
			schema: $target['schema']
		);
	}//end searchReferencedAmefElements()

	/**
	 * Read the referentiecomponenten of a gebruik object.
	 *
	 * @param ObjectEntity $gebruikObject The gebruik object
	 *
	 * @return array The referenced ids; empty when there is nothing to look up.
	 */
	private function amefReferenceIds(ObjectEntity $gebruikObject): array {
		$gebruikData = $gebruikObject->getObject();
		$gebruikUuid = $gebruikObject->getUuid();
		$referenceComponents = $gebruikData['usedForReferenceComponents'] ?? [];

		if (empty($referenceComponents) === true) {
			$this->logger->info(
				'No referentiecomponenten found for gebruik object',
				[
					'gebruikId' => $gebruikUuid,
				]
			);
			return [];
		}

		return $this->referenceComponentIds(referenceComponents: $referenceComponents, gebruikUuid: $gebruikUuid);
	}//end amefReferenceIds()

	/**
	 * Extract the ids of a gebruik object's referentiecomponenten.
	 *
	 * @param mixed       $referenceComponents The referentiecomponenten (not empty)
	 * @param string|null $gebruikUuid         The gebruik object's UUID
	 *
	 * @return array The ids; empty when no component carries one.
	 */
	private function referenceComponentIds(mixed $referenceComponents, ?string $gebruikUuid): array {
		$this->logger->debug(
			'Processing referentiecomponenten',
			[
				'app' => 'stackiq',
				'gebruikId' => $gebruikUuid,
				'referentieComponentenCount' => count($referenceComponents),
			]
		);

		return $this->validReferenceIds(referenceComponents: $referenceComponents, gebruikUuid: $gebruikUuid);
	}//end referenceComponentIds()

	/**
	 * Collect the referentiecomponent ids, warning when there are none.
	 *
	 * @param mixed       $referenceComponents The referentiecomponenten (not empty)
	 * @param string|null $gebruikUuid         The gebruik object's UUID
	 *
	 * @return array The ids; empty when no component carries one.
	 */
	private function validReferenceIds(mixed $referenceComponents, ?string $gebruikUuid): array {
		$referenceIds = $this->idsOf(referenceComponents: $referenceComponents);
		if (empty($referenceIds) === true) {
			$this->logger->warning(
				'No valid IDs found in referentiecomponenten',
				[
					'gebruikId' => $gebruikUuid,
				]
			);
		}

		return $referenceIds;
	}//end validReferenceIds()

	/**
	 * Collect the id of every referentiecomponent that has one.
	 *
	 * @param mixed $referenceComponents The referentiecomponenten
	 *
	 * @return array The ids.
	 */
	private function idsOf(mixed $referenceComponents): array {
		$referenceIds = [];
		foreach ($referenceComponents as $component) {
			if (isset($component['id']) === true) {
				$referenceIds[] = $component['id'];
			}
		}

		return $referenceIds;
	}//end idsOf()

	/**
	 * Read the AMEF register and element schema to search in.
	 *
	 * @param array $stats Processing statistics, updated in place
	 *
	 * @return array|null The register and schema, or null when not configured.
	 */
	private function amefSearchTarget(array &$stats): ?array {
		$voorzieningenConfig = $this->settingsService->getVoorzieningenConfig();
		$target = [
			'register' => $voorzieningenConfig['amef_register'] ?? '',
			'schema' => $voorzieningenConfig['element_schema'] ?? '',
		];

		if (empty($target['register']) === true || empty($target['schema']) === true) {
			$this->reportMissingAmefConfig(stats: $stats, target: $target);
			return null;
		}

		return $target;
	}//end amefSearchTarget()

	/**
	 * Record and log a missing AMEF register or element schema.
	 *
	 * @param array $stats  Processing statistics, updated in place
	 * @param array $target The configured register and schema
	 *
	 * @return void
	 */
	private function reportMissingAmefConfig(array &$stats, array $target): void {
		$stats['errors'][] = 'AMEF register or element schema not configured';
		$this->logger->error(
			'AMEF configuration missing',
			[
				'app' => 'stackiq',
				'amefRegister' => $target['register'],
				'elementSchema' => $target['schema'],
			]
		);
	}//end reportMissingAmefConfig()

	/**
	 * Collect the slugs of the found AMEF elements.
	 *
	 * @param array $amefElements The found AMEF elements
	 * @param array $stats        Processing statistics, updated in place
	 *
	 * @return array The slugs, in element order.
	 */
	private function amefSlugsOf(array $amefElements, array &$stats): array {
		$amefSlugs = [];
		foreach ($amefElements as $amefElement) {
			$amefData = $amefElement->getObject();
			if (isset($amefData['slug']) === true) {
				$amefSlugs[] = $amefData['slug'];
				$stats['amefElementsProcessed']++;
			}
		}

		return $amefSlugs;
	}//end amefSlugsOf()

	/**
	 * Store the AMEF slugs on the gebruik object, or log that there are none.
	 *
	 * @param ObjectEntity $gebruikObject The gebruik object
	 * @param array        $amefSlugs     The AMEF slugs found
	 *
	 * @return void
	 *
	 * @throws Exception If the gebruik object cannot be updated.
	 */
	private function storeAmefSlugs(ObjectEntity $gebruikObject, array $amefSlugs): void {
		if (empty($amefSlugs) === true) {
			$this->logger->info(
				'No AMEF elements with slugs found',
				[
					'gebruikId' => $gebruikObject->getUuid(),
				]
			);
			return;
		}

		$this->saveAmefSlugs(gebruikObject: $gebruikObject, amefSlugs: $amefSlugs);
	}//end storeAmefSlugs()

	/**
	 * Save the unique AMEF slugs on the gebruik object.
	 *
	 * @param ObjectEntity $gebruikObject The gebruik object
	 * @param array        $amefSlugs     The AMEF slugs found (not empty)
	 *
	 * @return void
	 *
	 * @throws Exception If the gebruik object cannot be updated.
	 */
	private function saveAmefSlugs(ObjectEntity $gebruikObject, array $amefSlugs): void {
		$gebruikData = $gebruikObject->getObject();
		$gebruikData['amefElements'] = array_unique($amefSlugs);
		$this->updateGebruikObject(
			gebruikObject: $gebruikObject,
			updatedData: $gebruikData
		);

		$this->logAmefSlugsUpdated(gebruikUuid: $gebruikObject->getUuid(), amefSlugs: $amefSlugs);
	}//end saveAmefSlugs()

	/**
	 * Log the AMEF slugs stored on a gebruik object.
	 *
	 * @param string|null $gebruikUuid The gebruik object's UUID
	 * @param array       $amefSlugs   The AMEF slugs stored
	 *
	 * @return void
	 */
	private function logAmefSlugsUpdated(?string $gebruikUuid, array $amefSlugs): void {
		$this->logger->critical(
			'AMEF ELEMENTS UPDATED',
			[
				'app' => 'stackiq',
				'gebruikId' => $gebruikUuid,
				'amefSlugs' => $amefSlugs,
				'amefElementsCount' => count($amefSlugs),
			]
		);
	}//end logAmefSlugsUpdated()

	/**
	 * Log a failure while linking AMEF elements.
	 *
	 * @param ObjectEntity $gebruikObject The gebruik object
	 * @param Exception    $exception     The failure
	 *
	 * @return void
	 */
	private function logAmefProcessingError(ObjectEntity $gebruikObject, Exception $exception): void {
		$this->logger->error(
			'AMEF PROCESSING ERROR',
			[
				'app' => 'stackiq',
				'gebruikId' => $gebruikObject->getUuid(),
				'exception' => $exception->getMessage(),
			]
		);
	}//end logAmefProcessingError()

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
						'app' => 'stackiq',
						'id' => $id,
						'error' => $e->getMessage(),
					]
				);
			}//end try
		}//end foreach

		$this->logger->info(
			'AMEF elements search completed',
			[
				'app' => 'stackiq',
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
					'app' => 'stackiq',
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
						'app' => 'stackiq',
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
					'app' => 'stackiq',
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
		$target = [null, null];

		foreach ($statusDates as $status => $dateString) {
			$entry = ['gebruikId' => $gebruikUuid, 'status' => $status, 'dateString' => $dateString];
			$date = $this->eligibleDate(entry: $entry, now: $now);
			if ($date !== null && ($target[1] === null || $date > $target[1])) {
				$target = [$status, $date];
			}
		}

		return $target;
	}//end resolveLatestEligibleStatus()

	/**
	 * The start date of one status, when it is set, parses and is not in the future.
	 *
	 * @param array{gebruikId: string, status: string, dateString: string|null} $entry The gebruik UUID, the status
	 *                                                                                 and its date string.
	 * @param DateTime                                                          $now   The reference moment.
	 *
	 * @return DateTime|null
	 */
	private function eligibleDate(array $entry, DateTime $now): ?DateTime {
		if (empty($entry['dateString']) === true) {
			return null;
		}

		$date = $this->parsedStatusDate(entry: $entry);
		if ($date === null || $date > $now) {
			return null;
		}

		return $date;
	}//end eligibleDate()

	/**
	 * Parse a status date string, logging and skipping one that does not parse.
	 *
	 * @param array{gebruikId: string, status: string, dateString: string} $entry The gebruik UUID, the status and
	 *                                                                            its date string.
	 *
	 * @return DateTime|null
	 */
	private function parsedStatusDate(array $entry): ?DateTime {
		try {
			return new DateTime($entry['dateString']);
		} catch (Exception $e) {
			$this->logger->warning(
				'Invalid date format',
				['app' => 'stackiq'] + $entry + ['error' => $e->getMessage()]
			);
			return null;
		}
	}//end parsedStatusDate()

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

			// Update the object.
			$this->objectService->saveObject(
				object: $updatedData,
				register: (int)$register,
				schema: (int)$gebruikSchema,
				uuid: $gebruikObject->getUuid()
			);

			$this->logger->info(
				'Gebruik object updated successfully',
				[
					'app' => 'stackiq',
					'gebruikId' => $gebruikObject->getUuid(),
				]
			);
		} catch (Exception $e) {
			$this->logger->error(
				'Failed to update gebruik object',
				[
					'app' => 'stackiq',
					'gebruikId' => $gebruikObject->getUuid(),
					'error' => $e->getMessage(),
				]
			);
			throw $e;
		}//end try
	}//end updateGebruikObject()
}//end class
