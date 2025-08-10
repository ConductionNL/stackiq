<?php
/**
 * Test XML Import/Export Round-trip
 * 
 * This script tests our new ArchiMateImportService and ArchiMateExportService
 * to ensure XML can be converted to arrays and back to XML correctly.
 */

require_once '/var/www/html/lib/base.php';

// Initialize Nextcloud
\OC::$CLI = true;

echo "🧪 Testing XML Import/Export Services\n";
echo "====================================\n\n";

// Get services
$logger = \OC::$server->getLogger();
$importService = new \OCA\SoftwareCatalog\Service\ArchiMateImportService($logger);
$exportService = new \OCA\SoftwareCatalog\Service\ArchiMateExportService($logger);

// Test 1: Simple element with attributes
echo "Test 1: Simple element with attributes\n";
$testXml1 = <<<XML
<element xsi:type="archimate:ApplicationComponent" name="Test App" id="test-001" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
    <documentation>Test documentation</documentation>
</element>
XML;

$xml1 = simplexml_load_string($testXml1);
$array1 = $importService->xmlToArray($xml1);
echo "Converted to array:\n";
print_r($array1);

// Convert back to XML
$newXml1 = new SimpleXMLElement('<element/>');
$exportService->arrayToXml($array1, $newXml1);
echo "\nConverted back to XML:\n";
echo $newXml1->asXML() . "\n\n";

// Test 2: Nested structure with multiple children
echo "Test 2: Nested structure\n";
$testXml2 = <<<XML
<folder name="Application" id="folder-app" type="application">
    <element xsi:type="archimate:ApplicationComponent" name="App1" id="app-001" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"/>
    <element xsi:type="archimate:ApplicationService" name="Service1" id="svc-001" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"/>
</folder>
XML;

$xml2 = simplexml_load_string($testXml2);
$array2 = $importService->xmlToArray($xml2);
echo "Converted to array:\n";
print_r($array2);

// Convert back to XML
$newXml2 = new SimpleXMLElement('<folder/>');
$exportService->arrayToXml($array2, $newXml2);
echo "\nConverted back to XML:\n";
echo $newXml2->asXML() . "\n\n";

// Test 3: Create clean ArchiMate structure
echo "Test 3: Create clean ArchiMate XML structure\n";
$modelMetadata = [
    'name' => 'Test Model',
    'identifier' => 'test-model-001',
    'version' => '4.6.0'
];

$cleanXml = $exportService->createCleanArchiMateXml($modelMetadata);
echo "Clean ArchiMate XML:\n";
echo $cleanXml->asXML() . "\n\n";

echo "✅ XML Import/Export Services Test Complete!\n";
?>

