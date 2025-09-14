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
$testFile = '/var/www/html/apps-extra/softwarecatalog/lib/Settings/GEMMA_smaller.xml';

// Target object focus (optional) e.g. --object=<archimate-id>. If omitted, no single-object focus is performed.
$targetObjectId = null;
foreach ($argv ?? [] as $arg) {
    if (str_starts_with($arg, '--object=')) {
        $targetObjectId = substr($arg, strlen('--object='));
    }
}
if ($targetObjectId) {
    echo "Focus object: $targetObjectId (override with --object=<archimate-id>)\n\n";
} else {
    echo "No focus object specified; running full test for all objects.\n\n";
}

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
 * Check if operation completed successfully (synchronous operations)
 */
function checkOperationSuccess($response, $operationType) {
    if (!$response) {
        echo "❌ $operationType failed: No response\n";
        return false;
    }
    
    if (isset($response['success']) && $response['success'] === true) {
        echo "✅ $operationType completed successfully!\n";
        return true;
    }
    
    if (isset($response['error'])) {
        echo "❌ $operationType failed: {$response['error']}\n";
        return false;
    }
    
    echo "❌ $operationType failed: Unknown error\n";
    return false;
}

/**
 * Quick DB lookup for an object by ArchiMate UUID
 */
function fetchObjectByUuid(string $uuid): ?array {
    try {
        $db = \OC::$server->getDatabaseConnection();
        $stmt = $db->prepare('SELECT id, uuid, name, object FROM oc_openregister_objects WHERE uuid = ? LIMIT 1');
        $stmt->execute([$uuid]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $row['object_decoded'] = json_decode($row['object'] ?? 'null', true);
        return $row;
    } catch (\Throwable $e) {
        echo "DB lookup error: {$e->getMessage()}\n";
        return null;
    }
}

/**
 * Find object in exported XML by identifier, return brief info
 */
function findInExportedXml(string $xmlPath, string $identifier): array {
    $result = ['present' => false, 'section' => null, 'type' => null, 'name' => null];
    if (!is_file($xmlPath)) {
        return $result;
    }
    $xmlStr = file_get_contents($xmlPath);
    if ($xmlStr === false) {
        return $result;
    }
    $xml = simplexml_load_string($xmlStr);
    if (!$xml) {
        return $result;
    }
    $xml->registerXPathNamespace('archimate', 'http://www.opengroup.org/xsd/archimate/3.0/');
    $xml->registerXPathNamespace('xsi', 'http://www.w3.org/2001/XMLSchema-instance');

    // Search elements
    $nodes = $xml->xpath('//*[local-name()="element" and @identifier="' . $identifier . '"]');
    if ($nodes && isset($nodes[0])) {
        $node = $nodes[0];
        $result['present'] = true;
        $result['section'] = 'elements';
        $result['type'] = (string)$node->attributes('xsi', true)['type'];
        $result['name'] = (string)$node->name;
        return $result;
    }

    // Search organizations
    $nodes = $xml->xpath('//*[local-name()="organizations"]/*[local-name()="item" and @identifier="' . $identifier . '"]');
    if ($nodes && isset($nodes[0])) {
        $node = $nodes[0];
        $result['present'] = true;
        $result['section'] = 'organizations';
        $result['type'] = 'Organization';
        $result['name'] = (string)$node->label;
        return $result;
    }

    // Search relationships
    $nodes = $xml->xpath('//*[local-name()="relationship" and @identifier="' . $identifier . '"]');
    if ($nodes && isset($nodes[0])) {
        $node = $nodes[0];
        $result['present'] = true;
        $result['section'] = 'relationships';
        $result['type'] = (string)$node->attributes('xsi', true)['type'];
        $result['name'] = (string)$node->name;
        return $result;
    }

    return $result;
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
    
    // Use multipart file upload instead of JSON file_path
    $uploadCmd = "curl -s -u $auth -X POST \"$baseUrl/import\" -F \"archiMateFile=@$testFile\" -F \"replaceExisting=true\"";
    $importResponse = shell_exec($uploadCmd);
    $importResult = json_decode($importResponse, true);
    
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
    
    // Check if import completed successfully (synchronous)
    if (!checkOperationSuccess($importResult, 'Import')) {
        throw new Exception("Import failed");
    }
    

    
    // Step 3: Export the data
    echo "3️⃣ EXPORTING DATA\n";
    echo "------------------\n";
    $exportData = json_encode([
        'format' => 'archimate',
        'includeRelationships' => true,
        'includeViews' => true,
        'organizationSpecific' => false,
        'selectedSchemas' => []
    ]);
    
    // Use raw curl for XML response (not JSON)
    $exportCmd = "curl -s -X POST \"$baseUrl/export\" -u $auth -H \"Content-Type: application/json\" -d '$exportData'";
    $exportXml = shell_exec($exportCmd);
    
    if (!$exportXml || strlen($exportXml) < 100) {
        throw new Exception("Export failed: " . ($exportXml ?: 'No response'));
    }
    
    // Check if it starts with XML declaration
    if (!str_starts_with(trim($exportXml), '<?xml')) {
        throw new Exception("Export failed: Response is not XML - " . substr($exportXml, 0, 200));
    }
    
    echo "✅ Export completed successfully!\n";
    $exportedXmlPath = '/tmp/exported_archimate.xml';
    file_put_contents($exportedXmlPath, $exportXml);
    echo "Export saved to: $exportedXmlPath\n";
    echo "Export size: " . number_format(strlen($exportXml)) . " bytes\n\n";
    
    // Step 4: Compare original vs exported XML
    echo "4️⃣ COMPARING ORIGINAL VS EXPORTED XML\n";
    echo "--------------------------------------\n";
    
    if (isset($exportedXmlPath) && file_exists($exportedXmlPath)) {
        echo "Running XML comparison...\n";
        $compareOutput = shell_exec('php /var/www/html/apps-extra/softwarecatalog/compare_archimate.php 2>&1');
        echo $compareOutput;
    } else {
        echo "❌ Cannot compare: exported XML file not found\n";
    }

    // Focus object export verification
    $latestExportPath = '/tmp/archimate_export_latest.xml';
    if ($targetObjectId) {
        echo "🔎 Focus object export lookup: {$targetObjectId} in {$latestExportPath}\n";
        $exportCheck = findInExportedXml($latestExportPath, $targetObjectId);
        if ($exportCheck['present']) {
            echo "   - Present in export under section='{$exportCheck['section']}', type='{$exportCheck['type']}', name=\"{$exportCheck['name']}\"\n\n";
        } else {
            echo "   - NOT PRESENT in export\n\n";
        }
    }

    // Step 6: Detailed per-difference triage (original XML, exported XML, database)
    echo "6️⃣ PER-DIFFERENCE TRIAGE\n";
    echo "========================\n";

    $reportPath = '/tmp/archimate_comparison_report.json';
    $reportJson = is_file($reportPath) ? file_get_contents($reportPath) : null;
    $report = $reportJson ? json_decode($reportJson, true) : null;
    if (!$report || empty($report['differences'])) {
        echo "No structured differences available (or report missing).\n\n";
    } else {
        // Load XML roots once for node lookups
        $originalXmlPath = '/var/www/html/apps-extra/softwarecatalog/lib/Settings/GEMMA_release.xml';
        $exportedXmlPath = $latestExportPath;
        $origStr = @file_get_contents($originalXmlPath);
        $expStr = @file_get_contents($exportedXmlPath);
        $origRoot = $origStr ? simplexml_load_string($origStr) : null;
        $expRoot = $expStr ? simplexml_load_string($expStr) : null;
        if ($origRoot) { $origRoot->registerXPathNamespace('archimate', 'http://www.opengroup.org/xsd/archimate/3.0/'); $origRoot->registerXPathNamespace('xsi', 'http://www.w3.org/2001/XMLSchema-instance'); }
        if ($expRoot) { $expRoot->registerXPathNamespace('archimate', 'http://www.opengroup.org/xsd/archimate/3.0/'); $expRoot->registerXPathNamespace('xsi', 'http://www.w3.org/2001/XMLSchema-instance'); }

        // Helper to fetch node XML string by section/id
        $getNodeXml = function($root, string $section, string $id): string {
            if (!$root) return '';
            switch ($section) {
                case 'elements':
                    $nodes = $root->xpath('//*[local-name()="element" and @identifier="' . $id . '"]');
                    break;
                case 'relationships':
                    $nodes = $root->xpath('//*[local-name()="relationship" and @identifier="' . $id . '"]');
                    break;
                case 'organizations':
                    $nodes = $root->xpath('//*[local-name()="organizations"]/*[local-name()="item" and @identifier="' . $id . '"]');
                    break;
                case 'property_definitions':
                    $nodes = $root->xpath('//*[local-name()="propertyDefinition" and @identifier="' . $id . '"]');
                    break;
                case 'folders':
                    $nodes = $root->xpath('//*[local-name()="folder" and @identifier="' . $id . '"]');
                    break;
                case 'views':
                    $nodes = $root->xpath('//*[local-name()="view" and @identifier="' . $id . '"]');
                    break;
                default:
                    $nodes = [];
            }
            if ($nodes && isset($nodes[0])) {
                $dom = dom_import_simplexml($nodes[0]);
                if ($dom && $dom->ownerDocument) {
                    return trim($dom->ownerDocument->saveXML($dom));
                }
            }
            return '';
        };

        // Iterate differences
        $idx = 0;
        foreach ($report['differences'] as $diff) {
            $idx++;
            $section = $diff['section'] ?? 'unknown';
            $path = $diff['path'] ?? '';
            $originalVal = $diff['original'] ?? '';
            $exportedVal = $diff['exported'] ?? '';
            $id = $path;
            $slashPos = strpos($path, '/');
            if ($slashPos !== false) { $id = substr($path, 0, $slashPos); }

            echo "#{$idx} [{$section}] {$path}\n";
            echo "- difference: original='" . substr($originalVal, 0, 200) . "' vs exported='" . substr($exportedVal, 0, 200) . "'\n";

            // How it was imported (original XML node)
            $origNode = $getNodeXml($origRoot, $section, $id);
            echo "- imported (original xml): " . ($origNode ? substr($origNode, 0, 600) : 'MISSING') . (strlen($origNode) > 600 ? '...[truncated]' : '') . "\n";

            // How it's exported (exported XML node)
            $expNode = $getNodeXml($expRoot, $section, $id);
            echo "- exported (xml): " . ($expNode ? substr($expNode, 0, 600) : 'MISSING') . (strlen($expNode) > 600 ? '...[truncated]' : '') . "\n";

            // How it's in the database
            $row = fetchObjectByUuid($id);
            if ($row) {
                $obj = $row['object_decoded'] ?? [];
                $props = $obj['properties'] ?? [];
                $propKeys = implode(',', array_slice(array_keys($props), 0, 10));
                echo "- database: found id={$row['id']} name=\"" . ($row['name'] ?? '') . "\" archimate_type=" . ($obj['archimate_type'] ?? 'NULL') . " original_archimate_type=" . ($obj['original_archimate_type'] ?? 'NULL') . " schema_id=" . ($obj['schema_id'] ?? 'NULL') . " register_id=" . ($obj['register_id'] ?? 'NULL') . " properties_keys_sample=[{$propKeys}]\n";
            } else {
                echo "- database: NOT FOUND\n";
            }

            echo "\n";
            // Optional: limit very large outputs
            if ($idx >= 200) {
                echo "... truncated after 200 differences for brevity ...\n\n";
                break;
            }
        }
    }
    
    // Step 7: Analysis and recommendations
    echo "\n7️⃣ ANALYSIS & RECOMMENDATIONS\n";
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
        echo "   2. Re-run this script to test fixes (optionally add --object=<archimate-id> to focus)\n";
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
