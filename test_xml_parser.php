<?php
/**
 * Test XML Parser for ArchiMate properties extraction
 * 
 * This script tests our new XML parser to ensure it properly extracts
 * XML attributes and properties (the critical issue from previous iterations).
 */

require_once __DIR__ . '/vendor/autoload.php';

// Sample ArchiMate XML similar to GEMMA_release.xml structure
$testXml = '<?xml version="1.0" encoding="UTF-8"?>
<model xmlns="http://www.opengroup.org/xsd/archimate/3.0/" 
       xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" 
       identifier="test-model-id">
  <name xml:lang="en">Test Model</name>
  <documentation xml:lang="en">Test documentation</documentation>
  <properties>
    <property propertyDefinitionRef="propid-1">
      <value xml:lang="en">Test Property Value</value>
    </property>
    <property propertyDefinitionRef="propid-2">
      <value xml:lang="en">Another Property</value>
    </property>
  </properties>
  <elements>
    <element identifier="id-test-element-1" xsi:type="Capability">
      <name xml:lang="en">Test Element Name</name>
      <documentation xml:lang="en">Element documentation</documentation>
      <properties>
        <property propertyDefinitionRef="propid-elem-1">
          <value xml:lang="en">Element Property Value</value>
        </property>
      </properties>
    </element>
    <element identifier="id-test-element-2" xsi:type="BusinessActor">
      <name xml:lang="en">Test Organization</name>
      <properties>
        <property propertyDefinitionRef="propid-elem-2">
          <value xml:lang="en">Organization Property</value>
        </property>
      </properties>
    </element>
  </elements>
  <relationships>
    <relationship identifier="id-test-rel-1" xsi:type="AssociationRelationship" 
                  source="id-test-element-1" target="id-test-element-2">
      <name xml:lang="en">Test Relationship</name>
    </relationship>
  </relationships>
</model>';

echo "🧪 Testing ArchiMate XML Parser\n";
echo str_repeat("=", 40) . "\n\n";

// Test 1: Basic XML parsing (the broken way)
echo "❌ Old Method (json_decode/json_encode - LOSES ATTRIBUTES):\n";
$oldXml = simplexml_load_string($testXml);
$oldResult = json_decode(json_encode($oldXml), true);
echo "Model identifier: " . ($oldResult['@attributes']['identifier'] ?? 'MISSING!') . "\n";
echo "Element 1 identifier: " . ($oldResult['elements']['element'][0]['@attributes']['identifier'] ?? 'MISSING!') . "\n";
echo "Element 1 type: " . ($oldResult['elements']['element'][0]['@attributes']['xsi:type'] ?? 'MISSING!') . "\n";
echo "Property definitionRef: " . ($oldResult['properties']['property'][0]['@attributes']['propertyDefinitionRef'] ?? 'MISSING!') . "\n\n";

// Test 2: New comprehensive parsing (should preserve attributes)
echo "✅ New Method (preserves attributes and properties):\n";

// Simulate the new parsing method
function parseXmlElementWithProperties($xml) {
    $result = [];
    
    // Extract attributes
    $attributes = [];
    foreach ($xml->attributes() as $name => $value) {
        $attributes[$name] = (string)$value;
    }
    
    // Handle all namespaced attributes
    $namespaces = $xml->getNamespaces(true);
    foreach ($namespaces as $prefix => $namespace) {
        if ($prefix) { // Skip default namespace
            foreach ($xml->attributes($prefix, true) as $name => $value) {
                $attributes["$prefix:$name"] = (string)$value;
            }
        }
    }
    
    // Explicitly handle xml namespace (common in ArchiMate for xml:lang)
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

$newXml = simplexml_load_string($testXml);
$newResult = parseXmlElementWithProperties($newXml);

echo "Model identifier: " . ($newResult['_attributes']['identifier'] ?? 'MISSING!') . "\n";
echo "Model name: " . ($newResult['name']['_value'] ?? 'MISSING!') . "\n";
echo "Model name attributes: " . json_encode($newResult['name']['_attributes'] ?? []) . "\n";
echo "Model name language: " . ($newResult['name']['_attributes']['xml:lang'] ?? 'MISSING!') . "\n\n";

echo "Properties found: " . count($newResult['properties']['property'] ?? []) . "\n";
if (isset($newResult['properties']['property'][0])) {
    $prop1 = $newResult['properties']['property'][0];
    echo "Property 1 definitionRef: " . ($prop1['_attributes']['propertyDefinitionRef'] ?? 'MISSING!') . "\n";
    echo "Property 1 value: " . ($prop1['value']['_value'] ?? 'MISSING!') . "\n";
    echo "Property 1 language: " . ($prop1['value']['_attributes']['xml:lang'] ?? 'MISSING!') . "\n";
}
echo "\n";

echo "Elements found: " . count($newResult['elements']['element'] ?? []) . "\n";
if (isset($newResult['elements']['element'][0])) {
    $elem1 = $newResult['elements']['element'][0];
    echo "Element 1 identifier: " . ($elem1['_attributes']['identifier'] ?? 'MISSING!') . "\n";
    echo "Element 1 type: " . ($elem1['_attributes']['xsi:type'] ?? 'MISSING!') . "\n";
    echo "Element 1 name: " . ($elem1['name']['_value'] ?? 'MISSING!') . "\n";
    echo "Element 1 documentation: " . ($elem1['documentation']['_value'] ?? 'MISSING!') . "\n";
    
    if (isset($elem1['properties']['property'][0])) {
        $elemProp = $elem1['properties']['property'][0];
        echo "Element 1 property definitionRef: " . ($elemProp['_attributes']['propertyDefinitionRef'] ?? 'MISSING!') . "\n";
        echo "Element 1 property value: " . ($elemProp['value']['_value'] ?? 'MISSING!') . "\n";
    }
}
echo "\n";

echo "Relationships found: " . count($newResult['relationships']['relationship'] ?? []) . "\n";
if (isset($newResult['relationships']['relationship'][0])) {
    $rel1 = $newResult['relationships']['relationship'][0];
    echo "Relationship 1 identifier: " . ($rel1['_attributes']['identifier'] ?? 'MISSING!') . "\n";
    echo "Relationship 1 type: " . ($rel1['_attributes']['xsi:type'] ?? 'MISSING!') . "\n";
    echo "Relationship 1 source: " . ($rel1['_attributes']['source'] ?? 'MISSING!') . "\n";
    echo "Relationship 1 target: " . ($rel1['_attributes']['target'] ?? 'MISSING!') . "\n";
}
echo "\n";

// Test 3: Show the full parsed structure
echo "🔍 Full Parsed Structure (first few levels):\n";
echo str_repeat("-", 30) . "\n";

function printStructure($data, $indent = 0) {
    $prefix = str_repeat("  ", $indent);
    
    if (is_array($data)) {
        foreach ($data as $key => $value) {
            if ($key === '_attributes') {
                echo $prefix . "📋 $key: " . json_encode($value) . "\n";
            } elseif ($key === '_value' || $key === '_text') {
                echo $prefix . "📝 $key: " . substr($value, 0, 50) . (strlen($value) > 50 ? '...' : '') . "\n";
            } elseif (is_array($value) && $indent < 3) {
                echo $prefix . "📁 $key:\n";
                if (isset($value[0]) && is_array($value[0])) {
                    echo $prefix . "  [Array with " . count($value) . " items]\n";
                    if (count($value) > 0) {
                        printStructure($value[0], $indent + 2);
                    }
                } else {
                    printStructure($value, $indent + 1);
                }
            } else {
                echo $prefix . "📄 $key: " . (is_array($value) ? '[Array]' : $value) . "\n";
            }
        }
    } else {
        echo $prefix . "📄 " . $data . "\n";
    }
}

printStructure($newResult);

echo "\n" . str_repeat("=", 40) . "\n";
echo "🎯 Test Summary:\n";
echo "✅ Attributes preserved: " . (isset($newResult['_attributes']['identifier']) ? 'YES' : 'NO') . "\n";
echo "✅ Namespaced attributes: " . (isset($newResult['elements']['element'][0]['_attributes']['xsi:type']) ? 'YES' : 'NO') . "\n";
echo "✅ Property attributes: " . (isset($newResult['properties']['property'][0]['_attributes']['propertyDefinitionRef']) ? 'YES' : 'NO') . "\n";
echo "✅ Multilingual text: " . (isset($newResult['name']['_attributes']['xml:lang']) ? 'YES' : 'NO') . "\n";
echo "✅ Relationship references: " . (isset($newResult['relationships']['relationship'][0]['_attributes']['source']) ? 'YES' : 'NO') . "\n";
echo "\nThis parser should now correctly handle the GEMMA_release.xml file! 🚀\n";