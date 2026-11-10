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
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Service\ObjectService;
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
class ArchiMateService {
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
	 * Cache for camelCase property name conversions to avoid redundant processing
	 *
	 * @var array<string, string>
	 */
	private array $camelCaseCache = [];

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
	 * @param SettingsService $settingsService Settings service for schema and organization configuration
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
		private readonly SettingsService $settingsService,
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
			$this->logger->error(
				'ArchiMate export failed',
				[
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				]
			);

			return [
				'success' => false,
				'error' => $e->getMessage(),
			];
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
			[$orgRegisterId, $orgSchemaId] = $this->resolveOrgRegisterAndSchema(voorzConfig: $voorzConfig);
			if ($orgRegisterId === null || $orgSchemaId === null) {
				throw new \RuntimeException('Organization register/schema not configured');
			}

			// Look up the organization directly by UUID.
			try {
				$orgEntity = $objectService->find(
					id: $organizationUuid,
					options: new ObjectFindOptions(register: $orgRegisterId, schema: $orgSchemaId),
					_rbac: false,
					_multitenancy: false
				);
			} catch (\Exception $e) {
				$orgEntity = null;
			}

			if ($orgEntity === null) {
				return [
					'success' => false,
					'error' => 'Organization not found: ' . $organizationUuid,
				];
			}

			$organization = $orgEntity->jsonSerialize();
			$orgName = $organization['name'] ?? $organization['@self']['name'] ?? 'Unknown';

			// Get AMEF config and base objects.
			$registerId = $this->getAmefRegisterId();
			if ($registerId === null) {
				throw new \RuntimeException('AMEF register ID is not configured');
			}

			$schemaIdMap = $this->createSchemaIdMap();

			// Query organization's gebruik and modules from Voorzieningen register.
			if (empty($voorzConfig['gebruik_schema']) === false) {
				$gebruikSchemaId = $voorzConfig['gebruik_schema'];
			} else {
				$gebruikSchemaId = null;
			}

			$gebruikData = [];
			if (empty($gebruikSchemaId) === false) {
				$gebruikQuery = [
					'@self' => [
						'register' => $orgRegisterId,
						'schema' => $gebruikSchemaId,
						'organisation' => $organizationUuid,
					],
					'_limit' => 10000,
				];
				$gebruikData = $objectService->searchObjects(query: $gebruikQuery, _rbac: false, _multitenancy: false);
			}

			if (empty($voorzConfig['module_schema']) === false) {
				$moduleSchemaId = $voorzConfig['module_schema'];
			} else {
				$moduleSchemaId = null;
			}

			$modulesData = [];
			if (empty($moduleSchemaId) === false) {
				$modulesQuery = [
					'@self' => [
						'register' => $orgRegisterId,
						'schema' => $moduleSchemaId,
						'organisation' => $organizationUuid,
					],
					'_limit' => 10000,
				];
				$modulesData = $objectService->searchObjects(query: $modulesQuery, _rbac: false, _multitenancy: false);
			}

			// Query deelname gebruik if enabled (gebruik objects where this org is in deelnemers).
			$deelnamesData = [];
			if ($options['deelnames'] ?? false) {
				if (empty($gebruikSchemaId) === false) {
					$deelnameQuery = [
						'@self' => [
							'register' => $orgRegisterId,
							'schema' => $gebruikSchemaId,
						],
						'participants' => $organizationUuid,
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
							'organization_uuid' => $organizationUuid,
						]
					);

					// Deelname modules belong to other orgs, so query all modules (without org filter).
					// to resolve names for the export.
					if (empty($deelnamesData) === false && $moduleSchemaId === true) {
						$allModulesQuery = [
							'@self' => [
								'register' => $orgRegisterId,
								'schema' => $moduleSchemaId,
							],
							'_limit' => 10000,
						];
						$allModules = $objectService->searchObjects(
							query: $allModulesQuery,
							_rbac: false,
							_multitenancy: false
						);
						// Merge into modulesData, deduplicating by ID.
						$existingIds = [];
						foreach ($modulesData as $m) {
							if (is_array($m) === true) {
								$mid = $m['id'] ?? $m['@self']['id'] ?? null;
							} else {
								$mid = null;
							}

							if (empty($mid) === false) {
								$existingIds[$mid] = true;
							}
						}

						foreach ($allModules as $mod) {
							if (is_object($mod) === true && method_exists($mod, 'jsonSerialize') === true) {
								$modArr = $mod->jsonSerialize();
							} else {
								$modArr = $mod;
							}

							$modId = $modArr['id'] ?? $modArr['@self']['id'] ?? null;
							if ($modId !== false && isset($existingIds[$modId]) === false) {
								$modulesData[] = $mod;
								$existingIds[$modId] = true;
							}
						}
					}//end if
				}//end if
			}//end if

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
			$this->logger->error(
				'Organization ArchiMate export failed',
				[
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				]
			);

			return [
				'success' => false,
				'error' => $e->getMessage(),
			];
		}//end try
	}//end exportOrgArchiMate()

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
		if ($this->appManager->isInstalled(appId: 'openregister') === false) {
			return null;
		}

		try {
			return $this->container->get(ObjectService::class);
		} catch (\Exception $e) {
			$this->logger->warning(
				'Failed to get ObjectService',
				[
					'error' => $e->getMessage(),
				]
			);
			return null;
		}
	}//end getObjectService()

	/**
	 * NOTE: Removed deprecated methods getArchiMateRegisterId() and getArchiMateModelSchemaId()
	 * that had hardcoded fallbacks. All configuration is now retrieved via SettingsService
	 * through the initializeCache() method and proper AMEF configuration.
	 */

	/**
	 * Read a configured id, failing closed on the empty default.
	 *
	 * The legacy fallback used to read every id with `''` as its default, so
	 * an unconfigured instance produced a config array full of empty STRINGS.
	 * Its consumers guard with `=== null` — `ViewService::getViews()` and
	 * `getView()` throw only when a value `=== null` — and `'' === null` is
	 * false. Today nothing reaches a query only because that fallback writes
	 * PLURAL key names (`views_schema`) while every consumer reads SINGULAR
	 * ones (`view_schema`), so the lookups miss and fall back to `null`. That
	 * is an accident of naming, not a defence: adding the singular keys — the
	 * obvious "cleanup" — would send `register => ''` straight into
	 * `searchObjects()` as an UNPINNED query, and an unpinned query returns
	 * rows, which reads exactly like a correct result.
	 *
	 * Returning null instead of `''` makes `?? null` downstream yield null,
	 * which is what every consumer already checks for, and the warning names
	 * the missing key so a misconfigured import stops reporting "0 objects"
	 * with no explanation.
	 *
	 * @param string $key The app-config key holding the id.
	 *
	 * @return string|null The configured id, or null when it is unset.
	 *
	 * @spec openspec/specs/archimate-import/spec.md
	 */
	private function resolveConfiguredId(string $key): ?string {
		$value = $this->config->getValueString('stackiq', $key, '');
		if (trim($value) === '') {
			$this->logger->warning(
				'ArchiMate configuration is incomplete — this id is not configured, so it is omitted rather than passed on as an empty string',
				['key' => $key]
			);

			return null;
		}

		return $value;
	}//end resolveConfiguredId()

	/**
	 * Get AMEF configuration from app config
	 *
	 * @return array AMEF configuration
	 * @spec   openspec/specs/archimate-import/spec.md
	 */
	public function getAmefConfig(): array {
		$this->logger->info('Getting AMEF configuration');

		try {
			// Get configuration from app config using the correct method.
			$config = $this->config->getValueString('stackiq', 'amef_config', '{}');
			$decoded = json_decode($config, true);

			if (is_array($decoded) === false) {
				// Fallback to individual config values for backward
				// compatibility. Every id is read through
				// resolveConfiguredId(), which guards the empty default at the
				// point of the read and omits the key entirely when it is
				// unset — so `?? null` downstream yields null, which is what
				// the consumers already check for.
				$decoded = array_filter(
					[
						'register_id' => $this->resolveConfiguredId(key: 'amef_register'),
						'model_schema_id' => $this->resolveConfiguredId(key: 'amef_model_schema'),
						'elements_schema' => $this->resolveConfiguredId(key: 'amef_elements_schema'),
						'relationships_schema' => $this->resolveConfiguredId(key: 'amef_relationships_schema'),
						'views_schema' => $this->resolveConfiguredId(key: 'amef_views_schema'),
						'organizations_schema' => $this->resolveConfiguredId(key: 'amef_organizations_schema'),
						'folders_schema' => $this->resolveConfiguredId(key: 'amef_folders_schema'),
						'property_definitions_schema' => $this->resolveConfiguredId(key: 'amef_property_definitions_schema'),
					],
					static function ($value) {
						return $value !== null;
					}
				);
			}//end if

			return $decoded;
		} catch (\Exception $e) {
			$this->logger->error(
				'Failed to get AMEF configuration',
				[
					'error' => $e->getMessage(),
				]
			);

			return [
				'success' => false,
				'error' => $e->getMessage(),
			];
		}//end try
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
		$amefConfig = $this->settingsService->getAmefConfig();

		// Try JSON config keys first: support both 'register_id' and 'register'.
		$rawRegisterId = $amefConfig['register_id'] ?? $amefConfig['register'] ?? null;

		// Fallback to legacy individual app config keys if not present in JSON.
		if ($rawRegisterId === null || $rawRegisterId === '') {
			$rawRegisterId = $this->config->getValueString('stackiq', 'amef_register_id', '');
			if ($this->config->getValueString('stackiq', 'amef_register', '') !== '') {
				// If only the plain register key is configured, use it as the ID.
				$rawRegisterId = $this->config->getValueString('stackiq', 'amef_register', '');
			}
		}

		// Validate and normalize to positive int.
		if ($rawRegisterId !== '' && is_numeric((string)$rawRegisterId) === true) {
			$registerId = (int)$rawRegisterId;
			if ($registerId > 0) {
				return $registerId;
			}
		}

		return null;
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

			// AMEF object types use a single register ID, not per-type register IDs.
			// Check if this is an AMEF object type.
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

			if ($registerId === null || $schemaId === null) {
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
				return [];
			}

			// Extract pagination parameters.
			$limit = $query['limit'] ?? 1000;
			// Default limit for large datasets.
			$offset = $query['offset'] ?? 0;
			$usePagination = $query['use_pagination'] ?? false;

			// Remove pagination parameters from query.
			unset($query['limit'], $query['offset'], $query['use_pagination']);

			// Build base query for register and schema.
			$baseQuery = [
				'@self' => [
					'register' => (int)$registerId,
					'schema' => (int)$schemaId,
				],
			];

			// Merge with provided query.
			$finalQuery = array_merge_recursive($baseQuery, $query);

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

			$paginationValue = 'disabled';
			if ($usePagination === true) {
				$paginationValue = 'enabled';
			}

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
		// OPTIMIZATION: Check cache first to avoid redundant conversions.
		if (isset($this->camelCaseCache[$propertyName]) === true) {
			return $this->camelCaseCache[$propertyName];
		}

		// Remove any leading/trailing whitespace.
		$propertyName = trim($propertyName);

		// Split by spaces and convert to camelCase.
		$words = explode(' ', $propertyName);

		if (count($words) === 1) {
			// Single word, just lowercase it.
			$result = strtolower($words[0]);
		} else {
			// First word is lowercase, subsequent words are capitalized.
			$camelCase = strtolower($words[0]);

			for ($i = 1; $i < count($words); $i++) {
				$camelCase .= ucfirst(strtolower($words[$i]));
			}

			$result = $camelCase;
		}

		// OPTIMIZATION: Cache the result for future use.
		$this->camelCaseCache[$propertyName] = $result;

		return $result;
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
