<?php
require_once '/var/www/html/lib/base.php';

// Initialize Nextcloud
\OC::$CLI = true;

// Test what the streaming parser actually produces
$xmlContent = '<element identifier="id-009fa62f25844aa3a87d252bf2b6bb0c" xsi:type="Capability">
      <name xml:lang="en">Publiceren en gebruiken van informatie over datadiensten</name>
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

// Use SimpleXMLElement to see what structure we get
$xml = new SimpleXMLElement($xmlContent);

echo "=== SIMPLEXML STRUCTURE ===\n";

// Convert to array like our code might do
$elementArray = json_decode(json_encode($xml), true);

echo "Full element array:\n";
print_r($elementArray);

echo "\n=== PROPERTIES SECTION ===\n";
if (isset($elementArray['properties'])) {
    echo "Properties section found:\n";
    print_r($elementArray['properties']);
    
    // Test our extraction logic on this structure
    echo "\n=== TESTING EXTRACTION ===\n";
    $properties = [];
    $propertiesData = $elementArray['properties'];
    
    if (isset($propertiesData['property'])) {
        echo "Property array found, type: " . gettype($propertiesData['property']) . "\n";
        
        // Check if it's a single property or array of properties
        if (isset($propertiesData['property']['@attributes'])) {
            // Single property
            echo "Single property detected\n";
            $property = $propertiesData['property'];
            $key = $property['@attributes']['propertyDefinitionRef'] ?? '';
            $value = $property['value'] ?? '';
            if (is_array($value)) {
                $value = $value['@content'] ?? $value['#text'] ?? '';
            }
            $properties[$key] = $value;
            echo "Extracted: '$key' => '$value'\n";
        } else {
            // Array of properties
            echo "Multiple properties detected\n";
            foreach ($propertiesData['property'] as $property) {
                $key = $property['@attributes']['propertyDefinitionRef'] ?? '';
                $value = $property['value'] ?? '';
                if (is_array($value)) {
                    $value = $value['@content'] ?? $value['#text'] ?? '';
                }
                $properties[$key] = $value;
                echo "Extracted: '$key' => '$value'\n";
            }
        }
    } else {
        echo "No 'property' key found in properties\n";
        echo "Available keys: " . implode(', ', array_keys($propertiesData)) . "\n";
    }
    
    echo "\nFinal properties array:\n";
    print_r($properties);
}
?>
