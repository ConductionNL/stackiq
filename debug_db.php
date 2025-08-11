<?php
require_once '/var/www/html/lib/base.php';

// Initialize Nextcloud
\OC::$CLI = true;

try {
    $db = \OC::$server->getDatabaseConnection();
    
    // First, let's see the table structure
    echo "=== TABLE STRUCTURE ===\n";
    $query = $db->prepare('DESCRIBE oc_openregister_objects');
    $query->execute();
    $columns = $query->fetchAll();
    
    foreach ($columns as $column) {
        echo $column['Field'] . " (" . $column['Type'] . ")\n";
    }
    
    // Query for a sample element using uuid instead
    echo "\n=== ELEMENT DATA ===\n";
    $query = $db->prepare('SELECT id, uuid, name, object FROM oc_openregister_objects WHERE uuid = ? LIMIT 1');
    $query->execute(['id-009fa62f25844aa3a87d252bf2b6bb0c']);
    $result = $query->fetch();
    
    if ($result) {
        echo "DB ID: " . $result['id'] . "\n";
        echo "UUID (ArchiMate ID): " . $result['uuid'] . "\n";
        echo "Name: " . $result['name'] . "\n";
        
        // Parse the JSON object
        $objectData = json_decode($result['object'], true);
        if ($objectData) {
            echo "ArchiMate Type: " . ($objectData['archimate_type'] ?? 'NULL') . "\n";
            echo "Original ArchiMate Type: " . ($objectData['original_archimate_type'] ?? 'NULL') . "\n";
            echo "Documentation: " . substr($objectData['documentation'] ?? 'NULL', 0, 100) . "...\n";
            echo "Properties count: " . (isset($objectData['properties']) ? count($objectData['properties']) : 0) . "\n";
            
            echo "Full JSON object (first 800 chars):\n";
            echo substr(json_encode($objectData, JSON_PRETTY_PRINT), 0, 800) . "...\n";
            
            if (isset($objectData['properties']) && is_array($objectData['properties'])) {
                echo "All properties:\n";
                foreach ($objectData['properties'] as $key => $value) {
                    echo "  '$key': " . substr($value, 0, 50) . "...\n";
                }
            }
        } else {
            echo "Failed to parse object JSON\n";
        }
    } else {
        echo "No element found with that ID\n";
    }
    
    // Query for property definitions
    echo "\n=== PROPERTY DEFINITIONS ===\n";
    $query2 = $db->prepare('SELECT COUNT(*) as count FROM oc_openregister_objects WHERE uuid LIKE ?');
    $query2->execute(['propid-%']);
    $propCount = $query2->fetch();
    echo "Property definitions count: " . $propCount['count'] . "\n";
    
    // Get a sample property definition
    $query3 = $db->prepare('SELECT uuid, name, object FROM oc_openregister_objects WHERE uuid LIKE ? LIMIT 1');
    $query3->execute(['propid-%']);
    $propResult = $query3->fetch();
    
    if ($propResult) {
        echo "Sample property definition:\n";
        echo "  UUID: " . $propResult['uuid'] . "\n";
        echo "  Name: " . $propResult['name'] . "\n";
        
        $propObjectData = json_decode($propResult['object'], true);
        if ($propObjectData) {
            echo "  Type: " . ($propObjectData['type'] ?? 'NULL') . "\n";
            echo "  ArchiMate Type: " . ($propObjectData['archimate_type'] ?? 'NULL') . "\n";
        }
    }
    
    // Query for relationships with names
    echo "\n=== RELATIONSHIPS ===\n";
    $query4 = $db->prepare('SELECT uuid, name, object FROM oc_openregister_objects WHERE uuid = ? LIMIT 1');
    $query4->execute(['id-3b28f55b7fb24b0d9b09cd1d5eef8e3e']); // This relationship should have name "A"
    $relResult = $query4->fetch();
    
    if ($relResult) {
        echo "Sample relationship:\n";
        echo "  UUID: " . $relResult['uuid'] . "\n";
        echo "  Name: " . $relResult['name'] . "\n";
        
        $relObjectData = json_decode($relResult['object'], true);
        if ($relObjectData) {
            echo "  Name in JSON: " . ($relObjectData['name'] ?? 'NULL') . "\n";
            echo "  ArchiMate Type: " . ($relObjectData['archimate_type'] ?? 'NULL') . "\n";
            echo "  Original ArchiMate Type: " . ($relObjectData['original_archimate_type'] ?? 'NULL') . "\n";
        }
    } else {
        echo "Relationship not found\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
