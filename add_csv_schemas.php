<?php

/**
 * Add CSV-based Schemas to Voorzieningen Register
 * Creates schemas based on CSV file structures, excluding columns starting with _ or @
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

echo "Starting to add CSV-based schemas...\n";

// Function to determine property type based on sample data
function determinePropertyType($sampleValues) {
    if (empty($sampleValues)) return 'string';
    
    $sample = $sampleValues[0];
    
    // Check if it's a UUID
    if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $sample)) {
        return 'string';
    }
    
    // Check if it's a number
    if (is_numeric($sample)) {
        return 'number';
    }
    
    // Check if it's a boolean
    if (in_array(strtolower($sample), ['true', 'false', 'j', 'n', 'yes', 'no'])) {
        return 'boolean';
    }
    
    // Check if it's a date
    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $sample)) {
        return 'string';
    }
    
    // Default to string
    return 'string';
}

// Function to create schema from CSV headers
function createSchemaFromCSV($csvFile, $schemaName, $schemaTitle, $schemaDescription) {
    if (!file_exists($csvFile)) {
        echo "Warning: CSV file $csvFile not found\n";
        return null;
    }
    
    $handle = fopen($csvFile, 'r');
    if (!$handle) {
        echo "Warning: Could not open CSV file $csvFile\n";
        return null;
    }
    
    // Read headers
    $headers = fgetcsv($handle);
    if (!$headers) {
        fclose($handle);
        return null;
    }
    
    // Read a few sample rows for type detection
    $sampleRows = [];
    for ($i = 0; $i < 5; $i++) {
        $row = fgetcsv($handle);
        if ($row) {
            $sampleRows[] = $row;
        }
    }
    fclose($handle);
    
    // Filter out columns starting with _ or @, and also exclude 'id'
    $validHeaders = [];
    $validIndices = [];
    foreach ($headers as $index => $header) {
        if (!preg_match('/^[_@]/', $header) && $header !== 'id') {
            $validHeaders[] = $header;
            $validIndices[] = $index;
        }
    }
    
    // Create properties
    $properties = [];
    foreach ($validHeaders as $headerIndex => $header) {
        $sampleValues = [];
        foreach ($sampleRows as $row) {
            if (isset($row[$validIndices[$headerIndex]])) {
                $sampleValues[] = $row[$validIndices[$headerIndex]];
            }
        }
        
        $type = determinePropertyType($sampleValues);
        
        $properties[$header] = [
            "description" => "",
            "type" => $type,
            "order" => $headerIndex + 1,
            "title" => ucfirst(str_replace('_', ' ', $header))
        ];
    }
    
    // Create schema
    $schema = [
        "uri" => null,
        "slug" => $schemaName,
        "title" => $schemaTitle,
        "description" => $schemaDescription,
        "version" => "0.0.1",
        "summary" => "",
        "icon" => null,
        "required" => [],
        "properties" => $properties,
        "archive" => [],
        "source" => "",
        "hardValidation" => false,
        "updated" => date('Y-m-d\TH:i:s+00:00'),
        "created" => date('Y-m-d\TH:i:s+00:00'),
        "maxDepth" => 0,
        "owner" => "system",
        "application" => null,
        "organisation" => "cb2bca24-40bf-4568-a138-454c63ab761c",
        "groups" => null,
        "authorization" => null,
        "deleted" => null,
        "configuration" => null
    ];
    
    return $schema;
}

// Define schemas to create
$schemasToCreate = [
    [
        'csvFile' => 'lib/Settings/koppeling.csv',
        'schemaName' => 'koppeling',
        'schemaTitle' => 'Koppeling',
        'schemaDescription' => 'Schema voor koppelingen tussen modules en systemen'
    ],
    [
        'csvFile' => 'lib/Settings/koppelingGebruik.csv',
        'schemaName' => 'koppelingGebruik',
        'schemaTitle' => 'Koppeling Gebruik',
        'schemaDescription' => 'Schema voor het gebruik van koppelingen'
    ],
    [
        'csvFile' => 'lib/Settings/compliancy.csv',
        'schemaName' => 'compliancy',
        'schemaTitle' => 'Compliancy',
        'schemaDescription' => 'Schema voor compliancy en standaard ondersteuning'
    ],
    [
        'csvFile' => 'lib/Settings/moduleGebruik.csv',
        'schemaName' => 'moduleGebruik',
        'schemaTitle' => 'Module Gebruik',
        'schemaDescription' => 'Schema voor het gebruik van modules'
    ],
    [
        'csvFile' => 'lib/Settings/moduleversie.csv',
        'schemaName' => 'moduleversie',
        'schemaTitle' => 'Module Versie',
        'schemaDescription' => 'Schema voor module versies'
    ]
];

// Add schemas to the voorzieningen register
$voorzieningenRegister = &$data['components']['registers']['voorzieningen'];

// Add new schemas to the schemas list
foreach ($schemasToCreate as $schemaConfig) {
    $schema = createSchemaFromCSV($schemaConfig['csvFile'], $schemaConfig['schemaName'], $schemaConfig['schemaTitle'], $schemaConfig['schemaDescription']);
    
    if ($schema) {
        // Add to schemas list if not already present
        if (!in_array($schemaConfig['schemaName'], $voorzieningenRegister['schemas'])) {
            $voorzieningenRegister['schemas'][] = $schemaConfig['schemaName'];
        }
        
        // Add to schemas object
        $data['components']['schemas'][$schemaConfig['schemaName']] = $schema;
        
        echo "Added schema: {$schemaConfig['schemaName']}\n";
    }
}

// Convert back to JSON with pretty formatting
$updatedJson = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

if ($updatedJson === false) {
    die("Error: Could not encode JSON\n");
}

// Create backup
$backupFile = $jsonFile . '.backup.' . date('Y-m-d_H-i-s');
if (copy($jsonFile, $backupFile)) {
    echo "Backup created: $backupFile\n";
} else {
    echo "Warning: Could not create backup\n";
}

// Write the updated JSON back to file
if (file_put_contents($jsonFile, $updatedJson) !== false) {
    echo "CSV schemas added successfully!\n";
    echo "Updated file: $jsonFile\n";
} else {
    die("Error: Could not write to file $jsonFile\n");
}

echo "Done!\n";
