<?php
require_once '/var/www/html/lib/base.php';

$db = \OC::$server->getDatabaseConnection();
$query = $db->getQueryBuilder();
$query->select('id', 'slug', 'object')
      ->from('softwarecatalog_objects')
      ->where($query->expr()->like('id', $query->createNamedParameter('%d4572e2e%')))
      ->setMaxResults(1);
$result = $query->execute();
$row = $result->fetch();

if ($row) {
    echo 'ID: ' . $row['id'] . PHP_EOL;
    echo 'Slug: ' . ($row['slug'] ?: '(empty)') . PHP_EOL;
    echo 'Object (first 500 chars): ' . substr($row['object'], 0, 500) . '...' . PHP_EOL;
    echo PHP_EOL . 'Full object structure:' . PHP_EOL;
    $obj = json_decode($row['object'], true);
    if ($obj) {
        // Show @self structure
        if (isset($obj['@self'])) {
            echo '@self: ' . json_encode($obj['@self'], JSON_PRETTY_PRINT) . PHP_EOL;
        }
        // Show flattened properties
        $flattenedProps = [];
        foreach ($obj as $key => $value) {
            if (!in_array($key, ['@self', 'xml', 'id', 'identifier', 'section', 'model_identifier', 'extracted_at'])) {
                $flattenedProps[$key] = $value;
            }
        }
        if (!empty($flattenedProps)) {
            echo 'Flattened properties: ' . json_encode($flattenedProps, JSON_PRETTY_PRINT) . PHP_EOL;
        }
    }
} else {
    echo 'No matching objects found' . PHP_EOL;
}

