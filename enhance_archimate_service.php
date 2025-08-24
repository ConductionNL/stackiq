<?php

/**
 * Script to enhance ArchiMateService.php with GEMMA Referentiecomponent-Standaard processing
 * 
 * This script adds the enhanced functionality to handle Verbindingsrol (Aanbevolen/Verplicht)
 * and creates separate arrays for aanbevolenStandaarden and verplichteStandaarden.
 */

echo "=== Enhancing ArchiMateService.php for GEMMA Referentiecomponent Processing ===\n\n";

$serviceFile = 'lib/Service/ArchiMateService.php';

if (!file_exists($serviceFile)) {
    echo "❌ Error: ArchiMateService.php not found at: $serviceFile\n";
    exit(1);
}

// Read the current file
$content = file_get_contents($serviceFile);
if ($content === false) {
    echo "❌ Error: Could not read ArchiMateService.php\n";
    exit(1);
}

// 1. Add the method call in saveObjectsToDatabase
$searchPattern1 = '        $this->logger->info(\'Saving objects to database using parallel batch processing\', [';
$replacement1 = '        // ENHANCEMENT: Process GEMMA Referentiecomponent-Standaard relationships before saving
        $objects = $this->processGemmaReferenceComponentStandards($objects);

        $this->logger->info(\'Saving objects to database using parallel batch processing\', [';

if (strpos($content, 'processGemmaReferenceComponentStandards') === false) {
    $content = str_replace($searchPattern1, $replacement1, $content);
    echo "✅ Added processGemmaReferenceComponentStandards call to saveObjectsToDatabase\n";
} else {
    echo "ℹ️  processGemmaReferenceComponentStandards call already exists\n";
}

// 2. Add the new methods before the closing brace
$newMethods = '
    /**
     * Process GEMMA Referentiecomponent-Standaard relationships with Verbindingsrol support
     * 
     * This method analyzes all objects to find Referentiecomponenten and Standaarden,
     * then uses relationships to link them together based on Verbindingsrol property.
     * Each Referentiecomponent gets two properties:
     * - \'aanbevolenStandaarden\' array for standards with Verbindingsrol = "Aanbevolen"
     * - \'verplichteStandaarden\' array for standards with Verbindingsrol = "Verplicht"
     * 
     * @param array $objects All objects from the import
     * @return array Objects with enhanced Referentiecomponent data
     */
    private function processGemmaReferenceComponentStandards(array $objects): array
    {
        $this->logger->info(\'Processing GEMMA Referentiecomponent-Standaard relationships with Verbindingsrol support\');
        
        // STEP 1: Filter objects by GEMMA type using flattened camelCase properties
        $referentieComponenten = [];
        $standaarden = [];
        $relationships = [];
        
        foreach ($objects as $index => $object) {
            // Check if this is an element with GEMMA type property
            if (isset($object[\'section\']) && $object[\'section\'] === \'elements\' && isset($object[\'gemmaType\'])) {
                if ($object[\'gemmaType\'] === \'Referentiecomponent\') {
                    $referentieComponenten[$object[\'identifier\']] = $index;
                    $this->logger->debug(\'Found Referentiecomponent\', [
                        \'identifier\' => $object[\'identifier\'],
                        \'name\' => $object[\'name\'] ?? \'Unknown\'
                    ]);
                } elseif ($object[\'gemmaType\'] === \'Standaard\') {
                    $standaarden[$object[\'identifier\']] = $index;
                    $this->logger->debug(\'Found Standaard\', [
                        \'identifier\' => $object[\'identifier\'],
                        \'name\' => $object[\'name\'] ?? \'Unknown\'
                    ]);
                }
            }
            
            // Collect relationships for linking
            if (isset($object[\'section\']) && $object[\'section\'] === \'relationships\') {
                $relationships[] = $object;
            }
        }
        
        $this->logger->info(\'GEMMA objects found\', [
            \'referentiecomponenten_count\' => count($referentieComponenten),
            \'standaarden_count\' => count($standaarden),
            \'relationships_count\' => count($relationships)
        ]);
        
        // STEP 2: Process relationships to find connections with Verbindingsrol
        $referentieComponentStandaardMap = [];
        
        foreach ($relationships as $relationship) {
            // Get source and target from relationship XML or flattened properties
            $source = $this->extractRelationshipEndpoint($relationship, \'source\');
            $target = $this->extractRelationshipEndpoint($relationship, \'target\');
            
            if (!$source || !$target) {
                continue;
            }
            
            // Get Verbindingsrol from flattened properties (camelCase: verbindingsrol)
            $verbindingsrol = $relationship[\'verbindingsrol\'] ?? null;
            
            // Skip if no Verbindingsrol is defined
            if (!$verbindingsrol) {
                continue;
            }
            
            // Check if one end is a Referentiecomponent and the other is a Standaard
            $refCompId = null;
            $standaardId = null;
            
            if (isset($referentieComponenten[$source]) && isset($standaarden[$target])) {
                // Referentiecomponent -> Standaard
                $refCompId = $source;
                $standaardId = $target;
            } elseif (isset($standaarden[$source]) && isset($referentieComponenten[$target])) {
                // Standaard -> Referentiecomponent (reverse direction)
                $refCompId = $target;
                $standaardId = $source;
            }
            
            if ($refCompId && $standaardId) {
                // Initialize arrays if not exists
                if (!isset($referentieComponentStandaardMap[$refCompId])) {
                    $referentieComponentStandaardMap[$refCompId] = [
                        \'aanbevolen\' => [],
                        \'verplicht\' => []
                    ];
                }
                
                // Add to appropriate array based on Verbindingsrol
                if (strtolower($verbindingsrol) === \'aanbevolen\') {
                    $referentieComponentStandaardMap[$refCompId][\'aanbevolen\'][] = $standaardId;
                } elseif (strtolower($verbindingsrol) === \'verplicht\') {
                    $referentieComponentStandaardMap[$refCompId][\'verplicht\'][] = $standaardId;
                } else {
                    // Log unknown Verbindingsrol for debugging
                    $this->logger->warning(\'Unknown Verbindingsrol found\', [
                        \'verbindingsrol\' => $verbindingsrol,
                        \'relationship\' => $relationship[\'identifier\'] ?? \'unknown\',
                        \'referentiecomponent\' => $refCompId,
                        \'standaard\' => $standaardId
                    ]);
                    continue;
                }
                
                $this->logger->debug(\'Found Referentiecomponent-Standaard link with Verbindingsrol\', [
                    \'referentiecomponent\' => $refCompId,
                    \'standaard\' => $standaardId,
                    \'verbindingsrol\' => $verbindingsrol,
                    \'relationship\' => $relationship[\'identifier\'] ?? \'unknown\'
                ]);
            }
        }
        
        // STEP 3: Add \'aanbevolenStandaarden\' and \'verplichteStandaarden\' properties to Referentiecomponenten
        $enhancedCount = 0;
        foreach ($referentieComponentStandaardMap as $referentieComponentId => $standaardenMap) {
            if (isset($referentieComponenten[$referentieComponentId])) {
                $objectIndex = $referentieComponenten[$referentieComponentId];
                
                // Remove duplicates and add the properties
                $aanbevolenStandaarden = array_unique($standaardenMap[\'aanbevolen\']);
                $verplichteStandaarden = array_unique($standaardenMap[\'verplicht\']);
                
                $objects[$objectIndex][\'aanbevolenStandaarden\'] = $aanbevolenStandaarden;
                $objects[$objectIndex][\'verplichteStandaarden\'] = $verplichteStandaarden;
                
                // Also add combined array for backward compatibility
                $allStandaarden = array_unique(array_merge($aanbevolenStandaarden, $verplichteStandaarden));
                $objects[$objectIndex][\'standaarden\'] = $allStandaarden;
                
                $this->logger->info(\'Enhanced Referentiecomponent with categorized standaarden\', [
                    \'referentiecomponent_id\' => $referentieComponentId,
                    \'referentiecomponent_name\' => $objects[$objectIndex][\'name\'] ?? \'Unknown\',
                    \'aanbevolen_count\' => count($aanbevolenStandaarden),
                    \'verplicht_count\' => count($verplichteStandaarden),
                    \'aanbevolen_ids\' => $aanbevolenStandaarden,
                    \'verplicht_ids\' => $verplichteStandaarden
                ]);
                
                $enhancedCount++;
            }
        }
        
        $this->logger->info(\'GEMMA Referentiecomponent-Standaard processing completed\', [
            \'referentiecomponenten_enhanced\' => $enhancedCount,
            \'total_referentiecomponenten\' => count($referentieComponenten),
            \'total_relationships_processed\' => count($relationships)
        ]);
        
        return $objects;
    }

    /**
     * Extract relationship endpoint (source or target) from relationship object
     * 
     * @param array $relationship The relationship object
     * @param string $endpoint Either \'source\' or \'target\'
     * @return string|null The endpoint identifier or null if not found
     */
    private function extractRelationshipEndpoint(array $relationship, string $endpoint): ?string
    {
        // Try flattened camelCase property first
        if (isset($relationship[$endpoint])) {
            return $relationship[$endpoint];
        }
        
        // Try XML structure
        if (isset($relationship[\'xml\'][$endpoint])) {
            $endpointData = $relationship[\'xml\'][$endpoint];
            
            // Handle different XML structures
            if (is_string($endpointData)) {
                return $endpointData;
            } elseif (is_array($endpointData)) {
                // Try _attributes.href or _value
                if (isset($endpointData[\'_attributes\'][\'href\'])) {
                    return $endpointData[\'_attributes\'][\'href\'];
                } elseif (isset($endpointData[\'_value\'])) {
                    return $endpointData[\'_value\'];
                }
            }
        }
        
        // Try direct XML access for ArchiMate format
        if (isset($relationship[\'xml\'][\'_attributes\'])) {
            $attr = $relationship[\'xml\'][\'_attributes\'];
            if ($endpoint === \'source\' && isset($attr[\'source\'])) {
                return $attr[\'source\'];
            } elseif ($endpoint === \'target\' && isset($attr[\'target\'])) {
                return $attr[\'target\'];
            }
        }
        
        return null;
    }
';

// Check if methods already exist
if (strpos($content, 'processGemmaReferenceComponentStandards') === false) {
    // Find the last closing brace and add methods before it
    $lastBracePos = strrpos($content, '}');
    if ($lastBracePos !== false) {
        $content = substr($content, 0, $lastBracePos) . $newMethods . "\n}";
        echo "✅ Added processGemmaReferenceComponentStandards and extractRelationshipEndpoint methods\n";
    } else {
        echo "❌ Error: Could not find closing brace in ArchiMateService.php\n";
        exit(1);
    }
} else {
    echo "ℹ️  processGemmaReferenceComponentStandards method already exists\n";
}

// Write the updated content back to the file
if (file_put_contents($serviceFile, $content) === false) {
    echo "❌ Error: Could not write updated ArchiMateService.php\n";
    exit(1);
}

echo "✅ Successfully enhanced ArchiMateService.php\n\n";

// Check syntax
echo "Checking PHP syntax...\n";
$output = [];
$returnCode = 0;
exec("php -l $serviceFile 2>&1", $output, $returnCode);

if ($returnCode === 0) {
    echo "✅ PHP syntax is valid\n";
} else {
    echo "❌ PHP syntax errors found:\n";
    foreach ($output as $line) {
        echo "   $line\n";
    }
    exit(1);
}

echo "\n=== Enhancement Complete ===\n";
echo "The ArchiMateService now supports:\n";
echo "- Processing Verbindingsrol property from relationships\n";
echo "- Creating separate aanbevolenStandaarden and verplichteStandaarden arrays\n";
echo "- Enhanced logging for debugging\n";
echo "- Backward compatibility with combined standaarden array\n\n";

echo "Next steps:\n";
echo "1. Test the import with GEMMA_release.xml\n";
echo "2. Check the logs for enhanced Referentiecomponent processing\n";
echo "3. Verify the results match the expected table format\n";

