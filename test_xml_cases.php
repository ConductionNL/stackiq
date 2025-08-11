<?php
/**
 * Test XML Import/Export for Different ArchiMate Cases
 * 
 * This script tests all the different XML patterns we encounter in ArchiMate:
 * 1. Attributes vs child nodes
 * 2. Namespaced attributes (xsi:type, xml:lang)
 * 3. Referenced attributes (propertyDefinitionRef)
 * 4. Mixed content (text + children)
 * 5. Nested structures
 */

require_once '/var/www/html/lib/base.php';

// Initialize Nextcloud
\OC::$CLI = true;

echo "🧪 Testing XML Cases for ArchiMate Import/Export\n";
echo "===============================================\n\n";

// Get services
$logger = \OC::$server->getLogger();
$importService = new \OCA\SoftwareCatalog\Service\ArchiMateImportService($logger);
$exportService = new \OCA\SoftwareCatalog\Service\ArchiMateExportService($logger);

function testXmlCase($name, $xmlString, $importService, $exportService) {
    echo "=== $name ===\n";
    echo "Original XML:\n$xmlString\n\n";
    
    $xml = simplexml_load_string($xmlString);
    $array = $importService->xmlToArray($xml);
    echo "Converted to Array:\n";
    print_r($array);
    
    // Convert back to XML
    $rootTag = $xml->getName();
    $newXml = new SimpleXMLElement("<$rootTag/>");
    $exportService->arrayToXml($array, $newXml);
    echo "\nConverted back to XML:\n";
    echo $newXml->asXML() . "\n\n";
    
    echo str_repeat("-", 80) . "\n\n";
}

// Case 1: Element with namespaced attributes and child nodes
$case1 = <<<XML
<element identifier="id-009fa62f25844aa3a87d252bf2b6bb0c" xsi:type="Capability" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
    <name xml:lang="en">Test Element</name>
    <documentation xml:lang="en">Test documentation</documentation>
</element>
XML;

testXmlCase("Case 1: Element with namespaced attributes", $case1, $importService, $exportService);

// Case 2: Property with referenced attribute
$case2 = <<<XML
<property propertyDefinitionRef="propid-67">
    <value xml:lang="en">Test Property Value</value>
</property>
XML;

testXmlCase("Case 2: Property with referenced attribute", $case2, $importService, $exportService);

// Case 3: Model with multiple namespaces
$case3 = <<<XML
<model xmlns="http://www.opengroup.org/xsd/archimate/3.0/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="http://www.opengroup.org/xsd/archimate/3.0/ http://www.opengroup.org/xsd/archimate/3.1/archimate3_Diagram.xsd" identifier="id-test">
    <name xml:lang="en">Test Model</name>
</model>
XML;

testXmlCase("Case 3: Model with multiple namespaces", $case3, $importService, $exportService);

// Case 4: Properties collection
$case4 = <<<XML
<properties>
    <property propertyDefinitionRef="propid-1">
        <value xml:lang="en">Value 1</value>
    </property>
    <property propertyDefinitionRef="propid-2">
        <value xml:lang="en">Value 2</value>
    </property>
</properties>
XML;

testXmlCase("Case 4: Properties collection", $case4, $importService, $exportService);

// Case 5: Relationship with source/target attributes
$case5 = <<<XML
<relationship identifier="rel-001" xsi:type="ServingRelationship" source="elem-001" target="elem-002" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
    <name xml:lang="en">Test Relationship</name>
</relationship>
XML;

testXmlCase("Case 5: Relationship with source/target", $case5, $importService, $exportService);

// Case 6: Complex nested structure (folder with elements)
$case6 = <<<XML
<folder name="Application" id="folder-app" type="application">
    <element identifier="elem-001" xsi:type="ApplicationComponent" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
        <name xml:lang="en">App Component</name>
        <properties>
            <property propertyDefinitionRef="propid-1">
                <value xml:lang="en">Component Value</value>
            </property>
        </properties>
    </element>
    <element identifier="elem-002" xsi:type="ApplicationService" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
        <name xml:lang="en">App Service</name>
    </element>
</folder>
XML;

testXmlCase("Case 6: Complex nested structure", $case6, $importService, $exportService);

echo "✅ All XML Cases Tested!\n";
echo "\nKey observations:\n";
echo "- Attributes are stored with _ prefix (e.g., _identifier, _xsi__type)\n";
echo "- Namespaced attributes use double underscore (xsi:type → _xsi__type)\n";
echo "- Legacy _attributes bag is preserved for compatibility\n";
echo "- Text content is stored as _value for leaf nodes, _text for mixed content\n";
echo "- Arrays are used for repeated elements\n";
?>

