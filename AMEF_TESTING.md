# AMEF Round-trip Testing Guide

This guide provides multiple ways to test the ArchiMate import/export functionality using the existing `GEMMA_release.xml` file.

## Overview

The GEMMA_release.xml file (254,813 lines, ~2.5MB) is a perfect test case for our ArchiMate import/export functionality because:

- ✅ **Large Scale**: Tests performance with substantial data (254K+ lines)
- ✅ **Real Data**: Uses actual ArchiMate model from GEMMA
- ✅ **Complex Structure**: Contains elements, relationships, views, and properties  
- ✅ **Round-trip Verification**: Import → Export → Compare workflow

## Testing Methods

### Method 1: Automated Shell Script (Recommended)

**Simple and fast testing with colored output**

```bash
# Run the automated test script
./test_amef_simple.sh
```

This script will:
1. 🔧 Test AMEF configuration (get settings + auto-configure)
2. 📥 Import GEMMA_release.xml via API  
3. 📤 Export back to new file via API
4. 💾 Download the exported file
5. 🔍 Compare original vs exported files
6. 📊 Show detailed summary and statistics

### Method 2: PHP Testing Script (Advanced)

**More detailed testing with exception handling**

```bash
# Run the PHP test script
php test_amef_roundtrip.php
```

Features:
- Comprehensive error handling
- Detailed timing information
- File size comparisons
- Diff analysis and summaries

### Method 3: Manual API Testing with cURL

**Step-by-step manual testing**

#### Step 1: Configure AMEF Settings

```bash
# Get current AMEF configuration
curl -u admin:admin \
  -H "X-Requested-With: XMLHttpRequest" \
  "http://localhost/index.php/apps/softwarecatalog/api/settings/amef"

# Auto-configure AMEF schemas
curl -u admin:admin \
  -X POST \
  -H "X-Requested-With: XMLHttpRequest" \
  -H "Content-Type: application/json" \
  "http://localhost/index.php/apps/softwarecatalog/api/settings/amef/auto-configure"
```

#### Step 2: Import GEMMA_release.xml

```bash
# Import the AMEF file
curl -u admin:admin \
  -X POST \
  -H "X-Requested-With: XMLHttpRequest" \
  -F "archiMateFile=@lib/Settings/GEMMA_release.xml" \
  -F "updateExisting=true" \
  -F "preserveIds=true" \
  "http://localhost/index.php/apps/softwarecatalog/api/archimate/import"
```

#### Step 3: Export Back to AMEF

```bash
# Export to ArchiMate format  
curl -u admin:admin \
  -X POST \
  -H "X-Requested-With: XMLHttpRequest" \
  -H "Content-Type: application/json" \
  -d '{
    "format": "xml",
    "includeRelationships": true,
    "includeViews": true,
    "organizationSpecific": false
  }' \
  "http://localhost/index.php/apps/softwarecatalog/api/archimate/export"
```

#### Step 4: Download Exported File

```bash
# Download the exported file (use filename from step 3 response)
curl -u admin:admin \
  -o exported_gemma.xml \
  "http://localhost/index.php/apps/softwarecatalog/api/archimate/download/FILENAME_FROM_EXPORT_RESPONSE"
```

#### Step 5: Compare Files

```bash
# Generate diff between original and exported
diff -u lib/Settings/GEMMA_release.xml exported_gemma.xml > gemma_diff.txt

# Check if files are identical
if [ $? -eq 0 ]; then
  echo "✅ Files are identical!"
else
  echo "⚠️ Files differ - check gemma_diff.txt"
  wc -l gemma_diff.txt
fi
```

## Expected Results

### ✅ **Perfect Round-trip**
- Files are byte-for-byte identical
- All ArchiMate IDs preserved correctly  
- No data loss during import/export cycle

### ⚠️ **Acceptable Differences**
- XML formatting differences (whitespace, attribute order)
- Timestamp/metadata differences
- Non-semantic ordering changes

### ❌ **Issues to Investigate**
- Missing elements or relationships
- Changed ArchiMate IDs
- Corrupted data structures
- Performance issues (timeouts, memory errors)

## Performance Expectations

With the GEMMA_release.xml file (254K+ lines):

| Operation | Expected Time | Memory Usage |
|-----------|---------------|--------------|
| Import    | 30-120s       | 256-512MB    |
| Export    | 15-60s        | 128-256MB    |
| Download  | 2-10s         | Minimal      |

*Times vary based on server performance and database size*

## Troubleshooting

### Import Fails
- ✅ Check AMEF schema configuration is complete
- ✅ Verify OpenRegister is running and accessible
- ✅ Increase PHP memory limit if needed (512MB+)
- ✅ Check server logs for detailed error messages

### Export Fails  
- ✅ Ensure objects were imported successfully
- ✅ Verify export permissions and disk space
- ✅ Check configured schemas contain data

### Files Differ
- ✅ Examine diff file for specific differences
- ✅ Check if differences are formatting-only
- ✅ Verify ArchiMate IDs are preserved
- ✅ Compare element counts and key properties

### Performance Issues
- ✅ Monitor server resources during testing
- ✅ Check if async processing is being used (100+ items)
- ✅ Verify ReactPHP is working correctly
- ✅ Review database query performance

## File Structure Analysis

The GEMMA_release.xml contains:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<model xmlns="http://www.opengroup.org/xsd/archimate/3.0/" 
       identifier="id-b58b6b03-a59d-472b-bd87-88ba77ded4e6">
  <name xml:lang="en">GEMMA release (test)</name>
  <documentation>...</documentation>
  <properties>...</properties>
  <elements>...</elements>
  <relationships>...</relationships>
  <views>...</views>
</model>
```

**Key Elements to Verify:**
- 🏗️ **Elements**: Business/Application/Technology components
- 🔗 **Relationships**: Connections between elements  
- 📊 **Views**: Visual diagrams and layouts
- 🏷️ **Properties**: Metadata and custom attributes
- 🆔 **Identifiers**: Unique IDs for update detection

## Integration with Docker Environment

Using the existing Docker setup:

```bash
# Test from inside the Nextcloud container
cd /home/rubenlinde/nextcloud-docker-dev
docker-compose exec nextcloud bash

# Navigate to SoftwareCatalog app
cd /var/www/html/apps-extra/softwarecatalog

# Run tests
./test_amef_simple.sh
```

## Continuous Testing

Add this to your development workflow:

1. **Before deployment**: Run round-trip test
2. **After schema changes**: Verify import/export still works  
3. **Performance monitoring**: Track import/export times
4. **Data integrity**: Regular file comparison checks

## Success Criteria

✅ **Functional Success**:
- Import completes without errors
- Export produces valid ArchiMate XML
- Round-trip preserves critical data

✅ **Performance Success**:
- Large files (250K+ lines) process within reasonable time
- Memory usage stays within server limits  
- Async processing handles large datasets

✅ **Data Integrity Success**:
- ArchiMate IDs preserved for update detection
- All elements, relationships, views maintained
- Properties and metadata preserved