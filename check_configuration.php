<?php

/**
 * Configuration check script for SoftwareCatalog
 * 
 * This script verifies and optionally fixes the configuration settings
 * for the SoftwareCatalog app to ensure proper integration with OpenRegister.
 * 
 * Expected configuration:
 * - Register ID: Dynamic (from configuration, e.g., 6 local, 14 online)
 * - Organisatie Schema ID: Dynamic (from configuration, e.g., 35 local, 37 online)
 * - Contactgegevens Schema ID: Dynamic (from configuration, e.g., 34 local, 36 online)
 * 
 * Usage: php check_configuration.php [--fix]
 */

// Set up basic environment
$baseDir = __DIR__;
require_once $baseDir . '/../../lib/base.php';

// Initialize app
\OC::$server->getAppManager()->loadApp('softwarecatalog');
\OC::$server->getAppManager()->loadApp('openregister');

$appName = 'softwarecatalog';
$config = \OC::$server->getConfig();

echo "=== SoftwareCatalog Configuration Check ===\n\n";

// Check if --fix flag is provided
$fixMode = in_array('--fix', $argv);

if ($fixMode) {
    echo "🔧 Fix mode enabled - will update configuration if needed\n\n";
} else {
    echo "🔍 Check mode - add '--fix' to update configuration\n\n";
}

// Expected configuration values
$expectedConfig = [
    'voorzieningen_organisatie_schema' => 35,
    'voorzieningen_contactgegevens_schema' => 34,
    'voorzieningen_gebruiker_schema' => 42
];

echo "1. Checking current configuration...\n";
$needsUpdate = false;

foreach ($expectedConfig as $key => $expectedValue) {
    $currentValue = $config->getValueString($appName, $key, '');
    $currentInt = $currentValue ? (int)$currentValue : null;
    
    if ($currentInt === $expectedValue) {
        echo "   ✅ $key: $currentValue (correct)\n";
    } else {
        echo "   ❌ $key: " . ($currentValue ?: 'NOT SET') . " (expected: $expectedValue)\n";
        $needsUpdate = true;
        
        if ($fixMode) {
            $config->setValueString($appName, $key, (string)$expectedValue);
            echo "   🔧 Updated $key to $expectedValue\n";
        }
    }
}

echo "\n2. Testing SettingsService...\n";
try {
    $settingsService = \OC::$server->get('OCA\SoftwareCatalog\Service\SettingsService');
    
    // Test schema lookups
    $contactgegevensSchemaId = $settingsService->getSchemaIdForObjectType('contactgegevens');
    $organisatieSchemaId = $settingsService->getSchemaIdForObjectType('organisatie');
    $gebruikerSchemaId = $settingsService->getSchemaIdForObjectType('gebruiker');
    
    echo "   - Contactgegevens schema ID: " . ($contactgegevensSchemaId ?? 'NOT SET') . "\n";
    echo "   - Organisatie schema ID: " . ($organisatieSchemaId ?? 'NOT SET') . "\n";
    echo "   - Gebruiker schema ID: " . ($gebruikerSchemaId ?? 'NOT SET') . "\n";
    
    // Verify correct values
    if ($contactgegevensSchemaId === 34) {
        echo "   ✅ Contactgegevens schema ID is correct\n";
    } else {
        echo "   ❌ Contactgegevens schema ID is incorrect (expected: 34)\n";
    }
    
    if ($organisatieSchemaId === 35) {
        echo "   ✅ Organisatie schema ID is correct\n";
    } else {
        echo "   ❌ Organisatie schema ID is incorrect (expected: 35)\n";
    }
    
} catch (\Exception $e) {
    echo "   ❌ Error testing SettingsService: " . $e->getMessage() . "\n";
}

echo "\n3. Testing OpenRegister availability...\n";
try {
    $objectService = \OC::$server->get('OCA\OpenRegister\Service\ObjectService');
    
    // Test register access
    $registers = $objectService->getRegisters();
    echo "   - Available registers: " . count($registers) . "\n";
    
    // Look for the configured voorzieningen register
    $voorzieningenRegister = null;
    $expectedRegisterId = $settingsService->getVoorzieningenRegisterId();
    
    foreach ($registers as $register) {
        if ($register['id'] == $expectedRegisterId) {
            $voorzieningenRegister = $register;
            break;
        }
    }
    
    if ($voorzieningenRegister) {
        echo "   ✅ Voorzieningen register (ID $expectedRegisterId) found: " . $voorzieningenRegister['title'] . "\n";
        
        // Check schemas
        $schemas = $voorzieningenRegister['schemas'] ?? [];
        echo "   - Schemas in register: " . count($schemas) . "\n";
        
        $foundSchemas = [];
        foreach ($schemas as $schema) {
            $foundSchemas[$schema['id']] = $schema['title'];
        }
        
        // Get expected schema IDs from configuration
        $expectedContactgegevensSchemaId = $settingsService->getSchemaIdForObjectType('contactgegevens');
        $expectedOrganisatieSchemaId = $settingsService->getSchemaIdForObjectType('organisatie');
        $expectedGebruikerSchemaId = $settingsService->getSchemaIdForObjectType('gebruiker');
        
        // Check for expected schemas
        if ($expectedContactgegevensSchemaId && isset($foundSchemas[$expectedContactgegevensSchemaId])) {
            echo "   ✅ Contactgegevens schema (ID $expectedContactgegevensSchemaId) found: " . $foundSchemas[$expectedContactgegevensSchemaId] . "\n";
        } else {
            echo "   ❌ Contactgegevens schema (ID " . ($expectedContactgegevensSchemaId ?: 'NOT_CONFIGURED') . ") not found in register\n";
        }
        
        if ($expectedOrganisatieSchemaId && isset($foundSchemas[$expectedOrganisatieSchemaId])) {
            echo "   ✅ Organisatie schema (ID $expectedOrganisatieSchemaId) found: " . $foundSchemas[$expectedOrganisatieSchemaId] . "\n";
        } else {
            echo "   ❌ Organisatie schema (ID " . ($expectedOrganisatieSchemaId ?: 'NOT_CONFIGURED') . ") not found in register\n";
        }
        
        if ($expectedGebruikerSchemaId && isset($foundSchemas[$expectedGebruikerSchemaId])) {
            echo "   ✅ Gebruiker schema (ID $expectedGebruikerSchemaId) found: " . $foundSchemas[$expectedGebruikerSchemaId] . "\n";
        } else {
            echo "   ❌ Gebruiker schema (ID " . ($expectedGebruikerSchemaId ?: 'NOT_CONFIGURED') . ") not found in register\n";
        }
        
    } else {
        echo "   ❌ Voorzieningen register (ID $expectedRegisterId) not found!\n";
        echo "   Available registers:\n";
        foreach ($registers as $register) {
            echo "     - ID " . $register['id'] . ": " . $register['title'] . "\n";
        }
    }
    
} catch (\Exception $e) {
    echo "   ❌ Error testing OpenRegister: " . $e->getMessage() . "\n";
}

echo "\n=== Summary ===\n";
if ($fixMode) {
    if ($needsUpdate) {
        echo "🔧 Configuration has been updated. Please test the organization creation again.\n";
    } else {
        echo "✅ Configuration was already correct.\n";
    }
} else {
    if ($needsUpdate) {
        echo "❌ Configuration needs updating. Run with '--fix' flag to update.\n";
    } else {
        echo "✅ Configuration looks good.\n";
    }
}

echo "\nNext steps:\n";
echo "1. Run: php check_configuration.php --fix\n";
echo "2. Test organization creation with contactpersonen\n";
echo "3. Check logs for any remaining errors\n"; 