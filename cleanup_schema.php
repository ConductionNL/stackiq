<?php

/**
 * Schema Cleanup Script
 * Removes empty, false, or null properties from schema definitions
 */

// Properties to clean up
$propertiesToClean = [
    'minLength', 'maxLength', 'example', 'immutable', 'minimum', 'maximum', 
    'multipleOf', 'exclusiveMin', 'exclusiveMax', 'minItems', 'maxItems', 
    'cascadeDelete', 'format', 'pattern', 'default', 'behavior', 'required', 
    'deprecated', 'visible', 'hideOnCollection'
];

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

echo "Starting schema cleanup...\n";

// Function to clean properties recursively
function cleanProperties(&$data, $propertiesToClean) {
    if (is_array($data)) {
        foreach ($data as $key => &$value) {
            if (is_array($value)) {
                // If this is a properties object (contains schema properties)
                if ($key === 'properties' && is_array($value)) {
                    foreach ($value as &$property) {
                        if (is_array($property)) {
                            foreach ($propertiesToClean as $propToClean) {
                                if (isset($property[$propToClean])) {
                                    $propValue = $property[$propToClean];
                                    // Remove if null, false, or empty string
                                    if ($propValue === null || $propValue === false || $propValue === '') {
                                        unset($property[$propToClean]);
                                        echo "Removed $propToClean from property\n";
                                    }
                                }
                            }
                        }
                    }
                } else {
                    // Recursively process other arrays
                    cleanProperties($value, $propertiesToClean);
                }
            }
        }
    }
}

// Clean the properties
cleanProperties($data, $propertiesToClean);

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
    echo "Schema cleanup completed successfully!\n";
    echo "Cleaned file: $jsonFile\n";
} else {
    die("Error: Could not write to file $jsonFile\n");
}

echo "Done!\n";
