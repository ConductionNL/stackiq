<?php
require_once '/var/www/html/lib/base.php';

// Initialize Nextcloud
\OC::$CLI = true;

// Test the actual streaming parser on a small sample
$xmlContent = file_get_contents('/var/www/html/apps-extra/softwarecatalog/lib/Settings/GEMMA_release.xml');

// Find the first element with properties
$start = strpos($xmlContent, '<element identifier="id-009fa62f25844aa3a87d252bf2b6bb0c"');
$end = strpos($xmlContent, '</element>', $start) + 10;
$elementXml = substr($xmlContent, $start, $end - $start);

echo "=== TESTING ACTUAL STREAMING PARSER ===\n";

// Use the same method as ArchiMateService
$xml = new SimpleXMLElement($elementXml);

// Create a mock ArchiMateService to test the actual parsing method
class TestArchiMateService {
    private function parseXmlElementWithProperties(\SimpleXMLElement $xml): array
    {
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
            $childData = $this->parseXmlElementWithProperties($child);
            
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
    
    public function testParsing($xml) {
        return $this->parseXmlElementWithProperties($xml);
    }
}

$service = new TestArchiMateService();
$parsed = $service->testParsing($xml);

echo "Parsed structure:\n";
print_r($parsed);

echo "\n=== TESTING PROPERTY EXTRACTION ===\n";

if (isset($parsed['properties'])) {
    echo "Properties found:\n";
    print_r($parsed['properties']);
    
    // Test the extractProperties logic
    $properties = [];
    $propertiesData = $parsed['properties'];
    
    if (isset($propertiesData['property'])) {
        echo "\nProperty data structure:\n";
        print_r($propertiesData['property']);
        
        echo "\nExtracting properties:\n";
        foreach ($propertiesData['property'] as $property) {
            $key = $property['_attributes']['propertyDefinitionRef'] ?? '';
            $value = $property['value']['_value'] ?? '';
            $properties[$key] = $value;
            echo "  '$key' => '$value'\n";
        }
        
        echo "\nFinal extracted properties:\n";
        print_r($properties);
    }
}
?>
