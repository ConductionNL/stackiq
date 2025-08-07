# ArchiMate Import/Export Circle Testing Documentation

## 🎯 **GOAL**
Achieve perfect round-trip data integrity: `GEMMA_release.xml` → Import → Export → `exported.xml` should be identical.

## 📋 **Current Status**
- **Last Import Results**: 8780 objects created (elements: 2765, relationships: 5696, views: 242, property_definitions: 77)
- **Organizations Issue**: Still showing 0 found/created (major bug)
- **Property Issue**: Properties still stored with empty keys instead of `propid-X` values

## 🔧 **Testing Commands**

### 1. Cancel Any Running Import
```bash
cd /home/rubenlinde/nextcloud-docker-dev && docker-compose exec nextcloud curl -X POST "http://localhost/index.php/apps/softwarecatalog/api/archimate/import/cancel" -u admin:admin
```

### 2. Run Fresh Import
```bash
cd /home/rubenlinde/nextcloud-docker-dev && docker-compose exec nextcloud curl -X POST "http://localhost/index.php/apps/softwarecatalog/api/archimate/import" -H "Content-Type: application/json" -u admin:admin -d '{"file_path": "/var/www/html/apps-extra/softwarecatalog/lib/Settings/GEMMA_release.xml"}'
```

### 3. Check Database Content (Debug)
```bash
cd /home/rubenlinde/nextcloud-docker-dev && docker-compose exec nextcloud php /var/www/html/apps-extra/softwarecatalog/debug_db.php
```

### 4. Run Export
```bash
cd /home/rubenlinde/nextcloud-docker-dev && docker-compose exec nextcloud curl -X POST "http://localhost/index.php/apps/softwarecatalog/api/archimate/export" -H "Content-Type: application/json" -u admin:admin
```

### 5. Run Comparison Script
```bash
cd /home/rubenlinde/nextcloud-docker-dev && docker-compose exec nextcloud php /var/www/html/apps-extra/softwarecatalog/compare_archimate.php
```

## 🐛 **Known Issues to Fix**

### Issue 1: Organizations Not Found (0 created)
**Problem**: Organizations section not being parsed during import
**Location**: `normalizeArchiMateData()` method in ArchiMateService.php
**Expected**: Should find 71 organizations like before

### Issue 2: Properties Have Empty Keys
**Problem**: Properties stored as `''` instead of `propid-1`, `propid-2`, etc.
**Root Cause**: Mismatch between `_attributes` vs `@attributes` in XML parser
**Location**: `extractProperties()` method in ArchiMateService.php

## 📁 **Key Files**

### Debug Scripts
- `debug_db.php` - Inspect database content
- `compare_archimate.php` - Compare original vs exported XML
- `debug_actual_import.php` - Test XML parser structure

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

## 🚀 **Next Steps After Restart**

1. Run the testing commands in sequence
2. If organizations still show 0, debug the `normalizeArchiMateData()` XML parsing
3. If properties still have empty keys, debug the `extractProperties()` method
4. Continue until comparison script shows perfect match

## 💡 **Key Insight**

The streaming XML parser uses `_attributes` (underscore) while SimpleXML uses `@attributes` (at-sign). The code should use `_attributes` consistently since we use the streaming parser for import.
