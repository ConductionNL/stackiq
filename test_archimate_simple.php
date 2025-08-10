<?php

/**
 * Simple ArchiMate API Test Script
 * 
 * This script provides a quick test of the ArchiMate import/export functionality
 * by making direct API calls using PHP curl.
 * 
 * Usage: Run this script inside the Nextcloud Docker container:
 * docker-compose exec nextcloud php /var/www/html/apps-extra/softwarecatalog/test_archimate_simple.php
 */

// Configuration
$baseUrl = 'http://localhost';
$auth = 'admin:admin';
$gemmaFile = '/var/www/html/apps-extra/softwarecatalog/lib/Settings/GEMMA_release.xml';

// Helper functions
function makeApiCall($url, $method = 'GET', $data = null, $files = null, $auth = 'admin:admin') {
    $ch = curl_init();
    
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERPWD, $auth);
    // Increase default timeout to accommodate larger imports (~36s observed)
    curl_setopt($ch, CURLOPT_TIMEOUT, 300);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    
    // Set headers
    $headers = [];
    if ($files) {
        // For file uploads, don't set Content-Type header - let curl set it
        curl_setopt($ch, CURLOPT_POSTFIELDS, $files);
    } elseif ($data) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    } else {
        $headers[] = 'Content-Type: application/json';
    }
    
    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }
    
    // Set method
    switch (strtoupper($method)) {
        case 'POST':
            curl_setopt($ch, CURLOPT_POST, true);
            break;
        case 'PUT':
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            break;
        case 'DELETE':
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
            break;
    }
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    
    curl_close($ch);
    
    return [
        'body' => $response,
        'http_code' => $httpCode,
        'error' => $error
    ];
}

function logMessage($type, $message) {
    $colors = [
        'INFO' => "\033[0;34m",
        'SUCCESS' => "\033[0;32m", 
        'WARNING' => "\033[1;33m",
        'ERROR' => "\033[0;31m"
    ];
    $reset = "\033[0m";
    
    $color = $colors[$type] ?? '';
    echo $color . "[$type]" . $reset . " $message\n";
}

// Start testing
logMessage('INFO', 'Starting ArchiMate API Simple Test');
logMessage('INFO', 'Base URL: ' . $baseUrl);
logMessage('INFO', 'GEMMA File: ' . $gemmaFile);

// Test 1: Health check
logMessage('INFO', 'Test 1: Health Check');
$healthResponse = makeApiCall($baseUrl . '/index.php/apps/softwarecatalog/api/health', 'GET', null, null, $auth);

if ($healthResponse['http_code'] === 200) {
    logMessage('SUCCESS', 'Health check passed');
} else {
    logMessage('WARNING', 'Health check returned: ' . $healthResponse['http_code']);
    if ($healthResponse['error']) {
        logMessage('ERROR', 'Curl error: ' . $healthResponse['error']);
    }
}

// Test 2: Auto-configure AMEF settings
logMessage('INFO', 'Test 2: Auto-configuring AMEF settings');
$amefAutoConfigResponse = makeApiCall($baseUrl . '/index.php/apps/softwarecatalog/api/settings/amef/auto-configure', 'POST', [], null, $auth);

if ($amefAutoConfigResponse['http_code'] === 200) {
    logMessage('SUCCESS', 'AMEF auto-configuration completed');
    $amefData = json_decode($amefAutoConfigResponse['body'], true);
    if ($amefData) {
        logMessage('INFO', 'Auto-config result: ' . json_encode($amefData, JSON_PRETTY_PRINT));
    }
} else {
    logMessage('ERROR', 'AMEF auto-configuration failed with status: ' . $amefAutoConfigResponse['http_code']);
    logMessage('ERROR', 'Response: ' . $amefAutoConfigResponse['body']);
}

// Test 3: Get AMEF settings
logMessage('INFO', 'Test 3: Getting AMEF settings');
$amefGetResponse = makeApiCall($baseUrl . '/index.php/apps/softwarecatalog/api/settings/amef', 'GET', null, null, $auth);

if ($amefGetResponse['http_code'] === 200) {
    logMessage('SUCCESS', 'AMEF settings retrieved successfully');
    $settings = json_decode($amefGetResponse['body'], true);
    if ($settings) {
        logMessage('INFO', 'Current AMEF settings: ' . json_encode($settings, JSON_PRETTY_PRINT));
    }
} else {
    logMessage('ERROR', 'Failed to get AMEF settings with status: ' . $amefGetResponse['http_code']);
}

// Test 4: Check if GEMMA file exists
if (!file_exists($gemmaFile)) {
    logMessage('ERROR', 'GEMMA file not found at: ' . $gemmaFile);
    exit(1);
}

$fileSize = filesize($gemmaFile);
logMessage('INFO', 'GEMMA file found, size: ' . number_format($fileSize) . ' bytes');

// Test 5: Import ArchiMate file (simplified)
logMessage('INFO', 'Test 4: Importing GEMMA ArchiMate file');

// Create a CURLFile for the upload
$curlFile = curl_file_create($gemmaFile, 'application/xml', 'GEMMA_release.xml');
$postFields = [
    'file' => $curlFile,
    'options[update_existing]' => 'true',
    'options[organization_filter]' => ''
];

$importResponse = makeApiCall($baseUrl . '/index.php/apps/softwarecatalog/api/archimate/import', 'POST', null, $postFields, $auth);

if ($importResponse['http_code'] === 200) {
    logMessage('SUCCESS', 'ArchiMate import initiated successfully');
    $importData = json_decode($importResponse['body'], true);
    
    if ($importData && isset($importData['operation_id'])) {
        $operationId = $importData['operation_id'];
        logMessage('INFO', 'Operation ID: ' . $operationId);
        
        // Monitor progress for a short while
        logMessage('INFO', 'Monitoring import progress...');
        for ($i = 1; $i <= 5; $i++) {
            sleep(2);
            
            $progressResponse = makeApiCall($baseUrl . '/index.php/apps/softwarecatalog/api/progress/' . $operationId, 'GET', null, null, $auth);
            
            if ($progressResponse['http_code'] === 200) {
                $progressData = json_decode($progressResponse['body'], true);
                if ($progressData) {
                    $status = $progressData['status'] ?? 'unknown';
                    $phase = $progressData['phase'] ?? 'unknown';
                    $percentage = $progressData['percentage'] ?? 0;
                    
                    logMessage('INFO', "Progress check $i: Status=$status, Phase=$phase, Progress=$percentage%");
                    
                    if ($status === 'completed') {
                        logMessage('SUCCESS', 'Import completed successfully!');
                        logMessage('INFO', 'Final result: ' . json_encode($progressData, JSON_PRETTY_PRINT));
                        break;
                    } elseif ($status === 'failed') {
                        logMessage('ERROR', 'Import failed!');
                        logMessage('ERROR', 'Error details: ' . json_encode($progressData, JSON_PRETTY_PRINT));
                        break;
                    }
                }
            } else {
                logMessage('WARNING', "Progress check $i failed with status: " . $progressResponse['http_code']);
            }
        }
    } else {
        logMessage('WARNING', 'No operation ID found in import response');
        logMessage('INFO', 'Import response: ' . $importResponse['body']);
    }
} else {
    logMessage('ERROR', 'ArchiMate import failed with status: ' . $importResponse['http_code']);
    logMessage('ERROR', 'Response: ' . $importResponse['body']);
    if ($importResponse['error']) {
        logMessage('ERROR', 'Curl error: ' . $importResponse['error']);
    }
}

// Test 6: Export ArchiMate data (basic test)
logMessage('INFO', 'Test 5: Testing ArchiMate export');
$exportData = [
    'format' => 'xml',
    'include_relationships' => true,
    'include_views' => true,
    'organization_specific' => false
];

$exportResponse = makeApiCall($baseUrl . '/index.php/apps/softwarecatalog/api/archimate/export', 'POST', $exportData, null, $auth);

if ($exportResponse['http_code'] === 200) {
    logMessage('SUCCESS', 'ArchiMate export initiated successfully');
    $exportResult = json_decode($exportResponse['body'], true);
    if ($exportResult) {
        logMessage('INFO', 'Export result: ' . json_encode($exportResult, JSON_PRETTY_PRINT));
        
        if (isset($exportResult['operation_id'])) {
            $exportOperationId = $exportResult['operation_id'];
            logMessage('INFO', 'Export Operation ID: ' . $exportOperationId);
            
            // Monitor export progress briefly
            for ($i = 1; $i <= 3; $i++) {
                sleep(1);
                
                $exportProgressResponse = makeApiCall($baseUrl . '/index.php/apps/softwarecatalog/api/progress/' . $exportOperationId, 'GET', null, null, $auth);
                
                if ($exportProgressResponse['http_code'] === 200) {
                    $exportProgressData = json_decode($exportProgressResponse['body'], true);
                    if ($exportProgressData) {
                        $status = $exportProgressData['status'] ?? 'unknown';
                        $phase = $exportProgressData['phase'] ?? 'unknown';
                        $percentage = $exportProgressData['percentage'] ?? 0;
                        
                        logMessage('INFO', "Export progress check $i: Status=$status, Phase=$phase, Progress=$percentage%");
                        
                        if ($status === 'completed') {
                            logMessage('SUCCESS', 'Export completed successfully!');
                            break;
                        } elseif ($status === 'failed') {
                            logMessage('ERROR', 'Export failed!');
                            break;
                        }
                    }
                }
            }
        }
    }
} else {
    logMessage('ERROR', 'ArchiMate export failed with status: ' . $exportResponse['http_code']);
    logMessage('ERROR', 'Response: ' . $exportResponse['body']);
}

logMessage('SUCCESS', 'ArchiMate API Simple Test completed!');
logMessage('INFO', 'For more detailed testing, run the bash script: ./test_archimate_api.sh');