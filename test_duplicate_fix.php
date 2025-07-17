#!/usr/bin/env php
<?php
/**
 * Test script to verify that duplicate contactgegevens objects are not created
 * when organizations are updated.
 *
 * This script tests the fix for the duplicate contactgegevens issue by:
 * 1. Creating a test organization with contactpersonen
 * 2. Updating the organization
 * 3. Checking that no duplicate contactgegevens objects were created
 *
 * Usage: php test_duplicate_fix.php
 */

// Include Nextcloud bootstrap
require_once '/var/www/html/config/config.php';
require_once '/var/www/html/lib/base.php';

use OCA\SoftwareCatalog\Service\SettingsService;
use OCA\SoftwareCatalog\Service\SoftwareCatalogueService;
use OCA\OpenRegister\Service\ObjectService;

// Helper function to get a service
function getService($serviceName) {
    try {
        return \OC::$server->get($serviceName);
    } catch (\Exception $e) {
        echo "Error getting service $serviceName: " . $e->getMessage() . "\n";
        return null;
    }
}

// Helper function to create test organization data
function createTestOrganizationData() {
    return [
        'naam' => 'Test Organization ' . time(),
        'website' => 'www.test-' . time() . '.com',
        'beoordeling' => 'Actief',
        'type' => 'Leverancier',
        'beschrijvingKort' => 'Test organization for duplicate fix testing',
        'contactpersonen' => [
            [
                'voornaam' => 'John',
                'achternaam' => 'Doe',
                'email' => 'john.doe.test.' . time() . '@example.com',
                'telefoon' => '123-456-7890',
                'functie' => 'beheerder'
            ],
            [
                'voornaam' => 'Jane',
                'achternaam' => 'Smith',
                'email' => 'jane.smith.test.' . time() . '@example.com',
                'telefoon' => '123-456-7891',
                'functie' => 'gebruiker'
            ]
        ]
    ];
}

// Helper function to count contactgegevens for an organization
function countContactgegevensForOrganization($organizationUuid, $objectService, $settingsService) {
    try {
        $registerId = $settingsService->getVoorzieningenRegisterId();
        $contactgegevensSchemaId = $settingsService->getSchemaIdForObjectType('contactgegevens');
        
        if (!$registerId || !$contactgegevensSchemaId) {
            echo "Error: Register or schema ID not configured\n";
            return 0;
        }
        
        $searchFilters = ['organisation' => $organizationUuid];
        $contactgegevens = $objectService->findAll($searchFilters, $registerId, $contactgegevensSchemaId);
        
        return count($contactgegevens);
    } catch (\Exception $e) {
        echo "Error counting contactgegevens: " . $e->getMessage() . "\n";
        return 0;
    }
}

// Main test function
function runDuplicateTest() {
    echo "=== Testing Duplicate Contactgegevens Fix ===\n\n";
    
    // Get required services
    $settingsService = getService(SettingsService::class);
    $catalogueService = getService(SoftwareCatalogueService::class);
    $objectService = getService(ObjectService::class);
    
    if (!$settingsService || !$catalogueService || !$objectService) {
        echo "Error: Could not get required services\n";
        return false;
    }
    
    // Get configuration
    $registerId = $settingsService->getVoorzieningenRegisterId();
    $organizationSchemaId = $settingsService->getSchemaIdForObjectType('organisatie');
    
    if (!$registerId || !$organizationSchemaId) {
        echo "Error: Register or schema ID not configured\n";
        return false;
    }
    
    echo "Using Register ID: $registerId\n";
    echo "Using Organization Schema ID: $organizationSchemaId\n\n";
    
    // Step 1: Create test organization
    echo "Step 1: Creating test organization...\n";
    $testData = createTestOrganizationData();
    $organizationObject = $objectService->saveObject($testData, [], $registerId, $organizationSchemaId);
    
    if (!$organizationObject) {
        echo "Error: Failed to create test organization\n";
        return false;
    }
    
    $organizationUuid = $organizationObject->getUuid();
    echo "Created organization with UUID: $organizationUuid\n";
    
    // Step 2: Process the organization (should create contactgegevens)
    echo "\nStep 2: Processing organization (creating contactgegevens)...\n";
    $catalogueService->processOrganization($organizationObject);
    
    // Count contactgegevens after initial creation
    $initialCount = countContactgegevensForOrganization($organizationUuid, $objectService, $settingsService);
    echo "Initial contactgegevens count: $initialCount\n";
    
    // Step 3: Update the organization (should NOT create duplicates)
    echo "\nStep 3: Updating organization (should not create duplicates)...\n";
    $updatedData = $organizationObject->getObject();
    $updatedData['beschrijvingKort'] = 'Updated description at ' . date('Y-m-d H:i:s');
    
    $updatedOrganization = $objectService->saveObject($updatedData, [], $registerId, $organizationSchemaId, $organizationUuid);
    
    // Process the updated organization
    $catalogueService->processOrganization($updatedOrganization);
    
    // Count contactgegevens after update
    $afterUpdateCount = countContactgegevensForOrganization($organizationUuid, $objectService, $settingsService);
    echo "Contactgegevens count after update: $afterUpdateCount\n";
    
    // Step 4: Test another update
    echo "\nStep 4: Second update test...\n";
    $updatedData2 = $updatedOrganization->getObject();
    $updatedData2['beschrijvingKort'] = 'Second update at ' . date('Y-m-d H:i:s');
    
    $updatedOrganization2 = $objectService->saveObject($updatedData2, [], $registerId, $organizationSchemaId, $organizationUuid);
    $catalogueService->processOrganization($updatedOrganization2);
    
    $afterSecondUpdateCount = countContactgegevensForOrganization($organizationUuid, $objectService, $settingsService);
    echo "Contactgegevens count after second update: $afterSecondUpdateCount\n";
    
    // Step 5: Verify results
    echo "\n=== Test Results ===\n";
    echo "Initial contactgegevens count: $initialCount\n";
    echo "After first update: $afterUpdateCount\n";
    echo "After second update: $afterSecondUpdateCount\n";
    
    if ($initialCount === $afterUpdateCount && $afterUpdateCount === $afterSecondUpdateCount) {
        echo "✓ SUCCESS: No duplicate contactgegevens were created during updates!\n";
        $success = true;
    } else {
        echo "✗ FAILURE: Duplicate contactgegevens were created during updates!\n";
        $success = false;
    }
    
    // Cleanup
    echo "\nCleaning up test data...\n";
    try {
        $objectService->deleteObject($organizationUuid, $registerId, $organizationSchemaId);
        echo "Test organization deleted.\n";
    } catch (\Exception $e) {
        echo "Warning: Could not delete test organization: " . $e->getMessage() . "\n";
    }
    
    return $success;
}

// Run the test
if (runDuplicateTest()) {
    echo "\n🎉 All tests passed!\n";
    exit(0);
} else {
    echo "\n❌ Tests failed!\n";
    exit(1);
} 