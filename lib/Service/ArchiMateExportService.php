<?php

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Service;

use Psr\Log\LoggerInterface;

/**
 * ArchiMate Export Service
 *
 * Provides generic array → XML conversion helpers for the AMEF export flow.
 * Respects the convention that attributes are stored with a leading underscore
 * and namespaced attributes use a `prefix__name` key (double underscore).
 */
class ArchiMateExportService
{
    public function __construct(
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Convert an associative array into a SimpleXMLElement tree.
     *
     * Conventions handled:
     * - Keys starting with `_` are written as attributes. Namespaced attributes
     *   use `prefix__name` and will be emitted as `prefix:name` if the namespace
     *   exists on the element.
     * - `_value` key is treated as node text content.
     * - `_text` key is treated as mixed content text.
     * - Numeric arrays produce repeated child elements with the same tag name.
     */
    public function arrayToXml(array $data, \SimpleXMLElement $xml): \SimpleXMLElement
    {
        // First pass: attributes and text content
        $addedAttributes = []; // Track attributes to avoid duplicates
        
        foreach ($data as $key => $value) {
            if ($key === '_value' || $key === '_text') {
                $xml[0] = (string) $value;
                continue;
            }

            if (is_string($key) && str_starts_with($key, '_') && $key !== '_attributes') {
                // Skip legacy _attributes bag, handle individual underscored keys as attributes
                $attrKey = substr($key, 1);
                
                // Fix double underscores to colons (e.g., xml__lang -> xml:lang)
                $attrKey = str_replace('__', ':', $attrKey);
                
                // Skip if this attribute was already added
                if (in_array($attrKey, $addedAttributes)) {
                    continue;
                }
                
                [$nsPrefix, $local] = $this->splitNamespacedKey($attrKey);

                if ($nsPrefix !== null) {
                    // Namespaced attribute, ensure namespace is declared on element
                    $nsUri = $this->getNamespaceUri($xml, $nsPrefix);
                    if ($nsUri) {
                        $xml->addAttribute($nsPrefix . ':' . $local, (string) $value, $nsUri);
                        $addedAttributes[] = $nsPrefix . ':' . $local;
                    } else {
                        // Fallback to non-namespaced if namespace not found
                        $xml->addAttribute($attrKey, (string) $value);
                        $addedAttributes[] = $attrKey;
                    }
                } else {
                    $xml->addAttribute($local, (string) $value);
                    $addedAttributes[] = $local;
                }
            }
        }

        // Handle legacy _attributes array with duplicate filtering
        if (isset($data['_attributes']) && is_array($data['_attributes'])) {
            foreach ($data['_attributes'] as $attrKey => $attrValue) {
                // Skip duplicate attributes with colon prefix or already added attributes
                if (str_starts_with($attrKey, ':') || in_array($attrKey, $addedAttributes)) {
                    continue;
                }
                
                // Fix double underscores to colons
                $cleanAttrKey = str_replace('__', ':', $attrKey);
                
                // Skip if cleaned version was already added
                if (in_array($cleanAttrKey, $addedAttributes)) {
                    continue;
                }
                
                $xml->addAttribute($cleanAttrKey, (string) $attrValue);
                $addedAttributes[] = $cleanAttrKey;
            }
        }

        // Second pass: children
        foreach ($data as $key => $value) {
            if ($key === '_value' || $key === '_text' || $key === '_attributes') {
                continue;
            }
            if (is_string($key) && str_starts_with($key, '_')) {
                // Already handled as attribute
                continue;
            }

            // Skip numeric keys - they indicate array items that should be handled differently
            if (is_int($key)) {
                continue;
            }

            // Ensure key is always a string for XML tag names
            $tagName = (string) $key;

            if (is_array($value)) {
                // Handle list of children
                if ($this->isList($value)) {
                    foreach ($value as $item) {
                        $child = $xml->addChild($tagName);
                        if (is_array($item)) {
                            $this->arrayToXml($item, $child);
                        } else {
                            $child[0] = (string) $item;
                        }
                    }
                } else {
                    $child = $xml->addChild($tagName);
                    $this->arrayToXml($value, $child);
                }
            } else {
                // Scalar child node
                $child = $xml->addChild($tagName);
                $child[0] = (string) $value;
            }
        }

        return $xml;
    }

    private function isList(array $arr): bool
    {
        return $arr === [] || array_keys($arr) === range(0, count($arr) - 1);
    }

    private function splitNamespacedKey(string $key): array
    {
        // Convert `xsi__type` to ['xsi', 'type']
        if (str_contains($key, '__')) {
            $parts = explode('__', $key, 2);
            if (count($parts) === 2 && $parts[0] !== '' && $parts[1] !== '') {
                return [$parts[0], $parts[1]];
            }
        }
        return [null, $key];
    }

    private function getNamespaceUri(\SimpleXMLElement $xml, string $prefix): string
    {
        $namespaces = $xml->getDocNamespaces(true) ?: [];
        return $namespaces[$prefix] ?? '';
    }

    /**
     * Create a clean ArchiMate XML structure with proper namespaces
     *
     * @param array $modelMetadata Model metadata from the database
     * @return \SimpleXMLElement Root XML element ready for population
     */
    public function createCleanArchiMateXml(array $modelMetadata): \SimpleXMLElement
    {
        $modelName = $modelMetadata['name'] ?? 'ArchiMate Model';
        $modelId = $modelMetadata['identifier'] ?? 'model-' . uniqid();

        $xmlString = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<model xmlns="http://www.opengroup.org/xsd/archimate/3.0/" 
       xmlns:xml="http://www.w3.org/XML/1998/namespace"
       xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" 
       xsi:schemaLocation="http://www.opengroup.org/xsd/archimate/3.0/ http://www.opengroup.org/xsd/archimate/3.1/archimate3_Diagram.xsd" 
       identifier="{$modelId}">
</model>
XML;

        $xml = simplexml_load_string($xmlString);
        if (!$xml) {
            throw new \RuntimeException('Failed to create base ArchiMate XML structure');
        }

        return $xml;
    }

    /**
     * Generic method to add any collection of objects to XML
     *
     * @param \SimpleXMLElement $xml Root XML element
     * @param array $objects Array of objects from database
     * @param string $folderName Name for the folder
     * @param string $folderId ID for the folder
     * @param string $folderType Type attribute for the folder
     * @param string $childTagName Tag name for child elements (default: 'element')
     * @return void
     */
    public function addObjectsToXml(
        \SimpleXMLElement $xml, 
        array $objects, 
        string $folderName, 
        string $folderId, 
        string $folderType, 
        string $childTagName = 'element'
    ): void {
        if (empty($objects)) {
            return;
        }

        $folder = $xml->addChild('folder');
        $folder->addAttribute('name', $folderName);
        $folder->addAttribute('id', $folderId);
        $folder->addAttribute('type', $folderType);

        foreach ($objects as $object) {
            $this->addObjectToFolder($folder, $object, $childTagName);
        }
    }

    /**
     * Convenience method for elements
     */
    public function addElementsToXml(\SimpleXMLElement $xml, array $elements): void
    {
        $this->addObjectsToXml($xml, $elements, 'Application', 'folder-elements', 'application', 'element');
    }

    /**
     * Convenience method for relationships
     */
    public function addRelationshipsToXml(\SimpleXMLElement $xml, array $relationships): void
    {
        $this->addObjectsToXml($xml, $relationships, 'Relations', 'folder-relations', 'relations', 'element');
    }

    /**
     * Specialized method for views with custom node handling
     */
    public function addViewsToXml(\SimpleXMLElement $xml, array $views): void
    {
        $this->logger->debug('Adding views to XML', [
            'view_count' => count($views),
            'view_keys' => array_keys($views)
        ]);
        
        if (empty($views)) {
            $this->logger->warning('No views to process');
            return;
        }

        $folder = $xml->addChild('folder');
        $folder->addAttribute('name', 'Views');
        $folder->addAttribute('id', 'folder-views');
        $folder->addAttribute('type', 'diagrams');

        foreach ($views as $view) {
            $this->addViewToFolder($folder, $view);
        }
    }

    /**
     * Convenience method for organizations
     */
    public function addOrganizationsToXml(\SimpleXMLElement $xml, array $organizations): void
    {
        $this->addObjectsToXml($xml, $organizations, 'Organizations', 'folder-organizations', 'business', 'item');
    }

    /**
     * Specialized method to add a view to the views folder with custom node handling
     */
    private function addViewToFolder(\SimpleXMLElement $folder, array $view): void
    {
        $viewNode = $folder->addChild('view');

        // Extract view data from different formats
        $viewData = $this->extractViewData($view);
        
        if (!$viewData) {
            $this->logger->warning('No valid view data found', [
                'view_keys' => array_keys($view),
                'view_structure' => $view
            ]);
            return;
        }

        // DEBUG: Check if this is our target view with nodes
        if (isset($viewData['_identifier']) && $viewData['_identifier'] === 'id-1c197dc3-71e5-40dc-8f5d-a96e983b41af') {
            $this->logger->debug('Found target view with specific ID', [
                'identifier' => $viewData['_identifier'],
                'raw_view' => $view,
                'extracted_view_data' => $viewData,
                'node_analysis' => [
                    'has_node' => isset($viewData['node']),
                    'node_count' => is_array($viewData['node'] ?? null) ? count($viewData['node']) : 0,
                    'node_sample' => isset($viewData['node'][0]) ? $viewData['node'][0] : 'NO FIRST NODE'
                ]
            ]);
        }

        $this->logger->debug('Processing view with custom logic', [
            'has_node' => isset($viewData['node']),
            'node_count' => is_array($viewData['node'] ?? null) ? count($viewData['node']) : 0,
            'has_connection' => isset($viewData['connection']),
            'connection_count' => is_array($viewData['connection'] ?? null) ? count($viewData['connection']) : 0
        ]);

        // Process view attributes and basic properties
        $this->addViewBasicData($viewNode, $viewData);

        // Process nodes with special handling
        if (isset($viewData['node']) && is_array($viewData['node'])) {
            $this->addViewNodes($viewNode, $viewData['node']);
        }

        // Process connections with special handling
        if (isset($viewData['connection']) && is_array($viewData['connection'])) {
            $this->addViewConnections($viewNode, $viewData['connection']);
        }
    }

    /**
     * Extract view data from different possible formats
     */
    private function extractViewData(array $view): ?array
    {
        // Format 1: OpenRegister object format with properties.xml_data
        if (isset($view['properties']['xml_data'])) {
            $xmlData = is_string($view['properties']['xml_data']) ? 
                json_decode($view['properties']['xml_data'], true) : 
                $view['properties']['xml_data'];
            return is_array($xmlData) ? $xmlData : null;
        }
        
        // Format 2: Object with xml_data field (from database)
        if (isset($view['xml_data'])) {
            $xmlData = is_string($view['xml_data']) ? 
                json_decode($view['xml_data'], true) : 
                $view['xml_data'];
            return is_array($xmlData) ? $xmlData : null;
        }
        
        // Format 3: Direct XML data (from convertFromOpenRegisterObjects)
        return $view;
    }

    /**
     * Add basic view data (attributes, name, documentation, properties) to view node
     */
    private function addViewBasicData(\SimpleXMLElement $viewNode, array $viewData): void
    {
        // Add attributes
        if (isset($viewData['_attributes'])) {
            foreach ($viewData['_attributes'] as $attrKey => $attrValue) {
                if (str_starts_with($attrKey, ':')) {
                    continue; // Skip duplicate attributes with colon prefix
                }
                $viewNode->addAttribute($attrKey, (string)$attrValue);
            }
        }

        // Add identifier directly if present
        if (isset($viewData['_identifier']) || isset($viewData['identifier'])) {
            $identifier = $viewData['_identifier'] ?? $viewData['identifier'];
            $viewNode->addAttribute('identifier', (string)$identifier);
        }

        // Add xsi:type if present
        foreach (['_xsi__type', 'xsi:type', '_xsi:type'] as $typeKey) {
            if (isset($viewData[$typeKey])) {
                $viewNode->addAttribute('xsi:type', (string)$viewData[$typeKey]);
                break;
            }
        }

        // Add name, documentation, and properties using the generic arrayToXml method
        // but exclude node and connection arrays to handle them separately
        $basicData = array_diff_key($viewData, ['node' => true, 'connection' => true]);
        $this->arrayToXml($basicData, $viewNode);
    }

    /**
     * Add view nodes with proper nested structure handling
     */
    private function addViewNodes(\SimpleXMLElement $viewNode, array $nodes): void
    {
        foreach ($nodes as $nodeData) {
            $node = $viewNode->addChild('node');
            $this->arrayToXml($nodeData, $node);
        }
    }

    /**
     * Add view connections with proper nested structure handling
     */
    private function addViewConnections(\SimpleXMLElement $viewNode, array $connections): void
    {
        foreach ($connections as $connectionData) {
            $connection = $viewNode->addChild('connection');
            $this->arrayToXml($connectionData, $connection);
        }
    }

    /**
     * Generic method to add any object to a folder - determines everything from the JSON data
     */
    private function addObjectToFolder(\SimpleXMLElement $folder, array $object, string $childTagName = 'element'): void
    {
        $objectNode = $folder->addChild($childTagName);
        
        // Handle different data formats:
        // 1. OpenRegister object format with properties.xml_data
        // 2. Direct XML data from convertFromOpenRegisterObjects 
        // 3. Raw object data as fallback
        
        if (isset($object['properties']['xml_data'])) {
            // Format 1: OpenRegister object format
            $xmlData = is_string($object['properties']['xml_data']) ? 
                json_decode($object['properties']['xml_data'], true) : 
                $object['properties']['xml_data'];
            
            if (is_array($xmlData)) {
                $this->arrayToXml($xmlData, $objectNode);
            }
        } elseif (isset($object['xml_data'])) {
            // Format 2: Object with xml_data field (from database)
            $xmlData = is_string($object['xml_data']) ? 
                json_decode($object['xml_data'], true) : 
                $object['xml_data'];
            
            if (is_array($xmlData)) {
                $this->arrayToXml($xmlData, $objectNode);
            }
        } else {
            // Format 3: Direct XML data (from convertFromOpenRegisterObjects)
            // This handles the case where $archiMateData['views'][$identifier] = $xmlData
            $this->arrayToXml($object, $objectNode);
        }
    }

    /**
     * Get all objects from the AMEF register
     * 
     * @param \OCA\OpenRegister\Service\ObjectService $objectService OpenRegister ObjectService
     * @param int $registerId AMEF register ID
     * @return array Array of objects from all schemas in the register
     * @throws \RuntimeException If retrieval fails
     */
    public function getObjectsFromDatabase(\OCA\OpenRegister\Service\ObjectService $objectService, int $registerId): array
    {
        $this->logger->info('Retrieving all objects from AMEF register', [
            'register_id' => $registerId
        ]);

        // Build simple query for all objects in the register (all schemas)
        $query = [
            '@self' => [
                'register' => $registerId
            ]
        ];

        try {
            $allObjects = $objectService->searchObjects(query: $query, rbac: false, multi: false);
            
            $this->logger->info('Objects retrieved successfully from AMEF register', [
                'total_retrieved_count' => count($allObjects),
                'register_id' => $registerId
            ]);

            return $allObjects;
            
        } catch (\Exception $e) {
            $this->logger->error("Failed to retrieve objects from AMEF register", [
                'register_id' => $registerId,
                'error' => $e->getMessage()
            ]);
            throw new \RuntimeException("Failed to retrieve objects from database: " . $e->getMessage());
        }
    }



    /**
     * Add property definitions to XML
     */
    public function addPropertyDefinitionsToXml(\SimpleXMLElement $xml, array $propertyDefinitions): void
    {
        $this->addObjectsToXml($xml, $propertyDefinitions, 'Property Definitions', 'folder-property-definitions', 'other', 'propertyDefinition');
    }

    /**
     * Complete export process: get all objects from database and render XML in one go
     * 
     * OPTIMIZED VERSION: This method processes 8000+ objects efficiently by:
     * 1. Single database query to get all objects
     * 2. Single pass through objects using section property directly
     * 3. Direct XML generation without intermediate arrays
     * 4. No JSON serialization overhead
     * 
     * @param \OCA\OpenRegister\Service\ObjectService $objectService OpenRegister ObjectService
     * @param int $registerId AMEF register ID
     * @param array $schemaIdMap Mapping of schema IDs to schema types (unused, kept for compatibility)
     * @param string|null $organization Organization filter (optional)
     * @return string Generated XML
     */
    public function exportArchiMateXml(
        \OCA\OpenRegister\Service\ObjectService $objectService, 
        int $registerId, 
        array $schemaIdMap, 
        ?string $organization = null
    ): string {

        $startTime = microtime(true);
        $this->logger->info('Starting OPTIMIZED ArchiMate XML export process (using section property)', [
            'register_id' => $registerId,
            'organization_filter' => $organization
        ]);

        // Step 1: Get all objects from database in one efficient query
        $objects = $this->getObjectsFromDatabase($objectService, $registerId);
        $dbTime = microtime(true) - $startTime;
        
        // Step 2: Process and generate XML in single optimized pass (no schema mapping needed)
        $xml = $this->generateXmlDirectly($objects, []);
        
        // Step 3: Run Quality Assurance checks on generated XML
        $this->runQualityAssuranceChecks($xml, $objects);
        
        $totalTime = microtime(true) - $startTime;
        
        $this->logger->info('OPTIMIZED ArchiMate XML export completed', [
            'total_objects' => count($objects),
            'xml_length' => strlen($xml),
            'db_time_seconds' => round($dbTime, 3),
            'total_time_seconds' => round($totalTime, 3),
            'objects_per_second' => round(count($objects) / $totalTime, 0)
        ]);

        return $xml;
    }

    /**
     * Generate XML directly from objects using section-based organization
     * 
     * ULTRA-OPTIMIZED VERSION: 
     * - Single pass to organize objects by section
     * - Direct XML generation per section
     * - No unnecessary loops or checks
     * 
     * @param array $objects Raw objects from database
     * @param array $schemaIdMap Schema ID to type mapping (unused)
     * @return string Generated XML
     */
    private function generateXmlDirectly(array $objects, array $schemaIdMap): string
    {
        $this->logger->info('Starting section-based XML generation from objects', [
            'object_count' => count($objects)
        ]);

        // Create base XML structure with model metadata
        $modelMetadata = $this->extractModelMetadata($objects);
        $propertyDefinitionMap = $modelMetadata['propertyDefinitionMap'] ?? [];
        $xml = $this->createCleanArchiMateXml($modelMetadata);
        
        // Add model name and properties if available
        if (!empty($modelMetadata)) {
            $this->addModelMetadataToXml($xml, $modelMetadata);
        }
        
        // Step 1: Organize objects by section in single pass
        $objectsBySection = [];
        $unmatchedCount = 0;
        
        foreach ($objects as $object) {
            // Serialize object if needed
            if (is_object($object) && method_exists($object, 'jsonSerialize')) {
                $object = $object->jsonSerialize();
            }

            $sectionName = $object['section'] ?? null;
            
            if ($sectionName) {
                if (!isset($objectsBySection[$sectionName])) {
                    $objectsBySection[$sectionName] = [];
                }
                $objectsBySection[$sectionName][] = $object;
            } else {
                $unmatchedCount++;
            }
        }
        
        // Step 2: Generate XML sections directly
        $validSections = ['elements', 'relationships', 'views', 'organizations', 'property_definitions'];
        $sectionCounts = [];
        
        // Map singular section names to plural for XML generation
        $sectionMapping = [
            'element' => 'elements',
            'relationship' => 'relationships',
            'view' => 'views',
            'organization' => 'organizations',
            'property_definition' => 'property_definitions'
        ];
        
        foreach ($validSections as $sectionName) {
            // Check both singular and plural section names
            $sectionObjects = [];
            foreach ($objectsBySection as $dbSection => $objects) {
                if (isset($sectionMapping[$dbSection]) && $sectionMapping[$dbSection] === $sectionName) {
                    $sectionObjects = array_merge($sectionObjects, $objects);
                }
            }
            
            if (!empty($sectionObjects)) {
                $sectionCounts[$sectionName] = count($sectionObjects);
                
                // Create section folder
                $sectionFolder = $this->createSectionFolder($xml, $sectionName);
                
                // Add all objects in this section
                foreach ($sectionObjects as $object) {
                    $this->addObjectDirectlyToXmlWithProperties($sectionFolder, $object, $sectionName, $propertyDefinitionMap);
                }
                
                $this->logger->debug("Generated XML section: {$sectionName}", [
                    'object_count' => count($sectionObjects)
                ]);
            }
        }
        
        // Debug logging
        $this->logger->info('Section-based XML generation completed', [
            'sections_found' => array_keys($objectsBySection),
            'section_counts' => $sectionCounts,
            'unmatched_objects' => $unmatchedCount,
            'total_objects_processed' => count($objects),
            'sections_with_data' => array_keys($sectionCounts)
        ]);

        return $this->formatXmlOutput($xml->asXML());
    }

    /**
     * Create section element in XML (matching original ArchiMate structure)
     */
    private function createSectionFolder(\SimpleXMLElement $xml, string $sectionName): \SimpleXMLElement
    {
        // Map our section names to proper ArchiMate XML elements
        $sectionMapping = [
            'elements' => 'elements',
            'relationships' => 'relationships', 
            'views' => 'views',
            'organizations' => 'organizations',
            'property_definitions' => 'propertyDefinitions'
        ];

        $xmlElementName = $sectionMapping[$sectionName] ?? $sectionName;
        $sectionElement = $xml->addChild($xmlElementName);
        
        // Views need a <diagrams> wrapper element according to ArchiMate standard
        if ($sectionName === 'views') {
            return $sectionElement->addChild('diagrams');
        }
        
        return $sectionElement;
    }

    /**
     * Add object directly to XML with properties from root fields
     */
    private function addObjectDirectlyToXmlWithProperties(\SimpleXMLElement $folder, array $object, string $sectionName, array $propertyDefinitionMap): void
    {
        $tagName = match($sectionName) {
            'organizations' => 'item',
            'property_definitions' => 'propertyDefinition',
            'views' => 'view',
            'relationships' => 'relationship',
            'elements' => 'element',
            default => 'element'
        };
        $objectNode = $folder->addChild($tagName);
        $xmlData = $this->cleanObjectDataForXml($object, $propertyDefinitionMap);
        if (is_array($xmlData) && !empty($xmlData)) {
            if ($sectionName === 'views') {
                $this->addViewDataToXmlNode($objectNode, $xmlData);
            } else {
                $this->addCleanDataToXmlNode($objectNode, $xmlData, $sectionName, $propertyDefinitionMap);
            }
        }
    }

    /**
     * Add view data to XML node with specialized handling for nodes and connections
     */
    private function addViewDataToXmlNode(\SimpleXMLElement $viewNode, array $viewData): void
    {
        // Add attributes first
        if (isset($viewData['_attributes'])) {
            foreach ($viewData['_attributes'] as $attrKey => $attrValue) {
                if (str_starts_with($attrKey, ':')) {
                    continue; // Skip duplicate attributes with colon prefix
                }
                $viewNode->addAttribute($attrKey, (string)$attrValue);
            }
        }

        // Add identifier directly if present
        if (isset($viewData['_identifier']) || isset($viewData['identifier'])) {
            $identifier = $viewData['_identifier'] ?? $viewData['identifier'];
            $viewNode->addAttribute('identifier', (string)$identifier);
        }

        // Add xsi:type if present
        foreach (['_xsi__type', 'xsi:type', '_xsi:type'] as $typeKey) {
            if (isset($viewData[$typeKey])) {
                $viewNode->addAttribute('xsi:type', (string)$viewData[$typeKey], 'http://www.w3.org/2001/XMLSchema-instance');
                break;
            }
        }

        // Process all other elements, handling nodes and connections specially
        foreach ($viewData as $key => $value) {
            // Skip already processed attributes and metadata, including duplicate id element
            if (in_array($key, ['_attributes', '_identifier', 'identifier', '_xsi__type', 'xsi:type', '_xsi:type', 'id'])) {
                continue;
            }
            
            if ($key === 'node' && is_array($value)) {
                // Handle view nodes with specialized processing
                foreach ($value as $nodeData) {
                    if (is_array($nodeData)) {
                        $nodeElement = $viewNode->addChild('node');
                        $this->addNodeDataToXmlElement($nodeElement, $nodeData);
                    } else {
                        // If it's not an array, treat it as a simple text value
                        $nodeElement = $viewNode->addChild('node');
                        $nodeElement[0] = (string)$nodeData;
                    }
                }
            } elseif ($key === 'connection' && is_array($value)) {
                // Handle view connections with special processing
                foreach ($value as $connectionData) {
                    if (is_array($connectionData)) {
                        $connectionElement = $viewNode->addChild('connection');
                        $this->arrayToXml($connectionData, $connectionElement);
                    } else {
                        // If it's not an array, treat it as a simple text value
                        $connectionElement = $viewNode->addChild('connection');
                        $connectionElement[0] = (string)$connectionData;
                    }
                }
            } else {
                // Handle all other elements normally (name, documentation, properties, etc.)
                if ($key === 'properties' && is_array($value)) {
                    // Use specialized property handling to avoid duplicate attributes
                    $this->addPropertiesToXml($viewNode, $value);
                } elseif (is_array($value)) {
                    $childElement = $viewNode->addChild($key);
                    $this->arrayToXml($value, $childElement);
                } else {
                    $childElement = $viewNode->addChild($key);
                    $childElement[0] = (string)$value;
                }
            }
        }
    }

    /**
     * Add node data to XML element with specialized handling for node attributes and nested elements
     */
    private function addNodeDataToXmlElement(\SimpleXMLElement $nodeElement, array $nodeData): void
    {
        // Add node attributes first - these are the positioning and identification attributes
        $nodeAttributes = [
            '_identifier' => 'identifier',
            '_x' => 'x', 
            '_y' => 'y',
            '_w' => 'w',
            '_h' => 'h',
            '_elementRef' => 'elementRef',
            '_xsi__type' => 'xsi:type'
        ];
        
        foreach ($nodeAttributes as $dataKey => $xmlAttr) {
            if (isset($nodeData[$dataKey])) {
                $nodeElement->addAttribute($xmlAttr, (string)$nodeData[$dataKey]);
            }
        }
        
        // Also check regular attributes array
        if (isset($nodeData['_attributes'])) {
            foreach ($nodeData['_attributes'] as $attrKey => $attrValue) {
                if (str_starts_with($attrKey, ':')) {
                    continue; // Skip duplicate attributes with colon prefix
                }
                // Skip if we already added this attribute from the direct keys
                if (in_array($attrKey, ['identifier', 'x', 'y', 'w', 'h', 'elementRef', 'xsi:type'])) {
                    continue;
                }
                $nodeElement->addAttribute($attrKey, (string)$attrValue);
            }
        }
        
        // Process nested elements (style, label, properties, etc.) but skip the attribute keys and duplicate keys
        $skipKeys = array_keys($nodeAttributes);
        $skipKeys[] = '_attributes';
        // Also skip the triple underscore duplicates
        $skipKeys = array_merge($skipKeys, ['___identifier', '___x', '___y', '___w', '___h', '___elementRef']);
        
        foreach ($nodeData as $key => $value) {
            if (in_array($key, $skipKeys)) {
                continue; // Skip already processed attributes
            }
            
            // Skip numeric keys as they can't be valid XML element names
            if (is_numeric($key)) {
                continue;
            }
            
            if ($key === 'node' && is_array($value)) {
                // Handle nested nodes recursively
                foreach ($value as $nestedNodeData) {
                    if (is_array($nestedNodeData)) {
                        $nestedNodeElement = $nodeElement->addChild('node');
                        $this->addNodeDataToXmlElement($nestedNodeElement, $nestedNodeData);
                    } else {
                        // If it's not an array, treat it as a simple text value
                        $nestedNodeElement = $nodeElement->addChild('node');
                        $nestedNodeElement[0] = (string)$nestedNodeData;
                    }
                }
            } elseif (is_array($value)) {
                $childElement = $nodeElement->addChild($key);
                $this->arrayToXml($value, $childElement);
            } else {
                $childElement = $nodeElement->addChild($key);
                $childElement[0] = (string)$value;
            }
        }
    }

    /**
     * Format XML output with proper indentation and line breaks for readability
     */
    private function formatXmlOutput(string $xmlString): string
    {
        // Use DOMDocument to format the XML with proper indentation
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;
        
        // Load the XML string
        if ($dom->loadXML($xmlString)) {
            return $dom->saveXML();
        }
        
        // If formatting fails, return original string
        return $xmlString;
    }

    /**
     * Clean object data for XML export - remove metadata and duplicate attributes
     */
    private function cleanObjectDataForXml(array $object, array $propertyDefinitionMap = []): array
    {
        // Remove our metadata fields
        $cleanData = $object;
        $metadataFields = ['section', 'identifier', 'model_identifier', '@self', 'extracted_at'];
        foreach ($metadataFields as $field) {
            unset($cleanData[$field]);
        }
        
        // Remove duplicate underscore fields that were created during parsing
        // Keep only the clean attribute names
        $fieldsToRemove = [];
        foreach ($cleanData as $key => $value) {
            // Remove fields that start with multiple underscores (___identifier, etc)
            if (is_string($key) && preg_match('/^_{2,}/', $key)) {
                $fieldsToRemove[] = $key;
            }
            // Remove single underscore fields that have clean equivalents
            elseif (is_string($key) && str_starts_with($key, '_') && $key !== '_attributes' && $key !== '_value' && $key !== '_text' && $key !== '_xsi__type') {
                $cleanKey = substr($key, 1);
                if (isset($cleanData[$cleanKey])) {
                    $fieldsToRemove[] = $key;
                }
            }
        }
        
        foreach ($fieldsToRemove as $field) {
            unset($cleanData[$field]);
        }
        
        // Remove flattened properties that will be reconstructed separately
        if (!empty($propertyDefinitionMap)) {
            foreach ($propertyDefinitionMap as $propRef => $propName) {
                unset($cleanData[$propName]);
            }
        }
        
        return $cleanData;
    }

    /**
     * Add clean data to XML node with proper ArchiMate structure
     */
    private function addCleanDataToXmlNode(\SimpleXMLElement $node, array $data, ?string $sectionName = null, array $propertyDefinitionMap = []): void
    {
        // Extract attributes from various possible locations
        $attributes = [];
        if (isset($data['identifier'])) {
            $attributes['identifier'] = (string)$data['identifier'];
        }
        if (isset($data['_attributes']) && is_array($data['_attributes'])) {
            foreach ($data['_attributes'] as $attrKey => $attrValue) {
                $cleanKey = str_replace(':', '', $attrKey);
                $isPropertyDefinition = ($sectionName === 'property_definitions');
                if ($cleanKey === 'type') {
                    if ($isPropertyDefinition) {
                        $attributes['type'] = (string)$attrValue;
                    } else {
                        $attributes['xsi:type'] = (string)$attrValue;
                    }
                } elseif (in_array($cleanKey, ['identifier', 'source', 'target', 'accessType'])) {
                    $attributes[$cleanKey] = (string)$attrValue;
                }
            }
        }
        foreach (['xsi:type', 'xsi_type', '_xsi:type', '_xsi__type', '_type'] as $typeKey) {
            if (isset($data[$typeKey])) {
                $isPropertyDefinition = ($sectionName === 'property_definitions');
                if ($typeKey === '_type' && $isPropertyDefinition && !isset($attributes['type'])) {
                    $attributes['type'] = (string)$data[$typeKey];
                    break;
                } elseif (in_array($typeKey, ['xsi:type', 'xsi_type', '_xsi:type', '_xsi__type']) && !isset($attributes['xsi:type'])) {
                    $attributes['xsi:type'] = (string)$data[$typeKey];
                    break;
                }
            }
        }
        foreach (['source', 'target', 'accessType', 'type'] as $attrName) {
            if (isset($data[$attrName]) && !isset($attributes[$attrName])) {
                $isPropertyDefinition = ($sectionName === 'property_definitions');
                if ($attrName === 'type') {
                    if ($isPropertyDefinition) {
                        $attributes['type'] = (string)$data[$attrName];
                    } elseif (!isset($attributes['xsi:type'])) {
                        $attributes['xsi:type'] = (string)$data[$attrName];
                    }
                } else {
                    $attributes[$attrName] = (string)$data[$attrName];
                }
            }
        }
        foreach ($attributes as $attrName => $attrValue) {
            if ($attrName === 'xsi:type') {
                $node->addAttribute('xsi:type', $attrValue, 'http://www.w3.org/2001/XMLSchema-instance');
            } else {
                $node->addAttribute($attrName, $attrValue);
            }
        }
        // Handle child elements
        foreach ($data as $key => $value) {
            if (in_array($key, ['identifier', 'xsi:type', 'xsi_type', '_xsi:type', '_type', 'source', 'target', 'accessType', 'type', '_attributes'])) {
                continue;
            }
            if ($key === 'name' && is_array($value)) {
                $nameNode = $node->addChild('name');
                if (isset($value['_value'])) {
                    $nameNode[0] = (string)$value['_value'];
                }
                foreach (['xml:lang', '_xml:lang', '_xml__lang', 'xml_lang'] as $langKey) {
                    if (isset($value[$langKey])) {
                        $nameNode->addAttribute('xml:lang', $value[$langKey], 'http://www.w3.org/XML/1998/namespace');
                        break;
                    }
                }
            } elseif ($key === 'documentation' && is_array($value)) {
                $docNode = $node->addChild('documentation');
                if (isset($value['_value'])) {
                    $docNode[0] = (string)$value['_value'];
                }
                foreach (['xml:lang', '_xml:lang', '_xml__lang', 'xml_lang'] as $langKey) {
                    if (isset($value[$langKey])) {
                        $docNode->addAttribute('xml:lang', $value[$langKey], 'http://www.w3.org/XML/1998/namespace');
                        break;
                    }
                }
            } elseif ($key === 'properties' && is_array($value)) {
                $this->addPropertiesToXml($node, $value);
            } elseif ($key === 'value' && is_array($value)) {
                $valueNode = $node->addChild('value');
                if (isset($value['_value'])) {
                    $valueNode[0] = (string)$value['_value'];
                }
                foreach (['xml:lang', '_xml:lang', '_xml__lang', 'xml_lang'] as $langKey) {
                    if (isset($value[$langKey])) {
                        $valueNode->addAttribute('xml:lang', $value[$langKey], 'http://www.w3.org/XML/1998/namespace');
                        break;
                    }
                }
            }
        }
        // Add properties from root fields using propertyDefinitionMap
        if (!empty($propertyDefinitionMap)) {
            $this->addPropertiesFromRootFields($node, $data, $propertyDefinitionMap);
        }
    }

    /**
     * Add properties to XML node using propertyDefinitionMap from model
     *
     * @param \SimpleXMLElement $node XML node to add properties to
     * @param array $object The object with root-level properties
     * @param array $propertyDefinitionMap Map of property name => propertyDefinitionRef
     */
    private function addPropertiesFromRootFields(
        \SimpleXMLElement $node,
        array $object,
        array $propertyDefinitionMap
    ): void {
        // Find all root-level fields that match a propertyDefinitionMap entry
        $properties = [];
        foreach ($propertyDefinitionMap as $propRef => $propName) {
            if (isset($object[$propName])) {
                $properties[] = [
                    'propertyDefinitionRef' => $propRef,
                    'value' => $object[$propName],
                ];
            }
        }
        if (!empty($properties)) {
            $propertiesNode = $node->addChild('properties');
            foreach ($properties as $property) {
                $propertyNode = $propertiesNode->addChild('property');
                $propertyNode->addAttribute('propertyDefinitionRef', $property['propertyDefinitionRef']);
                $valueNode = $propertyNode->addChild('value');
                $valueNode[0] = (string)$property['value'];
            }
        }
    }

    /**
     * Add properties section to XML
     */
    private function addPropertiesToXml(\SimpleXMLElement $node, array $properties): void
    {
        if (empty($properties)) {
            return;
        }

        $propertiesNode = $node->addChild('properties');
        
        // Handle the nested structure from import service: properties.property[]
        $propList = [];
        if (isset($properties['property'])) {
            // Structure from import: properties.property (single object or array)
            if ($this->isList($properties['property'])) {
                // Multiple properties as array
                $propList = $properties['property'];
            } else {
                // Single property as object - wrap in array
                $propList = [$properties['property']];
            }
        } elseif ($this->isList($properties)) {
            // Direct array of properties
            $propList = $properties;
        } else {
            // Single property object
            $propList = [$properties];
        }
        
        foreach ($propList as $property) {
            if (!is_array($property)) {
                continue;
            }
            
            $propertyNode = $propertiesNode->addChild('property');
            
            // Look for propertyDefinitionRef in various forms (including double underscore from import service)
            $propDefRef = null;
            foreach (['propertyDefinitionRef', '_propertyDefinitionRef', '___propertyDefinitionRef'] as $refKey) {
                if (isset($property[$refKey])) {
                    $propDefRef = (string)$property[$refKey];
                    break;
                }
            }
            
            // Also check in _attributes
            if (!$propDefRef && isset($property['_attributes']['propertyDefinitionRef'])) {
                $propDefRef = (string)$property['_attributes']['propertyDefinitionRef'];
            }
            
            if ($propDefRef) {
                $propertyNode->addAttribute('propertyDefinitionRef', $propDefRef);
            }
            
            // Handle value in various forms
            if (isset($property['value'])) {
                if (is_array($property['value'])) {
                    $valueNode = $propertyNode->addChild('value');
                    if (isset($property['value']['_value'])) {
                        $valueNode[0] = (string)$property['value']['_value'];
                    } elseif (isset($property['value']['value'])) {
                        $valueNode[0] = (string)$property['value']['value'];
                    }
                    
                    // Add xml:lang if present in various forms (including double underscore from import service)
                    foreach (['xml:lang', '_xml:lang', '_xml__lang', 'xml_lang'] as $langKey) {
                        if (isset($property['value'][$langKey])) {
                            $valueNode->addAttribute('xml:lang', $property['value'][$langKey], 'http://www.w3.org/XML/1998/namespace');
                            break;
                        }
                    }
                } else {
                    // Simple string value
                    $valueNode = $propertyNode->addChild('value');
                    $valueNode[0] = (string)$property['value'];
                }
            }
        }
    }

    /**
     * Extract model metadata from objects
     */
    private function extractModelMetadata(array $objects): array
    {
        foreach ($objects as $object) {
            if (is_object($object) && method_exists($object, 'jsonSerialize')) {
                $object = $object->jsonSerialize();
            }
            
            if (isset($object['section']) && $object['section'] === 'model') {
                return $object;
            }
        }
        
        return [];
    }

    /**
     * Add model metadata (name, documentation, properties) to XML root
     */
    private function addModelMetadataToXml(\SimpleXMLElement $xml, array $modelMetadata): void
    {
        // Add name if present
        if (isset($modelMetadata['name'])) {
            $nameNode = $xml->addChild('name');
            if (is_array($modelMetadata['name']) && isset($modelMetadata['name']['_value'])) {
                $nameNode[0] = (string)$modelMetadata['name']['_value'];
                if (isset($modelMetadata['name']['xml:lang'])) {
                    $nameNode->addAttribute('xml:lang', $modelMetadata['name']['xml:lang'], 'http://www.w3.org/XML/1998/namespace');
                }
            } elseif (is_string($modelMetadata['name'])) {
                $nameNode[0] = $modelMetadata['name'];
            }
        }

        // Add documentation if present
        if (isset($modelMetadata['documentation'])) {
            $docNode = $xml->addChild('documentation');
            if (is_array($modelMetadata['documentation']) && isset($modelMetadata['documentation']['_value'])) {
                $docNode[0] = (string)$modelMetadata['documentation']['_value'];
                if (isset($modelMetadata['documentation']['xml:lang'])) {
                    $docNode->addAttribute('xml:lang', $modelMetadata['documentation']['xml:lang'], 'http://www.w3.org/XML/1998/namespace');
                }
            } elseif (is_string($modelMetadata['documentation'])) {
                $docNode[0] = $modelMetadata['documentation'];
            }
        }

        // Add properties if present
        if (isset($modelMetadata['properties']) && is_array($modelMetadata['properties'])) {
            $this->addPropertiesToXml($xml, $modelMetadata['properties']);
        }
    }

    /**
     * Optimized method to add data to XML node
     */
    private function addDataToXmlNode(\SimpleXMLElement $node, array $data): void
    {
        // Add attributes first
        if (isset($data['_attributes']) && is_array($data['_attributes'])) {
            foreach ($data['_attributes'] as $attrName => $attrValue) {
                $node->addAttribute($attrName, (string)$attrValue);
            }
        }

        // Add text content
        if (isset($data['_value'])) {
            $node[0] = (string)$data['_value'];
        }

        // Add child elements
        foreach ($data as $key => $value) {
            if ($key === '_attributes' || $key === '_value' || is_int($key)) {
                continue;
            }

            if (is_array($value)) {
                if (isset($value[0])) {
                    // Array of items
                    foreach ($value as $item) {
                        $child = $node->addChild($key);
                        if (is_array($item)) {
                            $this->addDataToXmlNode($child, $item);
                        } else {
                            $child[0] = (string)$item;
                        }
                    }
                } else {
                    // Single object
                    $child = $node->addChild($key);
                    $this->addDataToXmlNode($child, $value);
                }
            } else {
                // Scalar value
                $child = $node->addChild($key);
                $child[0] = (string)$value;
            }
        }
    }

    /**
     * Convert OpenRegister objects back to ArchiMate format
     * 
     * @param array $objects OpenRegister objects from all schemas
     * @param array $schemaIdMap Mapping of schema IDs to schema types
     * @return array ArchiMate data structure
     */
    public function convertFromOpenRegisterObjects(array $objects, array $schemaIdMap): array
    {
        $this->logger->info('Converting from OpenRegister objects back to ArchiMate format', [
            'total_objects' => count($objects)
        ]);

        // First, organize objects by schema type based on their schema ID
        $organizedObjects = $this->organizeObjectsBySchemaType($objects, $schemaIdMap);
        
        $archiMateData = [
            'model_metadata' => [],
            'elements' => [],
            'relationships' => [],
            'organizations' => [],
            'views' => [],
            'property_definitions' => []
        ];

        // Process organized objects by schema type
        foreach ($organizedObjects as $schemaType => $schemaObjects) {
            $this->logger->debug("Processing objects for schema type", [
                'schema_type' => $schemaType,
                'object_count' => count($schemaObjects)
            ]);

            foreach ($schemaObjects as $object) {
                $section = $this->mapSchemaTypeToSection($schemaType);
                $identifier = $object['identifier'] ?? '';
                $xmlData = json_decode($object['xml_data'] ?? '{}', true);

                if ($section === 'model_metadata') {
                    $archiMateData['model_metadata'] = $xmlData;
                    $this->logger->debug('Added model metadata', [
                        'identifier' => $identifier
                    ]);
                } else {
                    $archiMateData[$section][$identifier] = $xmlData;
                    $this->logger->debug('Added section object', [
                        'section' => $section,
                        'identifier' => $identifier,
                        'schema_type' => $schemaType
                    ]);
                }
            }
        }

        // Reconstruct the proper nested XML structure for export
        $archiMateData = $this->reconstructNestedXmlStructure($archiMateData);

        $this->logger->info('Conversion completed', [
            'sections' => array_keys($archiMateData)
        ]);

        return $archiMateData;
    }

    /**
     * Organize objects by schema type based on their schema ID
     * 
     * @param array $objects Raw objects from database
     * @param array $schemaIdMap Mapping of schema IDs to schema types
     * @return array Objects organized by schema type
     */
    private function organizeObjectsBySchemaType(array $objects, array $schemaIdMap): array
    {
        $organizedObjects = [];

        // Organize objects by their schema
        foreach ($objects as $object) {
            // Serialize the object if it's not already an array
            if (is_object($object) && method_exists($object, 'jsonSerialize')) {
                $object = $object->jsonSerialize();
            }

            $schemaId = $object['@self']['schema'] ?? null;
            
            if ($schemaId && isset($schemaIdMap[$schemaId])) {
                $schemaType = $schemaIdMap[$schemaId];
                
                if (!isset($organizedObjects[$schemaType])) {
                    $organizedObjects[$schemaType] = [];
                }
                
                $organizedObjects[$schemaType][] = $object;
            }
        }

        return $organizedObjects;
    }

    /**
     * Map schema type to ArchiMate section name
     * 
     * @param string $schemaType Schema type from AMEF config
     * @return string Section name for ArchiMate data structure
     */
    private function mapSchemaTypeToSection(string $schemaType): string
    {
        $mapping = [
            'model' => 'model_metadata',
            'element' => 'elements',
            'relationship' => 'relationships',
            'view' => 'views',
            'organization' => 'organizations',
            'property_definition' => 'property_definitions'
        ];

        return $mapping[$schemaType] ?? $schemaType;
    }

    /**
     * Reconstruct the proper nested XML structure for export
     * 
     * @param array $archiMateData Flattened ArchiMate data
     * @return array Properly nested XML structure
     */
    private function reconstructNestedXmlStructure(array $archiMateData): array
    {
        // Reconstruct views with diagrams wrapper
        if (!empty($archiMateData['views']) && is_array($archiMateData['views'])) {
            $archiMateData['views'] = [
                'diagrams' => $archiMateData['views']
            ];
        }

        // Reconstruct organizations with items wrapper
        if (!empty($archiMateData['organizations']) && is_array($archiMateData['organizations'])) {
            $items = [];
            foreach ($archiMateData['organizations'] as $org) {
                $items[] = $org;
            }
            $archiMateData['organizations'] = [
                'item' => $items
            ];
        }

        return $archiMateData;
    }

    /**
     * Run comprehensive Quality Assurance checks on exported XML
     * 
     * @param string $xmlString The generated XML string
     * @param array $sourceData The original source data for reference
     * @throws \InvalidArgumentException If any QA check fails
     */
    private function runQualityAssuranceChecks(string $xmlString, array $sourceData): void
    {
        $this->logger->info('Running Quality Assurance checks on exported XML');
        
        try {
            $xml = new \SimpleXMLElement($xmlString);
            
            // QA Check 1: Every <element> has xsi:type and unique identifier
            $this->validateElementsHaveTypeAndIdentifier($xml);
            
            // QA Check 2: Every <relationship> has xsi:type, source, target with valid references
            $this->validateRelationshipsHaveSourceTarget($xml);
            
            // QA Check 3: No empty <property/> tags; all have propertyDefinitionRef and <value>
            $this->validatePropertiesAreNotEmpty($xml);
            
            // QA Check 4: propid-2 exists for all elements and value == identifier
            $this->validateObjectIdProperty($xml);
            
            // QA Check 5: name/documentation are trimmed and whitespace normalized
            $this->validateTextContentNormalized($xml);
            
            $this->logger->info('All Quality Assurance checks passed successfully');
            
        } catch (\Exception $e) {
            $this->logger->error('Quality Assurance check failed: ' . $e->getMessage());
            throw new \InvalidArgumentException('Export QA validation failed: ' . $e->getMessage());
        }
    }

    /**
     * Validate that every element has xsi:type and unique identifier
     */
    private function validateElementsHaveTypeAndIdentifier(\SimpleXMLElement $xml): void
    {
        $elements = $xml->xpath('//element');
        $identifiers = [];
        
        foreach ($elements as $element) {
            $attributes = $element->attributes();
            
            // Check xsi:type exists
            $xsiType = $element->attributes('http://www.w3.org/2001/XMLSchema-instance');
            if (!isset($xsiType['type'])) {
                throw new \InvalidArgumentException("Element missing xsi:type: " . (string)$attributes['identifier']);
            }
            
            // Check identifier exists and is unique
            if (!isset($attributes['identifier'])) {
                throw new \InvalidArgumentException("Element missing identifier");
            }
            
            $identifier = (string)$attributes['identifier'];
            if (in_array($identifier, $identifiers)) {
                throw new \InvalidArgumentException("Duplicate identifier found: " . $identifier);
            }
            $identifiers[] = $identifier;
        }
        
        $this->logger->debug("Validated " . count($elements) . " elements with unique identifiers and xsi:type");
    }

    /**
     * Validate that every relationship has xsi:type, source, target with valid references
     */
    private function validateRelationshipsHaveSourceTarget(\SimpleXMLElement $xml): void
    {
        $relationships = $xml->xpath('//relationship');
        $allIdentifiers = [];
        
        // Collect all valid identifiers from elements and relationships
        foreach ($xml->xpath('//*[@identifier]') as $node) {
            $allIdentifiers[] = (string)$node->attributes()['identifier'];
        }
        
        foreach ($relationships as $relationship) {
            $attributes = $relationship->attributes();
            
            // Check xsi:type exists
            $xsiType = $relationship->attributes('http://www.w3.org/2001/XMLSchema-instance');
            if (!isset($xsiType['type'])) {
                throw new \InvalidArgumentException("Relationship missing xsi:type: " . (string)$attributes['identifier']);
            }
            
            // Check source exists and references valid identifier
            if (!isset($attributes['source'])) {
                throw new \InvalidArgumentException("Relationship missing source: " . (string)$attributes['identifier']);
            }
            
            $source = (string)$attributes['source'];
            if (!in_array($source, $allIdentifiers)) {
                throw new \InvalidArgumentException("Relationship source references non-existent identifier: " . $source);
            }
            
            // Check target exists and references valid identifier
            if (!isset($attributes['target'])) {
                throw new \InvalidArgumentException("Relationship missing target: " . (string)$attributes['identifier']);
            }
            
            $target = (string)$attributes['target'];
            if (!in_array($target, $allIdentifiers)) {
                throw new \InvalidArgumentException("Relationship target references non-existent identifier: " . $target);
            }
        }
        
        $this->logger->debug("Validated " . count($relationships) . " relationships with valid source/target references");
    }

    /**
     * Validate that no properties are empty; all have propertyDefinitionRef and value
     */
    private function validatePropertiesAreNotEmpty(\SimpleXMLElement $xml): void
    {
        $properties = $xml->xpath('//property');
        
        foreach ($properties as $property) {
            $attributes = $property->attributes();
            
            // Check propertyDefinitionRef exists
            if (!isset($attributes['propertyDefinitionRef'])) {
                throw new \InvalidArgumentException("Property missing propertyDefinitionRef");
            }
            
            // Check value element exists and has content
            $valueElements = $property->xpath('value');
            if (empty($valueElements)) {
                throw new \InvalidArgumentException("Property missing value element: " . (string)$attributes['propertyDefinitionRef']);
            }
            
            $value = trim((string)$valueElements[0]);
            if (empty($value)) {
                throw new \InvalidArgumentException("Property has empty value: " . (string)$attributes['propertyDefinitionRef']);
            }
        }
        
        $this->logger->debug("Validated " . count($properties) . " properties have propertyDefinitionRef and non-empty values");
    }

    /**
     * Validate that propid-2 exists for all elements and value equals identifier
     */
    private function validateObjectIdProperty(\SimpleXMLElement $xml): void
    {
        $elements = $xml->xpath('//element');
        
        foreach ($elements as $element) {
            $identifier = (string)$element->attributes()['identifier'];
            
            // Find propid-2 property
            $objectIdProps = $element->xpath('properties/property[@propertyDefinitionRef="propid-2"]');
            if (empty($objectIdProps)) {
                throw new \InvalidArgumentException("Element missing propid-2 property: " . $identifier);
            }
            
            $valueElements = $objectIdProps[0]->xpath('value');
            if (empty($valueElements)) {
                throw new \InvalidArgumentException("Element propid-2 missing value: " . $identifier);
            }
            
            $objectIdValue = trim((string)$valueElements[0]);
            $expectedValue = str_replace('id-', '', $identifier); // Remove 'id-' prefix for comparison
            
            if ($objectIdValue !== $expectedValue) {
                throw new \InvalidArgumentException("Element propid-2 value mismatch. Expected: " . $expectedValue . ", Got: " . $objectIdValue . " (Element: " . $identifier . ")");
            }
        }
        
        $this->logger->debug("Validated propid-2 property for " . count($elements) . " elements");
    }

    /**
     * Validate that name/documentation text content is trimmed and whitespace normalized
     */
    private function validateTextContentNormalized(\SimpleXMLElement $xml): void
    {
        $textElements = $xml->xpath('//name | //documentation | //value');
        
        foreach ($textElements as $element) {
            $content = (string)$element;
            $trimmed = trim($content);
            $normalized = preg_replace('/\s+/', ' ', $trimmed); // Normalize multiple whitespace to single space
            
            if ($content !== $normalized) {
                $tagName = $element->getName();
                $parentId = '';
                if ($element->xpath('../@identifier')) {
                    $parentId = ' (Parent: ' . (string)$element->xpath('../@identifier')[0] . ')';
                }
                throw new \InvalidArgumentException("Text content not normalized in <" . $tagName . ">" . $parentId . ". Expected: '" . $normalized . "', Got: '" . $content . "'");
            }
        }
        
        $this->logger->debug("Validated " . count($textElements) . " text elements are properly trimmed and normalized");
    }
}


