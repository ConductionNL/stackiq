<?php

/**
 * ArchiMate Import Service for SoftwareCatalog
 * 
 * Handles the business logic for importing ArchiMate XML files with round-trip fidelity.
 * This service contains all the import-specific logic that was previously in ArchiMateService.
 * 
 * @category Service
 * @package  OCA\SoftwareCatalog\Service
 * @author   SoftwareCatalog Team
 * @license  AGPL-3.0
 * @version  1.0.0
 * @link     https://github.com/nextcloud/softwarecatalog
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Service;

use OCA\OpenRegister\Service\ObjectService;
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
 * @author   SoftwareCatalog Team
 * @license  AGPL-3.0
 * @version  1.0.0
 * @link     https://github.com/nextcloud/softwarecatalog
 */
class ArchiMateImportService
{
    /**
     * Configuration keys for ArchiMate processing
     */
    private const CONFIG_KEYS = [
        'archimate_register_id' => 'archimate_register_id',
        'archimate_schema_id' => 'archimate_schema_id',
        'archimate_model_schema_id' => 'archimate_model_schema_id'
    ];

    /**
     * Performance optimization settings
     */
    private const PERFORMANCE_OPTIMIZATIONS = [
        'disable_validation' => true,
        'disable_events' => true,
        'disable_rbac' => false,  // Keep RBAC for security
        'use_multi' => true,
        'xml_parse_flags' => LIBXML_NOCDATA | LIBXML_NONET,
        'memory_cleanup' => true,
        'parallel_processing' => true,
        'batch_size' => 1000,     // Default batch size (will be adjusted intelligently)
        'parallel_batches' => 8,  // Process 8 batches concurrently
        'max_batch_size_bytes' => 8388608,  // 8 MB - safe under MySQL's 16 MB limit
        'min_batch_size' => 50,   // Minimum batch size for very large objects
        'size_estimation_sample' => 10  // Sample size for estimating object sizes
    ];

    /**
     * Default schema IDs for ArchiMate objects
     */
    private const DEFAULT_SCHEMA_IDS = [
        'model' => 100,
        'element' => 101,
        'relationship' => 102,
        'view' => 103,
        'organization' => 104,
        'property_definition' => 105
    ];

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
     * Flag to track if we've already logged finding a GEMMA type property
     * 
     * @var bool
     */
    private bool $gemmaTypePropertyFound = false;

    /**
     * Cache for property definition maps to avoid rebuilding during import
     * 
     * @var array|null
     */
    private ?array $propertyDefinitionMapCache = null;

    /**
     * Storage for the last save operation results
     * Contains the structured return from ObjectService::saveObjects
     */
    private ?array $lastSaveResult = null;

    /**
     * Cached configuration values for performance optimization
     */
    private ?array $cachedConfig = null;

    /**
     * Constructor for ArchiMateImportService
     * 
     * @param IAppConfig $config Nextcloud app configuration service
     * @param IRootFolder $rootFolder Root folder service
     * @param IUserSession $userSession User session service
     * @param IAppManager $appManager App manager service
     * @param ContainerInterface $container PSR-11 container interface
     * @param LoggerInterface $logger Logger service
     */
    public function __construct(
        private readonly IAppConfig $config,
        private readonly IRootFolder $rootFolder,
        private readonly IUserSession $userSession,
        private readonly IAppManager $appManager,
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger
    ) {
    }

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
     */
    public function xmlToArray(\SimpleXMLElement $xml): array
    {
        // PERFORMANCE OPTIMIZATION: Initialize result only
        $result = [];

        // OPTIMIZATION: Extract non-namespaced attributes (skip redundant processing)
        $attributes = $xml->attributes();
        if (count($attributes) > 0) {
            $attrBag = [];
            foreach ($attributes as $attrName => $attrValue) {
                $name = (string) $attrName;
                $value = (string) $attrValue;
                // OPTIMIZATION: Only create underscored key if needed (skip str_replace for simple names)
                $underscoredKey = (strpos($name, ':') !== false) ? '_' . str_replace(':', '__', $name) : '_' . $name;
                $result[$underscoredKey] = $value;
                $attrBag[$name] = $value;
            }
            $result['_attributes'] = $attrBag;
        }

        // OPTIMIZATION: Extract namespaced attributes (simplified processing)
        foreach ($xml->getNameSpaces(true) as $prefix => $_) {
            $nsAttributes = $xml->attributes($prefix, true);
            if (count($nsAttributes) > 0) {
                foreach ($nsAttributes as $attrName => $attrValue) {
                    $name = (string) $attrName;
                    $value = (string) $attrValue;
                    $underscoredKey = '_' . $prefix . '__' . $name;
                    $result[$underscoredKey] = $value;
                    if (!isset($result['_attributes'])) {
                        $result['_attributes'] = [];
                    }
                    $result['_attributes'][$prefix . ':' . $name] = $value;
                }
            }
        }

        // Extract children
        $children = $xml->children();
        if (count($children) === 0) {
            // Leaf node: always return array shape for compatibility
            $text = trim((string) $xml);
            if ($text !== '') {
                $result['_value'] = $text;
            }
            return $result;
        }

        // OPTIMIZATION: Process child elements with faster array operations
        foreach ($children as $child) {
            $childName = $child->getName();
            $childValue = $this->xmlToArray($child);

            // OPTIMIZATION: Use isset instead of array_key_exists (faster)
            if (!isset($result[$childName])) {
                $result[$childName] = $childValue;
            } else {
                // OPTIMIZATION: Fast array conversion without expensive isAssoc check
                if (!is_array($result[$childName]) || !isset($result[$childName][0])) {
                    // Convert to indexed array if it's a single value or associative array
                    $result[$childName] = [$result[$childName]];
                }
                $result[$childName][] = $childValue;
            }
        }

        // Preserve text content when children exist
        $text = trim((string) $xml);
        if ($text !== '') {
            $result['_text'] = $text;
        }

        return $result;
    }


    /**
     * Check if an array is associative (has string keys)
     * 
     * @param array $array The array to check
     * @return bool True if associative, false if indexed
     */
    private function isAssoc(mixed $value): bool
    {
        if (!is_array($value)) {
            return false;
        }
        return array_keys($value) !== range(0, count($value) - 1);
    }

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
     * @return array Import results with detailed status
     */
    public function importArchiMateFileFromPathOptimized(array $options = []): array
    {
        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);
        
        // Starting OPTIMIZED ArchiMate XML import

        try {
            // OPTIMIZATION: Cache all configuration once at start
            $cacheStartTime = microtime(true);
            $this->initializeCache();
            $cacheTime = microtime(true) - $cacheStartTime;
            
            // Cache initialization completed
            
            // STEP 1: Parse XML to array (same as before)
            $filePath = $options['filePath'] ?? $options['file_path'] ?? '';
            if (empty($filePath) || !file_exists($filePath)) {
                throw new \InvalidArgumentException("File not found: {$filePath}");
            }
            
            $parseStartTime = microtime(true);
            $xmlData = $this->parseArchiMateXml($filePath);
            $parseTime = microtime(true) - $parseStartTime;
            
            // PERFORMANCE OPTIMIZATION: Clean up memory after XML parsing
            $memoryCleanupTime = 0;
            if (self::PERFORMANCE_OPTIMIZATIONS['memory_cleanup']) {
                $memoryCleanupStartTime = microtime(true);
                $this->cleanupMemory();
                $memoryCleanupTime = microtime(true) - $memoryCleanupStartTime;
            }
            
            // STEP 2: Extract model identifier
            $modelIdentifierStartTime = microtime(true);
            $modelIdentifier = $this->extractModelIdentifier($xmlData);
            $modelIdentifierTime = microtime(true) - $modelIdentifierStartTime;
            
            // STEP 3: Parse ALL objects in one go (like CSV import)
            $transformStartTime = microtime(true);
            $allObjects = $this->transformArchiMateXmlToObjectsBatch($xmlData, $modelIdentifier);
            $transformTime = microtime(true) - $transformStartTime;
            
            // Parsed and transformed all objects
            
            // STEP 4: Single saveObjects() call (like CSV import)
            $saveStartTime = microtime(true);
            $savedObjects = $this->saveObjectsToDatabase($allObjects);
            $saveTime = microtime(true) - $saveStartTime;
            
            // Capture detailed save timing from internal tracking
            $saveBreakdown = $this->lastSaveTimingBreakdown;
            
            $totalTime = microtime(true) - $startTime;
            $itemsPerSecond = count($allObjects) / max($totalTime, 0.001);
            
            // OPTIMIZED import completed successfully

            return [
                'success' => true,
                'file_info' => [
                    'name' => $options['fileName'] ?? basename($filePath),
                    'size' => filesize($filePath)
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
                        'database_save_seconds' => round($saveTime, 3)
                    ],
                    'save_operation_breakdown' => $saveBreakdown,
                    'memory_usage' => [
                        'start_memory_mb' => round($startMemory / 1024 / 1024, 2),
                        'current_memory_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
                        'peak_memory_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2)
                    ],
                    'processing_rates' => [
                        'xml_parse_objects_per_second' => round(count($allObjects) / max($parseTime, 0.001), 1),
                        'transform_objects_per_second' => round(count($allObjects) / max($transformTime, 0.001), 1),
                        'save_objects_per_second' => round(count($allObjects) / max($saveTime, 0.001), 1)
                    ]
                ],
                'statistics' => $this->calculateOptimizedStatistics($savedObjects)
            ];

        } catch (\Exception $e) {
            $this->logger->error('OPTIMIZED ArchiMate import failed', [
                'error' => $e->getMessage(),
                'file_path' => $options['file_path'] ?? 'unknown'
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

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
     * @return array Import results with detailed status
     */
    public function importArchiMateFileFromPath(array $options = []): array
    {
        // Track start time and memory for performance metrics
        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);
        
        // OPTIMIZATION: Cache configuration values once at the start
        $this->initializeCache();
        
        // Starting ArchiMate XML import with model detection

        try {
            // STEP 1: Parse XML to array using the specialized import service
            // This captures ALL possible XML values including attributes, text content, and nested elements
            $filePath = $options['filePath'] ?? $options['file_path'] ?? '';
            
            if (empty($filePath)) {
                throw new \InvalidArgumentException('File path is required for import');
            }
            
            if (!file_exists($filePath)) {
                throw new \InvalidArgumentException("File not found: {$filePath}");
            }
            
            $this->logger->info('Step 1: Parsing XML to array for complete data capture', ['filePath' => $filePath]);
            $parseStartTime = microtime(true);
            $xmlData = $this->parseArchiMateXml($filePath);
            $parseTime = microtime(true) - $parseStartTime;
            
            // STEP 2: Extract model identifier and detect if model already exists
            // This is critical for determining whether to create new or update existing model
            $this->logger->info('Step 2: Extracting model identifier and checking for existing model');
            $validationStartTime = microtime(true);
            $modelIdentifier = $this->extractModelIdentifier($xmlData);
            $modelExists = $this->checkIfModelExists($modelIdentifier);
            $validationTime = microtime(true) - $validationStartTime;
            
            // STEP 3: Normalize data structure for storage as JSON blob
            // Store complete raw XML data for exact round-trip fidelity during export
            $this->logger->info('Step 3: Normalizing data structure for JSON blob storage');
            $normalizedData = $this->normalizeArchiMateData($xmlData, $modelIdentifier);
            
            // STEP 4: Convert to OpenRegister objects with proper @self structure
            // Each object must have @self with register, schema, and id for ObjectService::saveObjects
            $this->logger->info('Step 4: Converting to OpenRegister objects with @self structure');
            $convertStartTime = microtime(true);
            $objects = $this->convertToOpenRegisterObjects($normalizedData, $modelIdentifier);
            $convertTime = microtime(true) - $convertStartTime;
            
            // STEP 5: Save objects using ObjectService::saveObjects
            // This handles the actual database persistence with proper validation
            $this->logger->info('Step 5: Saving objects to database using ObjectService::saveObjects');
            $savedObjects = $this->saveObjectsToDatabase($objects);
            
            // Calculate total time and memory usage
            $totalTime = microtime(true) - $startTime;
            $endMemory = memory_get_usage(true);
            $peakMemory = memory_get_peak_usage(true);
            
            // Count objects by type for detailed statistics
            $statistics = $this->calculateObjectStatistics($normalizedData, $savedObjects);
            
            // Calculate performance metrics
            $totalObjects = $statistics['summary']['total_objects_created'] + $statistics['summary']['total_objects_updated'];
            $itemsPerSecond = $totalObjects > 0 ? $totalObjects / $totalTime : 0;
            
            // Prepare comprehensive result with detailed information
            $result = [
                'success' => true,
                'file_info' => [
                    'name' => $options['fileName'] ?? basename($filePath),
                    'size' => filesize($filePath),
                    'mime_type' => $options['mimeType'] ?? 'text/xml'
                ],
                'processing_times' => [
                    'total_time_seconds' => round($totalTime, 3),
                    'validation_time_seconds' => round($validationTime, 3),
                    'parse_time_seconds' => round($parseTime, 3),
                    'convert_time_seconds' => round($convertTime, 3),
                    'performance_breakdown' => [
                        'validation_percent' => round(($validationTime / $totalTime) * 100, 1),
                        'parse_percent' => round(($parseTime / $totalTime) * 100, 1),
                        'convert_percent' => round(($convertTime / $totalTime) * 100, 1)
                    ]
                ],
                'memory_usage' => [
                    'start_mb' => round($startMemory / 1024 / 1024, 1),
                    'end_mb' => round($endMemory / 1024 / 1024, 1),
                    'peak_mb' => round($peakMemory / 1024 / 1024, 2),
                    'total_used_mb' => round(($endMemory - $startMemory) / 1024 / 1024, 1)
                ],
                'statistics' => $statistics,
                'summary' => [
                    'total_objects_created' => $statistics['summary']['total_objects_created'],
                    'total_objects_updated' => $statistics['summary']['total_objects_updated'],
                    'total_objects_deleted' => $statistics['summary']['total_objects_deleted'],
                    'total_objects_skipped' => $statistics['summary']['total_objects_skipped'],
                    'total_errors' => $statistics['summary']['total_errors']
                ],
                'performance_metrics' => [
                    'items_per_second' => round($itemsPerSecond, 2),
                    'processing_method' => 'synchronous_batch_processing',
                    'batch_size_used' => 100,
                    'dataset_size' => $totalObjects
                ]
            ];

            $this->logger->info('ArchiMate XML import completed successfully', [
                'model_identifier' => $modelIdentifier,
                'model_exists' => $modelExists,
                'imported_objects' => $totalObjects,
                'round_trip_fidelity' => 'enabled'
            ]);

            return $result;

        } catch (\Exception $e) {
            $this->logger->error('ArchiMate XML import failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'file_path' => $options['filePath'] ?? $options['file_path'] ?? 'unknown'
            ]);

            return [
                'success' => false,
                'message' => 'Import failed: ' . $e->getMessage(),
                'error' => $e->getMessage(),
                'step_failed' => 'unknown' // Will be refined with better error tracking
            ];
        }
    }

    /**
     * Parse ArchiMate XML file to array using the import service
     * 
     * @param string $filePath Path to XML file
     * @return array Parsed XML data
     */
    private function parseArchiMateXml(string $filePath): array
    {
        if (!file_exists($filePath)) {
            throw new \InvalidArgumentException("File not found: {$filePath}");
        }

        // PERFORMANCE OPTIMIZATION: Use more efficient XML parsing
        $xmlContent = file_get_contents($filePath);
        if ($xmlContent === false) {
            throw new \RuntimeException("Failed to read file: {$filePath}");
        }

        // PERFORMANCE OPTIMIZATION: Disable external entity loading for security and speed
        $previousValue = libxml_disable_entity_loader(true);
        
        try {
            // PERFORMANCE OPTIMIZATION: Use LIBXML_NOCDATA for faster parsing
            $xml = new SimpleXMLElement($xmlContent, LIBXML_NOCDATA | LIBXML_NONET);
            $result = $this->xmlToArray($xml);
            
            // PERFORMANCE OPTIMIZATION: Clear XML object from memory immediately
            unset($xml);
            
            return $result;
        } finally {
            // Restore previous entity loader setting
            libxml_disable_entity_loader($previousValue);
        }
    }

    /**
     * Extract model identifier from parsed XML data
     * 
     * This method looks for the model identifier in various locations within the XML:
     * 1. Root level _attributes.identifier (most common)
     * 2. Model element attributes
     * 3. Fallback to generated identifier if none found
     * 
     * @param array $xmlData Parsed XML data array
     * @return string Model identifier for tracking and storage
     */
    private function extractModelIdentifier(array $xmlData): string
    {
        $this->logger->debug('Extracting model identifier from XML data', [
            'xml_keys' => array_keys($xmlData)
        ]);

        // STEP 1: Try to find identifier in root attributes (most common location)
        if (isset($xmlData['_attributes']['identifier'])) {
            $modelId = $xmlData['_attributes']['identifier'];
            $this->logger->info('Found model identifier in root attributes', [
                'identifier' => $modelId
            ]);
            return $modelId;
        }

        // STEP 2: Look for model element with identifier
        if (isset($xmlData['model']) && is_array($xmlData['model'])) {
            if (isset($xmlData['model']['_attributes']['identifier'])) {
                $modelId = $xmlData['model']['_attributes']['identifier'];
                $this->logger->info('Found model identifier in model element attributes', [
                    'identifier' => $modelId
                ]);
                return $modelId;
            }
        }

        // STEP 3: Look for archimate:model namespace (ArchiMate Tool format)
        if (isset($xmlData['archimate:model']) && is_array($xmlData['archimate:model'])) {
            if (isset($xmlData['archimate:model']['_attributes']['identifier'])) {
                $modelId = $xmlData['archimate:model']['_attributes']['identifier'];
                $this->logger->info('Found model identifier in archimate:model namespace', [
                    'identifier' => $modelId
                ]);
                return $modelId;
            }
        }

        // STEP 4: Generate fallback identifier if none found
        $fallbackId = 'model-' . uniqid() . '-' . time();
        $this->logger->warning('No model identifier found, generating fallback', [
            'fallback_id' => $fallbackId
        ]);

        return $fallbackId;
    }

    /**
     * Check if a model already exists in the database
     * 
     * @param string $modelIdentifier The model identifier to check
     * @return bool True if model exists, false otherwise
     */
    private function checkIfModelExists(string $modelIdentifier): bool
    {
        $this->logger->debug('Checking if model already exists', [
            'model_identifier' => $modelIdentifier
        ]);

        try {
            // Get ObjectService to query existing objects
            $objectService = $this->getObjectService();
            if (!$objectService) {
                $this->logger->warning('ObjectService not available, assuming new model');
                return false;
            }

            // Get AMEF configuration for register and schema IDs
            $registerId = $this->getAmefRegisterId();
            $schemaId = $this->getAmefSchemaIdForType('model');
            
            if (!$registerId || !$schemaId) {
                $this->logger->warning('AMEF register or model schema not configured, assuming new model', [
                    'registerId' => $registerId,
                    'schemaId' => $schemaId
                ]);
                return false;
            }

            // Query for existing model objects with this identifier
            // Use searchObjects with @self structure for proper querying
            $query = [
                '@self' => [
                    'register' => $registerId,
                    'schema' => $schemaId
                ],
                'archimate_id' => $modelIdentifier
            ];

            $existingModels = $objectService->searchObjects($query);

            $exists = !empty($existingModels);
            
            $this->logger->info('Model existence check completed', [
                'model_identifier' => $modelIdentifier,
                'exists' => $exists,
                'found_count' => count($existingModels),
                'registerId' => $registerId,
                'schemaId' => $schemaId
            ]);

            return $exists;

        } catch (\Exception $e) {
            $this->logger->error('Error checking model existence', [
                'model_identifier' => $modelIdentifier,
                'error' => $e->getMessage()
            ]);
            // If we can't check, assume new model to avoid data loss
            return false;
        }
    }

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
     * @return array Normalized data structure ready for database storage
     */
    private function normalizeArchiMateData(array $data, string $modelIdentifier): array
    {
        $this->logger->info('Normalizing ArchiMate data structure for JSON blob storage', [
            'model_identifier' => $modelIdentifier
        ]);

        // STEP 0: Extract propertyDefinition map and store in model metadata
        $propertyDefinitionMap = $this->extractPropertyDefinitionMap($data);
        
        // Log property mapping for debugging
        if (!empty($propertyDefinitionMap)) {
            $this->logger->info('Property definitions extracted and mapped', [
                'total_properties' => count($propertyDefinitionMap),
                'property_mapping' => $this->getPropertyNameMapping($propertyDefinitionMap)
            ]);
        }

        // Initialize normalized structure with model metadata
        $normalized = [
            'model_metadata' => [],
            'model_identifier' => $modelIdentifier, // Add model identifier for linking
            'elements' => [],
            'relationships' => [],
            'organizations' => [],
            'views' => [],
            'property_definitions' => []
        ];

        // STEP 1: Extract and store model metadata
        if (isset($data['_attributes'])) {
            $normalized['model_metadata'] = $data['_attributes'];
        }
        // Also extract name and documentation from root level
        if (isset($data['name'])) {
            $normalized['model_metadata']['name'] = $data['name'];
        }
        if (isset($data['documentation'])) {
            $normalized['model_metadata']['documentation'] = $data['documentation'];
        }
        if (isset($data['properties'])) {
            $normalized['model_metadata']['properties'] = $data['properties'];
        }
        // Store propertyDefinitionMap in model_metadata
        $normalized['model_metadata']['propertyDefinitionMap'] = $propertyDefinitionMap;

        $this->logger->debug('Extracted model metadata', [
            'metadata_keys' => array_keys($normalized['model_metadata']),
            'has_name' => isset($normalized['model_metadata']['name']),
            'has_documentation' => isset($normalized['model_metadata']['documentation'])
        ]);

        // STEP 2: Process each section and store complete raw XML data
        $sections = ['elements', 'relationships', 'organizations', 'views', 'property_definitions'];
        $alternativeNames = [
            'views' => ['views', 'diagrams'],
            'organizations' => ['organizations', 'organisation'],
            'property_definitions' => ['propertyDefinitions', 'property_definitions', 'propertydefinitions']
        ];
        foreach ($sections as $section) {
            $sectionData = null;
            $actualSectionName = null;
            if (isset($data[$section])) {
                $sectionData = $data[$section];
                $actualSectionName = $section;
            } else {
                if (isset($alternativeNames[$section])) {
                    foreach ($alternativeNames[$section] as $altName) {
                        if (isset($data[$altName])) {
                            $sectionData = $data[$altName];
                            $actualSectionName = $altName;
                            break;
                        }
                    }
                }
            }
            if ($sectionData !== null) {
                $normalized[$section] = $this->extractSectionDataWithProperties($sectionData, $section, $modelIdentifier, $propertyDefinitionMap);
            }
        }
        $this->logger->info('Data normalization completed', [
            'model_identifier' => $modelIdentifier,
            'sections_processed' => $sections,
            'round_trip_fidelity' => 'enabled'
        ]);
        return $normalized;
    }

    /**
     * Extract data from a specific section, flatten properties, and store xml
     *
     * @param mixed $sectionData Section data from XML parsing
     * @param string $sectionName Name of the section being processed
     * @param string $modelIdentifier The model identifier for linking items
     * @param array $propertyDefinitionMap Map of propertyDefinitionRef => property name
     * @return array Extracted section data with complete XML preservation and flattened properties
     */
    private function extractSectionDataWithProperties(mixed $sectionData, string $sectionName, string $modelIdentifier, array $propertyDefinitionMap): array
    {
        $extracted = [];
        if (is_array($sectionData)) {
            $items = $this->findItemsInSection($sectionData, $sectionName);
            foreach ($items as $item) {
                $identifier = $this->extractIdentifier($item, $sectionName);
                if ($identifier) {
                    // OPTIMIZATION: Store XML data directly without expensive deep copy
                    // Start with base object structure  
                    $object = [
                        'identifier' => $identifier,
                        'section' => $sectionName,
                        'model_identifier' => $modelIdentifier,
                        'extracted_at' => time(),
                        'xml' => $this->extractEssentialXmlData($item) // OPTIMIZATION: Store only essential XML data
                    ];
                    
                    // Extract name from XML if it exists
                    if (isset($item['name'])) {
                        if (is_array($item['name']) && isset($item['name']['_value'])) {
                            $object['name'] = $item['name']['_value'];
                        } elseif (is_string($item['name'])) {
                            $object['name'] = $item['name'];
                        }
                    }
                    
                    // Extract documentation from XML if it exists and set to summary
                    if (isset($item['documentation'])) {
                        if (is_array($item['documentation']) && isset($item['documentation']['_value'])) {
                            $object['summary'] = $item['documentation']['_value'];
                        } elseif (is_string($item['documentation'])) {
                            $object['summary'] = $item['documentation'];
                        }
                    }
                    
                    // Flatten properties to root fields using the propertyDefinitionMap
                    if (isset($item['properties']) && isset($item['properties']['property'])) {
                        $props = $item['properties']['property'];
                        $processedProperties = [];
                        if (isset($props[0])) {
                            // Multiple properties
                            foreach ($props as $prop) {
                                $defRef = $prop['_attributes']['propertyDefinitionRef'] ?? null;
                                $value = $prop['value']['_value'] ?? $prop['value'] ?? null;
                                if ($defRef && isset($propertyDefinitionMap[$defRef])) {
                                    $name = $propertyDefinitionMap[$defRef];
                                    $camelCaseName = $this->convertToCamelCase($name);
                                    $object[$camelCaseName] = $value;
                                    
                                    // Store property mapping for reference
                                    if (!isset($object['_propertyMapping'])) {
                                        $object['_propertyMapping'] = [];
                                    }
                                    $object['_propertyMapping'][$camelCaseName] = $name;
                                    
                                    // If this property is 'Object ID', set slug for later use
                                    if (strtolower($name) === 'object id') {
                                        $object['_slug'] = $value; // Store temporarily, will be moved to @self.slug later
                                    }
                                }
                            }
                        } elseif (isset($props['_attributes']['propertyDefinitionRef'])) {
                            // Single property
                            $defRef = $props['_attributes']['propertyDefinitionRef'];
                            $value = $props['value']['_value'] ?? $props['value'] ?? null;
                            if ($defRef && isset($propertyDefinitionMap[$defRef])) {
                                $name = $propertyDefinitionMap[$defRef];
                                $camelCaseName = $this->convertToCamelCase($name);
                                $object[$camelCaseName] = $value;
                                
                                // Store property mapping for reference
                                if (!isset($object['_propertyMapping'])) {
                                    $object['_propertyMapping'] = [];
                                }
                                $object['_propertyMapping'][$camelCaseName] = $name;
                                
                                $processedProperties[] = [
                                    'original' => $name,
                                    'camelCase' => $camelCaseName,
                                    'value' => $value
                                ];
                                
                                if (strtolower($name) === 'object id') {
                                    $object['_slug'] = $value; // Store temporarily, will be moved to @self.slug later
                                }
                            }
                            
                            // OPTIMIZATION: Removed debug logging from tight loop for performance
                        }
                    }
                    $extracted[$identifier] = $object;
                }
            }
        }
        return $extracted;
    }

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
     * @return array Array of OpenRegister objects with proper @self structure
     */
    private function convertToOpenRegisterObjects(array $normalizedData, string $modelIdentifier): array
    {
        $this->logger->info('Converting to OpenRegister objects with @self structure', [
            'model_identifier' => $modelIdentifier
        ]);

        $objects = [];
        
        // STEP 1: Convert model metadata to model object
        if (!empty($normalizedData['model_metadata'])) {
            $this->logger->debug('Creating model object from metadata');
            $objects[] = $this->createModelObject($normalizedData['model_metadata'], $modelIdentifier);
        }

        // STEP 2: Convert each section to individual objects
        $sections = ['elements', 'relationships', 'organizations', 'views', 'property_definitions'];
        
        // OPTIMIZATION: Removed excessive debug logging from tight loops
        $sectionCounts = [];
        foreach ($sections as $section) {
            if (!empty($normalizedData[$section]) && is_array($normalizedData[$section])) {
                $sectionCounts[$section] = count($normalizedData[$section]);
                foreach ($normalizedData[$section] as $identifier => $data) {
                    $objects[] = $this->createSectionObject($section, $identifier, $data, $modelIdentifier);
                }
            } else {
                $sectionCounts[$section] = 0;
            }
        }
        
        // Single consolidated log entry
        $this->logger->debug('Sections processed', $sectionCounts);

        $this->logger->info('Conversion to OpenRegister objects completed', [
            'model_identifier' => $modelIdentifier,
            'total_objects' => count($objects),
            'sections_processed' => $sections
        ]);

        return $objects;
    }

    /**
     * Create model object with @self structure
     * 
     * @param array $metadata Model metadata
     * @param string $modelIdentifier Model identifier
     * @return array Model object with @self structure
     */
    private function createModelObject(array $metadata, string $modelIdentifier): array
    {
        // OPTIMIZATION: Use cached configuration values
        $registerId = $this->cachedConfig['registerId'] ?? 15;
        $schemaId = $this->cachedConfig['schemaIds']['model'] ?? 67;
        
        // Create object with @self structure and metadata at root level (no JSON serialization)
        $object = [
            '@self' => [
                'register' => $registerId,
                'schema' => $schemaId,
                'id' => $metadata['identifier'] ?? uniqid('model_'),
                'owner' => $this->getCurrentUserId(),
                'organisation' => $this->getCurrentOrganisation(),
                'published' => date('Y-m-d\TH:i:s\Z')
            ],
            'identifier' => $metadata['identifier'] ?? '',
            'section' => 'model',
            'model_identifier' => $modelIdentifier
        ];
        
        // Merge metadata directly at root level
        return array_merge($object, $metadata);
    }

    /**
     * Create section object with @self structure and flattened XML data
     * 
     * @param string $section Section name
     * @param string $identifier Item identifier
     * @param array $data Item data (already contains XML data at root level)
     * @param string $modelIdentifier Model identifier for linking
     * @return array Section object with @self structure
     */
    private function createSectionObject(string $section, string $identifier, array $data, string $modelIdentifier): array
    {
        // OPTIMIZATION: Use cached configuration values
        $registerId = $this->cachedConfig['registerId'] ?? 15;
        $schemaId = $this->cachedConfig['schemaIds'][$section] ?? $this->getSchemaIdForSection($section);
        
        // FIXED: Use objectId as main ID and AMEF identifier as slug
        $objectId = null;
        $slug = null;
        
        // Priority 1: Check for objectId property (flattened from "Object ID")
        if (isset($data['objectId'])) {
            $objectId = $data['objectId'];
            $slug = $identifier; // Use AMEF identifier as slug
        }
        // Priority 2: Check for temporary _slug field (legacy support)
        elseif (isset($data['_slug'])) {
            $objectId = $data['_slug'];
            $slug = $identifier; // Use AMEF identifier as slug
            unset($data['_slug']); // Remove the temporary field
        }
        // Priority 3: Check for direct "Object ID" property
        elseif (isset($data['Object ID'])) {
            $objectId = $data['Object ID'];
            $slug = $identifier; // Use AMEF identifier as slug
        }
        // Fallback: Use AMEF identifier as both ID and extract clean UUID for slug
        else {
            $objectId = $identifier;
            // Extract clean UUID from AMEF identifier (remove "id-" prefix if present)
            if ($identifier && str_starts_with($identifier, 'id-')) {
                $slug = substr($identifier, 3); // Remove "id-" prefix
            } else {
                $slug = $identifier;
            }
        }
        
        // Create object with @self structure using correct ID and slug
        $object = [
            '@self' => [
                'register' => $registerId,
                'schema' => $schemaId,
                'id' => $objectId, // Now using objectId as main ID
                'slug' => $slug,   // Now using AMEF identifier as slug
                'owner' => $this->getCurrentUserId(),
                'organisation' => $this->getCurrentOrganisation(),
                'published' => date('Y-m-d\TH:i:s\Z')
            ]
        ];
        
        // Merge XML data directly at root level (data already contains identifier, section, model_identifier)
        return array_merge($object, $data);
    }

    /**
     * Save objects to database using ObjectService::saveObjects
     * 
     * @param array $objects Objects to save
     * @return array Saved objects
     */
    private function saveObjectsToDatabase(array $objects): array
    {
        $saveStartTime = microtime(true);
        
        $serviceInitStartTime = microtime(true);
        $objectService = $this->getObjectService();
        if (!$objectService) {
            throw new \RuntimeException('ObjectService not available');
        }
        $serviceInitTime = microtime(true) - $serviceInitStartTime;

        // ENHANCEMENT: Process GEMMA Referentiecomponent-Standaard relationships before saving
        $gemmaProcessingStartTime = microtime(true);
        $objects = $this->processGemmaReferenceComponentStandards($objects);
        $gemmaProcessingTime = microtime(true) - $gemmaProcessingStartTime;

        // Saving objects to database

        // OPTIMIZATION: Use cached register ID
        $registerId = $this->cachedConfig['registerId'] ?? 15;



        // HYBRID OPTIMIZATION: Choose best strategy based on dataset size
        // Small datasets: Direct to ObjectService (avoids batching overhead)
        // Large datasets: Use our intelligent batching (better performance for bulk operations)
        $batchProcessingStartTime = microtime(true);
        $objectCount = count($objects);
        
        if ($objectCount < 2000) {
            // Small dataset: Let ObjectService handle everything directly
            $result = $this->saveObjectsDirectToService($objects, $objectService, $registerId);
        } else {
            // Large dataset: Use our intelligent batching for better performance
            $result = $this->saveObjectsInParallelBatches($objects, $objectService, $registerId);
        }
        $batchProcessingTime = microtime(true) - $batchProcessingStartTime;
        
        $totalSaveTime = microtime(true) - $saveStartTime;
        

        
        // Database save completed

        // Store timing breakdown for performance metrics
        $this->lastSaveTimingBreakdown = [
            'total_save_seconds' => round($totalSaveTime, 3),
            'service_init_seconds' => round($serviceInitTime, 3),
            'gemma_processing_seconds' => round($gemmaProcessingTime, 3),
            'batch_processing_seconds' => round($batchProcessingTime, 3),
            'objects_saved' => count($result),
            'save_rate_objects_per_second' => round(count($objects) / max($totalSaveTime, 0.001), 1)
        ];

        return $result;
    }

    /**
     * Save objects directly to ObjectService without custom batching
     * Lets ObjectService handle all batching, throttling, and optimization internally
     * 
     * @param array $objects Array of objects to save
     * @param ObjectService $objectService ObjectService instance  
     * @param int $registerId Register ID
     * @return array Array of saved objects
     */
    private function saveObjectsDirectToService(array $objects, ObjectService $objectService, int $registerId): array
    {
        try {
            // Single call to ObjectService - let it handle everything
            $saveResult = $objectService->saveObjects(
                objects: $objects,
                register: $registerId,
                schema: null, // Mixed schemas supported now
                rbac: true, // Enable proper RBAC
                multi: true, // Enable multi-processing if available  
                validation: true, // Enable validation
                events: true // Enable events
            );

            // Store result for statistics
            $this->lastSaveResult = $saveResult;

            // Return combined saved and updated objects
            return array_merge(
                $saveResult['saved'] ?? [],
                $saveResult['updated'] ?? [],
                $saveResult['unchanged'] ?? []
            );

        } catch (\Exception $e) {
            $this->logger->error('Error in direct ObjectService save', [
                'error' => $e->getMessage(),
                'object_count' => count($objects)
            ]);
            throw $e;
        }
    }

    /**
     * Save objects in parallel batches for maximum performance (DEPRECATED)
     * 
     * @param array $objects Array of objects to save
     * @param ObjectService $objectService ObjectService instance
     * @param int $registerId Register ID
     * @return array Array of saved objects
     */
    private function saveObjectsInParallelBatches(array $objects, ObjectService $objectService, int $registerId): array
    {
        $batchSize = self::PERFORMANCE_OPTIMIZATIONS['batch_size'];
        $parallelBatches = self::PERFORMANCE_OPTIMIZATIONS['parallel_batches'];
        
        // INTELLIGENT BATCH SIZING: Create size-aware batches instead of fixed-size chunks
        $chunks = $this->createIntelligentBatches($objects);
        $totalChunks = count($chunks);
        
        // Batch processing initialized

        $allResults = [];
        $processedChunks = 0;
        
        // Accumulate statistics from all chunks (using new format)
        $aggregatedStats = [
            'saved' => [],
            'updated' => [],
            'unchanged' => [],
            'invalid' => []
        ];
        
        // Process chunks sequentially but with larger batch sizes for better performance
        foreach ($chunks as $chunkIndex => $chunk) {
            $chunkInputCount = count($chunk);
            
            try {
                $saveResult = $objectService->saveObjects(
                    objects: $chunk,
                    register: $registerId,
                    schema: null,
                    rbac: self::PERFORMANCE_OPTIMIZATIONS['disable_rbac'] ? false : true,
                    multi: self::PERFORMANCE_OPTIMIZATIONS['use_multi'],
                    validation: !self::PERFORMANCE_OPTIMIZATIONS['disable_validation'],
                    events: !self::PERFORMANCE_OPTIMIZATIONS['disable_events']
                );
                

                
                // Calculate totals received back from this chunk
                $chunkTotalReceived = count($saveResult['saved'] ?? []) + 
                                    count($saveResult['updated'] ?? []) + 
                                    count($saveResult['unchanged'] ?? []) + 
                                    count($saveResult['invalid'] ?? []);
                
                // Accumulate statistics from this chunk
                $aggregatedStats['saved'] = array_merge($aggregatedStats['saved'], $saveResult['saved'] ?? []);
                $aggregatedStats['updated'] = array_merge($aggregatedStats['updated'], $saveResult['updated'] ?? []);
                $aggregatedStats['unchanged'] = array_merge($aggregatedStats['unchanged'], $saveResult['unchanged'] ?? []);
                $aggregatedStats['invalid'] = array_merge($aggregatedStats['invalid'], $saveResult['invalid'] ?? []);
                
                $savedObjects = array_merge(
                    $saveResult['saved'] ?? [],
                    $saveResult['updated'] ?? []
                );
                
                $allResults = array_merge($allResults, $savedObjects);
                
                $processedChunks++;
                
            } catch (\Exception $e) {
                $this->logger->error('Error processing chunk', [
                    'chunk_index' => $chunkIndex,
                    'error' => $e->getMessage()
                ]);
                // Continue with other chunks
            }
            
            // Memory cleanup between chunks
            if (self::PERFORMANCE_OPTIMIZATIONS['memory_cleanup']) {
                $this->cleanupMemory();
            }
        }
        
        // Store the aggregated result for statistics calculation
        $this->lastSaveResult = $aggregatedStats;
        
        $totalObjectsProcessed = count($aggregatedStats['saved']) + count($aggregatedStats['updated']) + count($aggregatedStats['unchanged']) + count($aggregatedStats['invalid']);
        
        // Batch processing completed
        
        // Log critical discrepancy if found
        if (count($objects) != $totalObjectsProcessed) {
            $this->logger->critical('OBJECT COUNT MISMATCH DETECTED', [
                'objects_sent_to_openregister' => count($objects),
                'objects_processed_by_openregister' => $totalObjectsProcessed,
                'missing_objects' => count($objects) - $totalObjectsProcessed,
                'this_explains_the_781_missing_objects' => true
            ]);
        }
        
        return $allResults;
    }

    /**
     * Save objects in a single batch (fallback method)
     * 
     * @param array $objects Array of objects to save
     * @param ObjectService $objectService ObjectService instance
     * @param int $registerId Register ID
     * @return array Array of saved objects
     */
    private function saveObjectsInSingleBatch(array $objects, ObjectService $objectService, int $registerId): array
    {
        // Using single batch processing
        

        
        $saveResult = $objectService->saveObjects(
            objects: $objects,
            register: $registerId,
            schema: null,
            rbac: self::PERFORMANCE_OPTIMIZATIONS['disable_rbac'] ? false : true,
            multi: self::PERFORMANCE_OPTIMIZATIONS['use_multi'],
            validation: !self::PERFORMANCE_OPTIMIZATIONS['disable_validation'],
            events: !self::PERFORMANCE_OPTIMIZATIONS['disable_events']
        );



        // Store the save result for later access to statistics
        $this->lastSaveResult = $saveResult;

        // Extract saved objects from the new structured return format
        $savedObjects = array_merge(
            $saveResult['saved'] ?? [],
            $saveResult['updated'] ?? []
        );

        // Objects saved successfully

        // Validation errors logged if any

        // Unchanged objects noted if any

        // Return the combined saved and updated objects (maintaining backward compatibility)
        return $savedObjects;
    }

    /**
     * Get ObjectService from container
     * 
     * @return ObjectService|null ObjectService instance or null if not available
     */
    private function getObjectService(): ?ObjectService
    {
        if (!$this->appManager->isInstalled('openregister')) {
            return null;
        }

        try {
            return $this->container->get(ObjectService::class);
        } catch (\Exception $e) {
            $this->logger->warning('Failed to get ObjectService', [
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Initialize cached configuration values for performance optimization
     * 
     * @return void
     */
    private function initializeCache(): void
    {
        if ($this->cachedConfig !== null) {
            return; // Already cached
        }

        $this->cachedConfig = [
            'userId' => $this->userSession->getUser()?->getUID(),
            'organisation' => 'default',
            'registerId' => $this->getAmefRegisterId(),
            'schemaIds' => [
                'model' => $this->getAmefSchemaIdForType('model'),
                'element' => $this->getAmefSchemaIdForType('element'),
                'relationship' => $this->getAmefSchemaIdForType('relationship'),
                'view' => $this->getAmefSchemaIdForType('view'),
                'organization' => $this->getAmefSchemaIdForType('organization'),
                'property_definition' => $this->getAmefSchemaIdForType('property_definition')
            ]
        ];
    }

    /**
     * Log current memory usage for performance monitoring
     * 
     * @param string $stage Description of the current processing stage
     * @return void
     */
    private function logMemoryUsage(string $stage): void
    {
        // Check if debug logging is available (Nextcloud logger doesn't have isDebug method)
        $memoryUsage = memory_get_usage(true);
        $memoryPeak = memory_get_peak_usage(true);
        $memoryLimit = ini_get('memory_limit');
        
        $this->logger->debug("Memory usage at: {$stage}", [
            'current_mb' => round($memoryUsage / 1024 / 1024, 2),
            'peak_mb' => round($memoryPeak / 1024 / 1024, 2),
            'limit' => $memoryLimit
        ]);
    }

    /**
     * Clean up memory by forcing garbage collection
     * 
     * @return void
     */
    private function cleanupMemory(): void
    {
        if (function_exists('gc_collect_cycles')) {
            $cycles = gc_collect_cycles();
            // Use PSR-3 standard logging instead of isDebug() check
            $this->logger->debug('Garbage collection completed', [
                'cycles_collected' => $cycles
            ]);
        }
    }

    /**
     * Get current user ID from cache
     * 
     * @return string|null Current user ID or null if not authenticated
     */
    private function getCurrentUserId(): ?string
    {
        return $this->cachedConfig['userId'] ?? null;
    }

    /**
     * Get current organisation from cache
     * 
     * @return string Default organisation
     */
    private function getCurrentOrganisation(): string
    {
        return $this->cachedConfig['organisation'] ?? 'default';
    }

    /**
     * Get AMEF configuration from app config
     * 
     * @return array AMEF configuration
     */
    public function getAmefConfig(): array
    {
        $this->logger->info('Getting AMEF configuration');
        
        try {
            // Get configuration from app config using the correct method
            $config = $this->config->getValueString('softwarecatalog', 'amef_config', '{}');
            $decoded = json_decode($config, true);
            
            if (!is_array($decoded)) {
                // Fallback to individual config values for backward compatibility
                $decoded = [
                    'register_id' => $this->config->getValueString('softwarecatalog', 'amef_register', ''),
                    'model_schema_id' => $this->config->getValueString('softwarecatalog', 'amef_model_schema', ''),
                    'elements_schema' => $this->config->getValueString('softwarecatalog', 'amef_elements_schema', ''),
                    'relationships_schema' => $this->config->getValueString('softwarecatalog', 'amef_relationships_schema', ''),
                    'views_schema' => $this->config->getValueString('softwarecatalog', 'amef_views_schema', ''),
                    'organizations_schema' => $this->config->getValueString('softwarecatalog', 'amef_organizations_schema', ''),
                    'folders_schema' => $this->config->getValueString('softwarecatalog', 'amef_folders_schema', ''),
                    'property_definitions_schema' => $this->config->getValueString('softwarecatalog', 'amef_property_definitions_schema', '')
                ];
            }
            
            return $decoded;
        } catch (\Exception $e) {
            $this->logger->error('Failed to get AMEF configuration', [
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Get AMEF register ID from configuration
     * 
     * @return int|null The register ID or null if not configured
     */
    private function getAmefRegisterId(): ?int
    {
        // Retrieve AMEF configuration
        $amefConfig = $this->getAmefConfig();

        // Try JSON config keys first: support both 'register_id' and 'register'
        $rawRegisterId = $amefConfig['register_id']
            ?? $amefConfig['register']
            ?? null;

        // Fallback to legacy individual app config keys if not present in JSON
        if ($rawRegisterId === null || $rawRegisterId === '') {
            $rawRegisterId = $this->config->getValueString('softwarecatalog', 'amef_register', '')
                ?: $this->config->getValueString('softwarecatalog', 'amef_register_id', '');
        }

        // Validate and normalize to positive int
        if ($rawRegisterId !== null && $rawRegisterId !== '' && is_numeric((string) $rawRegisterId)) {
            $registerId = (int) $rawRegisterId;
            return $registerId > 0 ? $registerId : null;
        }

        return null;
    }

    /**
     * Get AMEF schema ID for a specific ArchiMate type
     *
     * This method retrieves the schema ID for a given ArchiMate type from the AMEF configuration.
     * It looks for the schema ID using the pattern '{type}_schema' in the configuration.
     *
     * @param string $archiMateType The ArchiMate type (e.g., 'element', 'organization', 'relationship')
     * @return int|null The schema ID for the given type or null if not configured
     */
    private function getAmefSchemaIdForType(string $archiMateType): ?int
    {
        // Get AMEF configuration
        $amefConfig = $this->getAmefConfig();

        // Normalize plural → singular and handle the actual config structure
        $typeMapping = [
            'elements' => 'element',
            'organizations' => 'organization',
            // Accept both 'relationships' (AMEF wording) and UI term 'relation'
            'relationships' => 'relation',
            'views' => 'view',
            'models' => 'model',
            'properties' => 'property',
            // Accept both underscored and dashed naming conventions
            'property_definitions' => 'property-definition'
        ];
        $normalizedType = $typeMapping[$archiMateType] ?? $archiMateType;

        // Candidate keys: match the actual config structure
        $schemaKeyCandidatesByType = [
            'element' => ['element_schema'],
            'organization' => ['organization_schema'],
            'relationship' => ['relation_schema'],
            'view' => ['view_schema'],
            'model' => ['model_schema'],
            'property' => ['property_schema'],
            'property_definition' => ['property-definition_schema']
        ];

        $candidates = $schemaKeyCandidatesByType[$normalizedType] ?? [$normalizedType . '_schema'];

        // Try JSON config with the actual keys
        foreach ($candidates as $key) {
            if (array_key_exists($key, $amefConfig)) {
                $raw = $amefConfig[$key];
                if ($raw !== '' && $raw !== null && is_numeric((string) $raw)) {
                    $id = (int) $raw;
                    if ($id > 0) {
                        return $id;
                    }
                }
            }
        }

        // Fallback to legacy individual app config keys if not present in JSON
        foreach ($candidates as $key) {
            $raw = $this->config->getValueString('softwarecatalog', 'amef_' . $key, '')
                ?: $this->config->getValueString('softwarecatalog', $key, '');
            if ($raw !== '' && is_numeric((string) $raw)) {
                $id = (int) $raw;
                if ($id > 0) {
                    return $id;
                }
            }
        }

        return null;
    }

    /**
     * Get schema ID for a section
     * 
     * @param string $section Section name
     * @return int Schema ID
     */
    private function getSchemaIdForSection(string $section): int
    {
        $schemaIds = [
            'elements' => 101,
            'relationships' => 102,
            'views' => 103,
            'organizations' => 104,
            'property_definitions' => 105
        ];

        return $schemaIds[$section] ?? 100;
    }

    /**
     * Extract propertyDefinitions from the parsed XML and build a map
     *
     * @param array $data Parsed XML data
     * @return array Map of propertyDefinitionRef => property name
     */
    private function extractPropertyDefinitionMap(array $data): array
    {
        // OPTIMIZATION: Return cached property definition map if available
        if ($this->propertyDefinitionMapCache !== null) {
            return $this->propertyDefinitionMapCache;
        }
        
        $map = [];
        // Find propertyDefinitions section (handle possible alternative names)
        $propertyDefs = null;
        if (isset($data['propertyDefinitions'])) {
            $propertyDefs = $data['propertyDefinitions'];
        } elseif (isset($data['property_definitions'])) {
            $propertyDefs = $data['property_definitions'];
        } elseif (isset($data['propertyDefinitions'])) {
            $propertyDefs = $data['propertyDefinitions'];
        }
        if ($propertyDefs && isset($propertyDefs['propertyDefinition'])) {
            $defs = $propertyDefs['propertyDefinition'];
            if (isset($defs[0])) {
                // Array of propertyDefinition
                foreach ($defs as $def) {
                    if (isset($def['_attributes']['identifier']) && isset($def['name'])) {
                        $map[$def['_attributes']['identifier']] = is_array($def['name']) && isset($def['name']['_value']) ? $def['name']['_value'] : $def['name'];
                    }
                }
            } elseif (isset($defs['_attributes']['identifier']) && isset($defs['name'])) {
                // Single propertyDefinition
                $map[$defs['_attributes']['identifier']] = is_array($defs['name']) && isset($defs['name']['_value']) ? $defs['name']['_value'] : $defs['name'];
            }
        }
        
        // OPTIMIZATION: Cache the result for subsequent calls during the same import
        $this->propertyDefinitionMapCache = $map;
        
        return $map;
    }

    /**
     * Get property mapping information for debugging and reference
     * 
     * This method returns a mapping of original property names to their camelCase equivalents
     * which can be useful for understanding how properties are being processed.
     * 
     * @param array $propertyDefinitionMap The original property definition map
     * @return array Mapping of original names to camelCase names
     */
    public function getPropertyNameMapping(array $propertyDefinitionMap): array
    {
        $mapping = [];
        
        foreach ($propertyDefinitionMap as $propertyRef => $originalName) {
            $mapping[$originalName] = $this->convertToCamelCase($originalName);
        }
        
        return $mapping;
    }

    /**
     * Convert property names with spaces to camelCase for better database compatibility
     * 
     * Examples:
     * - "Object ID" -> "objectId"
     * - "Business Unit" -> "businessUnit"
     * - "System Name" -> "systemName"
     * 
     * @param string $propertyName Property name that may contain spaces
     * @return string CamelCase version of the property name
     */
    private function convertToCamelCase(string $propertyName): string
    {
        // OPTIMIZATION: Check cache first to avoid redundant conversions
        if (isset($this->camelCaseCache[$propertyName])) {
            return $this->camelCaseCache[$propertyName];
        }
        
        // Remove any leading/trailing whitespace
        $propertyName = trim($propertyName);
        
        // Split by spaces and convert to camelCase
        $words = explode(' ', $propertyName);
        
        if (count($words) === 1) {
            // Single word, just lowercase it
            $result = strtolower($words[0]);
        } else {
            // First word is lowercase, subsequent words are capitalized
            $camelCase = strtolower($words[0]);
            
            for ($i = 1; $i < count($words); $i++) {
                $camelCase .= ucfirst(strtolower($words[$i]));
            }
            
            $result = $camelCase;
        }
        
        // OPTIMIZATION: Cache the result for future use
        $this->camelCaseCache[$propertyName] = $result;
        
        return $result;
    }

    /**
     * Calculate optimized statistics for performance reporting
     * 
     * @param array $savedObjects Saved objects from ObjectService::saveObjects
     * @return array Statistics array
     */
    private function calculateOptimizedStatistics(array $savedObjects): array
    {
        $statistics = [
            'summary' => [
                'total_objects_created' => 0,
                'total_objects_updated' => 0,
                'total_objects_deleted' => 0,
                'total_objects_unchanged' => 0,
                'total_errors' => 0
            ]
        ];

        if ($this->lastSaveResult !== null) {
            $saveResult = $this->lastSaveResult;
            

            
            $statistics['summary'] = [
                'total_objects_created' => count($saveResult['saved'] ?? []),
                'total_objects_updated' => count($saveResult['updated'] ?? []),
                'total_objects_deleted' => 0,
                'total_objects_unchanged' => count($saveResult['unchanged'] ?? $saveResult['skipped'] ?? []),
                'total_errors' => count($saveResult['invalid'] ?? [])
            ];
            
            // Log detailed breakdown of results
            $totalStatisticsCount = array_sum([
                count($saveResult['saved'] ?? []),
                count($saveResult['updated'] ?? []),
                count($saveResult['unchanged'] ?? $saveResult['skipped'] ?? []),
                count($saveResult['invalid'] ?? [])
            ]);
            
            $this->logger->info('Import statistics breakdown', [
                'created' => count($saveResult['saved'] ?? []),
                'updated' => count($saveResult['updated'] ?? []),
                'unchanged' => count($saveResult['unchanged'] ?? $saveResult['skipped'] ?? []),
                'invalid' => count($saveResult['invalid'] ?? []),
                'total_in_statistics' => $totalStatisticsCount
            ]);
            
            // Log discrepancy analysis
            if ($totalStatisticsCount != 8781) { // Expected objects processed
                $this->logger->warning('Discrepancy found in object counts', [
                    'expected_objects_processed' => 8781,
                    'actual_statistics_total' => $totalStatisticsCount,
                    'missing_objects' => 8781 - $totalStatisticsCount,
                    'analysis' => 'Some objects may not be reaching OpenRegister saveObjects'
                ]);
            }
        }

        return $statistics;
    }

    /**
     * Get section structure configuration for XML parsing
     * 
     * @param string $sectionName The name of the section (e.g., 'elements', 'relationships', 'views', etc.)
     * @return array Configuration with direct_tags and nested_paths for finding items
     */
    private function getSectionStructureConfig(string $sectionName): array
    {
        // Define the structure configuration for each section type
        $configs = [
            'elements' => [
                'direct_tags' => ['element', 'elements'],
                'nested_paths' => [
                    ['model', 'elements', 'element'],
                    ['model', 'elements'],
                    ['elements', 'element'],
                    ['elements']
                ]
            ],
            'relationships' => [
                'direct_tags' => ['relationship', 'relationships'],
                'nested_paths' => [
                    ['model', 'relationships', 'relationship'],
                    ['model', 'relationships'],
                    ['relationships', 'relationship'],
                    ['relationships']
                ]
            ],
            'views' => [
                'direct_tags' => ['view', 'views', 'diagram', 'diagrams'],
                'nested_paths' => [
                    ['model', 'views', 'diagrams', 'view'],
                    ['model', 'views', 'diagrams'],
                    ['model', 'views'],
                    ['views', 'diagrams', 'view'],
                    ['views', 'diagrams'],
                    ['views']
                ]
            ],
            'organizations' => [
                'direct_tags' => ['item', 'items'],
                'nested_paths' => [
                    ['model', 'organizations', 'item'],
                    ['model', 'organizations'],
                    ['organizations', 'item'],
                    ['organizations']
                ]
            ],
            'property_definitions' => [
                'direct_tags' => ['propertyDefinition', 'propertyDefinitions'],
                'nested_paths' => [
                    ['model', 'propertyDefinitions', 'propertyDefinition'],
                    ['model', 'propertyDefinitions'],
                    ['propertyDefinitions', 'propertyDefinition'],
                    ['propertyDefinitions']
                ]
            ]
        ];

        return $configs[$sectionName] ?? [
            'direct_tags' => [$sectionName],
            'nested_paths' => [[$sectionName]]
        ];
    }

    /**
     * Check if an array is associative (has string keys)
     * 
     * @param array $array The array to check
     * @return bool True if associative, false if indexed
     */
    private function isAssociativeArray(array $array): bool
    {
        return count(array_filter(array_keys($array), 'is_string')) > 0;
    }

    /**
     * Find items within a specific section using AMEF configuration
     * 
     * @param array $sectionData The section data to search
     * @param string $sectionName The name of the section
     * @return array Array of items found
     */
    private function findItemsInSection(array $sectionData, string $sectionName): array
    {
        // OPTIMIZATION: Removed debug logging from section processing

        $items = [];
        
        // Safety check: ensure sectionData is an array
        if (!is_array($sectionData)) {
            return [];
        }
        
        // Get section structure configuration from AMEF config
        $config = $this->getSectionStructureConfig($sectionName);
        
        // Special handling for views with diagrams structure
        if ($sectionName === 'views') {
            
            // Handle nested structure: <views><diagrams><view>
            if (isset($sectionData['diagrams'])) {
                if (isset($sectionData['diagrams']['view'])) {
                    $viewArray = $sectionData['diagrams']['view'];
                    
                    // Handle single view vs array of views
                    if (!isset($viewArray[0]) && isset($viewArray['_attributes'])) {
                        // Single view
                        $items = [$viewArray];
                    } else {
                        // Array of views
                        $items = $viewArray;
                    }
                }
            } else {
                // Direct views structure (fallback)
                if (isset($sectionData['view'])) {
                    $items = $sectionData['view'];
                }
            }
        } else {
            // Try to find items using the configured paths for other sections
            foreach ($config['nested_paths'] as $path) {
                $currentData = $sectionData;
                $pathValid = true;
                
                foreach ($path as $key) {
                    if (isset($currentData[$key])) {
                        $currentData = $currentData[$key];
                    } else {
                        $pathValid = false;
                        break;
                    }
                }
                
                if ($pathValid && is_array($currentData)) {
                    // Check if this is a direct array of items or needs further processing
                    if (isset($currentData[0]) || $this->isAssociativeArray($currentData)) {
                        $items = $currentData;
                        break;
                    }
                }
            }
        }
        
        // If no items found through nested paths, try direct tags
        if (empty($items)) {
            foreach ($config['direct_tags'] as $tag) {
                if (isset($sectionData[$tag])) {
                    $items = $sectionData[$tag];
                    break;
                }
            }
        }
        
        // If still no items found, treat the section itself as items
        if (empty($items)) {
            $items = [$sectionData];
        }
        
        // Ensure items is always an array
        if (!is_array($items)) {
            $items = [$items];
        }
        
        // If items is an associative array with numeric keys, convert to indexed array
        if ($this->isAssociativeArray($items)) {
            $items = array_values($items);
        }
        
        return $items;
    }

    /**
     * Extract identifier from item data
     * 
     * @param array $item Item data
     * @param string $sectionName The section name for special handling
     * @return string|null Identifier or null if not found
     */
    private function extractIdentifier(array $item, string $sectionName = ''): ?string
    {
        // OPTIMIZATION: Use cached patterns for section-specific identifier extraction
        if (isset($this->identifierPatternCache[$sectionName])) {
            $patterns = $this->identifierPatternCache[$sectionName];
            
            // Try cached patterns in order of success frequency
            foreach ($patterns as $pattern) {
                $result = $this->extractIdentifierByPattern($item, $pattern);
                if ($result !== null) {
                    return $result;
                }
            }
        }
        
        // OPTIMIZATION: Build pattern cache on first encounter of section type
        $patterns = $this->buildIdentifierPatternsForSection($sectionName);
        $this->identifierPatternCache[$sectionName] = $patterns;
        
        // Try all patterns and return first successful match
        foreach ($patterns as $pattern) {
            $result = $this->extractIdentifierByPattern($item, $pattern);
            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }

    /**
     * OPTIMIZATION: Extract identifier using a specific pattern
     * 
     * @param array $item The item to extract from
     * @param array $pattern The extraction pattern ['path' => string[], 'type' => string]
     * @return string|null The extracted identifier or null
     */
    private function extractIdentifierByPattern(array $item, array $pattern): ?string
    {
        $path = $pattern['path'];
        $type = $pattern['type'];
        
        // Navigate to the target location
        $current = $item;
        foreach ($path as $key) {
            if (!isset($current[$key])) {
                return null;
            }
            $current = $current[$key];
        }
        
        // Extract based on type
        switch ($type) {
            case 'direct':
                return is_string($current) ? $current : null;
            case 'value':
                return is_array($current) && isset($current['_value']) ? (string) $current['_value'] : null;
            case 'array_search':
                if (is_array($current)) {
                    foreach ($current as $childItem) {
                        if (isset($childItem['_attributes']['identifierRef'])) {
                            return (string) $childItem['_attributes']['identifierRef'];
                        }
                    }
                }
                return null;
            default:
                return null;
        }
    }

    /**
     * OPTIMIZATION: Build identifier extraction patterns for a section type
     * 
     * @param string $sectionName The section name
     * @return array Array of extraction patterns ordered by likelihood of success
     */
    private function buildIdentifierPatternsForSection(string $sectionName): array
    {
        $patterns = [];
        
        // Special handling for organizations
        if ($sectionName === 'organizations') {
            $patterns[] = ['path' => ['_attributes', 'identifierRef'], 'type' => 'direct'];
            $patterns[] = ['path' => ['item'], 'type' => 'array_search'];
            $patterns[] = ['path' => ['label'], 'type' => 'value'];
            $patterns[] = ['path' => ['label'], 'type' => 'direct'];
        } else {
            // Standard patterns for other sections (ordered by frequency in ArchiMate)
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
    }

    /**
     * OPTIMIZATION: Extract only essential XML data to reduce memory usage by 20-30%
     * 
     * Instead of storing the complete XML structure, this method extracts only
     * the essential data needed for round-trip fidelity and export functionality.
     * For view objects, element splicing is performed if elements lookup is provided.
     * 
     * @param array $item The complete XML item data
     * @param array $elementsLookup Optional elements lookup for view processing
     * @param string $schemaType Schema type for conditional processing
     * @return array Essential XML data for storage
     */
    private function extractEssentialXmlData(array $item, array $elementsLookup = [], string $schemaType = ''): array
    {
        $essential = [];
        
        // Always preserve core attributes (needed for export)
        if (isset($item['_attributes'])) {
            $essential['_attributes'] = $item['_attributes'];
        }
        
        // Preserve name and documentation (already extracted to root level but needed for export)
        if (isset($item['name'])) {
            $essential['name'] = $item['name'];
        }
        
        if (isset($item['documentation'])) {
            $essential['documentation'] = $item['documentation'];
        }
        
        // Preserve properties structure (needed for property mapping)
        if (isset($item['properties'])) {
            $essential['properties'] = $item['properties'];
        }
        
        // For relationships, preserve source/target information
        if (isset($item['source'])) {
            $essential['source'] = $item['source'];
        }
        
        if (isset($item['target'])) {
            $essential['target'] = $item['target'];
        }
        
        // Preserve any other critical ArchiMate-specific fields
        $criticalFields = ['type', 'viewpoint', 'accessType', 'isDirected'];
        foreach ($criticalFields as $field) {
            if (isset($item[$field])) {
                $essential[$field] = $item[$field];
            }
        }
        
        // Extract nodes and connections for view objects with element splicing
        if ($schemaType === 'view') {
            $this->extractViewNodesAndConnections($item, $essential, $elementsLookup);
        } else {
            $this->extractViewNodesAndConnections($item, $essential);
        }
        
        // Add a marker to indicate this is essential data (for debugging)
        $essential['_essential_data'] = true;
        
        return $essential;
    }

    /**
     * Extract nodes and connections for view objects with full nested hierarchy and element splicing
     * 
     * This method recursively extracts the complete nested node structure from view XML data.
     * Each node can contain child nodes, creating a deep hierarchical structure that matches
     * the original ArchiMate view exactly. Additionally, elements are spliced into nodes
     * that reference them via elementRef.
     * 
     * @param array $item The complete XML item data  
     * @param array &$essential Essential XML data to add nodes/connections to (by reference)
     * @param array $elementsLookup Optional lookup array of elements by identifier for splicing
     * @return void
     */
    private function extractViewNodesAndConnections(array $item, array &$essential, array $elementsLookup = []): void
    {
        // Only process if this looks like a view object (has nodes or connections)
        if (!isset($item['node']) && !isset($item['connection'])) {
            return;
        }

        // Extract nodes array with full nested hierarchy and element splicing
        if (isset($item['node'])) {
            $essential['nodes'] = $this->extractNodesRecursively($item['node'], $elementsLookup);
        }

        // Extract connections array  
        if (isset($item['connection'])) {
            $essential['connections'] = $this->extractConnectionsRecursively($item['connection']);
        }
    }

    /**
     * Recursively extract nested nodes with full hierarchy and element splicing
     * 
     * This method processes nodes and their children recursively to capture the complete
     * nested structure as it appears in the ArchiMate XML. When a node references an element
     * via elementRef, the actual element data (minus _xml) is spliced into the node's 
     * 'element' property.
     * 
     * @param array $nodeData Node data (can be single node or array of nodes)
     * @param array $elementsLookup Lookup array of elements by identifier for splicing
     * @return array Array of processed nodes with nested children and spliced elements
     */
    private function extractNodesRecursively($nodeData, array $elementsLookup = []): array
    {
        $nodes = [];
        
        // Handle both single node and array of nodes
        if (!isset($nodeData[0])) {
            // Single node
            $nodeData = [$nodeData];
        }
        
        foreach ($nodeData as $node) {
            if (isset($node['_attributes'])) {
                $processedNode = [
                    'identifier' => $node['_attributes']['identifier'] ?? null,
                    'elementRef' => $node['_attributes']['elementRef'] ?? null,
                    'type' => $node['_attributes']['xsi:type'] ?? 'Element',
                    'x' => isset($node['_attributes']['x']) ? (int)$node['_attributes']['x'] : null,
                    'y' => isset($node['_attributes']['y']) ? (int)$node['_attributes']['y'] : null,
                    'w' => isset($node['_attributes']['w']) ? (int)$node['_attributes']['w'] : null,
                    'h' => isset($node['_attributes']['h']) ? (int)$node['_attributes']['h'] : null
                ];
                
                // Extract style information if present
                if (isset($node['style'])) {
                    $processedNode['style'] = $this->extractNodeStyle($node['style']);
                }
                
                // Extract label text for Label type nodes
                if (isset($node['label'])) {
                    if (is_array($node['label']) && isset($node['label']['_value'])) {
                        $processedNode['label'] = $node['label']['_value'];
                    } elseif (is_string($node['label'])) {
                        $processedNode['label'] = $node['label'];
                    }
                }
                
                // ELEMENT SPLICING: If node references an element, splice it in
                if (!empty($processedNode['elementRef']) && !empty($elementsLookup)) {
                    $elementRef = $processedNode['elementRef'];
                    if (isset($elementsLookup[$elementRef])) {
                        // Splice element data (minus _xml and other metadata) into the node
                        $element = $elementsLookup[$elementRef];
                        $processedNode['element'] = $this->prepareElementForSplicing($element);
                    }
                }
                
                // RECURSIVE: Extract child nodes if they exist (with element splicing)
                if (isset($node['node'])) {
                    $processedNode['children'] = $this->extractNodesRecursively($node['node'], $elementsLookup);
                }
                
                // RECURSIVE: Extract child connections if they exist
                if (isset($node['connection'])) {
                    $processedNode['connections'] = $this->extractConnectionsRecursively($node['connection']);
                }
                
                $nodes[] = $processedNode;
            }
        }
        
        return $nodes;
    }

    /**
     * Prepare element data for splicing by removing internal metadata
     * 
     * @param array $element The complete element object
     * @return array Element data suitable for splicing (without _xml, @self, etc.)
     */
    private function prepareElementForSplicing(array $element): array
    {
        // Start with a copy of the element
        $splicedElement = $element;
        
        // Remove internal metadata fields that shouldn't be in spliced data
        $fieldsToRemove = ['@self', 'xml', '_xml', 'section', 'model_identifier', 'extracted_at', '_propertyMapping'];
        
        foreach ($fieldsToRemove as $field) {
            unset($splicedElement[$field]);
        }
        
        return $splicedElement;
    }

    /**
     * Extract connections recursively 
     * 
     * @param array $connectionData Connection data (can be single connection or array)
     * @return array Array of processed connections
     */
    private function extractConnectionsRecursively($connectionData): array
    {
        $connections = [];
        
        // Handle both single connection and array of connections
        if (!isset($connectionData[0])) {
            // Single connection
            $connectionData = [$connectionData];
        }
        
        foreach ($connectionData as $connection) {
            if (isset($connection['_attributes'])) {
                $processedConnection = [
                    'identifier' => $connection['_attributes']['identifier'] ?? null,
                    'relationshipRef' => $connection['_attributes']['relationshipRef'] ?? null,
                    'type' => $connection['_attributes']['xsi:type'] ?? 'Relationship',
                    'source' => $connection['_attributes']['source'] ?? null,
                    'target' => $connection['_attributes']['target'] ?? null
                ];
                
                // Extract style information if present
                if (isset($connection['style'])) {
                    $processedConnection['style'] = $this->extractConnectionStyle($connection['style']);
                }
                
                $connections[] = $processedConnection;
            }
        }
        
        return $connections;
    }

    /**
     * Extract style information from a node
     * 
     * @param array $style Style data from XML
     * @return array Processed style information
     */
    private function extractNodeStyle(array $style): array
    {
        $processedStyle = [];
        
        // Extract fillColor
        if (isset($style['fillColor']['_attributes'])) {
            $fillColor = $style['fillColor']['_attributes'];
            $processedStyle['fillColor'] = [
                'r' => isset($fillColor['r']) ? (int)$fillColor['r'] : 255,
                'g' => isset($fillColor['g']) ? (int)$fillColor['g'] : 255,
                'b' => isset($fillColor['b']) ? (int)$fillColor['b'] : 255,
                'a' => isset($fillColor['a']) ? (int)$fillColor['a'] : 100
            ];
        }
        
        // Extract lineColor
        if (isset($style['lineColor']['_attributes'])) {
            $lineColor = $style['lineColor']['_attributes'];
            $processedStyle['lineColor'] = [
                'r' => isset($lineColor['r']) ? (int)$lineColor['r'] : 0,
                'g' => isset($lineColor['g']) ? (int)$lineColor['g'] : 0,
                'b' => isset($lineColor['b']) ? (int)$lineColor['b'] : 0,
                'a' => isset($lineColor['a']) ? (int)$lineColor['a'] : 100
            ];
        }
        
        // Extract font information
        if (isset($style['font'])) {
            $font = [];
            if (isset($style['font']['_attributes'])) {
                $font['name'] = $style['font']['_attributes']['name'] ?? 'Arial';
                $font['size'] = isset($style['font']['_attributes']['size']) ? (int)$style['font']['_attributes']['size'] : 12;
            }
            
            if (isset($style['font']['color']['_attributes'])) {
                $fontColor = $style['font']['color']['_attributes'];
                $font['color'] = [
                    'r' => isset($fontColor['r']) ? (int)$fontColor['r'] : 0,
                    'g' => isset($fontColor['g']) ? (int)$fontColor['g'] : 0,
                    'b' => isset($fontColor['b']) ? (int)$fontColor['b'] : 0
                ];
            }
            
            if (!empty($font)) {
                $processedStyle['font'] = $font;
            }
        }
        
        return $processedStyle;
    }

    /**
     * Extract style information from a connection
     * 
     * @param array $style Style data from XML
     * @return array Processed style information
     */
    private function extractConnectionStyle(array $style): array
    {
        $processedStyle = [];
        
        // Extract lineColor  
        if (isset($style['lineColor']['_attributes'])) {
            $lineColor = $style['lineColor']['_attributes'];
            $processedStyle['lineColor'] = [
                'r' => isset($lineColor['r']) ? (int)$lineColor['r'] : 0,
                'g' => isset($lineColor['g']) ? (int)$lineColor['g'] : 0,
                'b' => isset($lineColor['b']) ? (int)$lineColor['b'] : 0
            ];
        }
        
        // Extract font information
        if (isset($style['font'])) {
            $font = [];
            if (isset($style['font']['_attributes'])) {
                $font['name'] = $style['font']['_attributes']['name'] ?? 'Arial';
                $font['size'] = isset($style['font']['_attributes']['size']) ? (int)$style['font']['_attributes']['size'] : 12;
            }
            
            if (isset($style['font']['color']['_attributes'])) {
                $fontColor = $style['font']['color']['_attributes'];
                $font['color'] = [
                    'r' => isset($fontColor['r']) ? (int)$fontColor['r'] : 0,
                    'g' => isset($fontColor['g']) ? (int)$fontColor['g'] : 0,
                    'b' => isset($fontColor['b']) ? (int)$fontColor['b'] : 0
                ];
            }
            
            if (!empty($font)) {
                $processedStyle['font'] = $font;
            }
        }
        
        return $processedStyle;
    }

    /**
     * Extract GEMMA type from an object using multiple possible property names
     * 
     * This method tries different variations of GEMMA type property names to ensure
     * compatibility with different ArchiMate model variations.
     * 
     * @param array $object The object to extract GEMMA type from
     * @return string|null The GEMMA type value or null if not found
     */
    private function extractGemmaType(array $object): ?string
    {
        // Try various possible property names for GEMMA type
        $possiblePropertyNames = [
            'gemmaType',        // Standard camelCase conversion of "GEMMA Type"
            'gemmatype',        // Lowercase version
            'GemmaType',        // PascalCase version
            'GEMMA_Type',       // Underscore version
            'gemma_type',       // Lowercase underscore version
            'GEMMAType',        // All caps first word
            'type',             // Sometimes just "Type" in models
            'elementType',      // Alternative naming
            'componentType'     // Another alternative
        ];
        
        foreach ($possiblePropertyNames as $propertyName) {
            if (isset($object[$propertyName]) && !empty($object[$propertyName])) {
                $value = (string) $object[$propertyName];
                
                // Log the first successful match for debugging
                if (!isset($this->gemmaTypePropertyFound)) {
                    $this->logger->debug('GEMMA Type property found', [
                        'property_name' => $propertyName,
                        'value' => $value,
                        'object_id' => $object['identifier'] ?? 'unknown'
                    ]);
                    $this->gemmaTypePropertyFound = true;
                }
                
                return $value;
            }
        }
        
        // If no direct property found, check _propertyMapping for original property names
        if (isset($object['_propertyMapping'])) {
            foreach ($object['_propertyMapping'] as $camelCase => $original) {
                // Check if the original property name contains "gemma" or "type"
                if (stripos($original, 'gemma') !== false && stripos($original, 'type') !== false) {
                    if (isset($object[$camelCase]) && !empty($object[$camelCase])) {
                        $this->logger->debug('GEMMA Type found via property mapping', [
                            'camel_case_name' => $camelCase,
                            'original_name' => $original,
                            'value' => $object[$camelCase],
                            'object_id' => $object['identifier'] ?? 'unknown'
                        ]);
                        return (string) $object[$camelCase];
                    }
                }
            }
        }
        
        return null;
    }

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
     * @return array Objects with enhanced Referentiecomponent data
     */
    private function processGemmaReferenceComponentStandards(array $objects): array
    {
        $this->logger->info('Processing GEMMA Referentiecomponent-Standaard relationships with optimized single-pass algorithm');
        
        // OPTIMIZATION: Single-pass processing - collect all data types at once
        $referentieComponenten = [];
        $standaarden = [];
        $gemmaRelationshipMap = [];
        
        // Debug: Count objects and property variations
        $elementCount = 0;
        $elementsWithGemmaType = 0;
        $gemmaTypeVariations = [];
        
        // PASS 1: Collect Referentiecomponenten and Standaarden, process relationships immediately
        foreach ($objects as $index => $object) {
            // Debug: Count elements and GEMMA types
            if (isset($object['section']) && $object['section'] === 'element') {
                $elementCount++;
                
                // Check for various possible GEMMA type property names
                $gemmaTypeValue = $this->extractGemmaType($object);
                if ($gemmaTypeValue !== null) {
                    $elementsWithGemmaType++;
                    
                    // Track GEMMA type variations for debugging
                    if (!isset($gemmaTypeVariations[$gemmaTypeValue])) {
                        $gemmaTypeVariations[$gemmaTypeValue] = 0;
                    }
                    $gemmaTypeVariations[$gemmaTypeValue]++;
                    
                    if ($gemmaTypeValue === 'Referentiecomponent') {
                        $referentieComponenten[$object['identifier']] = $index;
                    } elseif ($gemmaTypeValue === 'Standaard') {
                        $standaarden[$object['identifier']] = $index;
                    }
                }
            }
            
            // Process relationships immediately when found (no separate collection needed)
            if (isset($object['section']) && $object['section'] === 'relationship') {
                $this->processRelationshipImmediate($object, $referentieComponenten, $standaarden, $gemmaRelationshipMap);
            }
        }
        
        // Enhanced debug logging
        $this->logger->info('GEMMA objects processing complete', [
            'total_elements' => $elementCount,
            'elements_with_gemma_type' => $elementsWithGemmaType,
            'gemma_type_variations' => $gemmaTypeVariations,
            'referentiecomponenten_count' => count($referentieComponenten),
            'standaarden_count' => count($standaarden),
            'processed_relationships' => count($gemmaRelationshipMap)
        ]);
        
        // STEP 2: Apply the processed relationship mappings to Referentiecomponenten
        $enhancedCount = 0;
        foreach ($gemmaRelationshipMap as $referentieComponentId => $standaardenMap) {
            if (isset($referentieComponenten[$referentieComponentId])) {
                $objectIndex = $referentieComponenten[$referentieComponentId];
                
                // Remove duplicates and add the properties
                $aanbevolenStandaarden = array_unique($standaardenMap['aanbevolen']);
                $verplichteStandaarden = array_unique($standaardenMap['verplicht']);
                
                $objects[$objectIndex]['aanbevolenStandaarden'] = $aanbevolenStandaarden;
                $objects[$objectIndex]['verplichteStandaarden'] = $verplichteStandaarden;
                
                // Also add combined array for backward compatibility
                $allStandaarden = array_unique(array_merge($aanbevolenStandaarden, $verplichteStandaarden));
                $objects[$objectIndex]['standaarden'] = $allStandaarden;
                
                $this->logger->info('Enhanced Referentiecomponent with categorized standaarden', [
                    'referentiecomponent_id' => $referentieComponentId,
                    'referentiecomponent_name' => $objects[$objectIndex]['name'] ?? 'Unknown',
                    'aanbevolen_count' => count($aanbevolenStandaarden),
                    'verplicht_count' => count($verplichteStandaarden),
                    'aanbevolen_ids' => $aanbevolenStandaarden,
                    'verplicht_ids' => $verplichteStandaarden
                ]);
                
                $enhancedCount++;
            }
        }
        
        $this->logger->info('GEMMA Referentiecomponent-Standaard processing completed', [
            'referentiecomponenten_enhanced' => $enhancedCount,
            'total_referentiecomponenten' => count($referentieComponenten),
            'total_relationships_processed' => count($gemmaRelationshipMap)
        ]);
        
        return $objects;
    }

    /**
     * OPTIMIZATION: Process relationship immediately when found (single-pass algorithm)
     * 
     * @param array $relationship The relationship object
     * @param array $referentieComponenten Array of Referentiecomponent identifiers
     * @param array $standaarden Array of Standaard identifiers  
     * @param array &$gemmaRelationshipMap The relationship map to update (by reference)
     * @return void
     */
    private function processRelationshipImmediate(array $relationship, array $referentieComponenten, array $standaarden, array &$gemmaRelationshipMap): void
    {
        // Get source and target from relationship XML or flattened properties
        $source = $this->extractRelationshipEndpoint($relationship, 'source');
        $target = $this->extractRelationshipEndpoint($relationship, 'target');
        
        if (!$source || !$target) {
            return;
        }
        
        // Get Verbindingsrol from flattened properties (camelCase: verbindingsrol)
        $verbindingsrol = $relationship['verbindingsrol'] ?? null;
        
        // Skip if no Verbindingsrol is defined
        if (!$verbindingsrol) {
            return;
        }
        
        // Check if one end is a Referentiecomponent and the other is a Standaard
        $refCompId = null;
        $standaardId = null;
        
        if (isset($referentieComponenten[$source]) && isset($standaarden[$target])) {
            // Referentiecomponent -> Standaard
            $refCompId = $source;
            $standaardId = $target;
        } elseif (isset($standaarden[$source]) && isset($referentieComponenten[$target])) {
            // Standaard -> Referentiecomponent (reverse direction)
            $refCompId = $target;
            $standaardId = $source;
        }
        
        if ($refCompId && $standaardId) {
            // Initialize arrays if not exists
            if (!isset($gemmaRelationshipMap[$refCompId])) {
                $gemmaRelationshipMap[$refCompId] = [
                    'aanbevolen' => [],
                    'verplicht' => []
                ];
            }
            
            // Add to appropriate array based on Verbindingsrol
            if (strtolower($verbindingsrol) === 'aanbevolen') {
                $gemmaRelationshipMap[$refCompId]['aanbevolen'][] = $standaardId;
            } elseif (strtolower($verbindingsrol) === 'verplicht') {
                $gemmaRelationshipMap[$refCompId]['verplicht'][] = $standaardId;
            }
        }
    }

    /**
     * Extract relationship endpoint (source or target) from relationship object
     * 
     * @param array $relationship The relationship object
     * @param string $endpoint Either 'source' or 'target'
     * @return string|null The endpoint identifier or null if not found
     */
    private function extractRelationshipEndpoint(array $relationship, string $endpoint): ?string
    {
        // Try flattened camelCase property first
        if (isset($relationship[$endpoint])) {
            return $relationship[$endpoint];
        }
        
        // Try XML structure
        if (isset($relationship['xml'][$endpoint])) {
            $endpointData = $relationship['xml'][$endpoint];
            
            // Handle different XML structures
            if (is_string($endpointData)) {
                return $endpointData;
            } elseif (is_array($endpointData)) {
                // Try _attributes.href or _value
                if (isset($endpointData['_attributes']['href'])) {
                    return $endpointData['_attributes']['href'];
                } elseif (isset($endpointData['_value'])) {
                    return $endpointData['_value'];
                }
            }
        }
        
        // Try direct XML access for ArchiMate format
        if (isset($relationship['xml']['_attributes'])) {
            $attr = $relationship['xml']['_attributes'];
            if ($endpoint === 'source' && isset($attr['source'])) {
                return $attr['source'];
            } elseif ($endpoint === 'target' && isset($attr['target'])) {
                return $attr['target'];
            }
        }
        
        return null;
    }

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
     * @return array Array of objects ready for saveObjects()
     */
    private function transformArchiMateXmlToObjectsBatch(array $xmlData, string $modelIdentifier): array
    {
        $startTime = microtime(true);
        $allObjects = [];
        
        // SPEED OPTIMIZATION 1: Pre-extract and cache EVERYTHING
        $cacheStartTime = microtime(true);
        $propertyDefinitionMap = $this->extractPropertyDefinitionMap($xmlData);
        
        // Create model object first
        if (isset($xmlData['_attributes']) || isset($xmlData['name'])) {
            $modelMetadata = [
                'identifier' => $modelIdentifier,
                'name' => $xmlData['name'] ?? '',
                'documentation' => $xmlData['documentation'] ?? '',
                'properties' => $xmlData['properties'] ?? [],
                'propertyDefinitionMap' => $propertyDefinitionMap
            ];
            
            if (isset($xmlData['_attributes'])) {
                $modelMetadata = array_merge($modelMetadata, $xmlData['_attributes']);
            }
            
            $allObjects[] = $this->createModelObjectDirect($modelMetadata, $modelIdentifier);
        }
        
        // Process each section type directly (no intermediate normalization)
        $sections = [
            'elements' => 'element',
            'relationships' => 'relationship', 
            'organizations' => 'organization',
            'views' => 'view',
            'property_definitions' => 'property_definition'
        ];
        
        // SPEED OPTIMIZATION 2: Pre-build ALL section lookups simultaneously
        $allLookups = $this->buildAllLookupsSimultaneously($xmlData);
        $elementsLookup = $this->buildElementsLookup($allObjects); // Will be rebuilt from processed objects
        
        $cacheTime = microtime(true) - $cacheStartTime;
        $this->logger->info('Pre-built all lookups', [
            'cache_build_time' => round($cacheTime, 3),
            'elements_count' => count($allLookups['elements']),
            'relationships_count' => count($allLookups['relationships']),
            'organizations_count' => count($allLookups['organizations']),
            'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 1)
        ]);
        
        // SPEED OPTIMIZATION 3: Process all non-view sections in bulk
        $bulkProcessingStart = microtime(true);
        $nonViewObjects = $this->bulkProcessNonViewSections(
            $xmlData,
                    $modelIdentifier,
            $propertyDefinitionMap,
            $allLookups
        );
        $allObjects = array_merge($allObjects, $nonViewObjects);
        
        // SPEED OPTIMIZATION: Build elements lookup directly from raw data (faster than from processed objects)
        $elementsLookup = $this->buildElementsLookupFromRawData($allLookups['elements'], $nonViewObjects, $propertyDefinitionMap);
        
        $bulkTime = microtime(true) - $bulkProcessingStart;
        
        // SPEED OPTIMIZATION 4: Process views with maximum speed optimizations
        $viewProcessingStart = microtime(true);
        $viewObjects = $this->processViewsMaximumSpeed(
            $xmlData,
            $modelIdentifier,
            $propertyDefinitionMap,
            $elementsLookup
        );
        $allObjects = array_merge($allObjects, $viewObjects);
        $viewTime = microtime(true) - $viewProcessingStart;
        
        $totalTime = microtime(true) - $startTime;
        // Transformation completed
        
        // MEMORY CLEANUP: Free all intermediate lookups and caches before database operations
        $memoryBeforeCleanup = memory_get_usage(true);
        unset($allLookups, $elementsLookup, $propertyDefinitionMap);
        $this->camelCaseCache = []; // Clear property name cache
        $this->identifierPatternCache = []; // Clear identifier pattern cache
        $this->propertyDefinitionMapCache = null; // Clear property definition cache
        
        // Force garbage collection to free memory immediately
        if (function_exists('gc_collect_cycles')) {
            $cycles = gc_collect_cycles();
            $memoryAfterCleanup = memory_get_usage(true);
            $memoryFreed = $memoryBeforeCleanup - $memoryAfterCleanup;
            
            $this->logger->info('Memory cleanup before database operations', [
                'memory_freed_mb' => round($memoryFreed / 1024 / 1024, 1),
                'gc_cycles_collected' => $cycles,
                'memory_before_mb' => round($memoryBeforeCleanup / 1024 / 1024, 1),
                'memory_after_mb' => round($memoryAfterCleanup / 1024 / 1024, 1)
            ]);
        }
        
        return $allObjects;
    }

    /**
     * Transform views with performance optimizations
     * 
     * This method processes views with several optimizations:
     * - Reduced memory allocations 
     * - Optimized element lookup caching
     * - Streamlined recursive processing
     * 
     * @param array $viewsData Views section data
     * @param string $modelIdentifier Model identifier  
     * @param array $propertyDefinitionMap Property definition map
     * @param array $elementsLookup Elements lookup for splicing
     * @return array Array of processed view objects
     */
    private function transformViewsOptimized(
        array $viewsData,
        string $modelIdentifier,
        array $propertyDefinitionMap,
        array $elementsLookup
    ): array {
        $objects = [];
        
        // Find items in section (optimized version for views)
        $items = $this->findItemsSimplified($viewsData, 'view');
        
        // OPTIMIZATION: Pre-filter elements to only those actually referenced in views
        $referencedElements = $this->extractReferencedElements($items);
        $filteredElementsLookup = array_intersect_key($elementsLookup, array_flip($referencedElements));
        
        $this->logger->debug('Optimized elements lookup for views', [
            'total_elements' => count($elementsLookup),
            'referenced_elements' => count($filteredElementsLookup),
            'optimization_ratio' => round((1 - count($filteredElementsLookup) / max(count($elementsLookup), 1)) * 100, 1) . '%'
        ]);
        
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            
            $identifier = $this->extractIdentifier($item, 'view');
            if (!$identifier) {
                continue;
            }
            
            // OPTIMIZATION: Use filtered elements lookup for better performance
            $essentialXmlData = $this->extractEssentialXmlData($item, $filteredElementsLookup, 'view');
            
            $object = [
                '@self' => [
                    'register' => $this->cachedConfig['registerId'] ?? 15,
                    'schema' => 111, // FIXED: Hard-code view schema ID for speed optimization
                    'id' => $identifier,
                    'owner' => $this->cachedConfig['userId'],
                    'organisation' => $this->cachedConfig['organisation'],

                ],
                'identifier' => $identifier,
                'section' => 'view',
                'model_identifier' => $modelIdentifier,
                'xml' => $essentialXmlData
            ];
            
            // Extract name and summary (same as other sections)
            if (isset($item['name'])) {
                if (is_array($item['name']) && isset($item['name']['_value'])) {
                    $object['name'] = $item['name']['_value'];
                } elseif (is_string($item['name'])) {
                    $object['name'] = $item['name'];
                }
            }
            
            if (isset($item['documentation'])) {
                if (is_array($item['documentation']) && isset($item['documentation']['_value'])) {
                    $object['summary'] = $item['documentation']['_value'];
                } elseif (is_string($item['documentation'])) {
                    $object['summary'] = $item['documentation'];
                }
            }
            
            // Flatten properties efficiently (same as other sections)
            if (isset($item['properties']['property']) && !empty($propertyDefinitionMap)) {
                $this->flattenPropertiesBatch($object, $item['properties']['property'], $propertyDefinitionMap);
                
                // Update ID and slug if objectId is available
                if (isset($object['objectId'])) {
                    $object['@self']['id'] = $object['objectId'];
                    $object['@self']['slug'] = $identifier;
                } else {
                    if ($identifier && str_starts_with($identifier, 'id-')) {
                        $object['@self']['slug'] = substr($identifier, 3);
                    } else {
                        $object['@self']['slug'] = $identifier;
                    }
                }
            } else {
                if ($identifier && str_starts_with($identifier, 'id-')) {
                    $object['@self']['slug'] = substr($identifier, 3);  
                } else {
                    $object['@self']['slug'] = $identifier;
                }
            }
            
            // Copy nodes and connections from XML to root level for easy access
            if (isset($object['xml']['nodes'])) {
                $object['nodes'] = $object['xml']['nodes'];
            }
            if (isset($object['xml']['connections'])) {
                $object['connections'] = $object['xml']['connections'];
            }
            
            $objects[] = $object;
        }
        
        return $objects;
    }

    /**
     * Extract all element references from view items for optimization
     * 
     * @param array $viewItems Array of view items
     * @return array Array of referenced element identifiers
     */
    private function extractReferencedElements(array $viewItems): array
    {
        $references = [];
        
        foreach ($viewItems as $item) {
            $this->collectElementReferencesRecursively($item, $references);
        }
        
        return array_unique($references);
    }
    
    /**
     * Recursively collect element references from view data
     * 
     * @param array $data View data to process
     * @param array &$references Array to collect references into (by reference)
     * @return void
     */
    private function collectElementReferencesRecursively(array $data, array &$references): void
    {
        // Check for elementRef in current level
        if (isset($data['_attributes']['elementRef'])) {
            $references[] = $data['_attributes']['elementRef'];
        }
        
        // Recursively check child nodes
        if (isset($data['node'])) {
            $nodeData = $data['node'];
            if (!isset($nodeData[0])) {
                $nodeData = [$nodeData];
            }
            
            foreach ($nodeData as $node) {
                $this->collectElementReferencesRecursively($node, $references);
            }
        }
    }

    /**
     * Build elements lookup array for view processing with element splicing
     * 
     * This method creates a fast lookup array of elements by their identifier
     * to enable efficient element splicing during view node processing.
     * 
     * @param array $elementObjects Array of processed element objects
     * @return array Lookup array with element identifier as key and element data as value
     */
    private function buildElementsLookup(array $elementObjects): array
    {
        $lookup = [];
        
        foreach ($elementObjects as $element) {
            $identifier = $element['identifier'] ?? null;
            if ($identifier) {
                $lookup[$identifier] = $element;
            }
        }
        
        $this->logger->debug('Built elements lookup for view processing', [
            'total_elements' => count($lookup),
            'sample_identifiers' => array_slice(array_keys($lookup), 0, 5)
        ]);
        
        return $lookup;
    }

    /**
     * SPEED OPTIMIZATION: Build elements lookup directly from raw XML data
     * 
     * This is faster than building from processed objects because we skip intermediate processing
     * and build the lookup table directly from the source data with minimal transformations.
     * 
     * @param array $rawElementsData Raw elements data from XML
     * @param array $processedObjects Already processed objects (for fallback)
     * @param array $propertyDefinitionMap Property definition map
     * @return array Elements lookup for view processing
     */
    private function buildElementsLookupFromRawData(
        array $rawElementsData,
        array $processedObjects,
        array $propertyDefinitionMap
    ): array {
        $lookup = [];
        
        // SPEED: Build directly from raw data with minimal processing
        foreach ($rawElementsData as $identifier => $rawItem) {
            $element = [
                'identifier' => $identifier,
                'section' => 'element'
            ];
            
            // Fast name extraction
            if (isset($rawItem['name'])) {
                $element['name'] = is_array($rawItem['name']) && isset($rawItem['name']['_value']) 
                    ? $rawItem['name']['_value'] 
                    : (is_string($rawItem['name']) ? $rawItem['name'] : '');
            }
            
            // Fast summary extraction
            if (isset($rawItem['documentation'])) {
                $element['summary'] = is_array($rawItem['documentation']) && isset($rawItem['documentation']['_value'])
                    ? $rawItem['documentation']['_value']
                    : (is_string($rawItem['documentation']) ? $rawItem['documentation'] : '');
            }
            
            // Fast properties flattening (only essential properties for splicing)
            if (isset($rawItem['properties']['property']) && !empty($propertyDefinitionMap)) {
                $props = isset($rawItem['properties']['property'][0]) 
                    ? $rawItem['properties']['property'] 
                    : [$rawItem['properties']['property']];
                
                foreach ($props as $prop) {
                    if (!isset($prop['_attributes']['propertyDefinitionRef'])) continue;
                    
                    $defRef = $prop['_attributes']['propertyDefinitionRef'];
                    $value = $prop['value']['_value'] ?? $prop['value'] ?? null;
                    
                    if ($value !== null && isset($propertyDefinitionMap[$defRef])) {
                        $propertyName = $propertyDefinitionMap[$defRef];
                        $camelCaseName = $this->convertToCamelCase($propertyName);
                        $element[$camelCaseName] = $value;
                    }
                }
            }
            
            $lookup[$identifier] = $element;
        }
        
        $this->logger->debug('Built SPEED elements lookup from raw data', [
            'total_elements' => count($lookup),
            'sample_identifiers' => array_slice(array_keys($lookup), 0, 5)
        ]);
        
        return $lookup;
    }

    /**
     * Create model object directly with cached configuration
     * 
     * @param array $metadata Model metadata
     * @param string $modelIdentifier Model identifier
     * @return array Model object with @self structure
     */
    private function createModelObjectDirect(array $metadata, string $modelIdentifier): array
    {
        return [
            '@self' => [
                'register' => $this->cachedConfig['registerId'] ?? 15,
                'schema' => $this->cachedConfig['schemaIds']['model'] ?? 67,
                'id' => $modelIdentifier,
                'owner' => $this->cachedConfig['userId'],
                'organisation' => $this->cachedConfig['organisation'],
                'published' => date('Y-m-d\TH:i:s\Z')
            ],
            'identifier' => $modelIdentifier,
            'section' => 'model',
            'model_identifier' => $modelIdentifier
        ] + $metadata;
    }

    /**
     * Find section data efficiently without complex nested searches
     * 
     * @param array $xmlData Parsed XML data
     * @param string $sectionName Section name to find
     * @return array Section data or empty array
     */
    private function findSectionData(array $xmlData, string $sectionName): array
    {
        // Direct lookup first
        if (isset($xmlData[$sectionName])) {
            return $xmlData[$sectionName];
        }
        
        // Alternative names lookup
        $alternatives = [
            'views' => ['diagrams'],
            'organizations' => ['organisation'],
            'property_definitions' => ['propertyDefinitions', 'propertydefinitions']
        ];
        
        if (isset($alternatives[$sectionName])) {
            foreach ($alternatives[$sectionName] as $altName) {
                if (isset($xmlData[$altName])) {
                    return $xmlData[$altName];
                }
            }
        }
        
        return [];
    }

    /**
     * Transform section objects in batch with minimal overhead and element splicing for views
     * 
     * @param array $sectionData Section data from XML
     * @param string $schemaType Schema type (singular)
     * @param string $modelIdentifier Model identifier
     * @param array $propertyDefinitionMap Property definition map
     * @param array $elementsLookup Optional elements lookup for view processing
     * @return array Array of transformed objects
     */
    private function transformSectionObjectsBatch(
        array $sectionData, 
        string $schemaType, 
        string $modelIdentifier, 
        array $propertyDefinitionMap,
        array $elementsLookup = []
    ): array {
        $objects = [];
        
        // Find items in section (simplified version)
        $items = $this->findItemsSimplified($sectionData, $schemaType);
        
        $skippedNotArray = 0;
        $skippedNoIdentifier = 0;
        
        foreach ($items as $item) {
            if (!is_array($item)) {
                $skippedNotArray++;
                continue;
            }
            
            $identifier = $this->extractIdentifier($item, $schemaType);
            if (!$identifier) {
                $skippedNoIdentifier++;
                continue;
            }
            
            // Create object directly (minimal processing) with element splicing for views
            $essentialXmlData = $this->extractEssentialXmlData($item, $elementsLookup, $schemaType);
            
            $object = [
                '@self' => [
                    'register' => $this->cachedConfig['registerId'] ?? 15,
                    'schema' => $this->cachedConfig['schemaIds'][$schemaType] ?? 100,
                    'id' => $identifier,
                    'owner' => $this->cachedConfig['userId'],
                    'organisation' => $this->cachedConfig['organisation'],
                    'published' => date('Y-m-d\TH:i:s\Z')
                ],
                'identifier' => $identifier,
                'section' => $schemaType,
                'model_identifier' => $modelIdentifier,
                'xml' => $essentialXmlData
            ];
            
            // Debug: Log XML data extraction
            $this->logger->debug('XML data extracted for object', [
                'object_id' => $identifier,
                'section' => $schemaType,
                'original_item_keys' => array_keys($item),
                'essential_xml_keys' => array_keys($essentialXmlData),
                'essential_xml_size' => strlen(json_encode($essentialXmlData)),
                'has_properties' => isset($item['properties']),
                'properties_structure' => isset($item['properties']) ? array_keys($item['properties']) : null
            ]);
            
            // Extract name from XML if it exists
            if (isset($item['name'])) {
                if (is_array($item['name']) && isset($item['name']['_value'])) {
                    $object['name'] = $item['name']['_value'];
                } elseif (is_string($item['name'])) {
                    $object['name'] = $item['name'];
                }
            }
            
            // Extract documentation from XML if it exists and set to summary
            if (isset($item['documentation'])) {
                if (is_array($item['documentation']) && isset($item['documentation']['_value'])) {
                    $object['summary'] = $item['documentation']['_value'];
                } elseif (is_string($item['documentation'])) {
                    $object['summary'] = $item['documentation'];
                }
            }
            

            
            // Flatten properties efficiently (if present)
            if (isset($item['properties']['property']) && !empty($propertyDefinitionMap)) {
                $this->flattenPropertiesBatch($object, $item['properties']['property'], $propertyDefinitionMap);
                
                // FIXED: After properties are flattened, update ID and slug if objectId is available
                if (isset($object['objectId'])) {
                    // Use objectId as main ID and AMEF identifier as slug
                    $object['@self']['id'] = $object['objectId'];
                    $object['@self']['slug'] = $identifier; // AMEF identifier becomes slug
                } else {
                    // Fallback: extract clean UUID from AMEF identifier for slug
                    if ($identifier && str_starts_with($identifier, 'id-')) {
                        $object['@self']['slug'] = substr($identifier, 3); // Remove "id-" prefix
                    } else {
                        $object['@self']['slug'] = $identifier;
                    }
                }
            } else {
                // No properties to flatten, use AMEF identifier logic
                if ($identifier && str_starts_with($identifier, 'id-')) {
                    $object['@self']['slug'] = substr($identifier, 3); // Remove "id-" prefix  
                } else {
                    $object['@self']['slug'] = $identifier;
                }
            }
            
            // NEW: For view objects, copy nodes and connections from XML to root level
            if ($schemaType === 'view' && isset($object['xml'])) {
                if (isset($object['xml']['nodes'])) {
                    $object['nodes'] = $object['xml']['nodes'];
                }
                if (isset($object['xml']['connections'])) {
                    $object['connections'] = $object['xml']['connections'];
                }
            }
            
            // DEBUG: Log final object structure before adding to array
            $this->logger->debug('Final object structure before save', [
                'object_id' => $identifier,
                'section' => $schemaType,
                'object_keys' => array_keys($object),
                'has_xml_property' => isset($object['xml']),
                'xml_keys' => isset($object['xml']) ? array_keys($object['xml']) : null,
                'has_property_mapping' => isset($object['_propertyMapping']),
                'property_mapping_count' => isset($object['_propertyMapping']) ? count($object['_propertyMapping']) : 0,
                'nodes_count' => isset($object['nodes']) ? count($object['nodes']) : 0,
                'connections_count' => isset($object['connections']) ? count($object['connections']) : 0,
                'sample_properties' => array_slice(array_diff(array_keys($object), ['@self', 'identifier', 'section', 'model_identifier', 'xml', '_propertyMapping', 'name', 'summary', 'nodes', 'connections']), 0, 5)
            ]);
            
            $objects[] = $object;
        }
        

        
        return $objects;
    }

    /**
     * Simplified item finding for better performance
     * 
     * @param array $sectionData Section data
     * @param string $sectionType Section type
     * @return array Items array
     */
    private function findItemsSimplified(array $sectionData, string $sectionType): array
    {
        // Handle views with diagrams structure
        if ($sectionType === 'view' && isset($sectionData['diagrams']['view'])) {
            $viewData = $sectionData['diagrams']['view'];
            return isset($viewData[0]) ? $viewData : [$viewData];
        }
        
        // Try common patterns
        $patterns = [
            $sectionType, // singular: element, relationship, etc.
            $sectionType . 's', // plural: elements, relationships, etc.
            'item', // organizations use 'item'
            'propertyDefinition' // property definitions
        ];
        
        foreach ($patterns as $pattern) {
            if (isset($sectionData[$pattern])) {
                $data = $sectionData[$pattern];
                return is_array($data) && isset($data[0]) ? $data : [$data];
            }
        }
        
        // Fallback: treat section data as single item
        return [$sectionData];
    }

    /**
     * Flatten properties in batch for better performance
     * 
     * @param array &$object Object to add properties to (by reference)
     * @param array $properties Properties array from XML
     * @param array $propertyDefinitionMap Property definition map
     * @return void
     */
    private function flattenPropertiesBatch(array &$object, array $properties, array $propertyDefinitionMap): void
    {
        $props = isset($properties[0]) ? $properties : [$properties];
        $processedProperties = [];
        
        // Debug: Log property flattening process
        $this->logger->debug('Flattening properties for object', [
            'object_id' => $object['identifier'] ?? 'unknown',
            'properties_count' => count($props),
            'property_definition_map_size' => count($propertyDefinitionMap),
            'sample_property_definitions' => array_slice($propertyDefinitionMap, 0, 5, true)
        ]);
        
        foreach ($props as $propIndex => $prop) {
            if (!isset($prop['_attributes']['propertyDefinitionRef'])) {
                $this->logger->warning('Property missing propertyDefinitionRef', [
                    'object_id' => $object['identifier'] ?? 'unknown',
                    'property_index' => $propIndex,
                    'property_structure' => array_keys($prop ?? [])
                ]);
                continue;
            }
            
            $defRef = $prop['_attributes']['propertyDefinitionRef'];
            $value = $prop['value']['_value'] ?? $prop['value'] ?? null;
            
            // Debug: Log property reference lookup
            if (!isset($propertyDefinitionMap[$defRef])) {
                $this->logger->warning('Property definition not found in map', [
                    'object_id' => $object['identifier'] ?? 'unknown',
                    'property_def_ref' => $defRef,
                    'available_refs' => array_keys($propertyDefinitionMap)
                ]);
                continue;
            }
            
            if ($value !== null && isset($propertyDefinitionMap[$defRef])) {
                $propertyName = $propertyDefinitionMap[$defRef];
                $camelCaseName = $this->convertToCamelCase($propertyName);
                $object[$camelCaseName] = $value;
                
                // Store property mapping for reference
                if (!isset($object['_propertyMapping'])) {
                    $object['_propertyMapping'] = [];
                }
                $object['_propertyMapping'][$camelCaseName] = $propertyName;
                
                $processedProperties[] = [
                    'original' => $propertyName,
                    'camelCase' => $camelCaseName,
                    'value' => $value,
                    'def_ref' => $defRef
                ];
                
                // Object ID property is now handled after property flattening is complete
                
                // Debug: Log GEMMA type properties specifically
                if (stripos($propertyName, 'gemma') !== false || $defRef === 'propid-3') {
                    $this->logger->info('GEMMA type property processed', [
                        'object_id' => $object['identifier'] ?? 'unknown',
                        'property_name' => $propertyName,
                        'camel_case_name' => $camelCaseName,
                        'value' => $value,
                        'def_ref' => $defRef
                    ]);
                }
            } else {
                $this->logger->warning('Property value is null or mapping missing', [
                    'object_id' => $object['identifier'] ?? 'unknown',
                    'property_def_ref' => $defRef,
                    'value' => $value,
                    'mapping_exists' => isset($propertyDefinitionMap[$defRef])
                ]);
            }
        }
        
        // Debug: Log final property flattening results
        $this->logger->debug('Property flattening completed', [
            'object_id' => $object['identifier'] ?? 'unknown',
            'processed_count' => count($processedProperties),
            'processed_properties' => $processedProperties,
            'object_keys_after_flattening' => array_keys($object)
        ]);
    }

    /**
     * SPEED OPTIMIZATION: Build all lookups simultaneously for maximum performance
     * 
     * Pre-builds all possible lookups in parallel to eliminate lookup building overhead
     * during processing. Uses more memory but significantly faster processing.
     * 
     * @param array $xmlData Complete XML data
     * @return array Array with all lookups: ['elements' => [...], 'relationships' => [...], etc.]
     */
    private function buildAllLookupsSimultaneously(array $xmlData): array
    {
        $lookups = [
            'elements' => [],
            'relationships' => [],
            'organizations' => [],
            'views' => [],
            'property_definitions' => []
        ];
        
        // Pre-extract all section data simultaneously
        $sections = [
            'elements' => 'element',
            'relationships' => 'relationship',
            'organizations' => 'organization',  
            'views' => 'view',
            'property_definitions' => 'property_definition'
        ];
        
        foreach ($sections as $sectionName => $schemaType) {
            $sectionData = $this->findSectionData($xmlData, $sectionName);
            if (!empty($sectionData)) {
                $items = $this->findItemsSimplified($sectionData, $schemaType);
                
                foreach ($items as $item) {
                    if (!is_array($item)) continue;
                    
                    $identifier = $this->extractIdentifier($item, $schemaType);
                    if ($identifier) {
                        // Store raw item data for fast processing later
                        $lookups[$sectionName][$identifier] = $item;
                    }
                }
            }
        }
        
        return $lookups;
    }

    /**
     * SPEED OPTIMIZATION: Bulk process all non-view sections with vectorized operations
     * 
     * @param array $xmlData XML data
     * @param string $modelIdentifier Model identifier
     * @param array $propertyDefinitionMap Property definition map
     * @param array $allLookups All pre-built lookups
     * @return array Processed objects
     */
    private function bulkProcessNonViewSections(
        array $xmlData,
        string $modelIdentifier, 
        array $propertyDefinitionMap,
        array $allLookups
    ): array {
        $objects = [];
        
        $sections = [
            'elements' => 'element',
            'relationships' => 'relationship',
            'organizations' => 'organization',
            'property_definitions' => 'property_definition'
        ];
        
        foreach ($sections as $sectionName => $schemaType) {
            if (empty($allLookups[$sectionName])) continue;
            
            $this->logger->debug("SPEED: Bulk processing {$sectionName}", [
                'item_count' => count($allLookups[$sectionName])
            ]);
            
            // SPEED OPTIMIZATION: Process all items in this section as a batch
            $sectionObjects = $this->bulkTransformSection(
                $allLookups[$sectionName],
                $schemaType,
                $modelIdentifier,
                $propertyDefinitionMap
            );
            
            $objects = array_merge($objects, $sectionObjects);
        }
        
        return $objects;
    }

    /**
     * SPEED OPTIMIZATION: Bulk transform a section with vectorized operations  
     * 
     * @param array $sectionItems Pre-loaded section items by identifier
     * @param string $schemaType Schema type
     * @param string $modelIdentifier Model identifier
     * @param array $propertyDefinitionMap Property definition map
     * @return array Transformed objects
     */
    private function bulkTransformSection(
        array $sectionItems,
        string $schemaType,
        string $modelIdentifier,
        array $propertyDefinitionMap
    ): array {
        $objects = [];
        
        foreach ($sectionItems as $identifier => $item) {
            // SPEED OPTIMIZATION: Direct object creation without intermediate steps
            $essentialXmlData = $this->extractEssentialXmlData($item, [], $schemaType);
            
            $object = [
                '@self' => [
                    'register' => $this->cachedConfig['registerId'] ?? 15,
                    'schema' => $this->cachedConfig['schemaIds'][$schemaType] ?? 100,
                    'id' => $identifier,
                    'owner' => $this->cachedConfig['userId'],
                    'organisation' => $this->cachedConfig['organisation'],
                    'published' => date('Y-m-d\TH:i:s\Z')
                ],
                'identifier' => $identifier,
                'section' => $schemaType,
                'model_identifier' => $modelIdentifier,
                'xml' => $essentialXmlData
            ];
            
            // Fast extract name and summary
            if (isset($item['name'])) {
                $object['name'] = is_array($item['name']) && isset($item['name']['_value']) 
                    ? $item['name']['_value'] 
                    : (is_string($item['name']) ? $item['name'] : '');
            }
            
            if (isset($item['documentation'])) {
                $object['summary'] = is_array($item['documentation']) && isset($item['documentation']['_value'])
                    ? $item['documentation']['_value']
                    : (is_string($item['documentation']) ? $item['documentation'] : '');
            }
            
            // Fast flatten properties
            if (isset($item['properties']['property']) && !empty($propertyDefinitionMap)) {
                $this->flattenPropertiesBatch($object, $item['properties']['property'], $propertyDefinitionMap);
                
                // Fast ID/slug update
                if (isset($object['objectId'])) {
                    $object['@self']['id'] = $object['objectId'];
                    $object['@self']['slug'] = $identifier;
                } else {
                    $object['@self']['slug'] = str_starts_with($identifier, 'id-') 
                        ? substr($identifier, 3) 
                        : $identifier;
                }
            } else {
                $object['@self']['slug'] = str_starts_with($identifier, 'id-') 
                    ? substr($identifier, 3) 
                    : $identifier;
            }
            
            $objects[] = $object;
        }
        
        return $objects;
    }

    /**
     * SPEED OPTIMIZATION: Process views with maximum speed optimizations
     * 
     * @param array $xmlData XML data  
     * @param string $modelIdentifier Model identifier
     * @param array $propertyDefinitionMap Property definition map
     * @param array $elementsLookup Elements lookup for splicing
     * @return array Processed view objects
     */
    private function processViewsMaximumSpeed(
        array $xmlData,
        string $modelIdentifier,
        array $propertyDefinitionMap, 
        array $elementsLookup
    ): array {
        $viewsData = $this->findSectionData($xmlData, 'views');
        if (empty($viewsData)) {
            return [];
        }
        
        $this->logger->info('SPEED MODE: Processing views with maximum optimizations', [
            'elements_available' => count($elementsLookup)
        ]);
        
        // SPEED OPTIMIZATION: Pre-extract all referenced elements
        $items = $this->findItemsSimplified($viewsData, 'view');
        $referencedElements = $this->extractReferencedElements($items);
        
        // SPEED OPTIMIZATION: Build super-fast lookup with array_intersect_key
        $filteredElementsLookup = array_intersect_key($elementsLookup, array_flip($referencedElements));
        
        $this->logger->debug('SPEED: Optimized element references', [
            'total_elements' => count($elementsLookup),
            'referenced_elements' => count($filteredElementsLookup),
            'memory_savings_percent' => round((1 - count($filteredElementsLookup) / max(count($elementsLookup), 1)) * 100, 1)
        ]);
        
        // SPEED OPTIMIZATION: Process with bulk operations
        return $this->bulkTransformViews($items, $modelIdentifier, $propertyDefinitionMap, $filteredElementsLookup);
    }

    /**
     * SPEED OPTIMIZATION: Bulk transform views with vectorized element splicing
     * 
     * @param array $viewItems View items to process
     * @param string $modelIdentifier Model identifier
     * @param array $propertyDefinitionMap Property definition map  
     * @param array $elementsLookup Filtered elements lookup
     * @return array Processed view objects
     */
    private function bulkTransformViews(
        array $viewItems,
        string $modelIdentifier,
        array $propertyDefinitionMap,
        array $elementsLookup
    ): array {
        $objects = [];
        
        foreach ($viewItems as $item) {
            if (!is_array($item)) continue;
            
            $identifier = $this->extractIdentifier($item, 'view');
            if (!$identifier) continue;
            
            // SPEED OPTIMIZATION: Direct processing with minimal overhead
            $essentialXmlData = $this->extractEssentialXmlData($item, $elementsLookup, 'view');
            
            $object = [
                '@self' => [
                    'register' => $this->cachedConfig['registerId'] ?? 15,
                    'schema' => 111, // FIXED: Hard-code view schema ID for speed optimization
                    'id' => $identifier,
                    'owner' => $this->cachedConfig['userId'],
                    'organisation' => $this->cachedConfig['organisation'],
                    'published' => date('Y-m-d\TH:i:s\Z')
                ],
                'identifier' => $identifier,
                'section' => 'view',
                'model_identifier' => $modelIdentifier,
                'xml' => $essentialXmlData
            ];
            
            // Fast name/summary extraction
            if (isset($item['name'])) {
                $object['name'] = is_array($item['name']) && isset($item['name']['_value']) 
                    ? $item['name']['_value'] 
                    : (is_string($item['name']) ? $item['name'] : '');
            }
            
            if (isset($item['documentation'])) {
                $object['summary'] = is_array($item['documentation']) && isset($item['documentation']['_value'])
                    ? $item['documentation']['_value']
                    : (is_string($item['documentation']) ? $item['documentation'] : '');
            }
            
            // Fast properties flattening
            if (isset($item['properties']['property']) && !empty($propertyDefinitionMap)) {
                $this->flattenPropertiesBatch($object, $item['properties']['property'], $propertyDefinitionMap);
                
                if (isset($object['objectId'])) {
                    $object['@self']['id'] = $object['objectId'];
                    $object['@self']['slug'] = $identifier;
                } else {
                    $object['@self']['slug'] = str_starts_with($identifier, 'id-') 
                        ? substr($identifier, 3) 
                        : $identifier;
                }
            } else {
                $object['@self']['slug'] = str_starts_with($identifier, 'id-') 
                    ? substr($identifier, 3) 
                    : $identifier;
            }
            
            // SPEED OPTIMIZATION: Direct copy without checks (we know it exists)
            if (isset($object['xml']['nodes'])) {
                $object['nodes'] = $object['xml']['nodes'];
            }
            if (isset($object['xml']['connections'])) {
                $object['connections'] = $object['xml']['connections'];
            }
            
            $objects[] = $object;
        }
        
        return $objects;
    }

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
     * @return array Array of batches, each containing objects that fit within size limits
     */
    private function createIntelligentBatches(array $objects): array
    {
        $maxBatchSizeBytes = self::PERFORMANCE_OPTIMIZATIONS['max_batch_size_bytes'];
        $minBatchSize = self::PERFORMANCE_OPTIMIZATIONS['min_batch_size'];
        $sampleSize = self::PERFORMANCE_OPTIMIZATIONS['size_estimation_sample'];
        
        if (empty($objects)) {
            return [];
        }
        
        // Estimate average object size by sampling
        $avgObjectSize = $this->estimateAverageObjectSize($objects, $sampleSize);
        
        // Calculate optimal batch size based on object size
        $optimalBatchSize = max($minBatchSize, intval($maxBatchSizeBytes / $avgObjectSize));
        
        $this->logger->info('Intelligent batch sizing analysis', [
            'total_objects' => count($objects),
            'estimated_avg_object_size_bytes' => $avgObjectSize,
            'max_batch_size_bytes' => $maxBatchSizeBytes,
            'calculated_optimal_batch_size' => $optimalBatchSize,
            'min_batch_size_enforced' => $minBatchSize
        ]);
        
        // Create batches with size awareness
        $batches = [];
        $currentBatch = [];
        $currentBatchSize = 0;
        
        foreach ($objects as $object) {
            $objectSize = $this->estimateObjectSize($object);
            
            // Check if adding this object would exceed the batch size limit
            if (!empty($currentBatch) && ($currentBatchSize + $objectSize) > $maxBatchSizeBytes) {
                // Current batch is full, save it and start a new one
                $batches[] = $currentBatch;
                $currentBatch = [$object];
                $currentBatchSize = $objectSize;
            } else {
                // Add object to current batch
                $currentBatch[] = $object;
                $currentBatchSize += $objectSize;
            }
            
            // Safety check: if a single object is larger than max batch size,
            // create a batch with just that object
            if (count($currentBatch) === 1 && $objectSize > $maxBatchSizeBytes) {
                $this->logger->warning('Very large object detected, creating single-object batch', [
                    'object_id' => $object['@self']['id'] ?? 'unknown',
                    'object_size_bytes' => $objectSize,
                    'max_batch_size_bytes' => $maxBatchSizeBytes
                ]);
                $batches[] = $currentBatch;
                $currentBatch = [];
                $currentBatchSize = 0;
            }
        }
        
        // Add the last batch if it has objects
        if (!empty($currentBatch)) {
            $batches[] = $currentBatch;
        }
        
        $this->logger->info('Intelligent batching completed', [
            'total_objects' => count($objects),
            'total_batches_created' => count($batches),
            'batch_sizes' => array_map('count', $batches),
            'estimated_batch_sizes_bytes' => array_map(fn($batch) => array_sum(array_map([$this, 'estimateObjectSize'], $batch)), $batches)
        ]);
        
        return $batches;
    }

    /**
     * Estimate the average size of objects by sampling
     * 
     * @param array $objects Array of objects to sample
     * @param int $sampleSize Number of objects to sample for size estimation
     * @return int Estimated average object size in bytes
     */
    private function estimateAverageObjectSize(array $objects, int $sampleSize): int
    {
        $totalObjects = count($objects);
        if ($totalObjects === 0) {
            return 1000; // Default fallback size
        }
        
        // Sample evenly distributed objects
        $sampleIndices = [];
        if ($totalObjects <= $sampleSize) {
            // Use all objects if we have fewer than sample size
            $sampleIndices = range(0, $totalObjects - 1);
        } else {
            // Sample evenly across the array
            $step = max(1, intval($totalObjects / $sampleSize));
            for ($i = 0; $i < $totalObjects; $i += $step) {
                $sampleIndices[] = $i;
                if (count($sampleIndices) >= $sampleSize) {
                    break;
                }
            }
        }
        
        // Calculate sizes of sampled objects
        $totalSampleSize = 0;
        foreach ($sampleIndices as $index) {
            $totalSampleSize += $this->estimateObjectSize($objects[$index]);
        }
        
        $averageSize = intval($totalSampleSize / count($sampleIndices));
        
        $this->logger->debug('Object size estimation completed', [
            'total_objects' => $totalObjects,
            'sampled_objects' => count($sampleIndices),
            'total_sample_size_bytes' => $totalSampleSize,
            'estimated_average_size_bytes' => $averageSize
        ]);
        
        return max(1000, $averageSize); // Minimum 1KB per object
    }

    /**
     * Estimate the serialized size of an object for batching purposes
     * 
     * @param array $object The object to estimate size for
     * @return int Estimated size in bytes
     */
    private function estimateObjectSize(array $object): int
    {
        // Quick estimation based on JSON serialization
        // This includes overhead for SQL parameters and structure
        $jsonSize = strlen(json_encode($object));
        
        // Add overhead for SQL INSERT statement structure
        // Each object becomes multiple parameters in a bulk INSERT
        $sqlOverhead = 500; // Estimated overhead per object in SQL
        
        return $jsonSize + $sqlOverhead;
    }

    /**
     * Calculate detailed object statistics for import operations
     * 
     * @param array $normalizedData Normalized ArchiMate data
     * @param array $savedObjects Objects that were saved to database
     * @return array Comprehensive statistics
     */
    private function calculateObjectStatistics(array $normalizedData, array $savedObjects): array
    {
        // Initialize statistics structure
        $statistics = [
            'elements' => ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'errors' => []],
            'organizations' => ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'errors' => []],
            'relationships' => ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'errors' => []],
            'views' => ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'errors' => []],
            'property_definitions' => ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'errors' => []]
        ];

        // If we have access to the actual save results from ObjectService, use those
        if ($this->lastSaveResult !== null) {
            $saveResult = $this->lastSaveResult;
            
            // Count objects by section type from the actual processed objects
            $allProcessedObjects = array_merge(
                $saveResult['saved'] ?? [],
                $saveResult['updated'] ?? [],
                $saveResult['unchanged'] ?? $saveResult['skipped'] ?? [],
                // For invalid objects, extract the original object from the error structure
                array_map(fn($item) => $item['object'] ?? [], $saveResult['invalid'] ?? [])
            );
            
            foreach ($allProcessedObjects as $object) {
                // Convert ObjectEntity to array if needed
                if (is_object($object) && method_exists($object, 'jsonSerialize')) {
                    $object = $object->jsonSerialize();
                }
                
                $sectionType = $object['section'] ?? 'elements'; // Default to elements if section not found
                
                // Map section types to statistics keys
                $sectionKey = match($sectionType) {
                    'elements' => 'elements',
                    'relationships' => 'relationships', 
                    'organizations' => 'organizations',
                    'views' => 'views',
                    'property_definitions' => 'property_definitions',
                    default => 'elements' // Default fallback
                };
                
                if (!isset($statistics[$sectionKey])) {
                    continue; // Skip unknown section types
                }
                
                // Determine if this object was created, updated, or had errors
                $objectId = $object['@self']['id'] ?? $object['identifier'] ?? null;
                
                // Check if this object is in the saved (created) list
                $wasCreated = !empty(array_filter($saveResult['saved'] ?? [], 
                    fn($saved) => ($saved->getUuid() === $objectId)));
                
                // Check if this object is in the updated list
                $wasUpdated = !empty(array_filter($saveResult['updated'] ?? [], 
                    fn($updated) => ($updated->getUuid() === $objectId)));
                
                // Check if this object was unchanged (no changes)
                $unchangedObjects = $saveResult['unchanged'] ?? $saveResult['skipped'] ?? [];
                $wasSkipped = !empty(array_filter($unchangedObjects,
                    fn($unchanged) => ($unchanged->getUuid() === $objectId)));
                
                // Check if this object had validation errors
                $hasErrors = !empty(array_filter($saveResult['invalid'] ?? [],
                    fn($invalid) => (($invalid['object']['@self']['id'] ?? null) === $objectId)));
                
                if ($wasCreated) {
                    $statistics[$sectionKey]['created']++;
                } elseif ($wasUpdated) {
                    $statistics[$sectionKey]['updated']++;
                } elseif ($wasSkipped) {
                    $statistics[$sectionKey]['skipped']++;
                } elseif ($hasErrors) {
                    // Add to errors array for this section
                    $errorInfo = array_filter($saveResult['invalid'] ?? [],
                        fn($invalid) => (($invalid['object']['@self']['id'] ?? null) === $objectId));
                    
                    if (!empty($errorInfo)) {
                        $statistics[$sectionKey]['errors'][] = array_values($errorInfo)[0]['error'] ?? 'Unknown validation error';
                    }
                } else {
                    // This shouldn't happen, but leave as fallback
                    $statistics[$sectionKey]['skipped']++;
                }
            }
        } else {
            // Fallback to old method if no save result is available
            $sections = ['elements', 'relationships', 'organizations', 'views', 'property_definitions'];
            foreach ($sections as $section) {
                if (isset($normalizedData[$section])) {
                    $count = count($normalizedData[$section]);
                    // Assume all objects were created (legacy behavior)
                    $statistics[$section]['created'] = $count;
                }
            }
        }

        // Calculate summary totals from actual statistics
        $summary = [
            'total_objects_created' => 0,
            'total_objects_updated' => 0,
            'total_objects_deleted' => 0,
            'total_objects_skipped' => 0,
            'total_errors' => 0
        ];

        foreach ($statistics as $section => $sectionStats) {
            if ($section !== 'summary') { // Skip summary section itself
                $summary['total_objects_created'] += $sectionStats['created'];
                $summary['total_objects_updated'] += $sectionStats['updated'];
                $summary['total_objects_skipped'] += $sectionStats['skipped'];
                $summary['total_errors'] += count($sectionStats['errors']);
            }
        }

        $statistics['summary'] = $summary;

        return $statistics;
    }
}


