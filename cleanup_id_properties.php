<?php

/**
 * Cleanup ID Properties from Schemas
 * Removes all 'id' properties from schema definitions to prevent conflicts with OpenRegister
 */

// Load the JSON file
$jsonFile = 'lib/Settings/softwarecatalogus_register.json';
$jsonContent = file_get_contents($jsonFile);

if ($jsonContent === false) {
    die("Error: Could not read file $jsonFile\n");
}

$data = json_decode($jsonContent, true);

if ($data === null) {
    die("Error: Invalid JSON in file $jsonFile\n");
}

echo "Starting ID property cleanup...\n";

// Function to recursively remove 'id' properties
function removeIdProperties(&$data) {
    if (is_array($data)) {
        foreach ($data as $key => &$value) {
            if (is_array($value)) {
                // If this is a properties object (contains schema properties)
                if ($key === 'properties' && is_array($value)) {
                    // Remove 'id' property if it exists
                    if (isset($value['id'])) {
                        unset($value['id']);
                        echo "Removed 'id' property from schema\n";
                    }
                    
                    // Also check nested properties for 'id'
                    foreach ($value as &$property) {
                        if (is_array($property) && isset($property['properties']) && is_array($property['properties'])) {
                            if (isset($property['properties']['id'])) {
                                unset($property['properties']['id']);
                                echo "Removed nested 'id' property from schema\n";
                            }
                        }
                    }
                } else {
                    // Recursively process other arrays
                    removeIdProperties($value);
                }
            }
        }
    }
}

// Clean the ID properties
removeIdProperties($data);

// Convert back to JSON with pretty formatting
$cleanedJson = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

if ($cleanedJson === false) {
    die("Error: Could not encode JSON\n");
}

// Create backup
$backupFile = $jsonFile . '.backup.' . date('Y-m-d_H-i-s');
if (copy($jsonFile, $backupFile)) {
    echo "Backup created: $backupFile\n";
} else {
    echo "Warning: Could not create backup\n";
}

// Write the cleaned JSON back to file
if (file_put_contents($jsonFile, $cleanedJson) !== false) {
    echo "ID property cleanup completed successfully!\n";
    echo "Cleaned file: $jsonFile\n";
} else {
    die("Error: Could not write to file $jsonFile\n");
}

echo "Done!\n";


