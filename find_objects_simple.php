<?php
require_once '/var/www/html/lib/base.php';

$db = \OC::$server->getDatabaseConnection();

// Try simpler table names
$possibleTables = [
    'openregister_objects'
];

echo "Checking for OpenRegister tables..." . PHP_EOL;

foreach ($possibleTables as $tableName) {
    try {
        $query = $db->getQueryBuilder();
        $query->select('COUNT(*)')
              ->from($tableName);
        $result = $query->execute();
        $count = $result->fetchOne();
        echo "✓ Table '$tableName' exists with $count rows" . PHP_EOL;
        
        if ($count > 0) {
            // Look for our specific object
            $query2 = $db->getQueryBuilder();
            $query2->select('*')
                   ->from($tableName)
                   ->where($query2->expr()->like('object', $query2->createNamedParameter('%d4572e2e-aa3e-4cbe-94aa-16f0e2ae9620%')))
                   ->setMaxResults(1);
            $result2 = $query2->execute();
            $row = $result2->fetch();
            
            if ($row) {
                echo "Found matching object!" . PHP_EOL;
                echo "ID: " . $row['id'] . PHP_EOL;
                if (isset($row['slug'])) {
                    echo "Slug: " . ($row['slug'] ?: '(empty)') . PHP_EOL;
                }
                echo "Object (first 500 chars): " . substr($row['object'], 0, 500) . '...' . PHP_EOL;
                
                // Parse and show structure
                $obj = json_decode($row['object'], true);
                if ($obj && isset($obj['@self'])) {
                    echo PHP_EOL . "@self structure:" . PHP_EOL;
                    echo json_encode($obj['@self'], JSON_PRETTY_PRINT) . PHP_EOL;
                }
                
                // Show flattened properties
                if ($obj) {
                    $flattened = [];
                    foreach (['GEMMA type', 'Object ID', 'GEMMA thema'] as $prop) {
                        if (isset($obj[$prop])) {
                            $flattened[$prop] = $obj[$prop];
                        }
                    }
                    if (!empty($flattened)) {
                        echo PHP_EOL . "Flattened properties:" . PHP_EOL;
                        echo json_encode($flattened, JSON_PRETTY_PRINT) . PHP_EOL;
                    }
                }
            } else {
                echo "No matching object found in $tableName" . PHP_EOL;
            }
        }
    } catch (Exception $e) {
        echo "Error with table '$tableName': " . $e->getMessage() . PHP_EOL;
    }
}

