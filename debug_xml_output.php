<?php
/**
 * Debug XML Output Script
 * 
 * This script tests the XML export and captures the raw output
 * to identify malformed XML issues.
 */

require_once '/var/www/html/lib/base.php';
\OC::$CLI = true;

echo "🔍 DEBUGGING XML EXPORT OUTPUT\n";
echo "==============================\n\n";

try {
    $container = \OC::$server->get(\Psr\Container\ContainerInterface::class);
    $archiMateService = $container->get('OCA\\SoftwareCatalog\\Service\\ArchiMateService');
    
    echo "Starting export with QA disabled...\n";
    
    // We need to temporarily disable QA to see the raw XML
    // Let's try exporting and catch the raw XML before QA validation
    
    $result = $archiMateService->exportToArchiMate(null);
    
    if (isset($result['success']) && !$result['success']) {
        echo "Export failed: " . ($result['error'] ?? 'Unknown error') . "\n";
        
        // The error happens during QA validation, so let's try to get more details
        if (isset($result['error']) && str_contains($result['error'], 'QA validation failed')) {
            echo "\n=== XML GENERATION APPEARS TO WORK, BUT QA VALIDATION FAILS ===\n";
            echo "This suggests the XML is being generated but is malformed.\n";
            echo "Need to examine the XML string before QA validation.\n";
        }
    } else {
        echo "Export succeeded!\n";
        print_r($result);
    }
    
} catch (Exception $e) {
    echo "\n💥 Debug failed: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}

echo "\n🏁 Debug completed\n";
?>

