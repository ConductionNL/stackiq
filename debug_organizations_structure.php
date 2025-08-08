<?php
/**
 * Debug script to examine the organizations section structure in GEMMA_release.xml
 */

require_once __DIR__ . '/vendor/autoload.php';

use OCA\SoftwareCatalog\Service\ArchiMateService;
use OCP\ILogger;
use Psr\Log\LoggerInterface;

// Mock logger for debugging
class DebugLogger implements LoggerInterface {
    public function emergency($message, array $context = []): void { echo "EMERGENCY: $message\n"; }
    public function alert($message, array $context = []): void { echo "ALERT: $message\n"; }
    public function critical($message, array $context = []): void { echo "CRITICAL: $message\n"; }
    public function error($message, array $context = []): void { echo "ERROR: $message\n"; }
    public function warning($message, array $context = []): void { echo "WARNING: $message\n"; }
    public function notice($message, array $context = []): void { echo "NOTICE: $message\n"; }
    public function info($message, array $context = []): void { echo "INFO: $message\n"; }
    public function debug($message, array $context = []): void { echo "DEBUG: $message\n"; }
    public function log($level, $message, array $context = []): void { echo "$level: $message\n"; }
}

echo "=== DEBUGGING ORGANIZATIONS STRUCTURE ===\n\n";

// Create service instance
$logger = new DebugLogger();
$service = new ArchiMateService($logger, null, null, null);

// Parse just the organizations section
$filePath = '/var/www/html/apps-extra/softwarecatalog/lib/Settings/GEMMA_release.xml';
echo "1. Parsing XML file: $filePath\n\n";

// Use reflection to access the private parseArchiMateXmlStreaming method
$reflection = new ReflectionClass($service);
$parseMethod = $reflection->getMethod('parseArchiMateXmlStreaming');
$parseMethod->setAccessible(true);

$parsedData = $parseMethod->invoke($service, $filePath);

echo "2. Checking if organizations section exists...\n";
if (isset($parsedData['organizations'])) {
    echo "✅ Organizations section found!\n\n";
    
    echo "3. Organizations section structure:\n";
    echo "Keys in organizations: " . implode(', ', array_keys($parsedData['organizations'])) . "\n\n";
    
    if (isset($parsedData['organizations']['item'])) {
        $orgItems = $parsedData['organizations']['item'];
        echo "4. Organization items found: " . count($orgItems) . "\n\n";
        
        echo "5. First few organization items structure:\n";
        for ($i = 0; $i < min(3, count($orgItems)); $i++) {
            echo "--- Item $i ---\n";
            echo "Structure: " . json_encode($orgItems[$i], JSON_PRETTY_PRINT) . "\n\n";
        }
        
        // Check for different patterns
        echo "6. Analysis of organization items:\n";
        $hasIdentifier = 0;
        $hasIdentifierRef = 0;
        $hasLabel = 0;
        $hasNestedItems = 0;
        
        foreach ($orgItems as $item) {
            if (isset($item['_attributes']['identifier'])) $hasIdentifier++;
            if (isset($item['_attributes']['identifierRef'])) $hasIdentifierRef++;
            if (isset($item['label'])) $hasLabel++;
            if (isset($item['item'])) $hasNestedItems++;
        }
        
        echo "Items with identifier attribute: $hasIdentifier\n";
        echo "Items with identifierRef attribute: $hasIdentifierRef\n";
        echo "Items with label element: $hasLabel\n";
        echo "Items with nested item elements: $hasNestedItems\n\n";
        
    } else {
        echo "❌ No 'item' key found in organizations section\n";
        echo "Available keys: " . implode(', ', array_keys($parsedData['organizations'])) . "\n";
    }
    
} else {
    echo "❌ No organizations section found in parsed data\n";
    echo "Available top-level keys: " . implode(', ', array_keys($parsedData)) . "\n";
}

echo "\n=== DEBUG COMPLETE ===\n";
