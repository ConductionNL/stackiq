<?php
/**
 * Test Export Method Script
 * 
 * Test the correct ArchiMate export method
 */

require_once '/var/www/html/lib/base.php';
\OC::$CLI = true;

echo "🔧 TESTING ARCHIMATE EXPORT METHOD\n";
echo "===================================\n\n";

try {
    $container = \OC::$server->get(\Psr\Container\ContainerInterface::class);
    $archiMateService = $container->get('OCA\\SoftwareCatalog\\Service\\ArchiMateService');
    
    echo "Testing exportToArchiMate method...\n";
    echo "Method signature: exportToArchiMate(?string \$organization = null)\n\n";
    
    $startTime = microtime(true);
    
    // Test the correct method - it only takes organization parameter
    $organization = null; // No organization filter
    $result = $archiMateService->exportToArchiMate($organization);
    
    $duration = microtime(true) - $startTime;
    echo "Export completed in " . round($duration, 3) . " seconds\n\n";
    
    echo "Export result:\n";
    if (is_array($result)) {
        echo "Result type: Array\n";
        echo "Keys: " . implode(', ', array_keys($result)) . "\n";
        
        if (isset($result['success'])) {
            echo "Success: " . ($result['success'] ? 'YES' : 'NO') . "\n";
        }
        
        if (isset($result['message'])) {
            echo "Message: {$result['message']}\n";
        }
        
        if (isset($result['error'])) {
            echo "Error: {$result['error']}\n";
        }
        
        if (isset($result['xml'])) {
            $xmlLength = strlen($result['xml']);
            echo "XML length: $xmlLength bytes\n";
            
            if ($xmlLength > 0) {
                // Validate XML
                libxml_use_internal_errors(true);
                $dom = new DOMDocument();
                if ($dom->loadXML($result['xml'])) {
                    echo "✅ Generated XML is valid!\n";
                    
                    // Save for inspection
                    file_put_contents('/tmp/test_export.xml', $result['xml']);
                    echo "XML saved to /tmp/test_export.xml\n";
                    
                    // Show first few lines
                    $lines = explode("\n", $result['xml']);
                    echo "First 10 lines:\n";
                    foreach (array_slice($lines, 0, 10) as $i => $line) {
                        echo sprintf("%2d: %s\n", $i+1, htmlspecialchars(substr($line, 0, 100)));
                    }
                    
                } else {
                    echo "❌ Generated XML is invalid:\n";
                    $errors = libxml_get_errors();
                    foreach ($errors as $error) {
                        echo "  - Line {$error->line}: {$error->message}";
                    }
                }
            }
        }
        
        if (isset($result['file_name'])) {
            echo "File name: {$result['file_name']}\n";
        }
        
        if (isset($result['statistics'])) {
            echo "Statistics:\n";
            print_r($result['statistics']);
        }
        
    } else {
        echo "Result type: " . gettype($result) . "\n";
        echo "Result content:\n";
        var_dump($result);
    }
    
} catch (Exception $e) {
    echo "❌ Export test failed: {$e->getMessage()}\n";
    echo "Stack trace:\n{$e->getTraceAsString()}\n";
}
?>
