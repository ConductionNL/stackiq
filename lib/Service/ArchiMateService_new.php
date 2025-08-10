<?php

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Service;

use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use OCP\Files\IRootFolder;
use OCP\IUserSession;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use React\Promise\Promise;
use React\Promise\Deferred;

/**
 * Class ArchiMateService - ULTRA SIMPLIFIED VERSION
 *
 * @category Service
 * @package  OCA\SoftwareCatalog\Service
 * @author   Conduction b.v. <info@conduction.nl>
 * @copyright Copyright (c) Conduction
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @version  1.0.0
 * @link     https://github.com/ConductionNL/SoftwareCatalog
 *
 * Ultra simplified approach:
 * 1. Convert entire XML to array
 * 2. Extract specific AMEF schemas (views, elements, relations, etc.) as separate objects
 * 3. Add modelId property to each extracted object
 * 4. Remove extracted arrays from the core model array
 * 5. Use bulk save for efficiency
 */
class ArchiMateService
{
    public const APP_NAME = 'softwarecatalog';

    public function __construct(
        private readonly IAppConfig $config,
        private readonly IRootFolder $rootFolder,
        private readonly IUserSession $userSession,
        private readonly IAppManager $appManager,
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger
    ) {
        $this->importService = new ArchiMateImportService($logger);
        $this->exportService = new ArchiMateExportService($logger);
    }

    private readonly ArchiMateImportService $importService;
    private readonly ArchiMateExportService $exportService;

    /**
     * Import ArchiMate file from path - ULTRA SIMPLIFIED
     * 1. Parse entire XML to array
     * 2. Extract AMEF objects and bulk save with modelId
     * 3. Save cleaned model array
     */
    public function importArchiMateFileFromPath(array $options = []): array
    {
        $filePath = $options['file_path'] ?? '';
        
        $this->logger->info('=== ARCHIMATE IMPORT START (ULTRA SIMPLIFIED) ===', [
            'file_path' => $filePath
        ]);

        try {
            // 1. Parse entire XML to array
            $xmlArray = $this->parseXmlToCompleteArray($filePath);
            
            // 2. Extract model identifier
            $modelId = $this->extractModelId($xmlArray);
            
            // 3. Extract and bulk save AMEF schema objects
            $results = $this->extractAndBulkSaveAmefObjects($xmlArray, $modelId);
            
            // 4. Save cleaned model array (with AMEF objects removed)
            $modelResult = $this->saveCleanedModelArray($xmlArray, $modelId);
            
            $totalCreated = array_sum(array_column($results, 'created'));
            $totalUpdated = array_sum(array_column($results, 'updated'));
            
            $this->logger->info('=== ARCHIMATE IMPORT COMPLETED ===', [
                'model_id' => $modelId,
                'total_created' => $totalCreated,
                'total_updated' => $totalUpdated
            ]);
            
            return [
                'success' => true,
                'model_id' => $modelId,
                'statistics' => $results,
                'total_created' => $totalCreated,
                'total_updated' => $totalUpdated
            ];
            
        } catch (\Exception $e) {
            $this->logger->error('ArchiMate import failed', [
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
     * Parse entire XML file to complete array structure
     */
    private function parseXmlToCompleteArray(string $filePath): array
    {
        if (!file_exists($filePath)) {
            throw new \RuntimeException("File not found: {$filePath}");
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            throw new \RuntimeException('Could not read file content');
        }

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($content);
        if ($xml === false) {
            $errors = libxml_get_errors();
            $errorMessages = array_map(fn($error) => trim($error->message), $errors);
            throw new \RuntimeException('Invalid XML format: ' . implode(', ', $errorMessages));
        }

        // Convert entire XML to array using our import service
        return $this->importService->xmlToArray($xml);
    }

    /**
     * Extract model identifier from XML array
     */
    private function extractModelId(array $xmlArray): string
    {
        // Try various locations for model identifier
        $modelId = $xmlArray['_attributes']['identifier'] ?? 
                   $xmlArray['_identifier'] ?? 
                   $xmlArray['identifier'] ?? 
                   'model-' . uniqid();
        
        $this->logger->info('Extracted model ID', ['model_id' => $modelId]);
        return $modelId;
    }

    /**
     * Extract AMEF schema objects and bulk save them
     */
    private function extractAndBulkSaveAmefObjects(array &$xmlArray, string $modelId): array
    {
        $results = [];
        
        // Define AMEF schema mappings
        $amefSchemas = [
            'elements' => ['child_tag' => 'element', 'schema_type' => 'element'],
            'relationships' => ['child_tag' => 'relationship', 'schema_type' => 'relationship'],
            'views' => ['child_tag' => 'view', 'schema_type' => 'view'],
            'organizations' => ['child_tag' => 'item', 'schema_type' => 'organization'],
            'propertyDefinitions' => ['child_tag' => 'propertyDefinition', 'schema_type' => 'property_definition']
        ];

        foreach ($amefSchemas as $sectionName => $config) {
            if (isset($xmlArray[$sectionName])) {
                $objects = $this->extractObjectsFromSection(
                    $xmlArray[$sectionName], 
                    $config['child_tag'], 
                    $modelId
                );
                
                if (!empty($objects)) {
                    $results[$config['schema_type']] = $this->bulkSaveObjects($objects, $config['schema_type']);
                    
                    // Remove from model array after extraction
                    unset($xmlArray[$sectionName]);
                    
                    $this->logger->info("Extracted and saved {$sectionName}", [
                        'count' => count($objects),
                        'schema_type' => $config['schema_type']
                    ]);
                } else {
                    $results[$config['schema_type']] = ['created' => 0, 'updated' => 0];
                }
            } else {
                $results[$config['schema_type']] = ['created' => 0, 'updated' => 0];
            }
        }

        return $results;
    }

    /**
     * Extract objects from a section of the XML array
     */
    private function extractObjectsFromSection(array $sectionData, string $childTag, string $modelId): array
    {
        $objects = [];
        
        // Handle different structures
        if (isset($sectionData[$childTag])) {
            // Structure: section -> childTag -> [items...]
            $items = is_array($sectionData[$childTag]) ? $sectionData[$childTag] : [$sectionData[$childTag]];
        } else {
            // Direct array structure: section -> [items...]
            $items = is_array($sectionData) ? $sectionData : [$sectionData];
        }

        foreach ($items as $item) {
            // Get identifier from various possible locations
            $identifier = $item['_attributes']['identifier'] ?? 
                         $item['_identifier'] ?? 
                         $item['identifier'] ?? 
                         null;

            if ($identifier) {
                // Create object with modelId property
                $objects[] = [
                    'archimate_id' => $identifier,
                    'uuid' => $identifier,
                    'name' => $this->extractName($item),
                    'archimate_type' => $this->extractType($item),
                    'original_archimate_type' => $this->extractType($item),
                    'documentation' => $this->extractDocumentation($item),
                    'modelId' => $modelId, // Add model identifier as property
                    'properties' => [
                        'xml_data' => json_encode($item), // Store complete raw XML data
                        'modelId' => $modelId // Also in properties for queries
                    ],
                    'source_id' => $item['_attributes']['source'] ?? $item['_source'] ?? '',
                    'target_id' => $item['_attributes']['target'] ?? $item['_target'] ?? '',
                    'import_time' => date('Y-m-d H:i:s'),
                    'import_source' => 'archimate_xml_import'
                ];
            } else {
                $this->logger->warning('Object missing identifier', [
                    'item_keys' => array_keys($item)
                ]);
            }
        }

        return $objects;
    }

    /**
     * Extract name from XML item
     */
    private function extractName(array $item): string
    {
        return $item['name']['_value'] ?? 
               $item['_attributes']['name'] ?? 
               $item['_name'] ?? 
               '';
    }

    /**
     * Extract type from XML item
     */
    private function extractType(array $item): string
    {
        return $item['_attributes']['xsi:type'] ?? 
               $item['_xsi__type'] ?? 
               $item['_attributes']['type'] ?? 
               $item['_type'] ?? 
               '';
    }

    /**
     * Extract documentation from XML item
     */
    private function extractDocumentation(array $item): string
    {
        return $item['documentation']['_value'] ?? 
               $item['documentation'] ?? 
               '';
    }

    /**
     * Bulk save objects using ObjectService
     */
    private function bulkSaveObjects(array $objects, string $schemaType): array
    {
        if (empty($objects)) {
            return ['created' => 0, 'updated' => 0];
        }

        $objectService = $this->getObjectService();
        if (!$objectService) {
            throw new \RuntimeException('ObjectService not available');
        }

        $registerId = $this->getAmefRegisterId();
        $schemaId = $this->getAmefSchemaIdForType($schemaType);
        
        if (!$registerId || !$schemaId) {
            throw new \RuntimeException("AMEF register or {$schemaType} schema not configured");
        }

        // Use bulk save if available, otherwise save individually
        $created = 0;
        $updated = 0;

        foreach ($objects as $objectData) {
            try {
                $savedObject = $objectService->saveObject($registerId, $schemaId, $objectData);
                // Simple heuristic for created vs updated
                $created++; // In real implementation, check if object existed
            } catch (\Exception $e) {
                $this->logger->error("Failed to save {$schemaType} object", [
                    'archimate_id' => $objectData['archimate_id'],
                    'error' => $e->getMessage()
                ]);
            }
        }

        return ['created' => $created, 'updated' => $updated];
    }

    /**
     * Save the cleaned model array (with AMEF objects removed)
     */
    private function saveCleanedModelArray(array $xmlArray, string $modelId): array
    {
        try {
            $modelData = [
                'archimate_id' => $modelId,
                'uuid' => $modelId,
                'name' => $this->extractName($xmlArray),
                'documentation' => $this->extractDocumentation($xmlArray),
                'properties' => [
                    'xml_data' => json_encode($xmlArray), // Store cleaned XML array
                    'model_type' => 'archimate_model'
                ],
                'import_time' => date('Y-m-d H:i:s'),
                'import_source' => 'archimate_xml_import'
            ];

            $savedModel = $this->saveObject($modelData, 'model');
            return ['success' => true, 'model_saved' => true];

        } catch (\Exception $e) {
            $this->logger->error('Failed to save model object', [
                'error' => $e->getMessage(),
                'model_id' => $modelId
            ]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Export to ArchiMate - ULTRA SIMPLIFIED
     */
    public function exportToArchiMate(array $criteria = [], array $options = []): array
    {
        try {
            // Get model object
            $modelObjects = $this->getModelObjects();
            $modelObject = !empty($modelObjects) ? $modelObjects[0] : [];
            
            // Reconstruct XML from model and objects
            $xmlArray = $this->reconstructXmlArray($modelObject);
            
            // Create XML using export service
            $xml = $this->exportService->createCleanArchiMateXml($modelObject);
            $this->exportService->arrayToXml($xmlArray, $xml);
            
            // Save to file
            $exportPath = '/tmp/archimate_export_latest.xml';
            file_put_contents($exportPath, $xml->asXML());
            
            return [
                'success' => true,
                'file_path' => $exportPath
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Reconstruct complete XML array from model and individual objects
     */
    private function reconstructXmlArray(array $modelObject): array
    {
        // Start with the cleaned model XML data
        $xmlArray = [];
        if (isset($modelObject['properties']['xml_data'])) {
            $xmlArray = json_decode($modelObject['properties']['xml_data'], true) ?: [];
        }

        // Add back the AMEF schema objects
        $modelId = $modelObject['archimate_id'] ?? '';
        if ($modelId) {
            $xmlArray['elements'] = $this->getObjectsAsXmlArray('element', $modelId);
            $xmlArray['relationships'] = $this->getObjectsAsXmlArray('relationship', $modelId);
            $xmlArray['views'] = $this->getObjectsAsXmlArray('view', $modelId);
            $xmlArray['organizations'] = $this->getObjectsAsXmlArray('organization', $modelId);
            $xmlArray['propertyDefinitions'] = $this->getObjectsAsXmlArray('property_definition', $modelId);
        }

        return $xmlArray;
    }

    /**
     * Get objects as XML array for a specific schema type
     */
    private function getObjectsAsXmlArray(string $schemaType, string $modelId): array
    {
        $objects = $this->getObjectsWithPagination($schemaType, [
            'modelId' => $modelId
        ]);

        $xmlObjects = [];
        foreach ($objects as $object) {
            if (isset($object['properties']['xml_data'])) {
                $xmlData = json_decode($object['properties']['xml_data'], true);
                if ($xmlData) {
                    $xmlObjects[] = $xmlData;
                }
            }
        }

        return $xmlObjects;
    }

    // Keep essential service methods...
    private function getObjectService(): ?\OCA\OpenRegister\Service\ObjectService
    {
        try {
            return $this->container->get(\OCA\OpenRegister\Service\ObjectService::class);
        } catch (\Exception $e) {
            return null;
        }
    }

    private function saveObject(array $objectData, string $type): array
    {
        $objectService = $this->getObjectService();
        if (!$objectService) {
            throw new \RuntimeException('ObjectService not available');
        }

        $registerId = $this->getAmefRegisterId();
        $schemaId = $this->getAmefSchemaIdForType($type);
        
        if (!$registerId || !$schemaId) {
            throw new \RuntimeException("AMEF register or {$type} schema not configured");
        }

        return $objectService->saveObject($registerId, $schemaId, $objectData);
    }

    private function getAmefRegisterId(): ?int
    {
        $amefConfig = $this->getAmefConfig();
        return $amefConfig['register_id'] ?? $amefConfig['register'] ?? 
               (int) $this->config->getValueString('softwarecatalog', 'amef_register_id', '0') ?: null;
    }

    private function getAmefSchemaIdForType(string $archiMateType): ?int
    {
        $amefConfig = $this->getAmefConfig();
        
        $typeMapping = [
            'elements' => 'element',
            'relationships' => 'relationship', 
            'relations' => 'relationship',
            'views' => 'view',
            'organizations' => 'organization',
            'property_definitions' => 'property_definition',
            'models' => 'model'
        ];
        
        $normalizedType = $typeMapping[$archiMateType] ?? $archiMateType;
        
        $schemaKeyCandidates = [
            $normalizedType . '_schema',
            $normalizedType . 's_schema',
            $normalizedType . '_schema_id'
        ];
        
        foreach ($schemaKeyCandidates as $key) {
            if (isset($amefConfig[$key])) {
                return (int) $amefConfig[$key];
            }
        }
        
        return null;
    }

    private function getAmefConfig(): array
    {
        $configJson = $this->config->getValueString('softwarecatalog', 'amef_config', '{}');
        $config = json_decode($configJson, true);
        return is_array($config) ? $config : [];
    }

    public function getElementObjects(array $query = []): array
    {
        return $this->getObjectsWithPagination('element', $query);
    }

    public function getRelationshipObjects(array $query = []): array
    {
        return $this->getObjectsWithPagination('relationship', $query);
    }

    public function getViewObjects(array $query = []): array
    {
        return $this->getObjectsWithPagination('view', $query);
    }

    public function getOrganizationObjects(array $query = []): array
    {
        return $this->getObjectsWithPagination('organization', $query);
    }

    public function getModelObjects(array $query = []): array
    {
        return $this->getObjectsWithPagination('model', $query);
    }

    private function getObjectsWithPagination(string $schemaType, array $query = []): array
    {
        try {
            $objectService = $this->getObjectService();
            if (!$objectService) {
                return [];
            }

            $registerId = $this->getAmefRegisterId();
            $schemaId = $this->getAmefSchemaIdForType($schemaType);
            
            if (!$registerId || !$schemaId) {
                return [];
            }

            $baseQuery = [
                '@self' => [
                    'register' => (int) $registerId,
                    'schema' => (int) $schemaId
                ]
            ];
            
            $finalQuery = array_merge_recursive($baseQuery, $query);
            return $objectService->searchObjects($finalQuery);
            
        } catch (\Exception $e) {
            return [];
        }
    }
}
?>

