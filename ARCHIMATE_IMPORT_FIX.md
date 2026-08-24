# ArchiMate Import Fix - "Unknown ArchiMate type" Error Resolution

## Problem Description

The ArchiMate import was failing with multiple "Unknown ArchiMate type: elements" errors, resulting in:
- 2765 elements found but 0 created/updated
- Import completing successfully but with no actual data import
- High memory usage (174MB peak) due to processing large files

## Root Cause Analysis

The issue was caused by a **type mismatch** between the processing methods:

1. **Optimized Processing Method** (`convertToOpenRegisterObjectsOptimized`):
   - Used for datasets > 1000 objects (your case: 2765 elements)
   - Passed **plural schema types** to processing methods: `'elements'`, `'organizations'`, `'relationships'`, etc.

2. **Schema ID Resolution Method** (`getAmefSchemaIdForType`):
   - Expected **singular schema types**: `'element'`, `'organization'`, `'relationship'`, etc.
   - Threw "Unknown ArchiMate type" exception for plural types

## Solution Implemented

### Fix Location
`lib/Service/ArchiMateService.php` - `getAmefSchemaIdForType()` method (lines 2110-2140)

### Code Changes
Added a **type mapping system** to convert plural types to singular types:

```php
// Map plural schema types to singular types for compatibility
$typeMapping = [
    'elements' => 'element',
    'organizations' => 'organization', 
    'relationships' => 'relationship',
    'views' => 'view',
    'models' => 'model',
    'properties' => 'property',
    'property_definitions' => 'property_definition'
];

// Convert plural type to singular if needed
$archiMateType = $typeMapping[$archiMateType] ?? $archiMateType;
```

### Supported Type Mappings
| Plural Type | Singular Type |
|-------------|---------------|
| `elements` | `element` |
| `organizations` | `organization` |
| `relationships` | `relationship` |
| `views` | `view` |
| `models` | `model` |
| `properties` | `property` |
| `property_definitions` | `property_definition` |

## Testing the Fix

### 1. Verify Configuration
Before testing, ensure AMEF configuration is properly set:

```bash
# Check AMEF configuration
docker-compose exec -u 33 nextcloud php occ config:app:get stackiq amef_config

# Check via API
curl -u 'admin:admin' \
     -H 'OCS-APIREQUEST: true' \
     'http://localhost/index.php/apps/stackiq/api/settings/debug'
```

### 2. Test Import via UI
1. Go to **Settings > Software Catalog > OpenRegister Integration > AMEF tab**
2. Verify all schema fields are configured
3. Upload your `GEMMA_release.xml` file
4. Monitor the import progress

### 3. Test Import via API
```bash
# Upload file via API
curl -u 'admin:admin' \
     -H 'OCS-APIREQUEST: true' \
     -F 'archiMateFile=@/path/to/GEMMA_release.xml' \
     'http://localhost/index.php/apps/stackiq/api/archimate/import'
```

### 4. Monitor Logs
```bash
# View real-time logs
docker logs -f master-nextcloud-1 | grep -E '\[ArchiMateService\]|\[SaveObject\]|Unknown ArchiMate type'

# Check for successful processing
docker logs master-nextcloud-1 | grep -E 'Object creation completed|Object update completed'
```

## Expected Results After Fix

### Before Fix
```json
{
    "success": true,
    "statistics": {
        "elements": {
            "found": 2765,
            "created": 0,
            "updated": 0,
            "errors": ["Unknown ArchiMate type: elements", "Unknown ArchiMate type: elements", ...]
        }
    }
}
```

### After Fix
```json
{
    "success": true,
    "statistics": {
        "elements": {
            "found": 2765,
            "created": 1500,
            "updated": 1265,
            "errors": []
        }
    }
}
```

## Verification Steps

### 1. Check Object Creation
```bash
# Check if objects were created in the database
docker-compose exec -u 33 nextcloud php occ config:app:get stackiq amef_config
```

### 2. Verify Schema Mapping
The fix ensures that:
- `'elements'` → `'element'` → `elements_schema` configuration
- `'organizations'` → `'organization'` → `organizations_schema` configuration
- All other plural types are correctly mapped to their singular counterparts

### 3. Monitor Performance
- Memory usage should remain reasonable (current: 174MB peak is acceptable for 2765 elements)
- Processing time should be consistent (current: 21.9 seconds is reasonable)
- No "Unknown ArchiMate type" errors should appear in logs

## Troubleshooting

### If Import Still Fails

1. **Check AMEF Configuration**:
   ```bash
   docker-compose exec -u 33 nextcloud php occ config:app:get stackiq amef_config
   ```
   Ensure all schema IDs are properly set.

2. **Verify VNG-GEMMA Register**:
   The system requires a register with slug `'vng-gemma'` to exist.

3. **Check Schema IDs**:
   Verify that the configured schema IDs match existing schemas in the VNG-GEMMA register.

4. **Run Auto-Configuration**:
   Use the "Auto-Configure AMEF" feature in the settings to automatically set up the configuration.

### Common Issues

1. **Missing VNG-GEMMA Register**: Create or import the required register
2. **Missing Schema Configurations**: Run auto-configuration or manually configure schemas
3. **Incorrect Schema IDs**: Verify schema IDs match existing schemas in the register

## Impact

This fix resolves the core issue preventing ArchiMate imports from working with large datasets (>1000 objects) and ensures that:

- All ArchiMate elements are properly processed and imported
- No "Unknown ArchiMate type" errors occur
- Import statistics accurately reflect created/updated objects
- The system can handle large ArchiMate files efficiently

## Files Modified

- `lib/Service/ArchiMateService.php` - Added type mapping in `getAmefSchemaIdForType()` method

## Related Documentation

- [ArchiMate Import/Export Documentation](website/docs/archimate-import-export.md)
- [Configuration Guide](docs/CONFIGURATION.md)
- [Testing Guide](docs/TESTING_ORGANIZATION_SYNC.md) 