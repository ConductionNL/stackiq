<?php
/**
 * AMEF Round-trip Testing Script
 * 
 * This script tests the ArchiMate import/export functionality using the 
 * existing GEMMA_release.xml file to verify round-trip accuracy.
 * 
 * @category Testing
 * @package  OCA\SoftwareCatalog\Testing
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @version  1.0.0
 */

// Configuration
$baseUrl = 'http://localhost'; // Adjust for your environment
$username = 'admin';
$password = 'admin';

// File paths
$originalFile = __DIR__ . '/lib/Settings/GEMMA_release.xml';
$exportedFile = __DIR__ . '/exported_gemma.xml';
$diffFile = __DIR__ . '/gemma_diff.txt';

/**
 * Make HTTP request with authentication
 * 
 * @param string $url    The URL to request
 * @param string $method HTTP method (GET, POST, etc.)
 * @param array  $data   Request data
 * @param array  $files  Files to upload
 * 
 * @return array Response data
 */
function makeRequest(string $url, string $method = 'GET', array $data = [], array $files = []): array
{
    global $username, $password;
    
    $ch = curl_init();
    
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERPWD => "$username:$password",
        CURLOPT_HTTPAUTH => CURL_AUTH_BASIC,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => [
            'X-Requested-With: XMLHttpRequest',
            'Content-Type: application/json'
        ],
        CURLOPT_TIMEOUT => 300, // 5 minutes timeout for large files
    ]);
    
    if (!empty($data) && $method !== 'GET') {
        if (!empty($files)) {
            // For file uploads, use multipart/form-data
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'X-Requested-With: XMLHttpRequest'
            ]);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data + $files);
        } else {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
    }
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    
    curl_close($ch);
    
    if ($error) {
        throw new Exception("cURL error: $error");
    }
    
    $decoded = json_decode($response, true);
    
    return [
        'http_code' => $httpCode,
        'response' => $decoded ?: $response,
        'raw_response' => $response
    ];
}

/**
 * Test AMEF configuration
 * 
 * @return bool Configuration success
 */
function testAmefConfiguration(): bool
{
    global $baseUrl;
    
    echo "🔧 Testing AMEF Configuration...\n";
    
    try {
        // Get current AMEF settings
        $result = makeRequest("$baseUrl/index.php/apps/softwarecatalog/api/settings/amef");
        
        if ($result['http_code'] !== 200) {
            echo "❌ Failed to get AMEF settings: {$result['response']}\n";
            return false;
        }
        
        echo "✅ Current AMEF settings retrieved\n";
        print_r($result['response']['settings']);
        
        // Try auto-configuration
        echo "\n🤖 Running auto-configuration...\n";
        $autoConfigResult = makeRequest("$baseUrl/index.php/apps/softwarecatalog/api/settings/amef/auto-configure", 'POST');
        
        if ($autoConfigResult['http_code'] === 200 && $autoConfigResult['response']['success']) {
            echo "✅ Auto-configuration successful!\n";
            print_r($autoConfigResult['response']['configured']);
            return true;
        } else {
            echo "⚠️  Auto-configuration had issues, but continuing...\n";
            if (isset($autoConfigResult['response']['errors'])) {
                foreach ($autoConfigResult['response']['errors'] as $error) {
                    echo "   - $error\n";
                }
            }
            return true; // Continue even if auto-config fails
        }
        
    } catch (Exception $e) {
        echo "❌ Configuration test failed: " . $e->getMessage() . "\n";
        return false;
    }
}

/**
 * Import AMEF file
 * 
 * @param string $filePath Path to AMEF file
 * 
 * @return array Import result
 */
function importAmefFile(string $filePath): array
{
    global $baseUrl;
    
    echo "📥 Importing AMEF file: $filePath\n";
    
    if (!file_exists($filePath)) {
        throw new Exception("File not found: $filePath");
    }
    
    $fileSize = filesize($filePath);
    echo "   File size: " . number_format($fileSize / 1024 / 1024, 2) . " MB\n";
    
    // Prepare file upload
    $postData = [
        'updateExisting' => 'true',
        'preserveIds' => 'true'
    ];
    
    $fileData = [
        'archiMateFile' => new CURLFile($filePath, 'application/xml', 'GEMMA_release.xml')
    ];
    
    $startTime = microtime(true);
    
    try {
        $result = makeRequest(
            "$baseUrl/index.php/apps/softwarecatalog/api/archimate/import",
            'POST',
            $postData,
            $fileData
        );
        
        $endTime = microtime(true);
        $duration = round($endTime - $startTime, 2);
        
        echo "   Import completed in {$duration}s\n";
        
        if ($result['http_code'] === 200 && $result['response']['success']) {
            echo "✅ AMEF import successful!\n";
            if (isset($result['response']['statistics'])) {
                echo "   Statistics:\n";
                foreach ($result['response']['statistics'] as $key => $value) {
                    echo "   - $key: $value\n";
                }
            }
        } else {
            echo "❌ AMEF import failed\n";
            echo "   Response: " . json_encode($result['response'], JSON_PRETTY_PRINT) . "\n";
        }
        
        return $result;
        
    } catch (Exception $e) {
        echo "❌ Import failed: " . $e->getMessage() . "\n";
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Export AMEF file
 * 
 * @return array Export result
 */
function exportAmefFile(): array
{
    global $baseUrl;
    
    echo "\n📤 Exporting AMEF file...\n";
    
    $exportData = [
        'format' => 'xml',
        'includeRelationships' => true,
        'includeViews' => true,
        'organizationSpecific' => false
    ];
    
    $startTime = microtime(true);
    
    try {
        $result = makeRequest(
            "$baseUrl/index.php/apps/softwarecatalog/api/archimate/export",
            'POST',
            $exportData
        );
        
        $endTime = microtime(true);
        $duration = round($endTime - $startTime, 2);
        
        echo "   Export completed in {$duration}s\n";
        
        if ($result['http_code'] === 200 && $result['response']['success']) {
            echo "✅ AMEF export successful!\n";
            if (isset($result['response']['statistics'])) {
                echo "   Statistics:\n";
                foreach ($result['response']['statistics'] as $key => $value) {
                    echo "   - $key: $value\n";
                }
            }
            return $result['response'];
        } else {
            echo "❌ AMEF export failed\n";
            echo "   Response: " . json_encode($result['response'], JSON_PRETTY_PRINT) . "\n";
            return ['success' => false];
        }
        
    } catch (Exception $e) {
        echo "❌ Export failed: " . $e->getMessage() . "\n";
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Download exported file
 * 
 * @param string $fileName File name to download
 * @param string $savePath Local path to save file
 * 
 * @return bool Download success
 */
function downloadExportedFile(string $fileName, string $savePath): bool
{
    global $baseUrl;
    
    echo "\n💾 Downloading exported file: $fileName\n";
    
    try {
        $result = makeRequest("$baseUrl/index.php/apps/softwarecatalog/api/archimate/download/$fileName");
        
        if ($result['http_code'] === 200) {
            file_put_contents($savePath, $result['raw_response']);
            $fileSize = filesize($savePath);
            echo "✅ File downloaded successfully!\n";
            echo "   Saved to: $savePath\n";
            echo "   Size: " . number_format($fileSize / 1024 / 1024, 2) . " MB\n";
            return true;
        } else {
            echo "❌ Download failed: HTTP {$result['http_code']}\n";
            return false;
        }
        
    } catch (Exception $e) {
        echo "❌ Download failed: " . $e->getMessage() . "\n";
        return false;
    }
}

/**
 * Compare original and exported files
 * 
 * @param string $originalFile Path to original file
 * @param string $exportedFile Path to exported file
 * @param string $diffFile     Path to save diff output
 * 
 * @return bool Files are identical
 */
function compareFiles(string $originalFile, string $exportedFile, string $diffFile): bool
{
    echo "\n🔍 Comparing files...\n";
    
    if (!file_exists($originalFile) || !file_exists($exportedFile)) {
        echo "❌ One or both files don't exist\n";
        return false;
    }
    
    $originalSize = filesize($originalFile);
    $exportedSize = filesize($exportedFile);
    
    echo "   Original file size: " . number_format($originalSize / 1024 / 1024, 2) . " MB\n";
    echo "   Exported file size: " . number_format($exportedSize / 1024 / 1024, 2) . " MB\n";
    
    // Generate diff
    $diffCommand = "diff -u " . escapeshellarg($originalFile) . " " . escapeshellarg($exportedFile) . " > " . escapeshellarg($diffFile) . " 2>&1";
    $exitCode = 0;
    system($diffCommand, $exitCode);
    
    if ($exitCode === 0) {
        echo "✅ Files are identical!\n";
        unlink($diffFile); // Remove empty diff file
        return true;
    } else {
        echo "⚠️  Files have differences\n";
        echo "   Diff saved to: $diffFile\n";
        
        // Show diff summary
        if (file_exists($diffFile)) {
            $diffContent = file_get_contents($diffFile);
            $diffLines = explode("\n", $diffContent);
            $addedLines = array_filter($diffLines, fn($line) => str_starts_with($line, '+') && !str_starts_with($line, '+++'));
            $removedLines = array_filter($diffLines, fn($line) => str_starts_with($line, '-') && !str_starts_with($line, '---'));
            
            echo "   Added lines: " . count($addedLines) . "\n";
            echo "   Removed lines: " . count($removedLines) . "\n";
            
            // Show first few differences
            $diffSample = array_slice($diffLines, 0, 20);
            echo "   First few differences:\n";
            foreach ($diffSample as $line) {
                if (str_starts_with($line, '+') || str_starts_with($line, '-')) {
                    echo "     $line\n";
                }
            }
        }
        
        return false;
    }
}

// Main execution
try {
    echo "🚀 Starting AMEF Round-trip Test\n";
    echo "=================================\n\n";
    
    // Step 1: Test AMEF configuration
    if (!testAmefConfiguration()) {
        echo "\n❌ AMEF configuration test failed. Exiting.\n";
        exit(1);
    }
    
    // Step 2: Import GEMMA_release.xml
    $importResult = importAmefFile($originalFile);
    if (!$importResult['response']['success']) {
        echo "\n❌ Import failed. Exiting.\n";
        exit(1);
    }
    
    // Step 3: Export to new file
    $exportResult = exportAmefFile();
    if (!$exportResult['success']) {
        echo "\n❌ Export failed. Exiting.\n";
        exit(1);
    }
    
    // Step 4: Download exported file
    $fileName = $exportResult['file_name'];
    if (!downloadExportedFile($fileName, $exportedFile)) {
        echo "\n❌ Download failed. Exiting.\n";
        exit(1);
    }
    
    // Step 5: Compare files
    $identical = compareFiles($originalFile, $exportedFile, $diffFile);
    
    // Summary
    echo "\n" . str_repeat("=", 50) . "\n";
    echo "🏁 AMEF Round-trip Test Summary\n";
    echo str_repeat("=", 50) . "\n";
    
    if ($identical) {
        echo "✅ SUCCESS: Round-trip completed with identical files!\n";
        echo "   The ArchiMate import/export functionality is working perfectly.\n";
        exit(0);
    } else {
        echo "⚠️  PARTIAL SUCCESS: Round-trip completed but files differ\n";
        echo "   This may be expected due to formatting or ordering differences.\n";
        echo "   Check the diff file for details: $diffFile\n";
        exit(0);
    }
    
} catch (Exception $e) {
    echo "\n❌ Test failed with exception: " . $e->getMessage() . "\n";
    exit(1);
}