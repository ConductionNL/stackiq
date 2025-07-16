<?php

/**
 * Test script to reproduce the "Not found" error when saving an organization
 * with contactpersonen set to {} (empty object)
 */

// Set up basic environment
$baseDir = __DIR__;
require_once $baseDir . '/../../lib/base.php';

// Initialize app
\OC::$server->getAppManager()->loadApp('softwarecatalog');
\OC::$server->getAppManager()->loadApp('openregister');

echo "=== Testing Empty Contactpersonen Issue ===\n\n";

try {
    // Get required services
    $settingsService = \OC::$server->get('OCA\SoftwareCatalog\Service\SettingsService');
    $objectService = \OC::$server->get('OCA\OpenRegister\Service\ObjectService');
    
    // Get configuration
    $registerId = $settingsService->getVoorzieningenRegisterId();
    $organizationSchemaId = $settingsService->getSchemaIdForObjectType('organisatie');
    
    echo "Register ID: $registerId\n";
    echo "Organization Schema ID: $organizationSchemaId\n\n";
    
    // Test organization data with empty contactpersonen
    $testData = [
        'naam' => 'Test Organization',
        'website' => 'www.test.com',
        'beoordeling' => 'Actief',
        'contactpersonen' => [], // Empty array
        'type' => 'Leverancier',
        'beschrijvingKort' => 'Test organization for empty contactpersonen'
    ];
    
    echo "Step 1: Creating organization with empty contactpersonen array...\n";
    
    // Create organization
    $organizationObject = $objectService->saveObject($testData, [], $registerId, $organizationSchemaId);
    
    if ($organizationObject) {
        echo "✅ Organization created successfully: " . $organizationObject->getUuid() . "\n";
        
        // Now test processing this organization
        echo "\nStep 2: Processing organization through SoftwareCatalogueService...\n";
        
        $catalogueService = \OC::$server->get('OCA\SoftwareCatalog\Service\SoftwareCatalogueService');
        
        try {
            $result = $catalogueService->processOrganization($organizationObject);
            echo "✅ Organization processed successfully: " . ($result ? 'true' : 'false') . "\n";
        } catch (\Exception $e) {
            echo "❌ Error processing organization: " . $e->getMessage() . "\n";
            echo "Trace:\n" . $e->getTraceAsString() . "\n";
        }
        
        // Test with empty object {} instead of empty array []
        echo "\nStep 3: Testing with contactpersonen as empty object {}...\n";
        
        $testData['contactpersonen'] = new \stdClass(); // Empty object
        
        try {
            $updatedOrganization = $objectService->saveObject($testData, [], $registerId, $organizationSchemaId, $organizationObject->getUuid());
            echo "✅ Organization updated with empty object successfully\n";
            
            // Process the updated organization
            $result = $catalogueService->processOrganization($updatedOrganization);
            echo "✅ Organization with empty object processed successfully: " . ($result ? 'true' : 'false') . "\n";
        } catch (\Exception $e) {
            echo "❌ Error with empty object: " . $e->getMessage() . "\n";
            echo "Trace:\n" . $e->getTraceAsString() . "\n";
        }
        
        // Clean up - delete the test organization
        echo "\nStep 4: Cleaning up test organization...\n";
        try {
            $objectService->deleteObject($organizationObject->getUuid(), $registerId);
            echo "✅ Test organization deleted\n";
        } catch (\Exception $e) {
            echo "⚠️ Failed to delete test organization: " . $e->getMessage() . "\n";
        }
        
    } else {
        echo "❌ Failed to create organization\n";
    }
    
} catch (\Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "Trace:\n" . $e->getTraceAsString() . "\n";
}

echo "\n=== Test Complete ===\n"; 