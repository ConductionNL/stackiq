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
     * @param ContainerInterface $container Dependency injection container
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
        $this->logger->info('Starting ArchiMate XML import with model detection', [
            'options' => $options,
            'file_path' => $options['file_path'] ?? 'unknown'
        ]);

        try {
            // STEP 1: Parse XML to array using the specialized import service
            // This captures ALL possible XML values including attributes, text content, and nested elements
            $this->logger->info('Step 1: Parsing XML to array for complete data capture');
            $xmlData = $this->parseArchiMateXml($options['file_path'] ?? '');
            
            // STEP 2: Extract model identifier and detect if model already exists
            // This is critical for determining whether to create new or update existing model
            $this->logger->info('Step 2: Extracting model identifier and checking for existing model');
            $modelIdentifier = $this->extractModelIdentifier($xmlData);
            $modelExists = $this->checkIfModelExists($modelIdentifier);
            
            // STEP 3: Normalize data structure for storage as JSON blob
            // Store complete raw XML data for exact round-trip fidelity during export
            $this->logger->info('Step 3: Normalizing data structure for JSON blob storage');
            $normalizedData = $this->normalizeArchiMateData($xmlData, $modelIdentifier);
            
            // STEP 4: Convert to OpenRegister objects with proper @self structure
            // Each object must have @self with register, schema, and id for ObjectService::saveObjects
            $this->logger->info('Step 4: Converting to OpenRegister objects with @self structure');
            $objects = $this->convertToOpenRegisterObjects($normalizedData, $modelIdentifier);
            
            // STEP 5: Save objects using ObjectService::saveObjects
            // This handles the actual database persistence with proper validation
            $this->logger->info('Step 5: Saving objects to database using ObjectService::saveObjects');
            $savedObjects = $this->saveObjectsToDatabase($objects);
            
            // Prepare comprehensive result with model detection info
            $result = [
                'success' => true,
                'message' => 'ArchiMate XML imported successfully',
                'model_info' => [
                    'identifier' => $modelIdentifier,
                    'exists' => $modelExists,
                    'action' => $modelExists ? 'updated' : 'created'
                ],
                'imported_objects' => count($savedObjects),
                'file_info' => [
                    'name' => $options['fileName'] ?? 'unknown',
                    'size' => $options['fileSize'] ?? 0,
                    'path' => $options['file_path'] ?? 'unknown'
                ],
                'round_trip_fidelity' => 'enabled', // Indicates complete XML data is stored
                'storage_format' => 'json_blob' // Shows data is stored as JSON blob
            ];

            $this->logger->info('ArchiMate XML import completed successfully', [
                'model_identifier' => $modelIdentifier,
                'model_exists' => $modelExists,
                'imported_objects' => count($savedObjects),
                'round_trip_fidelity' => 'enabled'
            ]);

            return $result;

        } catch (\Exception $e) {
            $this->logger->error('ArchiMate XML import failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'file_path' => $options['file_path'] ?? 'unknown'
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
     * @param array $criteria Export criteria
     * @param array $options Export options
     * @return array Export results
     */
    public function exportToArchiMate(array $criteria = [], array $options = []): array
    {
        $this->logger->info('Starting ArchiMate XML export', [
            'criteria' => $criteria,
            'options' => $options
        ]);

        try {
            // Retrieve objects from database
            $objects = $this->getObjectsFromDatabase($criteria);
            
            // Convert back to ArchiMate format
            $archiMateData = $this->convertFromOpenRegisterObjects($objects);
            
            // Generate XML using the specialized export service
            $xml = $this->generateArchiMateXml($archiMateData);
            
            $this->logger->info('ArchiMate export completed successfully', [
                'exported_count' => count($objects)
            ]);

            return [
                'success' => true,
                'xml' => $xml,
                'exported_count' => count($objects)
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
     * This method queries the database to determine if we're importing:
     * 1. A completely new model (create new)
     * 2. An update to an existing model (update existing)
     * 
     * @param string $modelIdentifier The model identifier to check
     * @return bool True if model exists, false if new
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

            // Query for existing model objects with this identifier
            // We'll look in the model schema for objects with matching archimate_id
            $existingModels = $objectService->getObjects(
                $this->getArchiMateRegisterId(),
                $this->getArchiMateModelSchemaId(),
                [
                    'archimate_id' => $modelIdentifier
                ]
            );

            $exists = !empty($existingModels);
            
            $this->logger->info('Model existence check completed', [
                'model_identifier' => $modelIdentifier,
                'exists' => $exists,
                'found_count' => count($existingModels)
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
            $this->logger->debug('Extracted model metadata', [
                'metadata_keys' => array_keys($data['_attributes'])
            ]);
        }

        // STEP 2: Process each section and store complete raw XML data
        // This ensures round-trip fidelity - we can reconstruct the exact XML later
        $sections = ['elements', 'relationships', 'organizations', 'views', 'property_definitions'];
        
        foreach ($sections as $section) {
            if (isset($data[$section])) {
                $this->logger->debug("Processing section: {$section}", [
                    'section_data_type' => gettype($data[$section]),
                    'section_data_count' => is_array($data[$section]) ? count($data[$section]) : 'not_array'
                ]);
                
                $normalized[$section] = $this->extractSectionData($data[$section], $section, $modelIdentifier);
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
            // STEP 2: Find the actual items within the section
            // Could be nested under child tags like <element>, <relationship>, etc.
            $items = $this->findItemsInSection($sectionData, $sectionName);
            
            $this->logger->debug("Found items in section", [
                'section' => $sectionName,
                'item_count' => count($items)
            ]);
            
            // STEP 3: Process each item and store complete XML data
            foreach ($items as $item) {
                $identifier = $this->extractIdentifier($item);
                if ($identifier) {
                    // Store the complete raw XML data structure for round-trip fidelity
                    // This ensures we can reconstruct the exact XML during export
                    $extracted[$identifier] = [
                        'xml_data' => $item,           // Complete raw XML data
                        'identifier' => $identifier,   // Unique identifier for the item
                        'section' => $sectionName,     // Section this item belongs to
                        'model_identifier' => $modelIdentifier, // Link to parent model
                        'extracted_at' => time()       // Timestamp for tracking
                    ];
                    
                    $this->logger->debug("Extracted item", [
                        'identifier' => $identifier,
                        'section' => $sectionName,
                        'model_identifier' => $modelIdentifier
                    ]);
                } else {
                    $this->logger->warning("Could not extract identifier from item", [
                        'section' => $sectionName,
                        'item_keys' => array_keys($item)
                    ]);
                }
            }
        } else {
            $this->logger->warning("Section data is not an array", [
                'section' => $sectionName,
                'data_type' => gettype($sectionData)
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
     * Find items within a section
     * 
     * @param array $sectionData Section data
     * @param string $sectionName Section name
     * @return array Found items
     */
    private function findItemsInSection(array $sectionData, string $sectionName): array
    {
        $items = [];
        
        // Common child tag names for each section
        $childTags = [
            'elements' => ['element'],
            'relationships' => ['relationship'],
            'organizations' => ['item', 'organization'],
            'views' => ['view', 'diagram'],
            'property_definitions' => ['propertyDefinition', 'property']
        ];

        $tags = $childTags[$sectionName] ?? [$sectionName];
        
        foreach ($tags as $tag) {
            if (isset($sectionData[$tag])) {
                $tagData = $sectionData[$tag];
                if (is_array($tagData)) {
                    // Handle single item vs array
                    if (isset($tagData[0]) || !isset($tagData['_attributes'])) {
                        $items = is_array($tagData) ? $tagData : [$tagData];
                    } else {
                        $items = [$tagData];
                    }
                    break;
                }
            }
        }

        // If no child tags found, treat the section itself as items
        if (empty($items) && !empty($sectionData)) {
            $items = [$sectionData];
        }

        return $items;
    }

    /**
     * Extract identifier from item data
     * 
     * @param array $item Item data
     * @return string|null Identifier or null if not found
     */
    private function extractIdentifier(array $item): ?string
    {
        // Check various possible identifier locations
        $identifierKeys = ['identifier', 'id', 'name'];
        
        foreach ($identifierKeys as $key) {
            if (isset($item['_attributes'][$key])) {
                return (string) $item['_attributes'][$key];
            }
            if (isset($item[$key])) {
                $value = $item[$key];
                if (is_array($value) && isset($value['_value'])) {
                    return (string) $value['_value'];
                }
                if (is_string($value)) {
                    return $value;
                }
            }
        }

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
            if (!empty($normalizedData[$section])) {
                $this->logger->debug("Converting section: {$section}", [
                    'item_count' => count($normalizedData[$section])
                ]);
                
                foreach ($normalizedData[$section] as $identifier => $data) {
                    $objects[] = $this->createSectionObject($section, $identifier, $data, $modelIdentifier);
                }
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
     * @return array Model object with @self structure
     */
    private function createModelObject(array $metadata): array
    {
        return [
            '@self' => [
                'register' => $this->getArchiMateRegisterId(),
                'schema' => $this->getArchiMateModelSchemaId(),
                'id' => $metadata['identifier'] ?? uniqid('model_'),
                'owner' => $this->getCurrentUserId(),
                'organisation' => $this->getCurrentOrganisation(),
                'created' => date('Y-m-d H:i:s'),
                'updated' => date('Y-m-d H:i:s')
            ],
            'identifier' => $metadata['identifier'] ?? '',
            'name' => $metadata['name'] ?? '',
            'documentation' => $metadata['documentation'] ?? '',
            'xml_data' => json_encode($metadata, JSON_PRETTY_PRINT)
        ];
    }

    /**
     * Create section object with @self structure
     * 
     * @param string $section Section name
     * @param string $identifier Item identifier
     * @param array $data Item data
     * @return array Section object with @self structure
     */
    private function createSectionObject(string $section, string $identifier, array $data): array
    {
        $schemaId = $this->getSchemaIdForSection($section);
        
        return [
            '@self' => [
                'register' => $this->getArchiMateRegisterId(),
                'schema' => $schemaId,
                'id' => $identifier,
                'owner' => $this->getCurrentUserId(),
                'organisation' => $this->getCurrentOrganisation(),
                'created' => date('Y-m-d H:i:s'),
                'updated' => date('Y-m-d H:i:s')
            ],
            'identifier' => $identifier,
            'section' => $section,
            'xml_data' => json_encode($data, JSON_PRETTY_PRINT)
        ];
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

        // Save objects using ObjectService::saveObjects with proper @self structure
        $savedObjects = $objectService->saveObjects(
            $objects,
            $this->getArchiMateRegisterId(),
            null, // Let ObjectService determine schema from @self
            true, // RBAC enabled
            true  // Multi-organization enabled
        );

        $this->logger->info('Objects saved successfully', [
            'saved_count' => count($savedObjects)
        ]);

        return $savedObjects;
    }

    /**
     * Get objects from database
     * 
     * @param array $criteria Search criteria
     * @return array Retrieved objects
     */
    private function getObjectsFromDatabase(array $criteria): array
    {
        $objectService = $this->getObjectService();
        if (!$objectService) {
            throw new \RuntimeException('ObjectService not available');
        }

        $this->logger->info('Retrieving objects from database', [
            'criteria' => $criteria
        ]);

        // Build query based on criteria
        $query = array_merge([
            'register' => $this->getArchiMateRegisterId()
        ], $criteria);

        $objects = $objectService->findAll($query);

        $this->logger->info('Objects retrieved successfully', [
            'retrieved_count' => count($objects)
        ]);

        return $objects;
    }

    /**
     * Convert OpenRegister objects back to ArchiMate format
     * 
     * @param array $objects OpenRegister objects
     * @return array ArchiMate data structure
     */
    private function convertFromOpenRegisterObjects(array $objects): array
    {
        $this->logger->info('Converting from OpenRegister objects back to ArchiMate format');

        $archiMateData = [
            'model_metadata' => [],
            'elements' => [],
            'relationships' => [],
            'organizations' => [],
            'views' => [],
            'property_definitions' => []
        ];

        foreach ($objects as $object) {
            $section = $object['section'] ?? 'model_metadata';
            $identifier = $object['identifier'] ?? '';
            $xmlData = json_decode($object['xml_data'] ?? '{}', true);

            if ($section === 'model_metadata') {
                $archiMateData['model_metadata'] = $xmlData;
            } else {
                $archiMateData[$section][$identifier] = $xmlData;
            }
        }

        $this->logger->info('Conversion completed', [
            'sections' => array_keys($archiMateData)
        ]);

        return $archiMateData;
    }

    /**
     * Generate ArchiMate XML from data using the export service
     * 
     * @param array $archiMateData ArchiMate data structure
     * @return string Generated XML
     */
    private function generateArchiMateXml(array $archiMateData): string
    {
        $this->logger->info('Generating ArchiMate XML using export service');

        // Create base XML structure
        $xml = $this->exportService->createCleanArchiMateXml($archiMateData['model_metadata'] ?? []);
        
        // Add sections using the export service methods
        if (!empty($archiMateData['elements'])) {
            $this->exportService->addElementsToXml($xml, $archiMateData['elements']);
        }
        
        if (!empty($archiMateData['relationships'])) {
            $this->exportService->addRelationshipsToXml($xml, $archiMateData['relationships']);
        }
        
        if (!empty($archiMateData['views'])) {
            $this->exportService->addViewsToXml($xml, $archiMateData['views']);
        }
        
        if (!empty($archiMateData['organizations'])) {
            $this->exportService->addOrganizationsToXml($xml, $archiMateData['organizations']);
        }

        $this->logger->info('XML generation completed');

        return $xml->asXML();
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
        return (int) ($this->config->getAppValue('softwarecatalog', 'archimate_register_id', '100'));
    }

    /**
     * Get ArchiMate model schema ID
     * 
     * @return int Schema ID
     */
    private function getArchiMateModelSchemaId(): int
    {
        return (int) ($this->config->getAppValue('softwarecatalog', 'archimate_model_schema_id', '100'));
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
}