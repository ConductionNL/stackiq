<?php
/**
 * ArchiMate Performance Optimization Test Script
 * 
 * This script tests both the original and optimized import methods
 * to measure performance improvements and ensure we achieve <1 minute target.
 */

declare(strict_types=1);

// Bootstrap Nextcloud
require_once __DIR__ . '/../../lib/base.php';

use OCA\SoftwareCatalog\Service\ArchiMateService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\SoftwareCatalog\Service\ArchiMateImportService;
use OCA\SoftwareCatalog\Service\ArchiMateExportService;

// Configuration
$GEMMA_FILE = '/var/www/html/apps-extra/softwarecatalog/lib/Settings/GEMMA_release.xml';
$TARGET_TIME_SECONDS = 60; // 1 minute target

// Colors for output
$RED = "\033[0;31m";
$GREEN = "\033[0;32m";
$YELLOW = "\033[1;33m";
$BLUE = "\033[0;34m";
$CYAN = "\033[0;36m";
$NC = "\033[0m"; // No Color

function logWithColor($message, $color = '') {
    echo $color . $message . "\033[0m" . PHP_EOL;
}

function formatTime($seconds) {
    if ($seconds < 60) {
        return round($seconds, 2) . 's';
    } else {
        $minutes = floor($seconds / 60);
        $remainingSeconds = round($seconds % 60, 2);
        return $minutes . 'm ' . $remainingSeconds . 's';
    }
}

function formatSpeed($objectsPerSecond) {
    return round($objectsPerSecond, 1) . ' objects/sec';
}

logWithColor("🚀 ARCHIMATE PERFORMANCE OPTIMIZATION TEST", $CYAN);
logWithColor("=" . str_repeat("=", 50), $CYAN);
echo PHP_EOL;

// Check if GEMMA file exists
if (!file_exists($GEMMA_FILE)) {
    logWithColor("❌ ERROR: GEMMA file not found: $GEMMA_FILE", $RED);
    exit(1);
}

$fileSize = filesize($GEMMA_FILE);
$fileSizeMB = round($fileSize / 1024 / 1024, 2);
logWithColor("📁 Test file: " . basename($GEMMA_FILE) . " ({$fileSizeMB} MB)", $BLUE);

// Get services
try {
    $container = \OC::$server->query(\Psr\Container\ContainerInterface::class);
    $config = \OC::$server->getAppConfig();
    $rootFolder = \OC::$server->getRootFolder();
    $userSession = \OC::$server->getUserSession();
    $appManager = \OC::$server->getAppManager();
    $logger = \OC::$server->getLogger();
    
    $importService = new ArchiMateImportService($logger);
    $exportService = new ArchiMateExportService($logger);
    
    $archiMateService = new ArchiMateService(
        $config,
        $rootFolder,
        $userSession,
        $appManager,
        $container,
        $logger,
        $importService,
        $exportService
    );
    
    logWithColor("✅ Services initialized successfully", $GREEN);
    echo PHP_EOL;
    
} catch (\Exception $e) {
    logWithColor("❌ ERROR: Failed to initialize services: " . $e->getMessage(), $RED);
    exit(1);
}

// Test options
$options = [
    'filePath' => $GEMMA_FILE,
    'fileName' => basename($GEMMA_FILE),
    'fileSize' => $fileSize,
    'mimeType' => 'text/xml',
    'updateExisting' => true,
    'preserveIds' => true
];

// Memory monitoring
$initialMemory = memory_get_usage(true);
logWithColor("💾 Initial memory usage: " . round($initialMemory / 1024 / 1024, 2) . " MB", $BLUE);
echo PHP_EOL;

// =============================================================================
// TEST 1: OPTIMIZED METHOD
// =============================================================================
logWithColor("🏎️  TEST 1: OPTIMIZED METHOD", $YELLOW);
logWithColor("-" . str_repeat("-", 40), $YELLOW);

$test1StartTime = microtime(true);
$test1StartMemory = memory_get_usage(true);

try {
    logWithColor("Starting optimized import...", $BLUE);
    $optimizedResult = $archiMateService->importArchiMateFileFromPathOptimized($options);
    
    $test1EndTime = microtime(true);
    $test1EndMemory = memory_get_usage(true);
    
    $optimizedTime = $test1EndTime - $test1StartTime;
    $optimizedMemoryUsed = $test1EndMemory - $test1StartMemory;
    
    if ($optimizedResult['success']) {
        $objectsProcessed = $optimizedResult['performance_metrics']['objects_processed'] ?? 0;
        $objectsPerSecond = $objectsProcessed / max($optimizedTime, 0.001);
        
        logWithColor("✅ OPTIMIZED METHOD RESULTS:", $GREEN);
        logWithColor("   ⏱️  Time: " . formatTime($optimizedTime), $GREEN);
        logWithColor("   🎯 Objects: $objectsProcessed", $GREEN);
        logWithColor("   ⚡ Speed: " . formatSpeed($objectsPerSecond), $GREEN);
        logWithColor("   💾 Memory: " . round($optimizedMemoryUsed / 1024 / 1024, 2) . " MB", $GREEN);
        
        if ($optimizedTime <= $TARGET_TIME_SECONDS) {
            logWithColor("   🎉 TARGET ACHIEVED! (< {$TARGET_TIME_SECONDS}s)", $GREEN);
        } else {
            logWithColor("   ⚠️  Target missed by " . formatTime($optimizedTime - $TARGET_TIME_SECONDS), $YELLOW);
        }
    } else {
        logWithColor("❌ OPTIMIZED METHOD FAILED: " . ($optimizedResult['error'] ?? 'Unknown error'), $RED);
        $optimizedTime = null;
    }
    
} catch (\Exception $e) {
    logWithColor("❌ OPTIMIZED METHOD EXCEPTION: " . $e->getMessage(), $RED);
    $optimizedTime = null;
}

echo PHP_EOL;

// Clear memory before next test
gc_collect_cycles();

// =============================================================================
// TEST 2: ORIGINAL METHOD (for comparison)
// =============================================================================
logWithColor("🐌 TEST 2: ORIGINAL METHOD", $YELLOW);
logWithColor("-" . str_repeat("-", 40), $YELLOW);

$test2StartTime = microtime(true);
$test2StartMemory = memory_get_usage(true);

try {
    logWithColor("Starting original import...", $BLUE);
    $originalResult = $archiMateService->importArchiMateFileFromPath($options);
    
    $test2EndTime = microtime(true);
    $test2EndMemory = memory_get_usage(true);
    
    $originalTime = $test2EndTime - $test2StartTime;
    $originalMemoryUsed = $test2EndMemory - $test2StartMemory;
    
    if ($originalResult['success']) {
        $objectsProcessed = $originalResult['summary']['total_objects_created'] ?? 0;
        $objectsPerSecond = $objectsProcessed / max($originalTime, 0.001);
        
        logWithColor("✅ ORIGINAL METHOD RESULTS:", $GREEN);
        logWithColor("   ⏱️  Time: " . formatTime($originalTime), $GREEN);
        logWithColor("   🎯 Objects: $objectsProcessed", $GREEN);
        logWithColor("   ⚡ Speed: " . formatSpeed($objectsPerSecond), $GREEN);
        logWithColor("   💾 Memory: " . round($originalMemoryUsed / 1024 / 1024, 2) . " MB", $GREEN);
    } else {
        logWithColor("❌ ORIGINAL METHOD FAILED: " . ($originalResult['error'] ?? 'Unknown error'), $RED);
        $originalTime = null;
    }
    
} catch (\Exception $e) {
    logWithColor("❌ ORIGINAL METHOD EXCEPTION: " . $e->getMessage(), $RED);
    $originalTime = null;
}

echo PHP_EOL;

// =============================================================================
// PERFORMANCE COMPARISON
// =============================================================================
logWithColor("📊 PERFORMANCE COMPARISON", $CYAN);
logWithColor("=" . str_repeat("=", 30), $CYAN);

if ($optimizedTime !== null && $originalTime !== null) {
    $improvement = $originalTime - $optimizedTime;
    $improvementPercent = (($originalTime - $optimizedTime) / $originalTime) * 100;
    
    logWithColor("📈 Performance Improvement:", $BLUE);
    logWithColor("   Original: " . formatTime($originalTime), $BLUE);
    logWithColor("   Optimized: " . formatTime($optimizedTime), $BLUE);
    logWithColor("   Improvement: " . formatTime($improvement) . " (" . round($improvementPercent, 1) . "%)", $GREEN);
    
    $speedupFactor = $originalTime / $optimizedTime;
    logWithColor("   Speedup: " . round($speedupFactor, 1) . "x faster", $GREEN);
    
} elseif ($optimizedTime !== null) {
    logWithColor("Only optimized method completed successfully", $YELLOW);
    logWithColor("Optimized time: " . formatTime($optimizedTime), $GREEN);
} elseif ($originalTime !== null) {
    logWithColor("Only original method completed successfully", $YELLOW);
    logWithColor("Original time: " . formatTime($originalTime), $GREEN);
} else {
    logWithColor("Both methods failed", $RED);
}

echo PHP_EOL;

// =============================================================================
// TARGET ASSESSMENT
// =============================================================================
logWithColor("🎯 TARGET ASSESSMENT", $CYAN);
logWithColor("=" . str_repeat("=", 25), $CYAN);

$targetMet = false;
if ($optimizedTime !== null) {
    if ($optimizedTime <= $TARGET_TIME_SECONDS) {
        logWithColor("🎉 SUCCESS: Target achieved!", $GREEN);
        logWithColor("   Target: " . $TARGET_TIME_SECONDS . "s", $GREEN);
        logWithColor("   Actual: " . formatTime($optimizedTime), $GREEN);
        logWithColor("   Margin: " . formatTime($TARGET_TIME_SECONDS - $optimizedTime) . " under target", $GREEN);
        $targetMet = true;
    } else {
        $shortfall = $optimizedTime - $TARGET_TIME_SECONDS;
        logWithColor("⚠️  Target missed by " . formatTime($shortfall), $YELLOW);
        logWithColor("   Additional optimization needed", $YELLOW);
    }
} else {
    logWithColor("❌ Cannot assess target - optimized method failed", $RED);
}

echo PHP_EOL;

// =============================================================================
// RECOMMENDATIONS
// =============================================================================
logWithColor("💡 RECOMMENDATIONS", $CYAN);
logWithColor("=" . str_repeat("=", 20), $CYAN);

if ($targetMet) {
    logWithColor("✅ Performance target achieved! Consider:", $GREEN);
    logWithColor("   • Deploy optimized method as default", $GREEN);
    logWithColor("   • Update API to use optimized method", $GREEN);
    logWithColor("   • Add performance monitoring", $GREEN);
} else {
    logWithColor("🔧 Additional optimizations needed:", $YELLOW);
    logWithColor("   • Implement streaming XML parser", $YELLOW);
    logWithColor("   • Add parallel processing with ReactPHP", $YELLOW);
    logWithColor("   • Optimize database bulk operations", $YELLOW);
    logWithColor("   • Consider memory-mapped file parsing", $YELLOW);
}

echo PHP_EOL;

// Final memory report
$finalMemory = memory_get_usage(true);
$peakMemory = memory_get_peak_usage(true);

logWithColor("💾 MEMORY REPORT", $BLUE);
logWithColor("=" . str_repeat("=", 20), $BLUE);
logWithColor("Initial: " . round($initialMemory / 1024 / 1024, 2) . " MB", $BLUE);
logWithColor("Final: " . round($finalMemory / 1024 / 1024, 2) . " MB", $BLUE);
logWithColor("Peak: " . round($peakMemory / 1024 / 1024, 2) . " MB", $BLUE);

echo PHP_EOL;
logWithColor("🏁 PERFORMANCE TEST COMPLETED", $CYAN);

// Exit with appropriate code
exit($targetMet ? 0 : 1);
