<?php
/**
 * Full Circle Test Script for ArchiMate Import/Export
 * 
 * This script performs a complete round-trip test:
 * 1. Clear any existing import
 * 2. Import GEMMA_release.xml
 * 3. Export the data
 * 4. Compare original vs exported XML
 * 
 * Usage: docker-compose exec nextcloud php /var/www/html/apps-extra/softwarecatalog/full_circle_test.php
 */

require_once '/var/www/html/lib/base.php';

// Initialize Nextcloud
\OC::$CLI = true;

echo "🚀 STARTING FULL CIRCLE TEST\n";
echo "============================\n\n";

// Configuration
$baseUrl = 'http://localhost/index.php/apps/softwarecatalog/api/archimate';
$auth = 'admin:admin';
$testFile = '/var/www/html/apps-extra/softwarecatalog/lib/Settings/GEMMA_release.xml';

/**
 * Execute cURL command and return response
 */
function executeCurl($url, $method = 'GET', $data = null, $auth = 'admin:admin') {
    $cmd = "curl -s -X $method \"$url\" -u $auth";
    
    if ($data) {
        $cmd .= " -H \"Content-Type: application/json\" -d '$data'";
    }
    
    $output = shell_exec($cmd);
    return json_decode($output, true);
}

/**
 * Wait for operation to complete
 */
function waitForCompletion($statusUrl, $operationType, $maxWaitTime = 300) {
    $startTime = time();
    echo "⏳ Waiting for $operationType to complete...\n";
    
    while (time() - $startTime < $maxWaitTime) {
        $status = executeCurl($statusUrl);
        
        if (isset($status[$operationType]) && $status[$operationType] === false) {
            echo "✅ $operationType completed!\n";
            return true;
        }
        
        if (isset($status[$operationType]['status']) && $status[$operationType]['status'] === 'completed') {
            echo "✅ $operationType completed!\n";
            return true;
        }
        
        echo ".";
        sleep(2);
    }
    
    echo "\n❌ $operationType timed out!\n";
    return false;
}

try {
    // Step 1: Clear any existing import
    echo "1️⃣ CLEARING EXISTING IMPORT\n";
    echo "----------------------------\n";
    $cancelResult = executeCurl("$baseUrl/import/cancel", 'POST');
    echo "Cancel result: " . ($cancelResult['success'] ?? false ? 'SUCCESS' : 'FAILED') . "\n\n";
    
    // Step 2: Import GEMMA_release.xml
    echo "2️⃣ IMPORTING GEMMA_RELEASE.XML\n";
    echo "-------------------------------\n";
    $importData = json_encode(['file_path' => $testFile]);
    $importResult = executeCurl("$baseUrl/import", 'POST', $importData);
    
    if (!$importResult || !($importResult['success'] ?? false)) {
        throw new Exception("Import failed: " . json_encode($importResult));
    }
    
    echo "Import started successfully!\n";
    echo "📊 Import Statistics:\n";
    
    if (isset($importResult['statistics'])) {
        foreach ($importResult['statistics'] as $type => $stats) {
            $created = $stats['created'] ?? 0;
            $updated = $stats['updated'] ?? 0;
            echo "  - $type: $created created, $updated updated\n";
        }
    }
    
    echo "\n";
    
    // Wait for import to complete
    if (!waitForCompletion("$baseUrl/../status", 'import_in_progress')) {
        throw new Exception("Import did not complete in time");
    }
    
    // Step 3: Check database content
    echo "\n3️⃣ CHECKING DATABASE CONTENT\n";
    echo "-----------------------------\n";
    
    $dbCheckOutput = shell_exec('php /var/www/html/apps-extra/softwarecatalog/debug_db.php 2>&1');
    echo "Database check output:\n";
    echo substr($dbCheckOutput, 0, 1000) . (strlen($dbCheckOutput) > 1000 ? "...[truncated]" : "") . "\n\n";
    
    // Step 4: Export the data
    echo "4️⃣ EXPORTING DATA\n";
    echo "------------------\n";
    $exportResult = executeCurl("$baseUrl/export", 'POST');
    
    if (!$exportResult || !($exportResult['success'] ?? false)) {
        throw new Exception("Export failed: " . json_encode($exportResult));
    }
    
    echo "Export started successfully!\n";
    echo "Export file: " . ($exportResult['file_path'] ?? 'Unknown') . "\n\n";
    
    // Wait for export to complete
    if (!waitForCompletion("$baseUrl/../status", 'export_in_progress')) {
        throw new Exception("Export did not complete in time");
    }
    
    // Step 5: Compare results
    echo "5️⃣ COMPARING RESULTS\n";
    echo "--------------------\n";
    
    $compareOutput = shell_exec('php /var/www/html/apps-extra/softwarecatalog/compare_archimate.php 2>&1');
    echo $compareOutput . "\n";
    
    // Step 6: Analysis and recommendations
    echo "\n6️⃣ ANALYSIS & RECOMMENDATIONS\n";
    echo "==============================\n";
    
    // Check for specific issues
    $issues = [];
    
    // Check organizations issue
    if (strpos($compareOutput, 'Organizations: 0') !== false || 
        (isset($importResult['statistics']['organizations']['created']) && $importResult['statistics']['organizations']['created'] == 0)) {
        $issues[] = "🚨 ORGANIZATIONS NOT IMPORTED: Check normalizeArchiMateData() method around line 1060-1070";
    }
    
    // Check property issues
    if (strpos($dbCheckOutput, "''") !== false && strpos($dbCheckOutput, "propid-") === false) {
        $issues[] = "🚨 PROPERTY KEYS EMPTY: Check extractProperties() method around line 1276-1290";
    }
    
    // Check for missing elements
    if (strpos($compareOutput, 'Elements: 0') !== false) {
        $issues[] = "🚨 NO ELEMENTS FOUND: Check XML parsing in normalizeArchiMateData()";
    }
    
    if (empty($issues)) {
        echo "🎉 NO CRITICAL ISSUES DETECTED!\n";
        echo "✅ Round-trip test appears successful!\n";
    } else {
        echo "❌ CRITICAL ISSUES FOUND:\n";
        foreach ($issues as $issue) {
            echo "   $issue\n";
        }
        echo "\n📋 NEXT STEPS:\n";
        echo "   1. Fix the issues listed above\n";
        echo "   2. Re-run this script to test fixes\n";
        echo "   3. Repeat until no issues remain\n";
    }
    
    echo "\n🏁 FULL CIRCLE TEST COMPLETED\n";
    echo "==============================\n";
    
} catch (Exception $e) {
    echo "\n💥 TEST FAILED: " . $e->getMessage() . "\n";
    echo "\n🔧 TROUBLESHOOTING:\n";
    echo "   1. Check if Nextcloud is running\n";
    echo "   2. Verify admin credentials work\n";
    echo "   3. Check if GEMMA_release.xml exists\n";
    echo "   4. Review error logs\n";
    exit(1);
}
?>
