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
    private const CONFIG_KEYS = [
        'archimate_register_id' => 'archimate_register_id',
        'archimate_schema_id' => 'archimate_schema_id',
        'archimate_model_schema_id' => 'archimate_model_schema_id'
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
     * Import ArchiMate XML file from path
     * 
     * @param array $options Import options
     * @return array Import results
     */
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
        
        $this->logger->info('Starting OPTIMIZED ArchiMate XML import', [
            'file_path' => $options['file_path'] ?? 'unknown'
        ]);

        try {
            // OPTIMIZATION: Cache all configuration once at start
            $this->initializeCache();
            
            // STEP 1: Parse XML to array (same as before)
            $filePath = $options['filePath'] ?? $options['file_path'] ?? '';
            if (empty($filePath) || !file_exists($filePath)) {
                throw new \InvalidArgumentException("File not found: {$filePath}");
            }
            
            $parseStartTime = microtime(true);
            $xmlData = $this->parseArchiMateXml($filePath);
            $parseTime = microtime(true) - $parseStartTime;
            
            // STEP 2: Extract model identifier
            $modelIdentifier = $this->extractModelIdentifier($xmlData);
            
            // STEP 3: Parse ALL objects in one go (like CSV import)
            $transformStartTime = microtime(true);
            $allObjects = $this->transformArchiMateXmlToObjectsBatch($xmlData, $modelIdentifier);
            $transformTime = microtime(true) - $transformStartTime;
            
            $this->logger->info('Parsed and transformed all objects', [
                'object_count' => count($allObjects),
                'parse_time' => round($parseTime, 3),
                'transform_time' => round($transformTime, 3)
            ]);
            
            // STEP 4: Single saveObjects() call (like CSV import)
            $saveStartTime = microtime(true);
            $savedObjects = $this->saveObjectsToDatabase($allObjects);
            $saveTime = microtime(true) - $saveStartTime;
            
            $totalTime = microtime(true) - $startTime;
            $itemsPerSecond = count($allObjects) / max($totalTime, 0.001);
            
            $this->logger->info('OPTIMIZED import completed successfully', [
                'total_objects' => count($allObjects),
                'total_time' => round($totalTime, 3),
                'items_per_second' => round($itemsPerSecond, 1),
                'breakdown' => [
                    'parse' => round($parseTime, 3),
                    'transform' => round($transformTime, 3), 
                    'save' => round($saveTime, 3)
                ]
            ]);

            return [
                'success' => true,
                'file_info' => [
                    'name' => $options['fileName'] ?? basename($filePath),
                    'size' => filesize($filePath)
                ],
                'performance_metrics' => [
                    'total_time_seconds' => round($totalTime, 3),
                    'items_per_second' => round($itemsPerSecond, 1),
                    'objects_processed' => count($allObjects)
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
        
        $this->logger->info('Starting ArchiMate XML import with model detection', [
            'options' => $options,
            'file_path' => $options['file_path'] ?? 'unknown'
        ]);

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

        $xmlContent = file_get_contents($filePath);
        if ($xmlContent === false) {
            throw new \RuntimeException("Failed to read file: {$filePath}");
        }

        $xml = new SimpleXMLElement($xmlContent);
        return $this->importService->xmlToArray($xml);
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
     * Create or update a model object in the database
     * 
     * This method establishes the model object that will be used to link all other objects.
     * It creates a model object with an archimate_id field that serves as the parent identifier.
     * 
     * @param array $modelMetadata Model metadata from the XML
     * @return array Result of the model creation/update operation
     */
    private function createOrUpdateModelObject(array $modelMetadata): array
    {
        try {
            $objectService = $this->getObjectService();
            if (!$objectService) {
                $this->logger->error('ArchiMateService: ObjectService not available for model object creation');
                return ['success' => false, 'error' => 'ObjectService not available'];
            }

            $registerId = $this->getAmefRegisterId();
            $schemaId = $this->getAmefSchemaIdForType('model');
            
            if (!$registerId || !$schemaId) {
                $this->logger->error('ArchiMateService: AMEF register or model schema not configured', [
                    'registerId' => $registerId,
                    'schemaId' => $schemaId
                ]);
                return ['success' => false, 'error' => 'AMEF register or model schema not configured'];
            }

            $modelIdentifier = $modelMetadata['identifier'] ?? '';
            if (empty($modelIdentifier)) {
                $this->logger->warning('ArchiMateService: No model identifier found in metadata');
                return ['success' => false, 'error' => 'No model identifier found'];
            }

            // Prepare model object data with @self structure for ObjectService::saveObjects
            $modelData = [
                '@self' => [
                    'register' => $registerId,
                    'schema' => $schemaId,
                    'id' => $modelIdentifier  // Use the archimate identifier as the UUID
                ],
                'archimate_id' => $modelIdentifier,
                'name' => $modelMetadata['name'] ?? '',
                'documentation' => $modelMetadata['documentation'] ?? '',
                'properties' => $modelMetadata['properties'] ?? [],
                'import_time' => date('Y-m-d H:i:s'),
                'import_source' => 'archimate_xml_import'
            ];

            // Save the model object using ObjectService::saveObjects
            $saveResult = $objectService->saveObjects([$modelData]);
            
            // Extract the saved/updated objects from the new structured return format
            $savedObjects = array_merge(
                $saveResult['saved'] ?? [],
                $saveResult['updated'] ?? []
            );
            
            if (empty($savedObjects)) {
                // Check if there are validation errors
                if (!empty($saveResult['invalid'])) {
                    $errorMsg = $saveResult['invalid'][0]['error'] ?? 'Model object failed validation';
                    $this->logger->error('ArchiMateService: Model object failed validation', [
                        'error' => $errorMsg,
                        'model_id' => $modelIdentifier
                    ]);
                    return ['success' => false, 'error' => "Validation failed: $errorMsg"];
                }
                
                $this->logger->error('ArchiMateService: Failed to save model object');
                return ['success' => false, 'error' => 'Failed to save model object'];
            }

            $savedModelObject = $savedObjects[0];
            
            // Determine if this was a create or update operation
            $modelAction = 'unknown';
            if (!empty($saveResult['saved'])) {
                $modelAction = 'created';
            } elseif (!empty($saveResult['updated'])) {
                $modelAction = 'updated';
            }
            
            $this->logger->info('ArchiMateService: Saved model object', [
                'model_id' => $modelIdentifier,
                'action' => $modelAction
            ]);
            
            return ['success' => true, 'action' => $modelAction];

        } catch (\Exception $e) {
            $this->logger->error('ArchiMateService: Failed to create/update model object', [
                'error' => $e->getMessage(),
                'model_metadata' => $modelMetadata
            ]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // Method removed - action is now determined directly from ObjectService::saveObjects structured return

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
                        'xml' => $item // Store the full parsed XML for this object (direct reference)
                    ];
                    
                    // Flatten properties to root fields using the propertyDefinitionMap
                    if (isset($item['properties']) && isset($item['properties']['property'])) {
                        $props = $item['properties']['property'];
                        if (isset($props[0])) {
                            // Multiple properties
                            foreach ($props as $prop) {
                                $defRef = $prop['_attributes']['propertyDefinitionRef'] ?? null;
                                $value = $prop['value']['_value'] ?? $prop['value'] ?? null;
                                if ($defRef && isset($propertyDefinitionMap[$defRef])) {
                                    $name = $propertyDefinitionMap[$defRef];
                                    $object[$name] = $value;
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
                                $object[$name] = $value;
                                if (strtolower($name) === 'object id') {
                                    $object['_slug'] = $value; // Store temporarily, will be moved to @self.slug later
                                }
                            }
                        }
                    }
                    $extracted[$identifier] = $object;
                }
            }
        }
        return $extracted;
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
        // OPTIMIZATION: Removed debug logging from tight loop
        
        // Special handling for organizations - they have identifierRef attributes
        if ($sectionName === 'organizations') {
            // Check for identifierRef in the item itself
            if (isset($item['_attributes']['identifierRef'])) {
                $identifier = (string) $item['_attributes']['identifierRef'];
                $this->logger->debug("Found organization identifierRef", [
                    'section' => $sectionName,
                    'identifier' => $identifier
                ]);
                return $identifier;
            }
            
            // Check for identifierRef in child elements
            if (isset($item['item']) && is_array($item['item'])) {
                foreach ($item['item'] as $childItem) {
                    if (isset($childItem['_attributes']['identifierRef'])) {
                        $identifier = (string) $childItem['_attributes']['identifierRef'];
                        $this->logger->debug("Found organization identifierRef in child", [
                            'section' => $sectionName,
                            'identifier' => $identifier
                        ]);
                        return $identifier;
                    }
                }
            }
            
            // For organizations, if no identifierRef found, try to use the label as identifier
            if (isset($item['label'])) {
                $label = $item['label'];
                if (is_array($label) && isset($label['_value'])) {
                    $identifier = (string) $label['_value'];
                    $this->logger->debug("Using organization label as identifier", [
                        'section' => $sectionName,
                        'identifier' => $identifier
                    ]);
                    return $identifier;
                }
                if (is_string($label)) {
                    $this->logger->debug("Using organization label string as identifier", [
                        'section' => $sectionName,
                        'identifier' => $label
                    ]);
                    return $label;
                }
            }
        }
        
        // Check various possible identifier locations for other sections
        $identifierKeys = ['identifier', 'id', 'name'];
        
        foreach ($identifierKeys as $key) {
            if (isset($item['_attributes'][$key])) {
                $identifier = (string) $item['_attributes'][$key];
                $this->logger->debug("Found identifier in attributes", [
                    'section' => $sectionName,
                    'key' => $key,
                    'identifier' => $identifier
                ]);
                return $identifier;
            }
            if (isset($item[$key])) {
                $value = $item[$key];
                if (is_array($value) && isset($value['_value'])) {
                    $identifier = (string) $value['_value'];
                    $this->logger->debug("Found identifier in nested value", [
                        'section' => $sectionName,
                        'key' => $key,
                        'identifier' => $identifier
                    ]);
                    return $identifier;
                }
                if (is_string($value)) {
                    $this->logger->debug("Found identifier as direct string", [
                        'section' => $sectionName,
                        'key' => $key,
                        'identifier' => $value
                    ]);
                    return $value;
                }
            }
        }

        // OPTIMIZATION: Removed warning logging from tight loop
        return null;
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
                'created' => date('Y-m-d H:i:s'),
                'updated' => date('Y-m-d H:i:s')
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
                'created' => date('Y-m-d H:i:s'),
                'updated' => date('Y-m-d H:i:s')
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
        $objectService = $this->getObjectService();
        if (!$objectService) {
            throw new \RuntimeException('ObjectService not available');
        }

        $this->logger->info('Saving objects to database using ObjectService::saveObjects', [
            'count' => count($objects)
        ]);

        // OPTIMIZATION: Use cached register ID
        $registerId = $this->cachedConfig['registerId'] ?? 15;

        // Save objects using ObjectService::saveObjects with proper @self structure
        $saveResult = $objectService->saveObjects(
            objects: $objects,
            register: $registerId
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
            
            // Count objects by section type from the actual saved objects
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
                
                // Check if this object had validation errors
                $hasErrors = !empty(array_filter($saveResult['invalid'] ?? [],
                    fn($invalid) => (($invalid['object']['@self']['id'] ?? null) === $objectId)));
                
                if ($wasCreated) {
                    $statistics[$sectionKey]['created']++;
                } elseif ($wasUpdated) {
                    $statistics[$sectionKey]['updated']++;
                } elseif ($hasErrors) {
                    // Add to errors array for this section
                    $errorInfo = array_filter($saveResult['invalid'] ?? [],
                        fn($invalid) => (($invalid['object']['@self']['id'] ?? null) === $objectId));
                    
                    if (!empty($errorInfo)) {
                        $statistics[$sectionKey]['errors'][] = array_values($errorInfo)[0]['error'] ?? 'Unknown validation error';
                    }
                } else {
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
                'created' => date('Y-m-d H:i:s'),
                'updated' => date('Y-m-d H:i:s')
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
                    'created' => date('Y-m-d H:i:s'),
                    'updated' => date('Y-m-d H:i:s')
                ],
                'identifier' => $identifier,
                'section' => $schemaType,
                'model_identifier' => $modelIdentifier,
                'xml' => $item // Store complete XML data for round-trip fidelity
            ];
            
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
        
        foreach ($props as $prop) {
            if (!isset($prop['_attributes']['propertyDefinitionRef'])) {
                continue;
            }
            
            $defRef = $prop['_attributes']['propertyDefinitionRef'];
            $value = $prop['value']['_value'] ?? $prop['value'] ?? null;
            
            if ($value !== null && isset($propertyDefinitionMap[$defRef])) {
                $propertyName = $propertyDefinitionMap[$defRef];
                $object[$propertyName] = $value;
                
                // Set slug for Object ID property
                if (strtolower($propertyName) === 'object id') {
                    $object['@self']['slug'] = $value;
                }
            }
        }
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

}