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
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use OCP\Files\IRootFolder;
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
	 * Cache for identifier extraction patterns by section type
	 *
	 * @var array<string, array>
	 */
	private array $identifierPatternCache = [];

	/**
	 * Cache for property definition maps to avoid rebuilding during import
	 *
	 * @var array|null
	 */
	private ?array $propDefMapCache = null;

	/**
	 * Flag to track if we've already logged finding a GEMMA type property
	 *
	 * @var boolean
	 */
	private bool $gemmaTypePropertyFound = false;
	private const CONFIG_KEYS = [
		'archimate_register_id' => 'archimate_register_id',
		'archimate_schema_id' => 'archimate_schema_id',
		'archimate_model_schema_id' => 'archimate_model_schema_id',
	];

	/**
	 * Performance optimization settings
	 */
	private const PERFORMANCE_OPTIMIZATIONS = [
		'disable_validation' => true,
		'disable_events' => true,
		'disable_rbac' => false,
		// Keep RBAC for security.
		'use_multi' => true,
		'xml_parse_flags' => LIBXML_NOCDATA | LIBXML_NONET,
		'memory_cleanup' => true,
		'parallel_processing' => true,
		'batch_size' => 1000,
		// Default batch size (will be adjusted intelligently).
		'parallel_batches' => 8,
		// Process 8 batches concurrently.
		'max_batch_size_bytes' => 8388608,
		// 8 MB - safe under MySQL's 16 MB limit.
		'min_batch_size' => 50,
		// Minimum batch size for very large objects.
		'size_estimation_sample' => 10,
		// Sample size for estimating object sizes.
	];

	/**
	 * NOTE: Default schema IDs removed - all schema IDs must be configured via AMEF settings.
	 * The system will fail gracefully with clear error messages if configuration is missing.
	 */

	/**
	 * Storage for the last save operation results.
	 * Contains the structured return from ObjectService::saveObjects.
	 *
	 * @var array|null
	 */
	private ?array $lastSaveResult = null;

	/**
	 * Cached configuration values for performance optimization.
	 *
	 * @var array|null
	 */
	private ?array $cachedConfig = null;

	/**
	 * Constructor for ArchiMateService
	 *
	 * @param IAppConfig $config Nextcloud app configuration service
	 * @param IRootFolder $rootFolder Root folder service
	 * @param IUserSession $userSession User session service
	 * @param IAppManager $appManager App manager service
	 * @param ContainerInterface $container PSR-11 container interface
	 * @param LoggerInterface $logger Logger service
	 * @param SettingsService $settingsService Settings service for schema and organization configuration
	 * @param ArchiMateImportService $importService Import service for XML parsing
	 * @param ArchiMateExportService $exportService Export service for XML generation
	 */
	public function __construct(
		private readonly IAppConfig $config,
		private readonly IRootFolder $rootFolder,
		private readonly IUserSession $userSession,
		private readonly IAppManager $appManager,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly SettingsService $settingsService,
		private readonly ArchiMateImportService $importService,
		private readonly ArchiMateExportService $exportService,
	) {
	}//end __construct()

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
					register: $orgRegisterId,
					schema: $orgSchemaId,
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
	 * Get section structure configuration for XML parsing
	 *
	 * @param string $sectionName The name of the section (e.g., 'elements', 'relationships', 'views', etc.)
	 *
	 * @return array Configuration with direct_tags and nested_paths for finding items
	 */
	private function getSectionStructureConfig(string $sectionName): array {
		// Define the structure configuration for each section type.
		$configs = [
			'elements' => [
				'direct_tags' => ['element', 'elements'],
				'nested_paths' => [
					['model', 'elements', 'element'],
					['model', 'elements'],
					['elements', 'element'],
					['elements'],
				],
			],
			'relationships' => [
				'direct_tags' => ['relationship', 'relationships'],
				'nested_paths' => [
					['model', 'relationships', 'relationship'],
					['model', 'relationships'],
					['relationships', 'relationship'],
					['relationships'],
				],
			],
			'views' => [
				'direct_tags' => ['view', 'views', 'diagram', 'diagrams'],
				'nested_paths' => [
					['model', 'views', 'diagrams', 'view'],
					['model', 'views', 'diagrams'],
					['model', 'views'],
					['views', 'diagrams', 'view'],
					['views', 'diagrams'],
					['views'],
				],
			],
			'organizations' => [
				'direct_tags' => ['item', 'items'],
				'nested_paths' => [
					['model', 'organizations', 'item'],
					['model', 'organizations'],
					['organizations', 'item'],
					['organizations'],
				],
			],
			'property_definitions' => [
				'direct_tags' => ['propertyDefinition', 'propertyDefinitions'],
				'nested_paths' => [
					['model', 'propertyDefinitions', 'propertyDefinition'],
					['model', 'propertyDefinitions'],
					['propertyDefinitions', 'propertyDefinition'],
					['propertyDefinitions'],
				],
			],
		];

		return $configs[$sectionName] ?? [
			'direct_tags' => [$sectionName],
			'nested_paths' => [[$sectionName]],
		];
	}//end getSectionStructureConfig()

	/**
	 * Check if an array is associative (has string keys)
	 *
	 * @param array $array The array to check
	 *
	 * @return bool True if associative, false if indexed
	 */
	private function isAssociativeArray(array $array): bool {
		return count(array_filter(array_keys($array), 'is_string')) > 0;
	}//end isAssociativeArray()

	/**
	 * Find items within a specific section using AMEF configuration
	 *
	 * @param array $sectionData The section data to search
	 * @param string $sectionName The name of the section
	 *
	 * @return array Array of items found
	 */
	private function findItemsInSection(array $sectionData, string $sectionName): array {
		// OPTIMIZATION: Removed debug logging from section processing.
		$items = [];

		// No is_array() safety check: $sectionData is declared array, so PHP
		// rejects anything else at the call boundary before this could run.

		// Get section structure configuration from AMEF config.
		$config = $this->getSectionStructureConfig(sectionName: $sectionName);

		// Special handling for views with diagrams structure.
		if ($sectionName === 'views') {
			// Handle nested structure: <views><diagrams><view>.
			if (isset($sectionData['diagrams']) === true) {
				if (isset($sectionData['diagrams']['view']) === true) {
					$viewArray = $sectionData['diagrams']['view'];

					// Handle single view vs array of views.
					if (isset($viewArray[0]) === false && isset($viewArray['_attributes']) === true) {
						// Single view.
						$items = [$viewArray];
					} else {
						// Array of views.
						$items = $viewArray;
					}
				}
			} else {
				// Direct views structure (fallback).
				if (isset($sectionData['view']) === true) {
					$items = $sectionData['view'];
				}
			}
		} else {
			// Try to find items using the configured paths for other sections.
			foreach ($config['nested_paths'] as $path) {
				$currentData = $sectionData;
				$pathValid = true;

				foreach ($path as $key) {
					if (isset($currentData[$key]) === true) {
						$currentData = $currentData[$key];
					} else {
						$pathValid = false;
						break;
					}
				}

				if ($pathValid !== false && is_array($currentData) === true) {
					// Check if this is a direct array of items or needs further processing.
					if (isset($currentData[0]) === true || $this->isAssociativeArray(array: $currentData) === true) {
						$items = $currentData;
						break;
					}
				}
			}//end foreach
		}//end if

		// If no items found through nested paths, try direct tags.
		if (empty($items) === true) {
			foreach ($config['direct_tags'] as $tag) {
				if (isset($sectionData[$tag]) === true) {
					$items = $sectionData[$tag];
					break;
				}
			}
		}

		// If still no items found, treat the section itself as items.
		if (empty($items) === true) {
			$items = [$sectionData];
		}

		// Ensure items is always an array.
		if (is_array($items) === false) {
			$items = [$items];
		}

		// If items is an associative array with numeric keys, convert to indexed array.
		if ($this->isAssociativeArray(array: $items) === true) {
			$items = array_values($items);
		}

		return $items;
	}//end findItemsInSection()

	/**
	 * Extract identifier from item data
	 *
	 * @param array $item Item data
	 * @param string $sectionName The section name for special handling
	 *
	 * @return string|null Identifier or null if not found
	 */
	private function extractIdentifier(array $item, string $sectionName = ''): ?string {
		// OPTIMIZATION: Use cached patterns for section-specific identifier extraction.
		if (isset($this->identifierPatternCache[$sectionName]) === true) {
			$patterns = $this->identifierPatternCache[$sectionName];

			// Try cached patterns in order of success frequency.
			foreach ($patterns as $pattern) {
				$result = $this->extractIdentifierByPattern(item: $item, pattern: $pattern);
				if ($result !== null) {
					return $result;
				}
			}
		}

		// OPTIMIZATION: Build pattern cache on first encounter of section type.
		$patterns = $this->buildIdentifierPatternsForSection(sectionName: $sectionName);
		$this->identifierPatternCache[$sectionName] = $patterns;

		// Try all patterns and return first successful match.
		foreach ($patterns as $pattern) {
			$result = $this->extractIdentifierByPattern(item: $item, pattern: $pattern);
			if ($result !== null) {
				return $result;
			}
		}

		return null;
	}//end extractIdentifier()

	/**
	 * OPTIMIZATION: Extract identifier using a specific pattern
	 *
	 * @param array $item The item to extract from
	 * @param array $pattern The extraction pattern ['path' => string[], 'type' => string]
	 *
	 * @return string|null The extracted identifier or null
	 */
	private function extractIdentifierByPattern(array $item, array $pattern): ?string {
		$path = $pattern['path'];
		$type = $pattern['type'];

		// Navigate to the target location.
		$current = $item;
		foreach ($path as $key) {
			if (isset($current[$key]) === false) {
				return null;
			}

			$current = $current[$key];
		}

		// Extract based on type.
		switch ($type) {
			case 'direct':
				if (is_string($current) === true) {
					return $current;
				}
				return null;
			case 'value':
				if (is_array($current) === true && isset($current['_value']) === true) {
					return $current['_value'];
				}
				return null;
			case 'array_search':
				if (is_array($current) === true) {
					foreach ($current as $childItem) {
						if (isset($childItem['_attributes']['identifierRef']) === true) {
							return (string)$childItem['_attributes']['identifierRef'];
						}
					}
				}
				return null;
			default:
				return null;
		}//end switch
	}//end extractIdentifierByPattern()

	/**
	 * OPTIMIZATION: Build identifier extraction patterns for a section type
	 *
	 * @param string $sectionName The section name
	 *
	 * @return array Array of extraction patterns ordered by likelihood of success
	 */
	private function buildIdentifierPatternsForSection(string $sectionName): array {
		$patterns = [];

		// Special handling for organizations.
		if ($sectionName === 'organizations') {
			$patterns[] = ['path' => ['_attributes', 'identifierRef'], 'type' => 'direct'];
			$patterns[] = ['path' => ['item'], 'type' => 'array_search'];
			$patterns[] = ['path' => ['label'], 'type' => 'value'];
			$patterns[] = ['path' => ['label'], 'type' => 'direct'];
		} else {
			// Standard patterns for other sections (ordered by frequency in ArchiMate).
			$patterns[] = ['path' => ['_attributes', 'identifier'], 'type' => 'direct'];
			$patterns[] = ['path' => ['_attributes', 'id'], 'type' => 'direct'];
			$patterns[] = ['path' => ['identifier'], 'type' => 'value'];
			$patterns[] = ['path' => ['identifier'], 'type' => 'direct'];
			$patterns[] = ['path' => ['id'], 'type' => 'value'];
			$patterns[] = ['path' => ['id'], 'type' => 'direct'];
			$patterns[] = ['path' => ['_attributes', 'name'], 'type' => 'direct'];
			$patterns[] = ['path' => ['name'], 'type' => 'value'];
			$patterns[] = ['path' => ['name'], 'type' => 'direct'];
		}

		return $patterns;
	}//end buildIdentifierPatternsForSection()

	/**
	 * Convert normalized data to OpenRegister objects with @self structure
	 *
	 * This method creates OpenRegister objects from the normalized ArchiMate data:
	 * 1. Creates a model object with proper @self structure
	 * 2. Creates section objects for each item (elements, relationships, etc.)
	 * 3. Ensures each object has the required @self structure for ObjectService::saveObjects
	 * 4. Links all objects to the parent model via model_identifier
	 *
	 * @param array $normalizedData Normalized ArchiMate data with model_identifier
	 * @param string $modelIdentifier The model identifier for linking objects
	 *
	 * @return array Array of OpenRegister objects with proper @self structure
	 */
	private function convertToOpenRegisterObjects(array $normalizedData, string $modelIdentifier): array {
		$this->logger->info(
			'Converting to OpenRegister objects with @self structure',
			[
				'model_identifier' => $modelIdentifier,
			]
		);

		$objects = [];

		// STEP 1: Convert model metadata to model object.
		if (empty($normalizedData['model_metadata']) === false) {
			$this->logger->debug('Creating model object from metadata');
			$objects[] = $this->createModelObject(
				metadata: $normalizedData['model_metadata'],
				modelIdentifier: $modelIdentifier
			);
		}

		// STEP 2: Convert each section to individual objects.
		$sections = ['elements', 'relationships', 'organizations', 'views', 'property_definitions'];

		// OPTIMIZATION: Removed excessive debug logging from tight loops.
		$sectionCounts = [];
		foreach ($sections as $section) {
			if (empty($normalizedData[$section]) === false && is_array($normalizedData[$section]) === true) {
				$sectionCounts[$section] = count($normalizedData[$section]);
				foreach ($normalizedData[$section] as $identifier => $data) {
					$objects[] = $this->createSectionObject(
						section: $section,
						identifier: $identifier,
						data: $data
					);
				}
			} else {
				$sectionCounts[$section] = 0;
			}
		}

		// Single consolidated log entry.
		$this->logger->debug('Sections processed', $sectionCounts);

		$this->logger->info(
			'Conversion to OpenRegister objects completed',
			[
				'model_identifier' => $modelIdentifier,
				'total_objects' => count($objects),
				'sections_processed' => $sections,
			]
		);

		return $objects;
	}//end convertToOpenRegisterObjects()

	/**
	 * Create model object with @self structure
	 *
	 * @param array $metadata Model metadata
	 * @param string $modelIdentifier Model identifier
	 *
	 * @return array Model object with @self structure
	 */
	private function createModelObject(array $metadata, string $modelIdentifier): array {
		// OPTIMIZATION: Use cached configuration values.
		$registerId = $this->cachedConfig['registerId'];
		$schemaId = $this->cachedConfig['schemaIds']['model'];

		// Create object with @self structure and metadata at root level (no JSON serialization).
		$object = [
			'@self' => [
				'register' => $registerId,
				'schema' => $schemaId,
				'id' => $metadata['identifier'] ?? uniqid('model_'),
				'published' => date('Y-m-d\TH:i:s\Z'),
			],
			'identifier' => $metadata['identifier'] ?? '',
			'section' => 'model',
			'model_identifier' => $modelIdentifier,
		];

		// Merge metadata directly at root level.
		return array_merge($object, $metadata);
	}//end createModelObject()

	/**
	 * Create section object with @self structure and flattened XML data
	 *
	 * @param string $section Section name
	 * @param string $identifier Item identifier
	 * @param array $data Item data (already contains XML data at root level)
	 *
	 * @return array Section object with @self structure
	 */
	private function createSectionObject(string $section, string $identifier, array $data): array {
		// OPTIMIZATION: Use cached configuration values.
		$registerId = $this->cachedConfig['registerId'];
		$schemaId = $this->cachedConfig['schemaIds'][$section] ?? $this->getSchemaIdForSection(section: $section);

		// Create object with @self structure and XML data at root level (no double serialization).
		$object = [
			'@self' => [
				'register' => $registerId,
				'schema' => $schemaId,
				'id' => $identifier,
				'published' => date('Y-m-d\TH:i:s\Z'),
			],
		];

		// Set slug: first try from _slug field, then from Object ID property, then extract from identifier.
		$slug = null;

		// Check if there's a temporary slug to move to @self structure.
		if (isset($data['_slug']) === true) {
			$slug = $data['_slug'];
			unset($data['_slug']);
		} elseif (isset($data['Object ID']) === true) {
			// Check if we have "Object ID" property directly.
			$slug = $data['Object ID'];
		} elseif (str_starts_with($identifier, 'id-') === true) {
			// Fallback: extract from identifier (remove "id-" prefix if present).
			$slug = substr($identifier, 3);
		}

		// Set the slug if we found one.
		if (empty($slug) === false) {
			$object['@self']['slug'] = $slug;
		}

		// Merge XML data directly at root level (data already contains identifier, section, model_identifier).
		return array_merge($object, $data);
	}//end createSectionObject()

	/**
	 * Save objects to database using ObjectService::saveObjects
	 *
	 * @param array $objects Objects to save
	 *
	 * @return array Saved objects
	 */
	private function saveObjectsToDatabase(array $objects): array {
		$saveStartTime = microtime(true);

		$serviceInitStartTime = microtime(true);
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			throw new \RuntimeException('ObjectService not available');
		}

		$serviceInitTime = microtime(true) - $serviceInitStartTime;

		// ENHANCEMENT: Process GEMMA Referentiecomponent-Standaard relationships before saving.
		$gemmaProcessingStartTime = microtime(true);
		$objects = $this->processGemmaReferenceComponentStandards(objects: $objects);
		$gemmaProcessingTime = microtime(true) - $gemmaProcessingStartTime;

		$this->logger->info(
			'Saving objects to database using parallel batch processing',
			[
				'count' => count($objects),
				'batch_size' => self::PERFORMANCE_OPTIMIZATIONS['batch_size'],
				'parallel_batches' => self::PERFORMANCE_OPTIMIZATIONS['parallel_batches'],
				'service_init_time' => round($serviceInitTime, 3),
				'gemma_processing_time' => round($gemmaProcessingTime, 3),
			]
		);

		// OPTIMIZATION: Use cached register ID.
		$registerId = $this->cachedConfig['registerId'];

		// PERFORMANCE OPTIMIZATION: Use parallel batch processing for large datasets.
		$batchProcessingStartTime = microtime(true);
		// PERFORMANCE_OPTIMIZATIONS['parallel_processing'] is a class constant set
		// to true, so only the batch-size threshold decides this.
		if (count($objects) > self::PERFORMANCE_OPTIMIZATIONS['batch_size']) {
			$result = $this->saveObjectsInParallelBatches(
				objects: $objects,
				objectService: $objectService,
				registerId: $registerId
			);
		} else {
			// Fallback to single batch for small datasets.
			$result = $this->saveObjectsInSingleBatch(
				objects: $objects,
				objectService: $objectService,
				registerId: $registerId
			);
		}

		$batchProcessingTime = microtime(true) - $batchProcessingStartTime;

		$totalSaveTime = microtime(true) - $saveStartTime;

		$this->logger->info(
			'Database save operation completed',
			[
				'total_save_time' => round($totalSaveTime, 3),
				'service_init_time' => round($serviceInitTime, 3),
				'gemma_processing_time' => round($gemmaProcessingTime, 3),
				'batch_processing_time' => round($batchProcessingTime, 3),
				'objects_saved' => count($result),
				'save_rate_objects_per_second' => round(count($objects) / max($totalSaveTime, 0.001), 1),
			]
		);

		// Store timing breakdown for performance metrics.
		$this->lastSaveTimingBreakdown = [
			'total_save_seconds' => round($totalSaveTime, 3),
			'service_init_seconds' => round($serviceInitTime, 3),
			'gemma_processing_seconds' => round($gemmaProcessingTime, 3),
			'batch_processing_seconds' => round($batchProcessingTime, 3),
			'objects_saved' => count($result),
			'save_rate_objects_per_second' => round(count($objects) / max($totalSaveTime, 0.001), 1),
		];

		return $result;
	}//end saveObjectsToDatabase()

	/**
	 * Save objects in parallel batches for maximum performance
	 *
	 * @param array $objects Array of objects to save
	 * @param ObjectServiceInterface $objectService ObjectService instance
	 * @param int $registerId Register ID
	 *
	 * @return array Array of saved objects
	 */
	private function saveObjectsInParallelBatches(array $objects, ObjectServiceInterface $objectService, int $registerId): array {
		$batchSize = self::PERFORMANCE_OPTIMIZATIONS['batch_size'];
		$parallelBatches = self::PERFORMANCE_OPTIMIZATIONS['parallel_batches'];

		// INTELLIGENT BATCH SIZING: Create size-aware batches instead of fixed-size chunks.
		$chunks = $this->createIntelligentBatches(objects: $objects);
		$totalChunks = count($chunks);

		$this->logger->info(
			'Starting intelligent batch processing',
			[
				'total_objects_to_save' => count($objects),
				'intelligent_batches_created' => $totalChunks,
				'batch_sizes' => array_map('count', $chunks),
				'batching_method' => 'size_aware_intelligent',
				'mysql_packet_limit_safe' => true,
			]
		);

		$allResults = [];
		$processedChunks = 0;

		// Accumulate statistics from all chunks.
		$aggregatedStats = [
			'saved' => [],
			'updated' => [],
			'skipped' => [],
			'invalid' => [],
		];

		// Process chunks sequentially but with larger batch sizes for better performance.
		foreach ($chunks as $chunkIndex => $chunk) {
			// OPTIMIZATION: Removed debug logging from chunk processing loop.
			try {
				if (self::PERFORMANCE_OPTIMIZATIONS['disable_rbac'] === true) {
					$rbacValue = false;
				} else {
					$rbacValue = true;
				}

				$saveResult = $objectService->saveObjects(
					objects: $chunk,
					register: $registerId,
					schema: null,
					_rbac: $rbacValue,
					validation: !self::PERFORMANCE_OPTIMIZATIONS['disable_validation'],
					events: !self::PERFORMANCE_OPTIMIZATIONS['disable_events']
				);

				// Accumulate statistics from this chunk.
				$aggregatedStats['saved'] = array_merge($aggregatedStats['saved'], $saveResult['saved'] ?? []);
				$aggregatedStats['updated'] = array_merge($aggregatedStats['updated'], $saveResult['updated'] ?? []);
				$aggregatedStats['skipped'] = array_merge($aggregatedStats['skipped'], $saveResult['skipped'] ?? []);
				$aggregatedStats['invalid'] = array_merge($aggregatedStats['invalid'], $saveResult['invalid'] ?? []);

				$savedObjects = array_merge(
					$saveResult['saved'] ?? [],
					$saveResult['updated'] ?? []
				);

				$allResults = array_merge($allResults, $savedObjects);

				$processedChunks++;
				$this->logger->info(
					'Processed chunk',
					[
						'processed_chunks' => $processedChunks,
						'total_chunks' => $totalChunks,
						'progress_percent' => round(($processedChunks / $totalChunks) * 100, 1),
						'chunk_saved' => count($saveResult['saved'] ?? []),
						'chunk_updated' => count($saveResult['updated'] ?? []),
						'chunk_skipped' => count($saveResult['skipped'] ?? []),
						'chunk_invalid' => count($saveResult['invalid'] ?? []),
					]
				);
			} catch (\Exception $e) {
				$this->logger->error(
					'Error processing chunk',
					[
						'chunk_index' => $chunkIndex,
						'error' => $e->getMessage(),
					]
				);
				// Continue with other chunks.
			}//end try

			// Memory cleanup between chunks.
			// PERFORMANCE_OPTIMIZATIONS['memory_cleanup'] is a class constant set
			// to true, so this was never conditional.
			$this->cleanupMemory();
		}//end foreach

		// Store the aggregated result for statistics calculation.
		$this->lastSaveResult = $aggregatedStats;

		$this->logger->info(
			'Optimized batch processing completed',
			[
				'total_objects_processed' => count($allResults),
				'total_chunks_processed' => $totalChunks,
				'aggregated_saved' => count($aggregatedStats['saved']),
				'aggregated_updated' => count($aggregatedStats['updated']),
				'aggregated_skipped' => count($aggregatedStats['skipped']),
				'aggregated_invalid' => count($aggregatedStats['invalid']),
			]
		);

		return $allResults;
	}//end saveObjectsInParallelBatches()

	/**
	 * Save objects in a single batch (fallback method)
	 *
	 * @param array $objects Array of objects to save
	 * @param ObjectServiceInterface $objectService ObjectService instance
	 * @param int $registerId Register ID
	 *
	 * @return array Array of saved objects
	 */
	private function saveObjectsInSingleBatch(array $objects, ObjectServiceInterface $objectService, int $registerId): array {
		$this->logger->info(
			'Using single batch processing',
			[
				'count' => count($objects),
			]
		);

		if (self::PERFORMANCE_OPTIMIZATIONS['disable_rbac'] === true) {
			$rbacValue = false;
		} else {
			$rbacValue = true;
		}

		$saveResult = $objectService->saveObjects(
			objects: $objects,
			register: $registerId,
			schema: null,
			_rbac: $rbacValue,
			validation: !self::PERFORMANCE_OPTIMIZATIONS['disable_validation'],
			events: !self::PERFORMANCE_OPTIMIZATIONS['disable_events']
		);

		// Store the save result for later access to statistics.
		$this->lastSaveResult = $saveResult;

		// Extract saved objects from the new structured return format.
		$savedObjects = array_merge(
			$saveResult['saved'] ?? [],
			$saveResult['updated'] ?? []
		);

		// Log detailed results including validation errors.
		$this->logger->info(
			'Objects saved successfully',
			[
				'saved_count' => count($saveResult['saved'] ?? []),
				'updated_count' => count($saveResult['updated'] ?? []),
				'unchanged_count' => count($saveResult['skipped'] ?? []),
				'invalid_count' => count($saveResult['invalid'] ?? []),
				'error_count' => count($saveResult['errors'] ?? []),
				'total_processed' => $saveResult['statistics']['totalProcessed'] ?? 0,
			]
		);

		// Log any validation errors for debugging.
		if (empty($saveResult['invalid']) === false) {
			foreach ($saveResult['invalid'] as $invalidItem) {
				$this->logger->warning(
					'Object failed validation during import',
					[
						'object_id' => $invalidItem['object']['@self']['id'] ?? 'unknown',
						'error' => $invalidItem['error'] ?? 'Unknown validation error',
						'type' => $invalidItem['type'] ?? 'ValidationException',
					]
				);
			}
		}

		// Log details about skipped objects if any.
		if (empty($saveResult['skipped']) === false) {
			$this->logger->info(
				'Objects skipped during import (no changes detected)',
				[
					'skipped_count' => count($saveResult['skipped']),
					'sample_skipped_ids' => array_slice(
						array_map(fn ($obj) => $obj->getUuid() ?? 'unknown', $saveResult['skipped']),
						0,
						5
					),
				]
			);
		}

		// Return the combined saved and updated objects (maintaining backward compatibility).
		return $savedObjects;
	}//end saveObjectsInSingleBatch()

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
	 * Initialize cached configuration values for performance optimization
	 *
	 * @return void
	 */
	private function initializeCache(): void {
		if ($this->cachedConfig !== null) {
			return;
			// Already cached.
		}

		// Get the AMEF register ID from configuration - throw error if missing.
		$amefConfig = $this->settingsService->getAmefConfig();
		if (isset($amefConfig['register']) === false || empty($amefConfig['register']) === true) {
			throw new \InvalidArgumentException(
				'AMEF register ID is not configured. Please configure the AMEF register via the admin interface.'
			);
		}

		$registerId = (int)$amefConfig['register'];

		$this->cachedConfig = [
			'registerId' => $registerId,
			// Use AMEF register ID directly.
			'schemaIds' => [
				'model' => $this->settingsService->getSchemaIdForObjectType('model'),
				'element' => $this->settingsService->getSchemaIdForObjectType('element'),
				'relationship' => $this->settingsService->getSchemaIdForObjectType('relationship'),
				'view' => $this->settingsService->getSchemaIdForObjectType('view'),
				'organization' => $this->settingsService->getSchemaIdForObjectType('organization'),
				'property_definition' => $this->settingsService->getSchemaIdForObjectType('property_definition'),
				// NOTE: 'property' removed - properties are never root-level
				// AMEF objects, only nested within other elements.
			],
		];

		$this->logger->debug(
			'ArchiMateService: Cache initialized',
			[
				'registerId' => $this->cachedConfig['registerId'],
				'schemaIds' => $this->cachedConfig['schemaIds'],
			]
		);

		// Validate that all required schema IDs are configured.
		$this->validateRequiredConfiguration();
	}//end initializeCache()

	/**
	 * Validates that all required configuration is present before import.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException If required configuration is missing.
	 */
	private function validateRequiredConfiguration(): void {
		$missingConfig = [];
		$requiredSchemaTypes = ['model', 'element', 'relationship', 'view', 'organization', 'property'];

		// Check register ID.
		if (empty($this->cachedConfig['registerId']) === true) {
			$missingConfig[] = 'AMEF Register ID (amef_register)';
		}

		// Check all required schema IDs.
		foreach ($requiredSchemaTypes as $schemaType) {
			$schemaId = $this->cachedConfig['schemaIds'][$schemaType] ?? null;
			if ($schemaId === null) {
				$configKey = "amef_{$schemaType}_schema";
				$missingConfig[] = "Schema ID for {$schemaType} ({$configKey})";
			}
		}

		// If any configuration is missing, throw detailed error.
		if (empty($missingConfig) === false) {
			$this->logger->error(
				'ArchiMateService: Missing required configuration',
				[
					'missing_config' => $missingConfig,
					'current_config' => [
						'registerId' => $this->cachedConfig['registerId'],
						'schemaIds' => $this->cachedConfig['schemaIds'],
					],
				]
			);

			$errorMessage = 'ArchiMate import cannot proceed due to missing configuration:' . "\n\n";
			$errorMessage .= "Missing configuration:\n";
			foreach ($missingConfig as $item) {
				$errorMessage .= "- {$item}\n";
			}

			$settingsHint = 'in the Stackiq settings before importing.';
			$errorMessage .= "\nPlease configure the AMEF register and all required schema IDs $settingsHint";
			$manualHint = 'or set them manually via the admin interface.';
			$errorMessage .= "\nYou can use the auto-configuration feature $manualHint";

			throw new \RuntimeException($errorMessage);
		}//end if

		$this->logger->info(
			'ArchiMateService: Configuration validation passed',
			[
				'registerId' => $this->cachedConfig['registerId'],
				'configuredSchemas' => count(array_filter($this->cachedConfig['schemaIds'])),
			]
		);
	}//end validateRequiredConfiguration()

	/**
	 * Log current memory usage for performance monitoring
	 *
	 * @param string $stage Description of the current processing stage
	 *
	 * @return void
	 */
	private function logMemoryUsage(string $stage): void {
		// Check if debug logging is available (Nextcloud logger doesn't have isDebug method).
		$memoryUsage = memory_get_usage(true);
		$memoryPeak = memory_get_peak_usage(true);
		$memoryLimit = ini_get('memory_limit');

		$this->logger->debug(
			"Memory usage at: {$stage}",
			[
				'current_mb' => round($memoryUsage / 1024 / 1024, 2),
				'peak_mb' => round($memoryPeak / 1024 / 1024, 2),
				'limit' => $memoryLimit,
			]
		);
	}//end logMemoryUsage()

	/**
	 * Clean up memory by forcing garbage collection
	 *
	 * @return void
	 */
	private function cleanupMemory(): void {
		if (function_exists('gc_collect_cycles') === true) {
			$cycles = gc_collect_cycles();
			// Use PSR-3 standard logging instead of isDebug() check.
			$this->logger->debug(
				'Garbage collection completed',
				[
					'cycles_collected' => $cycles,
				]
			);
		}
	}//end cleanupMemory()

	/**
	 * NOTE: Removed deprecated methods getArchiMateRegisterId() and getArchiMateModelSchemaId()
	 * that had hardcoded fallbacks. All configuration is now retrieved via SettingsService
	 * through the initializeCache() method and proper AMEF configuration.
	 */

	/**
	 * Get schema ID for a section
	 *
	 * @param string $section Section name
	 *
	 * @return int Schema ID
	 */
	private function getSchemaIdForSection(string $section): int {
		// Map section names to object types for SettingsService.
		$objectTypeMapping = [
			'elements' => 'element',
			'relationships' => 'relationship',
			'views' => 'view',
			'organizations' => 'organization',
			'property_definitions' => 'property_definition',
		];

		$objectType = $objectTypeMapping[$section] ?? $section;
		$schemaId = $this->settingsService->getSchemaIdForObjectType($objectType);

		// Ensure schema ID is configured - no hardcoded fallbacks.
		if ($schemaId === null) {
			$configMsg = "Schema ID for section '{$section}' is not configured.";
			$helpMsg = 'Please configure all AMEF schema IDs via the admin interface.';
			$typeMsg = "Expected object type: '{$objectType}'";
			throw new \RuntimeException("{$configMsg} {$helpMsg} {$typeMsg}");
		}

		return $schemaId;
	}//end getSchemaIdForSection()

	/**
	 * Test round-trip functionality
	 *
	 * @return array Test results
	 * @spec   openspec/specs/archimate-import/spec.md
	 */
	public function testRoundTrip(): array {
		$this->logger->info('Testing ArchiMate round-trip functionality');

		try {
			// Create test XML.
			$testXml = $this->createTestArchiMateXml();

			// Import.
			$importResult = $this->importArchiMateFileFromPath(
				options: [
					'file_path' => $this->createTempFile(content: $testXml),
				]
			);

			if ($importResult['success'] === false) {
				return [
					'success' => false,
					'error' => 'Import failed: ' . $importResult['error'],
				];
			}

			// Export.
			$exportResult = $this->exportToArchiMate();

			if ($exportResult['success'] === false) {
				return [
					'success' => false,
					'error' => 'Export failed: ' . $exportResult['error'],
				];
			}

			// Compare (simplified comparison).
			$importedCount = $importResult['imported_count'];
			$exportedCount = $exportResult['exported_count'];

			$success = $importedCount === $exportedCount;

			return [
				'success' => $success,
				'imported_count' => $importedCount,
				'exported_count' => $exportedCount,
				'round_trip_successful' => $success,
			];
		} catch (\Exception $e) {
			$this->logger->error(
				'Round-trip test failed',
				[
					'error' => $e->getMessage(),
				]
			);

			return [
				'success' => false,
				'error' => $e->getMessage(),
			];
		}//end try
	}//end testRoundTrip()

	/**
	 * Create test ArchiMate XML
	 *
	 * @return string Test XML content
	 */
	private function createTestArchiMateXml(): string {
		return '<?xml version="1.0" encoding="UTF-8"?>
<archimate:model xmlns:archimate="http://www.archimatetool.com/archimate" identifier="test-model">
  <name>Test Model</name>
  <documentation>Test model for round-trip verification</documentation>
  <elements>
    <element identifier="test-element-1" xsi:type="archimate:BusinessActor">
      <name>Test Actor</name>
    </element>
  </elements>
  <relationships>
    <relationship identifier="test-rel-1" xsi:type="archimate:AssociationRelationship">
      <source>test-element-1</source>
      <target>test-element-2</target>
    </relationship>
  </relationships>
</archimate:model>';
	}//end createTestArchiMateXml()

	/**
	 * Create temporary file with content
	 *
	 * @param string $content File content
	 *
	 * @return string Temporary file path
	 */
	private function createTempFile(string $content): string {
		$tempFile = tempnam(sys_get_temp_dir(), 'archimate_test_');
		file_put_contents($tempFile, $content);
		return $tempFile;
	}//end createTempFile()

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
	 * Get AMEF schema ID for a specific ArchiMate type via SettingsService
	 *
	 * This method retrieves the schema ID for a given ArchiMate type from SettingsService.
	 *
	 * @param string $archiMateType The ArchiMate type (e.g., 'element', 'organization', 'relationship')
	 *
	 * @return int|null The schema ID for the given type or null if not configured
	 */
	private function getAmefSchemaIdForType(string $archiMateType): ?int {
		// Use SettingsService to get schema ID.
		$schemaId = $this->settingsService->getSchemaIdForObjectType($archiMateType);
		return $schemaId;
	}//end getAmefSchemaIdForType()

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
	 * Check if any operation is in progress
	 *
	 * @return bool True if any operation is in progress
	 */
	private function isOperationInProgress(): bool {
		return $this->isImportInProgress() || $this->isExportInProgress();
	}//end isOperationInProgress()

	/**
	 * Create intelligent batches based on object size to prevent MySQL packet size issues
	 *
	 * This method analyzes object sizes and creates batches that stay under the MySQL
	 * max_allowed_packet limit while maintaining reasonable performance.
	 *
	 * @param array $objects Array of objects to batch
	 *
	 * @return array Array of batches, each containing objects that fit within size limits
	 */
	private function createIntelligentBatches(array $objects): array {
		$maxBatchSizeBytes = self::PERFORMANCE_OPTIMIZATIONS['max_batch_size_bytes'];
		$minBatchSize = self::PERFORMANCE_OPTIMIZATIONS['min_batch_size'];
		$sampleSize = self::PERFORMANCE_OPTIMIZATIONS['size_estimation_sample'];

		if (empty($objects) === true) {
			return [];
		}

		// Estimate average object size by sampling.
		$avgObjectSize = $this->estimateAverageObjectSize(objects: $objects, sampleSize: $sampleSize);

		// Calculate optimal batch size based on object size.
		$optimalBatchSize = max($minBatchSize, intval($maxBatchSizeBytes / $avgObjectSize));

		$this->logger->info(
			'Intelligent batch sizing analysis',
			[
				'total_objects' => count($objects),
				'estimated_avg_object_size_bytes' => $avgObjectSize,
				'max_batch_size_bytes' => $maxBatchSizeBytes,
				'calculated_optimal_batch_size' => $optimalBatchSize,
				'min_batch_size_enforced' => $minBatchSize,
			]
		);

		// Create batches with size awareness.
		$batches = [];
		$currentBatch = [];
		$currentBatchSize = 0;

		foreach ($objects as $object) {
			$objectSize = $this->estimateObjectSize(object: $object);

			// Check if adding this object would exceed the batch size limit.
			if (empty($currentBatch) === false && ($currentBatchSize + $objectSize) > $maxBatchSizeBytes) {
				// Current batch is full, save it and start a new one.
				$batches[] = $currentBatch;
				$currentBatch = [$object];
				$currentBatchSize = $objectSize;
			} else {
				// Add object to current batch.
				$currentBatch[] = $object;
				$currentBatchSize += $objectSize;
			}

			// Safety check: if a single object is larger than max batch size,.
			// create a batch with just that object.
			if (count($currentBatch) === 1 && $objectSize > $maxBatchSizeBytes) {
				$this->logger->warning(
					'Very large object detected, creating single-object batch',
					[
						'object_id' => $object['@self']['id'] ?? 'unknown',
						'object_size_bytes' => $objectSize,
						'max_batch_size_bytes' => $maxBatchSizeBytes,
					]
				);
				$batches[] = $currentBatch;
				$currentBatch = [];
				$currentBatchSize = 0;
			}
		}//end foreach

		// Add the last batch if it has objects.
		if (empty($currentBatch) === false) {
			$batches[] = $currentBatch;
		}

		$this->logger->info(
			'Intelligent batching completed',
			[
				'total_objects' => count($objects),
				'total_batches_created' => count($batches),
				'batch_sizes' => array_map('count', $batches),
				'estimated_batch_sizes_bytes' => array_map(
					fn ($batch) => array_sum(
						array_map([$this, 'estimateObjectSize'], $batch)
					),
					$batches
				),
			]
		);

		return $batches;
	}//end createIntelligentBatches()

	/**
	 * Estimate the average size of objects by sampling
	 *
	 * @param array $objects Array of objects to sample
	 * @param int $sampleSize Number of objects to sample for size estimation
	 *
	 * @return int Estimated average object size in bytes
	 */
	private function estimateAverageObjectSize(array $objects, int $sampleSize): int {
		$totalObjects = count($objects);
		if ($totalObjects === 0) {
			return 1000;
			// Default fallback size.
		}

		// Sample evenly distributed objects.
		$sampleIndices = [];
		if ($totalObjects <= $sampleSize) {
			// Use all objects if we have fewer than sample size.
			$sampleIndices = range(0, $totalObjects - 1);
		} else {
			// Sample evenly across the array.
			$step = max(1, intval($totalObjects / $sampleSize));
			for ($i = 0; $i < $totalObjects; $i += $step) {
				$sampleIndices[] = $i;
				if (count($sampleIndices) >= $sampleSize) {
					break;
				}
			}
		}

		// Calculate sizes of sampled objects.
		$totalSampleSize = 0;
		foreach ($sampleIndices as $index) {
			$totalSampleSize += $this->estimateObjectSize(object: $objects[$index]);
		}

		$averageSize = intval($totalSampleSize / count($sampleIndices));

		$this->logger->debug(
			'Object size estimation completed',
			[
				'total_objects' => $totalObjects,
				'sampled_objects' => count($sampleIndices),
				'total_sample_size_bytes' => $totalSampleSize,
				'estimated_average_size_bytes' => $averageSize,
			]
		);

		return max(1000, $averageSize);
		// Minimum 1KB per object.
	}//end estimateAverageObjectSize()

	/**
	 * Estimate the serialized size of an object for batching purposes
	 *
	 * @param array $object The object to estimate size for
	 *
	 * @return int Estimated size in bytes
	 */
	private function estimateObjectSize(array $object): int {
		// Quick estimation based on JSON serialization.
		// This includes overhead for SQL parameters and structure.
		$jsonSize = strlen(json_encode($object));

		// Add overhead for SQL INSERT statement structure.
		// Each object becomes multiple parameters in a bulk INSERT.
		$sqlOverhead = 500;
		// Estimated overhead per object in SQL.
		return $jsonSize + $sqlOverhead;
	}//end estimateObjectSize()

	/**
	 * Calculate detailed object statistics for import operations
	 *
	 * @param array $normalizedData Normalized ArchiMate data
	 *
	 * @return array Comprehensive statistics
	 */
	private function calculateObjectStatistics(array $normalizedData): array {
		// Initialize statistics structure.
		$statistics = [
			'elements' => ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []],
			'organizations' => ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []],
			'relationships' => ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []],
			'views' => ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []],
			'property_definitions' => ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []],
		];

		// If we have access to the actual save results from ObjectService, use those.
		if ($this->lastSaveResult !== null) {
			$saveResult = $this->lastSaveResult;

			// Count objects by section type from the actual processed objects.
			$allProcessedObjects = array_merge(
				$saveResult['saved'] ?? [],
				$saveResult['updated'] ?? [],
				$saveResult['skipped'] ?? [],
				// For invalid objects, extract the original object from the error structure.
				array_map(fn ($item) => $item['object'] ?? [], $saveResult['invalid'] ?? [])
			);

			foreach ($allProcessedObjects as $object) {
				// Convert ObjectEntity to array if needed.
				if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
					$object = $object->jsonSerialize();
				}

				$sectionType = $object['section'] ?? 'elements';
				// Default to elements if section not found.
				// Map section types to statistics keys.
				$sectionKey = match ($sectionType) {
					'elements' => 'elements',
					'relationships' => 'relationships',
					'organizations' => 'organizations',
					'views' => 'views',
					'property_definitions' => 'property_definitions',
					default => 'elements'
					// Default fallback.
				};

				// No "skip unknown section types" guard: the branch above pins
				// $sectionKey to a key $statistics always has, so it never fired.

				// Determine if this object was created, updated, or had errors.
				$objectId = $object['@self']['id'] ?? $object['identifier'] ?? null;

				// Check if this object is in the saved (created) list.
				$wasCreated = empty(
					array_filter(
						$saveResult['saved'] ?? [],
						fn ($saved) => ($saved->getUuid() === $objectId)
					)
				) === false;

				// Check if this object is in the updated list.
				$wasUpdated = empty(
					array_filter(
						$saveResult['updated'] ?? [],
						fn ($updated) => ($updated->getUuid() === $objectId)
					)
				) === false;

				// Check if this object was skipped (no changes).
				$wasSkipped = empty(
					array_filter(
						$saveResult['skipped'] ?? [],
						fn ($skipped) => ($skipped->getUuid() === $objectId)
					)
				) === false;

				// Check if this object had validation errors.
				$hasErrors = empty(
					array_filter(
						$saveResult['invalid'] ?? [],
						fn ($invalid) => (($invalid['object']['@self']['id'] ?? null) === $objectId)
					)
				) === false;

				if ($wasCreated === true) {
					$statistics[$sectionKey]['created']++;
				} elseif ($wasUpdated === true) {
					$statistics[$sectionKey]['updated']++;
				} elseif ($wasSkipped === true) {
					$statistics[$sectionKey]['skipped']++;
				} elseif ($hasErrors === true) {
					// Add to errors array for this section.
					$errorInfo = array_filter(
						$saveResult['invalid'] ?? [],
						fn ($invalid) => (($invalid['object']['@self']['id'] ?? null) === $objectId)
					);

					if (empty($errorInfo) === false) {
						$firstError = array_values($errorInfo)[0];
						$errorMessage = 'Unknown validation error';
						if (is_array($firstError) === true && isset($firstError['error']) === true) {
							$errorMessage = $firstError['error'];
						}

						$statistics[$sectionKey]['errors'][] = $errorMessage;
					}
				} else {
					// This shouldn't happen, but leave as fallback.
					$statistics[$sectionKey]['skipped']++;
				}//end if
			}//end foreach
		} else {
			// Fallback to old method if no save result is available.
			$sections = ['elements', 'relationships', 'organizations', 'views', 'property_definitions'];
			foreach ($sections as $section) {
				if (isset($normalizedData[$section]) === true) {
					$count = count($normalizedData[$section]);
					// Assume all objects were created (legacy behavior).
					$statistics[$section]['created'] = $count;
				}
			}
		}//end if

		// Calculate summary totals from actual statistics.
		$summary = [
			'total_objects_created' => 0,
			'total_objects_updated' => 0,
			'total_objects_deleted' => 0,
			'total_objects_skipped' => 0,
			'total_errors' => 0,
		];

		// No "skip the summary section" guard: `omschrijving` is written into
		// $statistics on the line AFTER this loop, so the loop can never see it.
		foreach ($statistics as $sectionStats) {
			$summary['total_objects_created'] += $sectionStats['created'];
			$summary['total_objects_updated'] += $sectionStats['updated'];
			$summary['total_objects_skipped'] += $sectionStats['skipped'];
			$summary['total_errors'] += count($sectionStats['errors']);
		}

		$statistics['omschrijving'] = $summary;

		return $statistics;
	}//end calculateObjectStatistics()

	/**
	 * Extract propertyDefinitions from the parsed XML and build a map
	 *
	 * @param array $data Parsed XML data
	 *
	 * @return array Map of propertyDefinitionRef => property name
	 */
	private function extractPropertyDefinitionMap(array $data): array {
		// OPTIMIZATION: Return cached property definition map if available.
		if ($this->propDefMapCache !== null) {
			return $this->propDefMapCache;
		}

		$map = [];
		// Find propertyDefinitions section (handle possible alternative names).
		$propertyDefs = null;
		if (isset($data['propertyDefinitions']) === true) {
			$propertyDefs = $data['propertyDefinitions'];
		} elseif (isset($data['property_definitions']) === true) {
			$propertyDefs = $data['property_definitions'];
		}

		if ($propertyDefs !== false && isset($propertyDefs['propertyDefinition']) === true) {
			$defs = $propertyDefs['propertyDefinition'];
			if (isset($defs[0]) === true) {
				// Array of propertyDefinition.
				foreach ($defs as $def) {
					if (isset($def['_attributes']['identifier']) === true && isset($def['name']) === true) {
						if (is_array($def['name']) === true && isset($def['name']['_value']) === true) {
							$map[$def['_attributes']['identifier']] = $def['name']['_value'];
						} else {
							$map[$def['_attributes']['identifier']] = $def['name'];
						}
					}
				}
			} elseif (isset($defs['_attributes']['identifier']) === true && isset($defs['name']) === true) {
				// Single propertyDefinition.
				if (is_array($defs['name']) === true && isset($defs['name']['_value']) === true) {
					$map[$defs['_attributes']['identifier']] = $defs['name']['_value'];
				} else {
					$map[$defs['_attributes']['identifier']] = $defs['name'];
				}
			}
		}//end if

		// OPTIMIZATION: Cache the result for subsequent calls during the same import.
		$this->propDefMapCache = $map;

		return $map;
	}//end extractPropertyDefinitionMap()

	/**
	 * Transform ArchiMate XML data to objects array in batch (OpenRegister pattern)
	 *
	 * This method follows the same pattern as OpenRegister CSV import:
	 * - Parse ALL sections at once
	 * - Create objects directly without intermediate normalization
	 * - Use cached configuration values
	 * - Minimize object copying and complex transformations
	 *
	 * @param array $xmlData Parsed XML data
	 * @param string $modelIdentifier Model identifier
	 *
	 * @return array Array of objects ready for saveObjects()
	 */
	private function transformArchiMateXmlToObjectsBatch(array $xmlData, string $modelIdentifier): array {
		$allObjects = [];

		// Extract propertyDefinitionMap once for all objects.
		$propDefMap = $this->extractPropertyDefinitionMap(data: $xmlData);

		// Create model object first.
		if (isset($xmlData['_attributes']) === true || isset($xmlData['name']) === true) {
			$modelMetadata = [
				'identifier' => $modelIdentifier,
				'name' => $xmlData['name'] ?? '',
				'documentation' => $xmlData['documentation'] ?? '',
				'properties' => $xmlData['properties'] ?? [],
				'propertyDefinitionMap' => $propDefMap,
			];

			if (isset($xmlData['_attributes']) === true) {
				$modelMetadata = array_merge($modelMetadata, $xmlData['_attributes']);
			}

			$allObjects[] = $this->createModelObjectDirect(metadata: $modelMetadata, modelIdentifier: $modelIdentifier);
		}

		// Process each section type directly (no intermediate normalization).
		$sections = [
			'elements' => 'element',
			'relationships' => 'relationship',
			'organizations' => 'organization',
			'views' => 'view',
			'property_definitions' => 'property_definition',
		];

		foreach ($sections as $sectionName => $schemaType) {
			$sectionData = $this->findSectionData(xmlData: $xmlData, sectionName: $sectionName);
			if (empty($sectionData) === false) {
				$sectionObjects = $this->transformSectionObjectsBatch(
					sectionData: $sectionData,
					schemaType: $schemaType,
					modelIdentifier: $modelIdentifier,
					propDefMap: $propDefMap
				);
				$allObjects = array_merge($allObjects, $sectionObjects);
			}
		}

		return $allObjects;
	}//end transformArchiMateXmlToObjectsBatch()

	/**
	 * Create model object directly with cached configuration
	 *
	 * @param array $metadata Model metadata
	 * @param string $modelIdentifier Model identifier
	 *
	 * @return array Model object with @self structure
	 */
	private function createModelObjectDirect(array $metadata, string $modelIdentifier): array {
		return [
			'@self' => [
				'register' => $this->cachedConfig['registerId'],
				'schema' => $this->cachedConfig['schemaIds']['model'],
				'id' => $modelIdentifier,
				'published' => date('Y-m-d\TH:i:s\Z'),
			],
			'identifier' => $modelIdentifier,
			'section' => 'model',
			'model_identifier' => $modelIdentifier,
		] + $metadata;
	}//end createModelObjectDirect()

	/**
	 * Find section data efficiently without complex nested searches
	 *
	 * @param array $xmlData Parsed XML data
	 * @param string $sectionName Section name to find
	 *
	 * @return array Section data or empty array
	 */
	private function findSectionData(array $xmlData, string $sectionName): array {
		// Direct lookup first.
		if (isset($xmlData[$sectionName]) === true) {
			return $xmlData[$sectionName];
		}

		// Alternative names lookup.
		$alternatives = [
			'views' => ['diagrams'],
			'organizations' => ['organisation'],
			'property_definitions' => ['propertyDefinitions', 'propertydefinitions'],
		];

		if (isset($alternatives[$sectionName]) === true) {
			foreach ($alternatives[$sectionName] as $altName) {
				if (isset($xmlData[$altName]) === true) {
					return $xmlData[$altName];
				}
			}
		}

		return [];
	}//end findSectionData()

	/**
	 * Transform section objects in batch with minimal overhead
	 *
	 * @param array $sectionData Section data from XML
	 * @param string $schemaType Schema type (singular)
	 * @param string $modelIdentifier Model identifier
	 * @param array $propDefMap Property definition map
	 *
	 * @return array Array of transformed objects
	 */
	private function transformSectionObjectsBatch(
		array $sectionData,
		string $schemaType,
		string $modelIdentifier,
		array $propDefMap,
	): array {
		$objects = [];

		// Find items in section (simplified version).
		$items = $this->findItemsSimplified(sectionData: $sectionData, sectionType: $schemaType);

		foreach ($items as $item) {
			if (is_array($item) === false) {
				continue;
			}

			$identifier = $this->extractIdentifier(item: $item, sectionName: $schemaType);
			if ($identifier === null) {
				continue;
			}

			// Create object directly (minimal processing).
			$object = [
				'@self' => [
					'register' => $this->cachedConfig['registerId'],
					'schema' => $this->cachedConfig['schemaIds'][$schemaType],
					'id' => $identifier,
					'published' => date('Y-m-d\TH:i:s\Z'),
				],
				'identifier' => $identifier,
				'section' => $schemaType,
				'model_identifier' => $modelIdentifier,
				'xml' => $this->extractEssentialXmlData(item: $item),
				// OPTIMIZATION: Store only essential XML data.
			];

			// Extract name from XML if it exists.
			if (isset($item['name']) === true) {
				if (is_array($item['name']) === true && isset($item['name']['_value']) === true) {
					$object['name'] = $item['name']['_value'];
				} elseif (is_string($item['name']) === true) {
					$object['name'] = $item['name'];
				}
			}

			// Extract documentation from XML if it exists and set to summary.
			if (isset($item['documentation']) === true) {
				if (is_array($item['documentation']) === true && isset($item['documentation']['_value']) === true) {
					$object['omschrijving'] = $item['documentation']['_value'];
				} elseif (is_string($item['documentation']) === true) {
					$object['omschrijving'] = $item['documentation'];
				}
			}

			// Flatten properties efficiently (if present).
			if (isset($item['properties']['property']) === true && empty($propDefMap) === false) {
				$this->flattenPropertiesBatch(
					object: $object,
					properties: $item['properties']['property'],
					propDefMap: $propDefMap
				);
			}

			$objects[] = $object;
		}//end foreach

		return $objects;
	}//end transformSectionObjectsBatch()

	/**
	 * Simplified item finding for better performance
	 *
	 * @param array $sectionData Section data
	 * @param string $sectionType Section type
	 *
	 * @return array Items array
	 */
	private function findItemsSimplified(array $sectionData, string $sectionType): array {
		// Handle views with diagrams structure.
		if ($sectionType === 'view' && isset($sectionData['diagrams']['view']) === true) {
			$viewData = $sectionData['diagrams']['view'];
			if (isset($viewData[0]) === true) {
				return $viewData;
			}

			return [$viewData];
		}

		// Try common patterns.
		$patterns = [
			// Singular: element, relationship, etc.
			$sectionType,
			// Plural: elements, relationships, etc.
			$sectionType . 's',
			// Organizations use 'item'.
			'item',
			// Property definitions.
			'propertyDefinition',
		];

		foreach ($patterns as $pattern) {
			if (isset($sectionData[$pattern]) === true) {
				$data = $sectionData[$pattern];
				if (is_array($data) === true && isset($data[0]) === true) {
					return $data;
				}

				return [$data];
			}
		}

		// Fallback: treat section data as single item.
		return [$sectionData];
	}//end findItemsSimplified()

	/**
	 * Flatten properties in batch for better performance
	 *
	 * @param array $object Object to add properties to (by reference).
	 * @param array $properties Properties array from XML.
	 * @param array $propDefMap Property definition map.
	 *
	 * @return void
	 */
	private function flattenPropertiesBatch(array &$object, array $properties, array $propDefMap): void {
		$props = [$properties];
		if (isset($properties[0]) === true) {
			$props = $properties;
		}

		$processedProperties = [];

		foreach ($props as $prop) {
			if (isset($prop['_attributes']['propertyDefinitionRef']) === false) {
				continue;
			}

			$defRef = $prop['_attributes']['propertyDefinitionRef'];
			$value = $prop['value']['_value'] ?? $prop['value'] ?? null;

			if ($value !== null && isset($propDefMap[$defRef]) === true) {
				$propertyName = $propDefMap[$defRef];
				$camelCaseName = $this->convertToCamelCase(propertyName: $propertyName);
				$object[$camelCaseName] = $value;

				// Store property mapping for reference.
				if (isset($object['_propertyMapping']) === false) {
					$object['_propertyMapping'] = [];
				}

				$object['_propertyMapping'][$camelCaseName] = $propertyName;

				$processedProperties[] = [
					'original' => $propertyName,
					'camelCase' => $camelCaseName,
					'value' => $value,
				];

				// Set slug for Object ID property.
				if (strtolower($propertyName) === 'object id') {
					$object['@self']['slug'] = $value;
				}
			}//end if
		}//end foreach

		// OPTIMIZATION: Removed debug logging from tight loop for performance.
	}//end flattenPropertiesBatch()

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

	/**
	 * Calculate optimized statistics for performance reporting
	 *
	 * Reads the persisted save outcome from {@see self::$lastSaveResult}.
	 *
	 * @return array Statistics array
	 */
	private function calculateOptimizedStatistics(): array {
		$statistics = [
			'omschrijving' => [
				'total_objects_created' => 0,
				'total_objects_updated' => 0,
				'total_objects_deleted' => 0,
				'total_objects_skipped' => 0,
				'total_errors' => 0,
			],
		];

		if ($this->lastSaveResult !== null) {
			$saveResult = $this->lastSaveResult;
			$statistics['omschrijving'] = [
				'total_objects_created' => count($saveResult['saved'] ?? []),
				'total_objects_updated' => count($saveResult['updated'] ?? []),
				'total_objects_deleted' => 0,
				'total_objects_skipped' => count($saveResult['skipped'] ?? []),
				'total_errors' => count($saveResult['invalid'] ?? []),
			];
		}

		return $statistics;
	}//end calculateOptimizedStatistics()

	/**
	 * Extract GEMMA type from an object using multiple possible property names
	 *
	 * This method tries different variations of GEMMA type property names to ensure
	 * compatibility with different ArchiMate model variations.
	 *
	 * @param array $object The object to extract GEMMA type from
	 *
	 * @return string|null The GEMMA type value or null if not found
	 */
	private function extractGemmaType(array $object): ?string {
		// Try various possible property names for GEMMA type.
		$possiblePropertyNames = [
			'gemmaType',
			// Standard camelCase conversion of "GEMMA Type".
			'gemmatype',
			// Lowercase version.
			'GemmaType',
			// PascalCase version.
			'GEMMA_Type',
			// Underscore version.
			'gemma_type',
			// Lowercase underscore version.
			'GEMMAType',
			// All caps first word.
			'type',
			// Sometimes just "Type" in models.
			'elementType',
			// Alternative naming.
			'componentType',
			// Another alternative.
		];

		foreach ($possiblePropertyNames as $propertyName) {
			if (isset($object[$propertyName]) === true && empty($object[$propertyName]) === false) {
				$value = (string)$object[$propertyName];

				// Log the first successful match for debugging.
				if ($this->gemmaTypePropertyFound === false) {
					$this->logger->debug(
						'GEMMA Type property found',
						[
							'property_name' => $propertyName,
							'value' => $value,
							'object_id' => $object['identifier'] ?? 'unknown',
						]
					);
					$this->gemmaTypePropertyFound = true;
				}

				return $value;
			}
		}

		// If no direct property found, check _propertyMapping for original property names.
		if (isset($object['_propertyMapping']) === true) {
			foreach ($object['_propertyMapping'] as $camelCase => $original) {
				// Check if the original property name contains "gemma" or "type".
				if (stripos($original, 'gemma') !== false && stripos($original, 'type') !== false) {
					if (isset($object[$camelCase]) === true && empty($object[$camelCase]) === false) {
						$this->logger->debug(
							'GEMMA Type found via property mapping',
							[
								'camel_case_name' => $camelCase,
								'original_name' => $original,
								'value' => $object[$camelCase],
								'object_id' => $object['identifier'] ?? 'unknown',
							]
						);
						return (string)$object[$camelCase];
					}
				}
			}
		}

		return null;
	}//end extractGemmaType()

	/**
	 * Process GEMMA Referentiecomponent-Standaard relationships with Verbindingsrol support
	 *
	 * This method analyzes all objects to find Referentiecomponenten and Standaarden,
	 * then uses relationships to link them together based on Verbindingsrol property.
	 * Each Referentiecomponent gets two properties:
	 * - 'recommendedStandards' array for standards with Verbindingsrol = "Aanbevolen"
	 * - 'mandatoryStandards' array for standards with Verbindingsrol = "Verplicht"
	 *
	 * @param array $objects All objects from the import
	 *
	 * @return array Objects with enhanced Referentiecomponent data
	 */
	private function processGemmaReferenceComponentStandards(array $objects): array {
		$this->logger->info(
			'Processing GEMMA Referentiecomponent-Standaard relationships with optimized single-pass algorithm'
		);

		// OPTIMIZATION: Single-pass processing - collect all data types at once.
		$referenceComponents = [];
		$standards = [];
		$gemmaRelationshipMap = [];

		// Debug: Count objects and property variations.
		$elementCount = 0;
		$elementsWithGemmaType = 0;
		$gemmaTypeVariations = [];

		// PASS 1: Collect Referentiecomponenten and Standaarden, process relationships immediately.
		foreach ($objects as $index => $object) {
			// Debug: Count elements and GEMMA types.
			if (isset($object['section']) === true && $object['section'] === 'element') {
				$elementCount++;

				// Check for various possible GEMMA type property names.
				$gemmaTypeValue = $this->extractGemmaType(object: $object);
				if ($gemmaTypeValue !== null) {
					$elementsWithGemmaType++;

					// Track GEMMA type variations for debugging.
					if (isset($gemmaTypeVariations[$gemmaTypeValue]) === false) {
						$gemmaTypeVariations[$gemmaTypeValue] = 0;
					}

					$gemmaTypeVariations[$gemmaTypeValue]++;

					if ($gemmaTypeValue === 'Referentiecomponent') {
						$referenceComponents[$object['identifier']] = $index;
					} elseif ($gemmaTypeValue === 'Standaard') {
						$standards[$object['identifier']] = $index;
					}
				}
			}//end if

			// Process relationships immediately when found (no separate collection needed).
			if (isset($object['section']) === true && $object['section'] === 'relationship') {
				$this->processRelationshipImmediate(
					relationship: $object,
					referenceComponents: $referenceComponents,
					standards: $standards,
					gemmaRelationshipMap: $gemmaRelationshipMap
				);
			}
		}//end foreach

		// Enhanced debug logging.
		$this->logger->info(
			'GEMMA objects processing complete',
			[
				'total_elements' => $elementCount,
				'elements_with_gemma_type' => $elementsWithGemmaType,
				'gemma_type_variations' => $gemmaTypeVariations,
				'referentiecomponenten_count' => count($referenceComponents),
				'standaarden_count' => count($standards),
				'processed_relationships' => count($gemmaRelationshipMap),
			]
		);

		// Additional debugging if no GEMMA types found.
		if ($elementsWithGemmaType === 0 && $elementCount > 0) {
			$this->logger->warning(
				'No GEMMA types found in any elements',
				[
					'total_elements_processed' => $elementCount,
					'sample_element_keys' => 'Will need to examine individual objects',
				]
			);
		}

		// STEP 2: Apply the processed relationship mappings to Referentiecomponenten.
		$enhancedCount = 0;
		foreach ($gemmaRelationshipMap as $referenceComponentId => $standardsMap) {
			if (isset($referenceComponents[$referenceComponentId]) === true) {
				$objectIndex = $referenceComponents[$referenceComponentId];

				// Remove duplicates and add the properties.
				$recommendedStandards = array_unique($standardsMap['aanbevolen']);
				$mandatoryStandards = array_unique($standardsMap['verplicht']);

				$objects[$objectIndex]['recommendedStandards'] = $recommendedStandards;
				$objects[$objectIndex]['mandatoryStandards'] = $mandatoryStandards;

				// Also add combined array for backward compatibility.
				$allStandards = array_unique(array_merge($recommendedStandards, $mandatoryStandards));
				$objects[$objectIndex]['standards'] = $allStandards;

				$this->logger->info(
					'Enhanced Referentiecomponent with categorized standaarden',
					[
						'referentiecomponent_id' => $referenceComponentId,
						'referentiecomponent_name' => $objects[$objectIndex]['name'] ?? 'Unknown',
						'aanbevolen_count' => count($recommendedStandards),
						'verplicht_count' => count($mandatoryStandards),
						'aanbevolen_ids' => $recommendedStandards,
						'verplicht_ids' => $mandatoryStandards,
					]
				);

				$enhancedCount++;
			}//end if
		}//end foreach

		$this->logger->info(
			'GEMMA Referentiecomponent-Standaard processing completed',
			[
				'referentiecomponenten_enhanced' => $enhancedCount,
				'total_referentiecomponenten' => count($referenceComponents),
				'total_relationships_processed' => count($gemmaRelationshipMap),
			]
		);

		return $objects;
	}//end processGemmaReferenceComponentStandards()

	/**
	 * OPTIMIZATION: Process relationship immediately when found (single-pass algorithm)
	 *
	 * @param array $relationship The relationship object.
	 * @param array $referenceComponents Array of Referentiecomponent identifiers.
	 * @param array $standards Array of Standaard identifiers.
	 * @param array $gemmaRelationshipMap The relationship map to update (by reference).
	 *
	 * @return void
	 */
	private function processRelationshipImmediate(
		array $relationship,
		array $referenceComponents,
		array $standards,
		array &$gemmaRelationshipMap,
	): void {
		// Get source and target from relationship XML or flattened properties.
		$source = $this->extractRelationshipEndpoint(relationship: $relationship, endpoint: 'source');
		$target = $this->extractRelationshipEndpoint(relationship: $relationship, endpoint: 'target');

		if ($source === null || $target === false) {
			return;
		}

		// Get Verbindingsrol from flattened properties (camelCase: verbindingsrol).
		$verbindingsrol = $relationship['verbindingsrol'] ?? null;

		// Skip if no Verbindingsrol is defined.
		if ($verbindingsrol === null) {
			return;
		}

		// Check if one end is a Referentiecomponent and the other is a Standaard.
		$refCompId = null;
		$standardId = null;

		if (isset($referenceComponents[$source]) === true && isset($standards[$target]) === true) {
			// Referentiecomponent -> Standaard.
			$refCompId = $source;
			$standardId = $target;
		} elseif (isset($standards[$source]) === true && isset($referenceComponents[$target]) === true) {
			// Standaard -> Referentiecomponent (reverse direction).
			$refCompId = $target;
			$standardId = $source;
		}

		if ($standardId === true) {
			// Initialize arrays if not exists.
			if (isset($gemmaRelationshipMap[$refCompId]) === false) {
				$gemmaRelationshipMap[$refCompId] = [
					'aanbevolen' => [],
					'verplicht' => [],
				];
			}

			// Add to appropriate array based on Verbindingsrol.
			if (strtolower($verbindingsrol) === 'aanbevolen') {
				$gemmaRelationshipMap[$refCompId]['aanbevolen'][] = $standardId;
			} elseif (strtolower($verbindingsrol) === 'verplicht') {
				$gemmaRelationshipMap[$refCompId]['verplicht'][] = $standardId;
			}
		}
	}//end processRelationshipImmediate()

	/**
	 * Extract relationship endpoint (source or target) from relationship object
	 *
	 * @param array $relationship The relationship object
	 * @param string $endpoint Either 'source' or 'target'
	 *
	 * @return string|null The endpoint identifier or null if not found
	 */
	private function extractRelationshipEndpoint(array $relationship, string $endpoint): ?string {
		// Try flattened camelCase property first.
		if (isset($relationship[$endpoint]) === true) {
			return $relationship[$endpoint];
		}

		// Try XML structure.
		if (isset($relationship['xml'][$endpoint]) === true) {
			$endpointData = $relationship['xml'][$endpoint];

			// Handle different XML structures.
			if (is_string($endpointData) === true) {
				return $endpointData;
			}

			if (is_array($endpointData) === true) {
				// Try _attributes.href or _value.
				if (isset($endpointData['_attributes']['href']) === true) {
					return $endpointData['_attributes']['href'];
				}

				if (isset($endpointData['_value']) === true) {
					return $endpointData['_value'];
				}
			}
		}

		// Try direct XML access for ArchiMate format.
		if (isset($relationship['xml']['_attributes']) === true) {
			$attr = $relationship['xml']['_attributes'];
			if ($endpoint === 'source' && isset($attr['source']) === true) {
				return $attr['source'];
			}

			if ($endpoint === 'target' && isset($attr['target']) === true) {
				return $attr['target'];
			}
		}

		return null;
	}//end extractRelationshipEndpoint()

	/**
	 * OPTIMIZATION: Extract only essential XML data to reduce memory usage by 20-30%
	 *
	 * Instead of storing the complete XML structure, this method extracts only
	 * the essential data needed for round-trip fidelity and export functionality.
	 *
	 * @param array $item The complete XML item data
	 *
	 * @return array Essential XML data for storage
	 */
	private function extractEssentialXmlData(array $item): array {
		$essential = [];

		// Always preserve core attributes (needed for export).
		if (isset($item['_attributes']) === true) {
			$essential['_attributes'] = $item['_attributes'];
		}

		// Preserve name and documentation (already extracted to root level but needed for export).
		if (isset($item['name']) === true) {
			$essential['name'] = $item['name'];
		}

		if (isset($item['documentation']) === true) {
			$essential['documentation'] = $item['documentation'];
		}

		// Preserve properties structure (needed for property mapping).
		if (isset($item['properties']) === true) {
			$essential['properties'] = $item['properties'];
		}

		// For relationships, preserve source/target information.
		if (isset($item['source']) === true) {
			$essential['source'] = $item['source'];
		}

		if (isset($item['target']) === true) {
			$essential['target'] = $item['target'];
		}

		// Preserve any other critical ArchiMate-specific fields.
		$criticalFields = ['type', 'viewpoint', 'accessType', 'isDirected'];
		foreach ($criticalFields as $field) {
			if (isset($item[$field]) === true) {
				$essential[$field] = $item[$field];
			}
		}

		// Add a marker to indicate this is essential data (for debugging).
		$essential['_essential_data'] = true;

		return $essential;
	}//end extractEssentialXmlData()
}//end class
