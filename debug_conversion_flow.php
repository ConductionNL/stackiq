<?php
require_once '/var/www/html/lib/base.php';

// Initialize Nextcloud
\OC::$CLI = true;

echo "🔍 DEBUGGING CONVERSION FLOW\n";
echo "=============================\n\n";

echo "1️⃣ SIMULATING NORMALIZED ELEMENT DATA (after extractProperties)\n";
echo "----------------------------------------------------------------\n";

// This is what we expect after extractProperties works correctly
$normalizedElement = [
    'id' => 'test-element-id',
    'name' => 'Test Element',
    'type' => 'Capability',
    'documentation' => 'Test documentation',
    'properties' => [
        'propid-1' => 'Thema-architectuur Common Ground',
        'propid-2' => 'a10869bf-a895-4a66-8f81-a4f96c58cc3e'
    ]
];

echo "Normalized element data:\n";
print_r($normalizedElement);

echo "\n2️⃣ SIMULATING convertToOpenRegisterFormat\n";
echo "-------------------------------------------\n";

function testConvertToOpenRegisterFormat(array $archiMateData, string $type, ?string $modelIdentifier = null): array {
    $baseData = [
        'archimate_id' => $archiMateData['id'],
        'name' => $archiMateData['name'] ?? '',
        'documentation' => $archiMateData['documentation'] ?? '',
        'properties' => $archiMateData['properties'] ?? [],
        'original_archimate_type' => $archiMateData['type'] ?? ''
    ];
    
    echo "Before adding model properties:\n";
    print_r($baseData['properties']);
    
    // Always add model identifier - THIS IS WHERE THE BUG MIGHT BE!
    $baseData['model_id'] = $modelIdentifier ?? '';
    $baseData['properties']['model'] = $modelIdentifier ?? '';
    $baseData['properties']['modal'] = $modelIdentifier ?? '';
    
    echo "\nAfter adding model properties:\n";
    print_r($baseData['properties']);
    
    return array_merge($baseData, [
        'archimate_type' => $archiMateData['type'] ?? '',
        'schema_id' => 123, // Mock schema ID
        'register_id' => 456 // Mock register ID
    ]);
}

$convertedData = testConvertToOpenRegisterFormat($normalizedElement, 'element', 'test-model-id');

echo "\nFinal converted data:\n";
print_r($convertedData);

echo "\n3️⃣ PROPERTY ANALYSIS\n";
echo "--------------------\n";

$properties = $convertedData['properties'];
$hasPropidKeys = false;
$hasEmptyKeys = false;
$hasInternalKeys = false;

foreach ($properties as $key => $value) {
    if (empty($key)) {
        $hasEmptyKeys = true;
        echo "❌ Empty key found: '$value'\n";
    } elseif (strpos($key, 'propid-') === 0) {
        $hasPropidKeys = true;
        echo "✅ Valid propid key: '$key' => '$value'\n";
    } elseif (in_array($key, ['model', 'modal'])) {
        $hasInternalKeys = true;
        echo "⚠️ Internal key: '$key' => '$value'\n";
    } else {
        echo "❓ Unknown key: '$key' => '$value'\n";
    }
}

echo "\nSummary:\n";
echo "- Has propid keys: " . ($hasPropidKeys ? "YES ✅" : "NO ❌") . "\n";
echo "- Has empty keys: " . ($hasEmptyKeys ? "YES ❌" : "NO ✅") . "\n";
echo "- Has internal keys: " . ($hasInternalKeys ? "YES" : "NO") . "\n";

if ($hasPropidKeys && !$hasEmptyKeys) {
    echo "\n🎉 CONVERSION FLOW LOOKS GOOD!\n";
    echo "The issue must be elsewhere...\n";
} else {
    echo "\n🚨 CONVERSION FLOW HAS ISSUES!\n";
}

echo "\n4️⃣ TESTING EMPTY ARRAY SCENARIO\n";
echo "--------------------------------\n";

// Test what happens when extractProperties returns empty array
$elementWithEmptyProperties = [
    'id' => 'test-element-id',
    'name' => 'Test Element',
    'type' => 'Capability',
    'documentation' => 'Test documentation',
    'properties' => [] // Empty properties - this might be the real issue!
];

echo "Element with empty properties:\n";
print_r($elementWithEmptyProperties);

$convertedEmptyProps = testConvertToOpenRegisterFormat($elementWithEmptyProperties, 'element', 'test-model-id');

echo "\nConverted data with empty properties:\n";
print_r($convertedEmptyProps['properties']);

echo "\n🏁 DEBUGGING COMPLETE\n";
echo "=====================\n";
?>
