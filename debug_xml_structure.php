<?php
require_once '/var/www/html/lib/base.php';

// Initialize Nextcloud
\OC::$CLI = true;

// Parse a small portion of the XML to see the actual structure
$xmlFile = '/var/www/html/apps-extra/softwarecatalog/lib/Settings/GEMMA_release.xml';
$xmlContent = file_get_contents($xmlFile);

// Find the first element with properties
$start = strpos($xmlContent, '<element identifier="id-009fa62f25844aa3a87d252bf2b6bb0c"');
$end = strpos($xmlContent, '</element>', $start) + 10;
$elementXml = substr($xmlContent, $start, $end - $start);

echo "=== ELEMENT XML STRUCTURE ===\n";
echo $elementXml . "\n\n";

// Parse it and see the structure
$dom = new DOMDocument();
$dom->loadXML('<root>' . $elementXml . '</root>');
$element = $dom->getElementsByTagName('element')->item(0);

echo "=== PARSED STRUCTURE ===\n";
$properties = $element->getElementsByTagName('properties')->item(0);
if ($properties) {
    echo "Found properties section\n";
    $propertyNodes = $properties->getElementsByTagName('property');
    echo "Property count: " . $propertyNodes->length . "\n";
    
    for ($i = 0; $i < $propertyNodes->length; $i++) {
        $prop = $propertyNodes->item($i);
        $propDefRef = $prop->getAttribute('propertyDefinitionRef');
        $value = $prop->getElementsByTagName('value')->item(0);
        $valueText = $value ? $value->textContent : 'NO VALUE';
        
        echo "Property $i:\n";
        echo "  propertyDefinitionRef: '$propDefRef'\n";
        echo "  value: '$valueText'\n";
    }
} else {
    echo "No properties section found\n";
}

// Test our XML parsing logic
echo "\n=== TESTING OUR PARSER ===\n";

// Simulate the streaming parser structure
$testData = [
    'properties' => [
        'property' => [
            [
                '_attributes' => ['propertyDefinitionRef' => 'propid-1'],
                'value' => ['_value' => 'Thema-architectuur Common Ground']
            ],
            [
                '_attributes' => ['propertyDefinitionRef' => 'propid-2'], 
                'value' => ['_value' => 'a10869bf-a895-4a66-8f81-a4f96c58cc3e']
            ]
        ]
    ]
];

// Test our extraction logic
$properties = [];
if (isset($testData['properties']['property'])) {
    foreach ($testData['properties']['property'] as $property) {
        $key = $property['_attributes']['propertyDefinitionRef'] ?? '';
        $value = $property['value']['_value'] ?? '';
        $properties[$key] = $value;
        echo "Extracted: '$key' => '$value'\n";
    }
}

print_r($properties);
?>
