<?php

/**
 * Module Compliance Service
 *
 * This file contains the service class for handling module compliance logic
 * in the SoftwareCatalog application.
 *
 * @category  Service
 * @package   OCA\SoftwareCatalog\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 *
 * @spec openspec/specs/method-decomposition/spec.md
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Service;

use OCA\OpenRegister\Service\ObjectService;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for handling module compliance logic.
 *
 * This service handles the automatic synchronization of module 'standaarden'
 * property based on linked compliance objects and their standaardversie references.
 *
 * @category Service
 * @package  OCA\SoftwareCatalog\Service
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version  GIT: <git_id>
 * @link     https://codeberg.org/Conduction/SoftwareCatalog
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
class ModuleComplianceService {
	/**
	 * Constructor for ModuleComplianceService
	 *
	 * @param ContainerInterface $container The container interface
	 * @param SettingsService $settingsService The settings service
	 * @param LoggerInterface $logger The logger instance
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly SettingsService $settingsService,
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
			$this->syncStandaarden(
				moduleObject: $moduleObject,
				moduleId: (string)$moduleId,
				moduleUuid: $moduleUuid,
				currentStandaarden: $this->normaliseCurrentStandaarden(rawStandaarden: $moduleData['standaardVersies'] ?? [])
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
	 * @param mixed $rawStandaarden The raw stored value (array or otherwise).
	 *
	 * @return array<int,string> A normalised array of standaardversie UUIDs.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md#task-8-2
	 */
	private function normaliseCurrentStandaarden(mixed $rawStandaarden): array {
		if (is_array($rawStandaarden) === false) {
			return [];
		}

		return $rawStandaarden;
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
	 * @param array<string> $currentStandaarden The standaarden currently stored
	 *                                          on the module.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md#task-8-2
	 */
	private function syncStandaarden(
		object $moduleObject,
		string $moduleId,
		string $moduleUuid,
		array $currentStandaarden,
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

		if ($this->arraysAreDifferent(array1: $currentStandaarden, array2: $standaardversieUuids) === false) {
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
				'oldStandaarden' => $currentStandaarden,
				'newStandaarden' => $standaardversieUuids,
			]
		);

		$this->updateModuleStandaarden(
			moduleObject: $moduleObject,
			standaardversieUuids: $standaardversieUuids
		);

		$this->logger->info(
			'ModuleComplianceService: Successfully updated module standaarden',
			[
				'moduleId' => $moduleId,
				'moduleUuid' => $moduleUuid,
				'standaarden' => $standaardversieUuids,
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
		$standaardversieUuids = [];
		$tracking = [
			'withStandaardversie' => 0,
			'withoutStandaardversie' => 0,
			'stringType' => 0,
			'arrayType' => 0,
			'objectType' => 0,
			'invalidType' => 0,
		];

		foreach ($complianceObjects as $complianceObject) {
			$complianceData = $complianceObject->getObject();
			$standaardversie = $complianceData['standaardversie'] ?? null;

			if ($standaardversie === null) {
				$tracking['withoutStandaardversie']++;
				$this->logger->debug(
					'ModuleComplianceService: Compliance object missing standaardversie',
					[
						'complianceId' => $complianceObject->getId(),
						'complianceUuid' => $complianceData['uuid'] ?? 'unknown',
					]
				);
				continue;
			}

			$tracking['withStandaardversie']++;

			// Handle both string UUID and object with UUID property.
			if (is_string($standaardversie) === true) {
				$tracking['stringType']++;
				$standaardversieUuids[] = $standaardversie;
				$this->logger->debug(
					'ModuleComplianceService: Found string standaardversie',
					[
						'complianceId' => $complianceObject->getId(),
						'standaardversie' => $standaardversie,
					]
				);
			} elseif (is_array($standaardversie) === true && isset($standaardversie['uuid']) === true) {
				$tracking['arrayType']++;
				$standaardversieUuids[] = $standaardversie['uuid'];
				$this->logger->debug(
					'ModuleComplianceService: Found array standaardversie',
					[
						'complianceId' => $complianceObject->getId(),
						'standaardversie' => $standaardversie['uuid'],
					]
				);
			} elseif (is_object($standaardversie) === true && isset($standaardversie->uuid) === true) {
				$tracking['objectType']++;
				$standaardversieUuids[] = $standaardversie->uuid;
				$this->logger->debug(
					'ModuleComplianceService: Found object standaardversie',
					[
						'complianceId' => $complianceObject->getId(),
						'standaardversie' => $standaardversie->uuid,
					]
				);
			}//end if

			if (is_string($standaardversie) === false
				&& (is_array($standaardversie) === false || isset($standaardversie['uuid']) === false)
				&& (is_object($standaardversie) === false || isset($standaardversie->uuid) === false)
			) {
				$tracking['invalidType']++;
				$standaardversieValue = (string)$standaardversie;
				if (is_array($standaardversie) === true) {
					$standaardversieValue = json_encode($standaardversie);
				}

				$this->logger->warning(
					'ModuleComplianceService: Invalid standaardversie type',
					[
						'complianceId' => $complianceObject->getId(),
						'type' => gettype($standaardversie),
						'value' => $standaardversieValue,
					]
				);
			}
		}//end foreach

		// Remove duplicates and empty values.
		$standaardversieUuids = array_unique(array_filter($standaardversieUuids));

		$this->logger->info(
			'ModuleComplianceService: Extracted standaardversie UUIDs',
			[
				'complianceCount' => count($complianceObjects),
				'tracking' => $tracking,
				'uniqueStandaardversieUuids' => count($standaardversieUuids),
				'standaardversieUuids' => $standaardversieUuids,
			]
		);

		return $standaardversieUuids;
	}//end extractStandaardversieUuids()

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
	private function updateModuleStandaarden(object $moduleObject, array $standaardversieUuids): void {
		try {
			// Get object service.
			$objectService = $this->getObjectService();
			if ($objectService === null) {
				throw new \RuntimeException('ObjectService not available');
			}

			// Get current module data.
			$moduleData = $moduleObject->getObject();

			// Update standaarden property.
			$moduleData['standaardVersies'] = $standaardversieUuids;

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
					'moduleUuid' => $moduleData['uuid'] ?? null,
					'standaarden' => $standaardversieUuids,
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

		$results = [
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

		try {
			// Get compliance schema ID from configuration.
			$complianceSchemaId = $this->settingsService->getSchemaIdForObjectType('compliancy');

			if ($complianceSchemaId === null) {
				throw new \RuntimeException('Compliance schema not configured');
			}

			// Get register ID from voorzieningen config.
			$voorzieningenConfig = $this->settingsService->getVoorzieningenConfig();
			$registerId = $voorzieningenConfig['register'] ?? null;

			if ($registerId === null) {
				throw new \RuntimeException('Voorzieningen register not configured');
			}

			// Get module schema ID from configuration.
			$moduleSchemaId = $this->settingsService->getSchemaIdForObjectType('module');

			if ($moduleSchemaId === null) {
				throw new \RuntimeException('Module schema not configured');
			}

			// Get object service.
			$objectService = $this->getObjectService();
			if ($objectService === null) {
				throw new \RuntimeException('ObjectService not available');
			}

			// Get all compliance objects (both schema AND register are required).
			// Bulk sync structurally needs "all matching" — bounded at a
			// documented safe ceiling rather than left unbounded.
			$query = [
				'@self' => [
					'schema' => (int)$complianceSchemaId,
					'register' => (int)$registerId,
				],
				'_limit' => 1000,
			];
			$complianceObjects = $objectService->searchObjects($query);

			$this->logger->info(
				'ModuleComplianceService: Found compliance objects for bulk sync',
				[
					'count' => count($complianceObjects),
				]
			);

			$results['totalProcessed'] = count($complianceObjects);

			// Group compliance objects by module UUID and track samples.
			$complianceByModule = [];
			$sampleCount = 0;

			foreach ($complianceObjects as $complianceObject) {
				$complianceData = $complianceObject->getObject();
				$moduleUuid = $complianceData['module'] ?? null;
				$standaardversie = $complianceData['standaardversie'] ?? null;

				// Track compliance objects with/without standaardversie (first 5 samples).
				if ($sampleCount < 5) {
					if ($standaardversie !== null) {
						$results['samples']['complianceWithStandaardversie'][] = [
							'id' => $complianceObject->getId(),
							'uuid' => $complianceData['uuid'] ?? 'unknown',
							'module' => $moduleUuid,
							'standaardversie' => $standaardversie,
						];
					}

					if ($standaardversie === null) {
						$results['samples']['complianceWithoutStandaardversie'][] = [
							'id' => $complianceObject->getId(),
							'uuid' => $complianceData['uuid'] ?? 'unknown',
							'module' => $moduleUuid,
						];
					}

					$sampleCount++;
				}

				if ($moduleUuid === null) {
					$results['complianceMissingModule']++;
					$results['errors'][] = 'Compliance object has no module reference: ' . $complianceObject->getId();
					continue;
				}

				// Handle both string UUID and object with UUID property.
				$moduleUuidValue = null;
				if (is_string($moduleUuid) === true) {
					$moduleUuidValue = $moduleUuid;
				} elseif (is_array($moduleUuid) === true && isset($moduleUuid['uuid']) === true) {
					$moduleUuidValue = $moduleUuid['uuid'];
				} elseif (is_object($moduleUuid) === true && isset($moduleUuid->uuid) === true) {
					$moduleUuidValue = $moduleUuid->uuid;
				}

				if ($moduleUuidValue === null) {
					$results['errors'][] = 'Invalid module reference in compliance object: ' . $complianceObject->getId();
					continue;
				}

				if (isset($complianceByModule[$moduleUuidValue]) === false) {
					$complianceByModule[$moduleUuidValue] = [];
				}

				$complianceByModule[$moduleUuidValue][] = $complianceObject;
			}//end foreach

			$results['modulesFound'] = count($complianceByModule);

			// Process each module.
			foreach ($complianceByModule as $moduleUuid => $moduleComplianceObjects) {
				try {
					// Find the module object (must specify register and schema for magic table lookup).
					$moduleObject = $objectService->find(
						id: $moduleUuid,
						register: (int)$registerId,
						schema: (int)$moduleSchemaId
					);
					if ($moduleObject === null) {
						$results['modulesNotFound']++;
						$results['errors'][] = 'Module not found for UUID: ' . $moduleUuid;

						// Add to full modules list.
						$results['modules'][] = [
							'uuid' => $moduleUuid,
							'name' => 'Not Found',
							'status' => 'error',
							'reason' => 'Module not found in database',
							'complianceCount' => count($moduleComplianceObjects),
							'currentStandaarden' => [],
							'newStandaarden' => [],
							'standardsCount' => 0,
						];
						continue;
					}

					// Get module data for tracking.
					$moduleData = $moduleObject->getObject();
					$moduleName = $moduleData['name'] ?? $moduleData['title'] ?? 'Unknown';

					// Extract standaardversie UUIDs from compliance objects.
					$standaardversieUuids = $this->extractStandaardversieUuids(complianceObjects: $moduleComplianceObjects);

					if (empty($standaardversieUuids) === true) {
						$results['modulesWithNoStandards']++;
						$results['complianceMissingStandaardversie'] += count($moduleComplianceObjects);

						// Add to full modules list.
						$results['modules'][] = [
							'uuid' => $moduleUuid,
							'name' => $moduleName,
							'status' => 'skipped',
							'reason' => 'No standaardversie found',
							'complianceCount' => count($moduleComplianceObjects),
							'currentStandaarden' => [],
							'newStandaarden' => [],
							'standardsCount' => 0,
						];

						// Add to samples (first 5).
						if (count($results['samples']['modulesSkipped']) < 5) {
							$complianceCount = count($moduleComplianceObjects);
							$reason = 'No standaardversie found in ' . $complianceCount . ' compliance object(s)';
							$results['samples']['modulesSkipped'][] = [
								'uuid' => $moduleUuid,
								'name' => $moduleName,
								'reason' => $reason,
								'complianceCount' => $complianceCount,
							];
						}

						continue;
					}//end if

					// Get current standaarden from module.
					$currentStandaarden = $moduleData['standaardVersies'] ?? [];

					// Ensure currentStandaarden is an array.
					if (is_array($currentStandaarden) === false) {
						$currentStandaarden = [];
					}

					// Compare and update if different.
					if ($this->arraysAreDifferent(array1: $currentStandaarden, array2: $standaardversieUuids) === true) {
						// Update the module with new standaarden.
						$this->updateModuleStandaarden(
							moduleObject: $moduleObject,
							standaardversieUuids: $standaardversieUuids
						);

						$results['modulesUpdated']++;
						$results['standardsAdded'] += count($standaardversieUuids);

						// Add to full modules list.
						$results['modules'][] = [
							'uuid' => $moduleUuid,
							'name' => $moduleName,
							'status' => 'updated',
							'reason' => 'Standards updated',
							'complianceCount' => count($moduleComplianceObjects),
							'currentStandaarden' => $currentStandaarden,
							'newStandaarden' => $standaardversieUuids,
							'standardsCount' => count($standaardversieUuids),
						];

						// Add to samples (first 5).
						if (count($results['samples']['modulesUpdated']) < 5) {
							$results['samples']['modulesUpdated'][] = [
								'uuid' => $moduleUuid,
								'name' => $moduleName,
								'oldStandaarden' => $currentStandaarden,
								'newStandaarden' => $standaardversieUuids,
								'complianceCount' => count($moduleComplianceObjects),
							];
						}

						$this->logger->info(
							'ModuleComplianceService: Updated module in bulk sync',
							[
								'moduleUuid' => $moduleUuid,
								'moduleName' => $moduleName,
								'standaarden' => $standaardversieUuids,
								'count' => count($standaardversieUuids),
							]
						);
					}//end if

					if ($this->arraysAreDifferent(array1: $currentStandaarden, array2: $standaardversieUuids) === false) {
						$results['modulesAlreadyUpToDate']++;

						// Add to full modules list.
						$results['modules'][] = [
							'uuid' => $moduleUuid,
							'name' => $moduleName,
							'status' => 'up-to-date',
							'reason' => 'Already up-to-date',
							'complianceCount' => count($moduleComplianceObjects),
							'currentStandaarden' => $currentStandaarden,
							'newStandaarden' => $standaardversieUuids,
							'standardsCount' => count($currentStandaarden),
						];

						// Add to samples (first 5).
						if (count($results['samples']['modulesSkipped']) < 5) {
							$results['samples']['modulesSkipped'][] = [
								'uuid' => $moduleUuid,
								'name' => $moduleName,
								'reason' => 'Already up-to-date',
								'currentStandaarden' => $currentStandaarden,
								'extractedStandaarden' => $standaardversieUuids,
								'complianceCount' => count($moduleComplianceObjects),
							];
						}
					}//end if
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
			}//end foreach

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
	 * Get the object service
	 *
	 * @return ObjectService|null The object service or null if not available
	 */
	private function getObjectService(): ?ObjectService {
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
