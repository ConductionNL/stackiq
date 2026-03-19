<?php

/**
 * ArchiMate Import Service for SoftwareCatalog
 *
 * Handles the business logic for importing ArchiMate XML files with round-trip fidelity.
 * This service contains all the import-specific logic that was previously in ArchiMateService.
 *
 * @category Service
 * @package  OCA\SoftwareCatalog\Service
 * @author   SoftwareCatalog Team <info@conduction.nl>
 * @license  AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.en.html
 * @link     https://github.com/nextcloud/softwarecatalog
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Service;

use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IUserSession;
use OCP\Files\IRootFolder;
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
 * @category Service
 * @package  OCA\SoftwareCatalog\Service
 * @author   SoftwareCatalog Team <info@conduction.nl>
 * @license  AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.en.html
 * @link     https://github.com/nextcloud/softwarecatalog
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyMethods)
 * @SuppressWarnings(PHPMD.ElseExpression)
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
 */
class ArchiMateImportService
{
    /**
     * Configuration keys for ArchiMate processing
     */
    private const CONFIG_KEYS = [
        'archimate_register_id'     => 'archimate_register_id',
        'archimate_schema_id'       => 'archimate_schema_id',
        'archimate_model_schema_id' => 'archimate_model_schema_id',
    ];

    /**
     * Performance optimization settings
     */
    private const PERFORMANCE_OPTIMIZATIONS = [
        'disable_validation'     => true,
        'disable_events'         => true,
        'disable_rbac'           => false,
    // Keep RBAC for security.
        'use_multi'              => true,
        'xml_parse_flags'        => LIBXML_NOCDATA | LIBXML_NONET,
        'memory_cleanup'         => true,
        'parallel_processing'    => true,
        'batch_size'             => 1000,
    // Default batch size (will be adjusted intelligently).
        'parallel_batches'       => 8,
    // Process 8 batches concurrently.
        'max_batch_size_bytes'   => 8388608,
    // 8 MB - safe under MySQL's 16 MB limit.
        'min_batch_size'         => 50,
    // Minimum batch size for very large objects.
        'size_estimation_sample' => 10,
    // Sample size for estimating object sizes.
    ];

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
    private array $idPatternCache = [];

    /**
     * Flag to track if we've already logged finding a GEMMA type property
     *
     * @var boolean
     */
    private bool $gemmaTypePropFound = false;

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
     * Cached configuration values for performance optimization.
     *
     * @var array|null
     */
    private ?array $cachedConfig = null;

    /**
     * Constructor for ArchiMateImportService
     *
     * @param IAppConfig          $config              Nextcloud app configuration service
     * @param IRootFolder         $rootFolder          Root folder service
     * @param IUserSession        $userSession         User session service
     * @param IAppManager         $appManager          App manager service
     * @param ContainerInterface  $container           PSR-11 container interface
     * @param LoggerInterface     $logger              Logger service
     * @param SettingsService     $settingsService     Settings service for AMEF configuration.
     * @param OrganisationService $organisationService Organisation service.
     */
    public function __construct(
        private readonly IAppConfig $config,
        private readonly IRootFolder $rootFolder,
        private readonly IUserSession $userSession,
        private readonly IAppManager $appManager,
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
        private readonly SettingsService $settingsService,
        private readonly OrganisationService $organisationService
    ) {
    }//end __construct()

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
     */
    public function xmlToArray(\SimpleXMLElement $xml): array
    {
        // PERFORMANCE OPTIMIZATION: Initialize result only.
        $result = [];

        // OPTIMIZATION: Extract non-namespaced attributes (skip redundant processing).
        $attributes = $xml->attributes();
        if (count($attributes) > 0) {
            $attrBag = [];
            foreach ($attributes as $attrName => $attrValue) {
                $name  = (string) $attrName;
                $value = (string) $attrValue;
                // OPTIMIZATION: Only create underscored key if needed (skip str_replace for simple names).
                if ((strpos($name, ':') !== false)) {
                    $underscoredKey = '_'.str_replace(':', '__', $name);
                } else {
                    $underscoredKey = '_'.$name;
                }

                $result[$underscoredKey] = $value;
                $attrBag[$name]          = $value;
            }

            $result['_attributes'] = $attrBag;
        }

        // OPTIMIZATION: Extract namespaced attributes (simplified processing).
        foreach ($xml->getNameSpaces(true) as $prefix => $nsUri) {
            $nsAttributes = $xml->attributes($prefix, true);
            if (count($nsAttributes) > 0) {
                foreach ($nsAttributes as $attrName => $attrValue) {
                    $name           = (string) $attrName;
                    $value          = (string) $attrValue;
                    $underscoredKey = '_'.$prefix.'__'.$name;
                    $result[$underscoredKey] = $value;
                    if (isset($result['_attributes']) === false) {
                        $result['_attributes'] = [];
                    }

                    $result['_attributes'][$prefix.':'.$name] = $value;
                }
            }
        }

        // Extract children.
        $children = $xml->children();
        if (count($children) === 0) {
            // Leaf node: always return array shape for compatibility.
            $text = trim((string) $xml);
            if ($text !== '') {
                $result['_value'] = $text;
            }

            return $result;
        }

        // OPTIMIZATION: Process child elements with faster array operations.
        foreach ($children as $child) {
            $childName  = $child->getName();
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
        $text = trim((string) $xml);
        if ($text !== '') {
            $result['_text'] = $text;
        }

        return $result;
    }//end xmlToArray()

    /**
     * Check if an array is associative (has string keys).
     *
     * @param mixed $value The value to check.
     *
     * @return bool True if associative, false if indexed
     */
    private function isAssoc(mixed $value): bool
    {
        if (is_array($value) === false) {
            return false;
        }

        return array_keys($value) !== range(0, count($value) - 1);
    }//end isAssoc()

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
     */
    public function importArchiMateFileFromPathOptimized(array $options=[]): array
    {
        $startTime   = microtime(true);
        $startMemory = memory_get_usage(true);

        // DEBUG: Verify that the optimized import method is being called.
        $this->logger->info('GEMMA IMPORT DEBUG: Starting optimized import', $options);

        // Starting OPTIMIZED ArchiMate XML import.
        try {
            // OPTIMIZATION: Cache all configuration once at start.
            $cacheStartTime = microtime(true);
            $this->initializeCache();
            $cacheTime = microtime(true) - $cacheStartTime;

            // Cache initialization completed.
            // STEP 1: Parse XML to array (same as before).
            $filePath = $options['filePath'] ?? $options['file_path'] ?? '';
            if (empty($filePath) === true || file_exists($filePath) === false) {
                throw new \InvalidArgumentException("File not found: {$filePath}");
            }

            $parseStartTime = microtime(true);
            $xmlData        = $this->parseArchiMateXml(filePath: $filePath);
            $parseTime      = microtime(true) - $parseStartTime;

            // PERFORMANCE OPTIMIZATION: Clean up memory after XML parsing.
            $memoryCleanupTime = 0;
            if (self::PERFORMANCE_OPTIMIZATIONS['memory_cleanup'] !== false) {
                $memCleanupStart = microtime(true);
                $this->cleanupMemory();
                $memoryCleanupTime = microtime(true) - $memCleanupStart;
            }

            // STEP 2: Extract model identifier.
            $modelIdStartTime    = microtime(true);
            $modelIdentifier     = $this->extractModelIdentifier(xmlData: $xmlData);
            $modelIdentifierTime = microtime(true) - $modelIdStartTime;

            // STEP 3: Parse ALL objects in one go (like CSV import).
            $transformStartTime = microtime(true);
            $allObjects         = $this->transformArchiMateXmlToObjectsBatch(
                xmlData: $xmlData,
                    modelIdentifier: $modelIdentifier
            );
            $transformTime      = microtime(true) - $transformStartTime;

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
            $saveTime     = microtime(true) - $saveStartTime;

            // Capture detailed save timing from internal tracking.
            $saveBreakdown = $this->lastSaveTiming;

            $totalTime      = microtime(true) - $startTime;
            $itemsPerSecond = count($allObjects) / max($totalTime, 0.001);

            // Use statistics directly from saveObjects result (stored in lastSaveResult).
            // No need for custom calculation since ObjectService already provides accurate stats.
            $statistics     = $this->buildStatisticsFromSaveResult();
            $detailedErrors = $this->extractDetailedErrors(statistics: $statistics);

            // OPTIMIZED import completed successfully.
            return [
                'success'             => true,
                'file_info'           => [
                    'name' => $options['fileName'] ?? basename($filePath),
                    'size' => filesize($filePath),
                ],
                'performance_metrics' => [
                    'total_time_seconds'       => round($totalTime, 3),
                    'items_per_second'         => round($itemsPerSecond, 1),
                    'objects_processed'        => count($allObjects),
                    'timing_breakdown'         => [
                        'cache_initialization_seconds'        => round($cacheTime, 3),
                        'xml_parsing_seconds'                 => round($parseTime, 3),
                        'memory_cleanup_seconds'              => round($memoryCleanupTime, 3),
                        'model_identifier_extraction_seconds' => round($modelIdentifierTime, 3),
                        'data_transformation_seconds'         => round($transformTime, 3),
                        'database_save_seconds'               => round($saveTime, 3),
                    ],
                    'save_operation_breakdown' => $saveBreakdown,
                    'memory_usage'             => [
                        'start_memory_mb'   => round($startMemory / 1024 / 1024, 2),
                        'current_memory_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
                        'peak_memory_mb'    => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
                    ],
                    'processing_rates'         => [
                        'xml_parse_objects_per_second' => round(count($allObjects) / max($parseTime, 0.001), 1),
                        'transform_objects_per_second' => round(count($allObjects) / max($transformTime, 0.001), 1),
                        'save_objects_per_second'      => round(count($allObjects) / max($saveTime, 0.001), 1),
                    ],
                ],
                'statistics'          => $statistics,
                'detailed_errors'     => $detailedErrors,
            ];
        } catch (\Exception $e) {
            $this->logger->error(
                    'OPTIMIZED ArchiMate import failed',
                    [
                        'error'     => $e->getMessage(),
                        'file_path' => $options['file_path'] ?? 'unknown',
                    ]
                    );

            return [
                'success' => false,
                'error'   => $e->getMessage(),
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
     */
    public function importArchiMateFileFromPath(array $options=[]): array
    {
        // Track start time and memory for performance metrics.
        $startTime   = microtime(true);
        $startMemory = memory_get_usage(true);

        // OPTIMIZATION: Cache configuration values once at the start.
        $this->initializeCache();

        // Starting ArchiMate XML import with model detection.
        try {
            // STEP 1: Parse XML to array using the specialized import service.
            // This captures ALL possible XML values including attributes, text content, and nested elements.
            $filePath = $options['filePath'] ?? $options['file_path'] ?? '';

            if (empty($filePath) === true) {
                throw new \InvalidArgumentException('File path is required for import');
            }

            if (file_exists($filePath) === false) {
                throw new \InvalidArgumentException("File not found: {$filePath}");
            }

            $this->logger->info('Step 1: Parsing XML to array for complete data capture', ['filePath' => $filePath]);
            $parseStartTime = microtime(true);
            $xmlData        = $this->parseArchiMateXml(filePath: $filePath);
            $parseTime      = microtime(true) - $parseStartTime;

            // STEP 2: Extract model identifier and detect if model already exists.
            // This is critical for determining whether to create new or update existing model.
            $this->logger->info('Step 2: Extracting model identifier and checking for existing model');
            $validationStartTime = microtime(true);
            $modelIdentifier     = $this->extractModelIdentifier(xmlData: $xmlData);
            $modelExists         = $this->checkIfModelExists(modelIdentifier: $modelIdentifier);
            $validationTime      = microtime(true) - $validationStartTime;

            // STEP 3: Normalize data structure for storage as JSON blob.
            // Store complete raw XML data for exact round-trip fidelity during export.
            $this->logger->info('Step 3: Normalizing data structure for JSON blob storage');
            $normalizedData = $this->normalizeArchiMateData(data: $xmlData, modelIdentifier: $modelIdentifier);

            // STEP 4: Convert to OpenRegister objects with proper @self structure.
            // Each object must have @self with register, schema, and id for ObjectService::saveObjects.
            $this->logger->info('Step 4: Converting to OpenRegister objects with @self structure');
            $convertStartTime = microtime(true);
            $objects          = $this->convertToOpenRegisterObjects(
                normalizedData: $normalizedData,
                modelIdentifier: $modelIdentifier
            );
            $convertTime      = microtime(true) - $convertStartTime;

            // STEP 5: Save objects using ObjectService::saveObjects.
            // This handles the actual database persistence with proper validation.
            $this->logger->info('Step 5: Saving objects to database using ObjectService::saveObjects');
            $savedObjects = $this->saveObjectsToDatabase(objects: $objects);

            // Calculate total time and memory usage.
            $totalTime  = microtime(true) - $startTime;
            $endMemory  = memory_get_usage(true);
            $peakMemory = memory_get_peak_usage(true);

            // Count objects by type for detailed statistics.
            $statistics = $this->calculateObjectStatistics(normalizedData: $normalizedData, savedObjects: $savedObjects);

            // Calculate performance metrics.
            $created      = $statistics['summary']['total_objects_created'];
            $updated      = $statistics['summary']['total_objects_updated'];
            $totalObjects = $created + $updated;
            if ($totalObjects > 0) {
                $itemsPerSecond = $totalObjects / $totalTime;
            } else {
                $itemsPerSecond = 0;
            }

            // Extract detailed error information from statistics.
            $detailedErrors = $this->extractDetailedErrors(statistics: $statistics);

            // Prepare comprehensive result with detailed information.
            $result = [
                'success'             => true,
                'file_info'           => [
                    'name'      => $options['fileName'] ?? basename($filePath),
                    'size'      => filesize($filePath),
                    'mime_type' => $options['mimeType'] ?? 'text/xml',
                ],
                'processing_times'    => [
                    'total_time_seconds'      => round($totalTime, 3),
                    'validation_time_seconds' => round($validationTime, 3),
                    'parse_time_seconds'      => round($parseTime, 3),
                    'convert_time_seconds'    => round($convertTime, 3),
                    'performance_breakdown'   => [
                        'validation_percent' => round(($validationTime / $totalTime) * 100, 1),
                        'parse_percent'      => round(($parseTime / $totalTime) * 100, 1),
                        'convert_percent'    => round(($convertTime / $totalTime) * 100, 1),
                    ],
                ],
                'memory_usage'        => [
                    'start_mb'      => round($startMemory / 1024 / 1024, 1),
                    'end_mb'        => round($endMemory / 1024 / 1024, 1),
                    'peak_mb'       => round($peakMemory / 1024 / 1024, 2),
                    'total_used_mb' => round(($endMemory - $startMemory) / 1024 / 1024, 1),
                ],
                'statistics'          => $statistics,
                'summary'             => [
                    'total_objects_created' => $statistics['summary']['total_objects_created'],
                    'total_objects_updated' => $statistics['summary']['total_objects_updated'],
                    'total_objects_deleted' => $statistics['summary']['total_objects_deleted'],
                    'total_objects_skipped' => $statistics['summary']['total_objects_skipped'],
                    'total_errors'          => $statistics['summary']['total_errors'],
                ],
                'performance_metrics' => [
                    'items_per_second'  => round($itemsPerSecond, 2),
                    'processing_method' => 'synchronous_batch_processing',
                    'batch_size_used'   => 100,
                    'dataset_size'      => $totalObjects,
                ],
                'detailed_errors'     => $detailedErrors,
            ];

            $this->logger->info(
                    'ArchiMate XML import completed successfully',
                    [
                        'model_identifier'    => $modelIdentifier,
                        'model_exists'        => $modelExists,
                        'imported_objects'    => $totalObjects,
                        'round_trip_fidelity' => 'enabled',
                    ]
                    );

            return $result;
        } catch (\Exception $e) {
            $this->logger->error(
                    'ArchiMate XML import failed',
                    [
                        'error'     => $e->getMessage(),
                        'trace'     => $e->getTraceAsString(),
                        'file_path' => $options['filePath'] ?? $options['file_path'] ?? 'unknown',
                    ]
                    );

            return [
                'success'     => false,
                'message'     => 'Import failed: '.$e->getMessage(),
                'error'       => $e->getMessage(),
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
     */
    private function parseArchiMateXml(string $filePath): array
    {
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
        $xml    = new SimpleXMLElement($xmlContent, LIBXML_NOCDATA | LIBXML_NONET);
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
    private function extractModelIdentifier(array $xmlData): string
    {
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
        $fallbackId = 'model-'.uniqid().'-'.time();
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
    private function checkIfModelExists(string $modelIdentifier): bool
    {
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
            $schemaId   = $this->getAmefSchemaIdForType(archiMateType: 'model');

            if ($registerId === null || $schemaId === false) {
                $this->logger->warning(
                        'AMEF register or model schema not configured, assuming new model',
                        [
                            'registerId' => $registerId,
                            'schemaId'   => $schemaId,
                        ]
                        );
                return false;
            }

            // Query for existing model objects with this identifier.
            // Use searchObjects with @self structure for proper querying.
            $query = [
                '@self'        => [
                    'register' => $registerId,
                    'schema'   => $schemaId,
                ],
                'archimate_id' => $modelIdentifier,
            ];

            $existingModels = $objectService->searchObjects($query);

            $exists = empty($existingModels) === false;

            $this->logger->info(
                    'Model existence check completed',
                    [
                        'model_identifier' => $modelIdentifier,
                        'exists'           => $exists,
                        'found_count'      => count($existingModels),
                        'registerId'       => $registerId,
                        'schemaId'         => $schemaId,
                    ]
                    );

            return $exists;
        } catch (\Exception $e) {
            $this->logger->error(
                    'Error checking model existence',
                    [
                        'model_identifier' => $modelIdentifier,
                        'error'            => $e->getMessage(),
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
     * @param array  $data            Raw parsed XML data from import service
     * @param string $modelIdentifier The model identifier for linking items
     *
     * @return array Normalized data structure ready for database storage
     */
    private function normalizeArchiMateData(array $data, string $modelIdentifier): array
    {
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
            'model_metadata'       => [],
            'model_identifier'     => $modelIdentifier,
        // Add model identifier for linking.
            'elements'             => [],
            'relationships'        => [],
            'organizations'        => [],
            'views'                => [],
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
                    'metadata_keys'     => array_keys($normalized['model_metadata']),
                    'has_name'          => isset($normalized['model_metadata']['name']) === true,
                    'has_documentation' => isset($normalized['model_metadata']['documentation']) === true,
                ]
                );

        // STEP 2: Process each section and store complete raw XML data.
        $sections         = ['elements', 'relationships', 'organizations', 'views', 'property_definitions'];
        $alternativeNames = [
            'views'                => ['views', 'diagrams'],
            'organizations'        => ['organizations', 'organisation'],
            'property_definitions' => ['propertyDefinitions', 'property_definitions', 'propertydefinitions'],
        ];
        foreach ($sections as $section) {
            $sectionData       = null;
            $actualSectionName = null;
            if (isset($data[$section]) === true) {
                $sectionData       = $data[$section];
                $actualSectionName = $section;
            } else {
                if (isset($alternativeNames[$section]) === true) {
                    foreach ($alternativeNames[$section] as $altName) {
                        if (isset($data[$altName]) === true) {
                            $sectionData       = $data[$altName];
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
                    $syntheticId = 'org-'.preg_replace('/^id-/', '', $modelIdentifier);
                    $normalized[$section][$syntheticId] = [
                        'identifier'       => $syntheticId,
                        'section'          => 'organization',
                        'model_identifier' => $modelIdentifier,
                        'name'             => 'Organizations',
                        // Complete hierarchy preserved.
                        'xml'              => $sectionData,
                    ];
                } else {
                    $normalized[$section] = $this->extractSectionDataWithProperties(
                        sectionData: $sectionData,
                        sectionName: $section,
                        modelIdentifier: $modelIdentifier,
                        propDefMap: $propDefMap
                    );
                }
            }//end if
        }//end foreach

        $this->logger->info(
                'Data normalization completed',
                [
                    'model_identifier'    => $modelIdentifier,
                    'sections_processed'  => $sections,
                    'round_trip_fidelity' => 'enabled',
                ]
                );
        return $normalized;
    }//end normalizeArchiMateData()

    /**
     * Extract data from a specific section, flatten properties, and store xml
     *
     * @param mixed  $sectionData     Section data from XML parsing
     * @param string $sectionName     Name of the section being processed
     * @param string $modelIdentifier The model identifier for linking items
     * @param array  $propDefMap      Map of propertyDefinitionRef => property name
     *
     * @return array Extracted section data with complete XML preservation and flattened properties
     */
    private function extractSectionDataWithProperties(
        mixed $sectionData,
        string $sectionName,
        string $modelIdentifier,
        array $propDefMap
    ): array {
        $extracted = [];
        if (is_array($sectionData) === true) {
            $items = $this->findItemsInSection(sectionData: $sectionData, sectionName: $sectionName);
            foreach ($items as $item) {
                $identifier = $this->extractIdentifier(item: $item, sectionName: $sectionName);
                if (empty($identifier) === false) {
                    // OPTIMIZATION: Store XML data directly without expensive deep copy.
                    // Start with base object structure.
                    $object = [
                        'identifier'       => $identifier,
                        'section'          => $sectionName,
                        'model_identifier' => $modelIdentifier,
                        'extracted_at'     => time(),
                        'xml'              => $this->extractEssentialXmlData(item: $item),
                    // OPTIMIZATION: Store only essential XML data.
                    ];

                    // Extract type from xsi:type attribute
                    // (e.g., "Capability", "ApplicationComponent", "Referentiecomponent").
                    // The xsi:type is stored as _xsi__type or in _attributes['xsi:type'].
                    if (isset($item['_xsi__type']) === true) {
                        $object['type'] = $item['_xsi__type'];
                    } else if (isset($item['_attributes']['xsi:type']) === true) {
                        $object['type'] = $item['_attributes']['xsi:type'];
                    }

                    // Extract name from XML if it exists.
                    if (isset($item['name']) === true) {
                        if (is_array($item['name']) === true && isset($item['name']['_value']) === true) {
                            $object['name'] = $item['name']['_value'];
                        } else if (is_string($item['name']) === true) {
                            $object['name'] = $item['name'];
                        }
                    }

                    // Extract documentation from XML if it exists - set both summary and documentation.
                    if (isset($item['documentation']) === true) {
                        $docValue = null;
                        if (is_array($item['documentation']) === true && isset($item['documentation']['_value']) === true) {
                            $docValue = $item['documentation']['_value'];
                        } else if (is_string($item['documentation']) === true) {
                            $docValue = $item['documentation'];
                        }

                        if ($docValue !== null) {
                            $object['summary']       = $docValue;
                            $object['documentation'] = $docValue;
                            // Also set documentation field for schema compatibility.
                        }
                    }

                    // Flatten properties to root fields using the propertyDefinitionMap.
                    if (isset($item['properties']) === true && isset($item['properties']['property']) === true) {
                        $props = $item['properties']['property'];
                        $processedProperties = [];
                        if (isset($props[0]) === true) {
                            // Multiple properties.
                            foreach ($props as $prop) {
                                $defRef = $prop['_attributes']['propertyDefinitionRef'] ?? null;
                                $value  = $prop['value']['_value'] ?? $prop['value'] ?? null;
                                if ($defRef !== false && isset($propDefMap[$defRef]) === true) {
                                    $name          = $propDefMap[$defRef];
                                    $camelCaseName = $this->convertToCamelCase(propertyName: $name);
                                    $object[$camelCaseName] = $value;

                                    // Store property mapping for reference.
                                    if (isset($object['_propertyMapping']) === false) {
                                        $object['_propertyMapping'] = [];
                                    }

                                    $object['_propertyMapping'][$camelCaseName] = $name;

                                    // If this property is 'Object ID', set slug for later use.
                                    if (strtolower($name) === 'object id') {
                                        $object['_slug'] = $value;
                                        // Store temporarily, will be moved to @self.slug later.
                                    }
                                }
                            }//end foreach
                        } else if (isset($props['_attributes']['propertyDefinitionRef']) === true) {
                            // Single property.
                            $defRef = $props['_attributes']['propertyDefinitionRef'];
                            $value  = $props['value']['_value'] ?? $props['value'] ?? null;
                            if ($defRef !== false && isset($propDefMap[$defRef]) === true) {
                                $name          = $propDefMap[$defRef];
                                $camelCaseName = $this->convertToCamelCase(propertyName: $name);
                                $object[$camelCaseName] = $value;

                                // Store property mapping for reference.
                                if (isset($object['_propertyMapping']) === false) {
                                    $object['_propertyMapping'] = [];
                                }

                                $object['_propertyMapping'][$camelCaseName] = $name;

                                $processedProperties[] = [
                                    'original'  => $name,
                                    'camelCase' => $camelCaseName,
                                    'value'     => $value,
                                ];

                                if (strtolower($name) === 'object id') {
                                    $object['_slug'] = $value;
                                    // Store temporarily, will be moved to @self.slug later.
                                }
                            }//end if
                        }//end if
                    }//end if

                    $extracted[$identifier] = $object;
                }//end if
            }//end foreach
        }//end if

        return $extracted;
    }//end extractSectionDataWithProperties()

    /**
     * Convert normalized data to OpenRegister objects with @self structure
     *
     * This method creates OpenRegister objects from the normalized ArchiMate data:
     * 1. Creates a model object with proper @self structure
     * 2. Creates section objects for each item (elements, relationships, etc.)
     * 3. Ensures each object has the required @self structure for ObjectService::saveObjects
     * 4. Links all objects to the parent model via model_identifier
     *
     * @param array  $normalizedData  Normalized ArchiMate data with model_identifier
     * @param string $modelIdentifier The model identifier for linking objects
     *
     * @return array Array of OpenRegister objects with proper @self structure
     */
    private function convertToOpenRegisterObjects(array $normalizedData, string $modelIdentifier): array
    {
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
                        data: $data,
                        modelIdentifier: $modelIdentifier
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
                    'model_identifier'   => $modelIdentifier,
                    'total_objects'      => count($objects),
                    'sections_processed' => $sections,
                ]
                );

        return $objects;
    }//end convertToOpenRegisterObjects()

    /**
     * Create model object with @self structure
     *
     * @param array  $metadata        Model metadata
     * @param string $modelIdentifier Model identifier
     *
     * @return array Model object with @self structure
     */
    private function createModelObject(array $metadata, string $modelIdentifier): array
    {
        // OPTIMIZATION: Use cached configuration values.
        $registerId     = $this->cachedConfig['registerId'] ?? throw new \RuntimeException(
                "Register ID not found. Ensure AMEF config is initialized."
            );
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
                $nameString = (string) $metadata['name']['_value'];
            } else if (is_string($metadata['name']) === true) {
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
            '@self'            => [
                'register'     => $registerId,
                'schema'       => $schemaId,
                'id'           => $metadata['identifier'] ?? uniqid('model_'),
                'owner'        => $this->getCurrentUserId(),
                'organisation' => $this->getCurrentOrganisation(),
                'published'    => date('Y-m-d\TH:i:s\Z'),
            ],
            'identifier'       => $metadata['identifier'] ?? '',
            'section'          => 'model',
            'model_identifier' => $modelIdentifier,
            'xml'              => $xmlData,
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
     * @param string $section         Section name
     * @param string $identifier      Item identifier
     * @param array  $data            Item data (already contains XML data at root level)
     * @param string $modelIdentifier Model identifier for linking
     *
     * @return array Section object with @self structure
     */
    private function createSectionObject(string $section, string $identifier, array $data, string $modelIdentifier): array
    {
        // OPTIMIZATION: Use cached configuration values.
        $registerId = $this->cachedConfig['registerId'] ?? throw new \RuntimeException(
                "Register ID not found. Ensure AMEF config is initialized."
            );
        $schemaId   = $this->cachedConfig['schemaIds'][$section] ?? $this->getSchemaIdForSection(section: $section);

        // FIXED: Use objectId as main ID and AMEF identifier as slug.
        $objectId = null;
        $slug     = null;

        // Priority 1: Check for objectId property (flattened from "Object ID").
        if (isset($data['objectId']) === true) {
            $objectId = $data['objectId'];
            // Use AMEF identifier as slug.
            $slug = $identifier;
        } else if (isset($data['_slug']) === true) {
            // Priority 2: Check for temporary _slug field (legacy support).
            $objectId = $data['_slug'];
            // Use AMEF identifier as slug.
            $slug = $identifier;
            // Remove the temporary field.
            unset($data['_slug']);
        } else if (isset($data['Object ID']) === true) {
            // Priority 3: Check for direct "Object ID" property.
            $objectId = $data['Object ID'];
            // Use AMEF identifier as slug.
            $slug = $identifier;
        } else {
            // Fallback: Use AMEF identifier as both ID and extract clean UUID for slug.
            $objectId = $identifier;
            // Extract clean UUID from AMEF identifier (remove "id-" prefix if present).
            if ($identifier !== false && str_starts_with($identifier, 'id-') === true) {
                $slug = substr($identifier, 3);
                // Remove "id-" prefix.
            } else {
                $slug = $identifier;
            }
        }//end if

        // Create object with @self structure using correct ID and slug.
        $object = [
            '@self' => [
                'register'     => $registerId,
                'schema'       => $schemaId,
                'id'           => $objectId,
        // Now using objectId as main ID.
                'slug'         => $slug,
        // Now using AMEF identifier as slug.
                'owner'        => $this->getCurrentUserId(),
                'organisation' => $this->getCurrentOrganisation(),
                'published'    => date('Y-m-d\TH:i:s\Z'),
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
    private function saveObjectsToDatabase(array $objects): array
    {
        $saveStartTime = microtime(true);

        // DEBUG: Log basic object info before sending to ObjectService.
        // Find first element with gemmaType for debugging.
        $gemmaElements = array_filter(
            $objects,
            fn($o) => ($o['section'] ?? '') === 'element' && empty($o['gemmaType']) === false
        );
        if (empty($gemmaElements) === false) {
            $sampleGemmaElem = array_values($gemmaElements)[0];
        } else {
            $sampleGemmaElem = null;
        }

        $this->logger->debug(
                'Objects before save',
                [
                    'total_objects_to_save'   => count($objects),
                    'elements_with_gemmaType' => count($gemmaElements),
                ]
                );

        $serviceInitStartTime = microtime(true);
        $objectService        = $this->getObjectService();
        if ($objectService === null) {
            throw new \RuntimeException('ObjectService not available');
        }

        $serviceInitTime = microtime(true) - $serviceInitStartTime;

        // ENHANCEMENT: Process GEMMA Referentiecomponent-Standaard relationships before saving.
        $gemmaStartTime = microtime(true);
        $objects        = $this->processGemmaReferenceComponentStandards(objects: $objects);
        $gemmaProcessingTime = microtime(true) - $gemmaStartTime;

        // Saving objects to database.
        // OPTIMIZATION: Use cached register ID.
        $registerId = $this->cachedConfig['registerId'] ?? throw new \RuntimeException(
                "Register ID not found. Ensure AMEF config is initialized."
            );

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
                    'schemas'     => array_map('count', $schemaGroups),
                ]
                );

        // Process each schema group.
        $allResults      = [];
        $aggregatedStats = [
            'saved'     => [],
            'updated'   => [],
            'unchanged' => [],
            'invalid'   => [],
        ];
        // Track counts per schema for accurate statistics (serialized objects lose the 'section' field).
        $countsBySchema = [];

        foreach ($schemaGroups as $schemaId => $schemaObjects) {
            $schemaObjectCount = count($schemaObjects);

            try {
                // Save this schema group with the specific schema ID.
                // PERFORMANCE: Disabled validation and events for bulk import (like CSV import pattern).
                if ($schemaId !== 'unknown') {
                    $schemaValue = (int) $schemaId;
                } else {
                    $schemaValue = null;
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

                // Merge results.
                $aggregatedStats['saved']     = array_merge($aggregatedStats['saved'], $saveResult['saved'] ?? []);
                $aggregatedStats['updated']   = array_merge($aggregatedStats['updated'], $saveResult['updated'] ?? []);
                $aggregatedStats['unchanged'] = array_merge($aggregatedStats['unchanged'], $saveResult['unchanged'] ?? []);
                $aggregatedStats['invalid']   = array_merge($aggregatedStats['invalid'], $saveResult['invalid'] ?? []);

                // Track per-schema counts for statistics (since serialized objects lose 'section').
                $countsBySchema[$schemaId] = [
                    'saved'     => count($saveResult['saved'] ?? []),
                    'updated'   => count($saveResult['updated'] ?? []),
                    'unchanged' => count($saveResult['unchanged'] ?? []),
                    'invalid'   => count($saveResult['invalid'] ?? []),
                ];

                $allResults = array_merge(
                    $allResults,
                    $saveResult['saved'] ?? [],
                    $saveResult['updated'] ?? [],
                    $saveResult['unchanged'] ?? []
                );

                $this->logger->debug(
                        'Schema group saved for magic mapping',
                        [
                            'schemaId'    => $schemaId,
                            'objectCount' => $schemaObjectCount,
                            'saved'       => count($saveResult['saved'] ?? []),
                            'updated'     => count($saveResult['updated'] ?? []),
                        ]
                        );
            } catch (\Exception $e) {
                $this->logger->error(
                        'Error saving schema group',
                        [
                            'schemaId'    => $schemaId,
                            'error'       => $e->getMessage(),
                            'objectCount' => $schemaObjectCount,
                        ]
                        );
            }//end try
        }//end foreach

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
        $savedCount      = count($aggregatedStats['saved'] ?? []);
        $updatedCount    = count($aggregatedStats['updated'] ?? []);
        $unchangedCount  = count($aggregatedStats['unchanged'] ?? []);
        $totalSavedCount = $savedCount + $updatedCount + $unchangedCount;
        if ($totalSavedCount > 0) {
            $objectsSavedValue = $totalSavedCount;
        } else {
            $objectsSavedValue = count($objects);
        }

        $this->lastSaveTiming = [
            'total_save_seconds'           => round($totalSaveTime, 3),
            'service_init_seconds'         => round($serviceInitTime, 3),
            'gemma_processing_seconds'     => round($gemmaProcessingTime, 3),
            'batch_processing_seconds'     => round($batchProcessingTime, 3),
            'objects_saved'                => $objectsSavedValue,
            'save_rate_objects_per_second' => round(count($objects) / max($totalSaveTime, 0.001), 1),
        ];

        return $result;
    }//end saveObjectsToDatabase()

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
    private function fixStandaardVersieUuids(int $registerId): void
    {
        try {
            $elementSchemaId = $this->cachedConfig['schemaIds']['element'] ?? null;
            if ($elementSchemaId === null) {
                $this->logger->warning('fixStandaardVersieUuids: Element schema ID not found in config');
                return;
            }

            // Get database connection.
            $connection = \OC::$server->getDatabaseConnection();
            $tableName  = 'oc_openregister_table_'.$registerId.'_'.$elementSchemaId;

            // Step 1: Build a mapping from ArchiMate identifier to database UUID for Standaarden.
            // The identifier field contains "id-{archimate_id}" and we need the _uuid field.
            $standaardQuery = $connection->executeQuery(
                "SELECT _uuid, identifier FROM {$tableName} WHERE gemma_type = 'Standaard' AND identifier IS NOT NULL"
            );

            $identifierToUuid = [];
            while (($row = $standaardQuery->fetch()) !== false) {
                $identifier = $row['identifier'] ?? '';
                $uuid       = $row['_uuid'] ?? '';

                if ($identifier !== false && $uuid === true) {
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
                $result       = $connection->executeStatement(
                    "UPDATE {$tableName} SET standaard = ? WHERE standaard = ? AND gemma_type = 'Standaardversie'",
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
     * Save objects directly to ObjectService without custom batching
     * Lets ObjectService handle all batching, throttling, and optimization internally
     *
     * @param array         $objects       Array of objects to save
     * @param ObjectService $objectService ObjectService instance
     * @param int           $registerId    Register ID
     *
     * @return array Array of saved objects
     */
    private function saveObjectsDirectToService(array $objects, ObjectService $objectService, int $registerId): array
    {
        try {
            // GROUP BY SCHEMA: For magic mapping support, save objects schema by schema.
            // This ensures each batch has a single schema so UnifiedObjectMapper can route to the correct table.
            $schemaGroups = [];
            foreach ($objects as $obj) {
                $schemaId = $obj['@self']['schema'] ?? 'unknown';
                $schemaGroups[$schemaId][] = $obj;
            }

            $this->logger->info(
                    'ArchiMate import: Grouped objects by schema',
                    [
                        'schemaCount' => count($schemaGroups),
                        'schemas'     => array_map('count', $schemaGroups),
                    ]
                    );

            // Save each schema group separately.
            $allSaved     = [];
            $allUpdated   = [];
            $allUnchanged = [];
            $allSkipped   = [];
            $allInvalid   = [];

            foreach ($schemaGroups as $schemaId => $schemaObjects) {
                if ($schemaId !== 'unknown') {
                    $schemaValue = (int) $schemaId;
                } else {
                    $schemaValue = null;
                }

                $saveResult = $objectService->saveObjects(
                    objects: $schemaObjects,
                    register: $registerId,
                    schema: $schemaValue,
                    _rbac: true,
                    _multitenancy: true,
                    validation: true,
                    events: true
                );

                // Merge results.
                $allSaved     = array_merge($allSaved, $saveResult['saved'] ?? []);
                $allUpdated   = array_merge($allUpdated, $saveResult['updated'] ?? []);
                $allUnchanged = array_merge($allUnchanged, $saveResult['unchanged'] ?? []);
                $allSkipped   = array_merge($allSkipped, $saveResult['skipped'] ?? []);
                $allInvalid   = array_merge($allInvalid, $saveResult['invalid'] ?? []);

                $this->logger->debug(
                        'Schema group saved',
                        [
                            'schemaId'    => $schemaId,
                            'objectCount' => count($schemaObjects),
                            'saved'       => count($saveResult['saved'] ?? []),
                            'updated'     => count($saveResult['updated'] ?? []),
                        ]
                        );
            }//end foreach

            // Combine all results.
            $saveResult = [
                'saved'     => $allSaved,
                'updated'   => $allUpdated,
                'unchanged' => $allUnchanged,
                'skipped'   => $allSkipped,
                'invalid'   => $allInvalid,
            ];

            // Store result for statistics.
            $this->lastSaveResult = $saveResult;

            // DEBUG: Log ObjectService save result.
            $this->logger->info(
                    'ObjectService save result DEBUG',
                    [
                        'total_objects_sent' => count($objects),
                        'saved_count'        => count($allSaved),
                        'updated_count'      => count($allUpdated),
                        'unchanged_count'    => count($allUnchanged),
                        'skipped_count'      => count($allSkipped),
                        'invalid_count'      => count($allInvalid),
                    ]
                    );

            // Return combined saved and updated objects.
            return array_merge($allSaved, $allUpdated, $allUnchanged);
        } catch (\Exception $e) {
            $this->logger->error(
                    'Error in direct ObjectService save',
                    [
                        'error'        => $e->getMessage(),
                        'object_count' => count($objects),
                    ]
                    );
            throw $e;
        }//end try
    }//end saveObjectsDirectToService()

    /**
     * Save objects in parallel batches for maximum performance (DEPRECATED)
     *
     * @param array         $objects       Array of objects to save
     * @param ObjectService $objectService ObjectService instance
     * @param int           $registerId    Register ID
     *
     * @return array Array of saved objects
     */
    private function saveObjectsInParallelBatches(array $objects, ObjectService $objectService, int $registerId): array
    {
        $batchSize       = self::PERFORMANCE_OPTIMIZATIONS['batch_size'];
        $parallelBatches = self::PERFORMANCE_OPTIMIZATIONS['parallel_batches'];

        // INTELLIGENT BATCH SIZING: Create size-aware batches instead of fixed-size chunks.
        $chunks      = $this->createIntelligentBatches(objects: $objects);
        $totalChunks = count($chunks);

        // Batch processing initialized.
        $allResults      = [];
        $processedChunks = 0;

        // Accumulate statistics from all chunks (using new format).
        $aggregatedStats = [
            'saved'     => [],
            'updated'   => [],
            'unchanged' => [],
            'invalid'   => [],
        ];

        // Process chunks sequentially but with larger batch sizes for better performance.
        foreach ($chunks as $chunkIndex => $chunk) {
            $chunkInputCount = count($chunk);

            try {
                if (self::PERFORMANCE_OPTIMIZATIONS['disable_rbac'] === true) {
                    $_rbacValue = false;
                } else {
                    $_rbacValue = true;
                }

                $saveResult = $objectService->saveObjects(
                    objects: $chunk,
                    register: $registerId,
                    schema: null,
                    _rbac: $_rbacValue,
                    _multitenancy: true,
                    validation: !self::PERFORMANCE_OPTIMIZATIONS['disable_validation'],
                    events: !self::PERFORMANCE_OPTIMIZATIONS['disable_events']
                );

                // Calculate totals received back from this chunk.
                $chunkSaved         = count($saveResult['saved'] ?? []);
                $chunkUpdated       = count($saveResult['updated'] ?? []);
                $chunkUnchanged     = count($saveResult['unchanged'] ?? []);
                $chunkInvalid       = count($saveResult['invalid'] ?? []);
                $chunkTotalReceived = $chunkSaved + $chunkUpdated + $chunkUnchanged + $chunkInvalid;

                // Accumulate statistics from this chunk.
                $aggregatedStats['saved']     = array_merge($aggregatedStats['saved'], $saveResult['saved'] ?? []);
                $aggregatedStats['updated']   = array_merge($aggregatedStats['updated'], $saveResult['updated'] ?? []);
                $aggregatedStats['unchanged'] = array_merge($aggregatedStats['unchanged'], $saveResult['unchanged'] ?? []);
                $aggregatedStats['invalid']   = array_merge($aggregatedStats['invalid'], $saveResult['invalid'] ?? []);

                $savedObjects = array_merge(
                    $saveResult['saved'] ?? [],
                    $saveResult['updated'] ?? []
                );

                $allResults = array_merge($allResults, $savedObjects);

                $processedChunks++;
            } catch (\Exception $e) {
                $this->logger->error(
                        'Error processing chunk',
                        [
                            'chunk_index' => $chunkIndex,
                            'error'       => $e->getMessage(),
                        ]
                        );
                // Continue with other chunks.
            }//end try

            // Memory cleanup between chunks.
            if (self::PERFORMANCE_OPTIMIZATIONS['memory_cleanup'] !== false) {
                $this->cleanupMemory();
            }
        }//end foreach

        // Store the aggregated result for statistics calculation.
        $this->lastSaveResult = $aggregatedStats;

        $totalSaved        = count($aggregatedStats['saved']);
        $totalUpdated      = count($aggregatedStats['updated']);
        $totalUnchanged    = count($aggregatedStats['unchanged']);
        $totalInvalid      = count($aggregatedStats['invalid']);
        $totalObjProcessed = $totalSaved + $totalUpdated + $totalUnchanged + $totalInvalid;

        // Batch processing completed.
        // Log critical discrepancy if found.
        if (count($objects) !== $totalObjProcessed) {
            $this->logger->critical(
                    'OBJECT COUNT MISMATCH DETECTED',
                    [
                        'objects_sent_to_openregister'          => count($objects),
                        'objects_processed_by_openregister'     => $totalObjProcessed,
                        'missing_objects'                       => count($objects) - $totalObjProcessed,
                        'this_explains_the_781_missing_objects' => true,
                    ]
                    );
        }

        return $allResults;
    }//end saveObjectsInParallelBatches()

    /**
     * Save objects in a single batch (fallback method)
     *
     * @param array         $objects       Array of objects to save
     * @param ObjectService $objectService ObjectService instance
     * @param int           $registerId    Register ID
     *
     * @return array Array of saved objects
     */
    private function saveObjectsInSingleBatch(array $objects, ObjectService $objectService, int $registerId): array
    {
        // Using single batch processing.
        if (self::PERFORMANCE_OPTIMIZATIONS['disable_rbac'] === true) {
            $_rbacValue = false;
        } else {
            $_rbacValue = true;
        }

        $saveResult = $objectService->saveObjects(
            objects: $objects,
            register: $registerId,
            schema: null,
            _rbac: $_rbacValue,
            _multitenancy: true,
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

        // Objects saved successfully.
        // Validation errors logged if any.
        // Unchanged objects noted if any.
        // Return the combined saved and updated objects (maintaining backward compatibility).
        return $savedObjects;
    }//end saveObjectsInSingleBatch()

    /**
     * Get ObjectService from container
     *
     * @return ObjectService|null ObjectService instance or null if not available
     */
    private function getObjectService(): ?ObjectService
    {
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
    private function initializeCache(): void
    {
        if ($this->cachedConfig !== null) {
            return;
            // Already cached.
        }

        $this->cachedConfig = [
            'userId'       => $this->userSession->getUser()?->getUID(),
            'organisation' => 'default',
            'registerId'   => $this->getAmefRegisterId(),
            'schemaIds'    => [
                'model'               => $this->getAmefSchemaIdForType(archiMateType: 'model'),
                'element'             => $this->getAmefSchemaIdForType(archiMateType: 'element'),
                'relationship'        => $this->getAmefSchemaIdForType(archiMateType: 'relationship'),
                'view'                => $this->getAmefSchemaIdForType(archiMateType: 'view'),
                'organization'        => $this->getAmefSchemaIdForType(archiMateType: 'organization'),
                'property_definition' => $this->getAmefSchemaIdForType(archiMateType: 'property_definition'),
                // NOTE: 'property' removed - properties are never root-level
                // AMEF objects, only nested within other elements.
            ],
        ];
    }//end initializeCache()

    /**
     * Log current memory usage for performance monitoring
     *
     * @param string $stage Description of the current processing stage
     *
     * @return void
     */
    private function logMemoryUsage(string $stage): void
    {
        // Check if debug logging is available (Nextcloud logger doesn't have isDebug method).
        $memoryUsage = memory_get_usage(true);
        $memoryPeak  = memory_get_peak_usage(true);
        $memoryLimit = ini_get('memory_limit');

        $this->logger->debug(
                "Memory usage at: {$stage}",
                [
                    'current_mb' => round($memoryUsage / 1024 / 1024, 2),
                    'peak_mb'    => round($memoryPeak / 1024 / 1024, 2),
                    'limit'      => $memoryLimit,
                ]
                );
    }//end logMemoryUsage()

    /**
     * Clean up memory by forcing garbage collection
     *
     * @return void
     */
    private function cleanupMemory(): void
    {
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
    private function getCurrentUserId(): ?string
    {
        return $this->cachedConfig['userId'] ?? null;
    }//end getCurrentUserId()

    /**
     * Get current organisation UUID from OrganisationService
     *
     * @return string Organisation UUID
     */
    private function getCurrentOrganisation(): string
    {
        try {
            $this->logger->info('Getting default organisation from OrganisationService');
            $defaultOrganisation = $this->organisationService->ensureDefaultOrganisation();
            $uuid = $defaultOrganisation->getUuid();

            $this->logger->info('Got default organisation UUID: '.$uuid);
            return $uuid;
        } catch (\Exception $e) {
            $this->logger->error('Failed to get default organisation: '.$e->getMessage());
            $this->logger->error('Exception trace: '.$e->getTraceAsString());
            // Fallback to cached value or 'default' string.
            return $this->cachedConfig['organisation'] ?? 'default';
        }
    }//end getCurrentOrganisation()

    /**
     * Get AMEF configuration from app config
     *
     * @return array AMEF configuration
     */
    public function getAmefConfig(): array
    {
        $this->logger->info('Getting AMEF configuration');

        try {
            // Get configuration from app config using the correct method.
            $config  = $this->config->getValueString('softwarecatalog', 'amef_config', '{}');
            $decoded = json_decode($config, true);

            if (is_array($decoded) === false) {
                // Fallback to individual config values for backward compatibility.
                $decoded = [
                    'register_id'                 => $this->config->getValueString(
                        'softwarecatalog',
                            'amef_register',
                            ''
                    ),
                    'model_schema_id'             => $this->config->getValueString(
                        'softwarecatalog',
                            'amef_model_schema',
                            ''
                    ),
                    'elements_schema'             => $this->config->getValueString(
                        'softwarecatalog',
                            'amef_elements_schema',
                            ''
                    ),
                    'relationships_schema'        => $this->config->getValueString(
                        'softwarecatalog',
                            'amef_relationships_schema',
                            ''
                    ),
                    'views_schema'                => $this->config->getValueString(
                        'softwarecatalog',
                            'amef_views_schema',
                            ''
                    ),
                    'organizations_schema'        => $this->config->getValueString(
                        'softwarecatalog',
                            'amef_organizations_schema',
                            ''
                    ),
                    'folders_schema'              => $this->config->getValueString(
                        'softwarecatalog',
                            'amef_folders_schema',
                            ''
                    ),
                    'property_definitions_schema' => $this->config->getValueString(
                        'softwarecatalog',
                            'amef_property_definitions_schema',
                            ''
                    ),
                ];
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
                'error'   => $e->getMessage(),
            ];
        }//end try
    }//end getAmefConfig()

    /**
     * Get AMEF register ID from configuration
     *
     * @return int|null The register ID or null if not configured
     */
    private function getAmefRegisterId(): ?int
    {
        // Retrieve AMEF configuration.
        $amefConfig = $this->getAmefConfig();

        // Try JSON config keys first: support both 'register_id' and 'register'.
        $rawRegisterId = $amefConfig['register_id'] ?? $amefConfig['register'] ?? null;

        // Fallback to legacy individual app config keys if not present in JSON.
        if ($rawRegisterId === null || $rawRegisterId === '') {
            if ($this->config->getValueString('softwarecatalog', 'amef_register', '') !== '') {
                $rawRegisterId = $this->config->getValueString('softwarecatalog', 'amef_register', '');
            } else {
                $rawRegisterId = $this->config->getValueString('softwarecatalog', 'amef_register_id', '');
            }
        }

        // Validate and normalize to positive int.
        if ($rawRegisterId !== null && $rawRegisterId !== '' && is_numeric((string) $rawRegisterId) === true) {
            $registerId = (int) $rawRegisterId;
            if ($registerId > 0) {
                return $registerId;
            } else {
                return null;
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
    private function getAmefSchemaIdForType(string $archiMateType): ?int
    {
        // Get AMEF configuration.
        $amefConfig = $this->getAmefConfig();

        // Normalize plural → singular and handle the actual config structure.
        $typeMapping    = [
            'elements'             => 'element',
            'organizations'        => 'organization',
            // Accept both 'relationships' (AMEF wording) and UI term 'relation'.
            'relationships'        => 'relation',
            'views'                => 'view',
            'models'               => 'model',
            // NOTE: 'properties' mapping removed - properties are never root-level AMEF objects.
            'property_definitions' => 'property_definition',
        ];
        $normalizedType = $typeMapping[$archiMateType] ?? $archiMateType;

        // Candidate keys: match the actual config structure.
        $schemaCandidates = [
            'element'             => ['element_schema'],
            'organization'        => ['organization_schema'],
            'relationship'        => ['relation_schema'],
            'view'                => ['view_schema'],
            'model'               => ['model_schema'],
            'property_definition' => ['property_definition_schema'],
            // NOTE: 'property' removed - properties are never root-level AMEF objects, only nested within other elements.
        ];

        $candidates = $schemaCandidates[$normalizedType] ?? [$normalizedType.'_schema'];

        // Try JSON config with the actual keys.
        foreach ($candidates as $key) {
            if (array_key_exists($key, $amefConfig) === true) {
                $raw = $amefConfig[$key];
                if ($raw !== '' && $raw !== null && is_numeric((string) $raw) === true) {
                    $id = (int) $raw;
                    if ($id > 0) {
                        return $id;
                    }
                }
            }
        }

        // Fallback to legacy individual app config keys if not present in JSON.
        foreach ($candidates as $key) {
            if ($this->config->getValueString('softwarecatalog', 'amef_'.$key, '') !== '') {
                $raw = $this->config->getValueString('softwarecatalog', 'amef_'.$key, '');
            } else {
                $raw = $this->config->getValueString('softwarecatalog', $key, '');
            }

            if ($raw !== '' && is_numeric((string) $raw) === true) {
                $id = (int) $raw;
                if ($id > 0) {
                    return $id;
                }
            }
        }

        return null;
    }//end getAmefSchemaIdForType()

    /**
     * Get schema ID for a section using SettingsService (no hardcoded fallbacks)
     *
     * @param string $section Section name
     *
     * @return int Schema ID
     * @throws \RuntimeException If schema ID is not configured
     */
    private function getSchemaIdForSection(string $section): int
    {
        // Map section names to object types for SettingsService.
        $objectTypeMapping = [
            'elements'             => 'element',
            'relationships'        => 'relationship',
            'views'                => 'view',
            'organizations'        => 'organization',
            'property_definitions' => 'property_definition',
        ];

        $objectType = $objectTypeMapping[$section] ?? $section;
        $schemaId   = $this->settingsService->getSchemaIdForObjectType($objectType);

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
    private function extractPropertyDefinitionMap(array $data): array
    {
        // OPTIMIZATION: Return cached property definition map if available.
        if ($this->propMapCache !== null) {
            return $this->propMapCache;
        }

        $map = [];
        // Find propertyDefinitions section (handle possible alternative names).
        $propertyDefs = null;
        if (isset($data['propertyDefinitions']) === true) {
            $propertyDefs = $data['propertyDefinitions'];
        } else if (isset($data['property_definitions']) === true) {
            $propertyDefs = $data['property_definitions'];
        } else if (isset($data['propertyDefinitions']) === true) {
            $propertyDefs = $data['propertyDefinitions'];
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
            } else if (isset($defs['_attributes']['identifier']) === true && isset($defs['name']) === true) {
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
     */
    public function getPropertyNameMapping(array $propDefMap): array
    {
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
    private function convertToCamelCase(string $propertyName): string
    {
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
     * Build statistics structure from ObjectService save result.
     * Simply converts the ObjectService result format to ArchiMate statistics format.
     *
     * @return array Statistics with created, updated, unchanged counts and summary.
     */
    private function buildStatisticsFromSaveResult(): array
    {
        // Initialize empty statistics.
        $statistics = [
            'elements'             => [
                'created'   => 0,
                'updated'   => 0,
                'unchanged' => 0,
                'errors'    => [],
            ],
            'relationships'        => [
                'created'   => 0,
                'updated'   => 0,
                'unchanged' => 0,
                'errors'    => [],
            ],
            'organizations'        => [
                'created'   => 0,
                'updated'   => 0,
                'unchanged' => 0,
                'errors'    => [],
            ],
            'views'                => [
                'created'   => 0,
                'updated'   => 0,
                'unchanged' => 0,
                'errors'    => [],
            ],
            'property_definitions' => [
                'created'   => 0,
                'updated'   => 0,
                'unchanged' => 0,
                'errors'    => [],
            ],
            'summary'              => [
                'total_objects_created'   => 0,
                'total_objects_updated'   => 0,
                'total_objects_deleted'   => 0,
                'total_objects_unchanged' => 0,
                'total_errors'            => 0,
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
                'model'               => 'elements',
                'element'             => 'elements',
                'relationship'        => 'relationships',
                'organization'        => 'organizations',
                'view'                => 'views',
                'property_definition' => 'property_definitions',
            ];

            // Build reverse map: schema ID → plural section name.
            $schemaToSection = [];
            foreach ($this->cachedConfig['schemaIds'] as $type => $schemaId) {
                $schemaToSection[(int) $schemaId] = $sectionMap[$type] ?? 'elements';
            }

            foreach ($saveResult['countsBySchema'] as $schemaId => $counts) {
                $sectionKey = $schemaToSection[(int) $schemaId] ?? 'elements';
                $statistics[$sectionKey]['created']   += $counts['saved'] ?? 0;
                $statistics[$sectionKey]['updated']   += $counts['updated'] ?? 0;
                $statistics[$sectionKey]['unchanged'] += $counts['unchanged'] ?? 0;
                if (($counts['invalid'] ?? 0) > 0) {
                    $statistics[$sectionKey]['errors'][] = "{$counts['invalid']} validation error(s)";
                }
            }
        }//end if

        // Calculate summary totals.
        foreach ($statistics as $section => $sectionStats) {
            if ($section === 'summary') {
                continue;
            }

            $statistics['summary']['total_objects_created']   += $sectionStats['created'];
            $statistics['summary']['total_objects_updated']   += $sectionStats['updated'];
            $statistics['summary']['total_objects_unchanged'] += $sectionStats['unchanged'];
            $statistics['summary']['total_errors']            += count($sectionStats['errors']);
        }

        return $statistics;
    }//end buildStatisticsFromSaveResult()

    /**
     * Calculate optimized statistics for performance reporting
     *
     * @param array $savedObjects Saved objects from ObjectService::saveObjects
     *
     * @return array Statistics array
     */
    private function calculateOptimizedStatistics(array $savedObjects): array
    {
        // Initialize statistics structure for detailed error extraction.
        $statistics = [
            'elements'             => ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'errors' => []],
            'organizations'        => ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'errors' => []],
            'relationships'        => ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'errors' => []],
            'views'                => ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'errors' => []],
            'property_definitions' => ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'errors' => []],
            'summary'              => [
                'total_objects_created'   => 0,
                'total_objects_updated'   => 0,
                'total_objects_deleted'   => 0,
                'total_objects_unchanged' => 0,
                'total_errors'            => 0,
            ],
        ];

        if ($this->lastSaveResult !== null) {
            $saveResult = $this->lastSaveResult;

            // Use per-schema counts if available (reliable — doesn't depend on serialized object fields).
            if (empty($saveResult['countsBySchema']) === false && $this->cachedConfig !== null) {
                $sectionMap = [
                    'model'               => 'elements',
                    'element'             => 'elements',
                    'relationship'        => 'relationships',
                    'organization'        => 'organizations',
                    'view'                => 'views',
                    'property_definition' => 'property_definitions',
                ];

                $schemaToSection = [];
                foreach ($this->cachedConfig['schemaIds'] as $type => $schemaId) {
                    $schemaToSection[(int) $schemaId] = $sectionMap[$type] ?? 'elements';
                }

                foreach ($saveResult['countsBySchema'] as $schemaId => $counts) {
                    $sectionKey = $schemaToSection[(int) $schemaId] ?? 'elements';
                    $statistics[$sectionKey]['created']   += $counts['saved'] ?? 0;
                    $statistics[$sectionKey]['updated']   += $counts['updated'] ?? 0;
                    $statistics[$sectionKey]['unchanged'] += $counts['unchanged'] ?? 0;
                    if (($counts['invalid'] ?? 0) > 0) {
                        $statistics[$sectionKey]['errors'][] = "{$counts['invalid']} validation error(s)";
                    }
                }
            }//end if

            // Calculate summary totals.
            $summary = [
                'total_objects_created'   => 0,
                'total_objects_updated'   => 0,
                'total_objects_deleted'   => 0,
                'total_objects_unchanged' => 0,
                'total_errors'            => 0,
            ];

            foreach ($statistics as $section => $sectionStats) {
                if ($section !== 'summary') {
                    $summary['total_objects_created']   += $sectionStats['created'];
                    $summary['total_objects_updated']   += $sectionStats['updated'];
                    $summary['total_objects_unchanged'] += $sectionStats['unchanged'];
                    $summary['total_errors']            += count($sectionStats['errors']);
                }
            }

            $statistics['summary'] = $summary;
        }//end if

        return $statistics;
    }//end calculateOptimizedStatistics()

    /**
     * Get section structure configuration for XML parsing
     *
     * @param string $sectionName The name of the section (e.g., 'elements', 'relationships', 'views', etc.)
     *
     * @return array Configuration with direct_tags and nested_paths for finding items
     */
    private function getSectionStructureConfig(string $sectionName): array
    {
        // Define the structure configuration for each section type.
        $configs = [
            'elements'             => [
                'direct_tags'  => ['element', 'elements'],
                'nested_paths' => [
                    ['model', 'elements', 'element'],
                    ['model', 'elements'],
                    ['elements', 'element'],
                    ['elements'],
                ],
            ],
            'relationships'        => [
                'direct_tags'  => ['relationship', 'relationships'],
                'nested_paths' => [
                    ['model', 'relationships', 'relationship'],
                    ['model', 'relationships'],
                    ['relationships', 'relationship'],
                    ['relationships'],
                ],
            ],
            'views'                => [
                'direct_tags'  => ['view', 'views', 'diagram', 'diagrams'],
                'nested_paths' => [
                    ['model', 'views', 'diagrams', 'view'],
                    ['model', 'views', 'diagrams'],
                    ['model', 'views'],
                    ['views', 'diagrams', 'view'],
                    ['views', 'diagrams'],
                    ['views'],
                ],
            ],
            'organizations'        => [
                'direct_tags'  => ['item', 'items'],
                'nested_paths' => [
                    ['model', 'organizations', 'item'],
                    ['model', 'organizations'],
                    ['organizations', 'item'],
                    ['organizations'],
                ],
            ],
            'property_definitions' => [
                'direct_tags'  => ['propertyDefinition', 'propertyDefinitions'],
                'nested_paths' => [
                    ['model', 'propertyDefinitions', 'propertyDefinition'],
                    ['model', 'propertyDefinitions'],
                    ['propertyDefinitions', 'propertyDefinition'],
                    ['propertyDefinitions'],
                ],
            ],
        ];

        return $configs[$sectionName] ?? [
            'direct_tags'  => [$sectionName],
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
    private function isAssociativeArray(array $array): bool
    {
        return count(array_filter(array_keys($array), 'is_string')) > 0;
    }//end isAssociativeArray()

    /**
     * Find items within a specific section using AMEF configuration
     *
     * @param array  $sectionData The section data to search
     * @param string $sectionName The name of the section
     *
     * @return array Array of items found
     */
    private function findItemsInSection(array $sectionData, string $sectionName): array
    {
        // OPTIMIZATION: Removed debug logging from section processing.
        $items = [];

        // Safety check: ensure sectionData is an array.
        if (is_array($sectionData) === false) {
            return [];
        }

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
                $pathValid   = true;

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
     * @param array  $item        Item data
     * @param string $sectionName The section name for special handling
     *
     * @return string|null Identifier or null if not found
     */
    private function extractIdentifier(array $item, string $sectionName=''): ?string
    {
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
     * @param array $item    The item to extract from
     * @param array $pattern The extraction pattern ['path' => string[], 'type' => string]
     *
     * @return string|null The extracted identifier or null
     */
    private function extractIdentifierByPattern(array $item, array $pattern): ?string
    {
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
                } else {
                    return null;
                }

            case 'value':
                if (is_array($current) === true && isset($current['_value']) === true) {
                    return (string) $current['_value'];
                } else {
                    return null;
                }

            case 'array_search':
                if (is_array($current) === true) {
                    foreach ($current as $childItem) {
                        if (isset($childItem['_attributes']['identifierRef']) === true) {
                            return (string) $childItem['_attributes']['identifierRef'];
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
    private function buildIdentifierPatternsForSection(string $sectionName): array
    {
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
     * @param array  $item           The complete XML item data
     * @param array  $elementsLookup Optional elements lookup for view processing
     * @param string $schemaType     Schema type for conditional processing
     *
     * @return array Essential XML data for storage
     */
    private function extractEssentialXmlData(array $item, array $elementsLookup=[], string $schemaType=''): array
    {
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

        // Preserve original node/connection arrays for views (needed by export's addViewDataToXmlNode).
        if (isset($item['node']) === true) {
            $essential['node'] = $item['node'];
        }

        if (isset($item['connection']) === true) {
            $essential['connection'] = $item['connection'];
        }

        // Extract flattened viewNodes/viewRelationships for frontend consumption.
        if ($schemaType === 'view') {
            $this->extractViewNodesAndConnections(item: $item, essential: $essential, elementsLookup: $elementsLookup);
        } else {
            $this->extractViewNodesAndConnections(item: $item, essential: $essential);
        }

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
     * @param array $essential      Essential XML data to enrich by reference
     * @param array $elementsLookup Optional lookup array of elements
     *
     * @return void
     */
    private function extractViewNodesAndConnections(array $item, array &$essential, array $elementsLookup=[]): void
    {
        // Only process if this looks like a view object (has nodes or connections).
        if (isset($item['node']) === false && isset($item['connection']) === false) {
            return;
        }

        // Extract viewNodes array with proper JSON structure.
        if (isset($item['node']) === true) {
            $essential['viewNodes'] = $this->extractViewNodesRecursively(
                nodeData: $item['node'],
                    elementsLookup: $elementsLookup
            );
        }

        // Extract viewRelationships array with proper JSON structure.
        if (isset($item['connection']) === true) {
            $essential['viewRelationships'] = $this->extractViewRelationshipsRecursively(
                connectionData: $item['connection']
            );
        }
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
     * @param array $nodeData       Node data (can be single node or array of nodes)
     * @param array $elementsLookup Lookup array of elements by identifier for enrichment
     *
     * @return array Array of viewNodes with standardized structure including parent references
     */
    private function extractViewNodesRecursively($nodeData, array $elementsLookup=[]): array
    {
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

            $nodeId     = $node['_attributes']['identifier'] ?? null;
            $elementRef = $node['_attributes']['elementRef'] ?? null;

            if ($nodeId === null) {
                continue;
            }

            // Create viewNode with standardized structure.
            if (isset($node['_attributes']['x']) === true) {
                $xValue = (int) $node['_attributes']['x'];
            } else {
                $xValue = 0;
            }

            if (isset($node['_attributes']['y']) === true) {
                $yValue = (int) $node['_attributes']['y'];
            } else {
                $yValue = 0;
            }

            if (isset($node['_attributes']['w']) === true) {
                $widthValue = (int) $node['_attributes']['w'];
            } else {
                $widthValue = 100;
            }

            if (isset($node['_attributes']['h']) === true) {
                $heightValue = (int) $node['_attributes']['h'];
            } else {
                $heightValue = 50;
            }

            $viewNode = [
                'modelNodeId' => $elementRef,
            // Reference to the ArchiMate element.
                'viewNodeId'  => $nodeId,
            // Unique identifier within this view.
                'x'           => $xValue,
                'y'           => $yValue,
                'width'       => $widthValue,
                'height'      => $heightValue,
                'parent'      => null,
            // Will be set to parent nodeId for nested nodes (see recursive processing below).
                'name'        => null,
                'type'        => $this->extractNodeType(node: $node),
            // Extract type directly from node XML.
                'color'       => 'rgb(255, 255, 255)',
            // Default white background.
                'borderColor' => 'rgb(0, 0, 0)',
            // Default black border.
                'font'        => [
                    'name'  => 'Segoe UI, Arial',
                    'size'  => 12,
                    'style' => 'normal',
                    'color' => 'rgb(0, 0, 0)',
                ],
                'description' => null,
                'elementRef'  => $elementRef,
            ];

            // Extract style information if present.
            if (isset($node['style']) === true) {
                $this->applyNodeStyle(viewNode: $viewNode, style: $node['style']);
            }

            // Extract label text for Label type nodes.
            if (isset($node['label']) === true) {
                if (is_array($node['label']) === true && isset($node['label']['_value']) === true) {
                    $viewNode['name'] = $node['label']['_value'];
                } else if (is_string($node['label']) === true) {
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
                if (isset($element['summary']) === true) {
                    $viewNode['description'] = $element['summary'];
                } else if (isset($element['documentation']) === true) {
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

                        $viewNode['type'] = strtolower((string) $gemmaType);
                    } else if (isset($element['xml']['_attributes']['xsi:type']) === true) {
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
                    'beschikbaarheid',
                    'integriteit',
                    'vertrouwelijkheid',
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
     * Debug helper: Log parent-child relationships in view nodes
     *
     * @param array  $viewNodes Array of view nodes with parent references
     * @param string $viewId    View identifier for logging context
     *
     * @return void
     */
    private function debugViewNodeHierarchy(array $viewNodes, string $viewId): void
    {
        $rootNodes  = array_filter($viewNodes, fn($node) => $node['parent'] === null);
        $childNodes = array_filter($viewNodes, fn($node) => $node['parent'] !== null);

        $this->logger->debug(
                "View node hierarchy for view: {$viewId}",
                [
                    'total_nodes'        => count($viewNodes),
                    'root_nodes'         => count($rootNodes),
                    'child_nodes'        => count($childNodes),
                    'parent_child_pairs' => array_map(
                fn($node) => ['child' => $node['viewNodeId'], 'parent' => $node['parent']],
                $childNodes
            ),
                ]
                );
    }//end debugViewNodeHierarchy()

    /**
     * Recursively extract nested nodes with full hierarchy and element splicing (LEGACY - for backward compatibility)
     *
     * This method processes nodes and their children recursively to capture the complete
     * nested structure as it appears in the ArchiMate XML. When a node references an element
     * via elementRef, the actual element data (minus _xml) is spliced into the node's
     * 'element' property.
     *
     * @param array $nodeData       Node data (can be single node or array of nodes)
     * @param array $elementsLookup Lookup array of elements by identifier for splicing
     *
     * @return array Array of processed nodes with nested children and spliced elements
     */
    private function extractNodesRecursively($nodeData, array $elementsLookup=[]): array
    {
        $nodes = [];

        // Handle both single node and array of nodes.
        if (isset($nodeData[0]) === false) {
            // Single node.
            $nodeData = [$nodeData];
        }

        foreach ($nodeData as $node) {
            if (isset($node['_attributes']) === true) {
                if (isset($node['_attributes']['x']) === true) {
                    $xValue = (int) $node['_attributes']['x'];
                } else {
                    $xValue = null;
                }

                if (isset($node['_attributes']['y']) === true) {
                    $yValue = (int) $node['_attributes']['y'];
                } else {
                    $yValue = null;
                }

                if (isset($node['_attributes']['w']) === true) {
                    $wValue = (int) $node['_attributes']['w'];
                } else {
                    $wValue = null;
                }

                if (isset($node['_attributes']['h']) === true) {
                    $hValue = (int) $node['_attributes']['h'];
                } else {
                    $hValue = null;
                }

                $processedNode = [
                    'identifier' => $node['_attributes']['identifier'] ?? null,
                    'elementRef' => $node['_attributes']['elementRef'] ?? null,
                    'type'       => $node['_attributes']['xsi:type'] ?? 'Element',
                    'x'          => $xValue,
                    'y'          => $yValue,
                    'w'          => $wValue,
                    'h'          => $hValue,
                ];

                // Extract style information if present.
                if (isset($node['style']) === true) {
                    $processedNode['style'] = $this->extractNodeStyle(style: $node['style']);
                }

                // Extract label text for Label type nodes.
                if (isset($node['label']) === true) {
                    if (is_array($node['label']) === true && isset($node['label']['_value']) === true) {
                        $processedNode['label'] = $node['label']['_value'];
                    } else if (is_string($node['label']) === true) {
                        $processedNode['label'] = $node['label'];
                    }
                }

                // ELEMENT SPLICING: If node references an element, splice it in.
                if (empty($processedNode['elementRef']) === false && empty($elementsLookup) === false) {
                    $elementRef = $processedNode['elementRef'];
                    if (isset($elementsLookup[$elementRef]) === true) {
                        // Splice element data (minus _xml and other metadata) into the node.
                        $element = $elementsLookup[$elementRef];
                        $processedNode['element'] = $this->prepareElementForSplicing(element: $element);
                    }
                }

                // RECURSIVE: Extract child nodes if they exist (with element splicing).
                if (isset($node['node']) === true) {
                    $processedNode['children'] = $this->extractNodesRecursively(
                        nodeData: $node['node'],
                            elementsLookup: $elementsLookup
                    );
                }

                // RECURSIVE: Extract child connections if they exist.
                if (isset($node['connection']) === true) {
                    $processedNode['connections'] = $this->extractConnectionsRecursively(
                        connectionData: $node['connection']
                    );
                }

                $nodes[] = $processedNode;
            }//end if
        }//end foreach

        return $nodes;
    }//end extractNodesRecursively()

    /**
     * Prepare element data for splicing by removing internal metadata
     *
     * @param array $element The complete element object
     *
     * @return array Element data suitable for splicing (without _xml, @self, etc.)
     */
    private function prepareElementForSplicing(array $element): array
    {
        // Start with a copy of the element.
        $splicedElement = $element;

        // Remove internal metadata fields that shouldn't be in spliced data.
        $fieldsToRemove = ['@self', 'xml', '_xml', 'section', 'model_identifier', 'extracted_at', '_propertyMapping'];

        foreach ($fieldsToRemove as $field) {
            unset($splicedElement[$field]);
        }

        return $splicedElement;
    }//end prepareElementForSplicing()

    /**
     * Extract connections recursively
     *
     * @param array $connectionData Connection data (can be single connection or array)
     *
     * @return array Array of processed connections
     */
    private function extractConnectionsRecursively($connectionData): array
    {
        $connections = [];

        // Handle both single connection and array of connections.
        if (isset($connectionData[0]) === false) {
            // Single connection.
            $connectionData = [$connectionData];
        }

        foreach ($connectionData as $connection) {
            if (isset($connection['_attributes']) === true) {
                $processedConnection = [
                    'identifier'      => $connection['_attributes']['identifier'] ?? null,
                    'relationshipRef' => $connection['_attributes']['relationshipRef'] ?? null,
                    'type'            => $connection['_attributes']['xsi:type'] ?? 'Relationship',
                    'source'          => $connection['_attributes']['source'] ?? null,
                    'target'          => $connection['_attributes']['target'] ?? null,
                ];

                // Extract style information if present.
                if (isset($connection['style']) === true) {
                    $processedConnection['style'] = $this->extractConnectionStyle(style: $connection['style']);
                }

                $connections[] = $processedConnection;
            }
        }

        return $connections;
    }//end extractConnectionsRecursively()

    /**
     * Extract all properties from an element for view node enrichment
     *
     * @param array $element Element data containing properties
     *
     * @return array Clean array of element properties
     */
    private function extractElementProperties(array $element): array
    {
        $properties = [];

        // Extract basic element properties.
        $basicProperties = ['identifier', 'name', 'summary', 'section', 'model_identifier'];
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
                if (is_scalar($value) === true
                    || (is_array($value) === true && $this->isComplexArray(array: $value) === false)
                ) {
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
    private function isComplexArray(array $array): bool
    {
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
     * @param array $style    Style data from XML
     *
     * @return void
     */
    private function applyNodeStyle(array &$viewNode, array $style): void
    {
        // Extract fillColor.
        if (isset($style['fillColor']['_attributes']) === true) {
            $fillColor = $style['fillColor']['_attributes'];
            if (isset($fillColor['r']) === true) {
                $r = (int) $fillColor['r'];
            } else {
                $r = 255;
            }

            if (isset($fillColor['g']) === true) {
                $g = (int) $fillColor['g'];
            } else {
                $g = 255;
            }

            if (isset($fillColor['b']) === true) {
                $b = (int) $fillColor['b'];
            } else {
                $b = 255;
            }

            $viewNode['color'] = "rgb($r, $g, $b)";
        }//end if

        // Extract lineColor (including alpha for border visibility).
        if (isset($style['lineColor']['_attributes']) === true) {
            $lineColor = $style['lineColor']['_attributes'];
            if (isset($lineColor['r']) === true) {
                $r = (int) $lineColor['r'];
            } else {
                $r = 0;
            }

            if (isset($lineColor['g']) === true) {
                $g = (int) $lineColor['g'];
            } else {
                $g = 0;
            }

            if (isset($lineColor['b']) === true) {
                $b = (int) $lineColor['b'];
            } else {
                $b = 0;
            }

            if (isset($lineColor['a']) === true) {
                $a = (int) $lineColor['a'];
            } else {
                $a = 100;
            }

            if ($a < 100) {
                $viewNode['borderColor'] = "rgba($r, $g, $b, ".round($a / 100, 2).")";
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
                    $font['size'] = (int) $style['font']['_attributes']['size'];
                } else {
                    $font['size'] = 12;
                }

                $font['style'] = 'normal';
            }

            if (isset($style['font']['color']['_attributes']) === true) {
                $fontColor = $style['font']['color']['_attributes'];
                if (isset($fontColor['r']) === true) {
                    $r = (int) $fontColor['r'];
                } else {
                    $r = 0;
                }

                if (isset($fontColor['g']) === true) {
                    $g = (int) $fontColor['g'];
                } else {
                    $g = 0;
                }

                if (isset($fontColor['b']) === true) {
                    $b = (int) $fontColor['b'];
                } else {
                    $b = 0;
                }

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
    private function extractViewRelationshipsRecursively($connectionData): array
    {
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

            $connectionId    = $connection['_attributes']['identifier'] ?? null;
            $relationshipRef = $connection['_attributes']['relationshipRef'] ?? null;
            $source          = $connection['_attributes']['source'] ?? null;
            $target          = $connection['_attributes']['target'] ?? null;

            if ($connectionId === null || $source === null || $target === false) {
                continue;
            }

            // Create viewRelationship with standardized structure.
            $viewRelationship = [
                'modelRelationshipId' => $relationshipRef,
            // Reference to the ArchiMate relationship.
                'sourceId'            => $source,
            // Source node viewNodeId.
                'targetId'            => $target,
            // Target node viewNodeId.
                'viewRelationshipId'  => $connectionId,
            // Unique identifier within this view.
                'type'                => $this->extractConnectionType(connection: $connection),
            // Extract type directly from connection XML.
                'bendpoints'          => [],
            // Array of bend points.
                'label'               => [],
            // Label information.
            ];

            // Extract bend points if present.
            if (isset($connection['bendpoint']) === true) {
                if (isset($connection['bendpoint'][0]) === true) {
                    $bendpoints = $connection['bendpoint'];
                } else {
                    $bendpoints = [$connection['bendpoint']];
                }

                foreach ($bendpoints as $bendpoint) {
                    if (isset($bendpoint['_attributes']) === true) {
                        if (isset($bendpoint['_attributes']['x']) === true) {
                            $xValue = (float) $bendpoint['_attributes']['x'];
                        } else {
                            $xValue = 0;
                        }

                        if (isset($bendpoint['_attributes']['y']) === true) {
                            $yValue = (float) $bendpoint['_attributes']['y'];
                        } else {
                            $yValue = 0;
                        }

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
                } else if (is_string($connection['label']) === true) {
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
    private function extractLabelMarkup(array $style): array
    {
        $markup = [
            'style' => [
                'fontSize'   => 11,
                'fontFamily' => 'Segoe UI, Arial',
                'fontColor'  => 'rgba(0, 0, 0, 1)',
                'fontStyle'  => 'normal',
                'fontWeight' => 'normal',
            ],
        ];

        // Extract font information if present.
        if (isset($style['font']) === true) {
            if (isset($style['font']['_attributes']) === true) {
                $markup['style']['fontFamily'] = $style['font']['_attributes']['name'] ?? 'Segoe UI, Arial';
                if (isset($style['font']['_attributes']['size']) === true) {
                    $markup['style']['fontSize'] = (int) $style['font']['_attributes']['size'];
                } else {
                    $markup['style']['fontSize'] = 11;
                }
            }

            if (isset($style['font']['color']['_attributes']) === true) {
                $fontColor = $style['font']['color']['_attributes'];
                if (isset($fontColor['r']) === true) {
                    $r = (int) $fontColor['r'];
                } else {
                    $r = 0;
                }

                if (isset($fontColor['g']) === true) {
                    $g = (int) $fontColor['g'];
                } else {
                    $g = 0;
                }

                if (isset($fontColor['b']) === true) {
                    $b = (int) $fontColor['b'];
                } else {
                    $b = 0;
                }

                if (isset($fontColor['a']) === true) {
                    $a = ((int) $fontColor['a'] / 100);
                } else {
                    $a = 1;
                }

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
    private function extractNodeType(array $node): ?string
    {
        // Priority 1: Check xsi:type attribute (most specific).
        if (isset($node['_attributes']['xsi:type']) === true) {
            $xsiType = $node['_attributes']['xsi:type'];

            // Handle different xsi:type formats.
            if ($xsiType === 'Label') {
                return 'label';
            } else if ($xsiType === 'Element') {
                return 'element';
            } else if (str_contains($xsiType, ':') === true) {
                // Handle namespaced types like "archimate:BusinessService".
                return strtolower(preg_replace('/^[a-z]+:/', '', $xsiType));
            } else {
                return strtolower($xsiType);
            }
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
    private function extractConnectionType(array $connection): string
    {
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
                return strtolower($type);
            } else if (str_contains($xsiType, ':') === true) {
                // Handle other namespaced types.
                return strtolower(preg_replace('/^[a-z]+:/', '', $xsiType));
            } else {
                return strtolower($xsiType);
            }
        }

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
     * Extract style information from a node (LEGACY - for backward compatibility)
     *
     * @param array $style Style data from XML
     *
     * @return array Processed style information
     */
    private function extractNodeStyle(array $style): array
    {
        $processedStyle = [];

        // Extract fillColor.
        if (isset($style['fillColor']['_attributes']) === true) {
            $fillColor = $style['fillColor']['_attributes'];
            if (isset($fillColor['r']) === true) {
                $rValue = (int) $fillColor['r'];
            } else {
                $rValue = 255;
            }

            if (isset($fillColor['g']) === true) {
                $gValue = (int) $fillColor['g'];
            } else {
                $gValue = 255;
            }

            if (isset($fillColor['b']) === true) {
                $bValue = (int) $fillColor['b'];
            } else {
                $bValue = 255;
            }

            if (isset($fillColor['a']) === true) {
                $aValue = (int) $fillColor['a'];
            } else {
                $aValue = 100;
            }

            $processedStyle['fillColor'] = [
                'r' => $rValue,
                'g' => $gValue,
                'b' => $bValue,
                'a' => $aValue,
            ];
        }//end if

        // Extract lineColor.
        if (isset($style['lineColor']['_attributes']) === true) {
            $lineColor = $style['lineColor']['_attributes'];
            if (isset($lineColor['r']) === true) {
                $rValue = (int) $lineColor['r'];
            } else {
                $rValue = 0;
            }

            if (isset($lineColor['g']) === true) {
                $gValue = (int) $lineColor['g'];
            } else {
                $gValue = 0;
            }

            if (isset($lineColor['b']) === true) {
                $bValue = (int) $lineColor['b'];
            } else {
                $bValue = 0;
            }

            if (isset($lineColor['a']) === true) {
                $aValue = (int) $lineColor['a'];
            } else {
                $aValue = 100;
            }

            $processedStyle['lineColor'] = [
                'r' => $rValue,
                'g' => $gValue,
                'b' => $bValue,
                'a' => $aValue,
            ];
        }//end if

        // Extract font information.
        if (isset($style['font']) === true) {
            $font = [];
            if (isset($style['font']['_attributes']) === true) {
                $font['name'] = $style['font']['_attributes']['name'] ?? 'Arial';
                if (isset($style['font']['_attributes']['size']) === true) {
                    $font['size'] = (int) $style['font']['_attributes']['size'];
                } else {
                    $font['size'] = 12;
                }
            }

            if (isset($style['font']['color']['_attributes']) === true) {
                $fontColor = $style['font']['color']['_attributes'];
                if (isset($fontColor['r']) === true) {
                    $rValue = (int) $fontColor['r'];
                } else {
                    $rValue = 0;
                }

                if (isset($fontColor['g']) === true) {
                    $gValue = (int) $fontColor['g'];
                } else {
                    $gValue = 0;
                }

                if (isset($fontColor['b']) === true) {
                    $bValue = (int) $fontColor['b'];
                } else {
                    $bValue = 0;
                }

                $font['color'] = [
                    'r' => $rValue,
                    'g' => $gValue,
                    'b' => $bValue,
                ];
            }//end if

            if (empty($font) === false) {
                $processedStyle['font'] = $font;
            }
        }//end if

        return $processedStyle;
    }//end extractNodeStyle()

    /**
     * Extract style information from a connection
     *
     * @param array $style Style data from XML
     *
     * @return array Processed style information
     */
    private function extractConnectionStyle(array $style): array
    {
        $processedStyle = [];

        // Extract lineColor.
        if (isset($style['lineColor']['_attributes']) === true) {
            $lineColor = $style['lineColor']['_attributes'];
            if (isset($lineColor['r']) === true) {
                $rValue = (int) $lineColor['r'];
            } else {
                $rValue = 0;
            }

            if (isset($lineColor['g']) === true) {
                $gValue = (int) $lineColor['g'];
            } else {
                $gValue = 0;
            }

            if (isset($lineColor['b']) === true) {
                $bValue = (int) $lineColor['b'];
            } else {
                $bValue = 0;
            }

            $processedStyle['lineColor'] = [
                'r' => $rValue,
                'g' => $gValue,
                'b' => $bValue,
            ];
        }//end if

        // Extract font information.
        if (isset($style['font']) === true) {
            $font = [];
            if (isset($style['font']['_attributes']) === true) {
                $font['name'] = $style['font']['_attributes']['name'] ?? 'Arial';
                if (isset($style['font']['_attributes']['size']) === true) {
                    $font['size'] = (int) $style['font']['_attributes']['size'];
                } else {
                    $font['size'] = 12;
                }
            }

            if (isset($style['font']['color']['_attributes']) === true) {
                $fontColor = $style['font']['color']['_attributes'];
                if (isset($fontColor['r']) === true) {
                    $rValue = (int) $fontColor['r'];
                } else {
                    $rValue = 0;
                }

                if (isset($fontColor['g']) === true) {
                    $gValue = (int) $fontColor['g'];
                } else {
                    $gValue = 0;
                }

                if (isset($fontColor['b']) === true) {
                    $bValue = (int) $fontColor['b'];
                } else {
                    $bValue = 0;
                }

                $font['color'] = [
                    'r' => $rValue,
                    'g' => $gValue,
                    'b' => $bValue,
                ];
            }//end if

            if (empty($font) === false) {
                $processedStyle['font'] = $font;
            }
        }//end if

        return $processedStyle;
    }//end extractConnectionStyle()

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
    private function extractGemmaType(array $object): ?string
    {
        // Try various possible property names for GEMMA type.
        $possiblePropNames = [
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

        foreach ($possiblePropNames as $propertyName) {
            if (isset($object[$propertyName]) === true && empty($object[$propertyName]) === false) {
                $rawValue = $object[$propertyName];
                // Handle case where value might be an array (e.g., from XML parsing with _value key).
                if (is_array($rawValue) === true) {
                    $value = $rawValue['_value'] ?? $rawValue[0] ?? '';
                } else {
                    $value = (string) $rawValue;
                }

                // Log the first successful match for debugging.
                if ($this->gemmaTypePropFound === false) {
                    $this->logger->debug(
                            'GEMMA Type property found',
                            [
                                'property_name' => $propertyName,
                                'value'         => $value,
                                'object_id'     => $object['identifier'] ?? 'unknown',
                            ]
                            );
                    $this->gemmaTypePropFound = true;
                }

                return $value;
            }//end if
        }//end foreach

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
                                    'original_name'   => $original,
                                    'value'           => $object[$camelCase],
                                    'object_id'       => $object['identifier'] ?? 'unknown',
                                ]
                                );
                        return (string) $object[$camelCase];
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
     * - 'aanbevolenStandaarden' array for standards with Verbindingsrol = "Aanbevolen"
     * - 'verplichteStandaarden' array for standards with Verbindingsrol = "Verplicht"
     *
     * @param array $objects All objects from the import
     *
     * @return array Objects with enhanced Referentiecomponent data
     */
    private function processGemmaReferenceComponentStandards(array $objects): array
    {
        $this->logger->info(
            'Processing GEMMA Referentiecomponent-Standaard and StandaardVersie relationships'
        );

        // OPTIMIZATION: Single-pass processing - collect all data types at once.
        $refComponenten       = [];
        $standaarden          = [];
        $standaardVersies     = [];
        $gemmaRelationshipMap = [];
        $stdVersieRelMap      = [];
        // StandaardVersie -> Standaard mappings.
        // Debug: Count objects and property variations.
        $elementCount        = 0;
        $gemmaElements       = 0;
        $gemmaTypeVariations = [];

        // PASS 1: Collect Referentiecomponenten, Standaarden, and StandaardVersies.
        foreach ($objects as $index => $object) {
            // Debug: Count elements and GEMMA types.
            if (isset($object['section']) === true && $object['section'] === 'element') {
                $elementCount++;

                // Check for various possible GEMMA type property names.
                $gemmaTypeValue = $this->extractGemmaType(object: $object);
                if ($gemmaTypeValue !== null) {
                    $gemmaElements++;

                    // Track GEMMA type variations for debugging.
                    if (isset($gemmaTypeVariations[$gemmaTypeValue]) === false) {
                        $gemmaTypeVariations[$gemmaTypeValue] = 0;
                    }

                    $gemmaTypeVariations[$gemmaTypeValue]++;

                    if ($gemmaTypeValue === 'Referentiecomponent') {
                        $refComponenten[$object['identifier']] = $index;
                    } else if ($gemmaTypeValue === 'Standaard') {
                        $standaarden[$object['identifier']] = $index;
                    } else if ($gemmaTypeValue === 'Standaardversie') {
                        $standaardVersies[$object['identifier']] = $index;
                    }
                }
            }//end if
        }//end foreach

        // PASS 2: Process relationships (need all entities collected first for StandaardVersie relationships).
        foreach ($objects as $object) {
            if (isset($object['section']) === true && $object['section'] === 'relationship') {
                // Process Referentiecomponent-Standaard relationships.
                $this->processRelationshipImmediate(
                    relationship: $object,
                    refComponenten: $refComponenten,
                    standaarden: $standaarden,
                    gemmaRelationshipMap: $gemmaRelationshipMap
                );

                // Process StandaardVersie-Standaard relationships (Specialization type).
                $this->processStandaardVersieRelationship(
                    relationship: $object,
                    standaardVersies: $standaardVersies,
                    standaarden: $standaarden,
                    stdVersieRelMap: $stdVersieRelMap
                );
            }
        }

        // Enhanced debug logging.
        $this->logger->info(
                'GEMMA objects processing complete',
                [
                    'total_elements'                => $elementCount,
                    'elements_with_gemma_type'      => $gemmaElements,
                    'gemma_type_variations'         => $gemmaTypeVariations,
                    'referentiecomponenten_count'   => count($refComponenten),
                    'standaarden_count'             => count($standaarden),
                    'standaardversies_count'        => count($standaardVersies),
                    'processed_relationships'       => count($gemmaRelationshipMap),
                    'standaardversie_relationships' => count($stdVersieRelMap),
                ]
                );

        // STEP 2: Apply the processed relationship mappings to Referentiecomponenten.
        $enhancedCount = 0;
        foreach ($gemmaRelationshipMap as $refCompId => $standaardenMap) {
            if (isset($refComponenten[$refCompId]) === true) {
                $objectIndex = $refComponenten[$refCompId];

                // Remove duplicates and add the properties.
                $aanbevolenStd = array_unique($standaardenMap['aanbevolen']);
                $verplichtStd  = array_unique($standaardenMap['verplicht']);

                $objects[$objectIndex]['aanbevolenStandaarden'] = $aanbevolenStd;
                $objects[$objectIndex]['verplichteStandaarden'] = $verplichtStd;

                // Also add combined array for backward compatibility.
                $allStandaarden = array_unique(array_merge($aanbevolenStd, $verplichtStd));
                $objects[$objectIndex]['standaarden'] = $allStandaarden;

                $this->logger->info(
                        'Enhanced Referentiecomponent with categorized standaarden',
                        [
                            'referentiecomponent_id'   => $refCompId,
                            'referentiecomponent_name' => $objects[$objectIndex]['name'] ?? 'Unknown',
                            'aanbevolen_count'         => count($aanbevolenStd),
                            'verplicht_count'          => count($verplichtStd),
                            'aanbevolen_ids'           => $aanbevolenStd,
                            'verplicht_ids'            => $verplichtStd,
                        ]
                        );

                $enhancedCount++;
            }//end if
        }//end foreach

        $this->logger->info(
                'GEMMA Referentiecomponent-Standaard processing completed',
                [
                    'referentiecomponenten_enhanced' => $enhancedCount,
                    'total_referentiecomponenten'    => count($refComponenten),
                    'total_relationships_processed'  => count($gemmaRelationshipMap),
                ]
                );

        // STEP 3: Apply StandaardVersie-Standaard relationship mappings.
        // Only store 'standaard' on StandaardVersie - use inversedBy for reverse lookup.
        $versieEnhancedCount = 0;

        foreach ($stdVersieRelMap as $versieId => $standaardId) {
            // Add standaard reference to StandaardVersie.
            if (isset($standaardVersies[$versieId]) === true) {
                $versieIndex = $standaardVersies[$versieId];
                // Convert to UUID format (remove "id-" prefix).
                $standaardUuid = str_replace('id-', '', $standaardId);
                $objects[$versieIndex]['standaard'] = $standaardUuid;
                $versieEnhancedCount++;
            }
        }

        $this->logger->info(
                'GEMMA StandaardVersie-Standaard processing completed',
                [
                    'standaardversies_enhanced'  => $versieEnhancedCount,
                    'total_standaardversies'     => count($standaardVersies),
                    'total_versie_relationships' => count($stdVersieRelMap),
                ]
                );

        // STEP 4: Add standaardVersies to ReferentieComponenten.
        // This allows querying ?gemmaType=referentiecomponent&_extend[]=gekoppeldeStandaardVersies.
        // to get all referentiecomponenten with their related standaardVersies in one call.
        // Build reverse map: Standaard ID -> [StandaardVersie UUIDs].
        $stdToVersiesMap = [];
        foreach ($stdVersieRelMap as $versieId => $standaardId) {
            $versieUuid = str_replace('id-', '', $versieId);
            if (isset($stdToVersiesMap[$standaardId]) === false) {
                $stdToVersiesMap[$standaardId] = [];
            }

            $stdToVersiesMap[$standaardId][] = $versieUuid;
        }

        // Add standaardVersies to each ReferentieComponent.
        $refCompVersCount = 0;
        foreach ($refComponenten as $refCompId => $objectIndex) {
            $stdVersiesRefComp = [];

            // Get all standaarden for this referentiecomponent (combined array).
            $refCompStandaarden = $objects[$objectIndex]['standaarden'] ?? [];

            // For each standaard, collect its standaardVersies.
            foreach ($refCompStandaarden as $standaardUuid) {
                // Convert UUID back to identifier format for lookup.
                $standaardIdentifier = 'id-'.$standaardUuid;

                if (isset($stdToVersiesMap[$standaardIdentifier]) === true) {
                    $stdVersiesRefComp = array_merge(
                        $stdVersiesRefComp,
                        $stdToVersiesMap[$standaardIdentifier]
                    );
                }
            }

            // Remove duplicates and add to referentiecomponent.
            // Use 'gekoppeldeStandaardVersies' to avoid conflict with inversedBy on 'standaardVersies'.
            if (empty($stdVersiesRefComp) === false) {
                $objects[$objectIndex]['gekoppeldeStandaardVersies'] = array_values(array_unique($stdVersiesRefComp));
                $refCompVersCount++;
            }
        }//end foreach

        $this->logger->info(
                'GEMMA ReferentieComponent-StandaardVersies processing completed',
                [
                    'referentiecomponenten_with_versies' => $refCompVersCount,
                    'total_referentiecomponenten'        => count($refComponenten),
                    'standaard_to_versies_mappings'      => count($stdToVersiesMap),
                ]
                );

        return $objects;
    }//end processGemmaReferenceComponentStandards()

    /**
     * Process StandaardVersie-Standaard relationships (Specialization type)
     *
     * @param array $relationship     The relationship object
     * @param array $standaardVersies Array of StandaardVersie identifiers
     * @param array $standaarden      Array of Standaard identifiers
     * @param array $stdVersieRelMap  Map of StandaardVersie to Standaard
     *
     * @return void
     */
    private function processStandaardVersieRelationship(
        array $relationship,
        array $standaardVersies,
        array $standaarden,
        array &$stdVersieRelMap
    ): void {
        // Get source and target from relationship.
        $source = $this->extractRelationshipEndpoint(relationship: $relationship, endpoint: 'source');
        $target = $this->extractRelationshipEndpoint(relationship: $relationship, endpoint: 'target');

        if ($source === null || $target === false) {
            return;
        }

        // Get relationship type (looking for Specialization).
        // Type can be in 'type' (from _xsi__type) or in _attributes['xsi:type'].
        $typeAttr     = $relationship['_attributes']['xsi:type'] ?? null;
        $relationType = $relationship['type'] ?? $relationship['_xsi__type'] ?? $typeAttr;
        if ($relationType !== 'Specialization') {
            return;
        }

        // Check if one end is a StandaardVersie and the other is a Standaard.
        $versieId    = null;
        $standaardId = null;

        if (isset($standaardVersies[$source]) === true && isset($standaarden[$target]) === true) {
            // StandaardVersie -> Standaard.
            $versieId    = $source;
            $standaardId = $target;
        } else if (isset($standaarden[$source]) === true && isset($standaardVersies[$target]) === true) {
            // Standaard -> StandaardVersie (reverse direction).
            $versieId    = $target;
            $standaardId = $source;
        }

        if ($versieId !== false && $standaardId === true) {
            $stdVersieRelMap[$versieId] = $standaardId;
        }
    }//end processStandaardVersieRelationship()

    /**
     * OPTIMIZATION: Process relationship immediately when found (single-pass algorithm)
     *
     * @param array $relationship         The relationship object
     * @param array $refComponenten       Array of Referentiecomponent identifiers
     * @param array $standaarden          Array of Standaard identifiers
     * @param array $gemmaRelationshipMap The relationship map to update
     *
     * @return void
     */
    private function processRelationshipImmediate(
        array $relationship,
        array $refComponenten,
        array $standaarden,
        array &$gemmaRelationshipMap
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
        $refCompId   = null;
        $standaardId = null;

        if (isset($refComponenten[$source]) === true && isset($standaarden[$target]) === true) {
            // Referentiecomponent -> Standaard.
            $refCompId   = $source;
            $standaardId = $target;
        } else if (isset($standaarden[$source]) === true && isset($refComponenten[$target]) === true) {
            // Standaard -> Referentiecomponent (reverse direction).
            $refCompId   = $target;
            $standaardId = $source;
        }

        if ($refCompId !== false && $standaardId === true) {
            // Initialize arrays if not exists.
            if (isset($gemmaRelationshipMap[$refCompId]) === false) {
                $gemmaRelationshipMap[$refCompId] = [
                    'aanbevolen' => [],
                    'verplicht'  => [],
                ];
            }

            if (is_string($verbindingsrol) === false) {
                return;
            }

            // Add to appropriate array based on Verbindingsrol.
            // Convert identifier to UUID format (remove "id-" prefix) for _extend compatibility.
            $standaardUuid = str_replace('id-', '', $standaardId);
            if (strtolower($verbindingsrol) === 'aanbevolen') {
                $gemmaRelationshipMap[$refCompId]['aanbevolen'][] = $standaardUuid;
            } else if (strtolower($verbindingsrol) === 'verplicht') {
                $gemmaRelationshipMap[$refCompId]['verplicht'][] = $standaardUuid;
            }
        }//end if
    }//end processRelationshipImmediate()

    /**
     * Extract relationship endpoint (source or target) from relationship object
     *
     * @param array  $relationship The relationship object
     * @param string $endpoint     Either 'source' or 'target'
     *
     * @return string|null The endpoint identifier or null if not found
     */
    private function extractRelationshipEndpoint(array $relationship, string $endpoint): ?string
    {
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
            } else if (is_array($endpointData) === true) {
                // Try _attributes.href or _value.
                if (isset($endpointData['_attributes']['href']) === true) {
                    return $endpointData['_attributes']['href'];
                } else if (isset($endpointData['_value']) === true) {
                    return $endpointData['_value'];
                }
            }
        }

        // Try direct XML access for ArchiMate format.
        if (isset($relationship['xml']['_attributes']) === true) {
            $attr = $relationship['xml']['_attributes'];
            if ($endpoint === 'source' && isset($attr['source']) === true) {
                return $attr['source'];
            } else if ($endpoint === 'target' && isset($attr['target']) === true) {
                return $attr['target'];
            }
        }

        return null;
    }//end extractRelationshipEndpoint()

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
     * @param array  $xmlData         Parsed XML data
     * @param string $modelIdentifier Model identifier
     *
     * @return array Array of objects ready for saveObjects()
     */
    private function transformArchiMateXmlToObjectsBatch(array $xmlData, string $modelIdentifier): array
    {
        $startTime  = microtime(true);
        $allObjects = [];

        // SPEED OPTIMIZATION 1: Pre-extract and cache EVERYTHING.
        $cacheStartTime = microtime(true);
        $propDefMap     = $this->extractPropertyDefinitionMap(data: $xmlData);

        // Create model object first.
        if (isset($xmlData['_attributes']) === true || isset($xmlData['name']) === true) {
            $modelMetadata = [
                'identifier'            => $modelIdentifier,
                'name'                  => $xmlData['name'] ?? '',
                'documentation'         => $xmlData['documentation'] ?? '',
                'properties'            => $xmlData['properties'] ?? [],
                'propertyDefinitionMap' => $propDefMap,
            ];

            if (isset($xmlData['_attributes']) === true) {
                $modelMetadata = array_merge($modelMetadata, $xmlData['_attributes']);
            }

            $allObjects[] = $this->createModelObjectDirect(metadata: $modelMetadata, modelIdentifier: $modelIdentifier);
        }

        // Process each section type directly (no intermediate normalization).
        $sections = [
            'elements'             => 'element',
            'relationships'        => 'relationship',
            'organizations'        => 'organization',
            'views'                => 'view',
            'property_definitions' => 'property_definition',
        ];

        // SPEED OPTIMIZATION 2: Pre-build ALL section lookups simultaneously.
        $allLookups     = $this->buildAllLookupsSimultaneously(xmlData: $xmlData);
        $elementsLookup = $this->buildElementsLookup(elementObjects: $allObjects);
        // Will be rebuilt from processed objects.
        $cacheTime = microtime(true) - $cacheStartTime;
        $this->logger->info(
                'Pre-built all lookups',
                [
                    'cache_build_time'    => round($cacheTime, 3),
                    'elements_count'      => count($allLookups['elements']),
                    'relationships_count' => count($allLookups['relationships']),
                    'organizations_count' => count($allLookups['organizations']),
                    'memory_usage_mb'     => round(memory_get_usage(true) / 1024 / 1024, 1),
                ]
                );

        // SPEED OPTIMIZATION 3: Process all non-view sections in bulk.
        $bulkProcessingStart = microtime(true);
        $nonViewObjects      = $this->bulkProcessNonViewSections(
            xmlData: $xmlData,
            modelIdentifier: $modelIdentifier,
            propDefMap: $propDefMap,
            allLookups: $allLookups
        );
        $allObjects          = array_merge($allObjects, $nonViewObjects);

        // SPEED OPTIMIZATION: Build elements lookup directly from raw data (faster than from processed objects).
        $elementsLookup = $this->buildElementsLookupFromRawData(
            rawElementsData: $allLookups['elements'],
            processedObjects: $nonViewObjects,
            propDefMap: $propDefMap
        );

        $bulkTime = microtime(true) - $bulkProcessingStart;

        // SPEED OPTIMIZATION 4: Process views with maximum speed optimizations.
        $viewProcessingStart = microtime(true);
        $viewObjects         = $this->processViewsMaximumSpeed(
            xmlData: $xmlData,
            modelIdentifier: $modelIdentifier,
            propDefMap: $propDefMap,
            elementsLookup: $elementsLookup
        );
        $allObjects          = array_merge($allObjects, $viewObjects);
        $viewTime            = microtime(true) - $viewProcessingStart;

        $totalTime = microtime(true) - $startTime;
        // Transformation completed.
        // MEMORY CLEANUP: Free all intermediate lookups and caches before database operations.
        $memoryBeforeCleanup = memory_get_usage(true);
        unset($allLookups, $elementsLookup, $propDefMap);
        $this->camelCaseCache = [];
        // Clear property name cache.
        $this->idPatternCache = [];
        // Clear identifier pattern cache.
        $this->propMapCache = null;
        // Clear property definition cache.
        // Force garbage collection to free memory immediately.
        if (function_exists('gc_collect_cycles') === true) {
            $cycles = gc_collect_cycles();
            $memoryAfterCleanup = memory_get_usage(true);
            $memoryFreed        = $memoryBeforeCleanup - $memoryAfterCleanup;

            $this->logger->info(
                    'Memory cleanup before database operations',
                    [
                        'memory_freed_mb'     => round($memoryFreed / 1024 / 1024, 1),
                        'gc_cycles_collected' => $cycles,
                        'memory_before_mb'    => round($memoryBeforeCleanup / 1024 / 1024, 1),
                        'memory_after_mb'     => round($memoryAfterCleanup / 1024 / 1024, 1),
                    ]
                    );
        }

        return $allObjects;
    }//end transformArchiMateXmlToObjectsBatch()

    /**
     * Transform views with performance optimizations
     *
     * This method processes views with several optimizations:
     * - Reduced memory allocations
     * - Optimized element lookup caching
     * - Streamlined recursive processing
     *
     * @param array  $viewsData       Views section data
     * @param string $modelIdentifier Model identifier
     * @param array  $propDefMap      Property definition map
     * @param array  $elementsLookup  Elements lookup for splicing
     *
     * @return array Array of processed view objects
     */
    private function transformViewsOptimized(
        array $viewsData,
        string $modelIdentifier,
        array $propDefMap,
        array $elementsLookup
    ): array {
        $objects = [];

        // Find items in section (optimized version for views).
        $items = $this->findItemsSimplified(sectionData: $viewsData, sectionType: 'view');

        // OPTIMIZATION: Pre-filter elements to only those actually referenced in views.
        $referencedElements = $this->extractReferencedElements(viewItems: $items);
        $filteredLookup     = array_intersect_key($elementsLookup, array_flip($referencedElements));

        $this->logger->debug(
                'Optimized elements lookup for views',
                [
                    'total_elements'      => count($elementsLookup),
                    'referenced_elements' => count($filteredLookup),
                    'optimization_ratio'  => round(
                        (1 - count($filteredLookup) / max(count($elementsLookup), 1)) * 100,
                            1
                    ).'%',
                ]
                );

        foreach ($items as $item) {
            if (is_array($item) === false) {
                continue;
            }

            $identifier = $this->extractIdentifier(item: $item, sectionName: 'view');
            if ($identifier === null) {
                continue;
            }

            // OPTIMIZATION: Use filtered elements lookup for better performance.
            $essentialXmlData = $this->extractEssentialXmlData(
                item: $item,
                    elementsLookup: $filteredLookup,
                    schemaType: 'view'
            );

            $object = [
                '@self'            => [
                    'register'     => $this->cachedConfig['registerId'] ?? throw new \RuntimeException("No register ID."),
                    'schema'       => $this->getSchemaIdForSection(section: 'view'),
                    'id'           => $identifier,
                    'owner'        => $this->cachedConfig['userId'],
                    'organisation' => $this->getCurrentOrganisation(),

                ],
                'identifier'       => $identifier,
                'section'          => 'view',
                'model_identifier' => $modelIdentifier,
                'xml'              => $essentialXmlData,
            ];

            // Extract name and summary (same as other sections).
            if (isset($item['name']) === true) {
                if (is_array($item['name']) === true && isset($item['name']['_value']) === true) {
                    $object['name'] = $item['name']['_value'];
                } else if (is_string($item['name']) === true) {
                    $object['name'] = $item['name'];
                }
            }

            if (isset($item['documentation']) === true) {
                if (is_array($item['documentation']) === true && isset($item['documentation']['_value']) === true) {
                    $object['summary'] = $item['documentation']['_value'];
                } else if (is_string($item['documentation']) === true) {
                    $object['summary'] = $item['documentation'];
                }
            }

            // Extract type from xsi:type attribute.
            if (isset($item['_xsi__type']) === true) {
                $object['type'] = $item['_xsi__type'];
            } else if (isset($item['_attributes']['xsi:type']) === true) {
                $object['type'] = $item['_attributes']['xsi:type'];
            }

            // Flatten properties efficiently (same as other sections).
            if (isset($item['properties']['property']) === true && empty($propDefMap) === false) {
                $this->flattenPropertiesBatch(
                    object: $object,
                    properties: $item['properties']['property'],
                    propDefMap: $propDefMap
                );

                // Keep @self.id as the full ArchiMate identifier (set above).
                // so stored IDs match GEMMA Online URLs (id-e0f57689-...).
                $object['@self']['slug'] = $identifier;
            } else {
                $object['@self']['slug'] = $identifier;
            }

            // Copy viewNodes and viewRelationships from XML to root level for easy access.
            if (isset($object['xml']['viewNodes']) === true) {
                $object['viewNodes'] = $object['xml']['viewNodes'];
            }

            if (isset($object['xml']['viewRelationships']) === true) {
                $object['viewRelationships'] = $object['xml']['viewRelationships'];
            }

            $objects[] = $object;
        }//end foreach

        return $objects;
    }//end transformViewsOptimized()

    /**
     * Extract all element references from view items for optimization
     *
     * @param array $viewItems Array of view items
     *
     * @return array Array of referenced element identifiers
     */
    private function extractReferencedElements(array $viewItems): array
    {
        $references = [];

        foreach ($viewItems as $item) {
            $this->collectElementReferencesRecursively(data: $item, references: $references);
        }

        return array_unique($references);
    }//end extractReferencedElements()

    /**
     * Recursively collect element references from view data
     *
     * @param array $data       View data to process
     * @param array $references Array to collect references into
     *
     * @return void
     */
    private function collectElementReferencesRecursively(array $data, array &$references): void
    {
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
    private function buildElementsLookup(array $elementObjects): array
    {
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
                    'total_elements'     => count($lookup),
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
     * @param array $rawElementsData  Raw elements data from XML
     * @param array $processedObjects Already processed objects (for fallback)
     * @param array $propDefMap       Property definition map
     *
     * @return array Elements lookup for view processing
     */
    private function buildElementsLookupFromRawData(
        array $rawElementsData,
        array $processedObjects,
        array $propDefMap
    ): array {
        $lookup = [];

        // SPEED: Build directly from raw data with minimal processing.
        foreach ($rawElementsData as $identifier => $rawItem) {
            $element = [
                'identifier' => $identifier,
                'section'    => 'element',
            ];

            // Fast name extraction.
            if (isset($rawItem['name']) === true) {
                if (is_array($rawItem['name']) === true && isset($rawItem['name']['_value']) === true) {
                    $element['name'] = $rawItem['name']['_value'];
                } else {
                    if (is_string($rawItem['name']) === true) {
                        $element['name'] = $rawItem['name'];
                    } else {
                        $element['name'] = '';
                    }
                }
            }

            // Fast summary extraction.
            if (isset($rawItem['documentation']) === true) {
                if (is_array($rawItem['documentation']) === true && isset($rawItem['documentation']['_value']) === true) {
                    $element['summary'] = $rawItem['documentation']['_value'];
                } else {
                    if (is_string($rawItem['documentation']) === true) {
                        $element['summary'] = $rawItem['documentation'];
                    } else {
                        $element['summary'] = '';
                    }
                }
            }

            // Extract type from xsi:type attribute.
            if (isset($rawItem['_xsi__type']) === true) {
                $element['type'] = $rawItem['_xsi__type'];
            } else if (isset($rawItem['_attributes']['xsi:type']) === true) {
                $element['type'] = $rawItem['_attributes']['xsi:type'];
            }

            // Fast properties flattening (only essential properties for splicing).
            if (isset($rawItem['properties']['property']) === true && empty($propDefMap) === false) {
                if (isset($rawItem['properties']['property'][0]) === true) {
                    $props = $rawItem['properties']['property'];
                } else {
                    $props = [$rawItem['properties']['property']];
                }

                foreach ($props as $prop) {
                    if (isset($prop['_attributes']['propertyDefinitionRef']) === false) {
                        continue;
                    }

                    $defRef = $prop['_attributes']['propertyDefinitionRef'];
                    $value  = $prop['value']['_value'] ?? $prop['value'] ?? null;

                    if ($value !== null && isset($propDefMap[$defRef]) === true) {
                        $propertyName            = $propDefMap[$defRef];
                        $camelCaseName           = $this->convertToCamelCase(propertyName: $propertyName);
                        $element[$camelCaseName] = $value;
                    }
                }
            }//end if

            $lookup[$identifier] = $element;
        }//end foreach

        $this->logger->debug(
                'Built SPEED elements lookup from raw data',
                [
                    'total_elements'     => count($lookup),
                    'sample_identifiers' => array_slice(array_keys($lookup), 0, 5),
                ]
                );

        return $lookup;
    }//end buildElementsLookupFromRawData()

    /**
     * Create model object directly with cached configuration
     *
     * @param array  $metadata        Model metadata
     * @param string $modelIdentifier Model identifier
     *
     * @return array Model object with @self structure
     */
    private function createModelObjectDirect(array $metadata, string $modelIdentifier): array
    {
        $organisation = $this->getCurrentOrganisation();

        // Extract a plain string name (schema column expects string, not array).
        $nameString = null;
        if (isset($metadata['name']) === true) {
            if (is_array($metadata['name']) === true && isset($metadata['name']['_value']) === true) {
                $nameString = (string) $metadata['name']['_value'];
            } else if (is_string($metadata['name']) === true) {
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

        $regId    = $this->cachedConfig['registerId'] ?? throw new \RuntimeException("No register ID.");
        $schemaId = $this->cachedConfig['schemaIds']['model'] ?? throw new \RuntimeException("No model schema ID.");
        $object   = [
            '@self'            => [
                'register'     => $regId,
                'schema'       => $schemaId,
                'id'           => $modelIdentifier,
                'owner'        => $this->cachedConfig['userId'],
                'organisation' => $organisation,
                'published'    => date('Y-m-d\TH:i:s\Z'),
            ],
            'identifier'       => $modelIdentifier,
            'section'          => 'model',
            'model_identifier' => $modelIdentifier,
            'xml'              => $xmlData,
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
     * @param array  $xmlData     Parsed XML data
     * @param string $sectionName Section name to find
     *
     * @return array Section data or empty array
     */
    private function findSectionData(array $xmlData, string $sectionName): array
    {
        // Direct lookup first.
        if (isset($xmlData[$sectionName]) === true) {
            return $xmlData[$sectionName];
        }

        // Alternative names lookup.
        $alternatives = [
            'views'                => ['diagrams'],
            'organizations'        => ['organisation'],
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
     * Transform section objects in batch with minimal overhead and element splicing for views
     *
     * @param array  $sectionData     Section data from XML
     * @param string $schemaType      Schema type (singular)
     * @param string $modelIdentifier Model identifier
     * @param array  $propDefMap      Property definition map
     * @param array  $elementsLookup  Optional elements lookup for view processing
     *
     * @return array Array of transformed objects
     */
    private function transformSectionObjectsBatch(
        array $sectionData,
        string $schemaType,
        string $modelIdentifier,
        array $propDefMap,
        array $elementsLookup=[]
    ): array {
        $objects = [];

        // Find items in section (simplified version).
        $items = $this->findItemsSimplified(sectionData: $sectionData, sectionType: $schemaType);

        $skippedNotArray     = 0;
        $skippedNoIdentifier = 0;

        foreach ($items as $item) {
            if (is_array($item) === false) {
                $skippedNotArray++;
                continue;
            }

            $identifier = $this->extractIdentifier(item: $item, sectionName: $schemaType);
            if ($identifier === null) {
                $skippedNoIdentifier++;
                continue;
            }

            // Create object directly (minimal processing) with element splicing for views.
            $essentialXmlData = $this->extractEssentialXmlData(
                item: $item,
                    elementsLookup: $elementsLookup,
                    schemaType: $schemaType
            );

            $regId  = $this->cachedConfig['registerId'] ?? throw new \RuntimeException("No register ID.");
            $sId    = $this->cachedConfig['schemaIds'][$schemaType] ?? throw new \RuntimeException("No schema.");
            $object = [
                '@self'            => [
                    'register'     => $regId,
                    'schema'       => $sId,
                    'id'           => $identifier,
                    'owner'        => $this->cachedConfig['userId'],
                    'organisation' => $this->getCurrentOrganisation(),
                    'published'    => date('Y-m-d\TH:i:s\Z'),
                ],
                'identifier'       => $identifier,
                'section'          => $schemaType,
                'model_identifier' => $modelIdentifier,
                'xml'              => $essentialXmlData,
            ];

            // Debug: Log XML data extraction.
            if (isset($item['properties']) === true) {
                $propsStructVal = array_keys($item['properties']);
            } else {
                $propsStructVal = null;
            }

            $this->logger->debug(
                    'XML data extracted for object',
                    [
                        'object_id'            => $identifier,
                        'section'              => $schemaType,
                        'original_item_keys'   => array_keys($item),
                        'essential_xml_keys'   => array_keys($essentialXmlData),
                        'essential_xml_size'   => strlen(json_encode($essentialXmlData)),
                        'has_properties'       => isset($item['properties']) === true,
                        'properties_structure' => $propsStructVal,
                    ]
                    );

            // Extract name from XML if it exists.
            if (isset($item['name']) === true) {
                if (is_array($item['name']) === true && isset($item['name']['_value']) === true) {
                    $object['name'] = $item['name']['_value'];
                } else if (is_string($item['name']) === true) {
                    $object['name'] = $item['name'];
                }
            }

            // Extract documentation from XML if it exists and set to summary.
            if (isset($item['documentation']) === true) {
                if (is_array($item['documentation']) === true && isset($item['documentation']['_value']) === true) {
                    $object['summary'] = $item['documentation']['_value'];
                } else if (is_string($item['documentation']) === true) {
                    $object['summary'] = $item['documentation'];
                }
            }

            // Extract type from xsi:type attribute (e.g., "Capability", "ApplicationComponent").
            if (isset($item['_xsi__type']) === true) {
                $object['type'] = $item['_xsi__type'];
            } else if (isset($item['_attributes']['xsi:type']) === true) {
                $object['type'] = $item['_attributes']['xsi:type'];
            }

            // Flatten properties efficiently (if present).
            if (isset($item['properties']['property']) === true && empty($propDefMap) === false) {
                $this->flattenPropertiesBatch(
                    object: $object,
                    properties: $item['properties']['property'],
                    propDefMap: $propDefMap
                );

                // FIXED: After properties are flattened, update ID and slug if objectId is available.
                if (isset($object['objectId']) === true) {
                    // Use objectId as main ID and AMEF identifier as slug.
                    $object['@self']['id']   = $object['objectId'];
                    $object['@self']['slug'] = $identifier;
                    // AMEF identifier becomes slug.
                } else {
                    // Fallback: extract clean UUID from AMEF identifier for slug.
                    if ($identifier !== false && str_starts_with($identifier, 'id-') === true) {
                        $object['@self']['slug'] = substr($identifier, 3);
                        // Remove "id-" prefix.
                    } else {
                        $object['@self']['slug'] = $identifier;
                    }
                }
            } else {
                // No properties to flatten, use AMEF identifier logic.
                if ($identifier !== false && str_starts_with($identifier, 'id-') === true) {
                    $object['@self']['slug'] = substr($identifier, 3);
                    // Remove "id-" prefix.
                } else {
                    $object['@self']['slug'] = $identifier;
                }
            }//end if

            // NEW: For view objects, copy viewNodes and viewRelationships from XML to root level.
            if ($schemaType === 'view' && isset($object['xml']) === true) {
                if (isset($object['xml']['viewNodes']) === true) {
                    $object['viewNodes'] = $object['xml']['viewNodes'];
                }

                if (isset($object['xml']['viewRelationships']) === true) {
                    $object['viewRelationships'] = $object['xml']['viewRelationships'];
                }
            }

            // DEBUG: Log final object structure before adding to array.
            if (isset($object['xml']) === true) {
                $xmlKeysValue = array_keys($object['xml']);
            } else {
                $xmlKeysValue = null;
            }

            if (isset($object['_propertyMapping']) === true) {
                $propMapCountVal = count($object['_propertyMapping']);
            } else {
                $propMapCountVal = 0;
            }

            if (isset($object['viewNodes']) === true) {
                $viewNodesCountValue = count($object['viewNodes']);
            } else {
                $viewNodesCountValue = 0;
            }

            if (isset($object['viewRelationships']) === true) {
                $viewRelCountVal = count($object['viewRelationships']);
            } else {
                $viewRelCountVal = 0;
            }

            $this->logger->debug(
                    'Final object structure before save',
                    [
                        'object_id'               => $identifier,
                        'section'                 => $schemaType,
                        'object_keys'             => array_keys($object),
                        'has_xml_property'        => isset($object['xml']) === true,
                        'xml_keys'                => $xmlKeysValue,
                        'has_property_mapping'    => isset($object['_propertyMapping']) === true,
                        'property_mapping_count'  => $propMapCountVal,
                        'viewNodes_count'         => $viewNodesCountValue,
                        'viewRelationships_count' => $viewRelCountVal,
                        'sample_properties'       => array_slice(
                            array_diff(
                                array_keys($object),
                                [
                                    '@self',
                                    'identifier',
                                    'section',
                                    'model_identifier',
                                    'xml',
                                    '_propertyMapping',
                                    'name',
                                    'summary',
                                    'viewNodes',
                                    'viewRelationships',
                                ]
                            ),
                            0,
                            5
                        ),
                    ]
                    );

            $objects[] = $object;
        }//end foreach

        return $objects;
    }//end transformSectionObjectsBatch()

    /**
     * Simplified item finding for better performance
     *
     * @param array  $sectionData Section data
     * @param string $sectionType Section type
     *
     * @return array Items array
     */
    private function findItemsSimplified(array $sectionData, string $sectionType): array
    {
        // Handle views with diagrams structure.
        if ($sectionType === 'view' && isset($sectionData['diagrams']['view']) === true) {
            $viewData = $sectionData['diagrams']['view'];
            if (isset($viewData[0]) === true) {
                return $viewData;
            } else {
                return [$viewData];
            }
        }

        // Try common patterns: Singular, plural, item, propertyDefinition.
        $patterns = [
            $sectionType,
            $sectionType.'s',
            'item',
            'propertyDefinition',
        ];

        foreach ($patterns as $pattern) {
            if (isset($sectionData[$pattern]) === true) {
                $data = $sectionData[$pattern];
                if (is_array($data) === true && isset($data[0]) === true) {
                    return $data;
                } else {
                    return [$data];
                }
            }
        }

        // Fallback: treat section data as single item.
        return [$sectionData];
    }//end findItemsSimplified()

    /**
     * Flatten properties in batch for better performance
     *
     * @param array $object     Object to add properties to
     * @param array $properties Properties array from XML
     * @param array $propDefMap Property definition map
     *
     * @return void
     */
    private function flattenPropertiesBatch(array &$object, array $properties, array $propDefMap): void
    {
        if (isset($properties[0]) === true) {
            $props = $properties;
        } else {
            $props = [$properties];
        }

        $processedProperties = [];

        // Debug: Log property flattening process.
        $this->logger->debug(
                'Flattening properties for object',
                [
                    'object_id'                    => $object['identifier'] ?? 'unknown',
                    'properties_count'             => count($props),
                    'property_definition_map_size' => count($propDefMap),
                    'sample_property_definitions'  => array_slice($propDefMap, 0, 5, true),
                ]
                );

        foreach ($props as $propIndex => $prop) {
            if (isset($prop['_attributes']['propertyDefinitionRef']) === false) {
                $this->logger->warning(
                        'Property missing propertyDefinitionRef',
                        [
                            'object_id'          => $object['identifier'] ?? 'unknown',
                            'property_index'     => $propIndex,
                            'property_structure' => array_keys($prop ?? []),
                        ]
                        );
                continue;
            }

            $defRef = $prop['_attributes']['propertyDefinitionRef'];
            $value  = $prop['value']['_value'] ?? $prop['value'] ?? null;

            // Debug: Log property reference lookup.
            if (isset($propDefMap[$defRef]) === false) {
                $this->logger->warning(
                        'Property definition not found in map',
                        [
                            'object_id'        => $object['identifier'] ?? 'unknown',
                            'property_def_ref' => $defRef,
                            'available_refs'   => array_keys($propDefMap),
                        ]
                        );
                continue;
            }

            if ($value !== null && isset($propDefMap[$defRef]) === true) {
                $propertyName           = $propDefMap[$defRef];
                $camelCaseName          = $this->convertToCamelCase(propertyName: $propertyName);
                $object[$camelCaseName] = $value;

                // Store property mapping for reference.
                if (isset($object['_propertyMapping']) === false) {
                    $object['_propertyMapping'] = [];
                }

                $object['_propertyMapping'][$camelCaseName] = $propertyName;

                $processedProperties[] = [
                    'original'  => $propertyName,
                    'camelCase' => $camelCaseName,
                    'value'     => $value,
                    'def_ref'   => $defRef,
                ];

                // Object ID property is now handled after property flattening is complete.
                // Debug: Log GEMMA type properties specifically.
                if (stripos($propertyName, 'gemma') !== false || $defRef === 'propid-3') {
                    $this->logger->info(
                            'GEMMA type property processed',
                            [
                                'object_id'       => $object['identifier'] ?? 'unknown',
                                'property_name'   => $propertyName,
                                'camel_case_name' => $camelCaseName,
                                'value'           => $value,
                                'def_ref'         => $defRef,
                            ]
                            );
                }
            } else {
                $this->logger->warning(
                        'Property value is null or mapping missing',
                        [
                            'object_id'        => $object['identifier'] ?? 'unknown',
                            'property_def_ref' => $defRef,
                            'value'            => $value,
                            'mapping_exists'   => isset($propDefMap[$defRef]) === true,
                        ]
                        );
            }//end if
        }//end foreach

        // Debug: Log final property flattening results.
        $this->logger->debug(
                'Property flattening completed',
                [
                    'object_id'                    => $object['identifier'] ?? 'unknown',
                    'processed_count'              => count($processedProperties),
                    'processed_properties'         => $processedProperties,
                    'object_keys_after_flattening' => array_keys($object),
                ]
                );
    }//end flattenPropertiesBatch()

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
    private function buildAllLookupsSimultaneously(array $xmlData): array
    {
        $lookups = [
            'elements'             => [],
            'relationships'        => [],
            'organizations'        => [],
            'views'                => [],
            'property_definitions' => [],
        ];

        // Pre-extract all section data simultaneously.
        $sections = [
            'elements'             => 'element',
            'relationships'        => 'relationship',
            'organizations'        => 'organization',
            'views'                => 'view',
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
     * @param array  $xmlData         XML data
     * @param string $modelIdentifier Model identifier
     * @param array  $propDefMap      Property definition map
     * @param array  $allLookups      All pre-built lookups
     *
     * @return array Processed objects
     */
    private function bulkProcessNonViewSections(
        array $xmlData,
        string $modelIdentifier,
        array $propDefMap,
        array $allLookups
    ): array {
        $objects = [];

        $sections = [
            'elements'             => 'element',
            'relationships'        => 'relationship',
            'organizations'        => 'organization',
            'property_definitions' => 'property_definition',
        ];

        foreach ($sections as $sectionName => $schemaType) {
            // Organizations are hierarchical folder trees — store as one tree object.
            if ($sectionName === 'organizations') {
                $orgData = $this->findSectionData(xmlData: $xmlData, sectionName: 'organizations');
                if (empty($orgData) === false) {
                    $syntheticId = 'org-'.preg_replace('/^id-/', '', $modelIdentifier);
                    $regId       = $this->cachedConfig['registerId'] ?? throw new \RuntimeException("No register ID.");
                    $orgSchemas  = $this->cachedConfig['schemaIds'];
                    $schemaId    = $orgSchemas['organization'] ?? throw new \RuntimeException("No org schema.");
                    $objects[]   = [
                        '@self'            => [
                            'register'     => $regId,
                            'schema'       => $schemaId,
                            'id'           => $syntheticId,
                            'owner'        => $this->cachedConfig['userId'],
                            'organisation' => $this->getCurrentOrganisation(),
                            'published'    => date('Y-m-d\TH:i:s\Z'),
                        ],
                        'identifier'       => $syntheticId,
                        'section'          => 'organization',
                        'model_identifier' => $modelIdentifier,
                        'name'             => 'Organizations',
                        'xml'              => $orgData,
                    ];
                }//end if

                continue;
            }//end if

            if (empty($allLookups[$sectionName]) === true) {
                continue;
            }

            $this->logger->debug(
                    "SPEED: Bulk processing {$sectionName}",
                    [
                        'item_count' => count($allLookups[$sectionName]),
                    ]
                    );

            // SPEED OPTIMIZATION: Process all items in this section as a batch.
            $sectionObjects = $this->bulkTransformSection(
                sectionItems: $allLookups[$sectionName],
                schemaType: $schemaType,
                modelIdentifier: $modelIdentifier,
                propDefMap: $propDefMap
            );

            $objects = array_merge($objects, $sectionObjects);
        }//end foreach

        return $objects;
    }//end bulkProcessNonViewSections()

    /**
     * SPEED OPTIMIZATION: Bulk transform a section with vectorized operations
     *
     * @param array  $sectionItems    Pre-loaded section items by identifier
     * @param string $schemaType      Schema type
     * @param string $modelIdentifier Model identifier
     * @param array  $propDefMap      Property definition map
     *
     * @return array Transformed objects
     */
    private function bulkTransformSection(
        array $sectionItems,
        string $schemaType,
        string $modelIdentifier,
        array $propDefMap
    ): array {
        $objects = [];

        foreach ($sectionItems as $identifier => $item) {
            // SPEED OPTIMIZATION: Direct object creation without intermediate steps.
            $essentialXmlData = $this->extractEssentialXmlData(item: $item, elementsLookup: [], schemaType: $schemaType);

            $regId  = $this->cachedConfig['registerId'] ?? throw new \RuntimeException("No register ID.");
            $sId    = $this->cachedConfig['schemaIds'][$schemaType] ?? throw new \RuntimeException("No schema.");
            $object = [
                '@self'            => [
                    'register'     => $regId,
                    'schema'       => $sId,
                    'id'           => $identifier,
                    'owner'        => $this->cachedConfig['userId'],
                    'organisation' => $this->getCurrentOrganisation(),
                    'published'    => date('Y-m-d\TH:i:s\Z'),
                ],
                'identifier'       => $identifier,
                'section'          => $schemaType,
                'model_identifier' => $modelIdentifier,
                'xml'              => $essentialXmlData,
            ];

            // Fast extract name and summary.
            if (isset($item['name']) === true) {
                if (is_array($item['name']) === true && isset($item['name']['_value']) === true) {
                    $object['name'] = $item['name']['_value'];
                } else {
                    if (is_string($item['name']) === true) {
                        $object['name'] = $item['name'];
                    } else {
                        $object['name'] = '';
                    }
                }
            }

            if (isset($item['documentation']) === true) {
                if (is_array($item['documentation']) === true && isset($item['documentation']['_value']) === true) {
                    $object['summary'] = $item['documentation']['_value'];
                } else {
                    if (is_string($item['documentation']) === true) {
                        $object['summary'] = $item['documentation'];
                    } else {
                        $object['summary'] = '';
                    }
                }
            }

            // Extract type from xsi:type attribute (e.g., "Capability", "ApplicationComponent").
            if (isset($item['_xsi__type']) === true) {
                $object['type'] = $item['_xsi__type'];
            } else if (isset($item['_attributes']['xsi:type']) === true) {
                $object['type'] = $item['_attributes']['xsi:type'];
            }

            // For relationships, extract source and target from attributes.
            if ($schemaType === 'relationship') {
                if (isset($item['_source']) === true) {
                    $object['source'] = $item['_source'];
                } else if (isset($item['_attributes']['source']) === true) {
                    $object['source'] = $item['_attributes']['source'];
                }

                if (isset($item['_target']) === true) {
                    $object['target'] = $item['_target'];
                } else if (isset($item['_attributes']['target']) === true) {
                    $object['target'] = $item['_attributes']['target'];
                }
            }

            // Fast flatten properties.
            if (isset($item['properties']['property']) === true && empty($propDefMap) === false) {
                $this->flattenPropertiesBatch(
                    object: $object,
                    properties: $item['properties']['property'],
                    propDefMap: $propDefMap
                );

                // Fast ID/slug update.
                if (isset($object['objectId']) === true) {
                    $object['@self']['id']   = $object['objectId'];
                    $object['@self']['slug'] = $identifier;
                } else {
                    if (str_starts_with($identifier, 'id-') === true) {
                        $object['@self']['slug'] = substr($identifier, 3);
                    } else {
                        $object['@self']['slug'] = $identifier;
                    }
                }
            } else {
                if (str_starts_with($identifier, 'id-') === true) {
                    $object['@self']['slug'] = substr($identifier, 3);
                } else {
                    $object['@self']['slug'] = $identifier;
                }
            }//end if

            $objects[] = $object;
        }//end foreach

        return $objects;
    }//end bulkTransformSection()

    /**
     * SPEED OPTIMIZATION: Process views with maximum speed optimizations
     *
     * @param array  $xmlData         XML data
     * @param string $modelIdentifier Model identifier
     * @param array  $propDefMap      Property definition map
     * @param array  $elementsLookup  Elements lookup for splicing
     *
     * @return array Processed view objects
     */
    private function processViewsMaximumSpeed(
        array $xmlData,
        string $modelIdentifier,
        array $propDefMap,
        array $elementsLookup
    ): array {
        $viewsData = $this->findSectionData(xmlData: $xmlData, sectionName: 'views');
        if (empty($viewsData) === true) {
            return [];
        }

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
                    'total_elements'         => count($elementsLookup),
                    'referenced_elements'    => count($filteredLookup),
                    'memory_savings_percent' => round(
                        (1 - count($filteredLookup) / max(count($elementsLookup), 1)) * 100,
                            1
                    ),
                ]
                );

        // SPEED OPTIMIZATION: Process with bulk operations.
        return $this->bulkTransformViews(
            viewItems: $items,
            modelIdentifier: $modelIdentifier,
            propDefMap: $propDefMap,
            elementsLookup: $filteredLookup
        );
    }//end processViewsMaximumSpeed()

    /**
     * SPEED OPTIMIZATION: Bulk transform views with vectorized element splicing
     *
     * @param array  $viewItems       View items to process
     * @param string $modelIdentifier Model identifier
     * @param array  $propDefMap      Property definition map
     * @param array  $elementsLookup  Filtered elements lookup
     *
     * @return array Processed view objects
     */
    private function bulkTransformViews(
        array $viewItems,
        string $modelIdentifier,
        array $propDefMap,
        array $elementsLookup
    ): array {
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
                    elementsLookup: $elementsLookup,
                    schemaType: 'view'
            );

            $object = [
                '@self'            => [
                    'register'     => $this->cachedConfig['registerId'] ?? throw new \RuntimeException("No register ID."),
                    'schema'       => $this->getSchemaIdForSection(section: 'view'),
                    'id'           => $identifier,
                    'owner'        => $this->cachedConfig['userId'],
                    'organisation' => $this->getCurrentOrganisation(),
                    'published'    => date('Y-m-d\TH:i:s\Z'),
                ],
                'identifier'       => $identifier,
                'section'          => 'view',
                'model_identifier' => $modelIdentifier,
                'xml'              => $essentialXmlData,
            ];

            // Fast name/summary extraction.
            if (isset($item['name']) === true) {
                if (is_array($item['name']) === true && isset($item['name']['_value']) === true) {
                    $object['name'] = $item['name']['_value'];
                } else {
                    if (is_string($item['name']) === true) {
                        $object['name'] = $item['name'];
                    } else {
                        $object['name'] = '';
                    }
                }
            }

            if (isset($item['documentation']) === true) {
                if (is_array($item['documentation']) === true && isset($item['documentation']['_value']) === true) {
                    $object['summary'] = $item['documentation']['_value'];
                } else {
                    if (is_string($item['documentation']) === true) {
                        $object['summary'] = $item['documentation'];
                    } else {
                        $object['summary'] = '';
                    }
                }
            }

            // Extract type from xsi:type attribute.
            if (isset($item['_xsi__type']) === true) {
                $object['type'] = $item['_xsi__type'];
            } else if (isset($item['_attributes']['xsi:type']) === true) {
                $object['type'] = $item['_attributes']['xsi:type'];
            }

            // Fast properties flattening.
            if (isset($item['properties']['property']) === true && empty($propDefMap) === false) {
                $this->flattenPropertiesBatch(
                    object: $object,
                    properties: $item['properties']['property'],
                    propDefMap: $propDefMap
                );

                // Keep @self.id as the full ArchiMate identifier (set above).
                // so stored IDs match GEMMA Online URLs (id-e0f57689-...).
                $object['@self']['slug'] = $identifier;
            } else {
                $object['@self']['slug'] = $identifier;
            }

            // SPEED OPTIMIZATION: Direct copy without checks (we know it exists).
            if (isset($object['xml']['viewNodes']) === true) {
                $object['viewNodes'] = $object['xml']['viewNodes'];
            }

            if (isset($object['xml']['viewRelationships']) === true) {
                $object['viewRelationships'] = $object['xml']['viewRelationships'];
            }

            $objects[] = $object;
        }//end foreach

        return $objects;
    }//end bulkTransformViews()

    /**
     * Create intelligent batches based on object size to prevent MySQL packet size issues
     *
     * This method analyzes object sizes and creates batches that stay under the MySQL
     * max_allowed_packet limit while maintaining reasonable performance.
     *
     * TODO: Move this intelligent batch sizing to OpenRegister core as a native feature
     * This functionality should be available for all bulk operations, not just ArchiMate imports.
     * OpenRegister's saveObjects() method should handle this automatically based on object sizes.
     *
     * @param array $objects Array of objects to batch
     *
     * @return array Array of batches, each containing objects that fit within size limits
     */
    private function createIntelligentBatches(array $objects): array
    {
        $maxBatchSizeBytes = self::PERFORMANCE_OPTIMIZATIONS['max_batch_size_bytes'];
        $minBatchSize      = self::PERFORMANCE_OPTIMIZATIONS['min_batch_size'];
        $sampleSize        = self::PERFORMANCE_OPTIMIZATIONS['size_estimation_sample'];

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
                    'total_objects'                   => count($objects),
                    'estimated_avg_object_size_bytes' => $avgObjectSize,
                    'max_batch_size_bytes'            => $maxBatchSizeBytes,
                    'calculated_optimal_batch_size'   => $optimalBatchSize,
                    'min_batch_size_enforced'         => $minBatchSize,
                ]
                );

        // Create batches with size awareness.
        $batches          = [];
        $currentBatch     = [];
        $currentBatchSize = 0;

        foreach ($objects as $object) {
            $objectSize = $this->estimateObjectSize(object: $object);

            // Check if adding this object would exceed the batch size limit.
            if (empty($currentBatch) === false && ($currentBatchSize + $objectSize) > $maxBatchSizeBytes) {
                // Current batch is full, save it and start a new one.
                $batches[]        = $currentBatch;
                $currentBatch     = [$object];
                $currentBatchSize = $objectSize;
            } else {
                // Add object to current batch.
                $currentBatch[]    = $object;
                $currentBatchSize += $objectSize;
            }

            // Safety check: if a single object is larger than max batch size,.
            // create a batch with just that object.
            if (count($currentBatch) === 1 && $objectSize > $maxBatchSizeBytes) {
                $this->logger->warning(
                        'Very large object detected, creating single-object batch',
                        [
                            'object_id'            => $object['@self']['id'] ?? 'unknown',
                            'object_size_bytes'    => $objectSize,
                            'max_batch_size_bytes' => $maxBatchSizeBytes,
                        ]
                        );
                $batches[]        = $currentBatch;
                $currentBatch     = [];
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
                    'total_objects'               => count($objects),
                    'total_batches_created'       => count($batches),
                    'batch_sizes'                 => array_map('count', $batches),
                    'estimated_batch_sizes_bytes' => array_map(
                        fn($batch) => array_sum(array_map([$this, 'estimateObjectSize'], $batch)),
                        $batches
                    ),
                ]
                );

        return $batches;
    }//end createIntelligentBatches()

    /**
     * Estimate the average size of objects by sampling
     *
     * @param array $objects    Array of objects to sample
     * @param int   $sampleSize Number of objects to sample for size estimation
     *
     * @return int Estimated average object size in bytes
     */
    private function estimateAverageObjectSize(array $objects, int $sampleSize): int
    {
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
                    'total_objects'                => $totalObjects,
                    'sampled_objects'              => count($sampleIndices),
                    'total_sample_size_bytes'      => $totalSampleSize,
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
    private function estimateObjectSize(array $object): int
    {
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
     * @param array $savedObjects   Objects that were saved to database
     *
     * @return array Comprehensive statistics
     */
    private function calculateObjectStatistics(array $normalizedData, array $savedObjects): array
    {
        // Initialize statistics structure.
        $statistics = [
            'elements'             => ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'errors' => []],
            'organizations'        => ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'errors' => []],
            'relationships'        => ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'errors' => []],
            'views'                => ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'errors' => []],
            'property_definitions' => ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'errors' => []],
        ];

        // If we have access to the actual save results from ObjectService, use those.
        if ($this->lastSaveResult !== null) {
            $saveResult = $this->lastSaveResult;

            // DEBUG: Log what we got from ObjectService.
            $this->logger->info(
                    '[ArchiMate] Using lastSaveResult for statistics',
                    [
                        'saved_count'     => count($saveResult['saved'] ?? []),
                        'updated_count'   => count($saveResult['updated'] ?? []),
                        'unchanged_count' => count($saveResult['unchanged'] ?? []),
                        'invalid_count'   => count($saveResult['invalid'] ?? []),
                        'keys_present'    => array_keys($saveResult),
                    ]
                    );

            // Count objects by section type from the actual processed objects.
            $allProcessedObjects = array_merge(
                $saveResult['saved'] ?? [],
                $saveResult['updated'] ?? [],
                $saveResult['unchanged'] ?? [],
                // For invalid objects, extract the original object from the error structure.
                array_map(fn($item) => $item['object'] ?? [], $saveResult['invalid'] ?? [])
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
                            'element'             => 'elements',
                            'relationship'        => 'relationships',
                            'organization'        => 'organizations',
                            'view'                => 'views',
                            'property_definition' => 'property_definitions',
                            'model'               => 'elements',
                        ];
                        foreach ($this->cachedConfig['schemaIds'] as $type => $schemaId) {
                            if ((int) $schemaId === (int) $objSchemaId) {
                                $sectionKey = $singularToPlural[$type] ?? 'elements';
                                break;
                            }
                        }
                    }

                    $sectionKey = $sectionKey ?? 'elements';
                } else if ($sectionKey === null) {
                    $sectionKey = 'elements';
                }//end if

                if (isset($statistics[$sectionKey]) === false) {
                    continue;
                    // Skip unknown section types.
                }

                // Determine if this object was created, updated, or had errors.
                $objectId = $object['@self']['id'] ?? $object['identifier'] ?? null;

                // Check if this object is in the saved (created) list.
                $wasCreated = empty(
                        array_filter(
                        $saveResult['saved'] ?? [],
                    fn($saved) => ($saved->getUuid() === $objectId)
                        )
                        ) === false;

                // Check if this object is in the updated list.
                $wasUpdated = empty(
                        array_filter(
                        $saveResult['updated'] ?? [],
                    fn($updated) => ($updated->getUuid() === $objectId)
                        )
                        ) === false;

                // Check if this object was unchanged (no changes).
                $unchangedObjects = $saveResult['unchanged'] ?? [];
                $wasSkipped       = empty(
                        array_filter(
                        $unchangedObjects,
                    fn($unchanged) => ($unchanged->getUuid() === $objectId)
                        )
                        ) === false;

                // Check if this object had validation errors.
                $hasErrors = empty(
                        array_filter(
                        $saveResult['invalid'] ?? [],
                    fn($invalid) => (($invalid['object']['@self']['id'] ?? null) === $objectId)
                        )
                        ) === false;

                if ($wasCreated === true) {
                    $statistics[$sectionKey]['created']++;
                } else if ($wasUpdated === true) {
                    $statistics[$sectionKey]['updated']++;
                } else if ($wasSkipped === true) {
                    $statistics[$sectionKey]['unchanged']++;
                } else if ($hasErrors === true) {
                    // Add to errors array for this section.
                    $errorInfo = array_filter(
                            $saveResult['invalid'] ?? [],
                        fn($invalid) => (($invalid['object']['@self']['id'] ?? null) === $objectId)
                            );

                    if (empty($errorInfo) === false) {
                        $statistics[$sectionKey]['errors'][]
                            = array_values($errorInfo)[0]['error'] ?? 'Unknown validation error';
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
            'total_objects_created'   => 0,
            'total_objects_updated'   => 0,
            'total_objects_deleted'   => 0,
            'total_objects_unchanged' => 0,
            'total_errors'            => 0,
        ];

        foreach ($statistics as $section => $sectionStats) {
            if ($section !== 'summary') {
                // Skip summary section itself.
                $summary['total_objects_created']   += $sectionStats['created'];
                $summary['total_objects_updated']   += $sectionStats['updated'];
                $summary['total_objects_unchanged'] += $sectionStats['unchanged'];
                $summary['total_errors']            += count($sectionStats['errors']);
            }
        }

        $statistics['summary'] = $summary;

        // DEBUG: Log the summary statistics to help diagnose the issue.
        $this->logger->info(
                '[ArchiMate] Statistics summary calculated',
                [
                    'summary'        => $summary,
                    'section_counts' => array_map(
                    fn($s) => [
                        'created'   => $s['created'] ?? 0,
                        'updated'   => $s['updated'] ?? 0,
                        'unchanged' => $s['unchanged'] ?? 0,
                        'errors'    => count($s['errors'] ?? []),
                    ],
                    array_filter($statistics, fn($k) => $k !== 'summary', ARRAY_FILTER_USE_KEY)
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
    private function extractDetailedErrors(array $statistics): array
    {
        $detailedErrors = [
            'total_count' => 0,
            'by_section'  => [],
            'summary'     => [],
        ];

        $sections = ['elements', 'relationships', 'organizations', 'views', 'property_definitions'];

        foreach ($sections as $section) {
            if (isset($statistics[$section]['errors']) === true && empty($statistics[$section]['errors']) === false) {
                $sectionErrors     = $statistics[$section]['errors'];
                $sectionErrorCount = count($sectionErrors);

                $detailedErrors['total_count'] += $sectionErrorCount;

                // Group errors by type/message for better presentation.
                $errorGroups = [];
                foreach ($sectionErrors as $error) {
                    if (is_string($error) === true) {
                        $errorMessage = $error;
                    } else {
                        $errorMessage = ($error['message'] ?? 'Unknown error');
                    }

                    $errorType = $this->categorizeError(errorMessage: $errorMessage);

                    if (isset($errorGroups[$errorType]) === false) {
                        $errorGroups[$errorType] = [
                            'type'     => $errorType,
                            'message'  => $errorMessage,
                            'count'    => 0,
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
                        'type'              => $errorType,
                        'message'           => $errorGroup['message'],
                        'total_count'       => 0,
                        'affected_sections' => [],
                    ];
                }

                $allErrors[$errorType]['total_count']        += $errorGroup['count'];
                $allErrors[$errorType]['affected_sections'][] = $sectionData['section_name'];
            }
        }

        // Sort by frequency and take top 10.
        uasort($allErrors, fn($a, $b) => $b['total_count'] - $a['total_count']);
        $detailedErrors['summary'] = array_slice(array_values($allErrors), 0, 10);

        return $detailedErrors;
    }//end extractDetailedErrors()

    /**
     * Categorize error types for better grouping and presentation
     *
     * @param string $errorMessage The error message to categorize
     *
     * @return string Error category/type
     */
    private function categorizeError(string $errorMessage): string
    {
        $errorMessage = strtolower($errorMessage);

        // Define error patterns and their categories.
        $errorPatterns = [
            'validation'   => ['validation', 'invalid', 'required', 'missing', 'empty'],
            'schema'       => ['schema', 'structure', 'format', 'type'],
            'reference'    => ['reference', 'identifier', 'not found', 'missing reference'],
            'property'     => ['property', 'attribute', 'field'],
            'constraint'   => ['constraint', 'unique', 'duplicate', 'already exists'],
            'relationship' => ['relationship', 'source', 'target', 'connection'],
            'data_type'    => ['string', 'integer', 'boolean', 'array', 'object'],
            'encoding'     => ['encoding', 'character', 'utf', 'ascii'],
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
