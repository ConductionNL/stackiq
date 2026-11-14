<?php

/**
 * ArchiMate Service for Stackiq
 *
 * Handles import and export of ArchiMate XML files with round-trip fidelity.
 * Stores complete XML data as JSON blobs in the database and reconstructs
 * exact XML output during export.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Stackiq Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/nextcloud/stackiq
 *
 * @spec openspec/specs/method-decomposition/spec.md
 *
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Stackiq\Kernel\Contracts\ArchiMateServiceInterface;
use OCA\Stackiq\Service\Settings\RegisterConfigReaderInterface;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * ArchiMate Service for handling XML import/export with round-trip fidelity
 *
 * This service provides a clean approach to ArchiMate XML processing:
 * 1. Import: Parse XML to array, store complete data as JSON blob
 * 2. Storage: Use ObjectService::saveObjects with proper @self structure
 * 3. Export: Reconstruct exact XML from stored JSON blobs
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Stackiq Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/nextcloud/stackiq
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyMethods)
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 * @SuppressWarnings(PHPMD.LongVariable)
 * @SuppressWarnings(PHPMD.ShortVariable)
 * @SuppressWarnings(PHPMD.MissingImport)
 * @SuppressWarnings(PHPMD.UnusedLocalVariable)
 * @SuppressWarnings(PHPMD.UnusedPrivateMethod)
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 * @SuppressWarnings(PHPMD.Superglobals)
 * @SuppressWarnings(PHPMD.CamelCaseVariableName)
 * @SuppressWarnings(PHPMD.CamelCaseParameterName)
 * @SuppressWarnings(PHPMD.UnusedPrivateField)
 * @SuppressWarnings(PHPMD.CountInLoopExpression)
 */
class ArchiMateService implements ArchiMateServiceInterface {
	/**
	 * Configuration keys for ArchiMate processing
	 */

	/**
	 * Store last save operation timing breakdown for performance metrics
	 *
	 * @var array
	 */
	private array $lastSaveTimingBreakdown = [];

	/**
	 * CamelCase property names, with their conversion cache; created on first use.
	 *
	 * @var ArchiMate\CamelCaseNames|null
	 */
	private ?ArchiMate\CamelCaseNames $camelCaseNames = null;

	/**
	 * NOTE: Default schema IDs removed - all schema IDs must be configured via AMEF settings.
	 * The system will fail gracefully with clear error messages if configuration is missing.
	 */

	/**
	 * Constructor for ArchiMateService
	 *
	 * @param IAppConfig $config Nextcloud app configuration service
	 * @param IUserSession $userSession User session service
	 * @param IAppManager $appManager App manager service
	 * @param ContainerInterface $container PSR-11 container interface
	 * @param LoggerInterface $logger Logger service
	 * @param RegisterConfigReaderInterface $settingsService Settings service for schema and organization configuration
	 * @param ArchiMateImportService $importService Import service for XML parsing
	 * @param ArchiMateExportService $exportService Export service for XML generation
	 * @param ProgressTracker $progressTracker Progress store a running import reads its cancel from
	 */
	public function __construct(
		private readonly IAppConfig $config,
		private readonly IUserSession $userSession,
		private readonly IAppManager $appManager,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly RegisterConfigReaderInterface $settingsService,
		private readonly ArchiMateImportService $importService,
		private readonly ArchiMateExportService $exportService,
		private readonly ProgressTracker $progressTracker,
	) {
	}//end __construct()

	/**
	 * Cancel a running ArchiMate import.
	 *
	 * The import runs in another request, so this records a cancel that the import
	 * reads before its next save batch, and clears the stored import status.
	 *
	 * @param string|null $operationId The import's operation id, as the page named it
	 *
	 * @return array<string, mixed> The cancellation result
	 *
	 * @spec openspec/specs/archimate-import-progress/spec.md#requirement-req-aip-002-an-admin-shall-be-able-to-cancel-a-running-import
	 */
	public function cancelArchiMateImport(?string $operationId = null): array {
		if ($operationId !== null && preg_match(ArchiMateImportService::OPERATION_ID_PATTERN, $operationId) !== 1) {
			return [
				'cancelled' => false,
				'operation_id' => null,
				'messages' => ['The operation id is not an ArchiMate import id'],
			];
		}

		$messages = [];
		if ($operationId !== null) {
			$this->progressTracker->setCancelRequested($operationId);
			$messages[] = 'The import stops before its next save batch';
		}

		$this->config->deleteKey('stackiq', 'archimate_import_status');
		$messages[] = 'Import status cleared';

		return [
			'cancelled' => true,
			'operation_id' => $operationId,
			'status_cleared' => true,
			'cancellation_time' => date('Y-m-d H:i:s'),
			'messages' => $messages,
		];
	}//end cancelArchiMateImport()

	/**
	 * OPTIMIZED: Import ArchiMate XML file using OpenRegister-style performance optimization
	 *
	 * This method follows the same pattern as OpenRegister ImportService:
	 * 1. Parse ALL XML data first (single pass)
	 * 2. Transform to objects array (batch processing)
	 * 3. Single saveObjects() call with all objects
	 *
	 * Expected performance: <1 minute for 8000 objects (vs current 13 minutes)
	 *
	 * @param array $options Import options including file_path, fileName, etc.
	 *
	 * @return array Import results with detailed status
	 * @spec   openspec/specs/archimate-import/spec.md
	 */
	public function importArchiMateFileFromPathOptimized(array $options = []): array {
		// Delegate to the import service.
		return $this->importService->importArchiMateFileFromPathOptimized($options);
	}//end importArchiMateFileFromPathOptimized()

	/**
	 * Import ArchiMate XML file from path with model detection and round-trip fidelity
	 *
	 * This method handles the complete import workflow:
	 * 1. Parse XML to array (capturing all possible XML values)
	 * 2. Detect if model already exists or is new
	 * 3. Normalize data structure for storage as JSON blob
	 * 4. Convert to OpenRegister objects with proper @self structure
	 * 5. Save objects using ObjectService::saveObjects
	 *
	 * @param array $options Import options including file_path, fileName, etc.
	 *
	 * @return array Import results with detailed status
	 *
	 * @spec openspec/specs/method-decomposition/spec.md
	 */
	public function importArchiMateFileFromPath(array $options = []): array {
		// Delegate to the import service.
		return $this->importService->importArchiMateFileFromPath($options);
	}//end importArchiMateFileFromPath()

	/**
	 * Export ArchiMate data to XML
	 *
	 * @param string|null $organization Organization filter (currently not implemented)
	 *
	 * @return array Export results
	 *
	 * @spec openspec/specs/method-decomposition/spec.md
	 */
	public function exportToArchiMate(?string $organization = null): array {
		$this->logger->info(
			'Starting ArchiMate XML export',
			[
				'organization' => $organization,
			]
		);

		try {
			// Get ObjectService and register ID.
			$objectService = $this->getObjectService();
			if ($objectService === null) {
				throw new \RuntimeException('ObjectService not available');
			}

			$registerId = $this->getAmefRegisterId();
			if ($registerId === null) {
				throw new \RuntimeException(
					'AMEF register ID is not configured. Please configure the AMEF register via the admin interface.'
				);
			}

			// Create schema ID mapping for the export service.
			$schemaIdMap = $this->createSchemaIdMap();

			// Use export service to handle complete export process in one go.
			$xml = $this->exportService->exportArchiMateXml($objectService, $registerId, $schemaIdMap, $organization);

			$this->logger->info(
				'ArchiMate export completed successfully',
				[
					'organization_filter' => $organization,
					'xml_size' => strlen($xml),
				]
			);

			return [
				'success' => true,
				'xml' => $xml,
				'exported_count' => 'calculated_in_export_service',
				// Will be logged by export service.
			];
		} catch (\Exception $e) {
			return $this->exportFailure(message: 'ArchiMate export failed', exception: $e);
		}//end try
	}//end exportToArchiMate()

	/**
	 * Export organization-specific ArchiMate data to XML
	 *
	 * Produces an enriched AMEFF file with the base GEMMA model plus the
	 * organization's applications plotted on referentiecomponenten in views.
	 *
	 * @param string $organizationUuid UUID of the organization to export for.
	 * @param array $options Optional export options.
	 *
	 * @return array Export results with 'success', 'xml', 'file_name'
	 * @spec   openspec/specs/archimate-import/spec.md
	 */
	public function exportOrgArchiMate(string $organizationUuid, array $options = []): array {
		$this->logger->info(
			'Starting organization ArchiMate XML export',
			[
				'organization_uuid' => $organizationUuid,
				'options' => $options,
			]
		);

		try {
			$objectService = $this->getObjectService();
			if ($objectService === null) {
				throw new \RuntimeException('ObjectService not available');
			}

			// Look up the organization from Voorzieningen register.
			$voorzConfig = $this->settingsService->getVoorzieningenConfig();
			[$orgEntity, $orgRegisterId] = $this->findExportOrganisation(
				objectService: $objectService,
				voorzConfig: $voorzConfig,
				organizationUuid: $organizationUuid
			);
			if ($orgEntity === null) {
				return [
					'success' => false,
					'error' => 'Organization not found: ' . $organizationUuid,
				];
			}

			$organization = $orgEntity->jsonSerialize();
			$orgName = $organization['name'] ?? $organization['@self']['name'] ?? 'Unknown';

			// Get AMEF config and base objects.
			$registerId = $this->requireAmefRegisterId();
			$schemaIdMap = $this->createSchemaIdMap();

			[$gebruikData, $modulesData, $deelnamesData] = $this->collectOrgExportData(
				objectService: $objectService,
				voorzConfig: $voorzConfig,
				orgScope: ['register' => $orgRegisterId, 'organisation' => $organizationUuid],
				options: $options
			);

			// Delegate to export service.
			$xml = $this->exportService->exportOrganizationArchiMateXml(
				$objectService,
				$registerId,
				$schemaIdMap,
				$orgName,
				$organizationUuid,
				$gebruikData,
				$modulesData,
				$deelnamesData,
				$options
			);

			// Generate file name: DD-MM-YYYY_Stackiq_AMEFF_export_OrgName.xml.
			$fileName = date('d-m-Y') . '_Stackiq_AMEFF_export_' . str_replace(' ', '_', $orgName) . '.xml';

			return [
				'success' => true,
				'xml' => $xml,
				'file_name' => $fileName,
			];
		} catch (\Exception $e) {
			return $this->exportFailure(message: 'Organization ArchiMate export failed', exception: $e);
		}//end try
	}//end exportOrgArchiMate()

	/**
	 * Log a failed export and answer it
	 *
	 * @param string     $message   The log message
	 * @param \Exception $exception The failure
	 *
	 * @return array{success: false, error: string} The failed export result
	 */
	private function exportFailure(string $message, \Exception $exception): array {
		$this->logger->error(
			$message,
			[
				'error' => $exception->getMessage(),
				'trace' => $exception->getTraceAsString(),
			]
		);

		return [
			'success' => false,
			'error' => $exception->getMessage(),
		];
	}//end exportFailure()

	/**
	 * Look up the organization to export directly by UUID
	 *
	 * @param ObjectServiceInterface $objectService    The object service
	 * @param array                  $voorzConfig      The voorzieningen configuration
	 * @param string                 $organizationUuid The organization UUID
	 *
	 * @return array{0: mixed, 1: mixed} The organization object, or null when it is missing or
	 *                                   the lookup fails, and the organization register id
	 *
	 * @throws \RuntimeException When the organization register or schema is not configured
	 */
	private function findExportOrganisation(ObjectServiceInterface $objectService, array $voorzConfig, string $organizationUuid): array {
		[$orgRegisterId, $orgSchemaId] = $this->resolveOrgRegisterAndSchema(voorzConfig: $voorzConfig);
		if ($orgRegisterId === null || $orgSchemaId === null) {
			throw new \RuntimeException('Organization register/schema not configured');
		}

		// Look up the organization directly by UUID.
		try {
			$orgEntity = $objectService->find(
				id: $organizationUuid,
				register: $orgRegisterId,
				schema: $orgSchemaId,
				_rbac: false,
				_multitenancy: false
			);
		} catch (\Exception $e) {
			$orgEntity = null;
		}

		return [$orgEntity, $orgRegisterId];
	}//end findExportOrganisation()

	/**
	 * The AMEF register id
	 *
	 * @return int The AMEF register id
	 *
	 * @throws \RuntimeException When the AMEF register is not configured
	 */
	private function requireAmefRegisterId(): int {
		$registerId = $this->getAmefRegisterId();
		if ($registerId === null) {
			throw new \RuntimeException('AMEF register ID is not configured');
		}

		return $registerId;
	}//end requireAmefRegisterId()

	/**
	 * The organization's gebruik, modules and deelname gebruik from the Voorzieningen register
	 *
	 * @param ObjectServiceInterface $objectService The object service
	 * @param array                  $voorzConfig   The voorzieningen configuration
	 * @param array                  $orgScope      The organization register id ('register') and UUID ('organisation')
	 * @param array                  $options       The export options; 'deelnames' adds the deelname gebruik
	 *
	 * @return array{0: array, 1: array, 2: array} The gebruik, the modules and the deelname gebruik
	 */
	private function collectOrgExportData(
		ObjectServiceInterface $objectService,
		array $voorzConfig,
		array $orgScope,
		array $options
	): array {
		$gebruikSchemaId = null;
		if (empty($voorzConfig['gebruik_schema']) === false) {
			$gebruikSchemaId = $voorzConfig['gebruik_schema'];
		}

		$moduleSchemaId = null;
		if (empty($voorzConfig['module_schema']) === false) {
			$moduleSchemaId = $voorzConfig['module_schema'];
		}

		$gebruikData = $this->searchOrgObjects(objectService: $objectService, orgScope: $orgScope, schemaId: $gebruikSchemaId);
		$modulesData = $this->searchOrgObjects(objectService: $objectService, orgScope: $orgScope, schemaId: $moduleSchemaId);

		// Query deelname gebruik if enabled (gebruik objects where this org is in deelnemers).
		$deelnamesData = [];
		if (empty($options['deelnames']) === false && empty($gebruikSchemaId) === false) {
			$deelnamesData = $this->searchDeelnames(objectService: $objectService, orgScope: $orgScope, gebruikSchemaId: $gebruikSchemaId);

			// Deelname modules belong to other orgs, so query all modules (without org filter).
			// to resolve names for the export.
			if (empty($deelnamesData) === false && $moduleSchemaId === true) {
				$modulesData = $this->mergeAllModules(
					objectService: $objectService,
					query: ['@self' => ['register' => $orgScope['register'], 'schema' => $moduleSchemaId], '_limit' => 10000],
					modulesData: $modulesData
				);
			}
		}

		return [$gebruikData, $modulesData, $deelnamesData];
	}//end collectOrgExportData()

	/**
	 * The objects of one schema owned by the organization, without RBAC or multitenancy
	 *
	 * @param ObjectServiceInterface $objectService The object service
	 * @param array                  $orgScope      The organization register id ('register') and UUID ('organisation')
	 * @param mixed                  $schemaId      The schema id; none means no objects
	 *
	 * @return array The objects found
	 */
	private function searchOrgObjects(ObjectServiceInterface $objectService, array $orgScope, mixed $schemaId): array {
		if (empty($schemaId) === true) {
			return [];
		}

		$query = [
			'@self' => [
				'register' => $orgScope['register'],
				'schema' => $schemaId,
				'organisation' => $orgScope['organisation'],
			],
			'_limit' => 10000,
		];
		return $objectService->searchObjects(query: $query, _rbac: false, _multitenancy: false);
	}//end searchOrgObjects()

	/**
	 * The gebruik objects that list the organization as a participant
	 *
	 * @param ObjectServiceInterface $objectService   The object service
	 * @param array                  $orgScope        The organization register id ('register') and UUID ('organisation')
	 * @param mixed                  $gebruikSchemaId The gebruik schema id
	 *
	 * @return array The deelname gebruik
	 */
	private function searchDeelnames(ObjectServiceInterface $objectService, array $orgScope, mixed $gebruikSchemaId): array {
		$deelnameQuery = [
			'@self' => [
				'register' => $orgScope['register'],
				'schema' => $gebruikSchemaId,
			],
			'participants' => $orgScope['organisation'],
			'_limit' => 10000,
		];
		$deelnamesData = $objectService->searchObjects(
			query: $deelnameQuery,
			_rbac: false,
			_multitenancy: false
		);
		$this->logger->info(
			'Retrieved deelname gebruik for org export',
			[
				'deelnames_count' => count($deelnamesData),
				'organization_uuid' => $orgScope['organisation'],
			]
		);

		return $deelnamesData;
	}//end searchDeelnames()

	/**
	 * The modules found plus every module of the register, deduplicated by id
	 *
	 * @param ObjectServiceInterface $objectService The object service
	 * @param array                  $query         The query for all modules
	 * @param array                  $modulesData   The modules found so far
	 *
	 * @return array The merged modules
	 */
	private function mergeAllModules(ObjectServiceInterface $objectService, array $query, array $modulesData): array {
		$allModules = $objectService->searchObjects(
			query: $query,
			_rbac: false,
			_multitenancy: false
		);
		// Merge into modulesData, deduplicating by ID.
		$existingIds = [];
		foreach ($modulesData as $m) {
			$mid = null;
			if (is_array($m) === true) {
				$mid = $m['id'] ?? $m['@self']['id'] ?? null;
			}

			if (empty($mid) === false) {
				$existingIds[$mid] = true;
			}
		}

		foreach ($allModules as $mod) {
			$modArr = $mod;
			if (is_object($mod) === true && method_exists($mod, 'jsonSerialize') === true) {
				$modArr = $mod->jsonSerialize();
			}

			$modId = $modArr['id'] ?? $modArr['@self']['id'] ?? null;
			if ($modId !== false && isset($existingIds[$modId]) === false) {
				$modulesData[] = $mod;
				$existingIds[$modId] = true;
			}
		}

		return $modulesData;
	}//end mergeAllModules()

	/**
	 * Resolves the organisation register + schema id pair for an org-scoped export.
	 *
	 * First tries the dedicated keys on the voorzieningen config; falls back to
	 * the generic settings lookups when either key is empty. Returns a pair where
	 * either element can be null if no resolution succeeded — caller is expected
	 * to early-throw in that case.
	 *
	 * Extracted from {@see exportOrgArchiMate()} as part of task 4.4 to replace
	 * the 4-branch if/else block with a single helper.
	 *
	 * @param array<string, mixed> $voorzConfig Settings → voorzieningen block.
	 *
	 * @return array{0: int|string|null, 1: int|string|null}
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md#task-4
	 */
	private function resolveOrgRegisterAndSchema(array $voorzConfig): array {
		$register = null;
		if (empty($voorzConfig['register']) === false) {
			$register = $voorzConfig['register'];
		}

		$schema = null;
		if (empty($voorzConfig['organisatie_schema']) === false) {
			$schema = $voorzConfig['organisatie_schema'];
		}

		if (empty($register) === true || empty($schema) === true) {
			$register = $this->settingsService->getVoorzieningenRegisterId();
			$schema = $this->settingsService->getSchemaIdForObjectType('organization');
		}

		return [$register, $schema];
	}//end resolveOrgRegisterAndSchema()

	/**
	 * Create schema ID mapping for export service
	 *
	 * @return array Mapping of schema IDs to schema types
	 */
	private function createSchemaIdMap(): array {
		$schemaTypes = ['model', 'element', 'relationship', 'view', 'organization', 'property_definition'];
		$schemaIdMap = [];

		foreach ($schemaTypes as $schemaType) {
			$schemaId = $this->settingsService->getSchemaIdForObjectType($schemaType);
			if (empty($schemaId) === false) {
				$schemaIdMap[$schemaId] = $schemaType;
			}
		}

		return $schemaIdMap;
	}//end createSchemaIdMap()

	/**
	 * Get ObjectService from container
	 *
	 * @return ObjectServiceInterface|null ObjectService instance or null if not available
	 */
	private function getObjectService(): ?ObjectServiceInterface {
		return (new ArchiMate\ObjectServiceLookup(appManager: $this->appManager, container: $this->container, logger: $this->logger))->find();
	}//end getObjectService()

	/**
	 * NOTE: Removed deprecated methods getArchiMateRegisterId() and getArchiMateModelSchemaId()
	 * that had hardcoded fallbacks. All configuration is now retrieved via SettingsService
	 * through the initializeCache() method and proper AMEF configuration.
	 */

	/**
	 * Get AMEF configuration from app config
	 *
	 * @return array AMEF configuration
	 * @spec   openspec/specs/archimate-import/spec.md
	 */
	public function getAmefConfig(): array {
		return (new ArchiMate\AmefConfigReader(config: $this->config, logger: $this->logger))->read();
	}//end getAmefConfig()

	/**
	 * Get the current status of ArchiMate operations
	 *
	 * @return array Status information including import/export status and object counts
	 * @spec   openspec/specs/archimate-import/spec.md
	 */
	public function getArchiMateStatus(): array {
		$this->logger->info('Getting ArchiMate status');

		try {
			// Get basic status information.
			$objectService = $this->getObjectService();
			if ($objectService === null) {
				return [
					'success' => false,
					'error' => 'ObjectService not available',
				];
			}

			// Get object counts using the proper getter methods.
			$elementObjects = $this->getElementObjects();
			$organizationObjects = $this->getOrganizationObjects();
			$viewObjects = $this->getViewObjects();
			$relationshipObjects = $this->getRelationshipObjects();
			$modelObjects = $this->getModelObjects();
			$propertyObjects = $this->getPropertyObjects();
			$propertyDefinitionObjects = $this->getPropertyDefinitionObjects();

			// Calculate totals.
			$elemCount = count($elementObjects) + count($organizationObjects);
			$viewRelCount = count($viewObjects) + count($relationshipObjects);
			$modelPropCount = count($modelObjects) + count($propertyObjects);
			$totalCount = $elemCount + $viewRelCount + $modelPropCount + count($propertyDefinitionObjects);

			return [
				'success' => true,
				'status' => 'ready',
				'model_count' => count($modelObjects),
				'total_objects' => $totalCount,
				'element_count' => count($elementObjects),
				'organization_count' => count($organizationObjects),
				'view_count' => count($viewObjects),
				'relationship_count' => count($relationshipObjects),
				'property_count' => count($propertyObjects),
				'property_definition_count' => count($propertyDefinitionObjects),
			];
		} catch (\Exception $e) {
			$this->logger->error(
				'Failed to get ArchiMate status',
				[
					'error' => $e->getMessage(),
				]
			);

			return [
				'success' => false,
				'error' => $e->getMessage(),
			];
		}//end try
	}//end getArchiMateStatus()

	/**
	 * Get AMEF register ID from configuration
	 *
	 * @return int|null The register ID or null if not configured
	 */
	private function getAmefRegisterId(): ?int {
		// Retrieve AMEF configuration (use SettingsService for consistency).
		return (new ArchiMate\AmefRegisterId(config: $this->config))->resolve(amefConfig: $this->settingsService->getAmefConfig());
	}//end getAmefRegisterId()

	/**
	 * Get element objects from the database
	 *
	 * @param array $query Query parameters
	 *
	 * @return array Array of element objects
	 */
	public function getElementObjects(array $query = []): array {
		return $this->getObjectsWithPagination(schemaType: 'element', query: $query);
	}//end getElementObjects()

	/**
	 * Get organization objects from the database
	 *
	 * @param array $query Query parameters
	 *
	 * @return array Array of organization objects
	 */
	public function getOrganizationObjects(array $query = []): array {
		return $this->getObjectsWithPagination(schemaType: 'organization', query: $query);
	}//end getOrganizationObjects()

	/**
	 * Get view objects from the database
	 *
	 * @param array $query Query parameters
	 *
	 * @return array Array of view objects
	 */
	public function getViewObjects(array $query = []): array {
		return $this->getObjectsWithPagination(schemaType: 'view', query: $query);
	}//end getViewObjects()

	/**
	 * Get relationship objects from the database
	 *
	 * @param array $query Query parameters
	 *
	 * @return array Array of relationship objects
	 */
	public function getRelationshipObjects(array $query = []): array {
		return $this->getObjectsWithPagination(schemaType: 'relationship', query: $query);
	}//end getRelationshipObjects()

	/**
	 * Get model objects from the database
	 *
	 * @param array $query Query parameters
	 *
	 * @return array Array of model objects
	 */
	public function getModelObjects(array $query = []): array {
		return $this->getObjectsWithPagination(schemaType: 'model', query: $query);
	}//end getModelObjects()

	/**
	 * Get property objects from the database
	 *
	 * @param array $query Query parameters
	 *
	 * @return array Array of property objects
	 */
	public function getPropertyObjects(array $query = []): array {
		return $this->getObjectsWithPagination(schemaType: 'property', query: $query);
	}//end getPropertyObjects()

	/**
	 * Get property definition objects from the database
	 *
	 * @param array $query Query parameters
	 *
	 * @return array Array of property definition objects
	 */
	public function getPropertyDefinitionObjects(array $query = []): array {
		return $this->getObjectsWithPagination(schemaType: 'property_definition', query: $query);
	}//end getPropertyDefinitionObjects()

	/**
	 * Get objects with pagination support for a specific schema type
	 *
	 * @param string $schemaType The schema type to retrieve objects for
	 * @param array $query Optional query criteria and pagination parameters
	 *
	 * @return array Array of objects matching the criteria
	 */
	private function getObjectsWithPagination(string $schemaType, array $query = []): array {
		try {
			$objectService = $this->getObjectService();
			if ($objectService === null) {
				$this->logger->error("ArchiMateService: ObjectService not available for {$schemaType} objects retrieval");
				return [];
			}

			$ids = $this->registerAndSchemaFor(schemaType: $schemaType);
			if ($ids === null) {
				return [];
			}

			[$registerId, $schemaId] = $ids;
			$usePagination = $query['use_pagination'] ?? false;
			$finalQuery = $this->paginatedQuery(registerId: $registerId, schemaId: $schemaId, query: $query);

			$paginationValue = 'disabled';
			if ($usePagination === true) {
				$paginationValue = 'enabled';
			}

			$this->logger->debug(
				"ArchiMateService: Retrieving {$schemaType} objects",
				[
					'register' => $registerId,
					'schema' => $schemaId,
					'query' => $finalQuery,
					'pagination' => $paginationValue,
				]
			);

			// Use searchObjects method for filtering.
			$objects = $objectService->searchObjects($finalQuery);

			$this->logger->debug(
				"ArchiMateService: Retrieved {$schemaType} objects",
				[
					'register' => $registerId,
					'schema' => $schemaId,
					'count' => count($objects),
					'pagination' => $paginationValue,
				]
			);

			return $objects;
		} catch (\Exception $e) {
			$this->logger->error(
				"ArchiMateService: Failed to retrieve {$schemaType} objects",
				[
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				]
			);

			return [];
		}//end try
	}//end getObjectsWithPagination()

	/**
	 * The register and schema ids for an object type, or null (logged) when either is not configured.
	 *
	 * AMEF object types use the single AMEF register, other types their own register.
	 *
	 * @param string $schemaType The schema type
	 *
	 * @return array{0: mixed, 1: mixed}|null
	 */
	private function registerAndSchemaFor(string $schemaType): ?array {
		$amefObjectTypes = [
			'model',
			'element',
			'relationship',
			'view',
			'property_definition',
			'organization',
			'property',
		];
		$isAmefType = in_array($schemaType, $amefObjectTypes, true) === true;

		// Use AMEF register ID for AMEF types, otherwise use per-type register ID.
		$registerId = $this->settingsService->getRegisterIdForObjectType($schemaType);
		if ($isAmefType === true) {
			$registerId = $this->getAmefRegisterId();
		}

		$schemaId = $this->settingsService->getSchemaIdForObjectType($schemaType);

		if ($registerId !== null && $schemaId !== null) {
			return [$registerId, $schemaId];
		}

		$errorMessage = "ArchiMateService: Register or {$schemaType} schema not configured";
		if ($isAmefType === true) {
			$errorMessage = "ArchiMateService: AMEF register or {$schemaType} schema not configured";
		}

		$this->logger->error(
			$errorMessage,
			[
				'registerId' => $registerId,
				'schemaId' => $schemaId,
				'isAmefType' => $isAmefType,
				'schemaType' => $schemaType,
			]
		);
		return null;
	}//end registerAndSchemaFor()

	/**
	 * The search query for one register and schema: the caller's filters,
	 * an optional pagination block and the bound the query layer reads.
	 *
	 * @param mixed $registerId The register id
	 * @param mixed $schemaId   The schema id
	 * @param array $query      Query criteria plus `limit`, `offset` and `use_pagination`
	 *
	 * @return array The query for searchObjects()
	 */
	private function paginatedQuery(mixed $registerId, mixed $schemaId, array $query): array {
		// Extract pagination parameters.
		$limit = $query['limit'] ?? 1000;
		// Default limit for large datasets.
		$offset = $query['offset'] ?? 0;
		$usePagination = $query['use_pagination'] ?? false;

		// Remove pagination parameters from query.
		unset($query['limit'], $query['offset'], $query['use_pagination']);

		// Build base query for register and schema, merged with the provided query.
		$finalQuery = array_merge_recursive(
			[
				'@self' => [
					'register' => (int)$registerId,
					'schema' => (int)$schemaId,
				],
			],
			$query
		);

		// Add pagination if requested.
		if ($usePagination !== false && $limit > 0) {
			$finalQuery['@pagination'] = [
				'limit' => (int)$limit,
				'offset' => (int)$offset,
			];
		}

		// Always bound the actual query: OpenRegister's searchObjects()
		// only reads the top-level `_limit`/`_offset` keys (see
		// MagicMapper/MagicSearchHandler) — `@pagination` above is not
		// read by the query layer, so this call was unbounded regardless
		// of the `usePagination` toggle. `$limit` already defaults to
		// 1000 above, so this is a straight bound, not a behavior change.
		$finalQuery['_limit'] = (int)$limit;
		if ($usePagination !== false) {
			$finalQuery['_offset'] = (int)$offset;
		}

		return $finalQuery;
	}//end paginatedQuery()

	/**
	 * Check if import is in progress
	 *
	 * @return bool True if import is in progress
	 * @spec   openspec/specs/archimate-import/spec.md
	 */
	public function isImportInProgress(): bool {
		// For now, return false as we haven't implemented status tracking yet.
		return false;
	}//end isImportInProgress()

	/**
	 * Check if export is in progress
	 *
	 * @return bool True if export is in progress
	 * @spec   openspec/specs/archimate-import/spec.md
	 */
	public function isExportInProgress(): bool {
		// For now, return false as we haven't implemented status tracking yet.
		return false;
	}//end isExportInProgress()

	/**
	 * Convert property names with spaces to camelCase for better database compatibility
	 *
	 * Examples:
	 * - "Object ID" -> "objectId"
	 * - "Business Unit" -> "businessUnit"
	 * - "System Name" -> "systemName"
	 *
	 * @param string $propertyName Property name that may contain spaces
	 *
	 * @return string CamelCase version of the property name
	 */
	private function convertToCamelCase(string $propertyName): string {
		$this->camelCaseNames ??= new ArchiMate\CamelCaseNames();
		return $this->camelCaseNames->convertName(propertyName: $propertyName);
	}//end convertToCamelCase()

	/**
	 * Get property mapping information for debugging and reference
	 *
	 * This method returns a mapping of original property names to their camelCase equivalents
	 * which can be useful for understanding how properties are being processed.
	 *
	 * @param array $propDefMap The original property definition map
	 *
	 * @return array Mapping of original names to camelCase names
	 * @spec   openspec/specs/archimate-import/spec.md
	 */
	public function getPropertyNameMapping(array $propDefMap): array {
		$mapping = [];

		foreach ($propDefMap as $propertyRef => $originalName) {
			$mapping[$originalName] = $this->convertToCamelCase(propertyName: $originalName);
		}

		return $mapping;
	}//end getPropertyNameMapping()
}//end class
