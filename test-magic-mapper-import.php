#!/usr/bin/env php
<?php

/**
 * Test script for importing the magic mapper version of softwarecatalogus_register.json
 * 
 * This script tests the import of registers with magic mapper configuration.
 * It should be run from within the Nextcloud container as user www-data.
 * 
 * Usage:
 *   docker exec -u 33 nextcloud php /var/www/html/custom_apps/softwarecatalog/test-magic-mapper-import.php
 */

require_once __DIR__ . '/../../lib/base.php';

use OCA\OpenRegister\Service\ConfigurationService;
use OCP\Server;

try {
    echo "╔══════════════════════════════════════════════════════════════════════════════╗\n";
    echo "║ Magic Mapper Register Import Test                                            ║\n";
    echo "╚══════════════════════════════════════════════════════════════════════════════╝\n\n";

    // Load the magic mapper JSON file.
    $jsonPath = __DIR__ . '/lib/Settings/softwarecatalogus_register_magic.json';
    echo "📂 Loading JSON from: $jsonPath\n";
    
    if (!file_exists($jsonPath)) {
        throw new Exception("JSON file not found: $jsonPath");
    }

    $jsonContent = file_get_contents($jsonPath);
    $data = json_decode($jsonContent, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Failed to parse JSON: " . json_last_error_msg());
    }

    echo "✓ JSON loaded successfully\n\n";

    // Get the configuration service.
    $configService = Server::get(ConfigurationService::class);
    
    // Import the configuration.
    echo "⚙️  Starting import...\n";
    $result = $configService->importFromApp(
        appId: 'softwarecatalog',
        data: $data,
        version: $data['info']['version'] ?? '2.0.1-magic',
        force: true // Force import for testing
    );

    echo "✓ Import completed!\n\n";

    // Display results.
    echo "═══════════════════════════════════════════════════════════════════════════════\n";
    echo "Import Results:\n";
    echo "═══════════════════════════════════════════════════════════════════════════════\n\n";

    if (!empty($result['registers'])) {
        echo "📋 Registers imported: " . count($result['registers']) . "\n";
        foreach ($result['registers'] as $register) {
            echo "  • " . $register->getTitle() . " (slug: " . $register->getSlug() . ")\n";
            
            // Check if configuration was set.
            $config = $register->getConfiguration();
            if ($config && isset($config['schemas'])) {
                echo "    🔮 Magic Mapper enabled for " . count($config['schemas']) . " schemas:\n";
                foreach ($config['schemas'] as $schemaSlug => $schemaConfig) {
                    if ($schemaConfig['magicMapping'] ?? false) {
                        echo "      ✓ " . $schemaSlug . "\n";
                    }
                }
            } else {
                echo "    ⚠️  No magic mapper configuration found\n";
            }
            echo "\n";
        }
    }

    if (!empty($result['schemas'])) {
        echo "\n📝 Schemas imported: " . count($result['schemas']) . "\n";
    }

    if (!empty($result['objects'])) {
        echo "\n📦 Objects imported: " . count($result['objects']) . "\n";
    }

    echo "\n";
    echo "╔══════════════════════════════════════════════════════════════════════════════╗\n";
    echo "║ ✅ Test completed successfully!                                              ║\n";
    echo "╚══════════════════════════════════════════════════════════════════════════════╝\n";

    exit(0);

} catch (Exception $e) {
    echo "\n";
    echo "╔══════════════════════════════════════════════════════════════════════════════╗\n";
    echo "║ ❌ Test failed!                                                               ║\n";
    echo "╚══════════════════════════════════════════════════════════════════════════════╝\n\n";
    echo "Error: " . $e->getMessage() . "\n";
    echo "Trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}

