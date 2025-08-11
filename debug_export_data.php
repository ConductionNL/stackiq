<?php
/**
 * Debug script to examine actual export data structure
 */

require_once '/var/www/html/lib/base.php';

// Initialize Nextcloud
\OC::$CLI = true;

// Use the same approach as ArchiMateExportService
$registerId = 6;
$connection = \OC::$server->getDatabaseConnection();

$qb = $connection->getQueryBuilder();
$qb->select('*')
   ->from('openregister_objects')
   ->where($qb->expr()->eq('register_id', $qb->createNamedParameter($registerId, \PDO::PARAM_INT)))
   ->setMaxResults(10); // Just get first 10 objects

$result = $qb->executeQuery();
$objects = $result->fetchAll();
$sampleCount = 3;
$count = 0;

echo "=== DEBUGGING EXPORT DATA STRUCTURE ===\n\n";

foreach ($objects as $object) {
    if ($count >= $sampleCount) break;
    
    if (is_object($object) && method_exists($object, 'jsonSerialize')) {
        $object = $object->jsonSerialize();
    }
    
    if (isset($object['section']) && $object['section'] === 'elements') {
        echo "--- ELEMENT SAMPLE #" . ($count + 1) . " ---\n";
        echo "Section: " . $object['section'] . "\n";
        echo "Identifier: " . ($object['identifier'] ?? 'NOT_FOUND') . "\n";
        
        // Check for xsi:type in various forms
        $xsiTypeFound = false;
        foreach (['xsi:type', 'xsi_type', '_xsi:type', '_type', 'type'] as $key) {
            if (isset($object[$key])) {
                echo "Found xsi:type as '$key': " . $object[$key] . "\n";
                $xsiTypeFound = true;
                break;
            }
        }
        
        if (!$xsiTypeFound) {
            echo "xsi:type: NOT_FOUND\n";
        }
        
        // Check _attributes
        if (isset($object['_attributes'])) {
            echo "_attributes keys: " . implode(', ', array_keys($object['_attributes'])) . "\n";
            foreach ($object['_attributes'] as $attrKey => $attrVal) {
                if (strpos($attrKey, 'type') !== false) {
                    echo "  -> $attrKey: $attrVal\n";
                }
            }
        }
        
        // Show all root keys
        echo "All root keys: " . implode(', ', array_keys($object)) . "\n";
        
        echo "\n";
        $count++;
    }
}

// Also check a relationship sample
foreach ($objects as $object) {
    if (is_object($object) && method_exists($object, 'jsonSerialize')) {
        $object = $object->jsonSerialize();
    }
    
    if (isset($object['section']) && $object['section'] === 'relationships') {
        echo "--- RELATIONSHIP SAMPLE ---\n";
        echo "Section: " . $object['section'] . "\n";
        echo "Identifier: " . ($object['identifier'] ?? 'NOT_FOUND') . "\n";
        
        // Check for xsi:type in various forms
        $xsiTypeFound = false;
        foreach (['xsi:type', 'xsi_type', '_xsi:type', '_type', 'type'] as $key) {
            if (isset($object[$key])) {
                echo "Found xsi:type as '$key': " . $object[$key] . "\n";
                $xsiTypeFound = true;
                break;
            }
        }
        
        if (!$xsiTypeFound) {
            echo "xsi:type: NOT_FOUND\n";
        }
        
        // Check _attributes
        if (isset($object['_attributes'])) {
            echo "_attributes keys: " . implode(', ', array_keys($object['_attributes'])) . "\n";
        }
        
        echo "\n";
        break;
    }
}
