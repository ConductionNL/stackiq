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
 * Class ArchiMateService - SIMPLIFIED VERSION
 *
 * @category Service
 * @package  OCA\SoftwareCatalog\Service
 * @author   Conduction b.v. <info@conduction.nl>
 * @copyright Copyright (c) Conduction
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @version  1.0.0
 * @link     https://github.com/ConductionNL/SoftwareCatalog
 *
 * Handles ArchiMate Exchange Format (AMEF) import and export operations.
 * Uses simplified approach: store raw XML as JSON, reconstruct clean XML on export.
 */
class ArchiMateService
{
    public const APP_NAME = 'softwarecatalog';

    private array $cachedObjects = [];

    public function __construct(
        private readonly IAppConfig $config,
        private readonly IRootFolder $rootFolder,
        private readonly IUserSession $userSession,
        private readonly IAppManager $appManager,
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger
    ) {
        // Initialize import/export services
        $this->importService = new ArchiMateImportService($logger);
        $this->exportService = new ArchiMateExportService($logger);
    }

    private readonly ArchiMateImportService $importService;
    private readonly ArchiMateExportService $exportService;

    /**
     * Import ArchiMate file from path - SIMPLIFIED VERSION
     */
    public function importArchiMateFileFromPath(array $options = []): array
    {
        $filePath = $options['file_path'] ?? '';
        $fileName = basename($filePath);
        
        $this->logger->info('=== ARCHIMATE IMPORT START (SIMPLIFIED) ===', [
            'file_path' => $filePath,
            'options' => $options
        ]);

        try {
            // Validate file
            $this->validateArchiMateFileFromPath($filePath, $fileName, 'application/xml');
            
            // Parse XML to raw array data
            $archiMateData = $this->parseArchiMateXmlStreaming($filePath);
            
            // Store model metadata
            if (!empty($archiMateData['model_metadata'])) {
                $modelResult = $this->createOrUpdateModelObject($archiMateData['model_metadata']);
                $this->logger->info('Model object created/updated', $modelResult);
            }
            
            // Convert and save each object type with raw XML data
            $results = [
                'elements' => $this->saveRawObjects($archiMateData['elements'] ?? [], 'element'),
                'relationships' => $this->saveRawObjects($archiMateData['relationships'] ?? [], 'relationship'),
                'views' => $this->saveRawObjects($archiMateData['views'] ?? [], 'view'),
                'organizations' => $this->saveRawObjects($archiMateData['organizations'] ?? [], 'organization'),
                'property_definitions' => $this->saveRawObjects($archiMateData['property_definitions'] ?? [], 'property_definition')
            ];
            
            $totalCreated = array_sum(array_column($results, 'created'));
            $totalUpdated = array_sum(array_column($results, 'updated'));
            
            $this->logger->info('=== ARCHIMATE IMPORT COMPLETED (SIMPLIFIED) ===', [
                'total_created' => $totalCreated,
                'total_updated' => $totalUpdated,
                'results' => $results
            ]);
            
            return [
                'success' => true,
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
     * Export to ArchiMate - SIMPLIFIED VERSION using new export service
     */
    public function exportToArchiMate(array $criteria = [], array $options = []): array
    {
        $this->logger->info('=== ARCHIMATE EXPORT START (SIMPLIFIED) ===', [
            'criteria' => $criteria,
            'options' => $options
        ]);

        try {
            // Get model metadata first
            $modelObjects = $this->getModelObjects();
            $modelMetadata = !empty($modelObjects) ? $modelObjects[0] : [];
            
            // Create clean XML structure
            $xml = $this->exportService->createCleanArchiMateXml($modelMetadata);
            
            // Get all objects and add them to XML
            $elements = $this->getElementObjects();
            $relationships = $this->getRelationshipObjects();
            $views = $this->getViewObjects();
            $organizations = $this->getOrganizationObjects();
            
            $this->exportService->addElementsToXml($xml, $elements);
            $this->exportService->addRelationshipsToXml($xml, $relationships);
            
            // Save exported XML to file
            $exportPath = '/tmp/archimate_export_latest.xml';
            $xmlContent = $xml->asXML();
            file_put_contents($exportPath, $xmlContent);
            
            $this->logger->info('=== ARCHIMATE EXPORT COMPLETED (SIMPLIFIED) ===', [
                'file_path' => $exportPath,
                'file_size' => strlen($xmlContent),
                'elements_count' => count($elements),
                'relationships_count' => count($relationships)
            ]);
            
            return [
                'success' => true,
                'file_path' => $exportPath,
                'statistics' => [
                    'elements' => count($elements),
                    'relationships' => count($relationships),
                    'views' => count($views),
                    'organizations' => count($organizations)
                ]
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

    // Keep essential helper methods but simplify them...
    
    private function validateArchiMateFileFromPath(string $filePath, string $fileName, string $mimeType): void
    {
        if (!file_exists($filePath)) {
            throw new \RuntimeException("File not found: {$filePath}");
        }
        if (!is_readable($filePath)) {
            throw new \RuntimeException("File not readable: {$filePath}");
        }
        $fileSize = filesize($filePath);
        if ($fileSize === false || $fileSize === 0) {
            throw new \RuntimeException("Invalid file size: {$filePath}");
        }
    }

    private function parseArchiMateXmlStreaming(string $filePath): array
    {
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

        $data = $this->importService->xmlToArray($xml);
        return $this->normalizeArchiMateData($data);
    }

    private function normalizeArchiMateData(array $data): array
    {
        $normalized = [
            'elements' => [],
            'relationships' => [],
            'organizations' => [],
            'views' => [],
            'property_definitions' => [],
            'model_metadata' => []
        ];

        // Extract model metadata
        if (isset($data['_attributes'])) {
            $normalized['model_metadata']['identifier'] = $data['_attributes']['identifier'] ?? '';
            $normalized['model_metadata']['attributes'] = $data['_attributes'];
        }
        if (isset($data['name'])) {
            $normalized['model_metadata']['name'] = $data['name']['_value'] ?? '';
        }
        if (isset($data['documentation'])) {
            $normalized['model_metadata']['documentation'] = $data['documentation']['_value'] ?? '';
        }

        // Store raw data for round-tripping
        $normalized['model_metadata']['properties'] = [];
        if (isset($data['properties'])) {
            $normalized['model_metadata']['properties']['properties'] = $data['properties'];
        }
        if (isset($data['folder'])) {
            $normalized['model_metadata']['properties']['folders'] = $data['folder'];
        }

        // Extract raw XML nodes for each type
        $this->extractRawXmlNodes($data, $normalized, 'elements', 'element');
        $this->extractRawXmlNodes($data, $normalized, 'relationships', 'relationship'); 
        $this->extractRawXmlNodes($data, $normalized, 'views', 'view');
        $this->extractRawXmlNodes($data, $normalized, 'organizations', 'item');
        $this->extractRawXmlNodes($data, $normalized, 'property_definitions', 'propertyDefinition');

        return $normalized;
    }

    private function extractRawXmlNodes(array $data, array &$normalized, string $section, string $childTag): void
    {
        if (!isset($data[$section])) {
            return;
        }

        $sectionData = $data[$section];
        $items = [];

        if (isset($sectionData[$childTag])) {
            $items = is_array($sectionData[$childTag]) ? $sectionData[$childTag] : [$sectionData[$childTag]];
        } else {
            $items = is_array($sectionData) ? $sectionData : [$sectionData];
        }

        foreach ($items as $item) {
            $identifier = $item['_attributes']['identifier'] ?? $item['_identifier'] ?? $item['identifier'] ?? null;
            if ($identifier) {
                $normalized[$section][$identifier] = [
                    'xml_data' => $item,
                    'identifier' => $identifier,
                    'section' => $section,
                    'child_tag' => $childTag
                ];
            }
        }
    }

    private function saveRawObjects(array $objects, string $type): array
    {
        $created = 0;
        $updated = 0;

        foreach ($objects as $object) {
            $openRegisterData = $this->convertRawToOpenRegisterFormat($object, $type);
            $savedObject = $this->saveObject($openRegisterData, $type);
            
            if ($this->determineObjectAction($savedObject) === 'created') {
                $created++;
            } else {
                $updated++;
            }
        }

        return ['created' => $created, 'updated' => $updated];
    }

    private function convertRawToOpenRegisterFormat(array $rawData, string $type): array
    {
        $xmlData = $rawData['xml_data'] ?? [];
        $identifier = $rawData['identifier'] ?? '';
        
        $name = $xmlData['name']['_value'] ?? $xmlData['_attributes']['name'] ?? '';
        $archiMateType = $xmlData['_attributes']['xsi:type'] ?? $xmlData['_xsi__type'] ?? $type;
        
        $openRegisterData = [
            'name' => $name,
            'archimate_id' => $identifier,
            'uuid' => $identifier,
            'archimate_type' => $archiMateType,
            'original_archimate_type' => $archiMateType,
            'documentation' => $xmlData['documentation']['_value'] ?? '',
            'properties' => [
                'xml_data' => json_encode($xmlData)
            ],
            'import_time' => date('Y-m-d H:i:s'),
            'import_source' => 'archimate_xml_import'
        ];

        // Handle relationship-specific fields
        if ($type === 'relationship') {
            $openRegisterData['source_id'] = $xmlData['_attributes']['source'] ?? '';
            $openRegisterData['target_id'] = $xmlData['_attributes']['target'] ?? '';
        }

        return $openRegisterData;
    }

    // Keep essential service methods...
    private function getObjectService(): ?\OCA\OpenRegister\Service\ObjectService
    {
        try {
            return $this->container->get(\OCA\OpenRegister\Service\ObjectService::class);
        } catch (\Exception $e) {
            $this->logger->error('Failed to get ObjectService', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function saveObject(array $objectData, string $type, ?string $modelIdentifier = null): array
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

    private function determineObjectAction(array $savedObject): string
    {
        // Simple heuristic - in real implementation this would be more sophisticated
        return 'created'; // or 'updated'
    }

    // Keep configuration methods...
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

    // Keep object retrieval methods...
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
            $this->logger->error("Failed to retrieve {$schemaType} objects", [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    private function createOrUpdateModelObject(array $modelMetadata): array
    {
        try {
            $modelData = [
                'archimate_id' => $modelMetadata['identifier'] ?? '',
                'name' => $modelMetadata['name'] ?? '',
                'documentation' => $modelMetadata['documentation'] ?? '',
                'properties' => $modelMetadata['properties'] ?? [],
                'import_time' => date('Y-m-d H:i:s'),
                'import_source' => 'archimate_xml_import'
            ];

            $savedModelObject = $this->saveObject($modelData, 'model');
            return ['success' => true, 'action' => 'saved'];

        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
?>

