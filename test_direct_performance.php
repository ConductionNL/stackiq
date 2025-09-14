<?php
/**
 * Direct Performance Test for ArchiMate Service
 * 
 * This script tests the ArchiMate service directly without HTTP overhead
 * to get accurate performance measurements.
 */

declare(strict_types=1);

// Add this directory to the include path
ini_set('include_path', ini_get('include_path') . PATH_SEPARATOR . __DIR__);

// We're running in the container environment
if (!defined('OC_CONSOLE')) {
    define('OC_CONSOLE', 1);
}

// Bootstrap Nextcloud  
require_once '/var/www/html/lib/base.php';

use OCA\SoftwareCatalog\Service\ArchiMateService;
use OCA\SoftwareCatalog\Service\ArchiMateImportService;  
use OCA\SoftwareCatalog\Service\ArchiMateExportService;

// Configuration
$GEMMA_FILE = '/var/www/html/apps-extra/softwarecatalog/lib/Settings/GEMMA_release.xml';
$TARGET_TIME_SECONDS = 60;

// Colors for output
$RED = "\033[0;31m";
$GREEN = "\033[0;32m";
$YELLOW = "\033[1;33m";
$BLUE = "\033[0;34m";
$CYAN = "\033[0;36m";
$NC = "\033[0m";

function logColor($message, $color = '') {
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

logColor("🚀 DIRECT ARCHIMATE PERFORMANCE TEST", $CYAN);
logColor("=" . str_repeat("=", 45), $CYAN);
echo PHP_EOL;

// Check file exists
if (!file_exists($GEMMA_FILE)) {
    logColor("❌ ERROR: GEMMA file not found: $GEMMA_FILE", $RED);
    exit(1);
}

$fileSize = filesize($GEMMA_FILE);
$fileSizeMB = round($fileSize / 1024 / 1024, 2);
logColor("📁 Test file: " . basename($GEMMA_FILE) . " ({$fileSizeMB} MB)", $BLUE);

// Initialize services
try {
    $container = \OC::$server->query(\Psr\Container\ContainerInterface::class);
    $config = \OC::$server->getConfig();
    $appConfig = \OC::$server->getAppConfig(); 
    $rootFolder = \OC::$server->getRootFolder();
    $userSession = \OC::$server->getUserSession();
    $appManager = \OC::$server->getAppManager();
    $logger = \OC::$server->getLogger();
    
    // Create PSR-compatible logger wrapper
    $psrLogger = new class($logger) implements \Psr\Log\LoggerInterface {
        private $logger;
        
        public function __construct($logger) {
            $this->logger = $logger;
        }
        
        public function emergency($message, array $context = []) {
            $this->logger->emergency($message, $context);
        }
        
        public function alert($message, array $context = []) {
            $this->logger->alert($message, $context);
        }
        
        public function critical($message, array $context = []) {
            $this->logger->critical($message, $context);
        }
        
        public function error($message, array $context = []) {
            $this->logger->error($message, $context);
        }
        
        public function warning($message, array $context = []) {
            $this->logger->warning($message, $context);
        }
        
        public function notice($message, array $context = []) {
            $this->logger->notice($message, $context);
        }
        
        public function info($message, array $context = []) {
            $this->logger->info($message, $context);
        }
        
        public function debug($message, array $context = []) {
            $this->logger->debug($message, $context);
        }
        
        public function log($level, $message, array $context = []) {
            $this->logger->log($level, $message, $context);
        }
    };

    $importService = new ArchiMateImportService($psrLogger);
    $exportService = new ArchiMateExportService($psrLogger);
    
    $archiMateService = new ArchiMateService(
        $appConfig,
        $rootFolder, 
        $userSession,
        $appManager,
        $container,
        $psrLogger,
        $importService,
        $exportService
    );
    
    logColor("✅ Services initialized successfully", $GREEN);
    
} catch (\Exception $e) {
    logColor("❌ ERROR: Failed to initialize services: " . $e->getMessage(), $RED);
    logColor("Stack trace: " . $e->getTraceAsString(), $RED);
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

echo PHP_EOL;

// =============================================================================
// TEST 1: OPTIMIZED METHOD  
// =============================================================================
logColor("🏎️  TEST 1: OPTIMIZED METHOD", $YELLOW);
logColor("-" . str_repeat("-", 35), $YELLOW);

$startTime = microtime(true);
$startMemory = memory_get_usage(true);

try {
    logColor("Starting optimized import...", $BLUE);
    $result = $archiMateService->importArchiMateFileFromPathOptimized($options);
    
    $endTime = microtime(true);
    $endMemory = memory_get_usage(true);
    $duration = $endTime - $startTime;
    $memoryUsed = $endMemory - $startMemory;
    
    if ($result['success']) {
        $objectsProcessed = $result['performance_metrics']['objects_processed'] ?? 0;
        $objectsPerSecond = $objectsProcessed / max($duration, 0.001);
        
        logColor("✅ OPTIMIZED METHOD SUCCESS!", $GREEN);
        logColor("   ⏱️  Duration: " . formatTime($duration), $GREEN);
        logColor("   🎯 Objects: $objectsProcessed", $GREEN);
        logColor("   ⚡ Speed: " . round($objectsPerSecond, 1) . " objects/sec", $GREEN);
        logColor("   💾 Memory: " . round($memoryUsed / 1024 / 1024, 2) . " MB", $GREEN);
        
        if ($duration <= $TARGET_TIME_SECONDS) {
            logColor("   🎉 TARGET ACHIEVED! (< {$TARGET_TIME_SECONDS}s)", $GREEN);
            $targetMet = true;
        } else {
            $shortfall = $duration - $TARGET_TIME_SECONDS;
            logColor("   ⚠️  Target missed by " . formatTime($shortfall), $YELLOW);
            $targetMet = false;
        }
        
        // Show breakdown if available
        if (isset($result['performance_metrics']['breakdown'])) {
            $breakdown = $result['performance_metrics']['breakdown'];
            logColor("   📊 Breakdown:", $BLUE);
            logColor("      Parse: " . ($breakdown['parse'] ?? '?') . "s", $BLUE);
            logColor("      Transform: " . ($breakdown['transform'] ?? '?') . "s", $BLUE);
            logColor("      Save: " . ($breakdown['save'] ?? '?') . "s", $BLUE);
        }
        
    } else {
        logColor("❌ OPTIMIZED METHOD FAILED: " . ($result['error'] ?? 'Unknown error'), $RED);
        $targetMet = false;
    }
    
} catch (\Exception $e) {
    logColor("❌ OPTIMIZED METHOD EXCEPTION: " . $e->getMessage(), $RED);
    logColor("Stack trace: " . $e->getTraceAsString(), $RED);
    $targetMet = false;
}

echo PHP_EOL;

// =============================================================================
// MEMORY REPORT
// =============================================================================
logColor("💾 MEMORY REPORT", $BLUE);
logColor("=" . str_repeat("=", 20), $BLUE);
$currentMemory = memory_get_usage(true);
$peakMemory = memory_get_peak_usage(true);
logColor("Current: " . round($currentMemory / 1024 / 1024, 2) . " MB", $BLUE);
logColor("Peak: " . round($peakMemory / 1024 / 1024, 2) . " MB", $BLUE);

echo PHP_EOL;

// =============================================================================
// FINAL ASSESSMENT
// =============================================================================
logColor("🎯 FINAL ASSESSMENT", $CYAN);
logColor("=" . str_repeat("=", 25), $CYAN);

if (isset($targetMet) && $targetMet) {
    logColor("🎉 SUCCESS: Performance target achieved!", $GREEN);
    logColor("✅ Import completed in under 60 seconds", $GREEN);
    logColor("✅ Ready for production deployment", $GREEN);
    
    echo PHP_EOL;
    logColor("📋 NEXT STEPS:", $GREEN);
    logColor("• Update API to default to optimized method", $GREEN);
    logColor("• Update frontend to use optimized endpoint", $GREEN);
    logColor("• Add performance monitoring", $GREEN);
    
    $exitCode = 0;
    
} else {
    logColor("⚠️  Performance target not yet achieved", $YELLOW);
    logColor("🔧 Additional optimization needed", $YELLOW);
    
    echo PHP_EOL;
    logColor("📋 OPTIMIZATION OPTIONS:", $YELLOW);
    logColor("• Implement streaming XML parser", $YELLOW);
    logColor("• Add ReactPHP parallel processing", $YELLOW);
    logColor("• Optimize database bulk operations", $YELLOW);
    logColor("• Consider memory-mapped file parsing", $YELLOW);
    
    $exitCode = 1;
}

echo PHP_EOL;
logColor("🏁 PERFORMANCE TEST COMPLETED", $CYAN);

exit($exitCode);
