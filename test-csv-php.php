#!/usr/bin/env php
<?php
/**
 * CSV Import Performance Test via PHP
 * 
 * Imports CSV files directly using OpenRegister services
 */

require_once __DIR__ . '/../../lib/base.php';

use OCA\OpenRegister\Service\ImportService;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;

echo "\n";
echo "╔══════════════════════════════════════════════════════════════════════════════╗\n";
echo "║              CSV IMPORT PERFORMANCE TEST - MAGIC MAPPER                      ║\n";
echo "╚══════════════════════════════════════════════════════════════════════════════╝\n";
echo "\n";

$importService = \OC::$server->get(ImportService::class);
$registerMapper = \OC::$server->get(RegisterMapper::class);
$schemaMapper = \OC::$server->get(SchemaMapper::class);

// CSV files to import
$csvFiles = [
    'organisatie' => '/var/www/html/custom_apps/stackiq/data/organisatie.csv',
    'module' => '/var/www/html/custom_apps/stackiq/data/module.csv',
];

// Find voorzieningen register
echo "📋 STAP 1: Register lookup\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

$registers = $registerMapper->findAll();
$register = null;

foreach ($registers as $r) {
    if ($r->getSlug() === 'voorzieningen') {
        $register = $r;
        break;
    }
}

if ($register === null) {
    echo "❌ 'voorzieningen' register niet gevonden!\n\n";
    exit(1);
}

echo "✓ Register gevonden: ID {$register->getId()}, Slug: {$register->getSlug()}\n";

// Check magic mapping configuration
$config = $register->getConfiguration() ?? [];
$schemasConfig = $config['schemas'] ?? [];
echo "✓ Schemas met magic mapping: " . implode(', ', array_keys($schemasConfig)) . "\n\n";

echo "📊 STAP 2: CSV Import Performance\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

$totalImported = 0;
$totalFailed = 0;
$totalDuration = 0;

foreach ($csvFiles as $schemaSlug => $csvPath) {
    echo "━━━ Importing $schemaSlug ━━━\n";
    
    // Find schema
    $schema = null;
    $schemas = $schemaMapper->findAll();
    
    foreach ($schemas as $s) {
        if ($s->getSlug() === $schemaSlug && $s->getRegister() == $register->getId()) {
            $schema = $s;
            break;
        }
    }
    
    if ($schema === null) {
        echo "❌ Schema '$schemaSlug' niet gevonden!\n\n";
        continue;
    }
    
    echo "   Schema ID: {$schema->getId()}\n";
    
    // Count lines
    $lineCount = count(file($csvPath));
    $objectCount = $lineCount - 1; // Minus header
    
    echo "   Objecten: $objectCount\n";
    
    // Check if magic mapping is enabled
    $schemaConfig = $schemasConfig[$schemaSlug] ?? $schemasConfig[(string)$schema->getId()] ?? [];
    $magicEnabled = ($schemaConfig['magicMapping'] ?? false) === true;
    
    echo "   Magic Mapping: " . ($magicEnabled ? "✓ Enabled" : "✗ Disabled") . "\n";
    
    // Import
    echo "   🚀 Starting import...\n";
    
    $startTime = microtime(true);
    
    try {
        $result = $importService->importFromFile(
            file: $csvPath,
            register: $register,
            schema: $schema,
            _rbac: false,
            _multitenancy: false,
            validation: false,
            events: false
        );
        
        $duration = microtime(true) - $startTime;
        
        $imported = $result['imported'] ?? 0;
        $failed = $result['failed'] ?? 0;
        
        $totalImported += $imported;
        $totalFailed += $failed;
        $totalDuration += $duration;
        
        $speed = $duration > 0 ? round($imported / $duration, 2) : 'N/A';
        
        echo "   ✓ Imported: $imported\n";
        if ($failed > 0) {
            echo "   ⚠️  Failed: $failed\n";
        }
        echo "   ⏱️  Duration: " . round($duration, 2) . "s\n";
        echo "   📊 Speed: $speed objects/sec\n";
        
        // Check magic table
        $db = \OC::$server->get(\OCP\IDBConnection::class);
        $tableName = "oc_openregister_table_{$register->getId()}_{$schema->getId()}";
        $schemaWrapper = $db->get(\OCP\DB\ISchemaWrapper::class);
        
        if ($schemaWrapper->tableExists($tableName)) {
            $qb = $db->getQueryBuilder();
            $qb->select($qb->func()->count('*', 'total'))->from($tableName);
            $count = $qb->executeQuery()->fetchOne();
            echo "   ✓ Magic table: $tableName ($count rows)\n";
        } else {
            echo "   ⚠️  Using blob storage\n";
        }
        
    } catch (\Exception $e) {
        echo "   ❌ Error: {$e->getMessage()}\n";
    }
    
    echo "\n";
}

echo "📊 PERFORMANCE SUMMARY\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

$overallSpeed = $totalDuration > 0 ? round($totalImported / $totalDuration, 2) : 'N/A';

echo "   📦 Total Imported: $totalImported objects\n";
echo "   ❌ Total Failed: $totalFailed objects\n";
echo "   ⏱️  Total Duration: " . round($totalDuration, 2) . "s\n";
echo "   🚀 Overall Speed: $overallSpeed objects/sec\n\n";

echo "✅ Import Test Complete!\n\n";

