<?php
/**
 * ArchiMate Import/Export Comparison Script
 * 
 * This script compares the original GEMMA_release.xml with the exported XML
 * to identify differences and ensure round-trip compatibility.
 */

require_once '/var/www/html/lib/base.php';

// Initialize Nextcloud
\OC::$CLI = true;

class ArchiMateComparator
{
    private $differences = [];
    private $stats = [
        'elements_compared' => 0,
        'relationships_compared' => 0,
        'views_compared' => 0,
        'property_definitions_compared' => 0,
        'folders_compared' => 0,
        'total_differences' => 0
    ];

    public function compareFiles(string $originalFile, string $exportedFile): array
    {
        echo "=== ArchiMate Import/Export Comparison ===\n\n";
        
        // Compare file sizes first
        $this->compareFileSizes($originalFile, $exportedFile);
        
        // Parse both XML files
        echo "Parsing original file: $originalFile\n";
        $originalXml = $this->parseXmlFile($originalFile);
        
        echo "Parsing exported file: $exportedFile\n";
        $exportedXml = $this->parseXmlFile($exportedFile);
        
        if (!$originalXml || !$exportedXml) {
            throw new Exception("Failed to parse one or both XML files");
        }
        
        // Compare different sections
        $this->compareModelMetadata($originalXml, $exportedXml);
        $this->compareElements($originalXml, $exportedXml);
        $this->compareRelationships($originalXml, $exportedXml);
        $this->compareOrganizations($originalXml, $exportedXml);
        $this->comparePropertyDefinitions($originalXml, $exportedXml);
        $this->compareFolders($originalXml, $exportedXml);
        $this->compareViews($originalXml, $exportedXml);
        
        // Generate report
        $this->generateReport();
        
        return [
            'differences' => $this->differences,
            'stats' => $this->stats
        ];
    }

    private function compareFileSizes(string $originalFile, string $exportedFile): void
    {
        echo "📊 FILE SIZE COMPARISON\n";
        echo str_repeat("=", 50) . "\n";
        
        $originalSize = file_exists($originalFile) ? filesize($originalFile) : 0;
        $exportedSize = file_exists($exportedFile) ? filesize($exportedFile) : 0;
        
        echo sprintf("Original file: %s (%s)\n", 
            basename($originalFile), 
            $this->formatBytes($originalSize)
        );
        echo sprintf("Exported file: %s (%s)\n", 
            basename($exportedFile), 
            $this->formatBytes($exportedSize)
        );
        
        if ($originalSize > 0 && $exportedSize > 0) {
            $ratio = $exportedSize / $originalSize;
            $difference = $exportedSize - $originalSize;
            
            echo sprintf("Size ratio: %.2fx (%s)\n", 
                $ratio, 
                $difference > 0 ? "+" . $this->formatBytes($difference) : $this->formatBytes($difference)
            );
            
            if ($ratio > 1.5) {
                echo "⚠️  WARNING: Export is significantly larger than original!\n";
                $this->addDifference('file_size', 'ratio', '1.0x', number_format($ratio, 2) . 'x larger');
            } elseif ($ratio < 0.8) {
                echo "⚠️  WARNING: Export is significantly smaller than original!\n";
                $this->addDifference('file_size', 'ratio', '1.0x', number_format(1/$ratio, 2) . 'x smaller');
            } else {
                echo "✅ File sizes are reasonably similar\n";
            }
        }
        
        echo "\n";
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= (1 << (10 * $pow));
        
        return round($bytes, 2) . ' ' . $units[$pow];
    }
    
    private function parseXmlFile(string $filePath): ?\SimpleXMLElement
    {
        if (!file_exists($filePath)) {
            echo "File not found: $filePath\n";
            return null;
        }
        
        $xmlContent = file_get_contents($filePath);
        if ($xmlContent === false) {
            echo "Failed to read file: $filePath\n";
            return null;
        }
        
        try {
            $xml = simplexml_load_string($xmlContent);
            if ($xml) {
                // Register namespaces for XPath queries
                $xml->registerXPathNamespace('archimate', 'http://www.opengroup.org/xsd/archimate/3.0/');
                $xml->registerXPathNamespace('xsi', 'http://www.w3.org/2001/XMLSchema-instance');
            }
            return $xml;
        } catch (Exception $e) {
            echo "Failed to parse XML: " . $e->getMessage() . "\n";
            return null;
        }
    }
    
    private function compareModelMetadata(\SimpleXMLElement $original, \SimpleXMLElement $exported): void
    {
        echo "\n--- Comparing Model Metadata ---\n";
        
        // Compare model attributes
        $originalAttrs = $original->attributes();
        $exportedAttrs = $exported->attributes();
        
        if ((string)$originalAttrs['identifier'] !== (string)$exportedAttrs['identifier']) {
            $this->addDifference('model_metadata', 'identifier', 
                (string)$originalAttrs['identifier'], 
                (string)$exportedAttrs['identifier']);
        }
        
        // Compare name
        if ((string)$original->name !== (string)$exported->name) {
            $this->addDifference('model_metadata', 'name', 
                (string)$original->name, 
                (string)$exported->name);
        }
        
        // Compare documentation
        if ((string)$original->documentation !== (string)$exported->documentation) {
            $this->addDifference('model_metadata', 'documentation', 
                substr((string)$original->documentation, 0, 100) . '...', 
                substr((string)$exported->documentation, 0, 100) . '...');
        }
        
        echo "Model metadata comparison completed\n";
    }
    
    private function compareElements(\SimpleXMLElement $original, \SimpleXMLElement $exported): void
    {
        echo "\n--- Comparing Elements ---\n";
        
        $originalElements = $original->xpath('//*[local-name()="element"]');
        $exportedElements = $exported->xpath('//*[local-name()="element"]');
        
        $this->stats['elements_compared'] = count($originalElements);
        
        echo sprintf("Original elements found: %d\n", count($originalElements));
        echo sprintf("Exported elements found: %d\n", count($exportedElements));
        
        // Check if exported elements have identifiers
        $exportedHasIdentifiers = false;
        $exportedHasXsiType = false;
        
        if (!empty($exportedElements)) {
            $firstExported = $exportedElements[0];
            $exportedHasIdentifiers = !empty((string)$firstExported['identifier']);
            $exportedHasXsiType = !empty((string)$firstExported->attributes('xsi', true)['type']);
            
            echo sprintf("Exported elements have identifier attribute: %s\n", 
                $exportedHasIdentifiers ? 'YES' : 'NO');
            echo sprintf("Exported elements have xsi:type attribute: %s\n", 
                $exportedHasXsiType ? 'YES' : 'NO');
        }
        
        // If exported elements don't have identifiers, we can't do detailed comparison
        if (!$exportedHasIdentifiers && !empty($originalElements)) {
            echo "❌ CRITICAL: Exported elements missing identifier attributes!\n";
            $this->addDifference('elements', 'ALL', 'HAS_IDENTIFIERS', 'MISSING_IDENTIFIERS');
            
            // Show sample of what's missing
            $sampleOriginal = $originalElements[0];
            echo sprintf("Expected: <element identifier=\"%s\" xsi:type=\"%s\">\n",
                (string)$sampleOriginal['identifier'],
                (string)$sampleOriginal->attributes('xsi', true)['type']
            );
            echo sprintf("Actual: <element> (no attributes)\n");
            return;
        }
        
        // Do detailed comparison if identifiers exist
        $originalIndexed = $this->indexByAttribute($originalElements, 'identifier');
        $exportedIndexed = $this->indexByAttribute($exportedElements, 'identifier');
        
        foreach ($originalIndexed as $id => $originalElement) {
            if (!isset($exportedIndexed[$id])) {
                $this->addDifference('elements', $id, 'EXISTS', 'MISSING');
                continue;
            }
            
            $exportedElement = $exportedIndexed[$id];
            
            // Compare xsi:type
            $originalType = (string)$originalElement->attributes('xsi', true)['type'];
            $exportedType = (string)$exportedElement->attributes('xsi', true)['type'];
            if ($originalType !== $exportedType) {
                $this->addDifference('elements', $id . '/xsi:type', $originalType, $exportedType);
            }
            
            // Compare name
            if ((string)$originalElement->name !== (string)$exportedElement->name) {
                $this->addDifference('elements', $id . '/name', 
                    (string)$originalElement->name, 
                    (string)$exportedElement->name);
            }
            
            // Compare documentation
            if ((string)$originalElement->documentation !== (string)$exportedElement->documentation) {
                $this->addDifference('elements', $id . '/documentation', 
                    substr((string)$originalElement->documentation, 0, 50) . '...', 
                    substr((string)$exportedElement->documentation, 0, 50) . '...');
            }
            
            // Compare properties
            $this->compareProperties($originalElement, $exportedElement, 'elements', $id);
        }
        
        // Check for extra elements in export
        foreach ($exportedElements as $id => $exportedElement) {
            if (!isset($originalElements[$id])) {
                $this->addDifference('elements', $id, 'MISSING', 'EXISTS');
            }
        }
        
        echo "Elements comparison completed: " . count($originalElements) . " elements\n";
    }
    
    private function compareRelationships(\SimpleXMLElement $original, \SimpleXMLElement $exported): void
    {
        echo "\n--- Comparing Relationships ---\n";
        
        $originalRels = $this->indexByAttribute($original->xpath('//*[local-name()="relationship"]'), 'identifier');
        $exportedRels = $this->indexByAttribute($exported->xpath('//*[local-name()="relationship"]'), 'identifier');
        
        $this->stats['relationships_compared'] = count($originalRels);
        
        foreach ($originalRels as $id => $originalRel) {
            if (!isset($exportedRels[$id])) {
                $this->addDifference('relationships', $id, 'EXISTS', 'MISSING');
                continue;
            }
            
            $exportedRel = $exportedRels[$id];
            
            // Compare xsi:type
            $originalType = (string)$originalRel->attributes('xsi', true)['type'];
            $exportedType = (string)$exportedRel->attributes('xsi', true)['type'];
            if ($originalType !== $exportedType) {
                $this->addDifference('relationships', $id . '/xsi:type', $originalType, $exportedType);
            }
            
            // Compare source and target
            $originalSource = (string)$originalRel->attributes()['source'];
            $exportedSource = (string)$exportedRel->attributes()['source'];
            if ($originalSource !== $exportedSource) {
                $this->addDifference('relationships', $id . '/source', $originalSource, $exportedSource);
            }
            
            $originalTarget = (string)$originalRel->attributes()['target'];
            $exportedTarget = (string)$exportedRel->attributes()['target'];
            if ($originalTarget !== $exportedTarget) {
                $this->addDifference('relationships', $id . '/target', $originalTarget, $exportedTarget);
            }
            
            // Compare name (if present)
            $originalName = (string)$originalRel->name;
            $exportedName = (string)$exportedRel->name;
            if ($originalName !== $exportedName) {
                $this->addDifference('relationships', $id . '/name', $originalName, $exportedName);
            }
            
            // Compare documentation
            if ((string)$originalRel->documentation !== (string)$exportedRel->documentation) {
                $this->addDifference('relationships', $id . '/documentation', 
                    substr((string)$originalRel->documentation, 0, 50) . '...', 
                    substr((string)$exportedRel->documentation, 0, 50) . '...');
            }
            
            // Compare properties
            $this->compareProperties($originalRel, $exportedRel, 'relationships', $id);
        }
        
        echo "Relationships comparison completed: " . count($originalRels) . " relationships\n";
    }
    
    private function compareOrganizations(\SimpleXMLElement $original, \SimpleXMLElement $exported): void
    {
        echo "\n--- Comparing Organizations ---\n";
        
        $originalOrgs = $this->indexByAttribute($original->xpath('//*[local-name()="organizations"]/*[local-name()="item"]'), 'identifier');
        $exportedOrgs = $this->indexByAttribute($exported->xpath('//*[local-name()="organizations"]/*[local-name()="item"]'), 'identifier');
        
        foreach ($originalOrgs as $id => $originalOrg) {
            if (!isset($exportedOrgs[$id])) {
                $this->addDifference('organizations', $id, 'EXISTS', 'MISSING');
                continue;
            }
            
            $exportedOrg = $exportedOrgs[$id];
            
            // Compare label
            if ((string)$originalOrg->label !== (string)$exportedOrg->label) {
                $this->addDifference('organizations', $id . '/label', 
                    (string)$originalOrg->label, 
                    (string)$exportedOrg->label);
            }
        }
        
        echo "Organizations comparison completed: " . count($originalOrgs) . " organizations\n";
    }
    
    private function comparePropertyDefinitions(\SimpleXMLElement $original, \SimpleXMLElement $exported): void
    {
        echo "\n--- Comparing Property Definitions ---\n";
        
        $originalProps = $this->indexByAttribute($original->xpath('//*[local-name()="propertyDefinition"]'), 'identifier');
        $exportedProps = $this->indexByAttribute($exported->xpath('//*[local-name()="propertyDefinition"]'), 'identifier');
        
        $this->stats['property_definitions_compared'] = count($originalProps);
        
        foreach ($originalProps as $id => $originalProp) {
            if (!isset($exportedProps[$id])) {
                $this->addDifference('property_definitions', $id, 'EXISTS', 'MISSING');
                continue;
            }
            
            $exportedProp = $exportedProps[$id];
            
            // Compare name
            if ((string)$originalProp->name !== (string)$exportedProp->name) {
                $this->addDifference('property_definitions', $id . '/name', 
                    (string)$originalProp->name, 
                    (string)$exportedProp->name);
            }
            
            // Compare type
            $originalType = (string)$originalProp->attributes()['type'];
            $exportedType = (string)$exportedProp->attributes()['type'];
            if ($originalType !== $exportedType) {
                $this->addDifference('property_definitions', $id . '/type', $originalType, $exportedType);
            }
        }
        
        echo "Property definitions comparison completed: " . count($originalProps) . " property definitions\n";
    }
    
    private function compareFolders(\SimpleXMLElement $original, \SimpleXMLElement $exported): void
    {
        echo "\n--- Comparing Folders ---\n";
        
        $originalFolders = $this->indexByAttribute($original->xpath('//*[local-name()="folder"]'), 'identifier');
        $exportedFolders = $this->indexByAttribute($exported->xpath('//*[local-name()="folder"]'), 'identifier');
        
        $this->stats['folders_compared'] = count($originalFolders);
        
        foreach ($originalFolders as $id => $originalFolder) {
            if (!isset($exportedFolders[$id])) {
                $this->addDifference('folders', $id, 'EXISTS', 'MISSING');
                continue;
            }
            
            $exportedFolder = $exportedFolders[$id];
            
            // Compare name
            $originalName = (string)$originalFolder->attributes()['name'];
            $exportedName = (string)$exportedFolder->attributes()['name'];
            if ($originalName !== $exportedName) {
                $this->addDifference('folders', $id . '/name', $originalName, $exportedName);
            }
        }
        
        echo "Folders comparison completed: " . count($originalFolders) . " folders\n";
    }
    
    private function compareViews(\SimpleXMLElement $original, \SimpleXMLElement $exported): void
    {
        echo "\n--- Comparing Views ---\n";
        
        $originalViews = $this->indexByAttribute($original->xpath('//*[local-name()="view"]'), 'identifier');
        $exportedViews = $this->indexByAttribute($exported->xpath('//*[local-name()="view"]'), 'identifier');
        
        $this->stats['views_compared'] = count($originalViews);
        
        foreach ($originalViews as $id => $originalView) {
            if (!isset($exportedViews[$id])) {
                $this->addDifference('views', $id, 'EXISTS', 'MISSING');
                continue;
            }
            
            $exportedView = $exportedViews[$id];
            
            // Compare xsi:type
            $originalType = (string)$originalView->attributes('xsi', true)['type'];
            $exportedType = (string)$exportedView->attributes('xsi', true)['type'];
            if ($originalType !== $exportedType) {
                $this->addDifference('views', $id . '/xsi:type', $originalType, $exportedType);
            }
            
            // Compare name
            if ((string)$originalView->name !== (string)$exportedView->name) {
                $this->addDifference('views', $id . '/name', 
                    (string)$originalView->name, 
                    (string)$exportedView->name);
            }
        }
        
        echo "Views comparison completed: " . count($originalViews) . " views\n";
    }
    
    private function compareProperties(\SimpleXMLElement $originalElement, \SimpleXMLElement $exportedElement, string $section, string $id): void
    {
        $originalProps = [];
        $exportedProps = [];
        
        // Extract original properties
        foreach ($originalElement->xpath('.//*[local-name()="property"]') as $prop) {
            $propId = (string)$prop->attributes()['propertyDefinitionRef'];
            $originalProps[$propId] = (string)$prop->value;
        }
        
        // Extract exported properties
        foreach ($exportedElement->xpath('.//*[local-name()="property"]') as $prop) {
            $propId = (string)$prop->attributes()['propertyDefinitionRef'];
            $exportedProps[$propId] = (string)$prop->value;
        }
        
        // Compare properties
        foreach ($originalProps as $propId => $originalValue) {
            if (!isset($exportedProps[$propId])) {
                $this->addDifference($section, $id . '/property/' . $propId, $originalValue, 'MISSING');
            } elseif ($originalValue !== $exportedProps[$propId]) {
                $this->addDifference($section, $id . '/property/' . $propId, $originalValue, $exportedProps[$propId]);
            }
        }
        
        // Check for extra properties in export
        foreach ($exportedProps as $propId => $exportedValue) {
            if (!isset($originalProps[$propId])) {
                $this->addDifference($section, $id . '/property/' . $propId, 'MISSING', $exportedValue);
            }
        }
    }
    
    private function indexByAttribute(array $elements, string $attribute): array
    {
        $indexed = [];
        foreach ($elements as $element) {
            $id = (string)$element->attributes()[$attribute];
            if ($id) {
                $indexed[$id] = $element;
            }
        }
        return $indexed;
    }
    
    private function addDifference(string $section, string $path, string $original, string $exported): void
    {
        $this->differences[] = [
            'section' => $section,
            'path' => $path,
            'original' => $original,
            'exported' => $exported
        ];
        $this->stats['total_differences']++;
    }
    
    private function generateReport(): void
    {
        echo "\n=== COMPARISON REPORT ===\n";
        echo "Total differences found: " . $this->stats['total_differences'] . "\n";
        echo "Elements compared: " . $this->stats['elements_compared'] . "\n";
        echo "Relationships compared: " . $this->stats['relationships_compared'] . "\n";
        echo "Views compared: " . $this->stats['views_compared'] . "\n";
        echo "Property definitions compared: " . $this->stats['property_definitions_compared'] . "\n";
        echo "Folders compared: " . $this->stats['folders_compared'] . "\n\n";
        
        if (empty($this->differences)) {
            echo "🎉 NO DIFFERENCES FOUND! Perfect round-trip compatibility achieved!\n";
            return;
        }
        
        // Group differences by section
        $groupedDifferences = [];
        foreach ($this->differences as $diff) {
            $groupedDifferences[$diff['section']][] = $diff;
        }
        
        foreach ($groupedDifferences as $section => $differences) {
            echo "--- $section (" . count($differences) . " differences) ---\n";
            
            $count = 0;
            foreach ($differences as $diff) {
                $count++;
                if ($count > 10) {
                    echo "... and " . (count($differences) - 10) . " more differences in this section\n";
                    break;
                }
                
                echo "  {$diff['path']}:\n";
                echo "    Original: {$diff['original']}\n";
                echo "    Exported: {$diff['exported']}\n";
            }
            echo "\n";
        }
    }
}

// Main execution
try {
    $comparator = new ArchiMateComparator();
    
    $originalFile = '/var/www/html/apps-extra/softwarecatalog/lib/Settings/GEMMA_release.xml';
    $exportedFile = '/tmp/archimate_export_latest.xml';
    
    // First, generate a fresh export
    echo "Generating fresh export...\n";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'http://localhost/index.php/apps/softwarecatalog/api/archimate/export');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, '{}');
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_USERPWD, 'admin:admin');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    $exportContent = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200 || !$exportContent) {
        throw new Exception("Failed to generate export. HTTP Code: $httpCode");
    }
    
    // Save export to file
    file_put_contents($exportedFile, $exportContent);
    echo "Export saved to: $exportedFile\n\n";
    
    // Run comparison
    $result = $comparator->compareFiles($originalFile, $exportedFile);
    
    // Save detailed results to file
    $reportFile = '/tmp/archimate_comparison_report.json';
    file_put_contents($reportFile, json_encode($result, JSON_PRETTY_PRINT));
    echo "\nDetailed report saved to: $reportFile\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
