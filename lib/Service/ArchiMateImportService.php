<?php

/**
 * ArchiMate Import Service for Stackiq
 *
 * Handles the business logic for importing ArchiMate XML files with round-trip fidelity.
 * This service contains all the import-specific logic that was previously in ArchiMateService.
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
use OCA\OpenRegister\Service\OrganisationService;
use OCA\Stackiq\Service\ArchiMate\ArchiMateImportScope;
use OCA\Stackiq\Service\ArchiMate\GemmaStandardLinker;
use OCA\Stackiq\Service\ProgressTracker;
use OCA\Stackiq\Service\Settings\ObjectTypeLocatorInterface;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use SimpleXMLElement;

/**
 * ArchiMate Import Service for handling XML import business logic with round-trip fidelity
 *
 * This service provides the complete import workflow:
 * 1. Parse XML to array (capturing all possible XML values)
 * 2. Detect if model already exists or is new
 * 3. Normalize data structure for storage as JSON blob
 * 4. Convert to OpenRegister objects with proper @self structure
 * 5. Save objects using ObjectService::saveObjects
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
 * @SuppressWarnings(PHPMD.UnusedLocalVariable)
 * @SuppressWarnings(PHPMD.UnusedPrivateMethod)
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 * @SuppressWarnings(PHPMD.MissingImport)
 * @SuppressWarnings(PHPMD.CamelCaseVariableName)
 * @SuppressWarnings(PHPMD.UnusedPrivateField)
 * @SuppressWarnings(PHPMD.CountInLoopExpression)
 *
 * @spec openspec/specs/archimate-import/spec.md#requirement-the-system-shall-parse-an-archimate-amef-file-into-catalog-objects-req-001
 */
class ArchiMateImportService {

	/**
	 * NOTE: Default schema IDs removed - all schema IDs must be configured via AMEF settings.
	 * The system will fail gracefully with clear error messages if configuration is missing.
	 */

	/**
	 * Store last save operation timing breakdown for performance metrics
	 *
	 * @var array
	 */
	private array $lastSaveTiming = [];

	/**
	 * CamelCase property names, with their conversion cache; created on first use.
	 *
	 * @var ArchiMate\CamelCaseNames|null
	 */
	private ?ArchiMate\CamelCaseNames $camelCaseNames = null;

	/**
	 * Cache for identifier extraction patterns by section type
	 *
	 * @var array<string, array>
	 */
	private array $idPatternCache = [];

	/**
	 * Cache for property definition maps to avoid rebuilding during import
	 *
	 * @var array|null
	 */
	private ?array $propMapCache = null;

	/**
	 * Storage for the last save operation results.
	 * Contains the structured return from ObjectService::saveObjects.
	 *
	 * @var array|null
	 */
	private ?array $lastSaveResult = null;

	/**
	 * The result lists of an OpenRegister saveObjects call, in the order they are aggregated.
	 */
	private const SAVE_BUCKETS = ['saved', 'updated', 'unchanged', 'invalid'];

	/**
	 * Operation ids a page may name for an import it follows.
	 */
	public const OPERATION_ID_PATTERN = '/^archimate_import_[A-Za-z0-9]{8,64}$/';

	/**
	 * The progress operation of the running import, or null when it is not tracked.
	 *
	 * @var string|null
	 */
	private ?string $operationId = null;

	/**
	 * Whether the running import stopped on a cancel.
	 *
	 * @var boolean
	 */
	private bool $cancelled = false;

	/**
	 * Cached configuration values for performance optimization.
	 *
	 * @var array|null
	 */
	private ?array $cachedConfig = null;

	/**
	 * Constructor for ArchiMateImportService
	 *
	 * @param IAppConfig $config Nextcloud app configuration service
	 * @param IUserSession $userSession User session service
	 * @param IAppManager $appManager App manager service
	 * @param ContainerInterface $container PSR-11 container interface
	 * @param LoggerInterface $logger Logger service
	 * @param ObjectTypeLocatorInterface $settingsService Resolves the AMEF schema ids.
	 * @param OrganisationService $organisationService Organisation service.
	 * @param IDBConnection $dbConnection Database connection interface.
	 * @param ProgressTracker $progressTracker Progress store the page follows and cancels the import through.
	 */
	public function __construct(
		private readonly IAppConfig $config,
		private readonly IUserSession $userSession,
		private readonly IAppManager $appManager,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly ObjectTypeLocatorInterface $settingsService,
		private readonly OrganisationService $organisationService,
		private readonly IDBConnection $dbConnection,
		private readonly ProgressTracker $progressTracker,
	) {
	}//end __construct()

	/**
	 * Validates the import-options array and returns the resolved file path.
	 *
	 * Accepts both `filePath` (camelCase) and `file_path` (snake_case) keys so
	 * callers don't have to harmonise the convention. Throws an
	 * `InvalidArgumentException` when the key is missing/empty or when the
	 * resolved path does not point at an existing file.
	 *
	 * Extracted from {@see importArchiMateFileFromPath()} +
	 * {@see importArchiMateFileFromPathOptimized()} as part of task 4.2 so the
	 * two entry points share a single guard clause.
	 *
	 * @param array<string, mixed> $options Import options.
	 *
	 * @return string The validated file path.
	 *
	 * @throws \InvalidArgumentException When the file path is missing or invalid.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md#task-4
	 */
	private function validateArchiMateFile(array $options): string {
		$filePath = $options['filePath'] ?? $options['file_path'] ?? '';
		if (empty($filePath) === true) {
			throw new \InvalidArgumentException('File path is required for import');
		}

		if (file_exists($filePath) === false) {
			throw new \InvalidArgumentException("File not found: {$filePath}");
		}

		return (string)$filePath;
	}//end validateArchiMateFile()

	/**
	 * Convert a SimpleXMLElement into a normalized associative array.
	 *
	 * Conventions:
	 * - All attributes added as top-level keys with leading underscore `_`.
	 * - Namespaced attributes use `prefix__name` in the underscored form and are
	 *   also available in the legacy bag form under `_attributes['prefix:name']`.
	 * - A legacy `_attributes` bag is maintained for backward compatibility.
	 * - Leaf node text is available as `_value`; when children exist alongside
	 *   text, it is available as `_text`.
	 * - Repeated child nodes are represented as arrays.
	 *
	 * @param \SimpleXMLElement $xml The XML element to convert.
	 *
	 * @return array The normalized associative array.
	 * @spec   openspec/specs/archimate-import/spec.md
	 */
	public function xmlToArray(\SimpleXMLElement $xml): array {
		// PERFORMANCE OPTIMIZATION: Initialize result only.
		$result = [];

		// OPTIMIZATION: Extract non-namespaced attributes (skip redundant processing).
		$attributes = $xml->attributes();
		if (count($attributes) > 0) {
			$attrBag = [];
			foreach ($attributes as $attrName => $attrValue) {
				$name = (string)$attrName;
				$value = (string)$attrValue;
				// OPTIMIZATION: Only apply str_replace for namespaced names (e.g. xsi:type -> _xsi_type).
				if (strpos($name, ':') !== false) {
					$underscoredKey = '_' . str_replace(':', '_', $name);
				} else {
					$underscoredKey = '_' . $name;
				}

				$result[$underscoredKey] = $value;
				$attrBag[$name] = $value;
			}

			$result['_attributes'] = $attrBag;
		}

		// OPTIMIZATION: Extract namespaced attributes (simplified processing).
		foreach ($xml->getNameSpaces(true) as $prefix => $nsUri) {
			$nsAttributes = $xml->attributes($prefix, true);
			if (count($nsAttributes) > 0) {
				foreach ($nsAttributes as $attrName => $attrValue) {
					$name = (string)$attrName;
					$value = (string)$attrValue;
					$underscoredKey = '_' . $prefix . '__' . $name;
					$result[$underscoredKey] = $value;
					if (isset($result['_attributes']) === false) {
						$result['_attributes'] = [];
					}

					$result['_attributes'][$prefix . ':' . $name] = $value;
				}
			}
		}

		// Extract children.
		$children = $xml->children();
		if (count($children) === 0) {
			// Leaf node: always return array shape for compatibility.
			$text = trim((string)$xml);
			if ($text !== '') {
				$result['_value'] = $text;
			}

			return $result;
		}

		// OPTIMIZATION: Process child elements with faster array operations.
		foreach ($children as $child) {
			$childName = $child->getName();
			$childValue = $this->xmlToArray(xml: $child);

			// OPTIMIZATION: Use isset instead of array_key_exists (faster).
			if (isset($result[$childName]) === false) {
				$result[$childName] = $childValue;
			} else {
				// OPTIMIZATION: Fast array conversion without expensive isAssoc check.
				if (is_array($result[$childName]) === false || isset($result[$childName][0]) === false) {
					// Convert to indexed array if it's a single value or associative array.
					$result[$childName] = [$result[$childName]];
				}

				$result[$childName][] = $childValue;
			}
		}

		// Preserve text content when children exist.
		$text = trim((string)$xml);
		if ($text !== '') {
			$result['_text'] = $text;
		}

		return $result;
	}//end xmlToArray()

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
		$startTime = microtime(true);
		$startMemory = memory_get_usage(true);

		// DEBUG: Verify that the optimized import method is being called.
		$this->logger->info('GEMMA IMPORT DEBUG: Starting optimized import', $options);

		// Starting OPTIMIZED ArchiMate XML import.
		$this->startTracking(options: $options);

		try {
			// OPTIMIZATION: Cache all configuration once at start.
			$cacheStartTime = microtime(true);
			$this->initializeCache();
			$cacheTime = microtime(true) - $cacheStartTime;

			// Cache initialization completed.
			// STEP 1: Parse XML to array (same as before).
			$this->trackPhase(phase: 'validating');
			$filePath = $this->validateArchiMateFile(options: $options);

			$this->trackPhase(phase: 'parsing');
			$parseStartTime = microtime(true);
			$xmlData = $this->parseArchiMateXml(filePath: $filePath);
			$parseTime = microtime(true) - $parseStartTime;

			if ($this->cancelRequested() === true) {
				$this->progressTracker->cancelOperation();
				return $this->cancelledResult();
			}

			// PERFORMANCE OPTIMIZATION: Clean up memory after XML parsing.
			$memoryCleanupTime = 0;
			$memCleanupStart = microtime(true);
			$this->cleanupMemory();
			$memoryCleanupTime = microtime(true) - $memCleanupStart;

			// STEP 2: Extract model identifier.
			$modelIdStartTime = microtime(true);
			$modelIdentifier = $this->extractModelIdentifier(xmlData: $xmlData);
			$modelIdentifierTime = microtime(true) - $modelIdStartTime;

			// STEP 3: Parse ALL objects in one go (like CSV import).
			$this->trackPhase(phase: 'analyzing');
			$transformStartTime = microtime(true);
			$allObjects = $this->transformArchiMateXmlToObjectsBatch(
				xmlData: $xmlData,
				modelIdentifier: $modelIdentifier
			);
			$transformTime = microtime(true) - $transformStartTime;

			if ($this->cancelRequested() === true) {
				$this->progressTracker->cancelOperation();
				return $this->cancelledResult();
			}

			// Parsed and transformed all objects.
			// STEP 4: Single saveObjects() call (like CSV import).
			$saveStartTime = microtime(true);
			$this->logger->info(
				'GEMMA IMPORT DEBUG: About to save objects to database',
				[
					'object_count' => count($allObjects),
				]
			);
			$savedObjects = $this->saveObjectsToDatabase(objects: $allObjects);
			$saveTime = microtime(true) - $saveStartTime;

			if ($this->cancelled === true) {
				return $this->cancelledResult();
			}

			$this->trackPhase(phase: 'finalizing');

			// Capture detailed save timing from internal tracking.
			$saveBreakdown = $this->lastSaveTiming;

			$totalTime = microtime(true) - $startTime;
			$itemsPerSecond = count($allObjects) / max($totalTime, 0.001);

			// Use statistics directly from saveObjects result (stored in lastSaveResult).
			// No need for custom calculation since ObjectService already provides accurate stats.
			$statistics = $this->buildStatisticsFromSaveResult();
			$detailedErrors = $this->extractDetailedErrors(statistics: $statistics);

			if ($this->operationId !== null) {
				$this->progressTracker->completeOperation();
			}

			// OPTIMIZED import completed successfully.
			return [
				'success' => true,
				'file_info' => [
					'name' => $options['fileName'] ?? basename($filePath),
					'size' => filesize($filePath),
				],
				'performance_metrics' => [
					'total_time_seconds' => round($totalTime, 3),
					'items_per_second' => round($itemsPerSecond, 1),
					'objects_processed' => count($allObjects),
					'timing_breakdown' => [
						'cache_initialization_seconds' => round($cacheTime, 3),
						'xml_parsing_seconds' => round($parseTime, 3),
						'memory_cleanup_seconds' => round($memoryCleanupTime, 3),
						'model_identifier_extraction_seconds' => round($modelIdentifierTime, 3),
						'data_transformation_seconds' => round($transformTime, 3),
						'database_save_seconds' => round($saveTime, 3),
					],
					'save_operation_breakdown' => $saveBreakdown,
					'memory_usage' => [
						'start_memory_mb' => round($startMemory / 1024 / 1024, 2),
						'current_memory_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
						'peak_memory_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
					],
					'processing_rates' => [
						'xml_parse_objects_per_second' => round(count($allObjects) / max($parseTime, 0.001), 1),
						'transform_objects_per_second' => round(count($allObjects) / max($transformTime, 0.001), 1),
						'save_objects_per_second' => round(count($allObjects) / max($saveTime, 0.001), 1),
					],
				],
				'statistics' => $statistics,
				'detailed_errors' => $detailedErrors,
			];
		} catch (\Exception $e) {
			$this->logger->error(
				'OPTIMIZED ArchiMate import failed',
				[
					'error' => $e->getMessage(),
					'file_path' => $options['file_path'] ?? 'unknown',
				]
			);

			if ($this->operationId !== null) {
				$this->progressTracker->failOperation(message: $e->getMessage());
			}

			return [
				'success' => false,
				'error' => $e->getMessage(),
			];
		}//end try
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
		// Track start time and memory for performance metrics.
		$startTime = microtime(true);
		$startMemory = memory_get_usage(true);

		// OPTIMIZATION: Cache configuration values once at the start.
		$this->initializeCache();

		// Starting ArchiMate XML import with model detection.
		try {
			// STEP 1: Parse XML to array using the specialized import service.
			// This captures ALL possible XML values including attributes, text content, and nested elements.
			$filePath = $this->validateArchiMateFile(options: $options);

			$this->logger->info('Step 1: Parsing XML to array for complete data capture', ['filePath' => $filePath]);
			$parseStartTime = microtime(true);
			$xmlData = $this->parseArchiMateXml(filePath: $filePath);
			$parseTime = microtime(true) - $parseStartTime;

			// STEP 2: Extract model identifier and detect if model already exists.
			// This is critical for determining whether to create new or update existing model.
			$this->logger->info('Step 2: Extracting model identifier and checking for existing model');
			$validationStartTime = microtime(true);
			$modelIdentifier = $this->extractModelIdentifier(xmlData: $xmlData);
			$modelExists = $this->checkIfModelExists(modelIdentifier: $modelIdentifier);
			$validationTime = microtime(true) - $validationStartTime;

			// STEP 3: Normalize data structure for storage as JSON blob.
			// Store complete raw XML data for exact round-trip fidelity during export.
			$this->logger->info('Step 3: Normalizing data structure for JSON blob storage');
			$normalizedData = $this->normalizeArchiMateData(data: $xmlData, modelIdentifier: $modelIdentifier);

			// STEP 4: Convert to OpenRegister objects with proper @self structure.
			// Each object must have @self with register, schema, and id for ObjectService::saveObjects.
			$this->logger->info('Step 4: Converting to OpenRegister objects with @self structure');
			$convertStartTime = microtime(true);
			$objects = $this->convertToOpenRegisterObjects(
				normalizedData: $normalizedData,
				modelIdentifier: $modelIdentifier
			);
			$convertTime = microtime(true) - $convertStartTime;

			// STEP 5: Save objects using ObjectService::saveObjects.
			// This handles the actual database persistence with proper validation.
			$this->logger->info('Step 5: Saving objects to database using ObjectService::saveObjects');
			$savedObjects = $this->saveObjectsToDatabase(objects: $objects);

			// Calculate total time and memory usage.
			$totalTime = microtime(true) - $startTime;
			$endMemory = memory_get_usage(true);
			$peakMemory = memory_get_peak_usage(true);

			// Count objects by type for detailed statistics.
			$statistics = $this->calculateObjectStatistics(normalizedData: $normalizedData);

			// Calculate performance metrics.
			$created = $statistics['omschrijving']['total_objects_created'];
			$updated = $statistics['omschrijving']['total_objects_updated'];
			$totalObjects = $created + $updated;
			if ($totalObjects > 0) {
				$itemsPerSecond = round($totalObjects / max($totalTime, 0.001), 2);
			} else {
				$itemsPerSecond = 0;
			}

			// Extract detailed error information from statistics.
			$detailedErrors = $this->extractDetailedErrors(statistics: $statistics);

			// Prepare comprehensive result with detailed information.
			$result = [
				'success' => true,
				'file_info' => [
					'name' => $options['fileName'] ?? basename($filePath),
					'size' => filesize($filePath),
					'mime_type' => $options['mimeType'] ?? 'text/xml',
				],
				'processing_times' => [
					'total_time_seconds' => round($totalTime, 3),
					'validation_time_seconds' => round($validationTime, 3),
					'parse_time_seconds' => round($parseTime, 3),
					'convert_time_seconds' => round($convertTime, 3),
					'performance_breakdown' => [
						'validation_percent' => round(($validationTime / $totalTime) * 100, 1),
						'parse_percent' => round(($parseTime / $totalTime) * 100, 1),
						'convert_percent' => round(($convertTime / $totalTime) * 100, 1),
					],
				],
				'memory_usage' => [
					'start_mb' => round($startMemory / 1024 / 1024, 1),
					'end_mb' => round($endMemory / 1024 / 1024, 1),
					'peak_mb' => round($peakMemory / 1024 / 1024, 2),
					'total_used_mb' => round(($endMemory - $startMemory) / 1024 / 1024, 1),
				],
				'statistics' => $statistics,
				'omschrijving' => [
					'total_objects_created' => $statistics['omschrijving']['total_objects_created'],
					'total_objects_updated' => $statistics['omschrijving']['total_objects_updated'],
					'total_objects_deleted' => $statistics['omschrijving']['total_objects_deleted'],
					'total_objects_unchanged' => $statistics['omschrijving']['total_objects_unchanged'],
					'total_errors' => $statistics['omschrijving']['total_errors'],
				],
				'performance_metrics' => [
					'items_per_second' => round($itemsPerSecond, 2),
					'processing_method' => 'synchronous_batch_processing',
					'batch_size_used' => 100,
					'dataset_size' => $totalObjects,
				],
				'detailed_errors' => $detailedErrors,
			];

			$this->logger->info(
				'ArchiMate XML import completed successfully',
				[
					'model_identifier' => $modelIdentifier,
					'model_exists' => $modelExists,
					'imported_objects' => $totalObjects,
					'round_trip_fidelity' => 'enabled',
				]
			);

			return $result;
		} catch (\Exception $e) {
			$this->logger->error(
				'ArchiMate XML import failed',
				[
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
					'file_path' => $options['filePath'] ?? $options['file_path'] ?? 'unknown',
				]
			);

			return [
				'success' => false,
				'message' => 'Import failed: ' . $e->getMessage(),
				'error' => $e->getMessage(),
				'step_failed' => 'unknown',
				// Will be refined with better error tracking.
			];
		}//end try
	}//end importArchiMateFileFromPath()

	/**
	 * Parse ArchiMate XML file to array using the import service
	 *
	 * @param string $filePath Path to XML file
	 *
	 * @return array Parsed XML data
	 *
	 * @spec openspec/specs/method-decomposition/spec.md
	 */
	private function parseArchiMateXml(string $filePath): array {
		if (file_exists($filePath) === false) {
			throw new \InvalidArgumentException("File not found: {$filePath}");
		}

		// PERFORMANCE OPTIMIZATION: Use more efficient XML parsing.
		$xmlContent = file_get_contents($filePath);
		if ($xmlContent === false) {
			throw new \RuntimeException("Failed to read file: {$filePath}");
		}

		// PERFORMANCE OPTIMIZATION: Use LIBXML_NOCDATA for faster parsing.
		// LIBXML_NONET disables network access for security.
		$xml = new SimpleXMLElement($xmlContent, LIBXML_NOCDATA | LIBXML_NONET);
		$result = $this->xmlToArray(xml: $xml);

		// PERFORMANCE OPTIMIZATION: Clear XML object from memory immediately.
		unset($xml);

		return $result;
	}//end parseArchiMateXml()

	/**
	 * Extract model identifier from parsed XML data
	 *
	 * This method looks for the model identifier in various locations within the XML:
	 * 1. Root level _attributes.identifier (most common)
	 * 2. Model element attributes
	 * 3. Fallback to generated identifier if none found
	 *
	 * @param array $xmlData Parsed XML data array
	 *
	 * @return string Model identifier for tracking and storage
	 */
	private function extractModelIdentifier(array $xmlData): string {
		$this->logger->debug(
			'Extracting model identifier from XML data',
			[
				'xml_keys' => array_keys($xmlData),
			]
		);

		// STEP 1: Try to find identifier in root attributes (most common location).
		if (isset($xmlData['_attributes']['identifier']) === true) {
			$modelId = $xmlData['_attributes']['identifier'];
			$this->logger->info(
				'Found model identifier in root attributes',
				[
					'identifier' => $modelId,
				]
			);
			return $modelId;
		}

		// STEP 2: Look for model element with identifier.
		if (isset($xmlData['model']) === true && is_array($xmlData['model']) === true) {
			if (isset($xmlData['model']['_attributes']['identifier']) === true) {
				$modelId = $xmlData['model']['_attributes']['identifier'];
				$this->logger->info(
					'Found model identifier in model element attributes',
					[
						'identifier' => $modelId,
					]
				);
				return $modelId;
			}
		}

		// STEP 3: Look for archimate:model namespace (ArchiMate Tool format).
		if (isset($xmlData['archimate:model']) === true && is_array($xmlData['archimate:model']) === true) {
			if (isset($xmlData['archimate:model']['_attributes']['identifier']) === true) {
				$modelId = $xmlData['archimate:model']['_attributes']['identifier'];
				$this->logger->info(
					'Found model identifier in archimate:model namespace',
					[
						'identifier' => $modelId,
					]
				);
				return $modelId;
			}
		}

		// STEP 4: Generate fallback identifier if none found.
		$fallbackId = 'model-' . uniqid() . '-' . time();
		$this->logger->warning(
			'No model identifier found, generating fallback',
			[
				'fallback_id' => $fallbackId,
			]
		);

		return $fallbackId;
	}//end extractModelIdentifier()

	/**
	 * Check if a model already exists in the database
	 *
	 * @param string $modelIdentifier The model identifier to check
	 *
	 * @return bool True if model exists, false otherwise
	 */
	private function checkIfModelExists(string $modelIdentifier): bool {
		$this->logger->debug(
			'Checking if model already exists',
			[
				'model_identifier' => $modelIdentifier,
			]
		);

		try {
			// Get ObjectService to query existing objects.
			$objectService = $this->getObjectService();
			if ($objectService === null) {
				$this->logger->warning('ObjectService not available, assuming new model');
				return false;
			}

			// Get AMEF configuration for register and schema IDs.
			$registerId = $this->getAmefRegisterId();
			$schemaId = $this->getAmefSchemaIdForType(archiMateType: 'model');

			if ($registerId === null || $schemaId === false) {
				$this->logger->warning(
					'AMEF register or model schema not configured, assuming new model',
					[
						'registerId' => $registerId,
						'schemaId' => $schemaId,
					]
				);
				return false;
			}

			// Query for existing model objects with this identifier.
			// Use searchObjects with @self structure for proper querying.
			$query = [
				'@self' => [
					'register' => $registerId,
					'schema' => $schemaId,
				],
				'archimate_id' => $modelIdentifier,
				// Existence check by a specific identifier — 1 is enough.
				'_limit' => 1,
			];

			$existingModels = $objectService->searchObjects($query);

			$exists = empty($existingModels) === false;

			$this->logger->info(
				'Model existence check completed',
				[
					'model_identifier' => $modelIdentifier,
					'exists' => $exists,
					'found_count' => count($existingModels),
					'registerId' => $registerId,
					'schemaId' => $schemaId,
				]
			);

			return $exists;
		} catch (\Exception $e) {
			$this->logger->error(
				'Error checking model existence',
				[
					'model_identifier' => $modelIdentifier,
					'error' => $e->getMessage(),
				]
			);
			// If we can't check, assume new model to avoid data loss.
			return false;
		}//end try
	}//end checkIfModelExists()

	/**
	 * Normalize ArchiMate data structure for storage as JSON blob
	 *
	 * This method processes the parsed XML data and prepares it for storage:
	 * 1. Extracts model metadata (identifier, name, version, etc.)
	 * 2. Processes each section (elements, relationships, organizations, views, property_definitions)
	 * 3. Stores complete raw XML data for each item to ensure round-trip fidelity
	 * 4. Adds model identifier to each item for proper linking
	 *
	 * @param array $data Raw parsed XML data from import service
	 * @param string $modelIdentifier The model identifier for linking items
	 *
	 * @return array Normalized data structure ready for database storage
	 */
	private function normalizeArchiMateData(array $data, string $modelIdentifier): array {
		$this->logger->info(
			'Normalizing ArchiMate data structure for JSON blob storage',
			[
				'model_identifier' => $modelIdentifier,
			]
		);

		// STEP 0: Extract propertyDefinition map and store in model metadata.
		$propDefMap = $this->extractPropertyDefinitionMap(data: $data);

		// Log property mapping for debugging.
		if (empty($propDefMap) === false) {
			$this->logger->info(
				'Property definitions extracted and mapped',
				[
					'total_properties' => count($propDefMap),
					'property_mapping' => $this->getPropertyNameMapping(propDefMap: $propDefMap),
				]
			);
		}

		// Initialize normalized structure with model metadata.
		$normalized = [
			'model_metadata' => [],
			'model_identifier' => $modelIdentifier,
			// Add model identifier for linking.
			'elements' => [],
			'relationships' => [],
			'organizations' => [],
			'views' => [],
			'property_definitions' => [],
		];

		// STEP 1: Extract and store model metadata.
		if (isset($data['_attributes']) === true) {
			$normalized['model_metadata'] = $data['_attributes'];
		}

		// Also extract name and documentation from root level.
		if (isset($data['name']) === true) {
			$normalized['model_metadata']['name'] = $data['name'];
		}

		if (isset($data['documentation']) === true) {
			$normalized['model_metadata']['documentation'] = $data['documentation'];
		}

		if (isset($data['properties']) === true) {
			$normalized['model_metadata']['properties'] = $data['properties'];
		}

		// Store propertyDefinitionMap in model_metadata.
		$normalized['model_metadata']['propertyDefinitionMap'] = $propDefMap;

		$this->logger->debug(
			'Extracted model metadata',
			[
				'metadata_keys' => array_keys($normalized['model_metadata']),
				'has_name' => isset($normalized['model_metadata']['name']) === true,
				'has_documentation' => isset($normalized['model_metadata']['documentation']) === true,
			]
		);

		// STEP 2: Process each section and store complete raw XML data.
		$scope = new ArchiMateImportScope(modelIdentifier: $modelIdentifier, propDefMap: $propDefMap);
		$sections = ['elements', 'relationships', 'organizations', 'views', 'property_definitions'];
		$alternativeNames = [
			'views' => ['views', 'diagrams'],
			'organizations' => ['organizations', 'organisation'],
			'property_definitions' => ['propertyDefinitions', 'property_definitions', 'propertydefinitions'],
		];
		foreach ($sections as $section) {
			$sectionData = null;
			$actualSectionName = null;
			if (isset($data[$section]) === true) {
				$sectionData = $data[$section];
				$actualSectionName = $section;
			} else {
				if (isset($alternativeNames[$section]) === true) {
					foreach ($alternativeNames[$section] as $altName) {
						if (isset($data[$altName]) === true) {
							$sectionData = $data[$altName];
							$actualSectionName = $altName;
							break;
						}
					}
				}
			}

			if ($sectionData !== null) {
				// Organizations are hierarchical folder trees, not flat objects with identifiers.
				// Store the entire tree as one raw entry so round-trip export can reconstruct it.
				if ($section === 'organizations') {
					$syntheticId = 'org-' . preg_replace('/^id-/', '', $modelIdentifier);
					$normalized[$section][$syntheticId] = [
						'identifier' => $syntheticId,
						'section' => 'organization',
						'model_identifier' => $modelIdentifier,
						'name' => 'Organizations',
						// Complete hierarchy preserved.
						'xml' => $sectionData,
					];
				} else {
					$normalized[$section] = $this->extractSectionDataWithProperties(
						sectionData: $sectionData,
						scope: $scope->withSection(section: $section)
					);
				}
			}//end if
		}//end foreach

		$this->logger->info(
			'Data normalization completed',
			[
				'model_identifier' => $modelIdentifier,
				'sections_processed' => $sections,
				'round_trip_fidelity' => 'enabled',
			]
		);
		return $normalized;
	}//end normalizeArchiMateData()

	/**
	 * Extract data from a specific section, flatten properties, and store xml
	 *
	 * @param mixed                $sectionData Section data from XML parsing
	 * @param ArchiMateImportScope $scope       The import scope for the section being processed
	 *
	 * @return array Extracted section data with complete XML preservation and flattened properties
	 */
	private function extractSectionDataWithProperties(mixed $sectionData, ArchiMateImportScope $scope): array {
		$extracted = [];
		if (is_array($sectionData) === false) {
			return $extracted;
		}

		$sectionName = $scope->section();
		$items = $this->findItemsInSection(sectionData: $sectionData, sectionName: $sectionName);
		foreach ($items as $item) {
			$identifier = $this->extractIdentifier(item: $item, sectionName: $sectionName);
			if (empty($identifier) === true) {
				continue;
			}

			// OPTIMIZATION: Store only essential XML data, without an expensive deep copy.
			$object = [
				'identifier' => $identifier,
				'section' => $sectionName,
				'model_identifier' => $scope->modelIdentifier(),
				'extracted_at' => time(),
				'xml' => $this->extractEssentialXmlData(item: $item),
			];

			$this->copyXsiType(object: $object, item: $item);
			$this->copyNameAndDocumentation(object: $object, item: $item);

			// Flatten properties to root fields using the propertyDefinitionMap.
			if (isset($item['properties']['property']) === true) {
				$this->flattenSectionProperties(
					object: $object,
					scope: $scope->withProperties(properties: $this->definedPropertyList(properties: $item['properties']['property']))
				);
			}

			$extracted[$identifier] = $object;
		}//end foreach

		return $extracted;
	}//end extractSectionDataWithProperties()

	/**
	 * Copy an item's name, and its documentation as both omschrijving and documentation, when they have text.
	 *
	 * @param array $object The object being built
	 * @param array $item   The raw XML item
	 *
	 * @return void
	 */
	private function copyNameAndDocumentation(array &$object, array $item): void {
		if (isset($item['name']) === true) {
			$name = $this->textValue(value: $item['name']);
			if ($name !== null) {
				$object['name'] = $name;
			}
		}

		if (isset($item['documentation']) === true) {
			$docValue = $this->textValue(value: $item['documentation']);
			if ($docValue !== null) {
				$object['omschrijving'] = $docValue;
				$object['documentation'] = $docValue;
			}
		}
	}//end copyNameAndDocumentation()

	/**
	 * A raw property list as a list; a single property only counts when it names its definition.
	 *
	 * @param array $properties The raw `properties.property` value
	 *
	 * @return array The properties as a list
	 */
	private function definedPropertyList(array $properties): array {
		if (isset($properties[0]) === true) {
			return $properties;
		}

		if (isset($properties['_attributes']['propertyDefinitionRef']) === true) {
			return [$properties];
		}

		return [];
	}//end definedPropertyList()

	/**
	 * Set each defined property on the object under its camelCase name; an "Object ID" also becomes the _slug.
	 *
	 * @param array                $object The object being built
	 * @param ArchiMateImportScope $scope  The scope with the item's property list and the definition map
	 *
	 * @return void
	 */
	private function flattenSectionProperties(array &$object, ArchiMateImportScope $scope): void {
		$propDefMap = $scope->propDefMap();
		foreach ($scope->properties() as $prop) {
			$defRef = $prop['_attributes']['propertyDefinitionRef'] ?? null;
			$value = $prop['value']['_value'] ?? $prop['value'] ?? null;
			if (isset($propDefMap[$defRef]) === false) {
				continue;
			}

			$name = $propDefMap[$defRef];
			$camelCaseName = $this->convertToCamelCase(propertyName: $name);
			$object[$camelCaseName] = $value;

			// Store property mapping for reference.
			$object['_propertyMapping'][$camelCaseName] = $name;

			// Stored temporarily, moved to @self.slug later.
			if (strtolower($name) === 'object id') {
				$object['_slug'] = $value;
			}
		}
	}//end flattenSectionProperties()

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
				foreach ($normalizedData[$section] as $data) {
					$objects[] = $this->createSectionObject(section: $section, data: $data);
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
		$registerId = $this->registerId();
		$modelSchemaIds = $this->cachedConfig['schemaIds']['model'] ?? null;
		if ($modelSchemaIds === null) {
			throw new \RuntimeException(
				"Schema ID for 'model' not found."
			);
		}

		$schemaId = $modelSchemaIds;

		// Extract a plain string name (schema column expects string, not array).
		$nameString = null;
		if (isset($metadata['name']) === true) {
			if (is_array($metadata['name']) === true && isset($metadata['name']['_value']) === true) {
				$nameString = (string)$metadata['name']['_value'];
			} elseif (is_string($metadata['name']) === true) {
				$nameString = $metadata['name'];
			}
		}

		// Build xml field preserving full array structure for round-trip fidelity.
		$xmlData = [];
		if (isset($metadata['name']) === true) {
			$xmlData['name'] = $metadata['name'];
		}

		if (isset($metadata['documentation']) === true) {
			$xmlData['documentation'] = $metadata['documentation'];
		}

		if (isset($metadata['properties']) === true) {
			$xmlData['properties'] = $metadata['properties'];
		}

		if (isset($metadata['propertyDefinitionMap']) === true) {
			$xmlData['propertyDefinitionMap'] = $metadata['propertyDefinitionMap'];
		}

		// Create object with @self structure and metadata at root level (no JSON serialization).
		$object = [
			'@self' => [
				'register' => $registerId,
				'schema' => $schemaId,
				'id' => $metadata['identifier'] ?? uniqid('model_'),
				'owner' => $this->getCurrentUserId(),
				'organisation' => $this->getCurrentOrganisation(),
				'published' => date('Y-m-d\TH:i:s\Z'),
			],
			'identifier' => $metadata['identifier'] ?? '',
			'section' => 'model',
			'model_identifier' => $modelIdentifier,
			'xml' => $xmlData,
		];

		// Merge metadata directly at root level, but override name with string version.
		$merged = array_merge($object, $metadata);
		if ($nameString !== null) {
			$merged['name'] = $nameString;
		}

		return $merged;
	}//end createModelObject()

	/**
	 * Create section object with @self structure and flattened XML data
	 *
	 * The object's id is its flattened "Object ID" when it has one, with the AMEF identifier as
	 * slug; otherwise the identifier is the id and the identifier without "id-" the slug.
	 *
	 * @param string $section Section name
	 * @param array  $data    Item data with its identifier (already contains XML data at root level)
	 *
	 * @return array Section object with @self structure
	 */
	private function createSectionObject(string $section, array $data): array {
		$identifier = $data['identifier'];

		// OPTIMIZATION: Use cached configuration values.
		$registerId = $this->registerId();
		$schemaId = $this->cachedConfig['schemaIds'][$section] ?? $this->getSchemaIdForSection(section: $section);

		$slug = $identifier;
		if (isset($data['objectId']) === true) {
			$objectId = $data['objectId'];
		} elseif (isset($data['_slug']) === true) {
			// The temporary _slug field (legacy support) is removed once read.
			$objectId = $data['_slug'];
			unset($data['_slug']);
		} elseif (isset($data['Object ID']) === true) {
			$objectId = $data['Object ID'];
		} else {
			$objectId = $identifier;
			$slug = $this->slugFromIdentifier(identifier: $identifier);
		}

		$object = [
			'@self' => [
				'register' => $registerId,
				'schema' => $schemaId,
				'id' => $objectId,
				'slug' => $slug,
				'owner' => $this->getCurrentUserId(),
				'organisation' => $this->getCurrentOrganisation(),
				'published' => date('Y-m-d\TH:i:s\Z'),
			],
		];

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

		// DEBUG: Log basic object info before sending to ObjectService.
		// Find first element with gemmaType for debugging.
		$gemmaElements = array_filter(
			$objects,
			fn ($o) => ($o['section'] ?? '') === 'element' && empty($o['gemmaType']) === false
		);
		$this->logger->debug(
			'Objects before save',
			[
				'total_objects_to_save' => count($objects),
				'elements_with_gemmaType' => count($gemmaElements),
			]
		);

		$serviceInitStartTime = microtime(true);
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			throw new \RuntimeException('ObjectService not available');
		}

		$serviceInitTime = microtime(true) - $serviceInitStartTime;

		// ENHANCEMENT: Process GEMMA Referentiecomponent-Standaard relationships before saving.
		$gemmaStartTime = microtime(true);
		$objects = (new GemmaStandardLinker(logger: $this->logger))->link(objects: $objects);
		$gemmaProcessingTime = microtime(true) - $gemmaStartTime;

		// Saving objects to database.
		// OPTIMIZATION: Use cached register ID.
		$registerId = $this->registerId();

		// MAGIC MAPPING SUPPORT: Group objects by schema first, then save each schema group.
		// This ensures each batch has a single schema so UnifiedObjectMapper can route to the correct magic table.
		$batchStartTime = microtime(true);

		// Group objects by schema.
		$schemaGroups = [];
		foreach ($objects as $obj) {
			$schemaId = $obj['@self']['schema'] ?? 'unknown';
			$schemaGroups[$schemaId][] = $obj;
		}

		$this->logger->info(
			'ArchiMate import: Grouped objects by schema for magic mapping',
			[
				'schemaCount' => count($schemaGroups),
				'schemas' => array_map('count', $schemaGroups),
			]
		);

		$groupResult = $this->saveSchemaGroups(schemaGroups: $schemaGroups, objectService: $objectService);
		$allResults = $groupResult['results'];
		$aggregatedStats = $groupResult['stats'];
		$countsBySchema = $aggregatedStats['countsBySchema'];
		unset($aggregatedStats['countsBySchema']);

		// Store aggregated result for statistics, including per-schema counts.
		$aggregatedStats['countsBySchema'] = $countsBySchema;
		$this->lastSaveResult = $aggregatedStats;
		$result = $allResults;

		$batchProcessingTime = microtime(true) - $batchStartTime;

		// POST-PROCESSING: Fix StandaardVersie standaard field UUIDs.
		// The standaard field was set with ArchiMate identifiers, but we need database UUIDs.
		// for the inversedBy lookup to work correctly.
		$this->fixStandaardVersieUuids(registerId: $registerId);

		$totalSaveTime = microtime(true) - $saveStartTime;

		// Database save completed.
		// Store timing breakdown for performance metrics.
		// FIX: Use aggregatedStats counts instead of $result which may be empty from bulk operations.
		$savedCount = count($aggregatedStats['saved'] ?? []);
		$updatedCount = count($aggregatedStats['updated'] ?? []);
		$unchangedCount = count($aggregatedStats['unchanged'] ?? []);
		$totalSavedCount = $savedCount + $updatedCount + $unchangedCount;
		if ($totalSavedCount > 0) {
			$objectsSavedValue = $totalSavedCount;
		} else {
			$objectsSavedValue = count($objects);
		}

		$this->lastSaveTiming = [
			'total_save_seconds' => round($totalSaveTime, 3),
			'service_init_seconds' => round($serviceInitTime, 3),
			'gemma_processing_seconds' => round($gemmaProcessingTime, 3),
			'batch_processing_seconds' => round($batchProcessingTime, 3),
			'objects_saved' => $objectsSavedValue,
			'save_rate_objects_per_second' => round(count($objects) / max($totalSaveTime, 0.001), 1),
		];

		return $result;
	}//end saveObjectsToDatabase()

	/**
	 * Save the objects one schema group at a time, recording progress and honouring a cancel.
	 *
	 * @param array<int|string, array<int, array<string, mixed>>> $schemaGroups  Objects keyed by schema id
	 * @param ObjectServiceInterface                              $objectService OpenRegister's object service
	 *
	 * @return array{results: array, stats: array} The saved objects and the aggregated statistics
	 *
	 * @spec openspec/specs/archimate-import-progress/spec.md#requirement-req-aip-001-a-running-import-shall-record-its-phase-and-the-objects-saved-so-far
	 */
	private function saveSchemaGroups(array $schemaGroups, ObjectServiceInterface $objectService): array {
		$registerId = $this->registerId();
		$outcome = ['results' => [], 'stats' => array_fill_keys(self::SAVE_BUCKETS, [])];
		// Track counts per schema for accurate statistics (serialized objects lose the 'section' field).
		$countsBySchema = [];

		$processed = 0;
		$this->trackPhase(
			phase: 'processing_elements',
			data: ['total_items' => array_sum(array_map('count', $schemaGroups)), 'reset_progress' => true]
		);

		foreach ($schemaGroups as $schemaId => $schemaObjects) {
			$schemaObjectCount = count($schemaObjects);

			// A cancel stops the import before its next batch; what is saved stays saved.
			if ($this->cancelRequested() === true) {
				$this->progressTracker->cancelOperation();
				break;
			}

			try {
				// Save this schema group with the specific schema ID.
				// PERFORMANCE: Disabled validation and events for bulk import (like CSV import pattern).
				$schemaValue = null;
				if ($schemaId !== 'unknown') {
					$schemaValue = $schemaId;
				}

				$saveResult = $objectService->saveObjects(
					objects: $schemaObjects,
					register: $registerId,
					schema: $schemaValue,
					_rbac: false,
					_multitenancy: false,
					validation: false,
					events: false
				);

				$countsBySchema[$schemaId] = $this->addSaveResult(outcome: $outcome, saveResult: $saveResult);

				$this->logger->debug(
					'Schema group saved for magic mapping',
					[
						'schemaId' => $schemaId,
						'objectCount' => $schemaObjectCount,
						'saved' => $countsBySchema[$schemaId]['saved'],
						'updated' => $countsBySchema[$schemaId]['updated'],
					]
				);
			} catch (\Exception $e) {
				$this->logger->error(
					'Error saving schema group',
					[
						'schemaId' => $schemaId,
						'error' => $e->getMessage(),
						'objectCount' => $schemaObjectCount,
					]
				);
			}//end try

			$processed += $schemaObjectCount;
			if ($this->operationId !== null) {
				$this->progressTracker->updateProgress(processedItems: $processed, itemType: (string) $schemaId);
			}
		}//end foreach

		$outcome['stats']['countsBySchema'] = $countsBySchema;

		return $outcome;
	}//end saveSchemaGroups()

	/**
	 * Add one schema group's save result to the running outcome.
	 *
	 * @param array{results: array, stats: array} $outcome    The saved objects and statistics so far
	 * @param array                               $saveResult What saveObjects returned for the group
	 *
	 * @return array{saved: int, updated: int, unchanged: int, invalid: int} The group's counts
	 */
	private function addSaveResult(array &$outcome, array $saveResult): array {
		$counts = [];
		foreach (self::SAVE_BUCKETS as $bucket) {
			$outcome['stats'][$bucket] = array_merge($outcome['stats'][$bucket], $saveResult[$bucket] ?? []);
			$counts[$bucket] = count($saveResult[$bucket] ?? []);
		}

		$outcome['results'] = array_merge(
			$outcome['results'],
			$saveResult['saved'] ?? [],
			$saveResult['updated'] ?? [],
			$saveResult['unchanged'] ?? []
		);

		return $counts;
	}//end addSaveResult()

	/**
	 * The cached AMEF register id.
	 *
	 * @return int The register id
	 *
	 * @throws \RuntimeException When the AMEF configuration names no register
	 */
	private function registerId(): int {
		return $this->cachedConfig['registerId'] ?? throw new \RuntimeException(
			'Register ID not found. Ensure AMEF config is initialized.'
		);
	}//end registerId()

	/**
	 * Start recording progress when the upload names a well-formed operation id.
	 *
	 * An id outside the pattern is ignored, so a caller cannot write into another
	 * operation's keys; the import then runs without progress.
	 *
	 * @param array<string, mixed> $options Import options, optionally with operationId
	 *
	 * @return void
	 *
	 * @spec openspec/specs/archimate-import-progress/spec.md#requirement-req-aip-001-a-running-import-shall-record-its-phase-and-the-objects-saved-so-far
	 */
	public function startTracking(array $options): void {
		$this->operationId = null;
		$this->cancelled   = false;

		$operationId = $options['operationId'] ?? null;
		if (is_string($operationId) === false || preg_match(self::OPERATION_ID_PATTERN, $operationId) !== 1) {
			return;
		}

		$this->operationId = $this->progressTracker->startOperation(
			operationType: 'archimate_import',
			operationId: $operationId
		);
	}//end startTracking()

	/**
	 * Whether the last import stopped on a cancel.
	 *
	 * @return bool True when it was cancelled
	 *
	 * @spec openspec/specs/archimate-import-progress/spec.md#requirement-req-aip-002-an-admin-shall-be-able-to-cancel-a-running-import
	 */
	public function wasCancelled(): bool {
		return $this->cancelled;
	}//end wasCancelled()

	/**
	 * Record the phase the import is in, when it is tracked.
	 *
	 * @param string               $phase The ProgressTracker phase
	 * @param array<string, mixed> $data  Phase data such as total_items
	 *
	 * @return void
	 */
	private function trackPhase(string $phase, array $data = []): void {
		if ($this->operationId !== null) {
			$this->progressTracker->setPhase(phase: $phase, data: $data);
		}
	}//end trackPhase()

	/**
	 * Whether a cancel was requested for the tracked import; remembers a yes.
	 *
	 * @return bool True when the import must stop
	 */
	private function cancelRequested(): bool {
		if ($this->operationId !== null && $this->progressTracker->isCancelRequested($this->operationId) === true) {
			$this->cancelled = true;
		}

		return $this->cancelled;
	}//end cancelRequested()

	/**
	 * The result of an import that stopped on a cancel, with what it had saved.
	 *
	 * @return array<string, mixed> The cancelled result
	 */
	private function cancelledResult(): array {
		$stats  = $this->lastSaveResult ?? [];
		$counts = [
			'saved'     => count($stats['saved'] ?? []),
			'updated'   => count($stats['updated'] ?? []),
			'unchanged' => count($stats['unchanged'] ?? []),
			'invalid'   => count($stats['invalid'] ?? []),
		];

		return [
			'success'       => false,
			'cancelled'     => true,
			'message'       => 'Import cancelled',
			'counts'        => $counts,
			'objects_saved' => $counts['saved'] + $counts['updated'] + $counts['unchanged'],
		];
	}//end cancelledResult()

	/**
	 * Fix StandaardVersie standaard field UUIDs after import
	 *
	 * The ArchiMate import sets the standaard field with ArchiMate identifiers (e.g., "92b166c5...")
	 * but the inversedBy lookup needs database UUIDs. This method:
	 * 1. Queries all Standaarden to get identifier → uuid mapping
	 * 2. Updates StandaardVersie objects to use the correct database UUIDs
	 *
	 * @param int $registerId The register ID
	 *
	 * @return void
	 */
	private function fixStandaardVersieUuids(int $registerId): void {
		try {
			$elementSchemaId = $this->cachedConfig['schemaIds']['element'] ?? null;
			if ($elementSchemaId === null) {
				$this->logger->warning('fixStandaardVersieUuids: Element schema ID not found in config');
				return;
			}

			// Get database connection.
			$connection = $this->dbConnection;
			$tableName = 'oc_openregister_table_' . $registerId . '_' . $elementSchemaId;

			// Step 1: Build a mapping from ArchiMate identifier to database UUID for Standaarden.
			// The identifier field contains "id-{archimate_id}" and we need the _uuid field.
			$standardQuery = $connection->executeQuery(
				"SELECT _uuid, identifier FROM {$tableName} WHERE gemma_type = 'Standaard' AND identifier IS NOT NULL"
			);

			$identifierToUuid = [];
			while (($row = $standardQuery->fetch()) !== false) {
				$identifier = (string) ($row['identifier'] ?? '');
				$uuid = (string) ($row['_uuid'] ?? '');

				if ($identifier !== '' && $uuid !== '') {
					// Store mapping without "id-" prefix for matching.
					$cleanId = str_replace('id-', '', $identifier);
					$identifierToUuid[$cleanId] = $uuid;
				}
			}

			$this->logger->info(
				'fixStandaardVersieUuids: Built identifier->uuid mapping',
				[
					'standaard_count' => count($identifierToUuid),
				]
			);

			if (empty($identifierToUuid) === true) {
				$this->logger->warning('fixStandaardVersieUuids: No Standaarden found to map');
				return;
			}

			// Step 2: Update StandaardVersies that have a standaard field with ArchiMate identifiers.
			// We need to replace ArchiMate IDs with database UUIDs.
			$updateCount = 0;

			foreach ($identifierToUuid as $archiMateId => $dbUuid) {
				// Update all StandaardVersies where standaard matches this ArchiMate ID.
				$result = $connection->executeStatement(
					"UPDATE {$tableName} SET standard = ? WHERE standard = ? AND gemma_type = 'Standaardversie'",
					[$dbUuid, $archiMateId]
				);
				$updateCount += $result;
			}

			$this->logger->info(
				'fixStandaardVersieUuids: Updated StandaardVersie standaard fields',
				[
					'updated_count' => $updateCount,
					'mapping_count' => count($identifierToUuid),
				]
			);
		} catch (\Exception $e) {
			$this->logger->error(
				'fixStandaardVersieUuids: Error fixing UUIDs',
				[
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				]
			);
		}//end try
	}//end fixStandaardVersieUuids()

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

		$this->cachedConfig = [
			'userId' => $this->userSession->getUser()?->getUID(),
			'organisation' => 'default',
			'registerId' => $this->getAmefRegisterId(),
			'schemaIds' => [
				'model' => $this->getAmefSchemaIdForType(archiMateType: 'model'),
				'element' => $this->getAmefSchemaIdForType(archiMateType: 'element'),
				'relationship' => $this->getAmefSchemaIdForType(archiMateType: 'relationship'),
				'view' => $this->getAmefSchemaIdForType(archiMateType: 'view'),
				'organization' => $this->getAmefSchemaIdForType(archiMateType: 'organization'),
				'property_definition' => $this->getAmefSchemaIdForType(archiMateType: 'property_definition'),
				// NOTE: 'property' removed - properties are never root-level
				// AMEF objects, only nested within other elements.
			],
		];
	}//end initializeCache()

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
	 * Get current user ID from cache
	 *
	 * @return string|null Current user ID or null if not authenticated
	 */
	private function getCurrentUserId(): ?string {
		return $this->cachedConfig['userId'] ?? null;
	}//end getCurrentUserId()

	/**
	 * Get current organisation UUID from OrganisationService
	 *
	 * @return string Organisation UUID
	 */
	private function getCurrentOrganisation(): string {
		try {
			$this->logger->info('Getting default organisation from OrganisationService');
			$defaultOrganisation = $this->organisationService->ensureDefaultOrganisation();
			$uuid = $defaultOrganisation->getUuid();

			$this->logger->info('Got default organisation UUID: ' . $uuid);
			return $uuid;
		} catch (\Exception $e) {
			$this->logger->error('Failed to get default organisation: ' . $e->getMessage());
			$this->logger->error('Exception trace: ' . $e->getTraceAsString());
			// Fallback to cached value or 'default' string.
			return $this->cachedConfig['organisation'] ?? 'default';
		}
	}//end getCurrentOrganisation()

	/**
	 * Read a configured id, failing closed on the empty default.
	 *
	 * The legacy fallback used to read every id with `''` as its default, so
	 * an unconfigured instance produced a config array full of empty STRINGS.
	 * Its consumers guard with `=== null` — `getAmefRegisterId()` and
	 * `getAmefSchemaIdForType()` reject them with `is_numeric()` + `> 0` — and `'' === null` is
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
	 * Get AMEF register ID from configuration
	 *
	 * @return int|null The register ID or null if not configured
	 */
	private function getAmefRegisterId(): ?int {
		// Retrieve AMEF configuration.
		$amefConfig = $this->getAmefConfig();

		// Try JSON config keys first: support both 'register_id' and 'register'.
		$rawRegisterId = $amefConfig['register_id'] ?? $amefConfig['register'] ?? null;

		// Fallback to legacy individual app config keys if not present in JSON.
		if ($rawRegisterId === null || $rawRegisterId === '') {
			$rawRegisterId = $this->config->getValueString('stackiq', 'amef_register_id', '');
			if ($this->config->getValueString('stackiq', 'amef_register', '') !== '') {
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
	 * Get AMEF schema ID for a specific ArchiMate type
	 *
	 * This method retrieves the schema ID for a given ArchiMate type from the AMEF configuration.
	 * It looks for the schema ID using the pattern '{type}_schema' in the configuration.
	 *
	 * @param string $archiMateType The ArchiMate type (e.g., 'element', 'organization', 'relationship')
	 *
	 * @return int|null The schema ID for the given type or null if not configured
	 */
	private function getAmefSchemaIdForType(string $archiMateType): ?int {
		// Get AMEF configuration.
		$amefConfig = $this->getAmefConfig();

		// Normalize plural → singular and handle the actual config structure.
		$typeMapping = [
			'elements' => 'element',
			'organizations' => 'organization',
			// Accept both 'relationships' (AMEF wording) and UI term 'relation'.
			'relationships' => 'relation',
			'views' => 'view',
			'models' => 'model',
			// NOTE: 'properties' mapping removed - properties are never root-level AMEF objects.
			'property_definitions' => 'property_definition',
		];
		$normalizedType = $typeMapping[$archiMateType] ?? $archiMateType;

		// Candidate keys: match the actual config structure.
		$schemaCandidates = [
			'element' => ['element_schema'],
			'organization' => ['organization_schema'],
			'relationship' => ['relation_schema'],
			'view' => ['view_schema'],
			'model' => ['model_schema'],
			'property_definition' => ['property_definition_schema'],
			// NOTE: 'property' removed - properties are never root-level AMEF objects, only nested within other elements.
		];

		$candidates = $schemaCandidates[$normalizedType] ?? [$normalizedType . '_schema'];

		// Try JSON config with the actual keys.
		foreach ($candidates as $key) {
			if (array_key_exists($key, $amefConfig) === true) {
				$raw = $amefConfig[$key];
				if ($raw !== '' && $raw !== null && is_numeric((string)$raw) === true) {
					$id = (int)$raw;
					if ($id > 0) {
						return $id;
					}
				}
			}
		}

		// Fallback to legacy individual app config keys if not present in JSON.
		foreach ($candidates as $key) {
			$raw = $this->config->getValueString('stackiq', $key, '');
			if ($this->config->getValueString('stackiq', 'amef_' . $key, '') !== '') {
				$raw = $this->config->getValueString('stackiq', 'amef_' . $key, '');
			}

			if ($raw !== '' && is_numeric((string)$raw) === true) {
				$id = (int)$raw;
				if ($id > 0) {
					return $id;
				}
			}
		}

		return null;
	}//end getAmefSchemaIdForType()

	/**
	 * Get schema ID for a section using the object type locator (no hardcoded fallbacks)
	 *
	 * @param string $section Section name
	 *
	 * @return int Schema ID
	 * @throws \RuntimeException If schema ID is not configured
	 */
	private function getSchemaIdForSection(string $section): int {
		// Map section names to object types for the object type locator.
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
			throw new \RuntimeException(
				"Schema ID for section '{$section}' is not configured. Expected object type: '{$objectType}'"
			);
		}

		return $schemaId;
	}//end getSchemaIdForSection()

	/**
	 * Extract propertyDefinitions from the parsed XML and build a map
	 *
	 * @param array $data Parsed XML data
	 *
	 * @return array Map of propertyDefinitionRef => property name
	 */
	private function extractPropertyDefinitionMap(array $data): array {
		// OPTIMIZATION: Return cached property definition map if available.
		if ($this->propMapCache !== null) {
			return $this->propMapCache;
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
						if (is_array($def['name']) === true
							&& isset($def['name']['_value']) === true
						) {
							$map[$def['_attributes']['identifier']] = $def['name']['_value'];
						} else {
							$map[$def['_attributes']['identifier']] = $def['name'];
						}
					}
				}
			} elseif (isset($defs['_attributes']['identifier']) === true && isset($defs['name']) === true) {
				// Single propertyDefinition.
				if (is_array($defs['name']) === true
					&& isset($defs['name']['_value']) === true
				) {
					$map[$defs['_attributes']['identifier']] = $defs['name']['_value'];
				} else {
					$map[$defs['_attributes']['identifier']] = $defs['name'];
				}
			}//end if
		}//end if

		// OPTIMIZATION: Cache the result for subsequent calls during the same import.
		$this->propMapCache = $map;

		return $map;
	}//end extractPropertyDefinitionMap()

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
			// Skip non-string values (e.g., empty arrays from incomplete property definitions).
			if (is_string($originalName) === false) {
				continue;
			}

			$mapping[$originalName] = $this->convertToCamelCase(propertyName: $originalName);
		}

		return $mapping;
	}//end getPropertyNameMapping()

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
		return $this->camelCaseNames->of(propertyName: $propertyName);
	}//end convertToCamelCase()

	/**
	 * Build statistics structure from ObjectService save result.
	 * Simply converts the ObjectService result format to ArchiMate statistics format.
	 *
	 * @return array Statistics with created, updated, unchanged counts and summary.
	 */
	private function buildStatisticsFromSaveResult(): array {
		// Initialize empty statistics.
		$statistics = [
			'elements' => [
				'created' => 0,
				'updated' => 0,
				'unchanged' => 0,
				'errors' => [],
			],
			'relationships' => [
				'created' => 0,
				'updated' => 0,
				'unchanged' => 0,
				'errors' => [],
			],
			'organizations' => [
				'created' => 0,
				'updated' => 0,
				'unchanged' => 0,
				'errors' => [],
			],
			'views' => [
				'created' => 0,
				'updated' => 0,
				'unchanged' => 0,
				'errors' => [],
			],
			'property_definitions' => [
				'created' => 0,
				'updated' => 0,
				'unchanged' => 0,
				'errors' => [],
			],
			'omschrijving' => [
				'total_objects_created' => 0,
				'total_objects_updated' => 0,
				'total_objects_deleted' => 0,
				'total_objects_unchanged' => 0,
				'total_errors' => 0,
			],
		];

		// If no save result available, return empty statistics.
		if ($this->lastSaveResult === null) {
			return $statistics;
		}

		$saveResult = $this->lastSaveResult;

		// Use per-schema counts if available (reliable — objects are saved per-schema-group,.
		// so we know which schema each count belongs to without inspecting serialized objects).
		if (empty($saveResult['countsBySchema']) === false && $this->cachedConfig !== null) {
			$sectionMap = [
				'model' => 'elements',
				'element' => 'elements',
				'relationship' => 'relationships',
				'organization' => 'organizations',
				'view' => 'views',
				'property_definition' => 'property_definitions',
			];

			// Build reverse map: schema ID → plural section name.
			$schemaToSection = [];
			foreach ($this->cachedConfig['schemaIds'] as $type => $schemaId) {
				$schemaToSection[(int)$schemaId] = $sectionMap[$type] ?? 'elements';
			}

			foreach ($saveResult['countsBySchema'] as $schemaId => $counts) {
				$sectionKey = $schemaToSection[(int)$schemaId] ?? 'elements';
				$statistics[$sectionKey]['created'] += $counts['saved'] ?? 0;
				$statistics[$sectionKey]['updated'] += $counts['updated'] ?? 0;
				$statistics[$sectionKey]['unchanged'] += $counts['unchanged'] ?? 0;
				if (($counts['invalid'] ?? 0) > 0) {
					$statistics[$sectionKey]['errors'][] = "{$counts['invalid']} validation error(s)";
				}
			}
		}//end if

		// Calculate summary totals.
		foreach ($statistics as $section => $sectionStats) {
			if ($section === 'omschrijving') {
				continue;
			}

			$statistics['omschrijving']['total_objects_created'] += $sectionStats['created'];
			$statistics['omschrijving']['total_objects_updated'] += $sectionStats['updated'];
			$statistics['omschrijving']['total_objects_unchanged'] += $sectionStats['unchanged'];
			$statistics['omschrijving']['total_errors'] += count($sectionStats['errors']);
		}

		return $statistics;
	}//end buildStatisticsFromSaveResult()

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
	 * Check if an array is associative (has string keys).
	 *
	 * @param array $array The array to check.
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

		// Ensure items is always an array; a section with one item holds that item, not a list.
		if (is_array($items) === false || isset($items['_attributes']) === true) {
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
		if (isset($this->idPatternCache[$sectionName]) === true) {
			$patterns = $this->idPatternCache[$sectionName];

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
		$this->idPatternCache[$sectionName] = $patterns;

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
	 * OPTIMIZATION: Extract only essential XML data to reduce memory usage by 20-30%
	 *
	 * Instead of storing the complete XML structure, this method extracts only
	 * the essential data needed for round-trip fidelity and export functionality.
	 * For view objects, element splicing is performed if elements lookup is provided.
	 *
	 * @param array $item           The complete XML item data
	 * @param array $elementsLookup Optional elements lookup for view processing
	 *
	 * @return array Essential XML data for storage
	 */
	private function extractEssentialXmlData(array $item, array $elementsLookup = []): array {
		$essential = [];

		// Preserve the core attributes, name, documentation, properties and relationship ends (needed for export),
		// any other critical ArchiMate-specific fields, and the original node/connection arrays of views.
		$fields = [
			'_attributes',
			'name',
			'documentation',
			'properties',
			'source',
			'target',
			'type',
			'viewpoint',
			'accessType',
			'isDirected',
			'node',
			'connection',
		];
		foreach ($fields as $field) {
			if (isset($item[$field]) === true) {
				$essential[$field] = $item[$field];
			}
		}

		// Extract flattened viewNodes/viewRelationships for frontend consumption.
		$essential = array_merge(
			$essential,
			$this->extractViewNodesAndConnections(item: $item, elementsLookup: $elementsLookup)
		);

		// Add a marker to indicate this is essential data (for debugging).
		$essential['_essential_data'] = true;

		return $essential;
	}//end extractEssentialXmlData()

	/**
	 * Extract view nodes and relationships for view objects with proper JSON structure
	 *
	 * This method extracts and transforms nodes and connections from view XML data into
	 * the standardized viewNodes and viewRelationships format used by the frontend.
	 *
	 * @param array $item           The complete XML item data
	 * @param array $elementsLookup Lookup array of elements
	 *
	 * @return array The viewNodes and viewRelationships the item has
	 */
	private function extractViewNodesAndConnections(array $item, array $elementsLookup): array {
		$extracted = [];

		// Extract viewNodes array with proper JSON structure.
		if (isset($item['node']) === true) {
			$extracted['viewNodes'] = $this->extractViewNodesRecursively(
				nodeData: $item['node'],
				elementsLookup: $elementsLookup
			);
		}

		// Extract viewRelationships array with proper JSON structure.
		if (isset($item['connection']) === true) {
			$extracted['viewRelationships'] = $this->extractViewRelationshipsRecursively(
				connectionData: $item['connection']
			);
		}

		return $extracted;
	}//end extractViewNodesAndConnections()


	/**
	 * Extract view nodes with proper JSON structure for frontend consumption
	 *
	 * This method transforms ArchiMate XML node data into the standardized viewNodes format
	 * expected by the frontend visualization components.
	 *
	 * **Parent-Child Relationship Handling:**
	 * - Root-level nodes have `parent: null`
	 * - Nested nodes have `parent: parentNodeId` set by the recursive processor
	 * - All nodes are flattened into a single array for efficient storage/querying
	 * - Frontend can reconstruct the hierarchy using parent references
	 *
	 * **Example Structure:**
	 * ```
	 * [
	 *   { viewNodeId: 'node1', parent: null },           // Root node
	 *   { viewNodeId: 'node2', parent: 'node1' },        // Child of node1
	 *   { viewNodeId: 'node3', parent: 'node1' },        // Child of node1
	 *   { viewNodeId: 'node4', parent: 'node2' }         // Grandchild (child of node2)
	 * ]
	 * ```
	 *
	 * @param array $nodeData Node data (can be single node or array of nodes)
	 * @param array $elementsLookup Lookup array of elements by identifier for enrichment
	 *
	 * @return array Array of viewNodes with standardized structure including parent references
	 */
	private function extractViewNodesRecursively($nodeData, array $elementsLookup = []): array {
		$viewNodes = [];

		// Handle both single node and array of nodes.
		if (isset($nodeData[0]) === false) {
			// Single node.
			$nodeData = [$nodeData];
		}

		foreach ($nodeData as $node) {
			if (isset($node['_attributes']) === false) {
				continue;
			}

			$nodeId = $node['_attributes']['identifier'] ?? null;
			$elementRef = $node['_attributes']['elementRef'] ?? null;

			if ($nodeId === null) {
				continue;
			}

			// Create viewNode with standardized structure.
			$xValue = (int)($node['_attributes']['x'] ?? 0);
			$yValue = (int)($node['_attributes']['y'] ?? 0);
			$widthValue = (int)($node['_attributes']['w'] ?? 100);
			$heightValue = (int)($node['_attributes']['h'] ?? 50);

			$viewNode = [
				'modelNodeId' => $elementRef,
				// Reference to the ArchiMate element.
				'viewNodeId' => $nodeId,
				// Unique identifier within this view.
				'x' => $xValue,
				'y' => $yValue,
				'width' => $widthValue,
				'height' => $heightValue,
				'parent' => null,
				// Will be set to parent nodeId for nested nodes (see recursive processing below).
				'name' => null,
				'type' => $this->extractNodeType(node: $node),
				// Extract type directly from node XML.
				'color' => 'rgb(255, 255, 255)',
				// Default white background.
				'borderColor' => 'rgb(0, 0, 0)',
				// Default black border.
				'font' => [
					'name' => 'Segoe UI, Arial',
					'size' => 12,
					'style' => 'normal',
					'color' => 'rgb(0, 0, 0)',
				],
				'description' => null,
				'elementRef' => $elementRef,
			];

			// Extract style information if present.
			if (isset($node['style']) === true) {
				$this->applyNodeStyle(viewNode: $viewNode, style: $node['style']);
			}

			// Extract label text for Label type nodes.
			if (isset($node['label']) === true) {
				if (is_array($node['label']) === true && isset($node['label']['_value']) === true) {
					$viewNode['name'] = $node['label']['_value'];
				} elseif (is_string($node['label']) === true) {
					$viewNode['name'] = $node['label'];
				}
			}

			// Enrich with element data if available and elementRef exists.
			if ($elementRef !== false && isset($elementsLookup[$elementRef]) === true) {
				$element = $elementsLookup[$elementRef];

				// Use element name if node doesn't have its own label.
				if ($viewNode['name'] === null && isset($element['name']) === true) {
					$viewNode['name'] = $element['name'];
				}

				// Set description from element documentation.
				if (isset($element['omschrijving']) === true) {
					$viewNode['description'] = $element['omschrijving'];
				} elseif (isset($element['documentation']) === true) {
					$viewNode['description'] = $element['documentation'];
				}

				// Enhance type with element data if node type wasn't fully extracted.
				if ($viewNode['type'] === null || $viewNode['type'] === 'element') {
					if (isset($element['gemmaType']) === true) {
						$gemmaType = $element['gemmaType'];
						// Handle case where gemmaType might be an array.
						if (is_array($gemmaType) === true) {
							$gemmaType = $gemmaType['_value'] ?? $gemmaType[0] ?? 'unknown';
						}

						$viewNode['type'] = strtolower((string)$gemmaType);
					} elseif (isset($element['xml']['_attributes']['xsi:type']) === true) {
						$archiType = $element['xml']['_attributes']['xsi:type'];
						// Convert ArchiMate type to simplified type
						// (e.g., "archimate:BusinessService" -> "businessservice").
						$viewNode['type'] = strtolower(preg_replace('/^archimate:|^[a-z]+:/', '', $archiType));
					}
				}

				// Add all element properties to view node for full data access.
				$viewNode['elementProperties'] = $this->extractElementProperties(element: $element);

				// Add specific useful properties directly to the node.
				if (isset($element['objectId']) === true) {
					$viewNode['objectId'] = $element['objectId'];
				}

				// Add GEMMA-specific properties if they exist.
				$gemmaProperties = [
					'gemmaType',
					'bivScoreBbn',
					'belangrijksteReden',
					'availability',
					'integrity',
					'confidentiality',
				];
				foreach ($gemmaProperties as $prop) {
					if (isset($element[$prop]) === true) {
						$viewNode[$prop] = $element[$prop];
					}
				}

				// Store element section for reference.
				if (isset($element['section']) === true) {
					$viewNode['elementSection'] = $element['section'];
				}

				// Store model identifier for linking.
				if (isset($element['model_identifier']) === true) {
					$viewNode['modelIdentifier'] = $element['model_identifier'];
				}
			}//end if

			// Add parent node BEFORE its children so the frontend rendering.
			// engine can look up parents via graph.getCell(parentId).
			$viewNodes[] = $viewNode;

			// Handle child nodes recursively (flatten hierarchy into single array
			// while preserving parent-child relationships).
			if (isset($node['node']) === true) {
				$childNodes = $this->extractViewNodesRecursively(nodeData: $node['node'], elementsLookup: $elementsLookup);

				// Set parent reference only for DIRECT children (those with parent === null).
				// Grandchildren already have their parent set by the recursive call.
				foreach ($childNodes as &$childNode) {
					if ($childNode['parent'] === null) {
						$childNode['parent'] = $nodeId;
					}
				}

				unset($childNode);

				// Add child nodes to the main flattened array (maintaining parent references).
				$viewNodes = array_merge($viewNodes, $childNodes);
			}
		}//end foreach

		return $viewNodes;
	}//end extractViewNodesRecursively()

	/**
	 * Extract all properties from an element for view node enrichment
	 *
	 * @param array $element Element data containing properties
	 *
	 * @return array Clean array of element properties
	 */
	private function extractElementProperties(array $element): array {
		$properties = [];

		// Extract basic element properties.
		$basicProperties = ['identifier', 'name', 'omschrijving', 'section', 'model_identifier'];
		foreach ($basicProperties as $prop) {
			if (isset($element[$prop]) === true) {
				$properties[$prop] = $element[$prop];
			}
		}

		// Extract all flattened properties (camelCase converted properties).
		$excludedKeys = ['@self', 'xml', '_propertyMapping', '_slug', '_essential_data'];
		foreach ($element as $key => $value) {
			if (in_array($key, $excludedKeys) === false && in_array($key, $basicProperties) === false) {
				// Only include non-object values or simple arrays.
				$isSimple = is_scalar($value) === true
					|| (is_array($value) === true
					&& $this->isComplexArray(array: $value) === false);
				if ($isSimple === true) {
					$properties[$key] = $value;
				}
			}
		}

		// Add property mapping for reference if available.
		if (isset($element['_propertyMapping']) === true) {
			$properties['_propertyMapping'] = $element['_propertyMapping'];
		}

		return $properties;
	}//end extractElementProperties()

	/**
	 * Check if an array contains complex nested structures
	 *
	 * @param array $array Array to check
	 *
	 * @return bool True if array contains complex nested structures
	 */
	private function isComplexArray(array $array): bool {
		foreach ($array as $value) {
			if (is_array($value) === true || is_object($value) === true) {
				return true;
			}
		}

		return false;
	}//end isComplexArray()

	/**
	 * Apply style information to a viewNode structure
	 *
	 * @param array $viewNode ViewNode structure to apply styles to
	 * @param array $style Style data from XML
	 *
	 * @return void
	 */
	private function applyNodeStyle(array &$viewNode, array $style): void {
		// Extract fillColor.
		if (isset($style['fillColor']['_attributes']) === true) {
			$fillColor = $style['fillColor']['_attributes'];
			$r = (int)($fillColor['r'] ?? 255);
			$g = (int)($fillColor['g'] ?? 255);
			$b = (int)($fillColor['b'] ?? 255);

			$viewNode['color'] = "rgb($r, $g, $b)";
		}//end if

		// Extract lineColor (including alpha for border visibility).
		if (isset($style['lineColor']['_attributes']) === true) {
			$lineColor = $style['lineColor']['_attributes'];
			$r = (int)($lineColor['r'] ?? 0);
			$g = (int)($lineColor['g'] ?? 0);
			$b = (int)($lineColor['b'] ?? 0);
			$a = (int)($lineColor['a'] ?? 100);

			if ($a < 100) {
				$viewNode['borderColor'] = "rgba($r, $g, $b, " . round($a / 100, 2) . ')';
			} else {
				$viewNode['borderColor'] = "rgb($r, $g, $b)";
			}
		}//end if

		// Extract font information.
		if (isset($style['font']) === true) {
			$font = [];
			if (isset($style['font']['_attributes']) === true) {
				$font['name'] = $style['font']['_attributes']['name'] ?? 'Segoe UI, Arial';
				if (isset($style['font']['_attributes']['size']) === true) {
					$font['size'] = (int)$style['font']['_attributes']['size'];
				} else {
					$font['size'] = 12;
				}

				$font['style'] = 'normal';
			}

			if (isset($style['font']['color']['_attributes']) === true) {
				$fontColor = $style['font']['color']['_attributes'];
				$r = (int)($fontColor['r'] ?? 0);
				$g = (int)($fontColor['g'] ?? 0);
				$b = (int)($fontColor['b'] ?? 0);
				$font['color'] = "rgb($r, $g, $b)";
			}//end if

			if (empty($font) === false) {
				$viewNode['font'] = array_merge($viewNode['font'], $font);
			}
		}//end if
	}//end applyNodeStyle()

	/**
	 * Extract view relationships with proper JSON structure for frontend consumption
	 *
	 * This method transforms ArchiMate XML connection data into the standardized viewRelationships
	 * format expected by the frontend visualization components.
	 *
	 * @param array $connectionData Connection data (can be single connection or array)
	 *
	 * @return array Array of viewRelationships with standardized structure
	 */
	private function extractViewRelationshipsRecursively($connectionData): array {
		$viewRelationships = [];

		// Handle both single connection and array of connections.
		if (isset($connectionData[0]) === false) {
			// Single connection.
			$connectionData = [$connectionData];
		}

		foreach ($connectionData as $connection) {
			if (isset($connection['_attributes']) === false) {
				continue;
			}

			$connectionId = $connection['_attributes']['identifier'] ?? null;
			$relationshipRef = $connection['_attributes']['relationshipRef'] ?? null;
			$source = $connection['_attributes']['source'] ?? null;
			$target = $connection['_attributes']['target'] ?? null;

			if ($connectionId === null || $source === null || $target === false) {
				continue;
			}

			// Create viewRelationship with standardized structure.
			$viewRelationship = [
				'modelRelationshipId' => $relationshipRef,
				// Reference to the ArchiMate relationship.
				'sourceId' => $source,
				// Source node viewNodeId.
				'targetId' => $target,
				// Target node viewNodeId.
				'viewRelationshipId' => $connectionId,
				// Unique identifier within this view.
				'type' => $this->extractConnectionType(connection: $connection),
				// Extract type directly from connection XML.
				'bendpoints' => [],
				// Array of bend points.
				'label' => [],
				// Label information.
			];

			// Extract bend points if present.
			if (isset($connection['bendpoint']) === true) {
				// Normalise to indexed array: single bendpoint is a plain assoc array.
				if (isset($connection['bendpoint'][0]) === true) {
					$bendpoints = $connection['bendpoint'];
				} else {
					$bendpoints = [$connection['bendpoint']];
				}

				foreach ($bendpoints as $bendpoint) {
					if (isset($bendpoint['_attributes']) === true) {
						$xValue = (int)($bendpoint['_attributes']['x'] ?? 0);
						$yValue = (int)($bendpoint['_attributes']['y'] ?? 0);

						$viewRelationship['bendpoints'][] = [
							'x' => $xValue,
							'y' => $yValue,
						];
					}
				}
			}//end if

			// Extract label information if present.
			if (isset($connection['label']) === true) {
				$label = [];

				if (is_array($connection['label']) === true && isset($connection['label']['_value']) === true) {
					$label['text'] = $connection['label']['_value'];
				} elseif (is_string($connection['label']) === true) {
					$label['text'] = $connection['label'];
				}

				// Extract label markup/style if present.
				if (isset($connection['style']) === true) {
					$label['markup'] = [$this->extractLabelMarkup(style: $connection['style'])];
				}

				$viewRelationship['label'] = $label;
			}

			$viewRelationships[] = $viewRelationship;
		}//end foreach

		return $viewRelationships;
	}//end extractViewRelationshipsRecursively()

	/**
	 * Extract label markup information from connection style
	 *
	 * @param array $style Style data from XML
	 *
	 * @return array Label markup structure
	 */
	private function extractLabelMarkup(array $style): array {
		$markup = [
			'style' => [
				'fontSize' => 11,
				'fontFamily' => 'Segoe UI, Arial',
				'fontColor' => 'rgba(0, 0, 0, 1)',
				'fontStyle' => 'normal',
				'fontWeight' => 'normal',
			],
		];

		// Extract font information if present.
		if (isset($style['font']) === true) {
			if (isset($style['font']['_attributes']) === true) {
				$markup['style']['fontFamily'] = $style['font']['_attributes']['name'] ?? 'Segoe UI, Arial';
				if (isset($style['font']['_attributes']['size']) === true) {
					$markup['style']['fontSize'] = (int)$style['font']['_attributes']['size'];
				} else {
					$markup['style']['fontSize'] = 11;
				}
			}

			if (isset($style['font']['color']['_attributes']) === true) {
				$fontColor = $style['font']['color']['_attributes'];
				$r = (int)($fontColor['r'] ?? 0);
				$g = (int)($fontColor['g'] ?? 0);
				$b = (int)($fontColor['b'] ?? 0);
				$a = (int)($fontColor['a'] ?? 1);

				// Convert percentage to decimal.
				$markup['style']['fontColor'] = "rgba($r, $g, $b, $a)";
			}//end if
		}//end if

		return $markup;
	}//end extractLabelMarkup()

	/**
	 * Extract node type directly from node XML attributes
	 *
	 * @param array $node Node data from XML
	 *
	 * @return string|null Node type extracted from XML or null if not found
	 */
	private function extractNodeType(array $node): ?string {
		// Priority 1: Check xsi:type attribute (most specific).
		if (isset($node['_attributes']['xsi:type']) === true) {
			$xsiType = $node['_attributes']['xsi:type'];

			// Handle different xsi:type formats.
			if ($xsiType === 'Label') {
				return 'label';
			}

			if ($xsiType === 'Element') {
				return 'element';
			}

			if (str_contains($xsiType, ':') === true) {
				// Handle namespaced types like "archimate:BusinessService".
				return strtolower(substr($xsiType, (int)strpos($xsiType, ':') + 1));
			}

			return strtolower($xsiType);
		}

		// Priority 2: Check if this is a Label node (has label content).
		if (isset($node['label']) === true) {
			return 'label';
		}

		// Priority 3: Check if this has an elementRef (it's an Element node).
		if (isset($node['_attributes']['elementRef']) === true) {
			return 'element';
		}

		// Fallback: Return null to allow element lookup to fill in the type.
		return null;
	}//end extractNodeType()

	/**
	 * Extract connection type directly from connection XML attributes
	 *
	 * @param array $connection Connection data from XML
	 *
	 * @return string Connection type extracted from XML or default 'association'
	 */
	private function extractConnectionType(array $connection): string {
		// Priority 1: Check xsi:type attribute (most specific).
		if (isset($connection['_attributes']['xsi:type']) === true) {
			$xsiType = $connection['_attributes']['xsi:type'];

			// Handle different connection type formats.
			if (str_contains($xsiType, 'Relationship') === true) {
				// Remove "Relationship" suffix and namespace prefix.
				// e.g., "archimate:ServingRelationship" -> "serving".
				$type = preg_replace('/^[a-z]+:/', '', $xsiType);
				// Remove namespace.
				$type = preg_replace('/relationship$/i', '', $type);
				// Remove "Relationship" suffix.
				return strtolower((string)$type);
			}

			if (str_contains($xsiType, ':') === true) {
				// Handle other namespaced types.
				return strtolower(substr($xsiType, (int)strpos($xsiType, ':') + 1));
			}

			return strtolower($xsiType);
		}//end if

		// Priority 2: Check if this has a relationshipRef (use that to determine type if possible).
		if (isset($connection['_attributes']['relationshipRef']) === true) {
			// We have a relationship reference, but we can't determine the type from just the ID.
			// Return generic 'relationship' type.
			return 'relationship';
		}

		// Fallback: Default association type.
		return 'association';
	}//end extractConnectionType()

	/**
	 * SPEED OPTIMIZED: Transform ArchiMate XML data with maximum performance focus
	 *
	 * This implementation prioritizes speed over memory usage:
	 * 1. Pre-build ALL lookups in memory (elements, relationships, organizations)
	 * 2. Cache all parsing results and property mappings
	 * 3. Use bulk operations with memory buffers
	 * 4. Eliminate redundant operations through aggressive caching
	 * 5. Process everything in memory-intensive but fast data structures
	 *
	 * @param array $xmlData Parsed XML data
	 * @param string $modelIdentifier Model identifier
	 *
	 * @return array Array of objects ready for saveObjects()
	 */
	private function transformArchiMateXmlToObjectsBatch(array $xmlData, string $modelIdentifier): array {
		$startTime = microtime(true);
		$allObjects = [];

		// SPEED OPTIMIZATION 1: Pre-extract and cache EVERYTHING.
		$cacheStartTime = microtime(true);
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

		// SPEED OPTIMIZATION 2: Pre-build ALL section lookups simultaneously.
		$allLookups = $this->buildAllLookupsSimultaneously(xmlData: $xmlData);
		$elementsLookup = $this->buildElementsLookup(elementObjects: $allObjects);
		// Will be rebuilt from processed objects.
		$cacheTime = microtime(true) - $cacheStartTime;
		$this->logger->info(
			'Pre-built all lookups',
			[
				'cache_build_time' => round($cacheTime, 3),
				'elements_count' => count($allLookups['elements']),
				'relationships_count' => count($allLookups['relationships']),
				'organizations_count' => count($allLookups['organizations']),
				'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 1),
			]
		);

		$scope = (new ArchiMateImportScope(modelIdentifier: $modelIdentifier, propDefMap: $propDefMap))->withLookups(lookups: $allLookups);

		// SPEED OPTIMIZATION 3: Process all non-view sections in bulk.
		$bulkProcessingStart = microtime(true);
		$nonViewObjects = $this->bulkProcessNonViewSections(xmlData: $xmlData, scope: $scope);
		$allObjects = array_merge($allObjects, $nonViewObjects);

		// SPEED OPTIMIZATION: Build elements lookup directly from raw data (faster than from processed objects).
		$elementsLookup = $this->buildElementsLookupFromRawData(scope: $scope);

		$bulkTime = microtime(true) - $bulkProcessingStart;

		// SPEED OPTIMIZATION 4: Process views with maximum speed optimizations.
		$viewProcessingStart = microtime(true);
		$viewObjects = $this->processViewsMaximumSpeed(
			xmlData: $xmlData,
			scope: $scope->withElementsLookup(elementsLookup: $elementsLookup)
		);
		$allObjects = array_merge($allObjects, $viewObjects);
		$viewTime = microtime(true) - $viewProcessingStart;

		$totalTime = microtime(true) - $startTime;
		// Transformation completed.
		// MEMORY CLEANUP: Free all intermediate lookups and caches before database operations.
		$memoryBeforeCleanup = memory_get_usage(true);
		unset($allLookups, $elementsLookup, $propDefMap, $scope);
		$this->camelCaseNames = null;
		// Clear property name cache.
		$this->idPatternCache = [];
		// Clear identifier pattern cache.
		$this->propMapCache = null;
		// Clear property definition cache.
		// Force garbage collection to free memory immediately.
		if (function_exists('gc_collect_cycles') === true) {
			$cycles = gc_collect_cycles();
			$memoryAfterCleanup = memory_get_usage(true);
			$memoryFreed = $memoryBeforeCleanup - $memoryAfterCleanup;

			$this->logger->info(
				'Memory cleanup before database operations',
				[
					'memory_freed_mb' => round($memoryFreed / 1024 / 1024, 1),
					'gc_cycles_collected' => $cycles,
					'memory_before_mb' => round($memoryBeforeCleanup / 1024 / 1024, 1),
					'memory_after_mb' => round($memoryAfterCleanup / 1024 / 1024, 1),
				]
			);
		}

		return $allObjects;
	}//end transformArchiMateXmlToObjectsBatch()

	/**
	 * Extract all element references from view items for optimization
	 *
	 * @param array $viewItems Array of view items
	 *
	 * @return array Array of referenced element identifiers
	 */
	private function extractReferencedElements(array $viewItems): array {
		$references = [];

		foreach ($viewItems as $item) {
			$this->collectElementReferencesRecursively(data: $item, references: $references);
		}

		return array_unique($references);
	}//end extractReferencedElements()

	/**
	 * Recursively collect element references from view data
	 *
	 * @param array $data View data to process
	 * @param array $references Array to collect references into
	 *
	 * @return void
	 */
	private function collectElementReferencesRecursively(array $data, array &$references): void {
		// Check for elementRef in current level.
		if (isset($data['_attributes']['elementRef']) === true) {
			$references[] = $data['_attributes']['elementRef'];
		}

		// Recursively check child nodes.
		if (isset($data['node']) === true) {
			$nodeData = $data['node'];
			if (isset($nodeData[0]) === false) {
				$nodeData = [$nodeData];
			}

			foreach ($nodeData as $node) {
				$this->collectElementReferencesRecursively(data: $node, references: $references);
			}
		}
	}//end collectElementReferencesRecursively()

	/**
	 * Build elements lookup array for view processing with element splicing
	 *
	 * This method creates a fast lookup array of elements by their identifier
	 * to enable efficient element splicing during view node processing.
	 *
	 * @param array $elementObjects Array of processed element objects
	 *
	 * @return array Lookup array with element identifier as key and element data as value
	 */
	private function buildElementsLookup(array $elementObjects): array {
		$lookup = [];

		foreach ($elementObjects as $element) {
			$identifier = $element['identifier'] ?? null;
			if (empty($identifier) === false) {
				$lookup[$identifier] = $element;
			}
		}

		$this->logger->debug(
			'Built elements lookup for view processing',
			[
				'total_elements' => count($lookup),
				'sample_identifiers' => array_slice(array_keys($lookup), 0, 5),
			]
		);

		return $lookup;
	}//end buildElementsLookup()

	/**
	 * SPEED OPTIMIZATION: Build elements lookup directly from raw XML data
	 *
	 * This is faster than building from processed objects because we skip intermediate processing
	 * and build the lookup table directly from the source data with minimal transformations.
	 *
	 * @param ArchiMateImportScope $scope The import scope with the raw section lookups
	 *
	 * @return array Elements lookup for view processing
	 */
	private function buildElementsLookupFromRawData(ArchiMateImportScope $scope): array {
		$lookup = [];

		// SPEED: Build directly from raw data with minimal processing.
		foreach ($scope->lookup(sectionName: 'elements') as $identifier => $rawItem) {
			$element = [
				'identifier' => $identifier,
				'section' => 'element',
			];
			$this->copyNameAndSummary(object: $element, item: $rawItem);
			$this->copyXsiType(object: $element, item: $rawItem);

			// Fast properties flattening (only essential properties for splicing).
			if (isset($rawItem['properties']['property']) === true && empty($scope->propDefMap()) === false) {
				$this->spliceProperties(
					element: $element,
					scope: $scope->withProperties(properties: $rawItem['properties']['property'])
				);
			}

			$lookup[$identifier] = $element;
		}//end foreach

		$this->logger->debug(
			'Built SPEED elements lookup from raw data',
			[
				'total_elements' => count($lookup),
				'sample_identifiers' => array_slice(array_keys($lookup), 0, 5),
			]
		);

		return $lookup;
	}//end buildElementsLookupFromRawData()

	/**
	 * Copy the defined, non-null property values of a raw element onto its lookup entry.
	 *
	 * @param array                $element The lookup entry
	 * @param ArchiMateImportScope $scope   The scope with the element's raw properties
	 *
	 * @return void
	 */
	private function spliceProperties(array &$element, ArchiMateImportScope $scope): void {
		$propDefMap = $scope->propDefMap();
		foreach ($this->propertyList(properties: $scope->properties()) as $prop) {
			if (isset($prop['_attributes']['propertyDefinitionRef']) === false) {
				continue;
			}

			$defRef = $prop['_attributes']['propertyDefinitionRef'];
			$value = $prop['value']['_value'] ?? $prop['value'] ?? null;
			if ($value !== null && isset($propDefMap[$defRef]) === true) {
				$element[$this->convertToCamelCase(propertyName: $propDefMap[$defRef])] = $value;
			}
		}
	}//end spliceProperties()

	/**
	 * A raw property list as a list: a single property becomes a list of one.
	 *
	 * @param array $properties The raw `properties.property` value
	 *
	 * @return array The properties as a list
	 */
	private function propertyList(array $properties): array {
		if (isset($properties[0]) === true) {
			return $properties;
		}

		return [$properties];
	}//end propertyList()

	/**
	 * The text of an XML leaf: its `_value`, or the value itself when it is a string.
	 *
	 * @param mixed $value The XML leaf
	 *
	 * @return mixed The text, or null when the leaf has none
	 */
	private function textValue(mixed $value): mixed {
		if (is_array($value) === true && isset($value['_value']) === true) {
			return $value['_value'];
		}

		if (is_string($value) === true) {
			return $value;
		}

		return null;
	}//end textValue()

	/**
	 * Copy an item's name and documentation onto the object as name and omschrijving.
	 *
	 * A name or documentation without text is copied as an empty string.
	 *
	 * @param array $object The object being built
	 * @param array $item   The raw XML item
	 *
	 * @return void
	 */
	private function copyNameAndSummary(array &$object, array $item): void {
		foreach (['name' => 'name', 'documentation' => 'omschrijving'] as $source => $target) {
			if (isset($item[$source]) === true) {
				$object[$target] = $this->textValue(value: $item[$source]) ?? '';
			}
		}
	}//end copyNameAndSummary()

	/**
	 * Copy an item's xsi:type (e.g. "Capability", "ApplicationComponent") onto the object as type.
	 *
	 * @param array $object The object being built
	 * @param array $item   The raw XML item
	 *
	 * @return void
	 */
	private function copyXsiType(array &$object, array $item): void {
		if (isset($item['_xsi__type']) === true) {
			$object['type'] = $item['_xsi__type'];
		} elseif (isset($item['_attributes']['xsi:type']) === true) {
			$object['type'] = $item['_attributes']['xsi:type'];
		}
	}//end copyXsiType()

	/**
	 * Create model object directly with cached configuration
	 *
	 * @param array $metadata Model metadata
	 * @param string $modelIdentifier Model identifier
	 *
	 * @return array Model object with @self structure
	 */
	private function createModelObjectDirect(array $metadata, string $modelIdentifier): array {
		$organisation = $this->getCurrentOrganisation();

		// Extract a plain string name (schema column expects string, not array).
		$nameString = null;
		if (isset($metadata['name']) === true) {
			if (is_array($metadata['name']) === true && isset($metadata['name']['_value']) === true) {
				$nameString = (string)$metadata['name']['_value'];
			} elseif (is_string($metadata['name']) === true) {
				$nameString = $metadata['name'];
			}
		}

		// Build xml field preserving full array structure for round-trip fidelity.
		$xmlData = [];
		if (isset($metadata['name']) === true) {
			$xmlData['name'] = $metadata['name'];
		}

		if (isset($metadata['documentation']) === true) {
			$xmlData['documentation'] = $metadata['documentation'];
		}

		if (isset($metadata['properties']) === true) {
			$xmlData['properties'] = $metadata['properties'];
		}

		if (isset($metadata['propertyDefinitionMap']) === true) {
			$xmlData['propertyDefinitionMap'] = $metadata['propertyDefinitionMap'];
		}

		$regId = $this->cachedConfig['registerId'] ?? throw new \RuntimeException('No register ID.');
		$schemaId = $this->cachedConfig['schemaIds']['model'] ?? throw new \RuntimeException('No model schema ID.');
		$object = [
			'@self' => [
				'register' => $regId,
				'schema' => $schemaId,
				'id' => $modelIdentifier,
				'owner' => $this->cachedConfig['userId'],
				'organisation' => $organisation,
				'published' => date('Y-m-d\TH:i:s\Z'),
			],
			'identifier' => $modelIdentifier,
			'section' => 'model',
			'model_identifier' => $modelIdentifier,
			'xml' => $xmlData,
		] + $metadata;

		// Override name with string version so schema column stores it properly.
		if ($nameString !== null) {
			$object['name'] = $nameString;
		}

		return $object;
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

		// Try common patterns: Singular, plural, item, propertyDefinition.
		$patterns = [
			$sectionType,
			$sectionType . 's',
			'item',
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
	 * @param array                $object Object to add properties to
	 * @param ArchiMateImportScope $scope  The scope with the item's raw properties and the definition map
	 *
	 * @return void
	 */
	private function flattenPropertiesBatch(array &$object, ArchiMateImportScope $scope): void {
		$props = $this->propertyList(properties: $scope->properties());
		$propDefMap = $scope->propDefMap();
		$processedProperties = [];

		// Debug: Log property flattening process.
		$this->logger->debug(
			'Flattening properties for object',
			[
				'object_id' => $object['identifier'] ?? 'unknown',
				'properties_count' => count($props),
				'property_definition_map_size' => count($propDefMap),
				'sample_property_definitions' => array_slice($propDefMap, 0, 5, true),
			]
		);

		foreach ($props as $propIndex => $prop) {
			if (isset($prop['_attributes']['propertyDefinitionRef']) === false) {
				$this->logger->warning(
					'Property missing propertyDefinitionRef',
					[
						'object_id' => $object['identifier'] ?? 'unknown',
						'property_index' => $propIndex,
						'property_structure' => array_keys($prop ?? []),
					]
				);
				continue;
			}

			$defRef = $prop['_attributes']['propertyDefinitionRef'];

			// Debug: Log property reference lookup.
			if (isset($propDefMap[$defRef]) === false) {
				$this->logger->warning(
					'Property definition not found in map',
					[
						'object_id' => $object['identifier'] ?? 'unknown',
						'property_def_ref' => $defRef,
						'available_refs' => array_keys($propDefMap),
					]
				);
				continue;
			}

			$entry = [
				'name' => $propDefMap[$defRef],
				'def_ref' => $defRef,
				'value' => $prop['value']['_value'] ?? $prop['value'] ?? null,
			];
			$processed = $this->applyFlattenedProperty(object: $object, entry: $entry);
			if ($processed !== null) {
				$processedProperties[] = $processed;
			}
		}//end foreach

		// Debug: Log final property flattening results.
		$this->logger->debug(
			'Property flattening completed',
			[
				'object_id' => $object['identifier'] ?? 'unknown',
				'processed_count' => count($processedProperties),
				'processed_properties' => $processedProperties,
				'object_keys_after_flattening' => array_keys($object),
			]
		);
	}//end flattenPropertiesBatch()

	/**
	 * Set one defined property on the object under its camelCase name, or log a null value.
	 *
	 * @param array                                         $object The object being built
	 * @param array{name: string, def_ref: string, value: mixed} $entry  The property name, its definition ref and its value
	 *
	 * @return array|null The processed property, or null when its value is null
	 */
	private function applyFlattenedProperty(array &$object, array $entry): ?array {
		$defRef = $entry['def_ref'];
		$value = $entry['value'];
		if ($value === null) {
			$this->logger->warning(
				'Property value is null',
				[
					'object_id' => $object['identifier'] ?? 'unknown',
					'property_def_ref' => $defRef,
					'value' => $value,
				]
			);
			return null;
		}

		$propertyName = $entry['name'];
		$camelCaseName = $this->convertToCamelCase(propertyName: $propertyName);
		$object[$camelCaseName] = $value;

		// Store property mapping for reference.
		$object['_propertyMapping'][$camelCaseName] = $propertyName;

		// Debug: Log GEMMA type properties specifically.
		if (stripos($propertyName, 'gemma') !== false || $defRef === 'propid-3') {
			$this->logger->info(
				'GEMMA type property processed',
				[
					'object_id' => $object['identifier'] ?? 'unknown',
					'property_name' => $propertyName,
					'camel_case_name' => $camelCaseName,
					'value' => $value,
					'def_ref' => $defRef,
				]
			);
		}

		return [
			'original' => $propertyName,
			'camelCase' => $camelCaseName,
			'value' => $value,
			'def_ref' => $defRef,
		];
	}//end applyFlattenedProperty()

	/**
	 * SPEED OPTIMIZATION: Build all lookups simultaneously for maximum performance
	 *
	 * Pre-builds all possible lookups in parallel to eliminate lookup building overhead
	 * during processing. Uses more memory but significantly faster processing.
	 *
	 * @param array $xmlData Complete XML data
	 *
	 * @return array Array with all lookups: ['elements' => [...], 'relationships' => [...], etc.]
	 */
	private function buildAllLookupsSimultaneously(array $xmlData): array {
		$lookups = [
			'elements' => [],
			'relationships' => [],
			'organizations' => [],
			'views' => [],
			'property_definitions' => [],
		];

		// Pre-extract all section data simultaneously.
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
				$items = $this->findItemsSimplified(sectionData: $sectionData, sectionType: $schemaType);

				foreach ($items as $item) {
					if (is_array($item) === false) {
						continue;
					}

					$identifier = $this->extractIdentifier(item: $item, sectionName: $schemaType);
					if (empty($identifier) === false) {
						// Store raw item data for fast processing later.
						$lookups[$sectionName][$identifier] = $item;
					}
				}
			}
		}

		return $lookups;
	}//end buildAllLookupsSimultaneously()

	/**
	 * SPEED OPTIMIZATION: Bulk process all non-view sections with vectorized operations
	 *
	 * @param array                $xmlData XML data
	 * @param ArchiMateImportScope $scope   The import scope with the pre-built lookups
	 *
	 * @return array Processed objects
	 */
	private function bulkProcessNonViewSections(array $xmlData, ArchiMateImportScope $scope): array {
		$objects = [];

		$sections = [
			'elements' => 'element',
			'relationships' => 'relationship',
			'organizations' => 'organization',
			'property_definitions' => 'property_definition',
		];

		foreach ($sections as $sectionName => $schemaType) {
			// Organizations are hierarchical folder trees — store as one tree object.
			if ($sectionName === 'organizations') {
				$orgData = $this->findSectionData(xmlData: $xmlData, sectionName: 'organizations');
				if (empty($orgData) === false) {
					$objects[] = $this->organisationTreeObject(
						orgData: $orgData,
						scope: $scope->withSection(section: $schemaType)
					);
				}

				continue;
			}

			$sectionItems = $scope->lookup(sectionName: $sectionName);
			if (empty($sectionItems) === true) {
				continue;
			}

			$this->logger->debug(
				"SPEED: Bulk processing {$sectionName}",
				[
					'item_count' => count($sectionItems),
				]
			);

			// SPEED OPTIMIZATION: Process all items in this section as a batch.
			$sectionObjects = $this->bulkTransformSection(
				sectionItems: $sectionItems,
				scope: $scope->withSection(section: $schemaType)
			);

			$objects = array_merge($objects, $sectionObjects);
		}//end foreach

		return $objects;
	}//end bulkProcessNonViewSections()

	/**
	 * The organizations section as one tree object, so a round-trip export can rebuild the hierarchy.
	 *
	 * @param array                $orgData The organizations section
	 * @param ArchiMateImportScope $scope   The import scope for the organization section
	 *
	 * @return array The tree object
	 */
	private function organisationTreeObject(array $orgData, ArchiMateImportScope $scope): array {
		$syntheticId = 'org-' . preg_replace('/^id-/', '', $scope->modelIdentifier());
		$head = [
			'register' => $this->cachedConfig['registerId'] ?? throw new \RuntimeException('No register ID.'),
			'schema' => $this->cachedConfig['schemaIds']['organization'] ?? throw new \RuntimeException('No org schema.'),
			'id' => $syntheticId,
		];
		$object = $this->importObject(head: $head, scope: $scope);
		$object['name'] = 'Organizations';
		$object['xml'] = $orgData;

		return $object;
	}//end organisationTreeObject()

	/**
	 * A new import object: its @self block and its identifier, section and model.
	 *
	 * @param array{register: int, schema: int, id: mixed} $head  The register, schema and id of the object
	 * @param ArchiMateImportScope                           $scope The import scope for the object's section
	 *
	 * @return array The object
	 */
	private function importObject(array $head, ArchiMateImportScope $scope): array {
		return [
			'@self' => $head + [
				'owner' => $this->cachedConfig['userId'],
				'organisation' => $this->getCurrentOrganisation(),
				'published' => date('Y-m-d\TH:i:s\Z'),
			],
			'identifier' => $head['id'],
			'section' => $scope->section(),
			'model_identifier' => $scope->modelIdentifier(),
		];
	}//end importObject()

	/**
	 * SPEED OPTIMIZATION: Bulk transform a section with vectorized operations
	 *
	 * @param array                $sectionItems Pre-loaded section items by identifier
	 * @param ArchiMateImportScope $scope        The import scope for this section
	 *
	 * @return array Transformed objects
	 */
	private function bulkTransformSection(array $sectionItems, ArchiMateImportScope $scope): array {
		$objects = [];
		$schemaType = $scope->section();

		foreach ($sectionItems as $identifier => $item) {
			// SPEED OPTIMIZATION: Direct object creation without intermediate steps.
			$essentialXmlData = $this->extractEssentialXmlData(item: $item);

			$head = [
				'register' => $this->cachedConfig['registerId'] ?? throw new \RuntimeException('No register ID.'),
				'schema' => $this->cachedConfig['schemaIds'][$schemaType] ?? throw new \RuntimeException('No schema.'),
				'id' => $identifier,
			];
			$object = $this->importObject(head: $head, scope: $scope);
			$object['xml'] = $essentialXmlData;

			$this->copyNameAndSummary(object: $object, item: $item);
			$this->copyXsiType(object: $object, item: $item);

			// For relationships, extract source and target from attributes.
			if ($schemaType === 'relationship') {
				$this->copyRelationshipEnds(object: $object, item: $item);
			}

			// Fast flatten properties.
			if (isset($item['properties']['property']) === true && empty($scope->propDefMap()) === false) {
				$this->flattenPropertiesBatch(
					object: $object,
					scope: $scope->withProperties(properties: $item['properties']['property'])
				);
			}

			// Fast ID/slug update: a flattened "Object ID" becomes the id, the identifier its slug.
			if (isset($object['objectId']) === true) {
				$object['@self']['id'] = $object['objectId'];
				$object['@self']['slug'] = $identifier;
			} else {
				$object['@self']['slug'] = $this->slugFromIdentifier(identifier: $identifier);
			}

			$objects[] = $object;
		}//end foreach

		return $objects;
	}//end bulkTransformSection()

	/**
	 * Copy a relationship's source and target attributes onto the object.
	 *
	 * @param array $object The relationship object being built
	 * @param array $item   The raw XML relationship
	 *
	 * @return void
	 */
	private function copyRelationshipEnds(array &$object, array $item): void {
		foreach (['source', 'target'] as $end) {
			if (isset($item['_' . $end]) === true) {
				$object[$end] = $item['_' . $end];
			} elseif (isset($item['_attributes'][$end]) === true) {
				$object[$end] = $item['_attributes'][$end];
			}
		}
	}//end copyRelationshipEnds()

	/**
	 * The slug of an AMEF identifier: the identifier without its "id-" prefix.
	 *
	 * @param string $identifier The AMEF identifier
	 *
	 * @return string The slug
	 */
	private function slugFromIdentifier(string $identifier): string {
		if (str_starts_with($identifier, 'id-') === true) {
			return substr($identifier, 3);
		}

		return $identifier;
	}//end slugFromIdentifier()

	/**
	 * SPEED OPTIMIZATION: Process views with maximum speed optimizations
	 *
	 * @param array                $xmlData XML data
	 * @param ArchiMateImportScope $scope   The import scope with the elements lookup for splicing
	 *
	 * @return array Processed view objects
	 */
	private function processViewsMaximumSpeed(array $xmlData, ArchiMateImportScope $scope): array {
		$viewsData = $this->findSectionData(xmlData: $xmlData, sectionName: 'views');
		if (empty($viewsData) === true) {
			return [];
		}

		$elementsLookup = $scope->elementsLookup();
		$this->logger->info(
			'SPEED MODE: Processing views with maximum optimizations',
			[
				'elements_available' => count($elementsLookup),
			]
		);

		// SPEED OPTIMIZATION: Pre-extract all referenced elements.
		$items = $this->findItemsSimplified(sectionData: $viewsData, sectionType: 'view');
		$referencedElements = $this->extractReferencedElements(viewItems: $items);

		// SPEED OPTIMIZATION: Build super-fast lookup with array_intersect_key.
		$filteredLookup = array_intersect_key($elementsLookup, array_flip($referencedElements));

		$this->logger->debug(
			'SPEED: Optimized element references',
			[
				'total_elements' => count($elementsLookup),
				'referenced_elements' => count($filteredLookup),
				'memory_savings_percent' => round(
					(1 - count($filteredLookup) / max(count($elementsLookup), 1)) * 100,
					1
				),
			]
		);

		// SPEED OPTIMIZATION: Process with bulk operations.
		return $this->bulkTransformViews(
			viewItems: $items,
			scope: $scope->withSection(section: 'view')->withElementsLookup(elementsLookup: $filteredLookup)
		);
	}//end processViewsMaximumSpeed()

	/**
	 * SPEED OPTIMIZATION: Bulk transform views with vectorized element splicing
	 *
	 * @param array                $viewItems View items to process
	 * @param ArchiMateImportScope $scope     The view scope with the filtered elements lookup
	 *
	 * @return array Processed view objects
	 */
	private function bulkTransformViews(array $viewItems, ArchiMateImportScope $scope): array {
		$objects = [];

		foreach ($viewItems as $item) {
			if (is_array($item) === false) {
				continue;
			}

			$identifier = $this->extractIdentifier(item: $item, sectionName: 'view');
			if ($identifier === null) {
				continue;
			}

			// SPEED OPTIMIZATION: Direct processing with minimal overhead.
			$essentialXmlData = $this->extractEssentialXmlData(
				item: $item,
				elementsLookup: $scope->elementsLookup()
			);

			$head = [
				'register' => $this->cachedConfig['registerId'] ?? throw new \RuntimeException('No register ID.'),
				'schema' => $this->getSchemaIdForSection(section: 'view'),
				'id' => $identifier,
			];
			$object = $this->importObject(head: $head, scope: $scope);
			$object['xml'] = $essentialXmlData;

			$this->copyNameAndSummary(object: $object, item: $item);
			$this->copyXsiType(object: $object, item: $item);

			// Fast properties flattening.
			if (isset($item['properties']['property']) === true && empty($scope->propDefMap()) === false) {
				$this->flattenPropertiesBatch(
					object: $object,
					scope: $scope->withProperties(properties: $item['properties']['property'])
				);
			}

			// Keep @self.id as the full ArchiMate identifier, so stored IDs match GEMMA Online URLs (id-e0f57689-...).
			$object['@self']['slug'] = $identifier;

			foreach (['viewNodes', 'viewRelationships'] as $key) {
				if (isset($object['xml'][$key]) === true) {
					$object[$key] = $object['xml'][$key];
				}
			}

			$objects[] = $object;
		}//end foreach

		return $objects;
	}//end bulkTransformViews()

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
			'elements' => ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'errors' => []],
			'organizations' => ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'errors' => []],
			'relationships' => ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'errors' => []],
			'views' => ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'errors' => []],
			'property_definitions' => ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'errors' => []],
		];

		// If we have access to the actual save results from ObjectService, use those.
		if ($this->lastSaveResult !== null) {
			$saveResult = $this->lastSaveResult;

			// DEBUG: Log what we got from ObjectService.
			$this->logger->info(
				'[ArchiMate] Using lastSaveResult for statistics',
				[
					'saved_count' => count($saveResult['saved'] ?? []),
					'updated_count' => count($saveResult['updated'] ?? []),
					'unchanged_count' => count($saveResult['unchanged'] ?? []),
					'invalid_count' => count($saveResult['invalid'] ?? []),
					'keys_present' => array_keys($saveResult),
				]
			);

			// Count objects by section type from the actual processed objects.
			$allProcessedObjects = array_merge(
				$saveResult['saved'] ?? [],
				$saveResult['updated'] ?? [],
				$saveResult['unchanged'] ?? [],
				// For invalid objects, extract the original object from the error structure.
				array_map(fn ($item) => $item['object'] ?? [], $saveResult['invalid'] ?? [])
			);

			foreach ($allProcessedObjects as $object) {
				// Convert ObjectEntity to array if needed.
				if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
					$object = $object->jsonSerialize();
				}

				$sectionType = $object['section'] ?? null;

				// Map section types (singular or plural) to statistics keys.
				$sectionKey = match ($sectionType) {
					'elements', 'element', 'model' => 'elements',
					'relationships', 'relationship' => 'relationships',
					'organizations', 'organization' => 'organizations',
					'views', 'view' => 'views',
					'property_definitions', 'property_definition' => 'property_definitions',
					default => null
				};

				// Fallback: use @self.schema to determine section.
				if ($sectionKey === null
					&& $this->cachedConfig !== null
					&& isset($this->cachedConfig['schemaIds']) === true
				) {
					$objSchemaId = $object['@self']['schema'] ?? null;
					if ($objSchemaId !== null) {
						$singularToPlural = [
							'element' => 'elements',
							'relationship' => 'relationships',
							'organization' => 'organizations',
							'view' => 'views',
							'property_definition' => 'property_definitions',
							'model' => 'elements',
						];
						foreach ($this->cachedConfig['schemaIds'] as $type => $schemaId) {
							if ((int)$schemaId === (int)$objSchemaId) {
								$sectionKey = $singularToPlural[$type] ?? 'elements';
								break;
							}
						}
					}

					$sectionKey = $sectionKey ?? 'elements';
				} elseif ($sectionKey === null) {
					$sectionKey = 'elements';
				}//end if

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

				// Check if this object was unchanged (no changes).
				$unchangedObjects = $saveResult['unchanged'] ?? [];
				$wasSkipped = empty(
					array_filter(
						$unchangedObjects,
						fn ($unchanged) => ($unchanged->getUuid() === $objectId)
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
					$statistics[$sectionKey]['unchanged']++;
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
					$statistics[$sectionKey]['unchanged']++;
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
			'total_objects_unchanged' => 0,
			'total_errors' => 0,
		];

		// No "skip the summary section" guard: `omschrijving` is written into
		// $statistics on the line AFTER this loop, so the loop can never see it.
		foreach ($statistics as $sectionStats) {
			$summary['total_objects_created'] += $sectionStats['created'];
			$summary['total_objects_updated'] += $sectionStats['updated'];
			$summary['total_objects_unchanged'] += $sectionStats['unchanged'];
			$summary['total_errors'] += count($sectionStats['errors']);
		}

		$statistics['omschrijving'] = $summary;

		// DEBUG: Log the summary statistics to help diagnose the issue.
		$this->logger->info(
			'[ArchiMate] Statistics summary calculated',
			[
				'omschrijving' => $summary,
				'section_counts' => array_map(
					fn ($s) => [
						'created' => $s['created'] ?? 0,
						'updated' => $s['updated'] ?? 0,
						'unchanged' => $s['unchanged'] ?? 0,
						'errors' => count($s['errors'] ?? []),
					],
					array_filter($statistics, fn ($k) => $k !== 'omschrijving', ARRAY_FILTER_USE_KEY)
				),
			]
		);

		return $statistics;
	}//end calculateObjectStatistics()

	/**
	 * Extract detailed error information from import statistics for frontend display
	 *
	 * @param array $statistics Import statistics containing section-wise error data
	 *
	 * @return array Formatted error information for frontend consumption
	 */
	private function extractDetailedErrors(array $statistics): array {
		$detailedErrors = [
			'total_count' => 0,
			'by_section' => [],
			'omschrijving' => [],
		];

		$sections = ['elements', 'relationships', 'organizations', 'views', 'property_definitions'];

		foreach ($sections as $section) {
			if (isset($statistics[$section]['errors']) === true && empty($statistics[$section]['errors']) === false) {
				$sectionErrors = $statistics[$section]['errors'];
				$sectionErrorCount = count($sectionErrors);

				$detailedErrors['total_count'] += $sectionErrorCount;

				// Group errors by type/message for better presentation.
				$errorGroups = [];
				foreach ($sectionErrors as $error) {
					$errorMessage = ($error['message'] ?? 'Unknown error');
					if (is_string($error) === true) {
						$errorMessage = $error;
					}

					$errorType = $this->categorizeError(errorMessage: $errorMessage);

					if (isset($errorGroups[$errorType]) === false) {
						$errorGroups[$errorType] = [
							'type' => $errorType,
							'message' => $errorMessage,
							'count' => 0,
							'examples' => [],
						];
					}

					$errorGroups[$errorType]['count']++;

					// Add example object ID if available (limit to 5 examples).
					if (count($errorGroups[$errorType]['examples']) < 5) {
						if (is_array($error) === true && isset($error['object_id']) === true) {
							$errorGroups[$errorType]['examples'][] = $error['object_id'];
						}
					}
				}//end foreach

				$detailedErrors['by_section'][$section] = [
					'section_name' => ucfirst(str_replace('_', ' ', $section)),
					'total_errors' => $sectionErrorCount,
					'error_groups' => array_values($errorGroups),
				];
			}//end if
		}//end foreach

		// Create summary of most common errors across all sections.
		$allErrors = [];
		foreach ($detailedErrors['by_section'] as $sectionData) {
			foreach ($sectionData['error_groups'] as $errorGroup) {
				$errorType = $errorGroup['type'];
				if (isset($allErrors[$errorType]) === false) {
					$allErrors[$errorType] = [
						'type' => $errorType,
						'message' => $errorGroup['message'],
						'total_count' => 0,
						'affected_sections' => [],
					];
				}

				$allErrors[$errorType]['total_count'] += $errorGroup['count'];
				$allErrors[$errorType]['affected_sections'][] = $sectionData['section_name'];
			}
		}

		// Sort by frequency and take top 10.
		uasort($allErrors, fn ($a, $b) => $b['total_count'] - $a['total_count']);
		$detailedErrors['omschrijving'] = array_slice(array_values($allErrors), 0, 10);

		return $detailedErrors;
	}//end extractDetailedErrors()

	/**
	 * Categorize error types for better grouping and presentation
	 *
	 * @param string $errorMessage The error message to categorize
	 *
	 * @return string Error category/type
	 */
	private function categorizeError(string $errorMessage): string {
		$errorMessage = strtolower($errorMessage);

		// Define error patterns and their categories.
		$errorPatterns = [
			'validation' => ['validation', 'invalid', 'required', 'missing', 'empty'],
			'schema' => ['schema', 'structure', 'format', 'type'],
			'reference' => ['reference', 'identifier', 'not found', 'missing reference'],
			'property' => ['property', 'attribute', 'field'],
			'constraint' => ['constraint', 'unique', 'duplicate', 'already exists'],
			'relationship' => ['relationship', 'source', 'target', 'connection'],
			'data_type' => ['string', 'integer', 'boolean', 'array', 'object'],
			'encoding' => ['encoding', 'character', 'utf', 'ascii'],
		];

		foreach ($errorPatterns as $category => $patterns) {
			foreach ($patterns as $pattern) {
				if (str_contains($errorMessage, $pattern) === true) {
					return $category;
				}
			}
		}

		return 'general';
	}//end categorizeError()
}//end class
