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
            $savedObjects = $objectService->saveObjects([$modelData]);
            
            if (empty($savedObjects)) {
                $this->logger->error('ArchiMateService: Failed to save model object');
                return ['success' => false, 'error' => 'Failed to save model object'];
            }

            $savedModelObject = $savedObjects[0];
            $modelAction = $this->determineObjectAction($savedModelObject);
            
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

    /**
     * Determine the action taken on an object during save operation
     * 
     * @param array $savedObject The saved object returned from ObjectService::saveObjects
     * @return string The action taken: 'created', 'updated', or 'unknown'
     */
    private function determineObjectAction(array $savedObject): string
    {
        // Check if the object was created or updated based on the response
        if (isset($savedObject['@self']['id'])) {
            // If we have an ID, the object was saved successfully
            // We can't easily determine if it was created or updated from the response
            // For now, assume it was updated if it has an ID
            return 'updated';
        }
        
        return 'unknown';
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
        
        $this->logger->debug('Extracted model metadata', [
            'metadata_keys' => array_keys($normalized['model_metadata']),
            'has_name' => isset($normalized['model_metadata']['name']),
            'has_documentation' => isset($normalized['model_metadata']['documentation'])
        ]);

        // STEP 2: Process each section and store complete raw XML data
        // This ensures round-trip fidelity - we can reconstruct the exact XML later
        $sections = ['elements', 'relationships', 'organizations', 'views', 'property_definitions'];
        
        $this->logger->debug("Available sections in data", [
            'available_sections' => array_keys($data),
            'sections_to_process' => $sections
        ]);
        
        // Check for alternative section names that might exist in the XML
        $alternativeNames = [
            'views' => ['views', 'diagrams'],
            'organizations' => ['organizations', 'organisation'],
            'property_definitions' => ['propertyDefinitions', 'property_definitions', 'propertydefinitions']
        ];
        
        foreach ($alternativeNames as $section => $alternatives) {
            foreach ($alternatives as $altName) {
                if (isset($data[$altName])) {
                    $this->logger->debug("Found alternative section name", [
                        'section' => $section,
                        'alternative_name' => $altName,
                        'data_type' => gettype($data[$altName]),
                        'data_keys' => is_array($data[$altName]) ? array_keys($data[$altName]) : []
                    ]);
                }
            }
        }
        
        foreach ($sections as $section) {
            $sectionData = null;
            $actualSectionName = null;
            
            // First try the direct section name
            if (isset($data[$section])) {
                $sectionData = $data[$section];
                $actualSectionName = $section;
            } else {
                // Try alternative names for this section
                if (isset($alternativeNames[$section])) {
                    foreach ($alternativeNames[$section] as $altName) {
                        if (isset($data[$altName])) {
                            $sectionData = $data[$altName];
                            $actualSectionName = $altName;
                            $this->logger->debug("Using alternative section name", [
                                'section' => $section,
                                'alternative_name' => $altName
                            ]);
                            break;
                        }
                    }
                }
            }
            
            if ($sectionData !== null) {
                $this->logger->debug("Processing section: {$section}", [
                    'actual_section_name' => $actualSectionName,
                    'section_data_type' => gettype($sectionData),
                    'section_data_count' => is_array($sectionData) ? count($sectionData) : 'not_array',
                    'section_data_keys' => is_array($sectionData) ? array_keys($sectionData) : []
                ]);
                
                $normalized[$section] = $this->extractSectionData($sectionData, $section, $modelIdentifier);
            } else {
                $this->logger->debug("Section not found: {$section}");
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
     * Extract data from a specific section with model linking
     * 
     * This method processes each section of the ArchiMate XML and extracts:
     * 1. Individual items (elements, relationships, organizations, views, property_definitions)
     * 2. Complete raw XML data for each item to ensure round-trip fidelity
     * 3. Links each item to the parent model via model_identifier
     * 
     * @param mixed $sectionData Section data from XML parsing
     * @param string $sectionName Name of the section being processed
     * @param string $modelIdentifier The model identifier for linking items
     * @return array Extracted section data with complete XML preservation
     */
    private function extractSectionData(mixed $sectionData, string $sectionName, string $modelIdentifier): array
    {
        $this->logger->debug("Extracting data from section: {$sectionName}", [
            'section_name' => $sectionName,
            'model_identifier' => $modelIdentifier,
            'data_type' => gettype($sectionData)
        ]);

        $extracted = [];
        
        // STEP 1: Handle different data structures (array, object, scalar)
        if (is_array($sectionData)) {
            $this->logger->debug("Section data structure", [
                'section' => $sectionName,
                'section_keys' => array_keys($sectionData),
                'section_data_sample' => array_slice($sectionData, 0, 2, true)
            ]);
            
            // STEP 2: Find the actual items within the section
            // Could be nested under child tags like <element>, <relationship>, etc.
            $items = $this->findItemsInSection($sectionData, $sectionName);
            
            $this->logger->debug("Found items in section", [
                'section' => $sectionName,
                'item_count' => count($items)
            ]);
            
            // STEP 3: Process each item and store complete XML data
            foreach ($items as $item) {
                $identifier = $this->extractIdentifier($item, $sectionName);
                if ($identifier) {
                    // Store XML data at root level with metadata fields
                    // This eliminates double JSON serialization and improves performance
                    $extracted[$identifier] = array_merge(
                        $item,                         // XML data at root level
                        [
                            'identifier' => $identifier,   // Unique identifier for the item
                            'section' => $sectionName,     // Section this item belongs to
                            'model_identifier' => $modelIdentifier, // Link to parent model
                            'extracted_at' => time()       // Timestamp for tracking
                        ]
                    );
                    
                    $this->logger->debug("Extracted item", [
                        'identifier' => $identifier,
                        'section' => $sectionName,
                        'model_identifier' => $modelIdentifier
                    ]);
                } else {
                    $this->logger->warning("Could not extract identifier from item", [
                        'section' => $sectionName,
                        'item_keys' => is_array($item) ? array_keys($item) : ['not_array']
                    ]);
                }
            }
        } else {
            $this->logger->warning("Section data is not an array", [
                'section' => $sectionName,
                'data_type' => gettype($sectionData),
                'data_value' => $sectionData
            ]);
        }

        $this->logger->info("Section extraction completed", [
            'section' => $sectionName,
            'items_extracted' => count($extracted),
            'model_identifier' => $modelIdentifier
        ]);

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
        $this->logger->debug("Finding items in section", [
            'section' => $sectionName,
            'section_data_keys' => is_array($sectionData) ? array_keys($sectionData) : ['not_array'],
            'section_data_type' => gettype($sectionData)
        ]);

        $items = [];
        
        // Safety check: ensure sectionData is an array
        if (!is_array($sectionData)) {
            $this->logger->warning("Section data is not an array", [
                'section' => $sectionName,
                'data_type' => gettype($sectionData),
                'data_value' => $sectionData
            ]);
            return [];
        }
        
        // Get section structure configuration from AMEF config
        $config = $this->getSectionStructureConfig($sectionName);
        
        $this->logger->debug("Section structure config", [
            'section' => $sectionName,
            'config' => $config
        ]);
        
        // Special handling for views with diagrams structure
        if ($sectionName === 'views') {
            $this->logger->debug("Special handling for views section", [
                'section_keys' => array_keys($sectionData)
            ]);
            
            // Handle nested structure: <views><diagrams><view>
            if (isset($sectionData['diagrams'])) {
                $this->logger->debug("Found diagrams structure in views", [
                    'diagrams_type' => gettype($sectionData['diagrams']),
                    'diagrams_keys' => is_array($sectionData['diagrams']) ? array_keys($sectionData['diagrams']) : []
                ]);
                
                if (isset($sectionData['diagrams']['view'])) {
                    $viewArray = $sectionData['diagrams']['view'];
                    $this->logger->debug("Found view array in diagrams", [
                        'view_array_type' => gettype($viewArray),
                        'view_array_count' => is_array($viewArray) ? count($viewArray) : 'not_array',
                        'is_single_view' => !isset($viewArray[0]) && isset($viewArray['_attributes'])
                    ]);
                    
                    // Handle single view vs array of views
                    if (!isset($viewArray[0]) && isset($viewArray['_attributes'])) {
                        // Single view
                        $items = [$viewArray];
                    } else {
                        // Array of views
                        $items = $viewArray;
                    }
                    
                    $this->logger->debug("Processed views from diagrams structure", [
                        'items_count' => count($items)
                    ]);
                }
            } else {
                // Direct views structure (fallback)
                if (isset($sectionData['view'])) {
                    $items = $sectionData['view'];
                    $this->logger->debug("Found direct view structure", [
                        'items_count' => is_array($items) ? count($items) : 'not_array'
                    ]);
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
                    $this->logger->debug("Found data at path", [
                        'section' => $sectionName,
                        'path' => $path,
                        'data_keys' => array_keys($currentData),
                        'data_type' => gettype($currentData)
                    ]);
                    
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
                    $this->logger->debug("Found direct tag", [
                        'section' => $sectionName,
                        'tag' => $tag,
                        'data_keys' => is_array($sectionData[$tag]) ? array_keys($sectionData[$tag]) : ['not_array']
                    ]);
                    $items = $sectionData[$tag];
                    break;
                }
            }
        }
        
        // If still no items found, treat the section itself as items
        if (empty($items)) {
            $this->logger->debug("No items found in section, treating section as items", [
                'section' => $sectionName,
                'section_keys' => is_array($sectionData) ? array_keys($sectionData) : ['not_array']
            ]);
            $items = [$sectionData];
        }
        
        // Ensure items is always an array
        if (!is_array($items)) {
            $this->logger->debug("Items is not an array, wrapping in array", [
                'section' => $sectionName,
                'items_type' => gettype($items)
            ]);
            $items = [$items];
        }
        
        // If items is an associative array with numeric keys, convert to indexed array
        if ($this->isAssociativeArray($items)) {
            $this->logger->debug("Converting associative array to indexed array", [
                'section' => $sectionName,
                'items_keys' => array_keys($items)
            ]);
            $items = array_values($items);
        }
        
        $this->logger->debug("Final items found", [
            'section' => $sectionName,
            'item_count' => count($items),
            'first_item_keys' => !empty($items) && is_array($items[0]) ? array_keys($items[0]) : [],
            'section_data_keys' => is_array($sectionData) ? array_keys($sectionData) : ['not_array']
        ]);
        
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
        $this->logger->debug("Extracting identifier", [
            'section' => $sectionName,
            'item_keys' => array_keys($item),
            'item_has_attributes' => isset($item['_attributes']),
            'attributes_keys' => isset($item['_attributes']) ? array_keys($item['_attributes']) : []
        ]);
        
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

        $this->logger->warning("No identifier found for item", [
            'section' => $sectionName,
            'item_keys' => array_keys($item),
            'item_has_attributes' => isset($item['_attributes']),
            'attributes_keys' => isset($item['_attributes']) ? array_keys($item['_attributes']) : []
        ]);

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
        
        foreach ($sections as $section) {
            if (!empty($normalizedData[$section]) && is_array($normalizedData[$section])) {
                $this->logger->debug("Converting section: {$section}", [
                    'item_count' => count($normalizedData[$section]),
                    'section_keys' => array_keys($normalizedData[$section])
                ]);
                
                foreach ($normalizedData[$section] as $identifier => $data) {
                    $objects[] = $this->createSectionObject($section, $identifier, $data, $modelIdentifier);
                }
            } else {
                $this->logger->debug("Section empty or not found: {$section}", [
                    'section_exists' => isset($normalizedData[$section]),
                    'section_type' => isset($normalizedData[$section]) ? gettype($normalizedData[$section]) : 'not_set',
                    'section_empty' => isset($normalizedData[$section]) ? empty($normalizedData[$section]) : 'not_set'
                ]);
            }
        }

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
        // Get AMEF configuration for register and schema IDs
        $registerId = $this->getAmefRegisterId();
        $schemaId = $this->getAmefSchemaIdForType('model');
        
        if (!$registerId || !$schemaId) {
            $this->logger->warning('AMEF register or model schema not configured, using fallback values', [
                'registerId' => $registerId,
                'schemaId' => $schemaId
            ]);
            // Fallback to hardcoded values if AMEF config is not available
            $registerId = $registerId ?: 15; // Default AMEF register ID
            $schemaId = $schemaId ?: 67; // Default model schema ID
        }
        
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
        // Get AMEF configuration for register and schema IDs
        $registerId = $this->getAmefRegisterId();
        $schemaId = $this->getAmefSchemaIdForType($section);
        
        if (!$registerId || !$schemaId) {
            $this->logger->warning('AMEF register or schema not configured for section, using fallback values', [
                'section' => $section,
                'registerId' => $registerId,
                'schemaId' => $schemaId
            ]);
            // Fallback to hardcoded values if AMEF config is not available
            $registerId = $registerId ?: 15; // Default AMEF register ID
            $schemaId = $schemaId ?: $this->getSchemaIdForSection($section); // Fallback to hardcoded schema
        }
        
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

        // Get AMEF configuration for register ID
        $registerId = $this->getAmefRegisterId();
        
        if (!$registerId) {
            $this->logger->warning('AMEF register not configured, using fallback value', [
                'registerId' => $registerId
            ]);
            // Fallback to hardcoded value if AMEF config is not available
            $registerId = $registerId ?: 15; // Default AMEF register ID
        }

        // Save objects using ObjectService::saveObjects with proper @self structure
        $savedObjects = $objectService->saveObjects(
            objects: $objects,
            register: $registerId
        );

        $this->logger->info('Objects saved successfully', [
            'saved_count' => count($savedObjects)
        ]);

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
     * Get current user ID
     * 
     * @return string|null Current user ID or null if not authenticated
     */
    private function getCurrentUserId(): ?string
    {
        $user = $this->userSession->getUser();
        return $user ? $user->getUID() : null;
    }

    /**
     * Get current organisation
     * 
     * @return string Default organisation
     */
    private function getCurrentOrganisation(): string
    {
        return 'default';
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

        // Count objects by type from normalized data
        $sections = ['elements', 'relationships', 'organizations', 'views', 'property_definitions'];
        foreach ($sections as $section) {
            if (isset($normalizedData[$section])) {
                $count = count($normalizedData[$section]);
                // Assume all objects were created (we can refine this later with actual save results)
                $statistics[$section]['created'] = $count;
            }
        }

        // Calculate summary totals
        $summary = [
            'total_objects_created' => 0,
            'total_objects_updated' => 0,
            'total_objects_deleted' => 0,
            'total_objects_skipped' => 0,
            'total_errors' => 0
        ];

        foreach ($statistics as $section => $sectionStats) {
            $summary['total_objects_created'] += $sectionStats['created'];
            $summary['total_objects_updated'] += $sectionStats['updated'];
            $summary['total_objects_skipped'] += $sectionStats['skipped'];
            $summary['total_errors'] += count($sectionStats['errors']);
        }

        $statistics['summary'] = $summary;

        return $statistics;
    }


}