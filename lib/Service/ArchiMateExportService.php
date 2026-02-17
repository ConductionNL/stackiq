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
                
                // Skip malformed attribute keys that would create invalid XML (e.g., __propertyDefinitionRef -> :propertyDefinitionRef)
                if (str_starts_with($attrKey, '__') || $attrKey === '') {
                    continue;
                }
                
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

                // Handle namespaced attributes (e.g., xml:lang, xsi:type)
                [$nsPrefix, $local] = $this->splitNamespacedKey($cleanAttrKey);
                if ($nsPrefix !== null) {
                    $nsUri = $this->getNamespaceUri($xml, $nsPrefix);
                    if ($nsUri) {
                        $xml->addAttribute($nsPrefix . ':' . $local, (string) $attrValue, $nsUri);
                        $addedAttributes[] = $nsPrefix . ':' . $local;
                        continue;
                    }
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

            // Skip colon-prefixed duplicate keys (artifacts from XML-to-JSON parsing)
            if (is_string($key) && str_starts_with($key, ':')) {
                continue;
            }

            // Skip numeric keys - they indicate array items that should be handled differently
            if (is_int($key)) {
                continue;
            }

            // Skip property-like fields that should be handled by specialized property methods
            // These fields often appear as direct data but should only be in <properties> structure
            $propertyLikeFields = [
                'beschikbaarheid', 'integriteit', 'vertrouwelijkheid', 'gemmaType',
                'objectId', 'bivScoreBbn', 'belangrijksteReden'
            ];
            if (in_array($key, $propertyLikeFields, true)) {
                continue; // Skip these - they should only appear in proper <properties><property> structure
            }
            
            // Special handling for elementProperties and other nested structures - filter out problematic fields
            if (($key === 'elementProperties' || $key === 'properties' || $key === 'viewNodes') && is_array($value)) {
                $value = $this->filterProblematicFields($value, $propertyLikeFields);
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
        // Also handle already-converted colon notation (e.g., 'xml:lang')
        if (str_contains($key, ':')) {
            $parts = explode(':', $key, 2);
            if (count($parts) === 2 && $parts[0] !== '' && $parts[1] !== '') {
                return [$parts[0], $parts[1]];
            }
        }
        return [null, $key];
    }

    /**
     * Recursively filter out problematic fields from nested data structures
     * 
     * @param array $data The data structure to filter
     * @param array $fieldsToRemove List of field names to remove
     * @return array Filtered data structure
     */
    private function filterProblematicFields(array $data, array $fieldsToRemove): array
    {
        $filtered = [];
        
        foreach ($data as $key => $value) {
            if (is_string($key) === false) {
                continue;
            }

            $shouldSkip = false;
            
            // Skip exact matches
            if (in_array($key, $fieldsToRemove, true)) {
                $shouldSkip = true;
            }
            
            // Skip fields that start with problematic patterns (e.g., "beschikbaarheid(belangrijksteReden)")
            foreach ($fieldsToRemove as $fieldPattern) {
                if (str_starts_with($key, $fieldPattern)) {
                    $shouldSkip = true;
                    break;
                }
            }
            
            // Skip fields with invalid XML tag name characters (parentheses, etc.)
            if (preg_match('/[(),<>\/\\\]/', $key)) {
                $shouldSkip = true;
            }
            
            if ($shouldSkip) {
                continue;
            }
            
            // Recursively filter nested arrays
            if (is_array($value)) {
                $filtered[$key] = $this->filterProblematicFields($value, $fieldsToRemove);
            } else {
                $filtered[$key] = $value;
            }
        }
        
        return $filtered;
    }

    private function getNamespaceUri(\SimpleXMLElement $xml, string $prefix): string
    {
        // Well-known namespaces — check first to avoid expensive getDocNamespaces calls
        static $wellKnown = [
            'xml' => 'http://www.w3.org/XML/1998/namespace',
            'xsi' => 'http://www.w3.org/2001/XMLSchema-instance',
        ];

        if (isset($wellKnown[$prefix])) {
            return $wellKnown[$prefix];
        }

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
     * Queries each schema separately because OpenRegister's magic table routing
     * requires both register AND schema in the query. Without schema, the query
     * falls back to the generic objects table (which is empty for magic-table registers).
     *
     * @param \OCA\OpenRegister\Service\ObjectService $objectService OpenRegister ObjectService
     * @param int $registerId AMEF register ID
     * @param array $schemaIdMap Mapping of schema IDs to schema types
     * @return array Array of objects from all schemas in the register
     * @throws \RuntimeException If retrieval fails
     */
    public function getObjectsFromDatabase(\OCA\OpenRegister\Service\ObjectService $objectService, int $registerId, array $schemaIdMap = []): array
    {
        $this->logger->info('Retrieving all objects from AMEF register', [
            'register_id' => $registerId,
            'schema_count' => count($schemaIdMap)
        ]);

        $allObjects = [];

        // Map schema types (singular) to section names used in XML generation
        $sectionNameMap = [
            'element' => 'element',
            'relationship' => 'relationship',
            'view' => 'view',
            'organization' => 'organization',
            'property_definition' => 'property_definition',
            'model' => 'model'
        ];

        // Query each schema separately (required for magic table routing)
        foreach ($schemaIdMap as $schemaId => $schemaType) {
            $query = [
                '@self' => [
                    'register' => $registerId,
                    'schema' => (int) $schemaId
                ],
                '_limit' => 10000
            ];

            try {
                $schemaObjects = $objectService->searchObjects(query: $query, _rbac: false, _multitenancy: false);

                // Inject 'section' field if missing (magic table objects don't store it)
                $sectionName = $sectionNameMap[$schemaType] ?? $schemaType;
                foreach ($schemaObjects as &$obj) {
                    if (is_object($obj) && method_exists($obj, 'jsonSerialize')) {
                        $obj = $obj->jsonSerialize();
                    }
                    if (!isset($obj['section'])) {
                        $obj['section'] = $sectionName;
                    }
                }
                unset($obj);

                $this->logger->info("Objects retrieved for schema", [
                    'schema_id' => $schemaId,
                    'schema_type' => $schemaType,
                    'count' => count($schemaObjects)
                ]);

                $allObjects = array_merge($allObjects, $schemaObjects);
            } catch (\Exception $e) {
                $this->logger->warning("Failed to retrieve objects for schema", [
                    'schema_id' => $schemaId,
                    'schema_type' => $schemaType,
                    'error' => $e->getMessage()
                ]);
            }
        }

        // Fallback: if no schemaIdMap provided, try querying register directly
        if (empty($schemaIdMap)) {
            $query = [
                '@self' => ['register' => $registerId],
                '_limit' => 10000
            ];

            try {
                $allObjects = $objectService->searchObjects(query: $query, _rbac: false, _multitenancy: false);
            } catch (\Exception $e) {
                $this->logger->error("Failed to retrieve objects from AMEF register", [
                    'register_id' => $registerId,
                    'error' => $e->getMessage()
                ]);
                throw new \RuntimeException("Failed to retrieve objects from database: " . $e->getMessage());
            }
        }

        $this->logger->info('Objects retrieved successfully from AMEF register', [
            'total_retrieved_count' => count($allObjects),
            'register_id' => $registerId
        ]);

        return $allObjects;
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

        // Step 1: Get all objects from database (queries each schema separately for magic table support)
        $objects = $this->getObjectsFromDatabase($objectService, $registerId, $schemaIdMap);
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
        $validSections = ['elements', 'relationships', 'organizations', 'property_definitions', 'views'];
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

                // Organizations are stored as a single tree object with the full hierarchy
                // in the xml field. Write items directly as children of <organizations>.
                if ($sectionName === 'organizations') {
                    $orgFolder = $this->createSectionFolder($xml, $sectionName);
                    foreach ($sectionObjects as $object) {
                        if (is_object($object) && method_exists($object, 'jsonSerialize')) {
                            $object = $object->jsonSerialize();
                        }
                        $xmlField = $object['xml'] ?? [];
                        // The xml field contains the raw organizations data with 'item' array
                        if (isset($xmlField['item'])) {
                            $items = $xmlField['item'];
                            // Ensure items is a list (could be single assoc array for one top-level folder)
                            if (!isset($items[0])) {
                                $items = [$items];
                            }
                            foreach ($items as $itemData) {
                                if (is_array($itemData)) {
                                    $itemNode = $orgFolder->addChild('item');
                                    $this->addOrganizationItemToXml($itemNode, $itemData);
                                }
                            }
                        }
                    }
                    $this->logger->debug("Generated XML section: {$sectionName} (tree mode)", [
                        'object_count' => count($sectionObjects)
                    ]);
                    continue;
                }

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
        // Prefer the 'xml' field (original ArchiMate structure preserved during import)
        // over cleanObjectDataForXml which loses array structure for name/documentation
        if (isset($object['xml']) && is_array($object['xml']) && !empty($object['xml'])) {
            $xmlData = $object['xml'];
            unset($xmlData['_essential_data']);
        } else {
            $xmlData = $this->cleanObjectDataForXml($object, $propertyDefinitionMap);
        }
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
                // Handle namespaced attributes (e.g., xsi:type)
                [$nsPrefix, $local] = $this->splitNamespacedKey($attrKey);
                if ($nsPrefix !== null) {
                    $nsUri = $this->getNamespaceUri($viewNode, $nsPrefix);
                    if ($nsUri) {
                        $viewNode->addAttribute($nsPrefix . ':' . $local, (string)$attrValue, $nsUri);
                        continue;
                    }
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

        // XSD-required order for ViewType (Diagram): name → documentation → properties → node → connection
        $this->addLangTextChild($viewNode, 'name', $viewData['name'] ?? null);
        $this->addLangTextChild($viewNode, 'documentation', $viewData['documentation'] ?? null);
        if (isset($viewData['properties']) && is_array($viewData['properties'])) {
            $this->addPropertiesToXml($viewNode, $viewData['properties']);
        }

        // Nodes
        if (isset($viewData['node']) && is_array($viewData['node'])) {
            $nodes = $viewData['node'];
            if (!$this->isList($nodes)) {
                $nodes = [$nodes];
            }
            foreach ($nodes as $nodeData) {
                if (is_array($nodeData)) {
                    $nodeElement = $viewNode->addChild('node');
                    $this->addNodeDataToXmlElement($nodeElement, $nodeData);
                }
            }
        }

        // Connections
        if (isset($viewData['connection']) && is_array($viewData['connection'])) {
            $connections = $viewData['connection'];
            if (!$this->isList($connections)) {
                $connections = [$connections];
            }
            foreach ($connections as $connectionData) {
                if (is_array($connectionData)) {
                    $connectionElement = $viewNode->addChild('connection');
                    $this->arrayToXml($connectionData, $connectionElement);
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
        
        $addedNodeAttrs = [];
        foreach ($nodeAttributes as $dataKey => $xmlAttr) {
            if (isset($nodeData[$dataKey])) {
                // Handle namespaced attributes like xsi:type
                [$nsPrefix, $local] = $this->splitNamespacedKey($xmlAttr);
                if ($nsPrefix !== null) {
                    $nsUri = $this->getNamespaceUri($nodeElement, $nsPrefix);
                    if ($nsUri) {
                        $nodeElement->addAttribute($nsPrefix . ':' . $local, (string)$nodeData[$dataKey], $nsUri);
                        $addedNodeAttrs[] = $nsPrefix . ':' . $local;
                        continue;
                    }
                }
                $nodeElement->addAttribute($xmlAttr, (string)$nodeData[$dataKey]);
                $addedNodeAttrs[] = $xmlAttr;
            }
        }

        // Also check regular attributes array
        if (isset($nodeData['_attributes'])) {
            foreach ($nodeData['_attributes'] as $attrKey => $attrValue) {
                if (str_starts_with($attrKey, ':')) {
                    continue; // Skip duplicate attributes with colon prefix
                }
                // Skip if we already added this attribute from the direct keys
                if (in_array($attrKey, ['identifier', 'x', 'y', 'w', 'h', 'elementRef', 'xsi:type']) || in_array($attrKey, $addedNodeAttrs)) {
                    continue;
                }
                // Handle namespaced attributes
                [$nsPrefix, $local] = $this->splitNamespacedKey($attrKey);
                if ($nsPrefix !== null) {
                    $nsUri = $this->getNamespaceUri($nodeElement, $nsPrefix);
                    if ($nsUri) {
                        $nodeElement->addAttribute($nsPrefix . ':' . $local, (string)$attrValue, $nsUri);
                        continue;
                    }
                }
                $nodeElement->addAttribute($attrKey, (string)$attrValue);
            }
        }
        
        // XSD-required order for ViewNodeType: label → style → viewRef → node (nested)
        // Label (used in Label nodes)
        if (isset($nodeData['label'])) {
            $labelData = $nodeData['label'];
            if (is_array($labelData)) {
                $labelElement = $nodeElement->addChild('label');
                $this->arrayToXml($labelData, $labelElement);
            } else {
                $labelElement = $nodeElement->addChild('label');
                $labelElement[0] = (string)$labelData;
            }
        }

        // Style (lineColor → fillColor → font per XSD StyleType order)
        if (isset($nodeData['style']) && is_array($nodeData['style'])) {
            $styleElement = $nodeElement->addChild('style');
            $styleData = $nodeData['style'];
            // Enforce StyleType order: lineColor → fillColor → font
            foreach (['lineColor', 'fillColor', 'font'] as $styleKey) {
                if (isset($styleData[$styleKey]) && is_array($styleData[$styleKey])) {
                    $child = $styleElement->addChild($styleKey);
                    $this->arrayToXml($styleData[$styleKey], $child);
                }
            }
        }

        // viewRef
        if (isset($nodeData['viewRef'])) {
            $viewRefData = $nodeData['viewRef'];
            if (is_array($viewRefData)) {
                $vrElement = $nodeElement->addChild('viewRef');
                $this->arrayToXml($viewRefData, $vrElement);
            }
        }

        // Nested nodes (Container/Element type)
        if (isset($nodeData['node']) && is_array($nodeData['node'])) {
            $nestedNodes = $nodeData['node'];
            if (!$this->isList($nestedNodes)) {
                $nestedNodes = [$nestedNodes];
            }
            foreach ($nestedNodes as $nestedNodeData) {
                if (is_array($nestedNodeData)) {
                    $nestedNodeElement = $nodeElement->addChild('node');
                    $this->addNodeDataToXmlElement($nestedNodeElement, $nestedNodeData);
                }
            }
        }
    }

    /**
     * Add organization item to XML with XSD-required child order: label → documentation → item
     */
    private function addOrganizationItemToXml(\SimpleXMLElement $itemNode, array $itemData): void
    {
        // Add identifierRef attribute if present
        if (isset($itemData['_identifierRef'])) {
            $itemNode->addAttribute('identifierRef', (string)$itemData['_identifierRef']);
        } elseif (isset($itemData['_attributes']['identifierRef'])) {
            $itemNode->addAttribute('identifierRef', (string)$itemData['_attributes']['identifierRef']);
        }

        // XSD order: label → documentation → item
        // Labels first
        if (isset($itemData['label'])) {
            $labels = $itemData['label'];
            if (is_array($labels) && !$this->isList($labels)) {
                $labels = [$labels]; // Single label → list
            }
            if (is_array($labels)) {
                foreach ($labels as $labelData) {
                    if (is_array($labelData)) {
                        $labelElement = $itemNode->addChild('label');
                        $this->arrayToXml($labelData, $labelElement);
                    } elseif (is_string($labelData)) {
                        $labelElement = $itemNode->addChild('label');
                        $labelElement[0] = $labelData;
                    }
                }
            } elseif (is_string($labels)) {
                $labelElement = $itemNode->addChild('label');
                $labelElement[0] = $labels;
            }
        }

        // Documentation
        $this->addLangTextChild($itemNode, 'documentation', $itemData['documentation'] ?? null);

        // Nested items
        if (isset($itemData['item'])) {
            $items = $itemData['item'];
            if (is_array($items) && !$this->isList($items)) {
                $items = [$items];
            }
            if (is_array($items)) {
                foreach ($items as $childItemData) {
                    if (is_array($childItemData)) {
                        $childNode = $itemNode->addChild('item');
                        $this->addOrganizationItemToXml($childNode, $childItemData);
                    }
                }
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
                // Skip colon-prefixed duplicate keys
                if (str_starts_with($attrKey, ':')) {
                    continue;
                }
                $isPropertyDefinition = ($sectionName === 'property_definitions');
                if ($attrKey === 'xsi:type') {
                    if ($isPropertyDefinition) {
                        $attributes['type'] = (string)$attrValue;
                    } else {
                        $attributes['xsi:type'] = (string)$attrValue;
                    }
                } elseif (in_array($attrKey, ['identifier', 'source', 'target', 'accessType', 'isDirected', 'type'])) {
                    if ($attrKey === 'type' && !$isPropertyDefinition) {
                        $attributes['xsi:type'] = (string)$attrValue;
                    } else {
                        $attributes[$attrKey] = (string)$attrValue;
                    }
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
        foreach (['source', 'target', 'accessType', 'isDirected', 'type'] as $attrName) {
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
        // Handle child elements in XSD-required order (xs:sequence):
        // NamedReferenceableType: name → documentation → properties
        $this->addLangTextChild($node, 'name', $data['name'] ?? null);
        $this->addLangTextChild($node, 'documentation', $data['documentation'] ?? null);
        if (isset($data['properties']) && is_array($data['properties'])) {
            $this->addPropertiesToXml($node, $data['properties']);
        }
        // Add properties from root fields using propertyDefinitionMap ONLY if no properties were already processed
        if (!empty($propertyDefinitionMap) && !isset($data['properties'])) {
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
            
            // Also check in _attributes, but avoid duplicate if we already found one
            if (!$propDefRef && isset($property['_attributes']['propertyDefinitionRef'])) {
                $propDefRef = (string)$property['_attributes']['propertyDefinitionRef'];
            }
            
            // Skip and clean up malformed attributes that would create invalid XML
            if (isset($property['_attributes'][':propertyDefinitionRef'])) {
                unset($property['_attributes'][':propertyDefinitionRef']);
            }
            // Also check for other malformed attribute patterns
            $badAttrs = [];
            foreach ($property['_attributes'] ?? [] as $attrName => $attrValue) {
                if (str_starts_with($attrName, ':')) {
                    $badAttrs[] = $attrName;
                }
            }
            foreach ($badAttrs as $badAttr) {
                unset($property['_attributes'][$badAttr]);
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
     * Add a child element with text content and optional xml:lang attribute
     */
    private function addLangTextChild(\SimpleXMLElement $parent, string $tagName, $data): void
    {
        if ($data === null) {
            return;
        }
        if (is_array($data)) {
            $childNode = $parent->addChild($tagName);
            if (isset($data['_value'])) {
                $childNode[0] = (string)$data['_value'];
            }
            foreach (['xml:lang', '_xml:lang', '_xml__lang', 'xml_lang'] as $langKey) {
                if (isset($data[$langKey])) {
                    $childNode->addAttribute('xml:lang', $data[$langKey], 'http://www.w3.org/XML/1998/namespace');
                    break;
                }
            }
        } elseif (is_string($data) && $data !== '') {
            $childNode = $parent->addChild($tagName);
            $childNode[0] = $data;
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
        // Prefer xml field data (preserves full array structure with xml:lang from import)
        $xmlField = $modelMetadata['xml'] ?? [];

        // Resolve name: prefer xml field (array with _value/_xml__lang), fall back to flat field
        $nameData = $xmlField['name'] ?? $modelMetadata['name'] ?? null;
        if ($nameData !== null) {
            $nameNode = $xml->addChild('name');
            if (is_array($nameData) && isset($nameData['_value'])) {
                $nameNode[0] = (string)$nameData['_value'];
                foreach (['xml:lang', '_xml:lang', '_xml__lang', 'xml_lang'] as $langKey) {
                    if (isset($nameData[$langKey])) {
                        $nameNode->addAttribute('xml:lang', $nameData[$langKey], 'http://www.w3.org/XML/1998/namespace');
                        break;
                    }
                }
            } elseif (is_string($nameData)) {
                $nameNode[0] = $nameData;
            }
        }

        // Resolve documentation: prefer xml field, fall back to flat field
        $docData = $xmlField['documentation'] ?? $modelMetadata['documentation'] ?? null;
        if ($docData !== null) {
            $docNode = $xml->addChild('documentation');
            if (is_array($docData) && isset($docData['_value'])) {
                $docNode[0] = (string)$docData['_value'];
                foreach (['xml:lang', '_xml:lang', '_xml__lang', 'xml_lang'] as $langKey) {
                    if (isset($docData[$langKey])) {
                        $docNode->addAttribute('xml:lang', $docData[$langKey], 'http://www.w3.org/XML/1998/namespace');
                        break;
                    }
                }
            } elseif (is_string($docData)) {
                $docNode[0] = $docData;
            }
        }

        // Resolve properties: prefer xml field, fall back to flat field
        $propsData = $xmlField['properties'] ?? $modelMetadata['properties'] ?? null;
        if ($propsData !== null && is_array($propsData)) {
            $this->addPropertiesToXml($xml, $propsData);
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
        
        // DEBUG: Save XML to file for inspection
        $debugPath = '/tmp/debug_export.xml';
        file_put_contents($debugPath, $xmlString);
        $this->logger->info('DEBUG: Raw XML saved to ' . $debugPath . ' (size: ' . strlen($xmlString) . ' bytes)');
        
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

    // =========================================================================
    // Organization-specific ArchiMate export
    // =========================================================================

    /**
     * Export organization-enriched ArchiMate XML.
     *
     * Takes the base GEMMA model objects, adds the organization's applications
     * as ApplicationComponent elements, creates SpecializationRelationships to
     * referentiecomponenten, copies views with applications plotted inside, and
     * adds SWC-specific organization folders.
     *
     * @param \OCA\OpenRegister\Service\ObjectService $objectService
     * @param int    $registerId    AMEF register ID
     * @param array  $schemaIdMap   Schema ID → type mapping
     * @param string $orgName       Human-readable organization name
     * @param string $orgUuid       Organization UUID
     * @param array  $gebruikData   Usage objects for this organization
     * @param array  $modulesData   Module objects for this organization
     * @return string Generated XML
     */
    public function exportOrganizationArchiMateXml(
        \OCA\OpenRegister\Service\ObjectService $objectService,
        int $registerId,
        array $schemaIdMap,
        string $orgName,
        string $orgUuid,
        array $gebruikData,
        array $modulesData
    ): string {
        $startTime = microtime(true);
        $this->logger->info('Starting organization ArchiMate export', [
            'organization' => $orgName,
            'gebruik_count' => count($gebruikData),
            'modules_count' => count($modulesData)
        ]);

        // Step 1: Get all base GEMMA objects
        $baseObjects = $this->getObjectsFromDatabase($objectService, $registerId, $schemaIdMap);

        // Step 2: Build module lookup maps
        [$moduleRefMap, $moduleNameMap] = $this->buildModuleLookupMaps($gebruikData, $modulesData);

        // Step 3: Ensure Bron property definition
        $bronPropDefId = $this->ensureBronPropertyDefinition($baseObjects);

        // Step 4: Generate SWC application elements
        $appElements = $this->generateApplicationElements($moduleRefMap, $moduleNameMap, $bronPropDefId);

        // Step 5: Generate SpecializationRelationships
        $relationships = $this->generateSpecializationRelationships($moduleRefMap, $bronPropDefId);

        // Step 6: Copy and enrich views
        $viewCopies = $this->copyAndEnrichViews(
            $baseObjects, $orgName, $moduleRefMap, $moduleNameMap, $appElements, $relationships, $bronPropDefId
        );

        // Step 7: Build SWC organization folders
        $swcFolders = $this->buildSwcOrganizationFolders($appElements, $relationships, $viewCopies);

        // Step 8: Assemble into XML
        $xml = $this->assembleOrganizationXml(
            $baseObjects, $orgName, $appElements, $relationships, $viewCopies, $swcFolders, $bronPropDefId
        );

        $totalTime = microtime(true) - $startTime;
        $this->logger->info('Organization ArchiMate export completed', [
            'organization' => $orgName,
            'app_elements' => count($appElements),
            'relationships' => count($relationships),
            'view_copies' => count($viewCopies),
            'total_time_seconds' => round($totalTime, 3)
        ]);

        return $xml;
    }

    /**
     * Build lookup maps from gebruik and modules data.
     *
     * @return array [moduleRefMap, moduleNameMap]
     *   moduleRefMap: moduleId => [refCompIdentifiers]
     *   moduleNameMap: moduleId => name
     */
    private function buildModuleLookupMaps(array $gebruikData, array $modulesData): array
    {
        $moduleRefMap = [];   // moduleId => [refCompIdentifiers]
        $moduleNameMap = [];  // moduleId => name

        // Build name map from modules data
        foreach ($modulesData as $module) {
            if (is_object($module) && method_exists($module, 'jsonSerialize')) {
                $module = $module->jsonSerialize();
            }
            $id = $module['id'] ?? $module['@self']['id'] ?? null;
            $name = $module['naam'] ?? $module['name'] ?? $module['@self']['name'] ?? null;
            if ($id && $name) {
                $moduleNameMap[$id] = $name;
            }
        }

        // Build ref map from gebruik data
        foreach ($gebruikData as $gebruik) {
            if (is_object($gebruik) && method_exists($gebruik, 'jsonSerialize')) {
                $gebruik = $gebruik->jsonSerialize();
            }

            $moduleId = $gebruik['module'] ?? null;
            if (!$moduleId) continue;

            // Get module name from gebruik if not already known
            if (!isset($moduleNameMap[$moduleId])) {
                $moduleNameMap[$moduleId] = $gebruik['moduleName'] ?? $gebruik['@self']['name'] ?? 'Module';
            }

            // Get referentiecomponenten UUIDs
            $refComps = $gebruik['gebruiktVoorReferentiecomponenten'] ?? [];
            if (!is_array($refComps)) continue;

            foreach ($refComps as $refComp) {
                $refCompUuid = is_string($refComp) ? $refComp : ($refComp['id'] ?? $refComp['uuid'] ?? null);
                if (!$refCompUuid) continue;

                // Build the ArchiMate identifier (id-{uuid})
                $refCompIdentifier = 'id-' . $refCompUuid;

                if (!isset($moduleRefMap[$moduleId])) {
                    $moduleRefMap[$moduleId] = [];
                }
                if (!in_array($refCompIdentifier, $moduleRefMap[$moduleId])) {
                    $moduleRefMap[$moduleId][] = $refCompIdentifier;
                }
            }
        }

        $this->logger->debug('Module lookup maps built', [
            'modules_with_refs' => count($moduleRefMap),
            'modules_with_names' => count($moduleNameMap)
        ]);

        return [$moduleRefMap, $moduleNameMap];
    }

    /**
     * Check if a Bron property definition exists in base objects, add one if not.
     *
     * @return string The propertyDefinition identifier for Bron
     */
    private function ensureBronPropertyDefinition(array &$baseObjects): string
    {
        $bronId = 'id-swc-propdef-bron';

        // Check if "Bron" already exists
        foreach ($baseObjects as $obj) {
            if (is_object($obj) && method_exists($obj, 'jsonSerialize')) {
                $obj = $obj->jsonSerialize();
            }
            $section = $obj['section'] ?? '';
            if ($section === 'property_definition') {
                $xml = $obj['xml'] ?? [];
                $name = $xml['name']['_value'] ?? $xml['name'] ?? null;
                if ($name === 'Bron') {
                    $existingId = $xml['_identifier'] ?? $obj['identifier'] ?? null;
                    if ($existingId) {
                        $this->logger->debug('Found existing Bron property definition', ['id' => $existingId]);
                        return $existingId;
                    }
                }
            }
        }

        $this->logger->debug('Bron property definition not found, will create', ['id' => $bronId]);
        return $bronId;
    }

    /**
     * Generate ApplicationComponent element arrays for each module.
     *
     * @return array Array of element data arrays ready for XML generation
     */
    private function generateApplicationElements(array $moduleRefMap, array $moduleNameMap, string $bronPropDefId): array
    {
        $elements = [];

        foreach ($moduleRefMap as $moduleId => $refCompIds) {
            $appIdentifier = 'id-swc-app-' . $moduleId;
            $name = $moduleNameMap[$moduleId] ?? 'Module';

            $elements[] = [
                'identifier' => $appIdentifier,
                'name' => $name,
                'xsi_type' => 'ApplicationComponent',
                'bronPropDefId' => $bronPropDefId,
                'moduleId' => $moduleId,
            ];
        }

        $this->logger->debug('Generated application elements', ['count' => count($elements)]);
        return $elements;
    }

    /**
     * Generate SpecializationRelationship arrays for module → refcomp mappings.
     *
     * @return array Array of relationship data arrays
     */
    private function generateSpecializationRelationships(array $moduleRefMap, string $bronPropDefId): array
    {
        $relationships = [];

        foreach ($moduleRefMap as $moduleId => $refCompIds) {
            $appIdentifier = 'id-swc-app-' . $moduleId;

            foreach ($refCompIds as $refCompIdentifier) {
                $relIdentifier = 'id-swc-rel-' . $moduleId . '-' . str_replace('id-', '', $refCompIdentifier);

                $relationships[] = [
                    'identifier' => $relIdentifier,
                    'xsi_type' => 'SpecializationRelationship',
                    'source' => $appIdentifier,
                    'target' => $refCompIdentifier,
                    'bronPropDefId' => $bronPropDefId,
                ];
            }
        }

        $this->logger->debug('Generated specialization relationships', ['count' => count($relationships)]);
        return $relationships;
    }

    /**
     * Copy qualifying views and inject application nodes inside referentiecomponent nodes.
     *
     * @return array Array of enriched view data arrays (XML blob format)
     */
    private function copyAndEnrichViews(
        array $baseObjects,
        string $orgName,
        array $moduleRefMap,
        array $moduleNameMap,
        array $appElements,
        array $relationships,
        string $bronPropDefId
    ): array {
        $viewCopies = [];

        // Build a reverse lookup: refCompIdentifier => [(appIdentifier, relIdentifier, moduleName)]
        $refCompApps = [];
        foreach ($moduleRefMap as $moduleId => $refCompIds) {
            $appIdentifier = 'id-swc-app-' . $moduleId;
            $moduleName = $moduleNameMap[$moduleId] ?? 'Module';

            foreach ($refCompIds as $refCompIdentifier) {
                $relIdentifier = 'id-swc-rel-' . $moduleId . '-' . str_replace('id-', '', $refCompIdentifier);

                if (!isset($refCompApps[$refCompIdentifier])) {
                    $refCompApps[$refCompIdentifier] = [];
                }
                $refCompApps[$refCompIdentifier][] = [
                    'appIdentifier' => $appIdentifier,
                    'relIdentifier' => $relIdentifier,
                    'name' => $moduleName,
                ];
            }
        }

        // Iterate view objects
        foreach ($baseObjects as $obj) {
            if (is_object($obj) && method_exists($obj, 'jsonSerialize')) {
                $obj = $obj->jsonSerialize();
            }
            $section = $obj['section'] ?? '';
            if ($section !== 'view') continue;

            $xmlData = $obj['xml'] ?? [];
            if (empty($xmlData)) continue;

            $originalIdentifier = $xmlData['_identifier'] ?? $obj['identifier'] ?? null;
            if (!$originalIdentifier) continue;

            // Deep-copy the view XML
            $viewCopy = json_decode(json_encode($xmlData), true);

            // Assign new identifier
            $newIdentifier = 'id-swc-view-' . str_replace('id-', '', $originalIdentifier);
            $viewCopy['_identifier'] = $newIdentifier;
            if (isset($viewCopy['_attributes']['identifier'])) {
                $viewCopy['_attributes']['identifier'] = $newIdentifier;
            }

            // Rename view: use Titel view SWC property or fallback to original name
            $viewName = $this->getViewSwcTitle($viewCopy) ?? $this->getViewName($viewCopy);
            $viewCopy['name'] = ['_value' => $viewName . ' ' . $orgName];

            // Add Bron property to view
            $viewCopy = $this->addBronProperty($viewCopy, $bronPropDefId);

            // Inject application nodes and connections
            $viewCopy = $this->injectApplicationNodesInView($viewCopy, $refCompApps);

            $viewCopies[] = [
                'identifier' => $newIdentifier,
                'xml' => $viewCopy,
                'section' => 'view',
            ];
        }

        $this->logger->debug('Copied and enriched views', ['count' => count($viewCopies)]);
        return $viewCopies;
    }

    /**
     * Extract "Titel view SWC" property value from view XML data.
     */
    private function getViewSwcTitle(array $viewData): ?string
    {
        $properties = $viewData['properties']['property'] ?? $viewData['properties'] ?? [];
        if (!is_array($properties)) return null;
        // Normalize to list
        if (isset($properties['_propertyDefinitionRef'])) {
            $properties = [$properties];
        }

        foreach ($properties as $prop) {
            if (!is_array($prop)) continue;
            // Check property definition name or ref
            $value = $prop['value']['_value'] ?? $prop['value'] ?? null;
            // We look for a property whose name contains "Titel view SWC"
            // This is stored as the property's propertyDefinitionRef linking to a named definition
            // For now, check if the property key/label matches
            $propName = $prop['_name'] ?? $prop['name'] ?? '';
            if (is_string($propName) && stripos($propName, 'Titel view SWC') !== false && $value) {
                return is_string($value) ? $value : null;
            }
        }

        return null;
    }

    /**
     * Extract view name from view XML data.
     */
    private function getViewName(array $viewData): string
    {
        if (isset($viewData['name']['_value'])) {
            return $viewData['name']['_value'];
        }
        if (isset($viewData['name']) && is_string($viewData['name'])) {
            return $viewData['name'];
        }
        return 'View';
    }

    /**
     * Add Bron=Softwarecatalogus property to an XML data array.
     */
    private function addBronProperty(array $data, string $bronPropDefId): array
    {
        $bronProp = [
            '_propertyDefinitionRef' => $bronPropDefId,
            'value' => ['_value' => 'Softwarecatalogus']
        ];

        if (!isset($data['properties'])) {
            $data['properties'] = ['property' => [$bronProp]];
        } elseif (isset($data['properties']['property'])) {
            if (isset($data['properties']['property']['_propertyDefinitionRef'])) {
                // Single property, convert to list
                $data['properties']['property'] = [$data['properties']['property'], $bronProp];
            } else {
                $data['properties']['property'][] = $bronProp;
            }
        } else {
            $data['properties']['property'] = [$bronProp];
        }

        return $data;
    }

    /**
     * Walk the view node tree and inject application child nodes.
     *
     * For each node whose elementRef matches a referentiecomponent with mapped apps,
     * add child nodes and connection elements.
     */
    private function injectApplicationNodesInView(array $viewData, array $refCompApps): array
    {
        // Inject into top-level nodes
        if (isset($viewData['node']) && is_array($viewData['node'])) {
            $nodes = $viewData['node'];
            if (!$this->isList($nodes)) {
                $nodes = [$nodes];
            }

            $newConnections = [];
            $viewData['node'] = $this->processNodesForInjection($nodes, $refCompApps, $newConnections);

            // Add connections to the view
            if (!empty($newConnections)) {
                if (!isset($viewData['connection'])) {
                    $viewData['connection'] = [];
                } elseif (!$this->isList($viewData['connection'])) {
                    $viewData['connection'] = [$viewData['connection']];
                }
                foreach ($newConnections as $conn) {
                    $viewData['connection'][] = $conn;
                }
            }
        }

        return $viewData;
    }

    /**
     * Recursively process nodes, injecting application child nodes where appropriate.
     */
    private function processNodesForInjection(array $nodes, array $refCompApps, array &$newConnections): array
    {
        foreach ($nodes as &$node) {
            if (!is_array($node)) continue;

            $elementRef = $node['_elementRef'] ?? $node['_attributes']['elementRef'] ?? null;

            if ($elementRef && isset($refCompApps[$elementRef])) {
                $apps = $refCompApps[$elementRef];
                $parentW = (int)($node['_w'] ?? $node['_attributes']['w'] ?? 120);
                $parentH = (int)($node['_h'] ?? $node['_attributes']['h'] ?? 80);
                $parentIdentifier = $node['_identifier'] ?? $node['_attributes']['identifier'] ?? null;

                // Calculate child node positions
                $childW = max($parentW - 20, 40);
                $childH = 18;
                $gap = 2;
                $childX = 10;

                // Ensure nested nodes array
                if (!isset($node['node'])) {
                    $node['node'] = [];
                } elseif (!$this->isList($node['node'])) {
                    $node['node'] = [$node['node']];
                }

                $existingChildCount = count($node['node']);

                foreach ($apps as $index => $app) {
                    $stackIndex = $existingChildCount + $index;
                    $childY = $parentH - 5 - (($stackIndex + 1) * ($childH + $gap));
                    if ($childY < 20) $childY = 20 + ($stackIndex * ($childH + $gap));

                    $childNodeId = 'id-swc-node-' . $app['appIdentifier'] . '-' . str_replace('id-', '', $elementRef);

                    $childNode = [
                        '_identifier' => $childNodeId,
                        '_elementRef' => $app['appIdentifier'],
                        '_xsi__type' => 'Element',
                        '_x' => (string)$childX,
                        '_y' => (string)max(20, $childY),
                        '_w' => (string)$childW,
                        '_h' => (string)$childH,
                        'style' => [
                            'fillColor' => ['_r' => '200', '_g' => '255', '_b' => '200', '_a' => '100'],
                            'lineColor' => ['_r' => '0', '_g' => '150', '_b' => '0'],
                            'font' => ['_name' => 'Segoe UI', '_size' => '9']
                        ]
                    ];

                    $node['node'][] = $childNode;

                    // Create connection for the relationship
                    if ($parentIdentifier) {
                        $connId = 'id-swc-conn-' . str_replace('id-swc-rel-', '', $app['relIdentifier']);
                        $newConnections[] = [
                            '_identifier' => $connId,
                            '_relationshipRef' => $app['relIdentifier'],
                            '_source' => $childNodeId,
                            '_target' => $parentIdentifier,
                            '_xsi__type' => 'Relationship',
                        ];
                    }
                }
            }

            // Recurse into nested nodes
            if (isset($node['node']) && is_array($node['node'])) {
                $nestedNodes = $node['node'];
                if (!$this->isList($nestedNodes)) {
                    $nestedNodes = [$nestedNodes];
                }
                $node['node'] = $this->processNodesForInjection($nestedNodes, $refCompApps, $newConnections);
            }
        }
        unset($node);

        return $nodes;
    }

    /**
     * Build SWC organization folder items.
     *
     * @return array Organization items for the SWC folders
     */
    private function buildSwcOrganizationFolders(array $appElements, array $relationships, array $viewCopies): array
    {
        $appItems = [];
        foreach ($appElements as $el) {
            $appItems[] = ['_identifierRef' => $el['identifier']];
        }

        $relItems = [];
        foreach ($relationships as $rel) {
            $relItems[] = ['_identifierRef' => $rel['identifier']];
        }

        $viewItems = [];
        foreach ($viewCopies as $vc) {
            $viewItems[] = ['_identifierRef' => $vc['identifier']];
        }

        return [
            'applications' => [
                'label' => ['_value' => 'Applicaties (Softwarecatalogus)'],
                'items' => $appItems,
            ],
            'relations' => [
                'label' => ['_value' => 'Relaties (Softwarecatalogus)'],
                'items' => $relItems,
            ],
            'views' => [
                'label' => ['_value' => 'Views (Softwarecatalogus)'],
                'items' => $viewItems,
            ],
        ];
    }

    /**
     * Assemble the final organization-specific ArchiMate XML.
     */
    private function assembleOrganizationXml(
        array $baseObjects,
        string $orgName,
        array $appElements,
        array $relationships,
        array $viewCopies,
        array $swcFolders,
        string $bronPropDefId
    ): string {
        // Extract model metadata
        $modelMetadata = $this->extractModelMetadata($baseObjects);
        $propertyDefinitionMap = $modelMetadata['propertyDefinitionMap'] ?? [];

        // Create base XML
        $xml = $this->createCleanArchiMateXml($modelMetadata);

        // Override model name
        $modelName = 'Softwarecatalogus ' . $orgName;
        // Remove existing name children and add new one
        foreach ($xml->children() as $child) {
            if ($child->getName() === 'name') {
                $dom = dom_import_simplexml($child);
                $dom->parentNode->removeChild($dom);
                break;
            }
        }
        $nameEl = $xml->addChild('name', htmlspecialchars($modelName));
        $nameEl->addAttribute('xml:lang', 'nl', 'http://www.w3.org/XML/1998/namespace');

        // Organize base objects by section
        $objectsBySection = [];
        foreach ($baseObjects as $object) {
            if (is_object($object) && method_exists($object, 'jsonSerialize')) {
                $object = $object->jsonSerialize();
            }
            $sectionName = $object['section'] ?? null;
            if ($sectionName) {
                $objectsBySection[$sectionName][] = $object;
            }
        }

        // --- Elements section ---
        $elementsFolder = $xml->addChild('elements');
        $sectionMapping = ['element' => 'elements'];
        foreach ($objectsBySection as $dbSection => $objects) {
            if (($sectionMapping[$dbSection] ?? null) === 'elements') {
                foreach ($objects as $obj) {
                    if (is_object($obj) && method_exists($obj, 'jsonSerialize')) {
                        $obj = $obj->jsonSerialize();
                    }
                    $this->addObjectDirectlyToXmlWithProperties($elementsFolder, $obj, 'elements', $propertyDefinitionMap);
                }
            }
        }
        // Add SWC application elements
        foreach ($appElements as $appEl) {
            $elNode = $elementsFolder->addChild('element');
            $elNode->addAttribute('identifier', $appEl['identifier']);
            $elNode->addAttribute('xsi:type', $appEl['xsi_type'], 'http://www.w3.org/2001/XMLSchema-instance');
            $nameChild = $elNode->addChild('name', htmlspecialchars($appEl['name']));
            $nameChild->addAttribute('xml:lang', 'nl', 'http://www.w3.org/XML/1998/namespace');
            // Add Bron property
            $propsEl = $elNode->addChild('properties');
            $propEl = $propsEl->addChild('property');
            $propEl->addAttribute('propertyDefinitionRef', $appEl['bronPropDefId']);
            $propEl->addChild('value', 'Softwarecatalogus');
        }

        // --- Relationships section ---
        $relsFolder = $xml->addChild('relationships');
        foreach ($objectsBySection as $dbSection => $objects) {
            if ($dbSection === 'relationship') {
                foreach ($objects as $obj) {
                    if (is_object($obj) && method_exists($obj, 'jsonSerialize')) {
                        $obj = $obj->jsonSerialize();
                    }
                    $this->addObjectDirectlyToXmlWithProperties($relsFolder, $obj, 'relationships', $propertyDefinitionMap);
                }
            }
        }
        // Add SWC relationships
        foreach ($relationships as $rel) {
            $relNode = $relsFolder->addChild('relationship');
            $relNode->addAttribute('identifier', $rel['identifier']);
            $relNode->addAttribute('xsi:type', $rel['xsi_type'], 'http://www.w3.org/2001/XMLSchema-instance');
            $relNode->addAttribute('source', $rel['source']);
            $relNode->addAttribute('target', $rel['target']);
            $propsEl = $relNode->addChild('properties');
            $propEl = $propsEl->addChild('property');
            $propEl->addAttribute('propertyDefinitionRef', $rel['bronPropDefId']);
            $propEl->addChild('value', 'Softwarecatalogus');
        }

        // --- Property Definitions section ---
        $propDefsFolder = $xml->addChild('propertyDefinitions');
        foreach ($objectsBySection as $dbSection => $objects) {
            if ($dbSection === 'property_definition') {
                foreach ($objects as $obj) {
                    if (is_object($obj) && method_exists($obj, 'jsonSerialize')) {
                        $obj = $obj->jsonSerialize();
                    }
                    $this->addObjectDirectlyToXmlWithProperties($propDefsFolder, $obj, 'property_definitions', $propertyDefinitionMap);
                }
            }
        }
        // Add Bron property definition if we created it
        if ($bronPropDefId === 'id-swc-propdef-bron') {
            $propDefNode = $propDefsFolder->addChild('propertyDefinition');
            $propDefNode->addAttribute('identifier', $bronPropDefId);
            $propDefNode->addAttribute('type', 'string');
            $nameChild = $propDefNode->addChild('name', 'Bron');
            $nameChild->addAttribute('xml:lang', 'nl', 'http://www.w3.org/XML/1998/namespace');
        }

        // --- Organizations section ---
        $orgsFolder = $xml->addChild('organizations');
        // Write existing organization tree
        foreach ($objectsBySection as $dbSection => $objects) {
            if ($dbSection === 'organization') {
                foreach ($objects as $obj) {
                    if (is_object($obj) && method_exists($obj, 'jsonSerialize')) {
                        $obj = $obj->jsonSerialize();
                    }
                    $xmlField = $obj['xml'] ?? [];
                    if (isset($xmlField['item'])) {
                        $items = $xmlField['item'];
                        if (!isset($items[0])) $items = [$items];
                        foreach ($items as $itemData) {
                            if (is_array($itemData)) {
                                $itemNode = $orgsFolder->addChild('item');
                                $this->addOrganizationItemToXml($itemNode, $itemData);
                            }
                        }
                    }
                }
            }
        }
        // Add SWC folder: top-level folder named after organization, with sub-folders
        $orgFolder = $orgsFolder->addChild('item');
        $orgLabelEl = $orgFolder->addChild('label', htmlspecialchars($orgName));
        $orgLabelEl->addAttribute('xml:lang', 'nl', 'http://www.w3.org/XML/1998/namespace');
        foreach ($swcFolders as $folderData) {
            $subFolder = $orgFolder->addChild('item');
            $labelEl = $subFolder->addChild('label', htmlspecialchars($folderData['label']['_value']));
            $labelEl->addAttribute('xml:lang', 'nl', 'http://www.w3.org/XML/1998/namespace');
            foreach ($folderData['items'] as $identifierRefItem) {
                $childItem = $subFolder->addChild('item');
                $childItem->addAttribute('identifierRef', $identifierRefItem['_identifierRef']);
            }
        }

        // --- Views section ---
        $viewsSection = $xml->addChild('views');
        $diagramsFolder = $viewsSection->addChild('diagrams');
        // Write original views
        foreach ($objectsBySection as $dbSection => $objects) {
            if ($dbSection === 'view') {
                foreach ($objects as $obj) {
                    if (is_object($obj) && method_exists($obj, 'jsonSerialize')) {
                        $obj = $obj->jsonSerialize();
                    }
                    $this->addObjectDirectlyToXmlWithProperties($diagramsFolder, $obj, 'views', $propertyDefinitionMap);
                }
            }
        }
        // Write enriched view copies
        foreach ($viewCopies as $vc) {
            $viewNode = $diagramsFolder->addChild('view');
            $this->addViewDataToXmlNode($viewNode, $vc['xml']);
        }

        return $this->formatXmlOutput($xml->asXML());
    }
}


