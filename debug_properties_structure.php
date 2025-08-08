<?php
require_once '/var/www/html/lib/base.php';

// Initialize Nextcloud
\OC::$CLI = true;

echo "🔍 DEBUGGING PROPERTIES STRUCTURE\n";
echo "==================================\n\n";

// Test XML with properties
$testXml = '<?xml version="1.0" encoding="UTF-8"?>
<element identifier="test-element" xsi:type="Capability" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
    <name xml:lang="en">Test Element</name>
    <documentation xml:lang="en">Test documentation</documentation>
    <properties>
        <property propertyDefinitionRef="propid-1">
            <value xml:lang="en">Thema-architectuur Common Ground</value>
        </property>
        <property propertyDefinitionRef="propid-2">
            <value xml:lang="en">a10869bf-a895-4a66-8f81-a4f96c58cc3e</value>
        </property>
    </properties>
</element>';

echo "1️⃣ TESTING STREAMING PARSER (parseXmlElementWithProperties)\n";
echo "-----------------------------------------------------------\n";

// Recreate the exact streaming parser method
function parseXmlElementWithProperties(\SimpleXMLElement $xml): array {
    $result = [];
    
    // Extract attributes
    $attributes = [];
    foreach ($xml->attributes() as $name => $value) {
        $attributes[$name] = (string)$value;
    }
    
    // Handle namespaced attributes
    $namespaces = $xml->getNamespaces(true);
    foreach ($namespaces as $prefix => $namespace) {
        if ($prefix) {
            foreach ($xml->attributes($prefix, true) as $name => $value) {
                $attributes["$prefix:$name"] = (string)$value;
            }
        }
    }
    
    // Handle xml namespace
    foreach ($xml->attributes('xml', true) as $name => $value) {
        $attributes["xml:$name"] = (string)$value;
    }
    
    // Get text content
    $textContent = trim((string)$xml);
    
    // Process child elements
    $children = [];
    $hasChildElements = false;
    
    foreach ($xml->children() as $name => $child) {
        $hasChildElements = true;
        $childData = parseXmlElementWithProperties($child);
        
        // Handle multiple children with same name
        if (isset($children[$name])) {
            if (!is_array($children[$name]) || !isset($children[$name][0])) {
                $children[$name] = [$children[$name]];
            }
            $children[$name][] = $childData;
        } else {
            $children[$name] = $childData;
        }
    }
    
    // Build result
    if (!empty($attributes)) {
        $result['_attributes'] = $attributes;
    }
    
    if (!empty($textContent) && !$hasChildElements) {
        $result['_value'] = $textContent;
    }
    
    if (!empty($children)) {
        $result = array_merge($result, $children);
    }
    
    if (!empty($textContent) && $hasChildElements) {
        $result['_text'] = $textContent;
    }
    
    return $result;
}

// Parse with streaming parser
$xml = new SimpleXMLElement($testXml);
$parsedData = parseXmlElementWithProperties($xml);

echo "Full parsed structure:\n";
print_r($parsedData);

echo "\n2️⃣ TESTING PROPERTIES EXTRACTION\n";
echo "--------------------------------\n";

if (isset($parsedData['properties'])) {
    echo "Properties section found:\n";
    print_r($parsedData['properties']);
    
    // Test current extractProperties logic
    echo "\n🧪 Testing Current extractProperties Logic:\n";
    function testExtractProperties(array $propertiesData): array {
        $properties = [];
        if (isset($propertiesData['property'])) {
            echo "Property array found, processing...\n";
            foreach ($propertiesData['property'] as $index => $property) {
                echo "Property $index structure:\n";
                print_r($property);
                
                $key = $property['_attributes']['propertyDefinitionRef'] ?? '';
                $value = $property['value']['_value'] ?? '';
                
                echo "Extracted: key='$key', value='$value'\n";
                $properties[$key] = $value;
            }
        } else {
            echo "❌ No 'property' key found in properties data\n";
            echo "Available keys: " . implode(', ', array_keys($propertiesData)) . "\n";
        }
        return $properties;
    }
    
    $extractedProperties = testExtractProperties($parsedData['properties']);
    
    echo "\nFinal extracted properties:\n";
    print_r($extractedProperties);
    
    // Check for issues
    echo "\n3️⃣ ISSUE ANALYSIS\n";
    echo "-----------------\n";
    
    $hasEmptyKeys = false;
    $hasPropidKeys = false;
    
    foreach ($extractedProperties as $key => $value) {
        if (empty($key)) {
            $hasEmptyKeys = true;
            echo "❌ Found empty key with value: '$value'\n";
        } elseif (strpos($key, 'propid-') === 0) {
            $hasPropidKeys = true;
            echo "✅ Found valid propid key: '$key'\n";
        } else {
            echo "⚠️ Found unexpected key: '$key'\n";
        }
    }
    
    if ($hasEmptyKeys) {
        echo "\n🚨 EMPTY KEYS DETECTED - This is the bug!\n";
    }
    
    if ($hasPropidKeys) {
        echo "\n✅ PROPID KEYS WORKING - Extraction logic is correct!\n";
    } else {
        echo "\n❌ NO PROPID KEYS - Extraction logic has issues!\n";
    }
    
} else {
    echo "❌ No properties section found in parsed data\n";
}

echo "\n4️⃣ TESTING WITH REAL GEMMA DATA\n";
echo "--------------------------------\n";

// Test with actual GEMMA file snippet
$gemmaContent = file_get_contents('/var/www/html/apps-extra/softwarecatalog/lib/Settings/GEMMA_release.xml');

// Find first element with properties
$start = strpos($gemmaContent, '<element identifier="id-009fa62f25844aa3a87d252bf2b6bb0c"');
$end = strpos($gemmaContent, '</element>', $start) + 10;
$realElementXml = substr($gemmaContent, $start, $end - $start);

echo "Real GEMMA element XML snippet:\n";
echo substr($realElementXml, 0, 500) . "...\n\n";

try {
    $realXml = new SimpleXMLElement($realElementXml);
    $realParsedData = parseXmlElementWithProperties($realXml);
    
    echo "Real GEMMA properties structure:\n";
    if (isset($realParsedData['properties'])) {
        print_r($realParsedData['properties']);
        
        $realExtracted = testExtractProperties($realParsedData['properties']);
        echo "\nReal GEMMA extracted properties:\n";
        print_r($realExtracted);
    } else {
        echo "❌ No properties found in real GEMMA data\n";
    }
    
} catch (Exception $e) {
    echo "❌ Error parsing real GEMMA data: " . $e->getMessage() . "\n";
}

echo "\n🏁 DEBUGGING COMPLETE\n";
echo "=====================\n";
?>
