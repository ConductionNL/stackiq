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
        foreach ($data as $key => $value) {
            if ($key === '_value' || $key === '_text') {
                $xml[0] = (string) $value;
                continue;
            }

            if (is_string($key) && str_starts_with($key, '_') && $key !== '_attributes') {
                // Skip legacy _attributes bag, handle individual underscored keys as attributes
                $attrKey = substr($key, 1);
                [$nsPrefix, $local] = $this->splitNamespacedKey($attrKey);

                if ($nsPrefix !== null) {
                    // Namespaced attribute, ensure namespace is declared on element
                    $nsUri = $this->getNamespaceUri($xml, $nsPrefix);
                    if ($nsUri) {
                        $xml->addAttribute($nsPrefix . ':' . $local, (string) $value, $nsUri);
                    } else {
                        // Fallback to non-namespaced if namespace not found
                        $xml->addAttribute($attrKey, (string) $value);
                    }
                } else {
                    $xml->addAttribute($local, (string) $value);
                }
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

            if (is_array($value)) {
                // Handle list of children
                if ($this->isList($value)) {
                    foreach ($value as $item) {
                        $child = $xml->addChild($key);
                        if (is_array($item)) {
                            $this->arrayToXml($item, $child);
                        } else {
                            $child[0] = (string) $item;
                        }
                    }
                } else {
                    $child = $xml->addChild($key);
                    $this->arrayToXml($value, $child);
                }
            } else {
                // Scalar child node
                $child = $xml->addChild($key);
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
        $version = $modelMetadata['version'] ?? '4.6.0';

        $xmlString = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<archimate:model xmlns:archimate="http://www.archimatetool.com/archimate" 
                 xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" 
                 name="{$modelName}" 
                 id="{$modelId}" 
                 version="{$version}">
</archimate:model>
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
     * Convenience method for views
     */
    public function addViewsToXml(\SimpleXMLElement $xml, array $views): void
    {
        $this->addObjectsToXml($xml, $views, 'Views', 'folder-views', 'diagrams', 'view');
    }

    /**
     * Convenience method for organizations
     */
    public function addOrganizationsToXml(\SimpleXMLElement $xml, array $organizations): void
    {
        $this->addObjectsToXml($xml, $organizations, 'Organizations', 'folder-organizations', 'business', 'item');
    }

    /**
     * Generic method to add any object to a folder - determines everything from the JSON data
     */
    private function addObjectToFolder(\SimpleXMLElement $folder, array $object, string $childTagName = 'element'): void
    {
        $objectNode = $folder->addChild($childTagName);
        
        // Extract stored XML data and convert back to XML
        if (isset($object['properties']['xml_data'])) {
            $xmlData = is_string($object['properties']['xml_data']) ? 
                json_decode($object['properties']['xml_data'], true) : 
                $object['properties']['xml_data'];
            
            if (is_array($xmlData)) {
                $this->arrayToXml($xmlData, $objectNode);
            }
        } else {
            // Generic fallback: convert the entire object to XML
            $this->arrayToXml($object, $objectNode);
        }
    }
}


