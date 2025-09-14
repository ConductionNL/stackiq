<?php
require_once '/var/www/html/lib/base.php';

// Let's simulate what our code should be doing
echo "=== DEBUGGING SLUG LOGIC ===" . PHP_EOL;

// This simulates what we extract from the XML
$extractedObject = [
    'identifier' => 'id-d4572e2e-aa3e-4cbe-94aa-16f0e2ae9620',
    'section' => 'elements', 
    'model_identifier' => 'some-model-id',
    'extracted_at' => time(),
    'xml' => [], // XML data
    'GEMMA type' => 'Buitengemeentelijke dienst',
    'Object ID' => 'd4572e2e-aa3e-4cbe-94aa-16f0e2ae9620',
    '_slug' => 'd4572e2e-aa3e-4cbe-94aa-16f0e2ae9620' // This should be set by our property processing
];

echo "1. Extracted object structure:" . PHP_EOL;
foreach ($extractedObject as $key => $value) {
    if (is_array($value)) {
        echo "  $key: [array]" . PHP_EOL;
    } else {
        echo "  $key: $value" . PHP_EOL;
    }
}

echo PHP_EOL . "2. Creating @self structure..." . PHP_EOL;

// This simulates our createSectionObject method
$identifier = $extractedObject['identifier'];
$registerId = 15;
$schemaId = 66;

$object = [
    '@self' => [
        'register' => $registerId,
        'schema' => $schemaId,
        'id' => $identifier,
        'owner' => 'admin',
        'organisation' => 'default',
        'created' => date('Y-m-d H:i:s'),
        'updated' => date('Y-m-d H:i:s')
    ]
];

// Check if there's a temporary slug to move to @self structure
if (isset($extractedObject['_slug'])) {
    $object['@self']['slug'] = $extractedObject['_slug'];
    unset($extractedObject['_slug']); // Remove the temporary field
    echo "✓ Found _slug, moved to @self.slug: " . $object['@self']['slug'] . PHP_EOL;
} else {
    echo "❌ No _slug found in extracted object" . PHP_EOL;
}

// Merge the rest of the data
$finalObject = array_merge($object, $extractedObject);

echo PHP_EOL . "3. Final object @self structure:" . PHP_EOL;
foreach ($finalObject['@self'] as $key => $value) {
    echo "  @self.$key: $value" . PHP_EOL;
}

echo PHP_EOL . "4. Expected database mapping:" . PHP_EOL;
echo "  uuid column: " . $finalObject['@self']['id'] . PHP_EOL;
echo "  slug column: " . ($finalObject['@self']['slug'] ?? '(NOT SET)') . PHP_EOL;
echo "  register column: " . $finalObject['@self']['register'] . PHP_EOL;
echo "  schema column: " . $finalObject['@self']['schema'] . PHP_EOL;

