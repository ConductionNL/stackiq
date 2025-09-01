<?php
/**
 * Debug Export Issues Script
 * 
 * This script helps identify and fix issues with ArchiMate export functionality
 */

require_once '/var/www/html/lib/base.php';
\OC::$CLI = true;

echo "🔍 DEBUGGING ARCHIMATE EXPORT ISSUES\n";
echo "=====================================\n\n";

try {
    // Step 1: Check if we have data to export
    echo "1️⃣ CHECKING DATA AVAILABILITY\n";
    echo "------------------------------\n";
    
    $db = \OC::$server->getDatabaseConnection();
    
    // First check what columns exist
    $stmt = $db->prepare('SHOW COLUMNS FROM oc_openregister_objects');
    $stmt->execute();
    $columns = $stmt->fetchAll();
    echo "Database columns available:\n";
    foreach (array_slice($columns, 0, 10) as $column) {
        echo "  - {$column['Field']} ({$column['Type']})\n";
    }
    echo "\n";
    
    // Check total objects count
    $stmt = $db->prepare('SELECT COUNT(*) as count FROM oc_openregister_objects');
    $stmt->execute();
    $totalCount = $stmt->fetch()['count'];
    echo "Total objects in database: $totalCount\n";
    
    // Check if register column exists and what values it has
    try {
        $stmt = $db->prepare('SELECT DISTINCT register, COUNT(*) as count FROM oc_openregister_objects GROUP BY register ORDER BY count DESC LIMIT 10');
        $stmt->execute();
        $registers = $stmt->fetchAll();
        echo "Register distribution:\n";
        foreach ($registers as $register) {
            echo "  - Register {$register['register']}: {$register['count']} objects\n";
        }
        
        // Focus on register 20 (AMEF)
        $stmt = $db->prepare('SELECT COUNT(*) as count FROM oc_openregister_objects WHERE register = ?');
        $stmt->execute([20]);
        $amefCount = $stmt->fetch()['count'];
    } catch (Exception $e) {
        echo "Could not check by register column: {$e->getMessage()}\n";
        $amefCount = $totalCount; // Use total as fallback
    }
    
    if ($amefCount == 0) {
        echo "❌ No objects found in AMEF register! Import may have failed.\n";
        exit(1);
    }
    
    echo "✅ Found $amefCount objects to potentially export\n\n";
    
    // Step 2: Check export service availability
    echo "2️⃣ CHECKING EXPORT SERVICE\n";
    echo "---------------------------\n";
    
    $container = \OC::$server->get(\Psr\Container\ContainerInterface::class);
    
    try {
        $archiMateService = $container->get('OCA\\SoftwareCatalog\\Service\\ArchiMateService');
        echo "✅ ArchiMateService available\n";
    } catch (Exception $e) {
        echo "❌ ArchiMateService not available: {$e->getMessage()}\n";
        exit(1);
    }
    
    // Step 3: Test export method directly (bypass API)
    echo "\n3️⃣ TESTING EXPORT METHOD DIRECTLY\n";
    echo "-----------------------------------\n";
    
    try {
        $settingsService = $container->get('OCA\\SoftwareCatalog\\Service\\SettingsService');
        
        // Test basic export parameters
        $exportOptions = [
            'format' => 'archimate',
            'includeRelationships' => true,
            'includeViews' => false, // Start simple
            'organizationSpecific' => false
        ];
        
        echo "Attempting direct export with options:\n";
        print_r($exportOptions);
        echo "\n";
        
        // Check if export method exists
        $reflection = new ReflectionClass($archiMateService);
        if ($reflection->hasMethod('exportArchiMateXml')) {
            echo "✅ exportArchiMateXml method exists\n";
            
            // Try to call it
            $method = $reflection->getMethod('exportArchiMateXml');
            if ($method->isPublic() || $method->isProtected()) {
                $method->setAccessible(true);
                
                echo "Calling export method...\n";
                $startTime = microtime(true);
                
                $result = $method->invoke($archiMateService, $exportOptions);
                
                $duration = microtime(true) - $startTime;
                echo "Export completed in " . round($duration, 3) . " seconds\n";
                
                if (is_array($result) && isset($result['success'])) {
                    if ($result['success']) {
                        echo "✅ Export successful!\n";
                        if (isset($result['xml'])) {
                            $xmlLength = strlen($result['xml']);
                            echo "Generated XML length: $xmlLength bytes\n";
                            
                            // Validate XML
                            libxml_use_internal_errors(true);
                            $dom = new DOMDocument();
                            if ($dom->loadXML($result['xml'])) {
                                echo "✅ Generated XML is valid\n";
                                
                                // Save sample for inspection
                                file_put_contents('/tmp/debug_export_sample.xml', $result['xml']);
                                echo "Sample saved to /tmp/debug_export_sample.xml\n";
                            } else {
                                echo "❌ Generated XML is invalid:\n";
                                $errors = libxml_get_errors();
                                foreach ($errors as $error) {
                                    echo "  - Line {$error->line}: {$error->message}";
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
                    echo "❌ Unexpected export result format\n";
                    print_r($result);
                }
            } else {
                echo "❌ Export method is not accessible\n";
            }
        } else {
            echo "❌ exportArchiMateXml method does not exist\n";
            
            // List available methods
            echo "Available methods:\n";
            $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);
            foreach ($methods as $method) {
                if (strpos(strtolower($method->getName()), 'export') !== false) {
                    echo "  - {$method->getName()}\n";
                }
            }
        }
        
    } catch (Exception $e) {
        echo "❌ Direct export test failed: {$e->getMessage()}\n";
        echo "Stack trace:\n{$e->getTraceAsString()}\n";
    }
    
    // Step 4: Check SettingsController export method
    echo "\n4️⃣ CHECKING SETTINGS CONTROLLER\n";
    echo "--------------------------------\n";
    
    try {
        $settingsController = $container->get('OCA\\SoftwareCatalog\\Controller\\SettingsController');
        echo "✅ SettingsController available\n";
        
        $reflection = new ReflectionClass($settingsController);
        if ($reflection->hasMethod('exportArchiMate')) {
            echo "✅ exportArchiMate method exists in controller\n";
        } else {
            echo "❌ exportArchiMate method missing in controller\n";
        }
        
    } catch (Exception $e) {
        echo "❌ SettingsController issue: {$e->getMessage()}\n";
    }
    
    // Step 5: Summary and recommendations
    echo "\n5️⃣ SUMMARY & RECOMMENDATIONS\n";
    echo "=============================\n";
    
    if ($amefCount > 0) {
        echo "✅ Data is available for export\n";
        echo "📋 NEXT STEPS:\n";
        echo "   1. Fix the export method implementation\n";
        echo "   2. Ensure XML generation is working correctly\n";
        echo "   3. Test with simple export options first\n";
        echo "   4. Gradually add complexity (relationships, views)\n";
    } else {
        echo "❌ No data available - import issues need to be resolved first\n";
    }
    
} catch (Exception $e) {
    echo "\n💥 DEBUG SCRIPT FAILED: {$e->getMessage()}\n";
    echo "Stack trace:\n{$e->getTraceAsString()}\n";
    exit(1);
}
?>
