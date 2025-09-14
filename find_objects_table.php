<?php
require_once '/var/www/html/lib/base.php';

$db = \OC::$server->getDatabaseConnection();

// Try different possible table names for OpenRegister
$possibleTables = [
    'oc_openregister_objects',
    'oc_openregister_object',
    'oc_or_objects',
    'openregister_objects',
    'objects'
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
        
        // Show table structure
        if ($count > 0) {
            $query2 = $db->getQueryBuilder();
            $query2->select('*')
                   ->from($tableName)
                   ->setMaxResults(1);
            $result2 = $query2->execute();
            $row = $result2->fetch();
            if ($row) {
                echo "  Columns: " . implode(', ', array_keys($row)) . PHP_EOL;
            }
        }
    } catch (Exception $e) {
        // Table doesn't exist, continue silently
        continue;
    }
}

// Let's also try to find our specific object by searching in all possible ways
echo PHP_EOL . "Searching for our test object..." . PHP_EOL;

// Try the most likely table name
try {
    $query = $db->getQueryBuilder();
    $query->select('*')
          ->from('oc_openregister_objects')
          ->where($query->expr()->like('object', $query->createNamedParameter('%d4572e2e-aa3e-4cbe-94aa-16f0e2ae9620%')))
          ->setMaxResults(1);
    $result = $query->execute();
    $row = $result->fetch();
    
    if ($row) {
        echo "Found object in oc_openregister_objects!" . PHP_EOL;
        echo "ID: " . $row['id'] . PHP_EOL;
        if (isset($row['slug'])) {
            echo "Slug: " . ($row['slug'] ?: '(empty)') . PHP_EOL;
        }
        echo "Object preview: " . substr($row['object'], 0, 300) . '...' . PHP_EOL;
    }
} catch (Exception $e) {
    echo "Error searching in oc_openregister_objects: " . $e->getMessage() . PHP_EOL;
}

