<?php
require_once '/var/www/html/lib/base.php';

$db = \OC::$server->getDatabaseConnection();

// Get all tables that contain 'stackiq' or 'catalog'
$query = $db->getQueryBuilder();
$query->select('TABLE_NAME')
      ->from('information_schema.TABLES')
      ->where($query->expr()->eq('TABLE_SCHEMA', $query->createNamedParameter('nextcloud')))
      ->andWhere($query->expr()->orX(
          $query->expr()->like('TABLE_NAME', $query->createNamedParameter('%stackiq%')),
          $query->expr()->like('TABLE_NAME', $query->createNamedParameter('%catalog%'))
      ));

try {
    $result = $query->execute();
    $tables = [];
    while ($row = $result->fetch()) {
        $tables[] = $row['TABLE_NAME'];
    }
    
    if (empty($tables)) {
        echo "No tables found containing 'stackiq' or 'catalog'" . PHP_EOL;
        
        // Let's check for any tables that might be related
        $query2 = $db->getQueryBuilder();
        $query2->select('TABLE_NAME')
               ->from('information_schema.TABLES')
               ->where($query2->expr()->eq('TABLE_SCHEMA', $query2->createNamedParameter('nextcloud')))
               ->andWhere($query2->expr()->like('TABLE_NAME', $query2->createNamedParameter('%object%')));
        
        $result2 = $query2->execute();
        echo "Tables containing 'object':" . PHP_EOL;
        while ($row = $result2->fetch()) {
            echo "- " . $row['TABLE_NAME'] . PHP_EOL;
        }
    } else {
        echo "Found tables:" . PHP_EOL;
        foreach ($tables as $table) {
            echo "- " . $table . PHP_EOL;
        }
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . PHP_EOL;
}

