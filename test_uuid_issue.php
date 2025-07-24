<?php
/**
 * Test script to debug UUID issue
 */

require_once '/home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib/Db/Organisation.php';

use OCA\OpenRegister\Db\Organisation;

echo "Testing Organisation entity UUID handling...\n";

// Create a new organisation
$organisation = new Organisation();
$organisation->setName('Test Organisation');
$organisation->setDescription('Test Description');

// Test UUID setting
$testUuid = '71658220-8409-4139-b383-b521e637a493';
echo "Setting UUID to: $testUuid\n";

try {
    $organisation->setUuid($testUuid);
    echo "UUID set successfully\n";
    
    $retrievedUuid = $organisation->getUuid();
    echo "Retrieved UUID: $retrievedUuid\n";
    
    if ($retrievedUuid === $testUuid) {
        echo "✅ UUID match successful\n";
    } else {
        echo "❌ UUID mismatch! Expected: $testUuid, Got: $retrievedUuid\n";
    }
    
} catch (Exception $e) {
    echo "❌ Error setting UUID: " . $e->getMessage() . "\n";
}

echo "Test completed.\n"; 