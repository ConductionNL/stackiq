<?php
/**
 * Test Small Export - Focus on XML generation issues with minimal data
 */

require_once '/var/www/html/lib/base.php';
\OC::$CLI = true;

echo "🔬 TESTING SMALL EXPORT FOR XML ISSUES\n";
echo "======================================\n\n";

try {
    $container = \OC::$server->get(\Psr\Container\ContainerInterface::class);
    
    // Get the export service directly
    $exportService = $container->get('OCA\\SoftwareCatalog\\Service\\ArchiMateExportService');
    $objectService = $container->get('OCA\\OpenRegister\\Service\\ObjectService');
    
    echo "Services loaded successfully\n\n";
    
    // Get just 3 objects from the database for testing
    $db = \OC::$server->getDatabaseConnection();
    $stmt = $db->prepare('SELECT * FROM oc_openregister_objects WHERE register = ? LIMIT 3');
    $stmt->execute(['20']);
    $rawObjects = $stmt->fetchAll();
    
    echo "Retrieved " . count($rawObjects) . " objects for testing\n";
    
    if (empty($rawObjects)) {
        echo "❌ No objects found in register 20\n";
        exit(1);
    }
    
    // Convert to object format that the export service expects
    $objects = [];
    foreach ($rawObjects as $rawObj) {
        $objectData = json_decode($rawObj['object'], true);
        if ($objectData) {
            $objects[] = (object) $objectData;
            echo "✅ Object {$rawObj['id']}: section=" . ($objectData['section'] ?? 'unknown') . ", schema=" . ($objectData['schema'] ?? 'unknown') . "\n";
        }
    }
    
    echo "\nTesting XML generation with " . count($objects) . " objects...\n\n";
    
    // Test the export with minimal objects
    $exportOptions = [
        'includeRelationships' => false,
        'includeViews' => false,  
        'selectedSchemas' => []
    ];
    
    try {
        $result = $exportService->exportArchiMateXml(
            $objectService, 
            20, // register ID
            $exportOptions,
            null // no organization filter
        );
        
        if (is_array($result) && isset($result['success'])) {
            if ($result['success']) {
                echo "✅ Export successful!\n";
                
                if (isset($result['xml'])) {
                    $xmlLength = strlen($result['xml']);
                    echo "Generated XML length: $xmlLength bytes\n";
                    
                    // Save the XML for inspection
                    file_put_contents('/tmp/small_export_test.xml', $result['xml']);
                    echo "XML saved to /tmp/small_export_test.xml\n";
                    
                    // Show first few lines for inspection
                    $lines = explode("\n", $result['xml']);
                    echo "\nFirst 20 lines of generated XML:\n";
                    echo str_repeat("-", 60) . "\n";
                    foreach (array_slice($lines, 0, 20) as $i => $line) {
                        echo sprintf("%2d: %s\n", $i+1, htmlspecialchars(substr($line, 0, 120)));
                    }
                    
                    // Try to validate the XML
                    echo "\nValidating XML...\n";
                    libxml_use_internal_errors(true);
                    $dom = new DOMDocument();
                    if ($dom->loadXML($result['xml'])) {
                        echo "✅ Generated XML is valid!\n";
                    } else {
                        echo "❌ XML validation failed:\n";
                        $errors = libxml_get_errors();
                        foreach (array_slice($errors, 0, 10) as $error) {
                            echo "  Line {$error->line}: {$error->message}";
                        }
                    }
                }
            } else {
                echo "❌ Export failed: {$result['message']}\n";
                if (isset($result['error'])) {
                    echo "Error details: {$result['error']}\n";
                }
            }
        } else {
            echo "❌ Unexpected result format\n";
            print_r($result);
        }
        
    } catch (Exception $e) {
        echo "❌ Export exception: {$e->getMessage()}\n";
        echo "Stack trace:\n{$e->getTraceAsString()}\n";
    }
    
} catch (Exception $e) {
    echo "❌ Test failed: {$e->getMessage()}\n";
    exit(1);
}
?>
