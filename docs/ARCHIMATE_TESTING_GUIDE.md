# ArchiMate Import/Export Testing Guide

This guide provides comprehensive instructions for testing the ArchiMate import/export functionality in the Stackiq application, including full round-trip testing to ensure data integrity.

## Prerequisites

- Docker Compose setup with Nextcloud container running
- Stackiq and OpenRegister apps enabled
- Admin credentials (default: admin:admin)
- Sample ArchiMate XML file (GEMMA_release.xml is included)

## Quick Start Testing

### 1. Basic Import Test

```bash
# Import the sample GEMMA file
cd /home/rubenlinde/nextcloud-docker-dev
docker-compose exec nextcloud curl -X POST \
  "http://localhost/index.php/apps/stackiq/api/archimate/import" \
  -H "Content-Type: application/json" \
  -u admin:admin \
  -d '{"file_path": "/var/www/html/apps-extra/stackiq/lib/Settings/GEMMA_release.xml"}'
```

Expected response includes statistics like:
```json
{
  "success": true,
  "statistics": {
    "elements": {"created": 2765, "updated": 0, "skipped": 0},
    "organizations": {"created": 71, "updated": 0, "skipped": 0},
    "relationships": {"created": 5696, "updated": 0, "skipped": 0},
    "views": {"created": 242, "updated": 0, "skipped": 0},
    "property_definitions": {"created": 77, "updated": 0, "skipped": 0}
  }
}
```

### 2. Basic Export Test

```bash
# Export the imported data
docker-compose exec nextcloud curl -X POST \
  "http://localhost/index.php/apps/stackiq/api/archimate/export" \
  -H "Content-Type: application/json" \
  -u admin:admin \
  -d '{}' > /tmp/exported_archimate.xml
```

## Full Round-Trip Testing

### Automated Comparison Script

The application includes a comprehensive comparison script that automatically tests round-trip compatibility:

```bash
# Run the automated comparison
docker-compose exec nextcloud php /var/www/html/apps-extra/stackiq/compare_archimate.php
```

This script will:
1. Generate a fresh export
2. Compare it with the original GEMMA_release.xml
3. Report all differences found
4. Save detailed results to `/tmp/archimate_comparison_report.json`

### Manual Round-Trip Testing Steps

#### Step 1: Prepare Test Environment

```bash
# Clear any existing import status
docker-compose exec nextcloud curl -X POST \
  "http://localhost/index.php/apps/stackiq/api/archimate/import/cancel" \
  -u admin:admin

# Check that the system is ready
docker-compose exec nextcloud curl -X GET \
  "http://localhost/index.php/apps/stackiq/api/settings/status" \
  -u admin:admin
```

#### Step 2: Import Original Data

```bash
# Import the original GEMMA file
docker-compose exec nextcloud curl -X POST \
  "http://localhost/index.php/apps/stackiq/api/archimate/import" \
  -H "Content-Type: application/json" \
  -u admin:admin \
  -d '{"file_path": "/var/www/html/apps-extra/stackiq/lib/Settings/GEMMA_release.xml"}'
```

#### Step 3: Export Imported Data

```bash
# Export the data to a new file
docker-compose exec nextcloud curl -X POST \
  "http://localhost/index.php/apps/stackiq/api/archimate/export" \
  -H "Content-Type: application/json" \
  -u admin:admin \
  -d '{}' > /tmp/round_trip_export.xml

# Check the export file size (should be similar to original)
docker-compose exec nextcloud ls -lh /var/www/html/apps-extra/stackiq/lib/Settings/GEMMA_release.xml
docker-compose exec nextcloud ls -lh /tmp/round_trip_export.xml
```

#### Step 4: Compare Files

```bash
# Run the detailed comparison
docker-compose exec nextcloud php /var/www/html/apps-extra/stackiq/compare_archimate.php

# For manual inspection, check specific sections
docker-compose exec nextcloud head -n 50 /tmp/round_trip_export.xml
```

## Database Inspection Tools

### Debug Database Content

Use the included debug script to inspect what's stored in the database:

```bash
docker-compose exec nextcloud php /var/www/html/apps-extra/stackiq/debug_db.php
```

This will show:
- Table structure
- Sample element data with ArchiMate types
- Property definitions count
- Sample relationship data
- Actual JSON object content

### Manual Database Queries

For deeper inspection, you can query the database directly:

```bash
# Count objects by type
docker-compose exec nextcloud php -r '
require_once "/var/www/html/lib/base.php";
\OC::$CLI = true;
$db = \OC::$server->getDatabaseConnection();
$query = $db->prepare("SELECT schema as type, COUNT(*) as count FROM oc_openregister_objects GROUP BY schema");
$query->execute();
foreach ($query->fetchAll() as $row) {
    echo $row["type"] . ": " . $row["count"] . "\n";
}
'
```

## Testing Specific Components

### Elements Testing

```bash
# Check a specific element
docker-compose exec nextcloud curl -X GET \
  "http://localhost/index.php/apps/stackiq/api/archimate/export" \
  -u admin:admin | grep -A 10 "id-009fa62f25844aa3a87d252bf2b6bb0c"
```

Expected output should include:
- Correct `xsi:type="Capability"`
- Element name and documentation
- Properties with valid `propertyDefinitionRef` values

### Relationships Testing

```bash
# Check relationships structure
docker-compose exec nextcloud curl -X GET \
  "http://localhost/index.php/apps/stackiq/api/archimate/export" \
  -u admin:admin | grep -A 5 -B 2 '<relationship'
```

Expected output should include:
- Correct `xsi:type` (e.g., "Access", "Aggregation")
- Valid `source` and `target` attributes
- Optional `<name>` elements where present
- Properties with correct references

### Property Definitions Testing

```bash
# Check property definitions
docker-compose exec nextcloud curl -X GET \
  "http://localhost/index.php/apps/stackiq/api/archimate/export" \
  -u admin:admin | grep -A 3 '<propertyDefinition'
```

Expected output:
- All `propid-X` definitions present
- Correct names and types
- Proper XML structure

### Folders Testing

```bash
# Check folders are exported
docker-compose exec nextcloud curl -X GET \
  "http://localhost/index.php/apps/stackiq/api/archimate/export" \
  -u admin:admin | grep -A 3 '<folder'
```

### Views/Diagrams Testing

```bash
# Check views structure
docker-compose exec nextcloud curl -X GET \
  "http://localhost/index.php/apps/stackiq/api/archimate/export" \
  -u admin:admin | grep -A 5 '<view'
```

Expected output:
- Views wrapped in `<diagrams>` section
- Correct `xsi:type="Diagram"`
- View names and properties

## Performance Testing

### Large File Import Testing

```bash
# Test with large files (measure time)
time docker-compose exec nextcloud curl -X POST \
  "http://localhost/index.php/apps/stackiq/api/archimate/import" \
  -H "Content-Type: application/json" \
  -u admin:admin \
  -d '{"file_path": "/var/www/html/apps-extra/stackiq/lib/Settings/GEMMA_release.xml"}'
```

### Memory Usage Monitoring

```bash
# Monitor memory during import
docker-compose exec nextcloud php -r '
echo "Memory before: " . memory_get_usage(true) / 1024 / 1024 . " MB\n";
// Trigger import via API call
echo "Memory after: " . memory_get_usage(true) / 1024 / 1024 . " MB\n";
echo "Peak memory: " . memory_get_peak_usage(true) / 1024 / 1024 . " MB\n";
'
```

## Troubleshooting

### Common Issues and Solutions

#### 1. Import Fails with "No file uploaded"

```bash
# Check file exists and is readable
docker-compose exec nextcloud ls -la /var/www/html/apps-extra/stackiq/lib/Settings/GEMMA_release.xml

# Ensure proper file path in request
curl -X POST "http://localhost/index.php/apps/stackiq/api/archimate/import" \
  -H "Content-Type: application/json" \
  -u admin:admin \
  -d '{"file_path": "/var/www/html/apps-extra/stackiq/lib/Settings/GEMMA_release.xml"}'
```

#### 2. Export Returns Empty or Minimal XML

```bash
# Check if data was actually imported
docker-compose exec nextcloud php /var/www/html/apps-extra/stackiq/debug_db.php

# Clear any stuck import status
docker-compose exec nextcloud curl -X POST \
  "http://localhost/index.php/apps/stackiq/api/archimate/import/cancel" \
  -u admin:admin
```

#### 3. Properties Missing from Export

This usually indicates an import issue. Check:

```bash
# Verify properties are stored correctly
docker-compose exec nextcloud php -r '
require_once "/var/www/html/lib/base.php";
\OC::$CLI = true;
$db = \OC::$server->getDatabaseConnection();
$query = $db->prepare("SELECT uuid, object FROM oc_openregister_objects WHERE uuid = ? LIMIT 1");
$query->execute(["id-009fa62f25844aa3a87d252bf2b6bb0c"]);
$result = $query->fetch();
if ($result) {
    $data = json_decode($result["object"], true);
    print_r($data["properties"]);
}
'
```

#### 4. Relationship Source/Target Empty

```bash
# Check relationship data structure
docker-compose exec nextcloud php -r '
require_once "/var/www/html/lib/base.php";
\OC::$CLI = true;
$db = \OC::$server->getDatabaseConnection();
$query = $db->prepare("SELECT uuid, object FROM oc_openregister_objects WHERE uuid = ? LIMIT 1");
$query->execute(["id-3b28f55b7fb24b0d9b09cd1d5eef8e3e"]);
$result = $query->fetch();
if ($result) {
    $data = json_decode($result["object"], true);
    echo "Source: " . ($data["source_id"] ?? "MISSING") . "\n";
    echo "Target: " . ($data["target_id"] ?? "MISSING") . "\n";
}
'
```

### Log Inspection

```bash
# Check Nextcloud logs for errors
docker-compose exec nextcloud tail -n 100 /var/www/html/data/nextcloud.log | grep -i "archimate\|stackiq"

# Check for specific error patterns
docker-compose exec nextcloud grep -i "error\|exception" /var/www/html/data/nextcloud.log | tail -n 20
```

## Validation Tools

### XML Structure Validation

```bash
# Validate XML structure of export
docker-compose exec nextcloud php -r '
$xml = file_get_contents("/tmp/round_trip_export.xml");
$dom = new DOMDocument();
$dom->loadXML($xml);
if ($dom->schemaValidate("/path/to/archimate.xsd")) {
    echo "XML is valid\n";
} else {
    echo "XML validation failed\n";
}
'
```

### Data Integrity Checks

```bash
# Count elements in original vs export
echo "Original elements:"
docker-compose exec nextcloud grep -c '<element ' /var/www/html/apps-extra/stackiq/lib/Settings/GEMMA_release.xml

echo "Exported elements:"
docker-compose exec nextcloud grep -c '<element ' /tmp/round_trip_export.xml

echo "Original relationships:"
docker-compose exec nextcloud grep -c '<relationship ' /var/www/html/apps-extra/stackiq/lib/Settings/GEMMA_release.xml

echo "Exported relationships:"
docker-compose exec nextcloud grep -c '<relationship ' /tmp/round_trip_export.xml
```

## Success Criteria

A successful round-trip test should show:

1. **Import Statistics**: All object types imported with expected counts
2. **Export Generation**: XML file generated with similar size to original
3. **Comparison Results**: Minimal or zero differences in automated comparison
4. **Data Integrity**: All elements, relationships, views, and properties preserved
5. **XML Validity**: Generated XML is well-formed and validates against schema

## Automated Testing Integration

### Continuous Integration Script

```bash
#!/bin/bash
# ci_test_archimate.sh

set -e

echo "Starting ArchiMate round-trip test..."

# Clear any existing state
docker-compose exec nextcloud curl -X POST \
  "http://localhost/index.php/apps/stackiq/api/archimate/import/cancel" \
  -u admin:admin

# Import test data
echo "Importing GEMMA data..."
IMPORT_RESULT=$(docker-compose exec nextcloud curl -s -X POST \
  "http://localhost/index.php/apps/stackiq/api/archimate/import" \
  -H "Content-Type: application/json" \
  -u admin:admin \
  -d '{"file_path": "/var/www/html/apps-extra/stackiq/lib/Settings/GEMMA_release.xml"}')

# Check import success
if ! echo "$IMPORT_RESULT" | grep -q '"success":true'; then
    echo "Import failed: $IMPORT_RESULT"
    exit 1
fi

# Run comparison test
echo "Running comparison test..."
COMPARISON_RESULT=$(docker-compose exec nextcloud php /var/www/html/apps-extra/stackiq/compare_archimate.php)

# Check for perfect round-trip
if echo "$COMPARISON_RESULT" | grep -q "NO DIFFERENCES FOUND"; then
    echo "✅ Perfect round-trip compatibility achieved!"
    exit 0
else
    echo "❌ Differences found in round-trip test"
    echo "$COMPARISON_RESULT"
    exit 1
fi
```

This comprehensive testing guide provides all the tools and procedures needed to thoroughly test the ArchiMate import/export functionality and ensure data integrity through complete round-trip testing.
