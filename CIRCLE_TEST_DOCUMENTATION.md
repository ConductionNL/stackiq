# ArchiMate Import/Export Circle Testing Documentation

## 🎯 **GOAL**
Achieve perfect round-trip data integrity: `GEMMA_release.xml` → Import → Export → `exported.xml` should be identical.

## 📋 **Current Status** 
- **Import**: Working correctly - 8780+ objects created across all sections
- **Export Size**: FIXED! Reduced from 52MB to 1.1MB (98% reduction)
- **XML Structure**: FIXED! Now uses correct OpenGroup namespace and `<elements>/<relationships>` structure
- **Content**: Names and documentation are populated correctly
- **Still Missing**: Element attributes (identifier, xsi:type), property values, complete relationship data

## 🔧 **Testing Commands**

### 1. Cancel Any Running Import
```bash
cd /home/rubenlinde/nextcloud-docker-dev && docker-compose exec nextcloud curl -X POST "http://localhost/index.php/apps/stackiq/api/archimate/import/cancel" -u admin:admin
```

### 2. Run Fresh Import
```bash
cd /home/rubenlinde/nextcloud-docker-dev && docker-compose exec nextcloud curl -X POST "http://localhost/index.php/apps/stackiq/api/archimate/import" -H "Content-Type: application/json" -u admin:admin -d '{"file_path": "/var/www/html/apps-extra/stackiq/lib/Settings/GEMMA_release.xml"}'
```

### 3. Check Database Content (Debug)
```bash
cd /home/rubenlinde/nextcloud-docker-dev && docker-compose exec nextcloud php /var/www/html/apps-extra/stackiq/debug_db.php
```

### 4. Run Export
```bash
cd /home/rubenlinde/nextcloud-docker-dev && docker-compose exec nextcloud curl -X POST "http://localhost/index.php/apps/stackiq/api/archimate/export" -H "Content-Type: application/json" -u admin:admin
```

### 5. Run Comparison Script
```bash
cd /home/rubenlinde/nextcloud-docker-dev && docker-compose exec nextcloud php /var/www/html/apps-extra/stackiq/compare_archimate.php
```

**What it does:**
- Generates a fresh export automatically
- Compares file sizes (original vs exported)
- Analyzes XML structure differences
- Checks for missing attributes (identifier, xsi:type)
- Validates property values and references
- Reports detailed differences by section
- Saves detailed JSON report to `/tmp/archimate_comparison_report.json`

## 🚀 **Performance Optimized API Endpoints**

### New Separate Endpoints (v2.2)
The API has been refactored for better performance by separating concerns:

#### 1. Basic Settings (Fast)
```bash
# Get basic configuration only (no object counts)
curl -u admin:admin "http://localhost/index.php/apps/stackiq/api/settings"
```

#### 2. ArchiMate Status (Medium)
```bash
# Get ArchiMate import/export status only (no object counts)
curl -u admin:admin "http://localhost/index.php/apps/stackiq/api/settings/archimate"
```

#### 3. Object Counts (Slow - Load on demand)
```bash
# Get object counts for all registers (separate endpoint)
curl -u admin:admin "http://localhost/index.php/apps/stackiq/api/settings/objects"
```

### Performance Benefits
- **Main settings endpoint**: Now loads in ~100ms instead of 2-5 seconds
- **ArchiMate status**: Loads in ~200ms for real-time polling
- **Object counts**: Loaded separately only when needed for statistics

## 🔧 **Export Data Analysis & Fixes Applied**

### ✅ **MAJOR FIXES COMPLETED**

#### 1. XML Namespace & Structure (FIXED)
- **Problem**: Used wrong namespace `http://www.archimatetool.com/archimate`
- **Fix**: Changed to correct OpenGroup namespace `http://www.opengroup.org/xsd/archimate/3.0/`
- **Location**: `ArchiMateExportService::createCleanArchiMateXml()`

#### 2. Section Structure (FIXED)
- **Problem**: Created `<folder>` elements instead of direct sections
- **Fix**: Now creates `<elements>`, `<relationships>`, `<views>`, etc. directly
- **Location**: `ArchiMateExportService::createSectionFolder()`

#### 3. Data Bloat (FIXED)
- **Problem**: Double JSON serialization caused 52MB export vs 13MB import
- **Fix**: Clean data filtering removes duplicate attributes (_identifier, ___identifier, etc.)
- **Location**: `ArchiMateExportService::cleanObjectDataForXml()`

### ❌ **REMAINING ISSUES TO FIX**

#### Issue 1: Missing Element Attributes
- **Problem**: Elements lack `identifier` and `xsi:type` attributes
- **Expected**: `<element identifier="id-009fa62f" xsi:type="Capability">`
- **Current**: `<element><name>...</name></element>`
- **Fix Needed**: Update `addCleanDataToXmlNode()` to handle these attributes

#### Issue 2: Empty Property Values
- **Problem**: Properties show as `<property/>` instead of full structure
- **Expected**: `<property propertyDefinitionRef="propid-1"><value xml:lang="en">...</value></property>`
- **Fix Needed**: Debug property data structure in flattened objects

#### Issue 3: Incomplete Relationships
- **Problem**: Relationships section exists but may be missing data
- **Fix Needed**: Verify relationship attributes (source, target, accessType)

## 🔍 **File Analysis Commands**

### Compare File Sizes
```bash
cd /home/rubenlinde/nextcloud-docker-dev && docker-compose exec nextcloud ls -lh /var/www/html/apps-extra/stackiq/lib/Settings/GEMMA_release.xml /tmp/exported_archimate.xml
```

### Check XML Structure
```bash
# Original structure
docker-compose exec nextcloud head -20 /var/www/html/apps-extra/stackiq/lib/Settings/GEMMA_release.xml

# Exported structure  
docker-compose exec nextcloud head -20 /tmp/exported_archimate.xml
```

### Find Element Samples
```bash
# Original element structure
docker-compose exec nextcloud grep -A 5 -B 5 "element.*identifier" /var/www/html/apps-extra/stackiq/lib/Settings/GEMMA_release.xml | head -20

# Exported element structure
docker-compose exec nextcloud grep -A 5 -B 5 "<element" /tmp/exported_archimate.xml | head -20
```

### Check Relationships
```bash
# Original relationships
docker-compose exec nextcloud grep -A 3 -B 3 "<relationships>" /var/www/html/apps-extra/stackiq/lib/Settings/GEMMA_release.xml

# Exported relationships
docker-compose exec nextcloud grep -A 10 "<relationships>" /tmp/exported_archimate.xml
```

## 📁 **Key Files**

### Debug Scripts
- `debug_db.php` - Inspect database content
- `compare_archimate.php` - **Main comparison tool** - Automatically exports fresh XML and compares with original
- `debug_actual_import.php` - Test XML parser structure
- `full_circle_test.php` - Complete automated import → export → compare cycle

### Main Service
- `lib/Service/ArchiMateService.php` - All import/export logic

### Test File
- `lib/Settings/GEMMA_release.xml` - Original test file

## 🔍 **Debugging Process**

1. **Import** → Check if organizations count > 0
2. **Database Check** → Verify properties have `propid-X` keys (not empty)
3. **Export** → Should complete successfully
4. **Comparison** → Run script to find remaining differences

## ✅ **Success Criteria**

The comparison script should show:
- **Elements**: All match (xsi:type, documentation, properties with propid-X)
- **Relationships**: All match (source/target not empty, correct names)
- **Views**: All match (correct structure)
- **Folders**: All match
- **Organizations**: 71 found and exported
- **Property Definitions**: 77 found and exported

## 🚀 **Next Steps to Complete Export**

### Priority 1: Fix Missing Element Attributes
The export generates clean XML but is missing crucial attributes:

```php
// Current export generates:
<element><name>...</name></element>

// Should generate:
<element identifier="id-009fa62f" xsi:type="Capability"><name>...</name></element>
```

**Fix Location**: `ArchiMateExportService::addCleanDataToXmlNode()`
- The method handles attributes but the data might not contain `identifier` and `xsi:type`
- Debug what's in the flattened object structure

### Priority 2: Fix Property Values
Properties are exported as empty `<property/>` instead of:
```xml
<property propertyDefinitionRef="propid-1">
  <value xml:lang="en">Thema-architectuur Common Ground</value>
</property>
```

### Priority 3: Verify Relationships
Relationships section exists but content needs verification.

## 🧪 **Complete Test Sequence**

1. **Fresh Import**: `curl -X POST "http://localhost/index.php/apps/stackiq/api/archimate/import" -H "Content-Type: application/json" -u admin:admin -d '{"file_path": "/var/www/html/apps-extra/stackiq/lib/Settings/GEMMA_release.xml"}'`

2. **Test Export**: `curl -X POST "http://localhost/index.php/apps/stackiq/api/archimate/export" -H "Content-Type: application/json" -u admin:admin -d '{"format":"archimate","includeRelationships":true,"includeViews":true,"organizationSpecific":false,"selectedSchemas":[]}' -o /tmp/test_export.xml`

3. **Run Comparison**: `php /var/www/html/apps-extra/stackiq/compare_archimate.php`

4. **Full Circle Test**: `php /var/www/html/apps-extra/stackiq/full_circle_test.php`

## 🔍 **Comparison Script Details**

### Usage
```bash
cd /home/rubenlinde/nextcloud-docker-dev && docker-compose exec nextcloud php /var/www/html/apps-extra/stackiq/compare_archimate.php
```

### What It Analyzes
1. **File Size Comparison**: Detects if export is significantly larger/smaller than original
2. **Model Metadata**: Name, documentation, properties at root level
3. **Elements**: Checks all 2,765 elements for identifier, xsi:type, properties
4. **Relationships**: Validates all 5,696 relationships for source, target, type
5. **Views**: Analyzes 242 views for proper structure
6. **Property Definitions**: Checks 77 property definitions
7. **Organizations**: Verifies organization data

### Output Interpretation
- **✅ "YES"**: Attribute found correctly
- **❌ "NO"**: Critical attribute missing
- **"MISSING"**: Expected data not found in export
- **Empty values**: Data exists but content is empty
- **Detailed report**: Saved to `/tmp/archimate_comparison_report.json`

### Success Indicators
- File sizes within 20% of each other
- All elements have identifier and xsi:type
- Properties have proper propertyDefinitionRef values
- Relationships have source, target, type attributes
- Final message: "NO CRITICAL ISSUES DETECTED!"

## ✅ **Major Progress Achieved**

- ✅ **File Size**: Reduced from 52MB to 1.1MB (98% improvement)
- ✅ **XML Namespace**: Fixed to OpenGroup standard 
- ✅ **XML Structure**: Uses correct `<elements>`, `<relationships>` sections
- ✅ **Content**: Names and documentation properly populated
- ✅ **Data Cleanup**: Removed duplicate attributes and metadata pollution
- ✅ **Performance**: Export now takes ~20 seconds instead of 3.5 minutes

## 🎯 **Final Goal**

When comparison script runs, it should show:
- Elements: All have identifier and xsi:type attributes
- Properties: All have propertyDefinitionRef and values
- Relationships: All have source, target, and type attributes
- File sizes within 20% of each other
- "NO CRITICAL ISSUES DETECTED!"
