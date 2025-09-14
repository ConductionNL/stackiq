<?php
require_once '/var/www/html/lib/base.php';

$db = \OC::$server->getDatabaseConnection();

try {
    // Search for our specific test object
    $query = $db->getQueryBuilder();
    $query->select('*')
          ->from('openregister_objects')
          ->where($query->expr()->like('uuid', $query->createNamedParameter('%d4572e2e-aa3e-4cbe-94aa-16f0e2ae9620%')))
          ->setMaxResults(1);
    $result = $query->execute();
    $row = $result->fetch();
    
    if ($row) {
        echo "🎉 Found our test object!" . PHP_EOL;
        echo "Database ID: " . $row['id'] . PHP_EOL;
        echo "UUID: " . $row['uuid'] . PHP_EOL;
        echo "Slug: " . ($row['slug'] ?: '(empty)') . PHP_EOL;
        echo "Register: " . $row['register'] . PHP_EOL;
        echo "Schema: " . $row['schema'] . PHP_EOL;
        echo PHP_EOL;
        
        // Parse the JSON object
        $obj = json_decode($row['object'], true);
        if ($obj) {
            echo "=== OBJECT STRUCTURE ANALYSIS ===" . PHP_EOL;
            
            // Check @self structure
            if (isset($obj['@self'])) {
                echo "✓ @self structure:" . PHP_EOL;
                foreach ($obj['@self'] as $key => $value) {
                    echo "  $key: " . $value . PHP_EOL;
                }
                echo PHP_EOL;
            } else {
                echo "❌ No @self structure found" . PHP_EOL;
            }
            
            // Check for flattened properties
            $expectedProps = ['GEMMA type', 'Object ID', 'GEMMA thema'];
            echo "=== FLATTENED PROPERTIES CHECK ===" . PHP_EOL;
            $foundProps = [];
            foreach ($expectedProps as $prop) {
                if (isset($obj[$prop])) {
                    echo "✓ $prop: " . $obj[$prop] . PHP_EOL;
                    $foundProps[] = $prop;
                } else {
                    echo "❌ $prop: NOT FOUND" . PHP_EOL;
                }
            }
            echo PHP_EOL;
            
            // Check if properties are in xml field instead
            if (isset($obj['xml']) && is_array($obj['xml'])) {
                echo "=== PROPERTIES IN XML FIELD (SHOULD BE EMPTY) ===" . PHP_EOL;
                $xmlProps = [];
                foreach ($expectedProps as $prop) {
                    if (isset($obj['xml'][$prop])) {
                        echo "⚠️  $prop found in xml field: " . $obj['xml'][$prop] . PHP_EOL;
                        $xmlProps[] = $prop;
                    }
                }
                if (empty($xmlProps)) {
                    echo "✓ No flattened properties found in xml field (good!)" . PHP_EOL;
                }
            }
            
            echo PHP_EOL . "=== SUMMARY ===" . PHP_EOL;
            echo "Root level properties found: " . count($foundProps) . "/" . count($expectedProps) . PHP_EOL;
            echo "Database slug field: " . ($row['slug'] ? "✓ " . $row['slug'] : "❌ EMPTY") . PHP_EOL;
            echo "@self.slug: " . (isset($obj['@self']['slug']) ? "✓ " . $obj['@self']['slug'] : "❌ NOT SET") . PHP_EOL;
        } else {
            echo "❌ Failed to parse object JSON" . PHP_EOL;
        }
    } else {
        echo "❌ Test object not found" . PHP_EOL;
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . PHP_EOL;
}

