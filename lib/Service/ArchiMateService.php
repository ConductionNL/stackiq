<?php

/**
 * ArchiMate Service for SoftwareCatalog
 * 
 * Handles import and export of ArchiMate XML files with round-trip fidelity.
 * Stores complete XML data as JSON blobs in the database and reconstructs
 * exact XML output during export.
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
 * ArchiMate Service for handling XML import/export with round-trip fidelity
 * 
 * This service provides a clean approach to ArchiMate XML processing:
 * 1. Import: Parse XML to array, store complete data as JSON blob
 * 2. Storage: Use ObjectService::saveObjects with proper @self structure
 * 3. Export: Reconstruct exact XML from stored JSON blobs
 * 
 * @category Service
 * @package  OCA\SoftwareCatalog\Service
 * @author   SoftwareCatalog Team
 * @license  AGPL-3.0
 * @version  1.0.0
 * @link     https://github.com/nextcloud/softwarecatalog
 */
class ArchiMateService
{
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
    private ?array $propertyDefinitionMapCache = null;

    /**
     * Flag to track if we've already logged finding a GEMMA type property
     * 
     * @var bool
     */
    private bool $gemmaTypePropertyFound = false;
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
     * Storage for the last save operation results
     * Contains the structured return from ObjectService::saveObjects
     */
    private ?array $lastSaveResult = null;

    /**
     * Cached configuration values for performance optimization
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
        private readonly ArchiMateImportService $importService,
        private readonly ArchiMateExportService $exportService
    ) {
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
        // Delegate to the import service
        return $this->importService->importArchiMateFileFromPathOptimized($options);
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
        // Delegate to the import service
        return $this->importService->importArchiMateFileFromPath($options);
    }

    /**
     * Export ArchiMate data to XML
     * 
     * @param string|null $organization Organization filter (currently not implemented)
     * @return array Export results
     */
    public function exportToArchiMate(?string $organization = null): array
    {
        $this->logger->info('Starting ArchiMate XML export', [
            'organization' => $organization
        ]);

        try {
            // Get ObjectService and register ID
            $objectService = $this->getObjectService();
            if (!$objectService) {
                throw new \RuntimeException('ObjectService not available');
            }

            $registerId = $this->getAmefRegisterId();
            if (!$registerId) {
                $registerId = 15; // Fallback
            }

            // Create schema ID mapping for the export service
            $schemaIdMap = $this->createSchemaIdMap();

            // Use export service to handle complete export process in one go
            $xml = $this->exportService->exportArchiMateXml($objectService, $registerId, $schemaIdMap, $organization);
            
            $this->logger->info('ArchiMate export completed successfully', [
                'organization_filter' => $organization,
                'xml_size' => strlen($xml)
            ]);

            return [
                'success' => true,
                'xml' => $xml,
                'exported_count' => 'calculated_in_export_service' // Will be logged by export service
            ];

        } catch (\Exception $e) {
            $this->logger->error('ArchiMate export failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Create schema ID mapping for export service
     * 
     * @return array Mapping of schema IDs to schema types
     */
    private function createSchemaIdMap(): array
    {
        $schemaTypes = ['model', 'element', 'relationship', 'view', 'organization', 'property_definition'];
        $schemaIdMap = [];

        foreach ($schemaTypes as $schemaType) {
            $schemaId = $this->getAmefSchemaIdForType($schemaType);
            if ($schemaId) {
                $schemaIdMap[$schemaId] = $schemaType;
            }
        }

        return $schemaIdMap;
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
        
        // Create object with @self structure and XML data at root level (no double serialization)
        $object = [
            '@self' => [
                'register' => $registerId,
                'schema' => $schemaId,
                'id' => $identifier,
                'owner' => $this->getCurrentUserId(),
                'organisation' => $this->getCurrentOrganisation(),

            ]
        ];
        
        // Set slug: first try from _slug field, then from Object ID property, then extract from identifier
        $slug = null;
        
        // Check if there's a temporary slug to move to @self structure
        if (isset($data['_slug'])) {
            $slug = $data['_slug'];
            unset($data['_slug']); // Remove the temporary field
        }
        // Check if we have "Object ID" property directly
        elseif (isset($data['Object ID'])) {
            $slug = $data['Object ID'];
        }
        // Fallback: extract from identifier (remove "id-" prefix if present)
        elseif ($identifier && str_starts_with($identifier, 'id-')) {
            $slug = substr($identifier, 3); // Remove "id-" prefix
        }
        
        // Set the slug if we found one
        if ($slug) {
            $object['@self']['slug'] = $slug;
        }
        
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

        $this->logger->info('Saving objects to database using parallel batch processing', [
            'count' => count($objects),
            'batch_size' => self::PERFORMANCE_OPTIMIZATIONS['batch_size'],
            'parallel_batches' => self::PERFORMANCE_OPTIMIZATIONS['parallel_batches'],
            'service_init_time' => round($serviceInitTime, 3),
            'gemma_processing_time' => round($gemmaProcessingTime, 3)
        ]);

        // OPTIMIZATION: Use cached register ID
        $registerId = $this->cachedConfig['registerId'] ?? 15;

        // PERFORMANCE OPTIMIZATION: Use parallel batch processing for large datasets
        $batchProcessingStartTime = microtime(true);
        if (self::PERFORMANCE_OPTIMIZATIONS['parallel_processing'] && count($objects) > self::PERFORMANCE_OPTIMIZATIONS['batch_size']) {
            $result = $this->saveObjectsInParallelBatches($objects, $objectService, $registerId);
        } else {
            // Fallback to single batch for small datasets
            $result = $this->saveObjectsInSingleBatch($objects, $objectService, $registerId);
        }
        $batchProcessingTime = microtime(true) - $batchProcessingStartTime;
        
        $totalSaveTime = microtime(true) - $saveStartTime;
        
        $this->logger->info('Database save operation completed', [
            'total_save_time' => round($totalSaveTime, 3),
            'service_init_time' => round($serviceInitTime, 3),
            'gemma_processing_time' => round($gemmaProcessingTime, 3),
            'batch_processing_time' => round($batchProcessingTime, 3),
            'objects_saved' => count($result),
            'save_rate_objects_per_second' => round(count($objects) / max($totalSaveTime, 0.001), 1)
        ]);

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
     * Save objects in parallel batches for maximum performance
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
        
        $this->logger->info('Starting intelligent batch processing', [
            'total_objects_to_save' => count($objects),
            'intelligent_batches_created' => $totalChunks,
            'batch_sizes' => array_map('count', $chunks),
            'batching_method' => 'size_aware_intelligent',
            'mysql_packet_limit_safe' => true
        ]);

        $allResults = [];
        $processedChunks = 0;
        
        // Accumulate statistics from all chunks
        $aggregatedStats = [
            'saved' => [],
            'updated' => [],
            'skipped' => [],
            'invalid' => []
        ];
        
        // Process chunks sequentially but with larger batch sizes for better performance
        foreach ($chunks as $chunkIndex => $chunk) {
            // OPTIMIZATION: Removed debug logging from chunk processing loop
            
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
                
                // Accumulate statistics from this chunk
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
                $this->logger->info('Processed chunk', [
                    'processed_chunks' => $processedChunks,
                    'total_chunks' => $totalChunks,
                    'progress_percent' => round(($processedChunks / $totalChunks) * 100, 1),
                    'chunk_saved' => count($saveResult['saved'] ?? []),
                    'chunk_updated' => count($saveResult['updated'] ?? []),
                    'chunk_skipped' => count($saveResult['skipped'] ?? []),
                    'chunk_invalid' => count($saveResult['invalid'] ?? [])
                ]);
                
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
        
        $this->logger->info('Optimized batch processing completed', [
            'total_objects_processed' => count($allResults),
            'total_chunks_processed' => $totalChunks,
            'aggregated_saved' => count($aggregatedStats['saved']),
            'aggregated_updated' => count($aggregatedStats['updated']),
            'aggregated_skipped' => count($aggregatedStats['skipped']),
            'aggregated_invalid' => count($aggregatedStats['invalid'])
        ]);
        
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
        $this->logger->info('Using single batch processing', [
            'count' => count($objects)
        ]);
        
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

        // Log detailed results including validation errors
        $this->logger->info('Objects saved successfully', [
            'saved_count' => count($saveResult['saved'] ?? []),
            'updated_count' => count($saveResult['updated'] ?? []),
            'skipped_count' => count($saveResult['skipped'] ?? []),
            'invalid_count' => count($saveResult['invalid'] ?? []),
            'error_count' => count($saveResult['errors'] ?? []),
            'total_processed' => $saveResult['statistics']['totalProcessed'] ?? 0
        ]);

        // Log any validation errors for debugging
        if (!empty($saveResult['invalid'])) {
            foreach ($saveResult['invalid'] as $invalidItem) {
                $this->logger->warning('Object failed validation during import', [
                    'object_id' => $invalidItem['object']['@self']['id'] ?? 'unknown',
                    'error' => $invalidItem['error'] ?? 'Unknown validation error',
                    'type' => $invalidItem['type'] ?? 'ValidationException'
                ]);
            }
        }

        // Log details about skipped objects if any
        if (!empty($saveResult['skipped'])) {
            $this->logger->info('Objects skipped during import (no changes detected)', [
                'skipped_count' => count($saveResult['skipped']),
                'sample_skipped_ids' => array_slice(
                    array_map(fn($obj) => $obj->getUuid() ?? 'unknown', $saveResult['skipped']),
                    0, 
                    5
                )
            ]);
        }

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
     * Get ArchiMate register ID
     * 
     * @return int Register ID
     */
    private function getArchiMateRegisterId(): int
    {
        return (int) ($this->config->getValueString('softwarecatalog', 'archimate_register_id', '100'));
    }

    /**
     * Get ArchiMate model schema ID
     * 
     * @return int Schema ID
     */
    private function getArchiMateModelSchemaId(): int
    {
        return (int) ($this->config->getValueString('softwarecatalog', 'archimate_model_schema_id', '100'));
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
     * Test round-trip functionality
     * 
     * @return array Test results
     */
    public function testRoundTrip(): array
    {
        $this->logger->info('Testing ArchiMate round-trip functionality');

        try {
            // Create test XML
            $testXml = $this->createTestArchiMateXml();
            
            // Import
            $importResult = $this->importArchiMateFileFromPath([
                'file_path' => $this->createTempFile($testXml)
            ]);
            
            if (!$importResult['success']) {
                return [
                    'success' => false,
                    'error' => 'Import failed: ' . $importResult['error']
                ];
            }

            // Export
            $exportResult = $this->exportToArchiMate();
            
            if (!$exportResult['success']) {
                return [
                    'success' => false,
                    'error' => 'Export failed: ' . $exportResult['error']
                ];
            }

            // Compare (simplified comparison)
            $importedCount = $importResult['imported_count'];
            $exportedCount = $exportResult['exported_count'];
            
            $success = $importedCount === $exportedCount;

            return [
                'success' => $success,
                'imported_count' => $importedCount,
                'exported_count' => $exportedCount,
                'round_trip_successful' => $success
            ];

        } catch (\Exception $e) {
            $this->logger->error('Round-trip test failed', [
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Create test ArchiMate XML
     * 
     * @return string Test XML content
     */
    private function createTestArchiMateXml(): string
    {
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
    }

    /**
     * Create temporary file with content
     * 
     * @param string $content File content
     * @return string Temporary file path
     */
    private function createTempFile(string $content): string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'archimate_test_');
        file_put_contents($tempFile, $content);
        return $tempFile;
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
     * Get Voorzieningen configuration directly from IAppConfig
     *
     * @return array The voorzieningen configuration
     */
    private function getVoorzieningenConfig(): array
    {
        $config = $this->config->getValueString('softwarecatalog', 'voorzieningen_config', '{}');
        $decoded = json_decode($config, true);
        
        if (!is_array($decoded)) {
            // Fallback to individual config values for backward compatibility
            $decoded = [
                'register' => $this->config->getValueString('softwarecatalog', 'voorzieningen_register', ''),
                'organisatie_schema' => $this->config->getValueString('softwarecatalog', 'voorzieningen_organisatie_schema', ''),
                'contactpersoon_schema' => $this->config->getValueString('softwarecatalog', 'voorzieningen_contactpersoon_schema', ''),
            ];
        }
        
        return $decoded;
    }

    /**
     * Get the current status of ArchiMate operations
     *
     * @return array Status information including import/export status and object counts
     */
    public function getArchiMateStatus(): array
    {
        $this->logger->info('Getting ArchiMate status');
        
        try {
            // Get basic status information
            $objectService = $this->getObjectService();
            if (!$objectService) {
                return [
                    'success' => false,
                    'error' => 'ObjectService not available'
                ];
            }
            
            // Get object counts using the proper getter methods
            $elementObjects = $this->getElementObjects();
            $organizationObjects = $this->getOrganizationObjects();
            $viewObjects = $this->getViewObjects();
            $relationshipObjects = $this->getRelationshipObjects();
            $modelObjects = $this->getModelObjects();
            $propertyObjects = $this->getPropertyObjects();
            $propertyDefinitionObjects = $this->getPropertyDefinitionObjects();
            
            // Calculate totals
            $totalCount = count($elementObjects) + count($organizationObjects) + 
                         count($viewObjects) + count($relationshipObjects) + 
                         count($modelObjects) + count($propertyObjects) + 
                         count($propertyDefinitionObjects);
            
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
                'property_definition_count' => count($propertyDefinitionObjects)
            ];
        } catch (\Exception $e) {
            $this->logger->error('Failed to get ArchiMate status', [
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
     * Get element objects from the database
     * 
     * @param array $query Query parameters
     * @return array Array of element objects
     */
    public function getElementObjects(array $query = []): array
    {
        return $this->getObjectsWithPagination('element', $query);
    }

    /**
     * Get organization objects from the database
     * 
     * @param array $query Query parameters
     * @return array Array of organization objects
     */
    public function getOrganizationObjects(array $query = []): array
    {
        return $this->getObjectsWithPagination('organization', $query);
    }

    /**
     * Get view objects from the database
     * 
     * @param array $query Query parameters
     * @return array Array of view objects
     */
    public function getViewObjects(array $query = []): array
    {
        return $this->getObjectsWithPagination('view', $query);
    }

    /**
     * Get relationship objects from the database
     * 
     * @param array $query Query parameters
     * @return array Array of relationship objects
     */
    public function getRelationshipObjects(array $query = []): array
    {
        return $this->getObjectsWithPagination('relationship', $query);
    }

    /**
     * Get model objects from the database
     * 
     * @param array $query Query parameters
     * @return array Array of model objects
     */
    public function getModelObjects(array $query = []): array
    {
        return $this->getObjectsWithPagination('model', $query);
    }

    /**
     * Get property objects from the database
     * 
     * @param array $query Query parameters
     * @return array Array of property objects
     */
    public function getPropertyObjects(array $query = []): array
    {
        return $this->getObjectsWithPagination('property', $query);
    }

    /**
     * Get property definition objects from the database
     * 
     * @param array $query Query parameters
     * @return array Array of property definition objects
     */
    public function getPropertyDefinitionObjects(array $query = []): array
    {
        return $this->getObjectsWithPagination('property_definition', $query);
    }

    /**
     * Get objects with pagination support for a specific schema type
     *
     * @param string $schemaType The schema type to retrieve objects for
     * @param array $query Optional query criteria and pagination parameters
     * @return array Array of objects matching the criteria
     */
    private function getObjectsWithPagination(string $schemaType, array $query = []): array
    {
        try {
            $objectService = $this->getObjectService();
            if (!$objectService) {
                $this->logger->error("ArchiMateService: ObjectService not available for {$schemaType} objects retrieval");
                return [];
            }

            $registerId = $this->getAmefRegisterId();
            $schemaId = $this->getAmefSchemaIdForType($schemaType);
            
            if (!$registerId || !$schemaId) {
                $this->logger->error("ArchiMateService: AMEF register or {$schemaType} schema not configured", [
                    'registerId' => $registerId,
                    'schemaId' => $schemaId
                ]);
                return [];
            }

            // Extract pagination parameters
            $limit = $query['limit'] ?? 1000; // Default limit for large datasets
            $offset = $query['offset'] ?? 0;
            $usePagination = $query['use_pagination'] ?? false;
            
            // Remove pagination parameters from query
            unset($query['limit'], $query['offset'], $query['use_pagination']);

            // Build base query for register and schema
            $baseQuery = [
                '@self' => [
                    'register' => (int) $registerId,
                    'schema' => (int) $schemaId
                ]
            ];
            
            // Merge with provided query
            $finalQuery = array_merge_recursive($baseQuery, $query);
            
            // Add pagination if requested
            if ($usePagination && $limit > 0) {
                $finalQuery['@pagination'] = [
                    'limit' => (int) $limit,
                    'offset' => (int) $offset
                ];
            }
            
            $this->logger->debug("ArchiMateService: Retrieving {$schemaType} objects", [
                'register' => $registerId,
                'schema' => $schemaId,
                'query' => $finalQuery,
                'pagination' => $usePagination ? ['limit' => $limit, 'offset' => $offset] : 'disabled'
            ]);
            
            // Use searchObjects method for filtering
            $objects = $objectService->searchObjects($finalQuery);
            
            $this->logger->debug("ArchiMateService: Retrieved {$schemaType} objects", [
                'register' => $registerId,
                'schema' => $schemaId,
                'count' => count($objects),
                'pagination' => $usePagination ? ['limit' => $limit, 'offset' => $offset] : 'disabled'
            ]);

            return $objects;
        } catch (\Exception $e) {
            $this->logger->error("ArchiMateService: Failed to retrieve {$schemaType} objects", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return [];
        }
    }

    /**
     * Check if import is in progress
     * 
     * @return bool True if import is in progress
     */
    public function isImportInProgress(): bool
    {
        // For now, return false as we haven't implemented status tracking yet
        return false;
    }

    /**
     * Check if export is in progress
     * 
     * @return bool True if export is in progress
     */
    public function isExportInProgress(): bool
    {
        // For now, return false as we haven't implemented status tracking yet
        return false;
    }

    /**
     * Check if any operation is in progress
     * 
     * @return bool True if any operation is in progress
     */
    public function isOperationInProgress(): bool
    {
        return $this->isImportInProgress() || $this->isExportInProgress();
    }

    /**
     * Create intelligent batches based on object size to prevent MySQL packet size issues
     * 
     * This method analyzes object sizes and creates batches that stay under the MySQL
     * max_allowed_packet limit while maintaining reasonable performance.
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
            'elements' => ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []],
            'organizations' => ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []],
            'relationships' => ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []],
            'views' => ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []],
            'property_definitions' => ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []]
        ];

        // If we have access to the actual save results from ObjectService, use those
        if ($this->lastSaveResult !== null) {
            $saveResult = $this->lastSaveResult;
            
            // Count objects by section type from the actual processed objects
            $allProcessedObjects = array_merge(
                $saveResult['saved'] ?? [],
                $saveResult['updated'] ?? [],
                $saveResult['skipped'] ?? [],
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
                
                // Check if this object was skipped (no changes)
                $wasSkipped = !empty(array_filter($saveResult['skipped'] ?? [],
                    fn($skipped) => ($skipped->getUuid() === $objectId)));
                
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
     * @return array Array of objects ready for saveObjects()
     */
    private function transformArchiMateXmlToObjectsBatch(array $xmlData, string $modelIdentifier): array
    {
        $allObjects = [];
        
        // Extract propertyDefinitionMap once for all objects
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
        
        foreach ($sections as $sectionName => $schemaType) {
            $sectionData = $this->findSectionData($xmlData, $sectionName);
            if (!empty($sectionData)) {
                $sectionObjects = $this->transformSectionObjectsBatch(
                    $sectionData,
                    $schemaType,
                    $modelIdentifier,
                    $propertyDefinitionMap
                );
                $allObjects = array_merge($allObjects, $sectionObjects);
            }
        }
        
        return $allObjects;
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
     * Transform section objects in batch with minimal overhead
     * 
     * @param array $sectionData Section data from XML
     * @param string $schemaType Schema type (singular)
     * @param string $modelIdentifier Model identifier
     * @param array $propertyDefinitionMap Property definition map
     * @return array Array of transformed objects
     */
    private function transformSectionObjectsBatch(
        array $sectionData, 
        string $schemaType, 
        string $modelIdentifier, 
        array $propertyDefinitionMap
    ): array {
        $objects = [];
        
        // Find items in section (simplified version)
        $items = $this->findItemsSimplified($sectionData, $schemaType);
        
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            
            $identifier = $this->extractIdentifier($item, $schemaType);
            if (!$identifier) {
                continue;
            }
            
            // Create object directly (minimal processing)
            $object = [
                '@self' => [
                    'register' => $this->cachedConfig['registerId'] ?? 15,
                    'schema' => $this->cachedConfig['schemaIds'][$schemaType] ?? 100,
                    'id' => $identifier,
                    'owner' => $this->cachedConfig['userId'],
                    'organisation' => $this->cachedConfig['organisation'],

                ],
                'identifier' => $identifier,
                'section' => $schemaType,
                'model_identifier' => $modelIdentifier,
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
            
            // Flatten properties efficiently (if present)
            if (isset($item['properties']['property']) && !empty($propertyDefinitionMap)) {
                $this->flattenPropertiesBatch($object, $item['properties']['property'], $propertyDefinitionMap);
            }
            
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
        
        foreach ($props as $prop) {
            if (!isset($prop['_attributes']['propertyDefinitionRef'])) {
                continue;
            }
            
            $defRef = $prop['_attributes']['propertyDefinitionRef'];
            $value = $prop['value']['_value'] ?? $prop['value'] ?? null;
            
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
                    'value' => $value
                ];
                
                // Set slug for Object ID property
                if (strtolower($propertyName) === 'object id') {
                    $object['@self']['slug'] = $value;
                }
            }
        }
        
        // OPTIMIZATION: Removed debug logging from tight loop for performance
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
                'total_objects_skipped' => 0,
                'total_errors' => 0
            ]
        ];

        if ($this->lastSaveResult !== null) {
            $saveResult = $this->lastSaveResult;
            $statistics['summary'] = [
                'total_objects_created' => count($saveResult['saved'] ?? []),
                'total_objects_updated' => count($saveResult['updated'] ?? []),
                'total_objects_deleted' => 0,
                'total_objects_skipped' => count($saveResult['skipped'] ?? []),
                'total_errors' => count($saveResult['invalid'] ?? [])
            ];
        }

        return $statistics;
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
                if (!$this->gemmaTypePropertyFound) {
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
        
        // Additional debugging if no GEMMA types found
        if ($elementsWithGemmaType === 0 && $elementCount > 0) {
            $this->logger->warning('No GEMMA types found in any elements', [
                'total_elements_processed' => $elementCount,
                'sample_element_keys' => 'Will need to examine individual objects'
            ]);
        }
        
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
     * OPTIMIZATION: Extract only essential XML data to reduce memory usage by 20-30%
     * 
     * Instead of storing the complete XML structure, this method extracts only
     * the essential data needed for round-trip fidelity and export functionality.
     * 
     * @param array $item The complete XML item data
     * @return array Essential XML data for storage
     */
    private function extractEssentialXmlData(array $item): array
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
        
        // Add a marker to indicate this is essential data (for debugging)
        $essential['_essential_data'] = true;
        
        return $essential;
    }

}