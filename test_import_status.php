<?php
require_once '/var/www/html/lib/base.php';

// Bootstrap Nextcloud environment for console access
\OC::$server->getUserManager();
\OC_App::loadApps();

try {
    echo "Testing GEMMA import after database clear...\n";
    
    // Get the ArchiMate service
    $container = \OC::$server->query(\OCA\SoftwareCatalog\Service\ArchiMateService::class);
    
    // Import from the GEMMA file
    $filePath = '/var/www/html/apps-extra/softwarecatalog/lib/Settings/GEMMA_release.xml';
    
    if (!file_exists($filePath)) {
        echo "❌ GEMMA file not found at: $filePath\n";
        exit(1);
    }
    
    echo "📁 Found GEMMA file: " . number_format(filesize($filePath) / (1024 * 1024), 2) . " MB\n";
    
    $options = [
        'filePath' => $filePath,
        'organization' => 'Generic'
    ];
    
    echo "🚀 Starting import...\n";
    $startTime = time();
    
    $result = $container->importArchiMateFileFromPathOptimized($options);
    
    $duration = time() - $startTime;
    echo "⏱️  Import completed in {$duration} seconds\n\n";
    
    if ($result['success']) {
        echo "✅ Import successful!\n\n";
        
        // Display statistics
        if (isset($result['statistics'])) {
            $stats = $result['statistics'];
            echo "📊 IMPORT STATISTICS:\n";
            echo "==================\n";
            
            foreach ($stats as $section => $sectionStats) {
                if ($section === 'summary') continue;
                
                echo "\n📋 {$section}:\n";
                echo "  • Created: " . ($sectionStats['created'] ?? 0) . "\n";
                echo "  • Updated: " . ($sectionStats['updated'] ?? 0) . "\n";
                echo "  • Skipped: " . ($sectionStats['skipped'] ?? 0) . "\n";
                echo "  • Errors: " . count($sectionStats['errors'] ?? []) . "\n";
                
                if (!empty($sectionStats['errors'])) {
                    echo "  📝 Sample errors:\n";
                    foreach (array_slice($sectionStats['errors'], 0, 3) as $error) {
                        echo "    - " . substr($error, 0, 80) . "...\n";
                    }
                }
            }
            
            echo "\n🎯 SUMMARY:\n";
            $summary = $stats['summary'] ?? [];
            echo "  • Total Created: " . ($summary['total_objects_created'] ?? 0) . "\n";
            echo "  • Total Updated: " . ($summary['total_objects_updated'] ?? 0) . "\n";
            echo "  • Total Unchanged: " . ($summary['total_objects_unchanged'] ?? 0) . "\n";
            echo "  • Total Errors: " . ($summary['total_errors'] ?? 0) . "\n";
        }
        
        // Display performance metrics
        if (isset($result['performance_metrics'])) {
            $perf = $result['performance_metrics'];
            echo "\n⚡ PERFORMANCE METRICS:\n";
            echo "====================\n";
            echo "  • Total Time: " . number_format($perf['total_time_seconds'] ?? 0, 2) . "s\n";
            echo "  • Objects/Second: " . number_format($perf['items_per_second'] ?? 0, 1) . "\n";
            echo "  • Objects Processed: " . number_format($perf['objects_processed'] ?? 0) . "\n";
        }
        
    } else {
        echo "❌ Import failed: " . ($result['error'] ?? 'Unknown error') . "\n";
        exit(1);
    }
    
} catch (\Exception $e) {
    echo "💥 Exception during import: " . $e->getMessage() . "\n";
    echo "📍 Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
?>
