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
use React\Promise\Promise;
use function React\Promise\all;
use React\Promise\Deferred;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;

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
     * ArchiMateService constructor
     *
     * @param IAppConfig $appConfig Application configuration service
     * @param IRootFolder $rootFolder Root folder service for file operations
     * @param IUserSession $userSession User session service
     * @param IAppManager $appManager App manager service for checking app availability
     * @param ContainerInterface $container Service container for dependency injection
     * @param LoggerInterface $logger Logger service
     */
    public function __construct(
        private readonly IAppConfig $appConfig,
        private readonly IRootFolder $rootFolder,
        private readonly IUserSession $userSession,
        private readonly IAppManager $appManager,
        private readonly ContainerInterface $container,
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
        $startTime = microtime(true);
        
        $this->logger->info('Starting ArchiMate export', [
            'criteria' => $criteria,
            'options' => $options
        ]);

        try {
            // Phase 1: Get objects from OpenRegister based on criteria
            $this->logger->info('Phase 1: Loading objects for export');
            $objects = $this->getObjectsForExport($criteria);

            // Phase 2: Convert to ArchiMate format
            $this->logger->info('Phase 2: Converting objects to ArchiMate format', [
                'object_count' => count($objects)
            ]);
            $archiMateData = $this->convertFromOpenRegisterObjects($objects, $options);

            // Phase 3: Generate export file
            $this->logger->info('Phase 3: Generating ArchiMate file');
            $exportFile = $this->generateArchiMateFile($archiMateData, $options);

            $totalTime = microtime(true) - $startTime;

            $this->logger->info('ArchiMate export completed successfully', [
                'objects_exported' => count($objects),
                'file_path' => $exportFile['path'],
                'total_time_seconds' => round($totalTime, 3)
            ]);

            return [
                'success' => true,
                'message' => 'ArchiMate export completed successfully',
                'file_path' => $exportFile['path'],
                'file_name' => $exportFile['name'],
                'statistics' => [
                    'objects_exported' => count($objects),
                    'file_size' => $exportFile['size'],
                    'total_time_seconds' => round($totalTime, 3)
                ]
            ];

        } catch (\Exception $e) {
            $totalTime = microtime(true) - $startTime;
            
            $this->logger->error('ArchiMate export failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'total_time_seconds' => round($totalTime, 3)
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
        
        // Always use streaming parser for consistent memory management
        $this->logger->info('Parsing ArchiMate file using streaming parser', [
            'filename' => $file->getName(),
            'size' => $fileSize,
            'method' => 'streaming'
        ]);
        
        return $this->parseArchiMateFileStreaming($file, $options);
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
                        
                        if ($elementCount <= 5) { // Log first 5 elements for debugging
                            $this->logger->debug('Found element', [
                                'count' => $elementCount,
                                'id' => $elementData['_attributes']['identifier'] ?? 'unknown',
                                'type' => $elementData['_attributes']['xsi:type'] ?? 'unknown'
                            ]);
                        }
                        
                        if ($progressTracker && $elementCount % 100 === 0) {
                            $progressTracker->updateProgress($elementCount, 'Processing elements');
                        }
                        
                    } elseif ($elementName === 'relationship') {
                        $elementData = $this->extractElementAttributes($reader);
                        $elementData = $this->processStreamingElementContent($reader, $elementData);
                        $result['relationships'][] = $elementData;
                        $relationshipCount++;
                        
                        if ($relationshipCount <= 5) { // Log first 5 relationships for debugging
                            $this->logger->debug('Found relationship', [
                                'count' => $relationshipCount,
                                'id' => $elementData['_attributes']['identifier'] ?? 'unknown',
                                'type' => $elementData['_attributes']['xsi:type'] ?? 'unknown'
                            ]);
                        }
                        
                        if ($progressTracker && $relationshipCount % 100 === 0) {
                            $progressTracker->updateProgress($relationshipCount, 'Processing relationships');
                        }
                        
                    } elseif ($elementName === 'view') {
                        $elementData = $this->extractElementAttributes($reader);
                        $elementData = $this->processStreamingElementContent($reader, $elementData);
                        $result['views'][] = $elementData;
                        $viewCount++;
                        
                        if ($viewCount <= 5) { // Log first 5 views for debugging
                            $this->logger->debug('Found view', [
                                'count' => $viewCount,
                                'id' => $elementData['_attributes']['identifier'] ?? 'unknown',
                                'type' => $elementData['_attributes']['xsi:type'] ?? 'unknown'
                            ]);
                        }
                        
                        if ($progressTracker && $viewCount % 10 === 0) {
                            $progressTracker->updateProgress($viewCount, 'Processing views');
                        }
                    }
                }
            }
            
            $reader->close();
            
            // Extract organizations from elements
            $result['organizations'] = $this->extractOrganizations($result['elements']);
            
            $this->logger->info('Streaming parsing completed', [
                'elements' => count($result['elements']),
                'relationships' => count($result['relationships']),
                'views' => count($result['views']),
                'organizations' => count($result['organizations'])
            ]);
            
            return $this->normalizeArchiMateData($result);
            
        } catch (\Exception $e) {
            $reader->close();
            throw new \RuntimeException('Streaming parsing failed: ' . $e->getMessage(), 0, $e);
        }
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
        // Add debug logging to see what we're working with
        $this->logger->debug('Normalizing ArchiMate data', [
            'data_keys' => array_keys($data),
            'has_elements' => isset($data['elements']),
            'has_relationships' => isset($data['relationships']),
            'has_views' => isset($data['views']),
            'has_organizations' => isset($data['organizations'])
        ]);

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
            
            $this->logger->debug('Processing elements', [
                'elements_count' => is_array($elementsData) ? count($elementsData) : 1,
                'elements_type' => gettype($elementsData)
            ]);
            
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
     * Extract text value with language information
     *
     * @param mixed $data Text data that may have attributes
     * 
     * @return array|null Extracted text value with language info
     */
    private function extractTextValueWithLanguage($data): ?array
    {
        if (is_string($data)) {
            return ['value' => $data, 'language' => 'en'];
        }
        
        if (is_array($data)) {
            $value = null;
            $language = 'en';
            
            if (isset($data['_value'])) {
                $value = $data['_value'];
            } elseif (isset($data['_text'])) {
                $value = $data['_text'];
            }
            
            // Extract language from attributes
            if (isset($data['_attributes']['xml:lang'])) {
                $language = $data['_attributes']['xml:lang'];
            }
            
            if ($value !== null) {
                return ['value' => $value, 'language' => $language];
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
        // Extract name with language information
        $nameData = $this->extractTextValueWithLanguage($element['name'] ?? null);
        $documentationData = $this->extractTextValueWithLanguage($element['documentation'] ?? null);
        
        // Extract all element-specific attributes
        $attributes = $element['_attributes'] ?? [];
        
        return [
            'id' => $attributes['identifier'] ?? null,
            'archiMateId' => $attributes['identifier'] ?? null, // Preserve for updates
            'type' => $attributes['xsi:type'] ?? 'Element',
            'name' => $nameData['value'] ?? null,
            'nameLanguage' => $nameData['language'] ?? 'en',
            'documentation' => $documentationData['value'] ?? null,
            'documentationLanguage' => $documentationData['language'] ?? 'en',
            'properties' => $this->extractProperties($element['properties'] ?? []),
            '_originalAttributes' => $attributes
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
        // Extract name with language information
        $nameData = $this->extractTextValueWithLanguage($relationship['name'] ?? null);
        $documentationData = $this->extractTextValueWithLanguage($relationship['documentation'] ?? null);
        
        // Extract all relationship-specific attributes
        $attributes = $relationship['_attributes'] ?? [];
        
        return [
            'id' => $attributes['identifier'] ?? null,
            'archiMateId' => $attributes['identifier'] ?? null,
            'type' => $attributes['xsi:type'] ?? 'Relationship',
            'source' => $attributes['source'] ?? null,
            'target' => $attributes['target'] ?? null,
            'accessType' => $attributes['accessType'] ?? null, // Important for Access relationships
            'name' => $nameData['value'] ?? null,
            'nameLanguage' => $nameData['language'] ?? 'en',
            'documentation' => $documentationData['value'] ?? null,
            'documentationLanguage' => $documentationData['language'] ?? 'en',
            'properties' => $this->extractProperties($relationship['properties'] ?? []),
            '_originalAttributes' => $attributes
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
        // Extract name with language information
        $nameData = $this->extractTextValueWithLanguage($view['name'] ?? null);
        $documentationData = $this->extractTextValueWithLanguage($view['documentation'] ?? null);
        
        // Extract all view-specific attributes
        $attributes = $view['_attributes'] ?? [];
        
        return [
            'id' => $attributes['identifier'] ?? null,
            'archiMateId' => $attributes['identifier'] ?? null,
            'type' => $attributes['xsi:type'] ?? 'View',
            'viewType' => $attributes['viewpoint'] ?? null,
            'name' => $nameData['value'] ?? null,
            'nameLanguage' => $nameData['language'] ?? 'en',
            'documentation' => $documentationData['value'] ?? null,
            'documentationLanguage' => $documentationData['language'] ?? 'en',
            'properties' => $this->extractProperties($view['properties'] ?? []),
            'nodes' => $this->extractViewNodes($view['node'] ?? []),
            'connections' => $this->extractViewConnections($view['connection'] ?? []),
            '_originalAttributes' => $attributes
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
                    'nameLanguage' => $element['nameLanguage'] ?? 'en',
                    'type' => $this->mapArchiMateTypeToOrganizationType($element['type']),
                    'beschrijvingKort' => $element['documentation'],
                    'descriptionLanguage' => $element['documentationLanguage'] ?? 'en',
                    'properties' => $element['properties']
                ];
            }
        }
        
        return $organizations;
    }

    /**
     * Extract view nodes with attributes and content
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
                $nodeData = [
                    'id' => $node['_attributes']['identifier'] ?? null,
                    'type' => $node['_attributes']['xsi:type'] ?? null,
                    'elementRef' => $node['_attributes']['elementRef'] ?? null,
                    'x' => $node['_attributes']['x'] ?? null,
                    'y' => $node['_attributes']['y'] ?? null,
                    'width' => $node['_attributes']['w'] ?? null,
                    'height' => $node['_attributes']['h'] ?? null,
                    'label' => $this->extractTextValueWithLanguage($node['label'] ?? null),
                    'style' => $this->extractNodeStyle($node['style'] ?? null),
                    'nodes' => $this->extractViewNodes($node['node'] ?? []) // Handle nested nodes
                ];
                
                $nodes[] = $nodeData;
            }
        }
        
        return $nodes;
    }

    /**
     * Extract node style information
     *
     * @param array|null $styleData Style data
     * 
     * @return array|null Normalized style data
     */
    private function extractNodeStyle(?array $styleData): ?array
    {
        if (!$styleData) {
            return null;
        }
        
        return [
            'fillColor' => $styleData['fillColor'] ?? null,
            'lineColor' => $styleData['lineColor'] ?? null,
            'font' => $styleData['font'] ?? null
        ];
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
     * Convert ArchiMate data to OpenRegister objects with performance monitoring
     *
     * @param array $archiMateData Normalized ArchiMate data
     * @param array $options Conversion options
     * 
     * @return array Conversion results with detailed statistics per schema
     */
    private function convertToOpenRegisterObjects(array $archiMateData, array $options): array
    {
        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);
        
        $results = [
            'objects_created' => 0,
            'objects_updated' => 0,
            'objects_deleted' => 0,
            'objects_skipped' => 0,
            'errors' => [],
            'processing_stats' => [
                'total_time' => 0,
                'preload_time' => 0,
                'parallel_processing_time' => 0,
                'method' => 'parallel'
            ],
            'schema_statistics' => [
                            'elements' => ['found' => 0, 'created' => 0, 'updated' => 0, 'deleted' => 0, 'skipped' => 0, 'errors' => [], 'processing_time' => 0],
            'organizations' => ['found' => 0, 'created' => 0, 'updated' => 0, 'deleted' => 0, 'skipped' => 0, 'errors' => [], 'processing_time' => 0],
            'relationships' => ['found' => 0, 'created' => 0, 'updated' => 0, 'deleted' => 0, 'skipped' => 0, 'errors' => [], 'processing_time' => 0],
            'views' => ['found' => 0, 'created' => 0, 'updated' => 0, 'deleted' => 0, 'skipped' => 0, 'errors' => [], 'processing_time' => 0]
            ]
        ];

        // Preload all existing objects to avoid individual DB queries during processing
        $preloadStart = microtime(true);
        $this->preloadExistingObjects();
        $preloadTime = microtime(true) - $preloadStart;
        $results['processing_stats']['preload_time'] = $preloadTime;

        $totalElements = count($archiMateData['elements'] ?? []);
        $totalOrganizations = count($archiMateData['organizations'] ?? []);
        $totalRelationships = count($archiMateData['relationships'] ?? []);
        $totalViews = count($archiMateData['views'] ?? []);
        
        $totalItems = $totalElements + $totalOrganizations + $totalRelationships + $totalViews;

        $this->logger->info('Starting ArchiMate data processing', [
            'total_elements' => $totalElements,
            'total_organizations' => $totalOrganizations,
            'total_relationships' => $totalRelationships,
            'total_views' => $totalViews,
            'total_items' => $totalItems,
            'preload_time_seconds' => round($preloadTime, 3),
            'cached_objects_loaded' => array_sum(array_map('count', $this->cachedObjects))
        ]);

        // Use parallel processing for different schema types
        $parallelStart = microtime(true);
        
        // Use parallel processing with ReactPHP
        $parallelResults = $this->processSchemasInParallel($archiMateData, $options);
        
        $parallelTime = microtime(true) - $parallelStart;
        $results['processing_stats']['parallel_processing_time'] = $parallelTime;

        // Merge results
        foreach ($parallelResults as $schemaType => $schemaResult) {
            $results['objects_created'] += $schemaResult['created'];
            $results['objects_updated'] += $schemaResult['updated'];
            $results['objects_deleted'] += $schemaResult['deleted'] ?? 0;
            $results['objects_skipped'] += $schemaResult['skipped'] ?? 0;
            $results['errors'] = array_merge($results['errors'], $schemaResult['errors']);
            $results['schema_statistics'][$schemaType] = $schemaResult;
        }

        $totalTime = microtime(true) - $startTime;
        $endMemory = memory_get_usage(true);
        $memoryUsed = $endMemory - $startMemory;
        $peakMemory = memory_get_peak_usage(true);
        
        $results['processing_stats']['total_time'] = $totalTime;
        $results['processing_stats']['memory_used_mb'] = round($memoryUsed / 1024 / 1024, 2);
        $results['processing_stats']['peak_memory_mb'] = round($peakMemory / 1024 / 1024, 2);

        $this->logger->info('Completed ArchiMate data processing', [
            'total_time_seconds' => round($totalTime, 3),
            'preload_time_seconds' => round($preloadTime, 3),
            'parallel_processing_time_seconds' => round($parallelTime, 3),
            'memory_used_mb' => round($memoryUsed / 1024 / 1024, 2),
            'peak_memory_mb' => round($peakMemory / 1024 / 1024, 2),
            'objects_created' => $results['objects_created'],
            'objects_updated' => $results['objects_updated'],
            'objects_deleted' => $results['objects_deleted'],
            'objects_skipped' => $results['objects_skipped'],
            'total_errors' => count($results['errors']),
            'performance_ratio' => round($parallelTime / $totalTime, 3)
        ]);

        // Handle orphaned object deletion if requested
        if (!empty($options['deleteOrphaned']) && $options['deleteOrphaned'] === true) {
            $orphanedResults = $this->deleteOrphanedObjects($archiMateData, $options);
            $results['objects_deleted'] += $orphanedResults['deleted'];
            $results['errors'] = array_merge($results['errors'], $orphanedResults['errors']);
            
            $this->logger->info('Orphaned object deletion completed', [
                'objects_deleted' => $orphanedResults['deleted'],
                'errors' => count($orphanedResults['errors'])
            ]);
        }

        // Log memory warnings
        if ($memoryUsed > 100 * 1024 * 1024) { // More than 100MB used
            $this->logger->warning('High memory usage detected during processing', [
                'memory_used_mb' => round($memoryUsed / 1024 / 1024, 2),
                'peak_memory_mb' => round($peakMemory / 1024 / 1024, 2),
                'total_items' => array_sum(array_map('count', [$archiMateData['elements'] ?? [], $archiMateData['organizations'] ?? [], $archiMateData['relationships'] ?? [], $archiMateData['views'] ?? []]))
            ]);
        }

        return $results;
    }

    /**
     * Process different schema types in parallel using PHP's parallel processing capabilities
     *
     * @param array $archiMateData Normalized ArchiMate data
     * @param array $options Conversion options
     * 
     * @return array Results for each schema type
     */
    private function processSchemasInParallel(array $archiMateData, array $options): array
    {
        $results = [
            'elements' => ['found' => 0, 'created' => 0, 'updated' => 0, 'deleted' => 0, 'skipped' => 0, 'errors' => [], 'processing_time' => 0],
            'organizations' => ['found' => 0, 'created' => 0, 'updated' => 0, 'deleted' => 0, 'skipped' => 0, 'errors' => [], 'processing_time' => 0],
            'relationships' => ['found' => 0, 'created' => 0, 'updated' => 0, 'deleted' => 0, 'skipped' => 0, 'errors' => [], 'processing_time' => 0],
            'views' => ['found' => 0, 'created' => 0, 'updated' => 0, 'deleted' => 0, 'skipped' => 0, 'errors' => [], 'processing_time' => 0]
        ];

        // Process different types of ArchiMate elements in parallel using async processing
        $promises = [];

        if (!empty($archiMateData['elements'])) {
            $this->logger->info('Starting parallel processing of elements', ['count' => count($archiMateData['elements'])]);
            $promises['elements'] = $this->processArchiMateElementsAsync($archiMateData['elements'], $options);
        }

        if (!empty($archiMateData['organizations'])) {
            $this->logger->info('Starting parallel processing of organizations', ['count' => count($archiMateData['organizations'])]);
            $promises['organizations'] = $this->processArchiMateOrganizationsAsync($archiMateData['organizations'], $options);
        }

        if (!empty($archiMateData['relationships'])) {
            $this->logger->info('Starting parallel processing of relationships', ['count' => count($archiMateData['relationships'])]);
            $promises['relationships'] = $this->processArchiMateRelationshipsAsync($archiMateData['relationships'], $options);
        }

        if (!empty($archiMateData['views'])) {
            $this->logger->info('Starting parallel processing of views', ['count' => count($archiMateData['views'])]);
            $promises['views'] = $this->processArchiMateViewsAsync($archiMateData['views'], $options);
        }

        // Wait for all promises to complete
        foreach ($promises as $schemaType => $promise) {
            $schemaStart = microtime(true);
            $results[$schemaType] = $this->waitForPromise($promise);
            $schemaTime = microtime(true) - $schemaStart;
            $results[$schemaType]['processing_time'] = $schemaTime;
            
            $this->logger->info("Completed processing of {$schemaType}", [
                'processing_time_seconds' => round($schemaTime, 3),
                'created' => $results[$schemaType]['created'],
                'updated' => $results[$schemaType]['updated'],
                'deleted' => $results[$schemaType]['deleted'] ?? 0,
                'skipped' => $results[$schemaType]['skipped'] ?? 0,
                'errors' => count($results[$schemaType]['errors'])
            ]);
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
        $startTime = microtime(true);
        
        $results = [
            'objects_created' => 0,
            'objects_updated' => 0,
            'objects_deleted' => 0,
            'errors' => [],
            'processing_stats' => [
                'total_time' => 0,
                'method' => 'asynchronous'
            ],
            'schema_statistics' => [
                            'elements' => ['found' => 0, 'created' => 0, 'updated' => 0, 'deleted' => 0, 'skipped' => 0, 'errors' => []],
            'organizations' => ['found' => 0, 'created' => 0, 'updated' => 0, 'deleted' => 0, 'skipped' => 0, 'errors' => []],
            'relationships' => ['found' => 0, 'created' => 0, 'updated' => 0, 'deleted' => 0, 'skipped' => 0, 'errors' => []],
            'views' => ['found' => 0, 'created' => 0, 'updated' => 0, 'deleted' => 0, 'skipped' => 0, 'errors' => []]
            ]
        ];

        // Preload all existing objects to avoid individual DB queries during processing
        $preloadStart = microtime(true);
        $this->preloadExistingObjects();
        $preloadTime = microtime(true) - $preloadStart;

        $this->logger->info('Async processing started', [
            'preload_time_seconds' => round($preloadTime, 3),
            'cached_objects_loaded' => array_sum(array_map('count', $this->cachedObjects))
        ]);

        // Process different types of ArchiMate elements asynchronously
        $promises = [];

        if (!empty($archiMateData['elements'])) {
            $promises['elements'] = $this->processArchiMateElementsAsync($archiMateData['elements'], $options);
        }

        if (!empty($archiMateData['organizations'])) {
            $promises['organizations'] = $this->processArchiMateOrganizationsAsync($archiMateData['organizations'], $options);
        }

        if (!empty($archiMateData['relationships'])) {
            $promises['relationships'] = $this->processArchiMateRelationshipsAsync($archiMateData['relationships'], $options);
        }

        if (!empty($archiMateData['views'])) {
            $promises['views'] = $this->processArchiMateViewsAsync($archiMateData['views'], $options);
        }

        // Wait for all promises to complete
        foreach ($promises as $schemaType => $promise) {
            $schemaStart = microtime(true);
            $schemaResult = $this->waitForPromise($promise);
            $schemaTime = microtime(true) - $schemaStart;
            
            $results['objects_created'] += $schemaResult['created'];
            $results['objects_updated'] += $schemaResult['updated'];
            $results['objects_deleted'] += $schemaResult['deleted'] ?? 0;
            $results['errors'] = array_merge($results['errors'], $schemaResult['errors']);
            $results['schema_statistics'][$schemaType] = $schemaResult;
            
            $this->logger->info("Async processing completed for {$schemaType}", [
                'processing_time_seconds' => round($schemaTime, 3),
                'created' => $schemaResult['created'],
                'updated' => $schemaResult['updated'],
                'deleted' => $schemaResult['deleted'] ?? 0,
                'errors' => count($schemaResult['errors'])
            ]);
        }

        $totalTime = microtime(true) - $startTime;
        $results['processing_stats']['total_time'] = $totalTime;

        $this->logger->info('Async processing completed', [
            'total_time_seconds' => round($totalTime, 3),
            'objects_created' => $results['objects_created'],
            'objects_updated' => $results['objects_updated'],
            'objects_deleted' => $results['objects_deleted'],
            'total_errors' => count($results['errors'])
        ]);

        return $results;
    }

    /**
     * Process ArchiMate elements asynchronously with detailed timing
     *
     * @param array $elements Elements to process
     * @param array $options Processing options
     * 
     * @return Promise
     */
    private function processArchiMateElementsAsync(array $elements, array $options): Promise
    {
        $startTime = microtime(true);
        
        $this->logger->info('Starting async processing of elements', [
            'count' => count($elements),
            'batch_size' => $options['batch_size'] ?? 50
        ]);

        return new Promise(function (callable $resolve, callable $reject) use ($elements, $options, $startTime) {
            try {
                $batchSize = $options['batch_size'] ?? 50;
                $chunks = array_chunk($elements, $batchSize);
                
                $this->logger->info('Elements split into chunks', [
                    'total_chunks' => count($chunks),
                    'chunk_size' => $batchSize
                ]);

                $promise = $this->processChunksAsync($chunks, $options, 'elements');
                
                $promise->then(
                    function ($results) use ($resolve, $startTime) {
                        $totalTime = microtime(true) - $startTime;
                        $results['processing_time'] = $totalTime;
                        
                        $this->logger->info('Async elements processing completed', [
                            'processing_time_seconds' => round($totalTime, 3),
                            'created' => $results['created'],
                            'updated' => $results['updated'],
                            'deleted' => $results['deleted'] ?? 0,
                            'errors' => count($results['errors'])
                        ]);
                        
                        $resolve($results);
                    },
                    function ($error) use ($reject, $startTime) {
                        $totalTime = microtime(true) - $startTime;
                        $this->logger->error('Async elements processing failed', [
                            'processing_time_seconds' => round($totalTime, 3),
                            'error' => $error
                        ]);
                        $reject($error);
                    }
                );
            } catch (\Exception $e) {
                $totalTime = microtime(true) - $startTime;
                $this->logger->error('Async elements processing exception', [
                    'processing_time_seconds' => round($totalTime, 3),
                    'error' => $e->getMessage()
                ]);
                $reject($e);
            }
        });
    }

    /**
     * Process ArchiMate organizations asynchronously with detailed timing
     *
     * @param array $organizations Organizations to process
     * @param array $options Processing options
     * 
     * @return Promise
     */
    private function processArchiMateOrganizationsAsync(array $organizations, array $options): Promise
    {
        $startTime = microtime(true);
        
        $this->logger->info('Starting async processing of organizations', [
            'count' => count($organizations),
            'batch_size' => $options['batch_size'] ?? 50
        ]);

        return new Promise(function (callable $resolve, callable $reject) use ($organizations, $options, $startTime) {
            try {
                $batchSize = $options['batch_size'] ?? 50;
                $chunks = array_chunk($organizations, $batchSize);
                
                $this->logger->info('Organizations split into chunks', [
                    'total_chunks' => count($chunks),
                    'chunk_size' => $batchSize
                ]);

                $promise = $this->processChunksAsync($chunks, $options, 'organizations');
                
                $promise->then(
                    function ($results) use ($resolve, $startTime) {
                        $totalTime = microtime(true) - $startTime;
                        $results['processing_time'] = $totalTime;
                        
                        $this->logger->info('Async organizations processing completed', [
                            'processing_time_seconds' => round($totalTime, 3),
                            'created' => $results['created'],
                            'updated' => $results['updated'],
                            'deleted' => $results['deleted'] ?? 0,
                            'errors' => count($results['errors'])
                        ]);
                        
                        $resolve($results);
                    },
                    function ($error) use ($reject, $startTime) {
                        $totalTime = microtime(true) - $startTime;
                        $this->logger->error('Async organizations processing failed', [
                            'processing_time_seconds' => round($totalTime, 3),
                            'error' => $error
                        ]);
                        $reject($error);
                    }
                );
            } catch (\Exception $e) {
                $totalTime = microtime(true) - $startTime;
                $this->logger->error('Async organizations processing exception', [
                    'processing_time_seconds' => round($totalTime, 3),
                    'error' => $e->getMessage()
                ]);
                $reject($e);
            }
        });
    }

    /**
     * Process chunks asynchronously with detailed timing and performance monitoring
     *
     * @param array $chunks Array of chunks to process
     * @param array $options Processing options
     * @param string $type Type of data being processed
     * 
     * @return Promise
     */
    private function processChunksAsync(array $chunks, array $options, string $type): Promise
    {
        $startTime = microtime(true);
        $totalChunks = count($chunks);
        
        $this->logger->info("Starting async chunk processing for {$type}", [
            'total_chunks' => $totalChunks,
            'chunk_size' => $options['batch_size'] ?? 50,
            'total_items' => array_sum(array_map('count', $chunks))
        ]);

        return new Promise(function (callable $resolve, callable $reject) use ($chunks, $options, $type, $startTime, $totalChunks) {
            try {
                $results = ['created' => 0, 'updated' => 0, 'deleted' => 0, 'skipped' => 0, 'errors' => []];
                $completedChunks = 0;
                $chunkPromises = [];

                // Process each chunk asynchronously
                foreach ($chunks as $chunkIndex => $chunk) {
                    $chunkStart = microtime(true);
                    
                    $chunkPromise = new Promise(function (callable $chunkResolve, callable $chunkReject) use ($chunk, $options, $type, $chunkIndex, $chunkStart) {
                        try {
                            $chunkResult = $this->processChunk($chunk, $options, $type);
                            $chunkTime = microtime(true) - $chunkStart;
                            
                            $this->logger->info("Chunk {$chunkIndex} completed for {$type}", [
                                'chunk_index' => $chunkIndex,
                                'chunk_size' => count($chunk),
                                'processing_time_seconds' => round($chunkTime, 3),
                                'created' => $chunkResult['created'],
                                'updated' => $chunkResult['updated'],
                                'deleted' => $chunkResult['deleted'] ?? 0,
                                'errors' => count($chunkResult['errors']),
                                'items_per_second' => round(count($chunk) / $chunkTime, 2)
                            ]);
                            
                            $chunkResolve($chunkResult);
                        } catch (\Exception $e) {
                            $chunkTime = microtime(true) - $chunkStart;
                            $this->logger->error("Chunk {$chunkIndex} failed for {$type}", [
                                'chunk_index' => $chunkIndex,
                                'processing_time_seconds' => round($chunkTime, 3),
                                'error' => $e->getMessage()
                            ]);
                            $chunkReject($e);
                        }
                    });

                    $chunkPromises[] = $chunkPromise;
                }

                // Wait for all chunks to complete
                $allChunksPromise = all($chunkPromises);
                
                $allChunksPromise->then(
                    function ($chunkResults) use ($resolve, $startTime, $totalChunks, $type) {
                        $totalTime = microtime(true) - $startTime;
                        $totalItems = 0;
                        $results = ['created' => 0, 'updated' => 0, 'deleted' => 0, 'skipped' => 0, 'errors' => []];
                        
                        foreach ($chunkResults as $chunkResult) {
                            $results['created'] += $chunkResult['created'];
                            $results['updated'] += $chunkResult['updated'];
                            $results['deleted'] += $chunkResult['deleted'] ?? 0;
                            $results['errors'] = array_merge($results['errors'], $chunkResult['errors']);
                            $totalItems += $chunkResult['processed'] ?? 0;
                        }
                        
                        $this->logger->info("All chunks completed for {$type}", [
                            'total_chunks' => $totalChunks,
                            'total_time_seconds' => round($totalTime, 3),
                            'total_items' => $totalItems,
                            'items_per_second' => round($totalItems / $totalTime, 2),
                            'chunks_per_second' => round($totalChunks / $totalTime, 2),
                            'created' => $results['created'],
                            'updated' => $results['updated'],
                            'deleted' => $results['deleted'],
                            'total_errors' => count($results['errors'])
                        ]);
                        
                        $resolve($results);
                    },
                    function ($error) use ($reject, $startTime, $type) {
                        $totalTime = microtime(true) - $startTime;
                        $this->logger->error("Chunk processing failed for {$type}", [
                            'total_time_seconds' => round($totalTime, 3),
                            'error' => $error
                        ]);
                        $reject($error);
                    }
                );
                
            } catch (\Exception $e) {
                $totalTime = microtime(true) - $startTime;
                $this->logger->error("Chunk processing exception for {$type}", [
                    'total_time_seconds' => round($totalTime, 3),
                    'error' => $e->getMessage()
                ]);
                $reject($e);
            }
        });
    }

    /**
     * Process a single chunk of data with detailed timing and bulk operations
     *
     * @param array $chunk Chunk of data to process
     * @param array $options Processing options
     * @param string $type Type of data being processed
     * 
     * @return array Processing results
     */
    private function processChunk(array $chunk, array $options, string $type): array
    {
        $startTime = microtime(true);
        $results = ['created' => 0, 'updated' => 0, 'deleted' => 0, 'skipped' => 0, 'errors' => [], 'processed' => count($chunk)];

        // Process items individually
        foreach ($chunk as $item) {
            $itemStart = microtime(true);
            
            try {
                $objectData = $this->mapItemToObject($item, $type);
                $saveResult = $this->createOrUpdateObject($objectData, $options);
                
                $itemTime = microtime(true) - $itemStart;
                
                if ($saveResult['created']) {
                    $results['created']++;
                } elseif ($saveResult['updated']) {
                    $results['updated']++;
                } elseif ($saveResult['deleted']) {
                    $results['deleted']++;
                } elseif ($saveResult['skipped']) {
                    $results['skipped']++;
                }
                
                // Log slow items for performance analysis
                if ($itemTime > 1.0) { // Items taking more than 1 second
                    $this->logger->warning("Slow item processing detected", [
                        'type' => $type,
                        'item_id' => $item['identifier'] ?? 'unknown',
                        'processing_time_seconds' => round($itemTime, 3),
                        'action' => $saveResult['created'] ? 'created' : ($saveResult['updated'] ? 'updated' : 'no_change')
                    ]);
                }
                
            } catch (\Exception $e) {
                $itemTime = microtime(true) - $itemStart;
                $results['errors'][] = "Failed to process {$type} item: " . $e->getMessage();
                
                $this->logger->error("Item processing failed", [
                    'type' => $type,
                    'item_id' => $item['identifier'] ?? 'unknown',
                    'processing_time_seconds' => round($itemTime, 3),
                    'error' => $e->getMessage()
                ]);
            }
        }

        $chunkTime = microtime(true) - $startTime;
        
        // Log chunk performance metrics
        if ($chunkTime > 5.0) { // Chunks taking more than 5 seconds
            $this->logger->warning("Slow chunk processing detected", [
                'type' => $type,
                'chunk_size' => count($chunk),
                'processing_time_seconds' => round($chunkTime, 3),
                'items_per_second' => round(count($chunk) / $chunkTime, 2),
                'created' => $results['created'],
                'updated' => $results['updated'],
                'errors' => count($results['errors'])
            ]);
        }

        return $results;
    }

    /**
     * Map item to object based on type
     *
     * @param array $item Item data
     * @param string $type Item type
     * 
     * @return array Object data
     */
    private function mapItemToObject(array $item, string $type): array
    {
        switch ($type) {
            case 'elements':
                return $this->mapElementToObject($item);
            case 'organizations':
                return $this->mapOrganizationToObject($item);
            case 'relationships':
                return $this->mapRelationshipToObject($item);
            case 'views':
                return $this->mapViewToObject($item);
            default:
                throw new \InvalidArgumentException("Unknown item type: {$type}");
        }
    }

    /**
     * Wait for a promise to complete with timeout and performance monitoring
     *
     * @param Promise $promise Promise to wait for
     * @param int $timeout Timeout in seconds
     * 
     * @return mixed Promise result
     */
    private function waitForPromise(Promise $promise, int $timeout = 300): mixed
    {
        $startTime = microtime(true);
        $result = null;
        $resolved = false;
        $error = null;

        $promise->then(
            function ($value) use (&$result, &$resolved) {
                $result = $value;
                $resolved = true;
            },
            function ($reason) use (&$error, &$resolved) {
                $error = $reason;
                $resolved = true;
            }
        );

        // Simple polling mechanism with timeout
        $timeoutTime = $startTime + $timeout;
        while (!$resolved && microtime(true) < $timeoutTime) {
            usleep(10000); // 10ms sleep
        }

        $waitTime = microtime(true) - $startTime;

        if (!$resolved) {
            $this->logger->error('Promise timeout exceeded', [
                'timeout_seconds' => $timeout,
                'actual_wait_time_seconds' => round($waitTime, 3)
            ]);
            throw new \RuntimeException("Promise timeout exceeded after {$timeout} seconds");
        }

        if ($error !== null) {
            $this->logger->error('Promise rejected', [
                'wait_time_seconds' => round($waitTime, 3),
                'error' => $error
            ]);
            throw $error;
        }

        $this->logger->debug('Promise resolved successfully', [
            'wait_time_seconds' => round($waitTime, 3)
        ]);

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
        $results = ['found' => count($elements), 'created' => 0, 'updated' => 0, 'deleted' => 0, 'skipped' => 0, 'errors' => []];

        foreach ($elements as $element) {
            try {
                // Map ArchiMate element to OpenRegister object structure
                $objectData = $this->mapElementToObject($element);
                
                // Create or update object in OpenRegister
                $result = $this->createOrUpdateObject($objectData, $options);
                
                if ($result['created']) {
                    $results['created']++;
                } else if ($result['updated']) {
                    $results['updated']++;
                } else if ($result['deleted']) {
                    $results['deleted']++;
                } else if ($result['skipped']) {
                    $results['skipped']++;
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
        $results = ['found' => count($organizations), 'created' => 0, 'updated' => 0, 'deleted' => 0, 'skipped' => 0, 'errors' => []];

        foreach ($organizations as $organization) {
            try {
                // Map ArchiMate organization to OpenRegister object structure
                $objectData = $this->mapOrganizationToObject($organization);
                
                // Create or update object in OpenRegister
                $result = $this->createOrUpdateObject($objectData, $options);
                
                if ($result['created']) {
                    $results['created']++;
                } else if ($result['updated']) {
                    $results['updated']++;
                } else if ($result['deleted']) {
                    $results['deleted']++;
                } else if ($result['skipped']) {
                    $results['skipped']++;
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
        $results = ['found' => count($relationships), 'created' => 0, 'updated' => 0, 'deleted' => 0, 'skipped' => 0, 'errors' => []];

        foreach ($relationships as $relationship) {
            try {
                // Map ArchiMate relationship to OpenRegister object structure
                $objectData = $this->mapRelationshipToObject($relationship);
                
                // Create or update object in OpenRegister
                $result = $this->createOrUpdateObject($objectData, $options);
                
                if ($result['created']) {
                    $results['created']++;
                } else if ($result['updated']) {
                    $results['updated']++;
                } else if ($result['deleted']) {
                    $results['deleted']++;
                } else if ($result['skipped']) {
                    $results['skipped']++;
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
        $results = ['found' => count($views), 'created' => 0, 'updated' => 0, 'deleted' => 0, 'skipped' => 0, 'errors' => []];

        foreach ($views as $view) {
            try {
                // Map ArchiMate view to OpenRegister object structure
                $objectData = $this->mapViewToObject($view);
                
                // Create or update object in OpenRegister
                $result = $this->createOrUpdateObject($objectData, $options);
                
                if ($result['created']) {
                    $results['created']++;
                } else if ($result['updated']) {
                    $results['updated']++;
                } else if ($result['deleted']) {
                    $results['deleted']++;
                } else if ($result['skipped']) {
                    $results['skipped']++;
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
        $chunkSize = 10;
        $chunks = array_chunk($relationships, $chunkSize);
        $results = ['created' => 0, 'updated' => 0, 'deleted' => 0, 'skipped' => 0, 'errors' => []];
        
        $this->processChunksAsync($chunks, $options, 'relationship')
            ->then(function($chunkResults) use ($deferred, &$results) {
                foreach ($chunkResults as $chunkResult) {
                    $results['created'] += $chunkResult['created'] ?? 0;
                    $results['updated'] += $chunkResult['updated'] ?? 0;
                    $results['deleted'] += $chunkResult['deleted'] ?? 0;
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
        $chunkSize = 10;
        $chunks = array_chunk($views, $chunkSize);
        $results = ['created' => 0, 'updated' => 0, 'deleted' => 0, 'skipped' => 0, 'errors' => []];
        
        $this->processChunksAsync($chunks, $options, 'view')
            ->then(function($chunkResults) use ($deferred, &$results) {
                foreach ($chunkResults as $chunkResult) {
                    $results['created'] += $chunkResult['created'] ?? 0;
                    $results['updated'] += $chunkResult['updated'] ?? 0;
                    $results['deleted'] += $chunkResult['deleted'] ?? 0;
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
            'nameLanguage' => $relationship['nameLanguage'] ?? 'en',
            'type' => 'Relatie',
            'beschrijving' => $relationship['documentation'] ?? '',
            'descriptionLanguage' => $relationship['documentationLanguage'] ?? 'en',
            'archiMateId' => $archiMateId, // Keep original ID for reference
            'archiMateType' => $relationship['type'] ?? 'unknown',
            'sourceId' => $relationship['source'] ?? null,
            'targetId' => $relationship['target'] ?? null,
            'accessType' => $relationship['accessType'] ?? null, // Important for Access relationships
            'properties' => $relationship['properties'] ?? [],
            'metadata' => [
                'importedAt' => date('c'),
                'sourceFile' => $relationship['_sourceFile'] ?? null,
                'relationshipType' => $relationship['type'] ?? 'unknown',
                'accessType' => $relationship['accessType'] ?? null,
                'nameLanguage' => $relationship['nameLanguage'] ?? 'en',
                'descriptionLanguage' => $relationship['documentationLanguage'] ?? 'en'
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
            'nameLanguage' => $view['nameLanguage'] ?? 'en',
            'type' => 'Overzicht',
            'beschrijving' => $view['documentation'] ?? '',
            'descriptionLanguage' => $view['documentationLanguage'] ?? 'en',
            'archiMateId' => $archiMateId, // Keep original ID for reference
            'archiMateType' => $view['type'] ?? 'view',
            'viewpoint' => $view['viewpoint'] ?? null,
            'nodes' => $view['nodes'] ?? [], // Include all node data with positioning and styles
            'connections' => $view['connections'] ?? [],
            'properties' => $view['properties'] ?? [],
            'metadata' => [
                'importedAt' => date('c'),
                'sourceFile' => $view['_sourceFile'] ?? null,
                'viewType' => $view['type'] ?? 'view',
                'nameLanguage' => $view['nameLanguage'] ?? 'en',
                'descriptionLanguage' => $view['documentationLanguage'] ?? 'en',
                'nodeCount' => count($view['nodes'] ?? []),
                'connectionCount' => count($view['connections'] ?? [])
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
            'nameLanguage' => $element['nameLanguage'] ?? 'en',
            'type' => $this->mapArchiMateType($element['type'] ?? 'unknown'),
            'beschrijving' => $element['documentation'] ?? '',
            'descriptionLanguage' => $element['documentationLanguage'] ?? 'en',
            'archiMateId' => $archiMateId, // Keep original ID for reference
            'archiMateType' => $element['type'] ?? 'unknown',
            'properties' => $element['properties'] ?? [],
            'metadata' => [
                'importedAt' => date('c'),
                'sourceFile' => $element['_sourceFile'] ?? null,
                'elementType' => $element['type'] ?? 'unknown',
                'nameLanguage' => $element['nameLanguage'] ?? 'en',
                'descriptionLanguage' => $element['documentationLanguage'] ?? 'en'
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
            'nameLanguage' => $organization['nameLanguage'] ?? 'en',
            'type' => 'Leverancier',
            'beschrijving' => $organization['documentation'] ?? '',
            'descriptionLanguage' => $organization['documentationLanguage'] ?? 'en',
            'website' => $organization['website'] ?? '',
            'beoordeling' => 'Actief',
            'archiMateId' => $archiMateId, // Keep original ID for reference
            'contactpersonen' => $organization['contacts'] ?? [],
            'metadata' => [
                'importedAt' => date('c'),
                'sourceFile' => $organization['_sourceFile'] ?? null,
                'organizationType' => 'ArchiMate',
                'nameLanguage' => $organization['nameLanguage'] ?? 'en',
                'descriptionLanguage' => $organization['documentationLanguage'] ?? 'en'
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
     * Compare two objects to determine if they are equal
     *
     * @param array $existingObject Existing object from cache
     * @param array $newObjectData New object data to compare
     * 
     * @return bool True if objects are equal, false otherwise
     */
    private function areObjectsEqual(array $existingObject, array $newObjectData): bool
    {
        // Fields to ignore in comparison (metadata that changes on every save)
        $ignoreFields = ['updated', 'created', 'version', 'id', 'uuid', '_self', 'register', 'schema'];
        
        // Create normalized copies for comparison
        $existing = $this->normalizeObjectForComparison($existingObject, $ignoreFields);
        $new = $this->normalizeObjectForComparison($newObjectData, $ignoreFields);
        
        // Deep comparison of the normalized objects
        return $this->deepArrayCompare($existing, $new);
    }

    /**
     * Normalize object for comparison by removing ignored fields and sorting
     *
     * @param array $object Object to normalize
     * @param array $ignoreFields Fields to ignore
     * 
     * @return array Normalized object
     */
    private function normalizeObjectForComparison(array $object, array $ignoreFields): array
    {
        // Remove ignored fields
        foreach ($ignoreFields as $field) {
            unset($object[$field]);
        }
        
        // Recursively sort arrays for consistent comparison
        $this->sortArrayRecursively($object);
        
        return $object;
    }

    /**
     * Sort array recursively for consistent comparison
     *
     * @param array &$array Array to sort
     */
    private function sortArrayRecursively(array &$array): void
    {
        foreach ($array as &$value) {
            if (is_array($value)) {
                $this->sortArrayRecursively($value);
            }
        }
        
        // Only sort if array has string keys (associative array)
        if ($this->isAssociativeArray($array)) {
            ksort($array);
        }
    }

    /**
     * Check if array is associative
     *
     * @param array $array Array to check
     * 
     * @return bool True if associative, false if indexed
     */
    private function isAssociativeArray(array $array): bool
    {
        return array_keys($array) !== range(0, count($array) - 1);
    }

    /**
     * Deep comparison of two arrays
     *
     * @param array $array1 First array
     * @param array $array2 Second array
     * 
     * @return bool True if arrays are equal, false otherwise
     */
    private function deepArrayCompare(array $array1, array $array2): bool
    {
        // Convert to JSON for deep comparison (handles nested arrays/objects)
        $json1 = json_encode($array1, JSON_SORT_KEYS);
        $json2 = json_encode($array2, JSON_SORT_KEYS);
        
        return $json1 === $json2;
    }

    /**
     * Create or update an object in OpenRegister
     *
     * @param array $objectData Object data to create or update
     * @param array $options Processing options
     * 
     * @return array Result with created/updated status
     */
    private function createOrUpdateObject(array $objectData, array $options): array
    {
        $startTime = microtime(true);
        $result = ['created' => false, 'updated' => false, 'deleted' => false, 'skipped' => false, 'error' => null];

        try {
            $uuid = $objectData['id'] ?? null;
            if (!$uuid) {
                throw new \RuntimeException('Object ID is required for save operation');
            }

            // Check if object exists and compare data
            $objectType = $this->determineObjectType($objectData);
            $existingObject = $this->findObjectByArchiMateId($uuid, $objectType);
            
            if ($existingObject) {
                // Compare objects to see if update is needed
                if ($this->areObjectsEqual($existingObject, $objectData)) {
                    $result['skipped'] = true;
                    
                    $totalTime = microtime(true) - $startTime;
                    $this->logger->notice("Object unchanged, skipping update", [
                        'uuid' => $uuid,
                        'type' => $objectType,
                        'processing_time_seconds' => round($totalTime, 3)
                    ]);
                    
                    return $result;
                }
            }

            $objectService = $this->getObjectService();
            if (!$objectService) {
                throw new \RuntimeException('ObjectService not available');
            }

            // Determine schema and register
            $schemaId = $this->getSchemaIdForObjectType($objectData);
            $registerId = $this->getAmefRegisterId();

            if (!$schemaId || !$registerId) {
                throw new \RuntimeException("Schema ID ({$schemaId}) or Register ID ({$registerId}) not configured");
            }

            // Set context
            $objectService->setSchema($schemaId);
            $objectService->setRegister($registerId);

            $dbStart = microtime(true);
            
            // Save object using named parameters for clarity
            $saveResult = $objectService->saveObject(
                object: $objectData,
                extend: [],
                register: $registerId,
                schema: $schemaId,
                uuid: $uuid,
                rbac: false,
                multi: false
            );
            
            $dbTime = microtime(true) - $dbStart;

            // Determine if this was a create or update operation
            if ($saveResult && is_object($saveResult)) {
                if ($existingObject) {
                    $result['updated'] = true;
                } else {
                    $result['created'] = true;
                }
            }

            $totalTime = microtime(true) - $startTime;

            // Log performance metrics
            if ($dbTime > 0.5) { // Database operations taking more than 0.5 seconds
                $this->logger->warning('Slow database operation detected', [
                    'object_type' => $objectData['type'] ?? 'unknown',
                    'object_id' => $uuid,
                    'db_time_seconds' => round($dbTime, 3),
                    'total_time_seconds' => round($totalTime, 3),
                    'action' => $result['created'] ? 'created' : ($result['updated'] ? 'updated' : 'no_change'),
                    'schema_id' => $schemaId,
                    'register_id' => $registerId
                ]);
            }

            // Log very slow operations
            if ($totalTime > 2.0) { // Total operation taking more than 2 seconds
                $this->logger->error('Very slow object operation detected', [
                    'object_type' => $objectData['type'] ?? 'unknown',
                    'object_id' => $uuid,
                    'total_time_seconds' => round($totalTime, 3),
                    'db_time_seconds' => round($dbTime, 3),
                    'overhead_time_seconds' => round($totalTime - $dbTime, 3),
                    'action' => $result['created'] ? 'created' : ($result['updated'] ? 'updated' : 'no_change')
                ]);
            }

        } catch (\Exception $e) {
            $totalTime = microtime(true) - $startTime;
            $result['error'] = $e->getMessage();
            
            $this->logger->error('Object save operation failed', [
                'object_type' => $objectData['type'] ?? 'unknown',
                'object_id' => $objectData['id'] ?? 'unknown',
                'processing_time_seconds' => round($totalTime, 3),
                'error' => $e->getMessage(),
                'schema_id' => $schemaId ?? 'unknown',
                'register_id' => $registerId ?? 'unknown'
            ]);
        }

        return $result;
    }

    /**
     * Get the ObjectService from the container
     *
     * @return \OCA\OpenRegister\Service\ObjectService|null
     */
    private function getObjectService(): ?\OCA\OpenRegister\Service\ObjectService
    {
        try {
            // Check if OpenRegister app is available
            if (!$this->appManager->isInstalled('openregister')) {
                $this->logger->warning('OpenRegister app is not installed');
                return null;
            }

            // Get the ObjectService from the container
            $objectService = $this->container->get(\OCA\OpenRegister\Service\ObjectService::class);
            return $objectService;
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to get ObjectService', [
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Preload all existing objects to avoid individual DB queries during processing
     * 
     * @return void
     */
    private function preloadExistingObjects(): void
    {
        $startTime = microtime(true);
        
        if (!empty($this->cachedObjects)) {
            $this->logger->info('Using existing cached objects', [
                'cached_objects_count' => array_sum(array_map('count', $this->cachedObjects))
            ]);
            return;
        }

        $this->logger->info('Starting preload of existing objects');
        $this->initializeEmptyCache();

        $objectService = $this->getObjectService();
        if (!$objectService) {
            $this->logger->error('ObjectService not available for preloading');
            return;
        }

        $totalLoaded = 0;
        $schemaLoadTimes = [];

        // Preload objects for each schema type
        $schemas = [
            'elements' => $this->getArchiMateElementSchemaId(),
            'organizations' => $this->getOrganizationSchemaId(),
            'relationships' => $this->getRelationshipSchemaId(),
            'views' => $this->getViewSchemaId()
        ];

        foreach ($schemas as $schemaType => $schemaId) {
            if (!$schemaId) {
                $this->logger->warning("Schema ID not configured for {$schemaType}");
                continue;
            }

            $schemaStart = microtime(true);
            
            try {
                $objectService->setSchema($schemaId);
                $objects = $objectService->searchObjects([], false, false);
                
                $schemaTime = microtime(true) - $schemaStart;
                $schemaLoadTimes[$schemaType] = $schemaTime;
                
                $objectCount = count($objects);
                $totalLoaded += $objectCount;
                
                // Convert ObjectEntity objects to arrays
                $objectArrays = [];
                foreach ($objects as $object) {
                    $objectArrays[] = $object->jsonSerialize();
                }
                
                $this->cachedObjects[$schemaType] = $objectArrays;
                
                $this->logger->info("Preloaded {$schemaType} objects", [
                    'schema_id' => $schemaId,
                    'object_count' => $objectCount,
                    'load_time_seconds' => round($schemaTime, 3),
                    'objects_per_second' => round($objectCount / $schemaTime, 2)
                ]);
                
                // Log slow schema loads
                if ($schemaTime > 5.0) { // Schema loads taking more than 5 seconds
                    $this->logger->warning("Slow schema preload detected for {$schemaType}", [
                        'schema_id' => $schemaId,
                        'object_count' => $objectCount,
                        'load_time_seconds' => round($schemaTime, 3),
                        'objects_per_second' => round($objectCount / $schemaTime, 2)
                    ]);
                }
                
            } catch (\Exception $e) {
                $schemaTime = microtime(true) - $schemaStart;
                $this->logger->error("Failed to preload {$schemaType} objects", [
                    'schema_id' => $schemaId,
                    'load_time_seconds' => round($schemaTime, 3),
                    'error' => $e->getMessage()
                ]);
            }
        }

        $totalTime = microtime(true) - $startTime;
        
        $this->logger->info('Completed preload of existing objects', [
            'total_time_seconds' => round($totalTime, 3),
            'total_objects_loaded' => $totalLoaded,
            'objects_per_second' => round($totalLoaded / $totalTime, 2),
            'schema_load_times' => array_map(function($time) { return round($time, 3); }, $schemaLoadTimes),
            'cached_objects_by_schema' => array_map('count', $this->cachedObjects)
        ]);

        // Log performance summary
        if ($totalTime > 10.0) { // Total preload taking more than 10 seconds
            $this->logger->warning('Slow overall preload detected', [
                'total_time_seconds' => round($totalTime, 3),
                'total_objects_loaded' => $totalLoaded,
                'objects_per_second' => round($totalLoaded / $totalTime, 2),
                'slowest_schema' => array_search(max($schemaLoadTimes), $schemaLoadTimes),
                'slowest_schema_time' => round(max($schemaLoadTimes), 3)
            ]);
        }
    }

    /**
     * Initialize empty cache to prevent errors
     * 
     * @return void
     */
    private function initializeEmptyCache(): void
    {
        $this->cachedObjects = [
            'element' => [],
            'organization' => [],
            'relationship' => [],
            'view' => []
        ];
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
        try {
            $objectService = $this->getObjectService();
            if (!$objectService) {
                $this->logger->warning('ObjectService not available for export');
                return [];
            }

            $objects = [];
            $organizationSpecific = $criteria['organizationSpecific'] ?? false;
            $organizationId = $criteria['organizationId'] ?? null;
            $organizationFilter = $criteria['organizationFilter'] ?? null;
            $selectedSchemas = $criteria['selectedSchemas'] ?? [];
            $includeRelationships = $criteria['includeRelationships'] ?? true;
            $includeViews = $criteria['includeViews'] ?? false;

            // Get AMEF register ID
            $registerId = $this->getAmefRegisterId();
            if (!$registerId) {
                $this->logger->warning('No AMEF register ID configured for export');
                return [];
            }

            // Get all schemas in the AMEF register
            $allSchemas = [
                'element' => $this->getArchiMateElementSchemaId(),
                'relationship' => $this->getRelationshipSchemaId(),
                'view' => $this->getViewSchemaId()
            ];

            // Filter schemas based on selection
            $schemas = [];
            if (empty($selectedSchemas)) {
                // If no schemas selected, use all available schemas
                $schemas = $allSchemas;
            } else {
                // Use only selected schemas
                foreach ($allSchemas as $type => $schemaId) {
                    if ($schemaId && in_array($schemaId, $selectedSchemas)) {
                        $schemas[$type] = $schemaId;
                    }
                }
            }

            // Get objects for each schema
            foreach ($schemas as $type => $schemaId) {
                if (!$schemaId) {
                    $this->logger->debug("No schema ID configured for type: $type");
                    continue;
                }

                try {
                    $objectService->setSchema($schemaId);
                    $schemaObjects = $objectService->searchObjects([], false, false); // rbac=false, multi=false
                    
                    // Convert ObjectEntity objects to arrays if needed
                    if (is_array($schemaObjects)) {
                        $convertedObjects = [];
                        foreach ($schemaObjects as $obj) {
                            $objArray = is_object($obj) ? $obj->jsonSerialize() : $obj;
                            $convertedObjects[] = $objArray;
                        }
                        $schemaObjects = $convertedObjects;
                    }
                    
                    // Filter by organization if specified
                    if (is_array($schemaObjects)) {
                        $schemaObjects = array_filter($schemaObjects, function($obj) use ($organizationSpecific, $organizationId, $organizationFilter) {
                            // Organization-specific mode: filter by exact organization ID
                            if ($organizationSpecific && $organizationId) {
                                $objOrgId = $obj['organizationId'] ?? $obj['organisatieId'] ?? null;
                                return $objOrgId == $organizationId;
                            }
                            
                            // Organization filter mode: filter by organization name
                            if (!$organizationSpecific && $organizationFilter) {
                                $objOrgName = $obj['organizationName'] ?? $obj['organisatieNaam'] ?? $obj['naam'] ?? '';
                                return stripos($objOrgName, $organizationFilter) !== false;
                            }
                            
                            return true;
                        });
                    }

                    // Add type information to objects
                    if (is_array($schemaObjects)) {
                        foreach ($schemaObjects as &$obj) {
                            $obj['_type'] = $type;
                            $obj['_schemaId'] = $schemaId;
                        }
                        $objects = array_merge($objects, $schemaObjects);
                        $this->logger->debug("Loaded " . count($schemaObjects) . " objects for type: $type");
                    }
                    
                } catch (\Exception $e) {
                    $this->logger->warning("Failed to load objects for type: $type", [
                        'error' => $e->getMessage()
                    ]);
                }
            }

            $this->logger->info('Export objects loaded', [
                'total_objects' => count($objects),
                'organization_specific' => $organizationSpecific,
                'organization_id_filter' => $organizationId,
                'organization_name_filter' => $organizationFilter,
                'selected_schemas' => $selectedSchemas,
                'schemas_used' => array_values($schemas),
                'include_relationships' => $includeRelationships,
                'include_views' => $includeViews
            ]);

            return $objects;

        } catch (\Exception $e) {
            $this->logger->error('Failed to get objects for export', [
                'error' => $e->getMessage(),
                'criteria' => $criteria
            ]);
            return [];
        }
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
        $archiMateData = [
            'model' => [
                'name' => 'Software Catalog Export',
                'identifier' => 'sc-' . uniqid(),
                'documentation' => 'Exported from Software Catalog',
                'properties' => []
            ],
            'elements' => [],
            'relationships' => [],
            'organizations' => [],
            'views' => []
        ];

        foreach ($objects as $object) {
            $type = $object['_type'] ?? 'element';
            
            switch ($type) {
                case 'element':
                    $archiMateData['elements'][] = $this->convertObjectToArchiMateElement($object);
                    break;
                    
                case 'relationship':
                    $archiMateData['relationships'][] = $this->convertObjectToArchiMateRelationship($object);
                    break;
                    
                case 'view':
                    $archiMateData['views'][] = $this->convertObjectToArchiMateView($object);
                    break;
                    
                case 'organization':
                    $archiMateData['organizations'][] = $this->convertObjectToArchiMateOrganization($object);
                    break;
                    
                default:
                    $this->logger->warning('Unknown object type for ArchiMate conversion', [
                        'type' => $type,
                        'object_id' => $object['id'] ?? 'unknown'
                    ]);
                    break;
            }
        }

        $this->logger->info('Converted OpenRegister objects to ArchiMate format', [
            'total_objects' => count($objects),
            'elements' => count($archiMateData['elements']),
            'relationships' => count($archiMateData['relationships']),
            'views' => count($archiMateData['views']),
            'organizations' => count($archiMateData['organizations'])
        ]);

        return $archiMateData;
    }

    /**
     * Convert OpenRegister object to ArchiMate element
     *
     * @param array $object OpenRegister object
     * 
     * @return array ArchiMate element
     */
    private function convertObjectToArchiMateElement(array $object): array
    {
        $element = [
            'identifier' => $object['id'] ?? $object['archiMateId'] ?? uniqid(),
            'xsi:type' => $object['archiMateType'] ?? 'archimate:Element',
            'name' => [
                '_value' => $object['naam'] ?? $object['name'] ?? 'Unnamed Element'
            ],
            'documentation' => [
                '_value' => $object['beschrijving'] ?? $object['description'] ?? ''
            ],
            'properties' => $this->convertPropertiesToArchiMate($object['properties'] ?? [])
        ];

        // Add language information if available
        if (isset($object['nameLanguage'])) {
            $element['name']['xml:lang'] = $object['nameLanguage'];
        }
        if (isset($object['descriptionLanguage'])) {
            $element['documentation']['xml:lang'] = $object['descriptionLanguage'];
        }

        return $element;
    }

    /**
     * Convert OpenRegister object to ArchiMate relationship
     *
     * @param array $object OpenRegister object
     * 
     * @return array ArchiMate relationship
     */
    private function convertObjectToArchiMateRelationship(array $object): array
    {
        $relationship = [
            'identifier' => $object['id'] ?? $object['archiMateId'] ?? uniqid(),
            'xsi:type' => $object['archiMateType'] ?? 'archimate:Relationship',
            'source' => $object['sourceId'] ?? $object['source'] ?? '',
            'target' => $object['targetId'] ?? $object['target'] ?? '',
            'name' => [
                '_value' => $object['naam'] ?? $object['name'] ?? 'Unnamed Relationship'
            ],
            'documentation' => [
                '_value' => $object['beschrijving'] ?? $object['description'] ?? ''
            ],
            'properties' => $this->convertPropertiesToArchiMate($object['properties'] ?? [])
        ];

        // Add language information if available
        if (isset($object['nameLanguage'])) {
            $relationship['name']['xml:lang'] = $object['nameLanguage'];
        }
        if (isset($object['descriptionLanguage'])) {
            $relationship['documentation']['xml:lang'] = $object['descriptionLanguage'];
        }

        // Add accessType if available (important for Access relationships)
        if (isset($object['accessType'])) {
            $relationship['accessType'] = $object['accessType'];
        }

        return $relationship;
    }

    /**
     * Convert OpenRegister object to ArchiMate view
     *
     * @param array $object OpenRegister object
     * 
     * @return array ArchiMate view
     */
    private function convertObjectToArchiMateView(array $object): array
    {
        $view = [
            'identifier' => $object['id'] ?? $object['archiMateId'] ?? uniqid(),
            'xsi:type' => $object['archiMateType'] ?? 'archimate:View',
            'viewpoint' => $object['viewpoint'] ?? $object['viewType'] ?? 'archimate:Viewpoint',
            'name' => [
                '_value' => $object['naam'] ?? $object['name'] ?? 'Unnamed View'
            ],
            'documentation' => [
                '_value' => $object['beschrijving'] ?? $object['description'] ?? ''
            ],
            'properties' => $this->convertPropertiesToArchiMate($object['properties'] ?? [])
        ];

        // Add language information if available
        if (isset($object['nameLanguage'])) {
            $view['name']['xml:lang'] = $object['nameLanguage'];
        }
        if (isset($object['descriptionLanguage'])) {
            $view['documentation']['xml:lang'] = $object['descriptionLanguage'];
        }

        // Add nodes and connections if available (critical for view structure)
        if (isset($object['nodes']) && !empty($object['nodes'])) {
            $view['nodes'] = $object['nodes'];
        }
        if (isset($object['connections']) && !empty($object['connections'])) {
            $view['connections'] = $object['connections'];
        }

        return $view;
    }

    /**
     * Convert OpenRegister object to ArchiMate organization
     *
     * @param array $object OpenRegister object
     * 
     * @return array ArchiMate organization
     */
    private function convertObjectToArchiMateOrganization(array $object): array
    {
        $organization = [
            'identifier' => $object['id'] ?? $object['archiMateId'] ?? uniqid(),
            'xsi:type' => 'archimate:BusinessActor',
            'name' => [
                '_value' => $object['naam'] ?? $object['name'] ?? 'Unnamed Organization'
            ],
            'documentation' => [
                '_value' => $object['beschrijving'] ?? $object['description'] ?? ''
            ],
            'properties' => $this->convertPropertiesToArchiMate($object['properties'] ?? [])
        ];

        // Add language information if available
        if (isset($object['nameLanguage'])) {
            $organization['name']['xml:lang'] = $object['nameLanguage'];
        }
        if (isset($object['descriptionLanguage'])) {
            $organization['documentation']['xml:lang'] = $object['descriptionLanguage'];
        }

        // Add website if available
        if (isset($object['website'])) {
            $organization['website'] = $object['website'];
        }

        // Add contact persons if available
        if (isset($object['contactpersonen']) && !empty($object['contactpersonen'])) {
            $organization['contacts'] = $object['contactpersonen'];
        }

        return $organization;
    }

    /**
     * Convert OpenRegister properties to ArchiMate properties format
     *
     * @param array $properties OpenRegister properties
     * 
     * @return array ArchiMate properties
     */
    private function convertPropertiesToArchiMate(array $properties): array
    {
        $archiMateProperties = [];
        
        foreach ($properties as $property) {
            if (is_array($property) && isset($property['definitionRef'])) {
                $archiMateProperties[] = [
                    'propertyDefinitionRef' => $property['definitionRef'],
                    'value' => [
                        '_value' => $property['value'] ?? '',
                        'xml:lang' => $property['language'] ?? 'en'
                    ]
                ];
            }
        }
        
        return $archiMateProperties;
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
        // Create root element with proper ArchiMate namespace
        $xml = new \SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><model xmlns="http://www.opengroup.org/xsd/archimate" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="http://www.opengroup.org/xsd/archimate http://www.opengroup.org/xsd/archimate/archimate_v3p0.xsd"></model>');
        
        // Add model attributes
        $xml->addAttribute('identifier', $data['model']['identifier'] ?? 'sc-export');
        $xml->addAttribute('name', $data['model']['name'] ?? 'Software Catalog Export');
        
        // Add model documentation if present
        if (!empty($data['model']['documentation'])) {
            $xml->addChild('documentation', $data['model']['documentation']);
        }

        // Add elements
        if (!empty($data['elements'])) {
            $elementsElement = $xml->addChild('elements');
            foreach ($data['elements'] as $element) {
                $this->addElementToXml($elementsElement, $element);
            }
        }

        // Add relationships
        if (!empty($data['relationships'])) {
            $relationshipsElement = $xml->addChild('relationships');
            foreach ($data['relationships'] as $relationship) {
                $this->addRelationshipToXml($relationshipsElement, $relationship);
            }
        }

        // Add views
        if (!empty($data['views'])) {
            $viewsElement = $xml->addChild('views');
            foreach ($data['views'] as $view) {
                $this->addViewToXml($viewsElement, $view);
            }
        }

        // Add organizations (as elements with specific types)
        if (!empty($data['organizations'])) {
            if (!isset($xml->elements)) {
                $xml->addChild('elements');
            }
            foreach ($data['organizations'] as $organization) {
                $this->addElementToXml($xml->elements, $organization);
            }
        }

        return $xml->asXML();
    }

    /**
     * Add element to XML
     *
     * @param \SimpleXMLElement $parent Parent XML element
     * @param array $element Element data
     * 
     * @return void
     */
    private function addElementToXml(\SimpleXMLElement $parent, array $element): void
    {
        $elementXml = $parent->addChild('element');
        
        // Add attributes
        if (isset($element['identifier'])) {
            $elementXml->addAttribute('identifier', $element['identifier']);
        }
        if (isset($element['xsi:type'])) {
            $elementXml->addAttribute('xsi:type', $element['xsi:type']);
        }
        
        // Add name with language attribute if available
        if (isset($element['name']['_value'])) {
            $nameXml = $elementXml->addChild('name', $element['name']['_value']);
            if (isset($element['name']['xml:lang'])) {
                $nameXml->addAttribute('xml:lang', $element['name']['xml:lang']);
            }
        }
        
        // Add documentation with language attribute if available
        if (isset($element['documentation']['_value'])) {
            $docXml = $elementXml->addChild('documentation', $element['documentation']['_value']);
            if (isset($element['documentation']['xml:lang'])) {
                $docXml->addAttribute('xml:lang', $element['documentation']['xml:lang']);
            }
        }
        
        // Add properties
        if (!empty($element['properties'])) {
            $propertiesXml = $elementXml->addChild('properties');
            foreach ($element['properties'] as $property) {
                $this->addPropertyToXml($propertiesXml, $property);
            }
        }
    }

    /**
     * Add relationship to XML
     *
     * @param \SimpleXMLElement $parent Parent XML element
     * @param array $relationship Relationship data
     * 
     * @return void
     */
    private function addRelationshipToXml(\SimpleXMLElement $parent, array $relationship): void
    {
        $relationshipXml = $parent->addChild('relationship');
        
        // Add attributes
        if (isset($relationship['identifier'])) {
            $relationshipXml->addAttribute('identifier', $relationship['identifier']);
        }
        if (isset($relationship['xsi:type'])) {
            $relationshipXml->addAttribute('xsi:type', $relationship['xsi:type']);
        }
        if (isset($relationship['source'])) {
            $relationshipXml->addAttribute('source', $relationship['source']);
        }
        if (isset($relationship['target'])) {
            $relationshipXml->addAttribute('target', $relationship['target']);
        }
        // Add accessType attribute if available
        if (isset($relationship['accessType'])) {
            $relationshipXml->addAttribute('accessType', $relationship['accessType']);
        }
        
        // Add name with language attribute if available
        if (isset($relationship['name']['_value'])) {
            $nameXml = $relationshipXml->addChild('name', $relationship['name']['_value']);
            if (isset($relationship['name']['xml:lang'])) {
                $nameXml->addAttribute('xml:lang', $relationship['name']['xml:lang']);
            }
        }
        
        // Add documentation with language attribute if available
        if (isset($relationship['documentation']['_value'])) {
            $docXml = $relationshipXml->addChild('documentation', $relationship['documentation']['_value']);
            if (isset($relationship['documentation']['xml:lang'])) {
                $docXml->addAttribute('xml:lang', $relationship['documentation']['xml:lang']);
            }
        }
        
        // Add properties
        if (!empty($relationship['properties'])) {
            $propertiesXml = $relationshipXml->addChild('properties');
            foreach ($relationship['properties'] as $property) {
                $this->addPropertyToXml($propertiesXml, $property);
            }
        }
    }

    /**
     * Add view to XML
     *
     * @param \SimpleXMLElement $parent Parent XML element
     * @param array $view View data
     * 
     * @return void
     */
    private function addViewToXml(\SimpleXMLElement $parent, array $view): void
    {
        $viewXml = $parent->addChild('view');
        
        // Add attributes
        if (isset($view['identifier'])) {
            $viewXml->addAttribute('identifier', $view['identifier']);
        }
        if (isset($view['xsi:type'])) {
            $viewXml->addAttribute('xsi:type', $view['xsi:type']);
        }
        if (isset($view['viewpoint'])) {
            $viewXml->addAttribute('viewpoint', $view['viewpoint']);
        }
        
        // Add name with language attribute if available
        if (isset($view['name']['_value'])) {
            $nameXml = $viewXml->addChild('name', $view['name']['_value']);
            if (isset($view['name']['xml:lang'])) {
                $nameXml->addAttribute('xml:lang', $view['name']['xml:lang']);
            }
        }
        
        // Add documentation with language attribute if available
        if (isset($view['documentation']['_value'])) {
            $docXml = $viewXml->addChild('documentation', $view['documentation']['_value']);
            if (isset($view['documentation']['xml:lang'])) {
                $docXml->addAttribute('xml:lang', $view['documentation']['xml:lang']);
            }
        }
        
        // Add properties
        if (!empty($view['properties'])) {
            $propertiesXml = $viewXml->addChild('properties');
            foreach ($view['properties'] as $property) {
                $this->addPropertyToXml($propertiesXml, $property);
            }
        }

        // Add nodes if available (critical for view structure)
        if (isset($view['nodes']) && !empty($view['nodes'])) {
            $nodesXml = $viewXml->addChild('nodes');
            foreach ($view['nodes'] as $node) {
                $this->addNodeToXml($nodesXml, $node);
            }
        }

        // Add connections if available
        if (isset($view['connections']) && !empty($view['connections'])) {
            $connectionsXml = $viewXml->addChild('connections');
            foreach ($view['connections'] as $connection) {
                $this->addConnectionToXml($connectionsXml, $connection);
            }
        }
    }

    /**
     * Add property to XML
     *
     * @param \SimpleXMLElement $parent Parent XML element
     * @param array $property Property data
     * 
     * @return void
     */
    private function addPropertyToXml(\SimpleXMLElement $parent, array $property): void
    {
        $propertyXml = $parent->addChild('property');
        
        // Add attributes
        if (isset($property['propertyDefinitionRef'])) {
            $propertyXml->addAttribute('propertyDefinitionRef', $property['propertyDefinitionRef']);
        }
        
        // Add value
        if (isset($property['value'])) {
            $valueXml = $propertyXml->addChild('value', $property['value']['_value'] ?? '');
            if (isset($property['value']['xml:lang'])) {
                $valueXml->addAttribute('xml:lang', $property['value']['xml:lang']);
            }
        }
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

    /**
     * Get schema ID for object type
     *
     * @param array $objectData Object data
     * 
     * @return int Schema ID
     */
    private function getSchemaIdForObjectType(array $objectData): int
    {
        $objectType = $this->determineObjectType($objectData);
        
        switch ($objectType) {
            case 'element':
                return $this->getArchiMateElementSchemaId() ?? 1;
            case 'relationship':
                return $this->getRelationshipSchemaId() ?? 2;
            case 'view':
                return $this->getViewSchemaId() ?? 3;
            case 'organization':
                return $this->getOrganizationSchemaId() ?? 4;
            default:
                return $this->getArchiMateElementSchemaId() ?? 1;
        }
    }

    /**
     * Import ArchiMate file from path with performance optimization
     *
     * @param array $options Import options including:
     *   - filePath: Path to the file
     *   - fileName: Name of the file
     *   - mimeType: MIME type of the file
     *   - updateExisting: Whether to update existing objects
     *   - preserveIds: Whether to preserve original IDs
     *   - batch_size: Number of items to process per batch (default: 50)
     *   - use_parallel: Whether to use parallel processing (default: true)
     * 
     * @return array Import results with detailed statistics
     */
    public function importArchiMateFileFromPath(array $options = []): array
    {
        $startTime = microtime(true);
        
        // Set default options
        $options = array_merge([
            'batch_size' => 50,
            'use_parallel' => true,
            'updateExisting' => true,
            'preserveIds' => true
        ], $options);

        $this->logger->info('Starting ArchiMate import from path', [
            'file_path' => $options['filePath'] ?? 'unknown',
            'file_name' => $options['fileName'] ?? 'unknown',
            'batch_size' => $options['batch_size'],
            'use_parallel' => $options['use_parallel'],
            'update_existing' => $options['updateExisting'],
            'preserve_ids' => $options['preserveIds']
        ]);

        try {
            // Validate file
            $validationStart = microtime(true);
            $this->validateArchiMateFileFromPath(
                $options['filePath'],
                $options['fileName'],
                $options['mimeType']
            );
            $validationTime = microtime(true) - $validationStart;

            // Parse file
            $parseStart = microtime(true);
            $archiMateData = $this->parseArchiMateFileFromPath($options['filePath'], $options);
            $parseTime = microtime(true) - $parseStart;

            // Convert to OpenRegister objects
            $convertStart = microtime(true);
            $convertResults = $this->convertToOpenRegisterObjects($archiMateData, $options);
            $convertTime = microtime(true) - $convertStart;

            $totalTime = microtime(true) - $startTime;

            $results = [
                'success' => true,
                'file_info' => [
                    'name' => $options['fileName'],
                    'size' => filesize($options['filePath']),
                    'mime_type' => $options['mimeType']
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
                'statistics' => $convertResults['schema_statistics'],
                'summary' => [
                    'total_objects_created' => $convertResults['objects_created'],
                    'total_objects_updated' => $convertResults['objects_updated'],
                    'total_objects_deleted' => $convertResults['objects_deleted'],
                    'total_errors' => count($convertResults['errors'])
                ],
                'performance_metrics' => [
                    'items_per_second' => $this->calculateItemsPerSecond($archiMateData, $totalTime),
                    'processing_method' => $options['use_parallel'] ? 'parallel' : 'sequential',
                    'batch_size_used' => $options['batch_size']
                ]
            ];

            $this->logger->info('ArchiMate import completed successfully', [
                'total_time_seconds' => round($totalTime, 3),
                'validation_time_seconds' => round($validationTime, 3),
                'parse_time_seconds' => round($parseTime, 3),
                'convert_time_seconds' => round($convertTime, 3),
                'objects_created' => $convertResults['objects_created'],
                'objects_updated' => $convertResults['objects_updated'],
                'objects_deleted' => $convertResults['objects_deleted'],
                'total_errors' => count($convertResults['errors']),
                'items_per_second' => $results['performance_metrics']['items_per_second']
            ]);

            return $results;

        } catch (\Exception $e) {
            $totalTime = microtime(true) - $startTime;
            
            $this->logger->error('ArchiMate import failed', [
                'total_time_seconds' => round($totalTime, 3),
                'error' => $e->getMessage(),
                'file_path' => $options['filePath'] ?? 'unknown'
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'processing_time_seconds' => round($totalTime, 3)
            ];
        }
    }

    /**
     * Calculate items processed per second
     *
     * @param array $archiMateData ArchiMate data
     * @param float $totalTime Total processing time
     * 
     * @return float Items per second
     */
    private function calculateItemsPerSecond(array $archiMateData, float $totalTime): float
    {
        $totalItems = count($archiMateData['elements'] ?? []) +
                     count($archiMateData['organizations'] ?? []) +
                     count($archiMateData['relationships'] ?? []) +
                     count($archiMateData['views'] ?? []);
        
        return $totalTime > 0 ? round($totalItems / $totalTime, 2) : 0;
    }

    /**
     * Validate ArchiMate file format from file path
     *
     * @param string $filePath Path to the file to validate
     * @param string $fileName Name of the file for logging
     * @param string $mimeType MIME type of the file
     * 
     * @throws \InvalidArgumentException If the file format is not supported
     */
    private function validateArchiMateFileFromPath(string $filePath, string $fileName, string $mimeType): void
    {
        // Check file size
        $fileSize = filesize($filePath);
        if ($fileSize === false || $fileSize === 0) {
            throw new \InvalidArgumentException('Invalid or empty file: ' . $fileName);
        }

        // Check if file is readable
        if (!is_readable($filePath)) {
            throw new \InvalidArgumentException('File is not readable: ' . $fileName);
        }

        // Read file content for validation
        $content = file_get_contents($filePath);
        if ($content === false) {
            throw new \InvalidArgumentException('Could not read file content: ' . $fileName);
        }

        // Validate content format
        if ($this->isJsonFormat($content)) {
            // JSON format validation
            $data = json_decode($content, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \InvalidArgumentException('Invalid JSON format in file: ' . $fileName);
            }
        } else {
            // XML format validation
            libxml_use_internal_errors(true);
            $xml = simplexml_load_string($content);
            if ($xml === false) {
                $errors = libxml_get_errors();
                libxml_clear_errors();
                throw new \InvalidArgumentException('Invalid XML format in file: ' . $fileName . '. Errors: ' . implode(', ', array_map(fn($e) => $e->message, $errors)));
            }
        }

        $this->logger->info('ArchiMate file validation passed', [
            'filename' => $fileName,
            'size' => $fileSize,
            'format' => $this->isJsonFormat($content) ? 'JSON' : 'XML'
        ]);
    }

    /**
     * Parse ArchiMate file content from file path
     *
     * @param string $filePath Path to the file to parse
     * @param array $options Additional parsing options
     * 
     * @return array Parsed ArchiMate data
     */
    private function parseArchiMateFileFromPath(string $filePath, array $options = []): array
    {
        $fileSize = filesize($filePath);
        $content = file_get_contents($filePath);
        
        if ($content === false) {
            throw new \RuntimeException('Could not read file content');
        }

        // Always use streaming parser for consistent memory management
        $this->logger->info('Using streaming parser for file', [
            'size' => $fileSize,
            'path' => $filePath
        ]);
        return $this->parseArchiMateFileStreamingFromPath($filePath, $options);
    }

    /**
     * Parse ArchiMate file using streaming XML parser from file path
     *
     * @param string $filePath Path to the file to parse
     * @param array $options Additional parsing options
     * 
     * @return array Parsed ArchiMate data
     */
    private function parseArchiMateFileStreamingFromPath(string $filePath, array $options = []): array
    {
        $reader = new \XMLReader();
        if (!$reader->open($filePath)) {
            throw new \RuntimeException('Could not open file for streaming parsing');
        }

        $archiMateData = [
            'elements' => [],
            'relationships' => [],
            'organizations' => [],
            'views' => []
        ];

        $currentElement = null;
        $elementStack = [];

        while ($reader->read()) {
            if ($reader->nodeType === \XMLReader::ELEMENT) {
                $nodeName = $reader->name;
                
                // Extract element attributes
                $attributes = [];
                if ($reader->hasAttributes) {
                    while ($reader->moveToNextAttribute()) {
                        $attributes[$reader->name] = $reader->value;
                    }
                    $reader->moveToElement();
                }

                // Process different element types
                switch ($nodeName) {
                    case 'element':
                        $elementData = $this->extractElementAttributes($reader);
                        $archiMateData['elements'][] = $elementData;
                        break;
                        
                    case 'relationship':
                        $relationshipData = $this->extractElementAttributes($reader);
                        $archiMateData['relationships'][] = $relationshipData;
                        break;
                        
                    case 'organization':
                        $organizationData = $this->extractElementAttributes($reader);
                        $archiMateData['organizations'][] = $organizationData;
                        break;
                        
                    case 'view':
                        $viewData = $this->extractElementAttributes($reader);
                        $archiMateData['views'][] = $viewData;
                        break;
                }
            }
        }

        $reader->close();
        
        $this->logger->info('Streaming parsing completed', [
            'elements' => count($archiMateData['elements']),
            'relationships' => count($archiMateData['relationships']),
            'organizations' => count($archiMateData['organizations']),
            'views' => count($archiMateData['views'])
        ]);

        return $this->normalizeArchiMateData($archiMateData);
    }



    /**
     * Add node to XML
     *
     * @param \SimpleXMLElement $parent Parent XML element
     * @param array $node Node data
     * 
     * @return void
     */
    private function addNodeToXml(\SimpleXMLElement $parent, array $node): void
    {
        $nodeXml = $parent->addChild('node');
        
        // Add attributes
        if (isset($node['id'])) {
            $nodeXml->addAttribute('id', $node['id']);
        }
        if (isset($node['type'])) {
            $nodeXml->addAttribute('type', $node['type']);
        }
        if (isset($node['elementRef'])) {
            $nodeXml->addAttribute('elementRef', $node['elementRef']);
        }
        if (isset($node['x'])) {
            $nodeXml->addAttribute('x', $node['x']);
        }
        if (isset($node['y'])) {
            $nodeXml->addAttribute('y', $node['y']);
        }
        if (isset($node['w'])) {
            $nodeXml->addAttribute('w', $node['w']);
        }
        if (isset($node['h'])) {
            $nodeXml->addAttribute('h', $node['h']);
        }
        
        // Add label with language if available
        if (isset($node['label']['_value'])) {
            $labelXml = $nodeXml->addChild('label', $node['label']['_value']);
            if (isset($node['label']['xml:lang'])) {
                $labelXml->addAttribute('xml:lang', $node['label']['xml:lang']);
            }
        }
        
        // Add style if available
        if (isset($node['style']) && !empty($node['style'])) {
            $styleXml = $nodeXml->addChild('style');
            if (isset($node['style']['fillColor'])) {
                $styleXml->addAttribute('fillColor', $node['style']['fillColor']);
            }
            if (isset($node['style']['lineColor'])) {
                $styleXml->addAttribute('lineColor', $node['style']['lineColor']);
            }
            if (isset($node['style']['font'])) {
                $styleXml->addAttribute('font', $node['style']['font']);
            }
        }
        
        // Add nested nodes if available
        if (isset($node['nodes']) && !empty($node['nodes'])) {
            foreach ($node['nodes'] as $nestedNode) {
                $this->addNodeToXml($nodeXml, $nestedNode);
            }
        }
    }

    /**
     * Add connection to XML
     *
     * @param \SimpleXMLElement $parent Parent XML element
     * @param array $connection Connection data
     * 
     * @return void
     */
    private function addConnectionToXml(\SimpleXMLElement $parent, array $connection): void
    {
        $connectionXml = $parent->addChild('connection');
        
        // Add attributes
        if (isset($connection['id'])) {
            $connectionXml->addAttribute('id', $connection['id']);
        }
        if (isset($connection['relationshipRef'])) {
            $connectionXml->addAttribute('relationshipRef', $connection['relationshipRef']);
        }
        if (isset($connection['source'])) {
            $connectionXml->addAttribute('source', $connection['source']);
        }
        if (isset($connection['target'])) {
            $connectionXml->addAttribute('target', $connection['target']);
        }
        
        // Add bendpoints if available
        if (isset($connection['bendpoints']) && !empty($connection['bendpoints'])) {
            $bendpointsXml = $connectionXml->addChild('bendpoints');
            foreach ($connection['bendpoints'] as $bendpoint) {
                $bendpointXml = $bendpointsXml->addChild('bendpoint');
                if (isset($bendpoint['x'])) {
                    $bendpointXml->addAttribute('x', $bendpoint['x']);
                }
                if (isset($bendpoint['y'])) {
                    $bendpointXml->addAttribute('y', $bendpoint['y']);
                }
            }
        }
    }

    /**
     * Delete orphaned objects that are no longer present in the imported ArchiMate data
     *
     * @param array $archiMateData The imported ArchiMate data
     * @param array $options Import options
     * 
     * @return array Results of orphaned object deletion
     */
    private function deleteOrphanedObjects(array $archiMateData, array $options): array
    {
        $startTime = microtime(true);
        $results = [
            'deleted' => 0,
            'errors' => []
        ];

        $this->logger->info('Starting orphaned object deletion', [
            'cached_objects_count' => array_sum(array_map('count', $this->cachedObjects))
        ]);

        // Extract all ArchiMate IDs from the imported data
        $importedArchiMateIds = [];
        
        // Collect IDs from elements
        foreach ($archiMateData['elements'] ?? [] as $element) {
            if (!empty($element['id'])) {
                $importedArchiMateIds[] = $element['id'];
            }
        }
        
        // Collect IDs from organizations
        foreach ($archiMateData['organizations'] ?? [] as $organization) {
            if (!empty($organization['id'])) {
                $importedArchiMateIds[] = $organization['id'];
            }
        }
        
        // Collect IDs from relationships
        foreach ($archiMateData['relationships'] ?? [] as $relationship) {
            if (!empty($relationship['id'])) {
                $importedArchiMateIds[] = $relationship['id'];
            }
        }
        
        // Collect IDs from views
        foreach ($archiMateData['views'] ?? [] as $view) {
            if (!empty($view['id'])) {
                $importedArchiMateIds[] = $view['id'];
            }
        }

        $importedArchiMateIds = array_unique($importedArchiMateIds);
        
        $this->logger->info('Collected imported ArchiMate IDs', [
            'imported_ids_count' => count($importedArchiMateIds)
        ]);

        // Get the object service
        $objectService = $this->getObjectService();
        if (!$objectService) {
            $error = 'Object service not available for orphaned object deletion';
            $this->logger->error($error);
            $results['errors'][] = $error;
            return $results;
        }

        // Process each object type and find orphaned objects
        foreach ($this->cachedObjects as $objectType => $objects) {
            $this->logger->info("Processing orphaned objects for type: {$objectType}", [
                'cached_objects_count' => count($objects)
            ]);

            foreach ($objects as $object) {
                // Check if this object has an ArchiMate ID
                $archiMateId = null;
                
                // Look for ArchiMate ID in properties
                if (!empty($object['properties'])) {
                    foreach ($object['properties'] as $property) {
                        if (isset($property['name']) && $property['name'] === 'archimate_id') {
                            $archiMateId = $property['value'] ?? null;
                            break;
                        }
                    }
                }
                
                // If no ArchiMate ID found, skip this object
                if (empty($archiMateId)) {
                    continue;
                }
                
                // Check if this ArchiMate ID is still present in the imported data
                if (!in_array($archiMateId, $importedArchiMateIds)) {
                    // This object is orphaned - delete it
                    try {
                        $deleteResult = $objectService->deleteObject($object['id']);
                        
                        if ($deleteResult['success']) {
                            $results['deleted']++;
                            $this->logger->notice("Deleted orphaned object", [
                                'object_id' => $object['id'],
                                'archimate_id' => $archiMateId,
                                'object_type' => $objectType
                            ]);
                        } else {
                            $error = "Failed to delete orphaned object: " . ($deleteResult['error'] ?? 'Unknown error');
                            $this->logger->error($error, [
                                'object_id' => $object['id'],
                                'archimate_id' => $archiMateId,
                                'object_type' => $objectType
                            ]);
                            $results['errors'][] = $error;
                        }
                    } catch (\Exception $e) {
                        $error = "Exception while deleting orphaned object: " . $e->getMessage();
                        $this->logger->error($error, [
                            'object_id' => $object['id'],
                            'archimate_id' => $archiMateId,
                            'object_type' => $objectType,
                            'exception' => $e->getMessage()
                        ]);
                        $results['errors'][] = $error;
                    }
                }
            }
        }

        $totalTime = microtime(true) - $startTime;
        
        $this->logger->info('Completed orphaned object deletion', [
            'total_time_seconds' => round($totalTime, 3),
            'objects_deleted' => $results['deleted'],
            'errors' => count($results['errors'])
        ]);

        return $results;
    }
}