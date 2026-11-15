<?php

/**
 * Module Compliance Service
 *
 * This file contains the service class for handling module compliance logic
 * in the Stackiq application.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/method-decomposition/spec.md
 *
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Stackiq\Kernel\Contracts\ModuleComplianceServiceInterface;
use OCA\Stackiq\Service\Settings\RegisterConfigReaderInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for handling module compliance logic.
 *
 * This service handles the automatic synchronization of module 'standards'
 * property based on linked compliance objects and their standaardversie references.
 *
 * @category Service
 * @package  OCA\Stackiq\Service
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version  GIT: <git_id>
 * @link     https://github.com/ConductionNL/stackiq
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
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
class ModuleComplianceService implements ModuleComplianceServiceInterface {
	/**
	 * Per-shape counters reported after extracting standaardversie UUIDs
	 */
	private const STANDARD_VERSION_TRACKING = [
		'withStandaardversie' => 0,
		'withoutStandaardversie' => 0,
		'stringType' => 0,
		'arrayType' => 0,
		'objectType' => 0,
		'invalidType' => 0,
	];

	/**
	 * Debug message logged for each valid standaardversie shape
	 */
	private const FOUND_STANDARD_VERSION_MESSAGES = [
		'stringType' => 'ModuleComplianceService: Found string standaardversie',
		'arrayType' => 'ModuleComplianceService: Found array standaardversie',
		'objectType' => 'ModuleComplianceService: Found object standaardversie',
	];

	/**
	 * Constructor for ModuleComplianceService
	 *
	 * @param ContainerInterface $container The container interface
	 * @param RegisterConfigReaderInterface $settingsService The settings service
	 * @param LoggerInterface $logger The logger instance
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly RegisterConfigReaderInterface $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle module compliance update
	 *
	 * @param object $moduleObject The module object that was updated
	 *
	 * @return void
	 *
	 * @throws \Exception If the update fails
	 *
	 * @spec openspec/specs/method-decomposition/spec.md
	 * @spec openspec/specs/module-compliance-assessment/spec.md
	 */
	public function handleModuleComplianceUpdate(object $moduleObject): void {
		$startTime = microtime(true);
		$moduleId = $moduleObject->getId();

		$this->logger->info(
			'ModuleComplianceService: Starting module compliance update handling',
			[
				'moduleId' => $moduleId,
				'timestamp' => date('Y-m-d H:i:s'),
			]
		);

		try {
			// Get the object service.
			$objectService = $this->getObjectService();
			if ($objectService === null) {
				throw new \RuntimeException('ObjectService not available');
			}

			// Get module UUID from entity.
			$moduleUuid = $moduleObject->getUuid();
			$moduleData = $moduleObject->getObject();

			if ($moduleUuid === null) {
				$this->logger->warning(
					'ModuleComplianceService: Module object has no UUID',
					[
						'moduleId' => $moduleId,
					]
				);
				return;
			}

			$this->logger->debug(
				'ModuleComplianceService: Processing module',
				[
					'moduleId' => $moduleId,
					'moduleUuid' => $moduleUuid,
				]
			);

			// Compute desired standaarden and apply if changed.
			$this->syncStandards(
				moduleObject: $moduleObject,
				moduleId: (string)$moduleId,
				moduleUuid: $moduleUuid,
				currentStandards: $this->normaliseCurrentStandards(rawStandards: $moduleData['standardVersions'] ?? [])
			);

			$endTime = microtime(true);
			$executionTime = round(($endTime - $startTime) * 1000, 2);

			$this->logger->info(
				'ModuleComplianceService: Completed module compliance update handling',
				[
					'moduleId' => $moduleId,
					'moduleUuid' => $moduleUuid,
					'executionTimeMs' => $executionTime,
					'timestamp' => date('Y-m-d H:i:s'),
				]
			);
		} catch (\Exception $e) {
			$this->logger->error(
				'ModuleComplianceService: Failed to handle module compliance update',
				[
					'moduleId' => $moduleId,
					'exception' => $e->getMessage(),
					'file' => $e->getFile(),
					'line' => $e->getLine(),
					'trace' => $e->getTraceAsString(),
				]
			);
			throw $e;
		}//end try
	}//end handleModuleComplianceUpdate()

	/**
	 * Normalise the module's stored standaardVersies value to an array.
	 *
	 * Extracted from `handleModuleComplianceUpdate()` per
	 * `openspec/changes/method-decomposition/tasks.md` task 8.2.
	 *
	 * @param mixed $rawStandards The raw stored value (array or otherwise).
	 *
	 * @return array<int,string> A normalised array of standaardversie UUIDs.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md#task-8-2
	 */
	private function normaliseCurrentStandards(mixed $rawStandards): array {
		if (is_array($rawStandards) === false) {
			return [];
		}

		return $rawStandards;
	}//end normaliseCurrentStandaarden()

	/**
	 * Resolve the desired standaarden for a module and apply if changed.
	 *
	 * Extracted from `handleModuleComplianceUpdate()` per
	 * `openspec/changes/method-decomposition/tasks.md` task 8.2: composes
	 * the fetch / extract / diff / persist pipeline into one named step.
	 *
	 * @param object $moduleObject The module entity.
	 * @param string $moduleId The internal id, for logging.
	 * @param string $moduleUuid The module UUID, drives the query.
	 * @param array<string> $currentStandards The standards currently stored
	 *                                        on the module.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md#task-8-2
	 */
	private function syncStandards(
		object $moduleObject,
		string $moduleId,
		string $moduleUuid,
		array $currentStandards,
	): void {
		$complianceObjects = $this->getComplianceObjectsForModule(moduleUuid: $moduleUuid);

		$this->logger->debug(
			'ModuleComplianceService: Found compliance objects',
			[
				'moduleId' => $moduleId,
				'moduleUuid' => $moduleUuid,
				'complianceCount' => count($complianceObjects),
			]
		);

		$standaardversieUuids = $this->extractStandaardversieUuids(complianceObjects: $complianceObjects);

		if ($this->arraysAreDifferent(array1: $currentStandards, array2: $standaardversieUuids) === false) {
			$this->logger->debug(
				'ModuleComplianceService: Standaarden are already up to date',
				[
					'moduleId' => $moduleId,
					'moduleUuid' => $moduleUuid,
				]
			);
			return;
		}

		$this->logger->info(
			'ModuleComplianceService: Standaarden differ, updating module',
			[
				'moduleId' => $moduleId,
				'moduleUuid' => $moduleUuid,
				'oldStandaarden' => $currentStandards,
				'newStandaarden' => $standaardversieUuids,
			]
		);

		$this->updateModuleStandards(
			moduleObject: $moduleObject,
			standaardversieUuids: $standaardversieUuids
		);

		$this->logger->info(
			'ModuleComplianceService: Successfully updated module standaarden',
			[
				'moduleId' => $moduleId,
				'moduleUuid' => $moduleUuid,
				'standards' => $standaardversieUuids,
			]
		);

	}//end syncStandaarden()

	/**
	 * Get compliance objects linked to a module
	 *
	 * @param string $moduleUuid The module UUID
	 *
	 * @return array Array of compliance objects
	 *
	 * @throws \Exception If retrieval fails
	 */
	private function getComplianceObjectsForModule(string $moduleUuid): array {
		try {
			// Get compliance schema ID from configuration.
			$complianceSchemaId = $this->settingsService->getSchemaIdForObjectType('compliancy');

			if ($complianceSchemaId === null) {
				$this->logger->warning(
					'ModuleComplianceService: Compliance schema not configured',
					[
						'moduleUuid' => $moduleUuid,
					]
				);
				return [];
			}

			// Get register ID from voorzieningen config.
			$voorzieningenConfig = $this->settingsService->getVoorzieningenConfig();
			$registerId = $voorzieningenConfig['register'] ?? null;

			if ($registerId === null) {
				$this->logger->warning(
					'ModuleComplianceService: Voorzieningen register not configured',
					[
						'moduleUuid' => $moduleUuid,
					]
				);
				return [];
			}

			// Get object service.
			$objectService = $this->getObjectService();
			if ($objectService === null) {
				throw new \RuntimeException('ObjectService not available');
			}

			// Query compliance objects where module matches the module UUID.
			$query = [
				'@self' => [
					'schema' => (int)$complianceSchemaId,
					'register' => (int)$registerId,
				],
				'module' => $moduleUuid,
				'_limit' => 500,
			];
			$complianceObjects = $objectService->searchObjects($query);

			$this->logger->debug(
				'ModuleComplianceService: Retrieved compliance objects',
				[
					'moduleUuid' => $moduleUuid,
					'complianceSchemaId' => $complianceSchemaId,
					'count' => count($complianceObjects),
				]
			);

			return $complianceObjects;
		} catch (\Exception $e) {
			$this->logger->error(
				'ModuleComplianceService: Failed to get compliance objects for module',
				[
					'moduleUuid' => $moduleUuid,
					'exception' => $e->getMessage(),
					'file' => $e->getFile(),
					'line' => $e->getLine(),
				]
			);
			throw $e;
		}//end try
	}//end getComplianceObjectsForModule()

	/**
	 * Extract standaardversie UUIDs from compliance objects
	 *
	 * @param array $complianceObjects Array of compliance objects
	 *
	 * @return array Array of standaardversie UUIDs
	 */
	private function extractStandaardversieUuids(array $complianceObjects): array {
		$collected = $this->collectStandardVersions(complianceObjects: $complianceObjects);

		// Remove duplicates and empty values.
		$standaardversieUuids = array_unique(array_filter($collected['uuids']));

		$this->logger->info(
			'ModuleComplianceService: Extracted standaardversie UUIDs',
			[
				'complianceCount' => count($complianceObjects),
				'tracking' => $collected['tracking'],
				'uniqueStandaardversieUuids' => count($standaardversieUuids),
				'standaardversieUuids' => $standaardversieUuids,
			]
		);

		return $standaardversieUuids;
	}//end extractStandaardversieUuids()

	/**
	 * Classify every compliance object's standaardversie and tally the shapes
	 *
	 * @param array $complianceObjects Array of compliance objects
	 *
	 * @return array{tracking: array<string, int>, uuids: array<int, string>} The shape tally and the UUIDs found
	 */
	private function collectStandardVersions(array $complianceObjects): array {
		$collected = ['tracking' => self::STANDARD_VERSION_TRACKING, 'uuids' => []];

		foreach ($complianceObjects as $complianceObject) {
			[$shape, $uuid] = $this->classifyStandardVersion(complianceObject: $complianceObject);
			$collected['tracking'][$shape]++;
			if ($shape !== 'withoutStandaardversie') {
				$collected['tracking']['withStandaardversie']++;
			}

			if ($uuid !== null) {
				$collected['uuids'][] = $uuid;
			}
		}

		return $collected;
	}//end collectStandardVersions()

	/**
	 * Classify one compliance object's standaardversie and log what was found
	 *
	 * @param object $complianceObject The compliance object
	 *
	 * @return array{0: string, 1: string|null} The tracking shape and the UUID, if any
	 */
	private function classifyStandardVersion(object $complianceObject): array {
		$complianceData = $complianceObject->getObject();
		$standardVersion = $complianceData['standardVersion'] ?? null;

		if ($standardVersion === null) {
			$this->logMissingStandardVersion(complianceObject: $complianceObject, complianceData: $complianceData);
			return ['withoutStandaardversie', null];
		}

		$found = $this->standardVersionShape(standardVersion: $standardVersion);
		if ($found[1] === null) {
			$this->logInvalidStandardVersion(complianceObject: $complianceObject, standardVersion: $standardVersion);
			return $found;
		}

		$this->logFoundStandardVersion(complianceObject: $complianceObject, found: $found);
		return $found;
	}//end classifyStandardVersion()

	/**
	 * Log a standaardversie UUID found on a compliance object
	 *
	 * @param object $complianceObject The compliance object
	 * @param array  $found            The tracking shape and the UUID
	 *
	 * @return void
	 */
	private function logFoundStandardVersion(object $complianceObject, array $found): void {
		$this->logger->debug(
			self::FOUND_STANDARD_VERSION_MESSAGES[$found[0]],
			['complianceId' => $complianceObject->getId(), 'standardVersion' => $found[1]]
		);
	}//end logFoundStandardVersion()

	/**
	 * Determine the shape of a standaardversie value: a string UUID, or an array or object with a UUID
	 *
	 * @param mixed $standardVersion The standaardversie value (not null)
	 *
	 * @return array{0: string, 1: string|null} The tracking shape and the UUID, null when invalid
	 */
	private function standardVersionShape(mixed $standardVersion): array {
		if (is_string($standardVersion) === true) {
			return ['stringType', $standardVersion];
		}

		$uuid = $this->nestedStandardVersionUuid(standardVersion: $standardVersion);
		if ($uuid === null) {
			return ['invalidType', null];
		}

		if (is_array($standardVersion) === true) {
			return ['arrayType', $uuid];
		}

		return ['objectType', $uuid];
	}//end standardVersionShape()

	/**
	 * Read the UUID property of an array or object standaardversie
	 *
	 * @param mixed $standardVersion The standaardversie value
	 *
	 * @return mixed The UUID, or null when the value carries none
	 */
	private function nestedStandardVersionUuid(mixed $standardVersion): mixed {
		if (is_array($standardVersion) === true && isset($standardVersion['uuid']) === true) {
			return $standardVersion['uuid'];
		}

		if (is_object($standardVersion) === true && isset($standardVersion->uuid) === true) {
			return $standardVersion->uuid;
		}

		return null;
	}//end nestedStandardVersionUuid()

	/**
	 * Log a compliance object that has no standaardversie
	 *
	 * @param object $complianceObject The compliance object
	 * @param array  $complianceData   The compliance object's data
	 *
	 * @return void
	 */
	private function logMissingStandardVersion(object $complianceObject, array $complianceData): void {
		$this->logger->debug(
			'ModuleComplianceService: Compliance object missing standaardversie',
			[
				'complianceId' => $complianceObject->getId(),
				'complianceUuid' => $complianceData['uuid'] ?? 'unknown',
			]
		);
	}//end logMissingStandardVersion()

	/**
	 * Log a standaardversie value of an unsupported type
	 *
	 * @param object $complianceObject The compliance object
	 * @param mixed  $standardVersion  The standaardversie value
	 *
	 * @return void
	 */
	private function logInvalidStandardVersion(object $complianceObject, mixed $standardVersion): void {
		$standardVersionValue = (string)$standardVersion;
		if (is_array($standardVersion) === true) {
			$standardVersionValue = json_encode($standardVersion);
		}

		$this->logger->warning(
			'ModuleComplianceService: Invalid standaardversie type',
			[
				'complianceId' => $complianceObject->getId(),
				'type' => gettype($standardVersion),
				'value' => $standardVersionValue,
			]
		);
	}//end logInvalidStandardVersion()

	/**
	 * Check if two arrays are different (ignoring order)
	 *
	 * @param array $array1 First array
	 * @param array $array2 Second array
	 *
	 * @return bool True if arrays are different
	 */
	private function arraysAreDifferent(array $array1, array $array2): bool {
		// Sort both arrays to ignore order.
		sort($array1);
		sort($array2);

		return $array1 !== $array2;
	}//end arraysAreDifferent()

	/**
	 * Update module standaarden property
	 *
	 * @param object $moduleObject The module object to update
	 * @param array $standaardversieUuids Array of standaardversie UUIDs
	 *
	 * @return void
	 *
	 * @throws \Exception If update fails
	 */
	private function updateModuleStandards(object $moduleObject, array $standaardversieUuids): void {
		try {
			// Get object service.
			$objectService = $this->getObjectService();
			if ($objectService === null) {
				throw new \RuntimeException('ObjectService not available');
			}

			// Get current module data.
			$moduleData = $moduleObject->getObject();

			// Read every value needed BEFORE the write below. Assigning a key
			// narrows the inferred array shape to just that key, after which
			// phpstan reports every other offset as non-existent. A /* @var */
			// annotation does not help: a single-asterisk comment is not a
			// docblock, so phpstan ignores it — which is what happened when the
			// /** */ form was changed to satisfy phpcs.
			$moduleUuid = $moduleData['uuid'] ?? null;

			// Update the standards property.
			$moduleData['standardVersions'] = $standaardversieUuids;

			// Get register ID from module object.
			$registerId = $moduleObject->getRegister();

			// Save the updated module.
			$savedObject = $objectService->saveObject(
				object: $moduleData,
				extend: [],
				register: $registerId,
				schema: $moduleObject->getSchema(),
				uuid: $moduleObject->getUuid()
			);

			$this->logger->info(
				'ModuleComplianceService: Updated module standaarden',
				[
					'moduleId' => $moduleObject->getId(),
					'moduleUuid' => $moduleUuid,
					'standards' => $standaardversieUuids,
				]
			);
		} catch (\Exception $e) {
			$this->logger->error(
				'ModuleComplianceService: Failed to update module standaarden',
				[
					'moduleId' => $moduleObject->getId(),
					'standaardversieUuids' => $standaardversieUuids,
					'exception' => $e->getMessage(),
					'file' => $e->getFile(),
					'line' => $e->getLine(),
				]
			);
			throw $e;
		}//end try
	}//end updateModuleStandaarden()

	/**
	 * Perform bulk sync of module standards from all compliance objects
	 *
	 * @return array Results of the bulk sync operation
	 *
	 * @throws \Exception If the bulk sync fails
	 *
	 * @spec openspec/specs/method-decomposition/spec.md
	 */
	public function bulkSyncModuleStandards(): array {
		$startTime = microtime(true);

		$this->logger->info('ModuleComplianceService: Starting bulk sync of module standards');

		$results = $this->emptyBulkSyncResults();

		try {
			$target = $this->bulkSyncTarget();

			// Get all compliance objects (both schema AND register are required).
			// Bulk sync structurally needs "all matching" — bounded at a
			// documented safe ceiling rather than left unbounded.
			$query = [
				'@self' => [
					'schema' => (int)$target['complianceSchemaId'],
					'register' => (int)$target['registerId'],
				],
				'_limit' => 1000,
			];
			$complianceObjects = $target['objectService']->searchObjects($query);

			$this->logger->info(
				'ModuleComplianceService: Found compliance objects for bulk sync',
				[
					'count' => count($complianceObjects),
				]
			);

			$results['totalProcessed'] = count($complianceObjects);

			$complianceByModule = $this->groupComplianceByModule(complianceObjects: $complianceObjects, results: $results);

			$results['modulesFound'] = count($complianceByModule);

			foreach ($complianceByModule as $moduleUuid => $moduleComplianceObjects) {
				$this->syncModuleInBulk(
					moduleUuid: $moduleUuid,
					complianceObjects: $moduleComplianceObjects,
					target: $target,
					results: $results
				);
			}

			$endTime = microtime(true);
			$executionTime = round(($endTime - $startTime) * 1000, 2);

			$this->logger->info(
				'ModuleComplianceService: Completed bulk sync of module standards',
				[
					'results' => $results,
					'executionTimeMs' => $executionTime,
				]
			);

			return $results;
		} catch (\Exception $e) {
			$this->logger->error(
				'ModuleComplianceService: Bulk sync failed',
				[
					'exception' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				]
			);
			throw $e;
		}//end try
	}//end bulkSyncModuleStandards()

	/**
	 * The empty result of a bulk sync
	 *
	 * @return array The counters, errors, samples and module list, all empty
	 */
	private function emptyBulkSyncResults(): array {
		return [
			'totalProcessed' => 0,
			'complianceMissingModule' => 0,
			'complianceMissingStandaardversie' => 0,
			'modulesFound' => 0,
			'modulesNotFound' => 0,
			'modulesWithNoStandards' => 0,
			'modulesAlreadyUpToDate' => 0,
			'modulesUpdated' => 0,
			'standardsAdded' => 0,
			'errors' => [],
			'samples' => [
				'complianceWithStandaardversie' => [],
				'complianceWithoutStandaardversie' => [],
				'modulesUpdated' => [],
				'modulesSkipped' => [],
			],
			// Full list of all processed modules.
			'modules' => [],
		];
	}//end emptyBulkSyncResults()

	/**
	 * The schemas, register and object service a bulk sync works on
	 *
	 * @return array{complianceSchemaId: int, registerId: mixed, moduleSchemaId: int, objectService: ObjectServiceInterface}
	 *
	 * @throws \RuntimeException When a schema, the register or the object service is missing
	 */
	private function bulkSyncTarget(): array {
		$complianceSchemaId = $this->settingsService->getSchemaIdForObjectType('compliancy');
		if ($complianceSchemaId === null) {
			throw new \RuntimeException('Compliance schema not configured');
		}

		$voorzieningenConfig = $this->settingsService->getVoorzieningenConfig();
		$registerId = $voorzieningenConfig['register'] ?? null;
		if ($registerId === null) {
			throw new \RuntimeException('Voorzieningen register not configured');
		}

		$moduleSchemaId = $this->settingsService->getSchemaIdForObjectType('module');
		if ($moduleSchemaId === null) {
			throw new \RuntimeException('Module schema not configured');
		}

		$objectService = $this->getObjectService();
		if ($objectService === null) {
			throw new \RuntimeException('ObjectService not available');
		}

		return [
			'complianceSchemaId' => $complianceSchemaId,
			'registerId' => $registerId,
			'moduleSchemaId' => $moduleSchemaId,
			'objectService' => $objectService,
		];
	}//end bulkSyncTarget()

	/**
	 * Group compliance objects by the UUID of the module they reference
	 *
	 * Samples the first five compliance objects and records the ones without a
	 * usable module reference in the results.
	 *
	 * @param array $complianceObjects The compliance objects
	 * @param array $results           The bulk sync results, updated in place
	 *
	 * @return array<string, array> The compliance objects per module UUID
	 */
	private function groupComplianceByModule(array $complianceObjects, array &$results): array {
		$complianceByModule = [];
		$sampleCount = 0;

		foreach ($complianceObjects as $complianceObject) {
			$complianceData = $complianceObject->getObject();
			$moduleUuid = $complianceData['module'] ?? null;

			// Track compliance objects with/without standaardversie (first 5 samples).
			if ($sampleCount < 5) {
				$this->sampleComplianceObject(complianceObject: $complianceObject, results: $results);
				$sampleCount++;
			}

			if ($moduleUuid === null) {
				$results['complianceMissingModule']++;
				$results['errors'][] = 'Compliance object has no module reference: ' . $complianceObject->getId();
				continue;
			}

			// Handle both string UUID and object with UUID property.
			$moduleUuidValue = $moduleUuid;
			if (is_string($moduleUuid) === false) {
				$moduleUuidValue = $this->nestedStandardVersionUuid(standardVersion: $moduleUuid);
			}

			if ($moduleUuidValue === null) {
				$results['errors'][] = 'Invalid module reference in compliance object: ' . $complianceObject->getId();
				continue;
			}

			$complianceByModule[$moduleUuidValue][] = $complianceObject;
		}//end foreach

		return $complianceByModule;
	}//end groupComplianceByModule()

	/**
	 * Add one compliance object to the with or without standaardversie samples
	 *
	 * @param object $complianceObject The compliance object
	 * @param array  $results          The bulk sync results, updated in place
	 *
	 * @return void
	 */
	private function sampleComplianceObject(object $complianceObject, array &$results): void {
		$complianceData = $complianceObject->getObject();
		$standardVersion = $complianceData['standardVersion'] ?? null;
		$sample = [
			'id' => $complianceObject->getId(),
			'uuid' => $complianceData['uuid'] ?? 'unknown',
			'module' => $complianceData['module'] ?? null,
		];

		if ($standardVersion === null) {
			$results['samples']['complianceWithoutStandaardversie'][] = $sample;
			return;
		}

		$sample['standardVersion'] = $standardVersion;
		$results['samples']['complianceWithStandaardversie'][] = $sample;
	}//end sampleComplianceObject()

	/**
	 * Sync the standards of one module in a bulk sync and record the outcome
	 *
	 * A failure is recorded as an error and logged; it does not stop the bulk sync.
	 *
	 * @param string|int $moduleUuid    The module UUID
	 * @param array  $complianceObjects The module's compliance objects
	 * @param array  $target            The schemas, register and object service of the bulk sync
	 * @param array  $results           The bulk sync results, updated in place
	 *
	 * @return void
	 */
	private function syncModuleInBulk(string|int $moduleUuid, array $complianceObjects, array $target, array &$results): void {
		try {
			// Find the module object (must specify register and schema for magic table lookup).
			$moduleObject = $target['objectService']->find(
				id: $moduleUuid,
				register: (int)$target['registerId'],
				schema: (int)$target['moduleSchemaId']
			);
			if ($moduleObject === null) {
				$this->recordModuleNotFound(moduleUuid: $moduleUuid, complianceCount: count($complianceObjects), results: $results);
				return;
			}

			$moduleData = $moduleObject->getObject();
			$module = [
				'uuid' => $moduleUuid,
				'name' => $moduleData['name'] ?? $moduleData['title'] ?? 'Unknown',
				'complianceCount' => count($complianceObjects),
			];

			$standaardversieUuids = $this->extractStandaardversieUuids(complianceObjects: $complianceObjects);
			if (empty($standaardversieUuids) === true) {
				$this->recordModuleWithoutStandards(module: $module, results: $results);
				return;
			}

			$currentStandards = $this->normaliseCurrentStandards(rawStandards: $moduleData['standardVersions'] ?? []);
			if ($this->arraysAreDifferent(array1: $currentStandards, array2: $standaardversieUuids) === false) {
				$this->recordModuleUpToDate(module: $module, standards: [$currentStandards, $standaardversieUuids], results: $results);
				return;
			}

			$this->updateModuleStandards(moduleObject: $moduleObject, standaardversieUuids: $standaardversieUuids);
			$this->recordModuleUpdated(module: $module, standards: [$currentStandards, $standaardversieUuids], results: $results);
		} catch (\Exception $e) {
			$results['errors'][] = 'Failed to process module ' . $moduleUuid . ': ' . $e->getMessage();
			$this->logger->error(
				'ModuleComplianceService: Error processing module in bulk sync',
				[
					'moduleUuid' => $moduleUuid,
					'exception' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				]
			);
		}//end try
	}//end syncModuleInBulk()

	/**
	 * Record a module that a compliance object references but that does not exist
	 *
	 * @param string|int $moduleUuid    The module UUID
	 * @param int    $complianceCount The number of compliance objects referencing it
	 * @param array  $results         The bulk sync results, updated in place
	 *
	 * @return void
	 */
	private function recordModuleNotFound(string|int $moduleUuid, int $complianceCount, array &$results): void {
		$results['modulesNotFound']++;
		$results['errors'][] = 'Module not found for UUID: ' . $moduleUuid;

		$results['modules'][] = [
			'uuid' => $moduleUuid,
			'name' => 'Not Found',
			'status' => 'error',
			'reason' => 'Module not found in database',
			'complianceCount' => $complianceCount,
			'currentStandaarden' => [],
			'newStandaarden' => [],
			'standardsCount' => 0,
		];
	}//end recordModuleNotFound()

	/**
	 * Record a module whose compliance objects carry no standaardversie
	 *
	 * @param array $module  The module's uuid, name and compliance count
	 * @param array $results The bulk sync results, updated in place
	 *
	 * @return void
	 */
	private function recordModuleWithoutStandards(array $module, array &$results): void {
		$results['modulesWithNoStandards']++;
		$results['complianceMissingStandaardversie'] += $module['complianceCount'];

		$results['modules'][] = [
			'uuid' => $module['uuid'],
			'name' => $module['name'],
			'status' => 'skipped',
			'reason' => 'No standaardversie found',
			'complianceCount' => $module['complianceCount'],
			'currentStandaarden' => [],
			'newStandaarden' => [],
			'standardsCount' => 0,
		];

		if (count($results['samples']['modulesSkipped']) < 5) {
			$results['samples']['modulesSkipped'][] = [
				'uuid' => $module['uuid'],
				'name' => $module['name'],
				'reason' => 'No standaardversie found in ' . $module['complianceCount'] . ' compliance object(s)',
				'complianceCount' => $module['complianceCount'],
			];
		}
	}//end recordModuleWithoutStandards()

	/**
	 * Record a module whose standards were updated
	 *
	 * @param array $module    The module's uuid, name and compliance count
	 * @param array $standards The module's current and new standaardversie UUIDs
	 * @param array $results   The bulk sync results, updated in place
	 *
	 * @return void
	 */
	private function recordModuleUpdated(array $module, array $standards, array &$results): void {
		[$currentStandards, $newStandards] = $standards;
		$results['modulesUpdated']++;
		$results['standardsAdded'] += count($newStandards);

		$results['modules'][] = [
			'uuid' => $module['uuid'],
			'name' => $module['name'],
			'status' => 'updated',
			'reason' => 'Standards updated',
			'complianceCount' => $module['complianceCount'],
			'currentStandaarden' => $currentStandards,
			'newStandaarden' => $newStandards,
			'standardsCount' => count($newStandards),
		];

		if (count($results['samples']['modulesUpdated']) < 5) {
			$results['samples']['modulesUpdated'][] = [
				'uuid' => $module['uuid'],
				'name' => $module['name'],
				'oldStandaarden' => $currentStandards,
				'newStandaarden' => $newStandards,
				'complianceCount' => $module['complianceCount'],
			];
		}

		$this->logger->info(
			'ModuleComplianceService: Updated module in bulk sync',
			[
				'moduleUuid' => $module['uuid'],
				'moduleName' => $module['name'],
				'standards' => $newStandards,
				'count' => count($newStandards),
			]
		);
	}//end recordModuleUpdated()

	/**
	 * Record a module whose standards already match its compliance objects
	 *
	 * @param array $module    The module's uuid, name and compliance count
	 * @param array $standards The module's current and extracted standaardversie UUIDs
	 * @param array $results   The bulk sync results, updated in place
	 *
	 * @return void
	 */
	private function recordModuleUpToDate(array $module, array $standards, array &$results): void {
		[$currentStandards, $extractedStandards] = $standards;
		$results['modulesAlreadyUpToDate']++;

		$results['modules'][] = [
			'uuid' => $module['uuid'],
			'name' => $module['name'],
			'status' => 'up-to-date',
			'reason' => 'Already up-to-date',
			'complianceCount' => $module['complianceCount'],
			'currentStandaarden' => $currentStandards,
			'newStandaarden' => $extractedStandards,
			'standardsCount' => count($currentStandards),
		];

		if (count($results['samples']['modulesSkipped']) < 5) {
			$results['samples']['modulesSkipped'][] = [
				'uuid' => $module['uuid'],
				'name' => $module['name'],
				'reason' => 'Already up-to-date',
				'currentStandaarden' => $currentStandards,
				'extractedStandaarden' => $extractedStandards,
				'complianceCount' => $module['complianceCount'],
			];
		}
	}//end recordModuleUpToDate()

	/**
	 * Get the object service
	 *
	 * @return ObjectServiceInterface|null The object service or null if not available
	 */
	private function getObjectService(): ?ObjectServiceInterface {
		try {
			return $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (\Exception $e) {
			$this->logger->error(
				'ModuleComplianceService: Failed to get ObjectService',
				[
					'exception' => $e->getMessage(),
				]
			);
			return null;
		}
	}//end getObjectService()
}//end class
