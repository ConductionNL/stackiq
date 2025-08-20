<?php
require_once '/var/www/html/lib/base.php';

$db = \OC::$server->getDatabaseConnection();

try {
    // Try to just select from the table without COUNT
    $query = $db->getQueryBuilder();
    $query->select('*')
          ->from('openregister_objects')
          ->setMaxResults(1);
    $result = $query->execute();
    $row = $result->fetch();
    
    if ($row) {
        echo "✓ openregister_objects table exists!" . PHP_EOL;
        echo "Columns: " . implode(', ', array_keys($row)) . PHP_EOL;
        echo "Sample row:" . PHP_EOL;
        foreach ($row as $key => $value) {
            $displayValue = is_string($value) ? substr($value, 0, 100) : $value;
            echo "  $key: " . $displayValue . PHP_EOL;
        }
    } else {
        echo "Table exists but is empty" . PHP_EOL;
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . PHP_EOL;
}

