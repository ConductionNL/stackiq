<?php
require_once '/var/www/html/lib/base.php';

$db = \OC::$server->getDatabaseConnection();

// Try different possible table names
$possibleTables = [
    'oc_openregister_objects',
    'oc_openregister_object',
    'openregister_objects',
    'objects',
    'oc_stackiq_objects'
];

foreach ($possibleTables as $tableName) {
    try {
        $query = $db->getQueryBuilder();
        $query->select('COUNT(*)')
              ->from($tableName);
        $result = $query->execute();
        $count = $result->fetchOne();
        echo "Table '$tableName' exists with $count rows" . PHP_EOL;
        
        // If we found a table with data, let's check for our specific object
        if ($count > 0) {
            $query2 = $db->getQueryBuilder();
            $query2->select('id', 'slug', 'object')
                   ->from($tableName)
                   ->where($query2->expr()->like('id', $query2->createNamedParameter('%d4572e2e%')))
                   ->setMaxResults(1);
            $result2 = $query2->execute();
            $row = $result2->fetch();
            
            if ($row) {
                echo "Found matching object in '$tableName':" . PHP_EOL;
                echo "ID: " . $row['id'] . PHP_EOL;
                echo "Slug: " . ($row['slug'] ?: '(empty)') . PHP_EOL;
                echo "Object preview: " . substr($row['object'], 0, 200) . '...' . PHP_EOL;
                break;
            }
        }
    } catch (Exception $e) {
        // Table doesn't exist, continue
        continue;
    }
}

