<?php

declare(strict_types=1);

/**
 * ArchiMate Service
 *
 * This service handles ArchiMate file import and export functionality for the Software Catalog.
 * It processes ArchiMate files and converts them to/from OpenRegister objects.
 *
 * @category Service
 * @package  OCA\SoftwareCatalog\Service
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link     https://github.com/ConductionNL/SoftwareCatalog
 * @version  1.0.0
 */

namespace OCA\SoftwareCatalog\Service;

use OCP\IAppConfig;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use React\EventLoop\Loop;
use React\Promise\Promise;
use React\Promise\Deferred;

/**
 * Service class for handling ArchiMate file operations
 *
 * This service provides functionality to import ArchiMate files and convert them
 * to OpenRegister objects, as well as export OpenRegister objects to ArchiMate format.
 * 
 * @category Service
 * @package  OCA\SoftwareCatalog\Service
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link     https://github.com/ConductionNL/SoftwareCatalog
 * @version  1.0.0
 */
class ArchiMateService
{
    /**
     * Constructor for ArchiMateService
     *
     * @param IAppConfig        $appConfig    The app configuration service
     * @param IRootFolder       $rootFolder   The root folder service
     * @param IUserSession      $userSession  The user session service
     * @param LoggerInterface   $logger       The logger interface
     */
    public function __construct(
        private readonly IAppConfig $appConfig,
        private readonly IRootFolder $rootFolder,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Cached objects indexed by type and ID for efficient lookups during import
     * Structure: ['element' => ['id1' => object1, 'id2' => object2], 'organization' => [...], ...]
     * 
     * @var array<string, array<string, array>>
     */
    private array $cachedObjects = [];

    /**
     * Import an ArchiMate file and convert it to OpenRegister objects
     *
     * This method processes an uploaded ArchiMate file, extracts the architectural
     * elements, and creates corresponding objects in the OpenRegister system.
     *
     * @param File $archiMateFile The uploaded ArchiMate file
     * @param array $options Additional options for the import process
     * 
     * @return array Import results containing success status, message, and statistics
     * 
     * @throws \InvalidArgumentException If the file format is not supported
     * @throws \RuntimeException If the import process fails
     */
    public function importArchiMateFile(File $archiMateFile, array $options = []): array
    {
        $progressTracker = $options['progressTracker'] ?? null;
        
        $this->logger->info('Starting ArchiMate file import', [
            'filename' => $archiMateFile->getName(),
            'size' => $archiMateFile->getSize(),
            'mimetype' => $archiMateFile->getMimeType()
        ]);

        try {
            // Phase 1: Validating file format
            if ($progressTracker) {
                $progressTracker->setPhase('validating');
            }
            $this->validateArchiMateFile($archiMateFile);

            // Phase 2: Parsing the ArchiMate file
            if ($progressTracker) {
                $progressTracker->setPhase('parsing');
            }
            $archiMateData = $this->parseArchiMateFile($archiMateFile, $options);

            // Phase 3: Analyzing structure and setting totals
            if ($progressTracker) {
                $totalItems = $this->countArchiMateItems($archiMateData);
                $progressTracker->setPhase('analyzing', ['total_items' => $totalItems]);
                $progressTracker->updateStatistics([
                    'elements_count' => count($archiMateData['elements'] ?? []),
                    'relationships_count' => count($archiMateData['relationships'] ?? []),
                    'organizations_count' => count($archiMateData['organizations'] ?? []),  
                    'views_count' => count($archiMateData['views'] ?? [])
                ]);
            }

            // Phase 4: Caching existing objects for efficient lookups
            if ($progressTracker) {
                $progressTracker->setPhase('caching');
            }
            $this->preloadExistingObjects();

            // Phase 5: Converting to OpenRegister objects
            if ($progressTracker) {
                $progressTracker->setPhase('processing_elements');
            }
            $importResults = $this->convertToOpenRegisterObjects($archiMateData, $options);

            // Phase 6: Finalizing
            if ($progressTracker) {
                $progressTracker->setPhase('finalizing');
                $progressTracker->updateStatistics($importResults);
            }

            $this->logger->info('ArchiMate file import completed successfully', [
                'filename' => $archiMateFile->getName(),
                'objects_created' => $importResults['objects_created'] ?? 0,
                'objects_updated' => $importResults['objects_updated'] ?? 0
            ]);

            return [
                'success' => true,
                'message' => 'ArchiMate file imported successfully',
                'filename' => $archiMateFile->getName(),
                'statistics' => $importResults
            ];

        } catch (\Exception $e) {
            if ($progressTracker) {
                $progressTracker->addError('Import failed: ' . $e->getMessage(), [
                    'trace' => $e->getTraceAsString()
                ]);
            }
            
            $this->logger->error('ArchiMate file import failed', [
                'filename' => $archiMateFile->getName(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'message' => 'Failed to import ArchiMate file: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Count total ArchiMate items for progress tracking
     *
     * @param array $archiMateData Parsed ArchiMate data
     * 
     * @return int Total number of items to process
     */
    private function countArchiMateItems(array $archiMateData): int
    {
        $totalItems = 0;
        $totalItems += count($archiMateData['elements'] ?? []);
        $totalItems += count($archiMateData['relationships'] ?? []);
        $totalItems += count($archiMateData['organizations'] ?? []);
        $totalItems += count($archiMateData['views'] ?? []);
        
        return $totalItems;
    }

    /**
     * Export OpenRegister objects to ArchiMate format
     *
     * This method queries OpenRegister objects based on provided criteria and
     * converts them to ArchiMate format for download.
     *
     * @param array $criteria Criteria for selecting objects to export
     * @param array $options Export options including format and organization-specific data
     * 
     * @return array Export results containing success status, file path, and statistics
     * 
     * @throws \RuntimeException If the export process fails
     */
    public function exportToArchiMate(array $criteria = [], array $options = []): array
    {
        $progressTracker = $options['progressTracker'] ?? null;
        
        $this->logger->info('Starting ArchiMate export', [
            'criteria' => $criteria,
            'options' => $options
        ]);

        try {
            // Phase 1: Analyzing export requirements
            if ($progressTracker) {
                $progressTracker->setPhase('analyzing');
            }

            // Phase 2: Getting objects from OpenRegister based on criteria
            if ($progressTracker) {
                $progressTracker->setPhase('caching');
            }
            $objects = $this->getObjectsForExport($criteria);

            // Phase 3: Converting to ArchiMate format
            if ($progressTracker) {
                $totalItems = count($objects);
                $progressTracker->setPhase('processing_elements', ['total_items' => $totalItems]);
                $progressTracker->updateStatistics([
                    'objects_to_export' => $totalItems
                ]);
            }
            $archiMateData = $this->convertFromOpenRegisterObjects($objects, $options);

            // Phase 4: Generating export file
            if ($progressTracker) {
                $progressTracker->setPhase('finalizing');
            }
            $exportFile = $this->generateArchiMateFile($archiMateData, $options);

            // Phase 5: Completed
            if ($progressTracker) {
                $progressTracker->updateStatistics([
                    'objects_exported' => count($objects),
                    'file_size' => $exportFile['size']
                ]);
            }

            $this->logger->info('ArchiMate export completed successfully', [
                'objects_exported' => count($objects),
                'file_path' => $exportFile['path']
            ]);

            return [
                'success' => true,
                'message' => 'ArchiMate export completed successfully',
                'file_path' => $exportFile['path'],
                'file_name' => $exportFile['name'],
                'statistics' => [
                    'objects_exported' => count($objects),
                    'file_size' => $exportFile['size']
                ]
            ];

        } catch (\Exception $e) {
            if ($progressTracker) {
                $progressTracker->addError('Export failed: ' . $e->getMessage(), [
                    'trace' => $e->getTraceAsString()
                ]);
            }
            
            $this->logger->error('ArchiMate export failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'message' => 'Failed to export to ArchiMate: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Validate that the uploaded file is a valid ArchiMate file
     *
     * @param File $file The file to validate
     * 
     * @return void
     * 
     * @throws \InvalidArgumentException If the file is not valid
     */
    private function validateArchiMateFile(File $file): void
    {
        $allowedExtensions = ['archimate', 'xml'];
        $allowedMimeTypes = ['application/xml', 'text/xml', 'application/octet-stream'];

        $extension = strtolower(pathinfo($file->getName(), PATHINFO_EXTENSION));
        $mimeType = $file->getMimeType();

        if (!in_array($extension, $allowedExtensions) && !in_array($mimeType, $allowedMimeTypes)) {
            throw new \InvalidArgumentException(
                'Invalid file format. Expected ArchiMate (.archimate) or XML (.xml) file.'
            );
        }

        // Additional validation can be added here to check file structure
    }

    /**
     * Parse an ArchiMate file using streaming XML parser for large files
     *
     * This method automatically chooses between streaming (XMLReader) and memory-based (SimpleXML)
     * parsing based on file size to optimize memory usage for large files.
     *
     * @param File $file The ArchiMate file to parse
     * @param array $options Additional options including progress tracker
     * 
     * @return array Parsed data
     * 
     * @throws \RuntimeException If parsing fails
     */
    private function parseArchiMateFile(File $file, array $options = []): array
    {
        $progressTracker = $options['progressTracker'] ?? null;
        $fileSize = $file->getSize();
        
        // Use streaming parser for files larger than 5MB to prevent memory issues
        $useStreaming = $fileSize > 5 * 1024 * 1024; // 5MB threshold
        
        $this->logger->info('Parsing ArchiMate file', [
            'filename' => $file->getName(),
            'size' => $fileSize,
            'useStreaming' => $useStreaming
        ]);
        
        if ($useStreaming) {
            return $this->parseArchiMateFileStreaming($file, $options);
        } else {
            return $this->parseArchiMateFileMemory($file, $options);
        }
    }

    /**
     * Parse ArchiMate file using streaming XMLReader (for large files)
     *
     * This method processes XML files in a memory-efficient way by reading
     * elements one at a time instead of loading the entire file into memory.
     *
     * @param File $file The ArchiMate file to parse
     * @param array $options Additional options including progress tracker
     * 
     * @return array Parsed data
     * 
     * @throws \RuntimeException If parsing fails
     */
    private function parseArchiMateFileStreaming(File $file, array $options = []): array
    {
        $progressTracker = $options['progressTracker'] ?? null;
        $filePath = $file->getStorage()->getLocalFile($file->getPath());
        
        if (!file_exists($filePath)) {
            throw new \RuntimeException('Cannot access file for streaming parsing');
        }
        
        $reader = new \XMLReader();
        if (!$reader->open($filePath)) {
            throw new \RuntimeException('Failed to open XML file for streaming parsing');
        }
        
        $this->logger->info('Starting streaming XML parsing', [
            'filename' => $file->getName(),
            'filepath' => $filePath
        ]);
        
        $result = [
            'elements' => [],
            'relationships' => [],
            'views' => [],
            'organizations' => []
        ];
        
        $elementCount = 0;
        $relationshipCount = 0;
        $viewCount = 0;
        
        try {
            // Read through the entire XML document
            while ($reader->read()) {
                if ($reader->nodeType === \XMLReader::ELEMENT) {
                    $elementName = $reader->name;
                    
                    // Process specific ArchiMate elements
                    if ($elementName === 'element') {
                        $elementData = $this->extractElementAttributes($reader);
                        $elementData = $this->processStreamingElementContent($reader, $elementData);
                        $result['elements'][] = $elementData;
                        $elementCount++;
                        
                        if ($progressTracker && $elementCount % 100 === 0) {
                            $progressTracker->updateProgress($elementCount, 'Processing elements');
                        }
                        
                    } elseif ($elementName === 'relationship') {
                        $elementData = $this->extractElementAttributes($reader);
                        $elementData = $this->processStreamingElementContent($reader, $elementData);
                        $result['relationships'][] = $elementData;
                        $relationshipCount++;
                        
                        if ($progressTracker && $relationshipCount % 50 === 0) {
                            $progressTracker->updateProgress($relationshipCount, 'Processing relationships');
                        }
                        
                    } elseif ($elementName === 'view') {
                        $elementData = $this->extractElementAttributes($reader);
                        $elementData = $this->processStreamingElementContent($reader, $elementData);
                        $result['views'][] = $elementData;
                        $viewCount++;
                        
                        if ($progressTracker && $viewCount % 10 === 0) {
                            $progressTracker->updateProgress($viewCount, 'Processing views');
                        }
                    }
                }
            }
            
        } finally {
            $reader->close();
        }
        
        $this->logger->info('Completed streaming XML parsing', [
            'elements' => $elementCount,
            'relationships' => $relationshipCount,
            'views' => $viewCount
        ]);
        
        return $this->normalizeArchiMateData($result);
    }

    /**
     * Parse ArchiMate file using memory-based SimpleXML (for smaller files)
     *
     * This method loads the entire file into memory and uses SimpleXML for parsing.
     * It's efficient for smaller files but should not be used for large files.
     *
     * @param File $file The ArchiMate file to parse
     * @param array $options Additional options including progress tracker
     * 
     * @return array Parsed data
     * 
     * @throws \RuntimeException If parsing fails
     */
    private function parseArchiMateFileMemory(File $file, array $options = []): array
    {
        $content = $file->getContent();
        
        // Handle different file formats
        if ($this->isJsonFormat($content)) {
            return $this->parseJsonArchiMate($content);
        } else {
            return $this->parseXmlArchiMate($content);
        }
    }



    /**
     * Extract attributes from the current XML element
     *
     * @param \XMLReader $reader The XMLReader instance
     * 
     * @return array Extracted attributes
     */
    private function extractElementAttributes(\XMLReader $reader): array
    {
        $attributes = [];
        
        if ($reader->hasAttributes) {
            while ($reader->moveToNextAttribute()) {
                $attributes[$reader->name] = $reader->value;
            }
            // Move back to element
            $reader->moveToElement();
        }
        
        return ['_attributes' => $attributes];
    }

    /**
     * Process the content of an XML element (text and child elements)
     *
     * @param \XMLReader $reader The XMLReader instance
     * @param array $elementData Current element data
     * 
     * @return array Processed element data
     */
    private function processStreamingElementContent(\XMLReader $reader, array $elementData): array
    {
        $hasChildren = false;
        $textContent = '';
        
        // Check if element has content
        if (!$reader->isEmptyElement) {
            // Read child elements and text
            while ($reader->read()) {
                if ($reader->nodeType === \XMLReader::END_ELEMENT) {
                    break;
                } elseif ($reader->nodeType === \XMLReader::TEXT || $reader->nodeType === \XMLReader::CDATA) {
                    $textContent .= $reader->value;
                } elseif ($reader->nodeType === \XMLReader::ELEMENT) {
                    $hasChildren = true;
                    $childName = $reader->name;
                    $childData = $this->extractElementAttributes($reader);
                    
                    if (!$reader->isEmptyElement) {
                        $childData = $this->processStreamingElementContent($reader, $childData);
                    }
                    
                    // Handle multiple children with same name
                    if (isset($elementData[$childName])) {
                        if (!is_array($elementData[$childName]) || !isset($elementData[$childName][0])) {
                            $elementData[$childName] = [$elementData[$childName]];
                        }
                        $elementData[$childName][] = $childData;
                    } else {
                        $elementData[$childName] = $childData;
                    }
                }
            }
        }
        
        // Add text content if present
        if (!empty($textContent) && !$hasChildren) {
            $elementData['_value'] = trim($textContent);
        } elseif (!empty($textContent) && $hasChildren) {
            $elementData['_text'] = trim($textContent);
        }
        
        return $elementData;
    }

    /**
     * Process child elements recursively
     *
     * @param \XMLReader $reader The XMLReader instance
     * @param array &$elementData Reference to element data to populate
     * 
     * @return void
     */
    private function processStreamingChildren(\XMLReader $reader, array &$elementData): void
    {
        if (!$reader->isEmptyElement) {
            while ($reader->read()) {
                if ($reader->nodeType === \XMLReader::END_ELEMENT) {
                    break;
                } elseif ($reader->nodeType === \XMLReader::ELEMENT) {
                    $childName = $reader->name;
                    $childData = $this->extractElementAttributes($reader);
                    
                    if (!$reader->isEmptyElement) {
                        $childData = $this->processStreamingElementContent($reader, $childData);
                    }
                    
                    // Handle multiple children with same name
                    if (isset($elementData[$childName])) {
                        if (!is_array($elementData[$childName]) || !isset($elementData[$childName][0])) {
                            $elementData[$childName] = [$elementData[$childName]];
                        }
                        $elementData[$childName][] = $childData;
                    } else {
                        $elementData[$childName] = $childData;
                    }
                }
            }
        }
    }

    /**
     * Check if content is in JSON format
     *
     * @param string $content File content
     * 
     * @return bool True if JSON format
     */
    private function isJsonFormat(string $content): bool
    {
        json_decode($content);
        return json_last_error() === JSON_ERROR_NONE;
    }

    /**
     * Parse JSON format ArchiMate file
     *
     * @param string $content JSON content
     * 
     * @return array Parsed data
     */
    private function parseJsonArchiMate(string $content): array
    {
        $data = json_decode($content, true);
        
        if ($data === null) {
            throw new \RuntimeException('Invalid JSON format in ArchiMate file');
        }

        return $this->normalizeArchiMateData($data);
    }

    /**
     * Parse XML format ArchiMate file
     *
     * @param string $content XML content
     * 
     * @return array Parsed data
     */
    private function parseXmlArchiMate(string $content): array
    {
        // Load XML with proper error handling
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($content);
        
        if ($xml === false) {
            $errors = libxml_get_errors();
            $errorMessages = array_map(fn($error) => trim($error->message), $errors);
            throw new \RuntimeException('Invalid XML format in ArchiMate file: ' . implode(', ', $errorMessages));
        }

        // Use our comprehensive XML parser that preserves attributes and properties
        $data = $this->parseXmlElementWithProperties($xml);
        
        return $this->normalizeArchiMateData($data);
    }

    /**
     * Parse XML element preserving both attributes and child elements
     * This is critical for ArchiMate files where attributes contain essential data
     *
     * @param \SimpleXMLElement $xml XML element to parse
     * 
     * @return array Parsed data with attributes and properties preserved
     */
    private function parseXmlElementWithProperties(\SimpleXMLElement $xml): array
    {
        $result = [];
        
        // Extract attributes first (crucial for ArchiMate identifiers, types, etc.)
        $attributes = [];
        foreach ($xml->attributes() as $name => $value) {
            $attributes[$name] = (string)$value;
        }
        
        // Handle all namespaced attributes
        $namespaces = $xml->getNamespaces(true);
        foreach ($namespaces as $prefix => $namespace) {
            if ($prefix) { // Skip default namespace
                foreach ($xml->attributes($prefix, true) as $name => $value) {
                    $attributes["$prefix:$name"] = (string)$value;
                }
            }
        }
        
        // Explicitly handle xml namespace (common in ArchiMate for xml:lang)
        foreach ($xml->attributes('xml', true) as $name => $value) {
            $attributes["xml:$name"] = (string)$value;
        }
        
        // Get text content
        $textContent = trim((string)$xml);
        
        // Process child elements
        $children = [];
        $hasChildElements = false;
        
        foreach ($xml->children() as $name => $child) {
            $hasChildElements = true;
            $childData = $this->parseXmlElementWithProperties($child);
            
            // Handle multiple children with same name (like multiple <property> elements)
            if (isset($children[$name])) {
                if (!is_array($children[$name]) || !isset($children[$name][0])) {
                    $children[$name] = [$children[$name]];
                }
                $children[$name][] = $childData;
            } else {
                $children[$name] = $childData;
            }
        }
        
        // Build result based on what we found
        if (!empty($attributes)) {
            $result['_attributes'] = $attributes;
        }
        
        if (!empty($textContent) && !$hasChildElements) {
            $result['_value'] = $textContent;
        }
        
        if (!empty($children)) {
            $result = array_merge($result, $children);
        }
        
        // Special handling for elements that have both text and attributes
        if (!empty($textContent) && $hasChildElements) {
            $result['_text'] = $textContent;
        }
        
        return $result;
    }

    /**
     * Normalize ArchiMate data to a consistent format
     * Now properly handles attributes and properties from XML parsing
     *
     * @param array $data Raw parsed data with attributes
     * 
     * @return array Normalized data
     */
    private function normalizeArchiMateData(array $data): array
    {
        // Extract model metadata
        $modelInfo = [
            'identifier' => $data['_attributes']['identifier'] ?? null,
            'name' => $this->extractTextValue($data['name'] ?? null),
            'documentation' => $this->extractTextValue($data['documentation'] ?? null),
            'properties' => $this->extractProperties($data['properties'] ?? [])
        ];

        // Extract and normalize elements
        $elements = [];
        if (isset($data['elements']['element'])) {
            $elementsData = $data['elements']['element'];
            // Handle single element vs array of elements
            if (isset($elementsData['_attributes'])) {
                $elementsData = [$elementsData];
            }
            
            foreach ($elementsData as $element) {
                if (is_array($element)) {
                    $elements[] = $this->normalizeArchiMateElement($element);
                }
            }
        }

        // Extract and normalize relationships
        $relationships = [];
        if (isset($data['relationships']['relationship'])) {
            $relationshipsData = $data['relationships']['relationship'];
            if (isset($relationshipsData['_attributes'])) {
                $relationshipsData = [$relationshipsData];
            }
            
            foreach ($relationshipsData as $relationship) {
                if (is_array($relationship)) {
                    $relationships[] = $this->normalizeArchiMateRelationship($relationship);
                }
            }
        }

        // Extract and normalize views
        $views = [];
        if (isset($data['views']['view'])) {
            $viewsData = $data['views']['view'];
            if (isset($viewsData['_attributes'])) {
                $viewsData = [$viewsData];
            }
            
            foreach ($viewsData as $view) {
                if (is_array($view)) {
                    $views[] = $this->normalizeArchiMateView($view);
                }
            }
        }

        return [
            'model' => $modelInfo,
            'elements' => $elements,
            'relationships' => $relationships,
            'organizations' => $this->extractOrganizations($elements), // Extract from elements based on type
            'views' => $views,
            'properties' => $modelInfo['properties']
        ];
    }

    /**
     * Extract text value handling both simple strings and attribute-value structures
     *
     * @param mixed $data Text data that may have attributes
     * 
     * @return string|null Extracted text value
     */
    private function extractTextValue($data): ?string
    {
        if (is_string($data)) {
            return $data;
        }
        
        if (is_array($data)) {
            if (isset($data['_value'])) {
                return $data['_value'];
            }
            if (isset($data['_text'])) {
                return $data['_text'];
            }
        }
        
        return null;
    }

    /**
     * Extract properties from property elements
     *
     * @param array $propertiesData Properties data from XML
     * 
     * @return array Normalized properties
     */
    private function extractProperties(array $propertiesData): array
    {
        $properties = [];
        
        if (isset($propertiesData['property'])) {
            $propertyData = $propertiesData['property'];
            
            // Handle single property vs array of properties
            if (isset($propertyData['_attributes'])) {
                $propertyData = [$propertyData];
            }
            
            foreach ($propertyData as $property) {
                if (is_array($property) && isset($property['_attributes']['propertyDefinitionRef'])) {
                    $properties[] = [
                        'definitionRef' => $property['_attributes']['propertyDefinitionRef'],
                        'value' => $this->extractTextValue($property['value'] ?? null),
                        'language' => $property['value']['_attributes']['xml:lang'] ?? 'en'
                    ];
                }
            }
        }
        
        return $properties;
    }

    /**
     * Normalize ArchiMate element with proper attribute extraction
     *
     * @param array $element Element data with attributes
     * 
     * @return array Normalized element
     */
    private function normalizeArchiMateElement(array $element): array
    {
        return [
            'id' => $element['_attributes']['identifier'] ?? null,
            'archiMateId' => $element['_attributes']['identifier'] ?? null, // Preserve for updates
            'type' => $element['_attributes']['xsi:type'] ?? 'Element',
            'name' => $this->extractTextValue($element['name'] ?? null),
            'documentation' => $this->extractTextValue($element['documentation'] ?? null),
            'properties' => $this->extractProperties($element['properties'] ?? []),
            '_originalAttributes' => $element['_attributes'] ?? []
        ];
    }

    /**
     * Normalize ArchiMate relationship with proper attribute extraction
     *
     * @param array $relationship Relationship data with attributes
     * 
     * @return array Normalized relationship
     */
    private function normalizeArchiMateRelationship(array $relationship): array
    {
        return [
            'id' => $relationship['_attributes']['identifier'] ?? null,
            'archiMateId' => $relationship['_attributes']['identifier'] ?? null,
            'type' => $relationship['_attributes']['xsi:type'] ?? 'Relationship',
            'source' => $relationship['_attributes']['source'] ?? null,
            'target' => $relationship['_attributes']['target'] ?? null,
            'name' => $this->extractTextValue($relationship['name'] ?? null),
            'properties' => $this->extractProperties($relationship['properties'] ?? []),
            '_originalAttributes' => $relationship['_attributes'] ?? []
        ];
    }

    /**
     * Normalize ArchiMate view with proper attribute extraction
     *
     * @param array $view View data with attributes
     * 
     * @return array Normalized view
     */
    private function normalizeArchiMateView(array $view): array
    {
        return [
            'id' => $view['_attributes']['identifier'] ?? null,
            'archiMateId' => $view['_attributes']['identifier'] ?? null,
            'type' => $view['_attributes']['xsi:type'] ?? 'View',
            'viewType' => $view['_attributes']['viewpoint'] ?? null,
            'name' => $this->extractTextValue($view['name'] ?? null),
            'properties' => $this->extractProperties($view['properties'] ?? []),
            'nodes' => $this->extractViewNodes($view['node'] ?? []),
            'connections' => $this->extractViewConnections($view['connection'] ?? []),
            '_originalAttributes' => $view['_attributes'] ?? []
        ];
    }

    /**
     * Extract organizations from elements based on type patterns
     *
     * @param array $elements All elements
     * 
     * @return array Organization elements
     */
    private function extractOrganizations(array $elements): array
    {
        $organizations = [];
        $orgTypes = ['BusinessActor', 'BusinessRole', 'Stakeholder', 'BusinessInterface'];
        
        foreach ($elements as $element) {
            if (in_array($element['type'] ?? '', $orgTypes)) {
                $organizations[] = [
                    'id' => $element['id'],
                    'archiMateId' => $element['archiMateId'],
                    'naam' => $element['name'],
                    'type' => $this->mapArchiMateTypeToOrganizationType($element['type']),
                    'beschrijvingKort' => $element['documentation'],
                    'properties' => $element['properties']
                ];
            }
        }
        
        return $organizations;
    }

    /**
     * Extract view nodes with attributes
     *
     * @param array $nodesData Nodes data
     * 
     * @return array Normalized view nodes
     */
    private function extractViewNodes(array $nodesData): array
    {
        if (empty($nodesData)) {
            return [];
        }
        
        // Handle single node vs array of nodes
        if (isset($nodesData['_attributes'])) {
            $nodesData = [$nodesData];
        }
        
        $nodes = [];
        foreach ($nodesData as $node) {
            if (is_array($node) && isset($node['_attributes'])) {
                $nodes[] = [
                    'id' => $node['_attributes']['identifier'] ?? null,
                    'elementRef' => $node['_attributes']['elementRef'] ?? null,
                    'x' => $node['_attributes']['x'] ?? null,
                    'y' => $node['_attributes']['y'] ?? null,
                    'width' => $node['_attributes']['w'] ?? null,
                    'height' => $node['_attributes']['h'] ?? null
                ];
            }
        }
        
        return $nodes;
    }

    /**
     * Extract view connections with attributes
     *
     * @param array $connectionsData Connections data
     * 
     * @return array Normalized view connections
     */
    private function extractViewConnections(array $connectionsData): array
    {
        if (empty($connectionsData)) {
            return [];
        }
        
        // Handle single connection vs array of connections
        if (isset($connectionsData['_attributes'])) {
            $connectionsData = [$connectionsData];
        }
        
        $connections = [];
        foreach ($connectionsData as $connection) {
            if (is_array($connection) && isset($connection['_attributes'])) {
                $connections[] = [
                    'id' => $connection['_attributes']['identifier'] ?? null,
                    'relationshipRef' => $connection['_attributes']['relationshipRef'] ?? null,
                    'source' => $connection['_attributes']['source'] ?? null,
                    'target' => $connection['_attributes']['target'] ?? null
                ];
            }
        }
        
        return $connections;
    }

    /**
     * Map ArchiMate element type to organization type
     *
     * @param string $archiMateType ArchiMate element type
     * 
     * @return string Organization type
     */
    private function mapArchiMateTypeToOrganizationType(string $archiMateType): string
    {
        $mapping = [
            'BusinessActor' => 'Organisatie',
            'BusinessRole' => 'Rol',
            'Stakeholder' => 'Stakeholder',
            'BusinessInterface' => 'Interface'
        ];
        
        return $mapping[$archiMateType] ?? 'Organisatie';
    }

    /**
     * Convert ArchiMate data to OpenRegister objects
     *
     * This method handles large AMEF files by processing different types of elements
     * asynchronously using ReactPHP to prevent timeouts and improve performance.
     *
     * @param array $archiMateData Normalized ArchiMate data
     * @param array $options Conversion options
     * 
     * @return array Conversion results
     */
    private function convertToOpenRegisterObjects(array $archiMateData, array $options): array
    {
        $results = [
            'objects_created' => 0,
            'objects_updated' => 0,
            'errors' => [],
            'processing_stats' => []
        ];

        // Preload all existing objects to avoid individual DB queries during processing
        $this->preloadExistingObjects();

        // Determine if we should use async processing based on data size
        $totalElements = count($archiMateData['elements'] ?? []);
        $totalOrganizations = count($archiMateData['organizations'] ?? []);
        $totalRelationships = count($archiMateData['relationships'] ?? []);
        $totalViews = count($archiMateData['views'] ?? []);
        
        $totalItems = $totalElements + $totalOrganizations + $totalRelationships + $totalViews;
        $useAsyncProcessing = $totalItems > 100; // Use async for large files

        $this->logger->info('Processing ArchiMate data', [
            'total_elements' => $totalElements,
            'total_organizations' => $totalOrganizations,
            'total_relationships' => $totalRelationships,
            'total_views' => $totalViews,
            'total_items' => $totalItems,
            'use_async' => $useAsyncProcessing,
            'cached_objects_loaded' => array_sum(array_map('count', $this->cachedObjects))
        ]);

        if ($useAsyncProcessing) {
            return $this->convertToOpenRegisterObjectsAsync($archiMateData, $options);
        } else {
            return $this->convertToOpenRegisterObjectsSync($archiMateData, $options);
        }
    }

    /**
     * Synchronous processing for smaller files
     *
     * @param array $archiMateData Normalized ArchiMate data
     * @param array $options Conversion options
     * 
     * @return array Conversion results
     */
    private function convertToOpenRegisterObjectsSync(array $archiMateData, array $options): array
    {
        $results = [
            'objects_created' => 0,
            'objects_updated' => 0,
            'errors' => [],
            'processing_stats' => ['method' => 'synchronous']
        ];

        // Process different types of ArchiMate elements sequentially
        if (!empty($archiMateData['elements'])) {
            $elementResults = $this->processArchiMateElements($archiMateData['elements'], $options);
            $results['objects_created'] += $elementResults['created'];
            $results['objects_updated'] += $elementResults['updated'];
            $results['errors'] = array_merge($results['errors'], $elementResults['errors']);
        }

        if (!empty($archiMateData['organizations'])) {
            $orgResults = $this->processArchiMateOrganizations($archiMateData['organizations'], $options);
            $results['objects_created'] += $orgResults['created'];
            $results['objects_updated'] += $orgResults['updated'];
            $results['errors'] = array_merge($results['errors'], $orgResults['errors']);
        }

        if (!empty($archiMateData['relationships'])) {
            $relResults = $this->processArchiMateRelationships($archiMateData['relationships'], $options);
            $results['objects_created'] += $relResults['created'];
            $results['objects_updated'] += $relResults['updated'];
            $results['errors'] = array_merge($results['errors'], $relResults['errors']);
        }

        if (!empty($archiMateData['views'])) {
            $viewResults = $this->processArchiMateViews($archiMateData['views'], $options);
            $results['objects_created'] += $viewResults['created'];
            $results['objects_updated'] += $viewResults['updated'];
            $results['errors'] = array_merge($results['errors'], $viewResults['errors']);
        }

        return $results;
    }

    /**
     * Asynchronous processing for large files using ReactPHP
     *
     * @param array $archiMateData Normalized ArchiMate data
     * @param array $options Conversion options
     * 
     * @return array Conversion results
     */
    private function convertToOpenRegisterObjectsAsync(array $archiMateData, array $options): array
    {
        $loop = Loop::get();
        $results = [
            'objects_created' => 0,
            'objects_updated' => 0,
            'errors' => [],
            'processing_stats' => ['method' => 'asynchronous']
        ];

        $this->logger->info('Starting asynchronous ArchiMate processing');

        // Create promises for different processing tasks
        $promises = [];

        // Process elements asynchronously
        if (!empty($archiMateData['elements'])) {
            $promises['elements'] = $this->processArchiMateElementsAsync($archiMateData['elements'], $options);
        }

        // Process organizations asynchronously  
        if (!empty($archiMateData['organizations'])) {
            $promises['organizations'] = $this->processArchiMateOrganizationsAsync($archiMateData['organizations'], $options);
        }

        // Process relationships asynchronously
        if (!empty($archiMateData['relationships'])) {
            $promises['relationships'] = $this->processArchiMateRelationshipsAsync($archiMateData['relationships'], $options);
        }

        // Process views asynchronously
        if (!empty($archiMateData['views'])) {
            $promises['views'] = $this->processArchiMateViewsAsync($archiMateData['views'], $options);
        }

        // Wait for all processing to complete
        $allResults = [];
        foreach ($promises as $type => $promise) {
            try {
                $result = $this->waitForPromise($promise);
                $allResults[$type] = $result;
                
                $results['objects_created'] += $result['created'] ?? 0;
                $results['objects_updated'] += $result['updated'] ?? 0;
                $results['errors'] = array_merge($results['errors'], $result['errors'] ?? []);
                
            } catch (\Exception $e) {
                $this->logger->error("Failed to process $type asynchronously", [
                    'error' => $e->getMessage()
                ]);
                $results['errors'][] = "Failed to process $type: " . $e->getMessage();
            }
        }

        $results['processing_stats']['async_results'] = $allResults;
        
        $this->logger->info('Completed asynchronous ArchiMate processing', [
            'total_created' => $results['objects_created'],
            'total_updated' => $results['objects_updated'],
            'total_errors' => count($results['errors'])
        ]);

        return $results;
    }

    /**
     * Process ArchiMate elements asynchronously
     *
     * @param array $elements ArchiMate elements
     * @param array $options Processing options
     * 
     * @return Promise
     */
    private function processArchiMateElementsAsync(array $elements, array $options): Promise
    {
        $deferred = new Deferred();
        
        // Process elements in chunks to avoid memory issues
        $chunkSize = 50;
        $chunks = array_chunk($elements, $chunkSize);
        $results = ['created' => 0, 'updated' => 0, 'errors' => []];
        
        $this->processChunksAsync($chunks, $options, 'element')
            ->then(function($chunkResults) use ($deferred, &$results) {
                foreach ($chunkResults as $chunkResult) {
                    $results['created'] += $chunkResult['created'] ?? 0;
                    $results['updated'] += $chunkResult['updated'] ?? 0;
                    $results['errors'] = array_merge($results['errors'], $chunkResult['errors'] ?? []);
                }
                $deferred->resolve($results);
            })
            ->otherwise(function($error) use ($deferred) {
                $deferred->reject($error);
            });
            
        return $deferred->promise();
    }

    /**
     * Process ArchiMate organizations asynchronously
     *
     * @param array $organizations ArchiMate organizations
     * @param array $options Processing options
     * 
     * @return Promise
     */
    private function processArchiMateOrganizationsAsync(array $organizations, array $options): Promise
    {
        $deferred = new Deferred();
        
        // Process organizations in smaller chunks
        $chunkSize = 25;
        $chunks = array_chunk($organizations, $chunkSize);
        $results = ['created' => 0, 'updated' => 0, 'errors' => []];
        
        $this->processChunksAsync($chunks, $options, 'organization')
            ->then(function($chunkResults) use ($deferred, &$results) {
                foreach ($chunkResults as $chunkResult) {
                    $results['created'] += $chunkResult['created'] ?? 0;
                    $results['updated'] += $chunkResult['updated'] ?? 0;
                    $results['errors'] = array_merge($results['errors'], $chunkResult['errors'] ?? []);
                }
                $deferred->resolve($results);
            })
            ->otherwise(function($error) use ($deferred) {
                $deferred->reject($error);
            });
            
        return $deferred->promise();
    }

    /**
     * Process chunks of data asynchronously
     *
     * @param array $chunks Array of data chunks
     * @param array $options Processing options
     * @param string $type Type of items being processed
     * 
     * @return Promise
     */
    private function processChunksAsync(array $chunks, array $options, string $type): Promise
    {
        $deferred = new Deferred();
        $results = [];
        $processed = 0;
        $total = count($chunks);
        
        foreach ($chunks as $index => $chunk) {
            // Process each chunk with a slight delay to prevent overwhelming the system
            Loop::get()->addTimer($index * 0.1, function() use ($chunk, $options, $type, &$results, &$processed, $total, $deferred) {
                try {
                    if ($type === 'element') {
                        $result = $this->processArchiMateElements($chunk, $options);
                    } elseif ($type === 'organization') {
                        $result = $this->processArchiMateOrganizations($chunk, $options);
                    } elseif ($type === 'relationship') {
                        $result = $this->processArchiMateRelationships($chunk, $options);
                    } elseif ($type === 'view') {
                        $result = $this->processArchiMateViews($chunk, $options);
                    } else {
                        throw new \InvalidArgumentException("Unknown processing type: $type");
                    }
                    
                    $results[] = $result;
                    $processed++;
                    
                    // Check if all chunks are processed
                    if ($processed >= $total) {
                        $deferred->resolve($results);
                    }
                    
                } catch (\Exception $e) {
                    $this->logger->error("Failed to process $type chunk", [
                        'error' => $e->getMessage(),
                        'chunk_index' => array_search($chunk, array_values($chunks))
                    ]);
                    
                    $results[] = ['created' => 0, 'updated' => 0, 'errors' => [$e->getMessage()]];
                    $processed++;
                    
                    if ($processed >= $total) {
                        $deferred->resolve($results);
                    }
                }
            });
        }
        
        return $deferred->promise();
    }

    /**
     * Wait for a promise to complete (blocking)
     *
     * @param Promise $promise The promise to wait for
     * 
     * @return mixed The resolved value
     * 
     * @throws \Exception If the promise rejects
     */
    private function waitForPromise(Promise $promise)
    {
        $result = null;
        $error = null;
        $completed = false;
        
        $promise->then(
            function($value) use (&$result, &$completed) {
                $result = $value;
                $completed = true;
            },
            function($reason) use (&$error, &$completed) {
                $error = $reason;
                $completed = true;
            }
        );
        
        // Run the event loop until the promise completes
        $loop = Loop::get();
        while (!$completed) {
            $loop->tick();
            usleep(1000); // Small delay to prevent excessive CPU usage
        }
        
        if ($error !== null) {
            if ($error instanceof \Exception) {
                throw $error;
            } else {
                throw new \Exception('Promise rejected: ' . $error);
            }
        }
        
        return $result;
    }

    /**
     * Process ArchiMate elements and create OpenRegister objects
     *
     * @param array $elements ArchiMate elements
     * @param array $options Processing options
     * 
     * @return array Processing results
     */
    private function processArchiMateElements(array $elements, array $options): array
    {
        $results = ['created' => 0, 'updated' => 0, 'errors' => []];

        foreach ($elements as $element) {
            try {
                // Map ArchiMate element to OpenRegister object structure
                $objectData = $this->mapElementToObject($element);
                
                // Create or update object in OpenRegister
                $result = $this->createOrUpdateObject($objectData, $options);
                
                if ($result['created']) {
                    $results['created']++;
                } else {
                    $results['updated']++;
                }
                
            } catch (\Exception $e) {
                $results['errors'][] = 'Failed to process element: ' . $e->getMessage();
                $this->logger->warning('Failed to process ArchiMate element', [
                    'element' => $element,
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $results;
    }

    /**
     * Process ArchiMate organizations and create OpenRegister objects
     *
     * @param array $organizations ArchiMate organizations
     * @param array $options Processing options
     * 
     * @return array Processing results
     */
    private function processArchiMateOrganizations(array $organizations, array $options): array
    {
        $results = ['created' => 0, 'updated' => 0, 'errors' => []];

        foreach ($organizations as $organization) {
            try {
                // Map ArchiMate organization to OpenRegister object structure
                $objectData = $this->mapOrganizationToObject($organization);
                
                // Create or update object in OpenRegister
                $result = $this->createOrUpdateObject($objectData, $options);
                
                if ($result['created']) {
                    $results['created']++;
                } else {
                    $results['updated']++;
                }
                
            } catch (\Exception $e) {
                $results['errors'][] = 'Failed to process organization: ' . $e->getMessage();
                $this->logger->warning('Failed to process ArchiMate organization', [
                    'organization' => $organization,
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $results;
    }

    /**
     * Process ArchiMate relationships and create OpenRegister objects
     *
     * @param array $relationships ArchiMate relationships
     * @param array $options Processing options
     * 
     * @return array Processing results
     */
    private function processArchiMateRelationships(array $relationships, array $options): array
    {
        $results = ['created' => 0, 'updated' => 0, 'errors' => []];

        foreach ($relationships as $relationship) {
            try {
                // Map ArchiMate relationship to OpenRegister object structure
                $objectData = $this->mapRelationshipToObject($relationship);
                
                // Create or update object in OpenRegister
                $result = $this->createOrUpdateObject($objectData, $options);
                
                if ($result['created']) {
                    $results['created']++;
                } else {
                    $results['updated']++;
                }
                
            } catch (\Exception $e) {
                $results['errors'][] = 'Failed to process relationship: ' . $e->getMessage();
                $this->logger->warning('Failed to process ArchiMate relationship', [
                    'relationship' => $relationship,
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $results;
    }

    /**
     * Process ArchiMate views and create OpenRegister objects
     *
     * @param array $views ArchiMate views
     * @param array $options Processing options
     * 
     * @return array Processing results
     */
    private function processArchiMateViews(array $views, array $options): array
    {
        $results = ['created' => 0, 'updated' => 0, 'errors' => []];

        foreach ($views as $view) {
            try {
                // Map ArchiMate view to OpenRegister object structure
                $objectData = $this->mapViewToObject($view);
                
                // Create or update object in OpenRegister
                $result = $this->createOrUpdateObject($objectData, $options);
                
                if ($result['created']) {
                    $results['created']++;
                } else {
                    $results['updated']++;
                }
                
            } catch (\Exception $e) {
                $results['errors'][] = 'Failed to process view: ' . $e->getMessage();
                $this->logger->warning('Failed to process ArchiMate view', [
                    'view' => $view,
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $results;
    }

    /**
     * Process ArchiMate relationships asynchronously
     *
     * @param array $relationships ArchiMate relationships
     * @param array $options Processing options
     * 
     * @return Promise
     */
    private function processArchiMateRelationshipsAsync(array $relationships, array $options): Promise
    {
        $deferred = new Deferred();
        
        // Process relationships in chunks
        $chunkSize = 50;
        $chunks = array_chunk($relationships, $chunkSize);
        $results = ['created' => 0, 'updated' => 0, 'errors' => []];
        
        $this->processChunksAsync($chunks, $options, 'relationship')
            ->then(function($chunkResults) use ($deferred, &$results) {
                foreach ($chunkResults as $chunkResult) {
                    $results['created'] += $chunkResult['created'] ?? 0;
                    $results['updated'] += $chunkResult['updated'] ?? 0;
                    $results['errors'] = array_merge($results['errors'], $chunkResult['errors'] ?? []);
                }
                $deferred->resolve($results);
            })
            ->otherwise(function($error) use ($deferred) {
                $deferred->reject($error);
            });
            
        return $deferred->promise();
    }

    /**
     * Process ArchiMate views asynchronously
     *
     * @param array $views ArchiMate views
     * @param array $options Processing options
     * 
     * @return Promise
     */
    private function processArchiMateViewsAsync(array $views, array $options): Promise
    {
        $deferred = new Deferred();
        
        // Process views in smaller chunks as they can be complex
        $chunkSize = 25;
        $chunks = array_chunk($views, $chunkSize);
        $results = ['created' => 0, 'updated' => 0, 'errors' => []];
        
        $this->processChunksAsync($chunks, $options, 'view')
            ->then(function($chunkResults) use ($deferred, &$results) {
                foreach ($chunkResults as $chunkResult) {
                    $results['created'] += $chunkResult['created'] ?? 0;
                    $results['updated'] += $chunkResult['updated'] ?? 0;
                    $results['errors'] = array_merge($results['errors'], $chunkResult['errors'] ?? []);
                }
                $deferred->resolve($results);
            })
            ->otherwise(function($error) use ($deferred) {
                $deferred->reject($error);
            });
            
        return $deferred->promise();
    }

    /**
     * Map ArchiMate relationship to OpenRegister object structure
     *
     * @param array $relationship ArchiMate relationship data
     * 
     * @return array OpenRegister object data
     */
    private function mapRelationshipToObject(array $relationship): array
    {
        // IMPORTANT: Use ArchiMate ID as OpenRegister object ID for update detection
        $archiMateId = $relationship['identifier'] ?? $relationship['id'] ?? null;
        
        return [
            'id' => $archiMateId, // Use ArchiMate ID as OpenRegister object ID
            'naam' => $relationship['name'] ?? 'Unnamed Relationship',
            'type' => 'Relatie',
            'beschrijving' => $relationship['documentation'] ?? '',
            'archiMateId' => $archiMateId, // Keep original ID for reference
            'archiMateType' => $relationship['type'] ?? 'unknown',
            'sourceId' => $relationship['source'] ?? null,
            'targetId' => $relationship['target'] ?? null,
            'properties' => $relationship['properties'] ?? [],
            'metadata' => [
                'importedAt' => date('c'),
                'sourceFile' => $relationship['_sourceFile'] ?? null,
                'relationshipType' => $relationship['type'] ?? 'unknown'
            ]
        ];
    }

    /**
     * Map ArchiMate view to OpenRegister object structure
     *
     * @param array $view ArchiMate view data
     * 
     * @return array OpenRegister object data
     */
    private function mapViewToObject(array $view): array
    {
        // IMPORTANT: Use ArchiMate ID as OpenRegister object ID for update detection
        $archiMateId = $view['identifier'] ?? $view['id'] ?? null;
        
        return [
            'id' => $archiMateId, // Use ArchiMate ID as OpenRegister object ID
            'naam' => $view['name'] ?? 'Unnamed View',
            'type' => 'Overzicht',
            'beschrijving' => $view['documentation'] ?? '',
            'archiMateId' => $archiMateId, // Keep original ID for reference
            'archiMateType' => $view['type'] ?? 'view',
            'viewpoint' => $view['viewpoint'] ?? null,
            'elements' => $view['elements'] ?? [],
            'connections' => $view['connections'] ?? [],
            'properties' => $view['properties'] ?? [],
            'metadata' => [
                'importedAt' => date('c'),
                'sourceFile' => $view['_sourceFile'] ?? null,
                'viewType' => $view['type'] ?? 'view'
            ]
        ];
    }

    /**
     * Map ArchiMate element to OpenRegister object structure
     *
     * @param array $element ArchiMate element data
     * 
     * @return array OpenRegister object data
     */
    private function mapElementToObject(array $element): array
    {
        // IMPORTANT: Use ArchiMate ID as OpenRegister object ID for update detection
        $archiMateId = $element['identifier'] ?? $element['id'] ?? null;
        
        return [
            'id' => $archiMateId, // Use ArchiMate ID as OpenRegister object ID
            'naam' => $element['name'] ?? 'Unnamed Element',
            'type' => $this->mapArchiMateType($element['type'] ?? 'unknown'),
            'beschrijving' => $element['documentation'] ?? '',
            'archiMateId' => $archiMateId, // Keep original ID for reference
            'archiMateType' => $element['type'] ?? 'unknown',
            'properties' => $element['properties'] ?? [],
            'metadata' => [
                'importedAt' => date('c'),
                'sourceFile' => $element['_sourceFile'] ?? null,
                'elementType' => $element['type'] ?? 'unknown'
            ]
        ];
    }

    /**
     * Map ArchiMate organization to OpenRegister object structure
     *
     * @param array $organization ArchiMate organization data
     * 
     * @return array OpenRegister object data
     */
    private function mapOrganizationToObject(array $organization): array
    {
        // IMPORTANT: Use ArchiMate ID as OpenRegister object ID for update detection  
        $archiMateId = $organization['identifier'] ?? $organization['id'] ?? null;
        
        return [
            'id' => $archiMateId, // Use ArchiMate ID as OpenRegister object ID
            'naam' => $organization['name'] ?? 'Unnamed Organization',
            'type' => 'Leverancier',
            'beschrijving' => $organization['documentation'] ?? '',
            'website' => $organization['website'] ?? '',
            'beoordeling' => 'Actief',
            'archiMateId' => $archiMateId, // Keep original ID for reference
            'contactpersonen' => $organization['contacts'] ?? [],
            'metadata' => [
                'importedAt' => date('c'),
                'sourceFile' => $organization['_sourceFile'] ?? null,
                'organizationType' => 'ArchiMate'
            ]
        ];
    }

    /**
     * Map ArchiMate type to OpenRegister type
     *
     * @param string $archiMateType ArchiMate element type
     * 
     * @return string Mapped OpenRegister type
     */
    private function mapArchiMateType(string $archiMateType): string
    {
        $typeMapping = [
            'ApplicationComponent' => 'Applicatie',
            'ApplicationService' => 'Service',
            'BusinessActor' => 'Organisatie',
            'BusinessRole' => 'Rol',
            'BusinessProcess' => 'Proces',
            'BusinessService' => 'Service',
            'TechnologyService' => 'Technische Service',
            'Node' => 'Infrastructuur'
        ];

        return $typeMapping[$archiMateType] ?? 'Onbekend';
    }

    /**
     * Create or update object in OpenRegister with ID preservation
     *
     * @param array $objectData Object data with ArchiMate ID
     * @param array $options Creation options
     * 
     * @return array Result with created/updated flag
     */
    private function createOrUpdateObject(array $objectData, array $options): array
    {
        // In the real implementation, this would use ObjectService to:
        // 1. Check if object with ArchiMate ID already exists
        // 2. Update existing object or create new one
        // 3. Preserve the ArchiMate ID as the OpenRegister object ID
        
        $archiMateId = $objectData['id'] ?? $objectData['archiMateId'] ?? null;
        $created = false;
        
        if ($archiMateId && $options['preserveIds'] ?? true) {
            // Determine object type from object data for optimized cache lookup
            $objectType = $this->determineObjectType($objectData);
            
            // Check if object with this ArchiMate ID already exists using cached data
            $existingObject = $this->findObjectByArchiMateId($archiMateId, $objectType);
            
            if ($existingObject && ($options['updateExisting'] ?? true)) {
                // Update existing object
                $this->logger->debug('Would update existing object', [
                    'archiMateId' => $archiMateId,
                    'objectData' => $objectData
                ]);
                $created = false;
            } else if (!$existingObject) {
                // Create new object with preserved ID
                $this->logger->debug('Would create new object with preserved ID', [
                    'archiMateId' => $archiMateId,
                    'objectData' => $objectData
                ]);
                $created = true;
            } else {
                // Object exists but updating is disabled
                $this->logger->debug('Object exists but update disabled', [
                    'archiMateId' => $archiMateId
                ]);
                return ['created' => false, 'skipped' => true, 'reason' => 'exists_no_update'];
            }
        } else {
            // No ArchiMate ID or ID preservation disabled - create with generated ID
            $this->logger->debug('Would create object with generated ID', [
                'objectData' => $objectData
            ]);
            $created = true;
        }

        return ['created' => $created];
    }

    /**
     * Preload all existing objects from OpenRegister to avoid individual database queries
     * Objects are cached by type and indexed by their ID for O(1) lookup performance
     * 
     * @return void
     */
    private function preloadExistingObjects(): void
    {
        $startTime = microtime(true);
        $this->cachedObjects = [];

        try {
            // Define the object types we need to cache
            $objectTypes = [
                'element' => $this->getArchiMateElementSchemaId(),
                'organization' => $this->getOrganizationSchemaId(),
                'relationship' => $this->getRelationshipSchemaId(),
                'view' => $this->getViewSchemaId()
            ];

            $totalObjects = 0;

            foreach ($objectTypes as $type => $schemaId) {
                if ($schemaId) {
                    // In real implementation, this would query OpenRegister:
                    // $objects = $this->objectService->getObjectsBySchema($schemaId);
                    $objects = []; // Placeholder for now

                    // Index objects by their ID for fast lookup
                    $this->cachedObjects[$type] = [];
                    foreach ($objects as $object) {
                        $id = $object['id'] ?? $object['archiMateId'] ?? null;
                        if ($id) {
                            $this->cachedObjects[$type][$id] = $object;
                            $totalObjects++;
                        }
                    }
                }
            }

            $loadTime = round((microtime(true) - $startTime) * 1000, 2);
            
            $this->logger->info('Successfully preloaded existing objects for efficient lookup', [
                'total_objects' => $totalObjects,
                'object_types' => array_keys($this->cachedObjects),
                'objects_by_type' => array_map('count', $this->cachedObjects),
                'load_time_ms' => $loadTime
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to preload existing objects', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            // Initialize empty cache to prevent errors
            $this->cachedObjects = [
                'element' => [],
                'organization' => [],
                'relationship' => [],
                'view' => []
            ];
        }
    }

    /**
     * Find existing object by ArchiMate ID using preloaded cache
     *
     * @param string $archiMateId The ArchiMate ID to search for
     * @param string $objectType The type of object to search for (element, organization, etc.)
     * 
     * @return array|null Existing object data or null if not found
     */
    private function findObjectByArchiMateId(string $archiMateId, string $objectType = ''): ?array
    {
        // If no object type specified, search across all types
        if (empty($objectType)) {
            foreach ($this->cachedObjects as $type => $objects) {
                if (isset($objects[$archiMateId])) {
                    $this->logger->debug('Found existing object in cache', [
                        'archiMateId' => $archiMateId,
                        'type' => $type
                    ]);
                    return $objects[$archiMateId];
                }
            }
        } else {
            // Search in specific object type
            if (isset($this->cachedObjects[$objectType][$archiMateId])) {
                $this->logger->debug('Found existing object in cache by type', [
                    'archiMateId' => $archiMateId,
                    'type' => $objectType
                ]);
                return $this->cachedObjects[$objectType][$archiMateId];
            }
        }
        
        $this->logger->debug('Object not found in cache', [
            'archiMateId' => $archiMateId,
            'type' => $objectType ?: 'all'
        ]);
        
        return null;
    }

    /**
     * Get objects from OpenRegister for export
     *
     * @param array $criteria Selection criteria
     * 
     * @return array Objects to export
     */
    private function getObjectsForExport(array $criteria): array
    {
        // Placeholder - would query OpenRegister objects based on criteria
        return [];
    }

    /**
     * Convert OpenRegister objects to ArchiMate format
     *
     * @param array $objects OpenRegister objects
     * @param array $options Conversion options
     * 
     * @return array ArchiMate data
     */
    private function convertFromOpenRegisterObjects(array $objects, array $options): array
    {
        // Placeholder - would convert objects to ArchiMate format
        return [
            'model' => [
                'name' => 'Software Catalog Export',
                'identifier' => 'sc-' . uniqid(),
                'elements' => [],
                'relationships' => [],
                'organizations' => []
            ]
        ];
    }

    /**
     * Generate ArchiMate file from data
     *
     * @param array $archiMateData ArchiMate data
     * @param array $options Generation options
     * 
     * @return array File information
     */
    private function generateArchiMateFile(array $archiMateData, array $options): array
    {
        $format = $options['format'] ?? 'xml';
        $fileName = 'software-catalog-export-' . date('Y-m-d-H-i-s') . '.' . $format;
        
        if ($format === 'json') {
            $content = json_encode($archiMateData, JSON_PRETTY_PRINT);
        } else {
            $content = $this->convertToXml($archiMateData);
        }

        // Save file to temporary location
        $userFolder = $this->rootFolder->getUserFolder($this->userSession->getUser()->getUID());
        $file = $userFolder->newFile($fileName);
        $file->putContent($content);

        return [
            'path' => $file->getPath(),
            'name' => $fileName,
            'size' => strlen($content)
        ];
    }

    /**
     * Convert data to XML format
     *
     * @param array $data Data to convert
     * 
     * @return string XML content
     */
    private function convertToXml(array $data): string
    {
        // Placeholder - would convert array data to proper ArchiMate XML format
        $xml = new \SimpleXMLElement('<model></model>');
        
        // Add basic model information
        $xml->addAttribute('identifier', $data['model']['identifier'] ?? 'sc-export');
        $xml->addChild('name', $data['model']['name'] ?? 'Software Catalog Export');

        return $xml->asXML();
    }

    /**
     * Get the schema ID for ArchiMate elements
     * 
     * @return int|null Schema ID or null if not configured
     */
    private function getArchiMateElementSchemaId(): ?int
    {
        $schemaId = $this->appConfig->getValueString('softwarecatalog', 'amef_elements_schema', '');
        return !empty($schemaId) ? (int)$schemaId : null;
    }

    /**
     * Get the schema ID for organizations
     * 
     * @return int|null Schema ID or null if not configured
     */
    private function getOrganizationSchemaId(): ?int
    {
        // Try AMEF-specific configuration first, then fall back to general organization schema
        $amefSchema = $this->appConfig->getValueString('softwarecatalog', 'amef_organizations_schema', '');
        if (!empty($amefSchema)) {
            return (int)$amefSchema;
        }
        
        $generalSchema = $this->appConfig->getValueString('softwarecatalog', 'voorzieningen_organisatie_schema', '');
        return !empty($generalSchema) ? (int)$generalSchema : null;
    }

    /**
     * Get the schema ID for relationships
     * 
     * @return int|null Schema ID or null if not configured
     */
    private function getRelationshipSchemaId(): ?int
    {
        $schemaId = $this->appConfig->getValueString('softwarecatalog', 'amef_relationships_schema', '');
        return !empty($schemaId) ? (int)$schemaId : null;
    }

    /**
     * Get the schema ID for views
     * 
     * @return int|null Schema ID or null if not configured
     */
    private function getViewSchemaId(): ?int
    {
        $schemaId = $this->appConfig->getValueString('softwarecatalog', 'amef_views_schema', '');
        return !empty($schemaId) ? (int)$schemaId : null;
    }

    /**
     * Get the register ID for AMEF operations
     * 
     * @return int|null Register ID or null if not configured
     */
    private function getAmefRegisterId(): ?int
    {
        $registerId = $this->appConfig->getValueString('softwarecatalog', 'amef_register_id', '');
        return !empty($registerId) ? (int)$registerId : null;
    }

    /**
     * Determine the object type from object data for efficient cache lookup
     * 
     * @param array $objectData The object data to analyze
     * 
     * @return string The object type (element, organization, relationship, view)
     */
    private function determineObjectType(array $objectData): string
    {
        // Check for specific fields that indicate object type
        if (isset($objectData['source']) && isset($objectData['target'])) {
            return 'relationship';
        }
        
        if (isset($objectData['viewType']) || isset($objectData['nodes']) || isset($objectData['connections'])) {
            return 'view';
        }
        
        if (isset($objectData['naam']) || isset($objectData['contactpersonen']) || isset($objectData['type'])) {
            return 'organization';
        }
        
        // Default to element for ArchiMate elements
        return 'element';
    }
}