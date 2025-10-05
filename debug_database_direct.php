<?php

/**
 * Debug script to check database directly
 * This script runs inside the Nextcloud container and checks the database directly
 */

require_once __DIR__ . '/../lib/base.php';

echo "=== Direct Database Debug ===\n\n";

// The contactpersoon ID from the user's example
$contactpersoonId = 'e8ba22ba-35ab-4b59-a097-91df6fa2cd3d';

echo "Looking for contactpersoon ID: " . $contactpersoonId . "\n\n";

try {
    // Get database connection
    $connection = \OC::$server->getDatabaseConnection();
    
    // Query the openregister_objects table
    $query = "SELECT * FROM oc_openregister_objects WHERE id = ?";
    $stmt = $connection->prepare($query);
    $result = $stmt->execute([$contactpersoonId]);
    $row = $stmt->fetch();
    
    if ($row) {
        echo "Found contactpersoon in database:\n";
        echo "ID: " . $row['id'] . "\n";
        echo "Name: " . $row['name'] . "\n";
        echo "Object JSON: " . $row['object'] . "\n";
        echo "Created: " . $row['created'] . "\n";
        echo "Updated: " . $row['updated'] . "\n\n";
        
        // Decode the JSON object
        $objectData = json_decode($row['object'], true);
        if ($objectData) {
            echo "Decoded object data:\n";
            echo json_encode($objectData, JSON_PRETTY_PRINT) . "\n\n";
            
            // Check for username
            if (isset($objectData['username'])) {
                echo "Username found: " . $objectData['username'] . "\n";
                
                // Check if user exists in Nextcloud
                $userManager = \OC::$server->getUserManager();
                $user = $userManager->get($objectData['username']);
                
                if ($user) {
                    echo "User exists in Nextcloud: " . $user->getUID() . "\n";
                    echo "User enabled: " . ($user->isEnabled() ? 'Yes' : 'No') . "\n";
                    echo "User disabled: " . ($user->isEnabled() ? 'No' : 'Yes') . "\n";
                } else {
                    echo "User NOT found in Nextcloud\n";
                }
            } else {
                echo "No username field in object data\n";
            }
        } else {
            echo "Failed to decode object JSON\n";
        }
    } else {
        echo "Contactpersoon NOT found in database\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}

echo "\n=== Debug Complete ===\n";
